<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuctionException;
use Database;
use PDO;

/**
 * =====================================================================
 *  The auction engine
 * =====================================================================
 *
 *  Every method that changes money or ownership runs inside a single
 *  transaction and takes a row lock on the lot before reading anything it
 *  intends to act on:
 *
 *      SELECT … FROM auction_lots WHERE id = ? FOR UPDATE
 *
 *  That lock is the whole concurrency story. Two owners clicking "Bid" in
 *  the same millisecond both reach placeBid(); the first to acquire the lot
 *  row proceeds, the second blocks until the first commits and then re-reads
 *  a current_bid that already includes the first bid — so it fails
 *  BID_TOO_LOW instead of overwriting. Without FOR UPDATE both would read
 *  the stale bid and the later write would silently win.
 *
 *  Lock order is always lot → team. Nothing in this class takes them the
 *  other way round, which is what keeps it deadlock-free.
 *
 *  Money is compared in integer paise, never in floats: 32_50_000.00 is
 *  exactly representable, but the sum of a few increments is not guaranteed
 *  to be, and "is this bid >= the minimum" must not depend on that.
 */
final class AuctionService
{
    /**
     * Record a bid from a team.
     *
     * Rejects — as AuctionException — a lot that isn't live, an expired
     * countdown, a team bidding against itself, a bid below the next legal
     * step, a full squad, an overseas quota breach, and (the one the brief
     * asks about) a team that cannot afford it.
     *
     * @return array<string,mixed> the lot's new public state
     */
    public function placeBid(
        int $lotId,
        int $teamId,
        ?int $userId,
        float|int|string $amount,
        ?string $ipAddress = null,
    ): array {
        $amountPaise = self::paise($amount);

        return Database::transaction(function (PDO $pdo) use ($lotId, $teamId, $userId, $amountPaise, $ipAddress): array {
            $lot  = $this->lockLot($lotId);
            $team = $this->lockTeam($teamId);

            if ((int) $lot['tournament_id'] !== (int) $team['tournament_id']) {
                throw new AuctionException(
                    AuctionException::WRONG_TOURNAMENT,
                    'That team is not part of this tournament.',
                    [],
                    403
                );
            }

            if ($lot['status'] !== 'live') {
                throw new AuctionException(
                    AuctionException::LOT_NOT_LIVE,
                    'This lot is not open for bidding.',
                    ['status' => $lot['status']]
                );
            }

            if ((int) $lot['is_expired'] === 1) {
                throw new AuctionException(
                    AuctionException::LOT_EXPIRED,
                    'The hammer has already fallen on this lot.',
                    ['ends_at' => $lot['ends_at']]
                );
            }

            // Bidding against yourself just inflates the price for no reason.
            if ($lot['current_bidder_team_id'] !== null
                && (int) $lot['current_bidder_team_id'] === $teamId
            ) {
                throw new AuctionException(
                    AuctionException::ALREADY_LEADING,
                    'You already hold the highest bid.'
                );
            }

            $rules     = $this->tournamentRules((int) $lot['tournament_id']);
            $increment = self::paise($rules['bid_increment']);
            $basePaise = self::paise($lot['base_price']);
            $minimum   = $lot['current_bid'] === null
                ? $basePaise
                : self::paise($lot['current_bid']) + $increment;

            if ($amountPaise < $minimum) {
                throw new AuctionException(
                    AuctionException::BID_TOO_LOW,
                    'The bid must be at least ' . self::rupees($minimum) . '.',
                    ['minimum' => self::rupees($minimum), 'current_bid' => $lot['current_bid']]
                );
            }

            // Keep every bid on the increment grid so the ladder stays readable.
            if ((($amountPaise - $basePaise) % $increment) !== 0) {
                throw new AuctionException(
                    AuctionException::BID_NOT_ALIGNED,
                    'Bids must move in steps of ' . self::rupees($increment) . '.',
                    ['increment' => self::rupees($increment)]
                );
            }

            $this->assertSquadHasRoom($team, $rules, (int) $lot['player_id']);
            $this->assertCanAfford($team, $rules, $lot, $amountPaise);

            // Append to the audit log first: the UNIQUE (lot_id, bid_amount)
            // index is a second line of defence behind the row lock — if two
            // transactions somehow reached the same amount, one gets a
            // duplicate-key error rather than a silently lost bid.
            Database::run(
                'INSERT INTO auction_bids (lot_id, player_id, team_id, user_id, bid_amount, ip_address)
                 VALUES (:lot, :player, :team, :user, :amount, INET6_ATON(:ip))',
                [
                    ':lot'    => $lotId,
                    ':player' => (int) $lot['player_id'],
                    ':team'   => $teamId,
                    ':user'   => $userId,
                    ':amount' => self::rupees($amountPaise),
                    ':ip'     => $ipAddress,
                ]
            );

            // Read it now: the driver's insert id reflects the most recent
            // statement, and the UPDATE below resets it to 0.
            $bidId = Database::lastInsertId();

            // Each accepted bid restarts the countdown (anti-snipe).
            Database::run(
                'UPDATE auction_lots
                    SET current_bid            = :amount,
                        current_bidder_team_id = :team,
                        bid_count              = bid_count + 1,
                        ends_at                = NOW() + INTERVAL :secs SECOND
                  WHERE id = :lot',
                [
                    ':amount' => self::rupees($amountPaise),
                    ':team'   => $teamId,
                    ':secs'   => (int) $rules['bid_timer_seconds'],
                    ':lot'    => $lotId,
                ]
            );

            return $this->lotState($lotId) + ['ok' => true, 'bid_id' => $bidId];
        });
    }

    /**
     * Bring the hammer down: award the lot to the leading bidder.
     *
     * Four writes, one transaction — the lot closes, the player becomes
     * 'sold' and joins the squad, the team's purse is debited, and the
     * winning bid is flagged in the log. Any one of them failing rolls back
     * all four, so a player can never be sold without the money moving.
     *
     * @return array<string,mixed>
     */
    public function sell(int $lotId, ?int $closedByUserId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($lotId, $closedByUserId): array {
            $lot = $this->lockLot($lotId);

            if (!in_array($lot['status'], ['live', 'paused'], true)) {
                throw new AuctionException(
                    AuctionException::LOT_NOT_LIVE,
                    'This lot has already been closed.',
                    ['status' => $lot['status']]
                );
            }

            if ($lot['current_bid'] === null || $lot['current_bidder_team_id'] === null) {
                throw new AuctionException(
                    AuctionException::NO_BIDS,
                    'Nobody has bid on this player — mark the lot unsold instead.'
                );
            }

            $teamId = (int) $lot['current_bidder_team_id'];
            $team   = $this->lockTeam($teamId);
            $rules  = $this->tournamentRules((int) $lot['tournament_id']);
            $price  = self::paise($lot['current_bid']);

            // Re-check under the lock. The purse cannot have moved while we
            // hold the lot (all spending goes through this method), but a
            // manual correction in the admin panel could have, and quietly
            // driving a team negative is worse than refusing to close.
            $this->assertSquadHasRoom($team, $rules, (int) $lot['player_id']);

            if (self::paise($team['purse_remaining']) < $price) {
                throw new AuctionException(
                    AuctionException::INSUFFICIENT_PURSE,
                    $team['name'] . ' can no longer cover ' . self::rupees($price) . '.',
                    ['purse_remaining' => $team['purse_remaining']]
                );
            }

            $priceRupees = self::rupees($price);

            Database::run(
                'UPDATE auction_lots
                    SET status             = :sold,
                        sold_to_team_id    = :team,
                        sold_price         = :price,
                        closed_at          = NOW(),
                        closed_by_user_id  = :user
                  WHERE id = :lot',
                [':sold' => 'sold', ':team' => $teamId, ':price' => $priceRupees,
                 ':user' => $closedByUserId, ':lot' => $lotId]
            );

            // players.status -> 'sold'. The chk_player_sold constraint refuses
            // this row unless team_id is set and the price clears base price,
            // so a half-applied sale cannot be written even by accident.
            Database::run(
                'UPDATE players
                    SET status     = :sold,
                        team_id    = :team,
                        sold_price = :price
                  WHERE id = :player',
                [':sold' => 'sold', ':team' => $teamId, ':price' => $priceRupees,
                 ':player' => (int) $lot['player_id']]
            );

            // Debit the purse. purse_remaining is a generated column, so it
            // follows automatically; chk_team_spent rejects an overdraft.
            Database::run(
                'UPDATE teams
                    SET purse_spent     = purse_spent + :price,
                        players_bought  = players_bought + 1,
                        overseas_bought = overseas_bought + :overseas
                  WHERE id = :team',
                [':price' => $priceRupees, ':overseas' => (int) $lot['is_overseas'], ':team' => $teamId]
            );

            Database::run(
                'UPDATE auction_bids SET is_winning = 1
                  WHERE lot_id = :lot AND team_id = :team AND bid_amount = :price',
                [':lot' => $lotId, ':team' => $teamId, ':price' => $priceRupees]
            );

            $fresh = $this->lockTeam($teamId);

            return [
                'ok'         => true,
                'outcome'    => 'sold',
                'lot_id'     => $lotId,
                'player_id'  => (int) $lot['player_id'],
                'player'     => $lot['full_name'],
                'team_id'    => $teamId,
                'team'       => $team['name'],
                'price'      => $priceRupees,
                'purse_left' => $fresh['purse_remaining'],
                'squad_size' => (int) $fresh['players_bought'],
            ];
        });
    }

    /**
     * Close a lot with no winner. The player returns to the pool as 'unsold'
     * and can be re-listed in a later round.
     *
     * @return array<string,mixed>
     */
    public function markUnsold(int $lotId, ?int $closedByUserId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($lotId, $closedByUserId): array {
            $lot = $this->lockLot($lotId);

            if (!in_array($lot['status'], ['live', 'paused'], true)) {
                throw new AuctionException(
                    AuctionException::LOT_NOT_LIVE,
                    'This lot has already been closed.',
                    ['status' => $lot['status']]
                );
            }

            Database::run(
                'UPDATE auction_lots
                    SET status = :unsold, closed_at = NOW(), closed_by_user_id = :user
                  WHERE id = :lot',
                [':unsold' => 'unsold', ':user' => $closedByUserId, ':lot' => $lotId]
            );

            Database::run(
                'UPDATE players SET status = :unsold WHERE id = :player',
                [':unsold' => 'unsold', ':player' => (int) $lot['player_id']]
            );

            return [
                'ok'        => true,
                'outcome'   => 'unsold',
                'lot_id'    => $lotId,
                'player_id' => (int) $lot['player_id'],
                'player'    => $lot['full_name'],
            ];
        });
    }

    /**
     * Put the next queued player under the hammer.
     *
     * @return array<string,mixed>
     */
    public function startNextLot(int $tournamentId, ?int $startedByUserId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($tournamentId, $startedByUserId): array {
            $open = Database::one(
                'SELECT id FROM auction_lots
                  WHERE tournament_id = :t AND status IN (:live, :paused)
                  LIMIT 1 FOR UPDATE',
                [':t' => $tournamentId, ':live' => 'live', ':paused' => 'paused']
            );

            if ($open !== null) {
                throw new AuctionException(
                    AuctionException::LOT_ALREADY_OPEN,
                    'Close the current lot before starting the next one.',
                    ['lot_id' => (int) $open['id']]
                );
            }

            $next = Database::one(
                'SELECT id, player_id FROM auction_lots
                  WHERE tournament_id = :t AND status = :queued
               ORDER BY lot_order
                  LIMIT 1 FOR UPDATE',
                [':t' => $tournamentId, ':queued' => 'queued']
            );

            if ($next === null) {
                throw new AuctionException(
                    AuctionException::NOTHING_QUEUED,
                    'No players left in the queue.'
                );
            }

            $rules = $this->tournamentRules($tournamentId);

            Database::run(
                'UPDATE auction_lots
                    SET status = :live, started_at = NOW(), ends_at = NOW() + INTERVAL :secs SECOND
                  WHERE id = :lot',
                [':live' => 'live', ':secs' => (int) $rules['bid_timer_seconds'], ':lot' => (int) $next['id']]
            );

            Database::run(
                'UPDATE players SET status = :inAuction WHERE id = :player',
                [':inAuction' => 'in_auction', ':player' => (int) $next['player_id']]
            );

            return $this->lotState((int) $next['id']) + ['ok' => true];
        });
    }

    /**
     * Everything the dashboard needs for one poll: the live lot, the purse
     * board and the recent bid feed. Read-only, no locks.
     *
     * @return array<string,mixed>
     */
    public function liveState(int $tournamentId): array
    {
        $lot = Database::one(
            'SELECT * FROM v_live_auction WHERE tournament_id = :t LIMIT 1',
            [':t' => $tournamentId]
        );

        $bids = $lot === null ? [] : Database::all(
            'SELECT b.bid_amount, b.placed_at, t.short_name, t.name AS team_name, t.primary_color
               FROM auction_bids b
               JOIN teams t ON t.id = b.team_id
              WHERE b.lot_id = :lot
           ORDER BY b.placed_at DESC, b.id DESC
              LIMIT 8',
            [':lot' => (int) $lot['lot_id']]
        );

        return [
            'ok'    => true,
            'lot'   => $lot,
            'teams' => Database::all(
                'SELECT id, name, short_name, primary_color, purse_total, purse_spent,
                        purse_remaining, players_bought, overseas_bought
                   FROM teams WHERE tournament_id = :t ORDER BY purse_remaining DESC',
                [':t' => $tournamentId]
            ),
            'bids'  => $bids,
        ];
    }

    // -----------------------------------------------------------------
    //  Guards
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $team
     * @param array<string,mixed> $rules
     */
    private function assertSquadHasRoom(array $team, array $rules, int $playerId): void
    {
        if ((int) $team['players_bought'] >= (int) $rules['max_squad_size']) {
            throw new AuctionException(
                AuctionException::SQUAD_FULL,
                $team['name'] . ' already has a full squad of ' . $rules['max_squad_size'] . '.',
                ['players_bought' => (int) $team['players_bought']]
            );
        }

        $player = Database::one('SELECT is_overseas FROM players WHERE id = :id', [':id' => $playerId]);

        if ($player !== null
            && (int) $player['is_overseas'] === 1
            && (int) $team['overseas_bought'] >= (int) $rules['max_overseas']
        ) {
            throw new AuctionException(
                AuctionException::OVERSEAS_LIMIT,
                $team['name'] . ' has already signed ' . $rules['max_overseas'] . ' overseas players.',
                ['overseas_bought' => (int) $team['overseas_bought']]
            );
        }
    }

    /**
     * The purse check.
     *
     * A team may not simply spend down to zero: it still has to be able to
     * field a legal squad afterwards. So the affordable ceiling is
     *
     *      purse_remaining − (slots still needed × cheapest player left)
     *
     * where "slots still needed" counts up to min_squad_size, and is capped
     * by how many players actually remain in the pool.
     *
     * @param array<string,mixed> $team
     * @param array<string,mixed> $rules
     * @param array<string,mixed> $lot
     */
    private function assertCanAfford(array $team, array $rules, array $lot, int $amountPaise): void
    {
        $remaining = self::paise($team['purse_remaining']);
        $reserve   = $this->reserveRequired($team, $rules, (int) $lot['player_id'], (int) $lot['tournament_id']);

        if ($amountPaise + $reserve > $remaining) {
            $ceiling = max(0, $remaining - $reserve);

            throw new AuctionException(
                AuctionException::INSUFFICIENT_PURSE,
                $reserve > 0
                    ? sprintf(
                        '%s can bid at most %s — %s of the purse is reserved to complete the squad.',
                        $team['name'],
                        self::rupees($ceiling),
                        self::rupees($reserve)
                    )
                    : sprintf('%s only has %s left.', $team['name'], self::rupees($remaining)),
                [
                    'purse_remaining' => self::rupees($remaining),
                    'reserved'        => self::rupees($reserve),
                    'max_bid'         => self::rupees($ceiling),
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $team
     * @param array<string,mixed> $rules
     */
    private function reserveRequired(array $team, array $rules, int $playerId, int $tournamentId): int
    {
        $slotsNeeded = (int) $rules['min_squad_size'] - ((int) $team['players_bought'] + 1);

        if ($slotsNeeded <= 0) {
            return 0;
        }

        $pool = Database::one(
            'SELECT COUNT(*) AS available, COALESCE(MIN(base_price), 0) AS cheapest
               FROM players
              WHERE tournament_id = :t
                AND id <> :player
                AND status IN (:available, :inAuction, :unsold)',
            [':t' => $tournamentId, ':player' => $playerId,
             ':available' => 'available', ':inAuction' => 'in_auction', ':unsold' => 'unsold']
        );

        // Can't reserve for slots there are no players left to fill.
        $slotsNeeded = min($slotsNeeded, (int) ($pool['available'] ?? 0));

        return $slotsNeeded * self::paise($pool['cheapest'] ?? 0);
    }

    // -----------------------------------------------------------------
    //  Reads
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function lockLot(int $lotId): array
    {
        $lot = Database::one(
            'SELECT l.id, l.tournament_id, l.player_id, l.status, l.base_price, l.current_bid,
                    l.current_bidder_team_id, l.bid_count, l.ends_at,
                    (l.ends_at IS NOT NULL AND l.ends_at < NOW()) AS is_expired,
                    p.full_name, p.is_overseas
               FROM auction_lots l
               JOIN players p ON p.id = l.player_id
              WHERE l.id = :lot
              LIMIT 1
                FOR UPDATE',
            [':lot' => $lotId]
        );

        if ($lot === null) {
            throw new AuctionException(AuctionException::LOT_NOT_FOUND, 'Lot not found.', [], 404);
        }

        return $lot;
    }

    /** @return array<string,mixed> */
    private function lockTeam(int $teamId): array
    {
        $team = Database::one(
            'SELECT id, tournament_id, name, short_name, purse_total, purse_spent,
                    purse_remaining, players_bought, overseas_bought
               FROM teams
              WHERE id = :team
              LIMIT 1
                FOR UPDATE',
            [':team' => $teamId]
        );

        if ($team === null) {
            throw new AuctionException(AuctionException::TEAM_NOT_FOUND, 'Team not found.', [], 404);
        }

        return $team;
    }

    /** @return array<string,mixed> */
    private function tournamentRules(int $tournamentId): array
    {
        $rules = Database::one(
            'SELECT bid_increment, bid_timer_seconds, min_squad_size, max_squad_size, max_overseas
               FROM tournaments WHERE id = :t',
            [':t' => $tournamentId]
        );

        if ($rules === null) {
            throw new AuctionException(AuctionException::LOT_NOT_FOUND, 'Tournament not found.', [], 404);
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    private function lotState(int $lotId): array
    {
        $lot = Database::one(
            'SELECT l.id AS lot_id, l.status, l.base_price, l.current_bid, l.bid_count, l.ends_at,
                    TIMESTAMPDIFF(SECOND, NOW(), l.ends_at) AS seconds_left,
                    l.current_bidder_team_id,
                    t.name AS bidder_team_name, t.short_name AS bidder_team_short,
                    t.primary_color AS bidder_team_color,
                    p.full_name AS player_name
               FROM auction_lots l
               JOIN players p ON p.id = l.player_id
          LEFT JOIN teams   t ON t.id = l.current_bidder_team_id
              WHERE l.id = :lot',
            [':lot' => $lotId]
        );

        return ['lot' => $lot];
    }

    // -----------------------------------------------------------------
    //  Money — integers only
    // -----------------------------------------------------------------

    /** DECIMAL(14,2) arrives from PDO as a string; convert to whole paise. */
    private static function paise(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Back to the '1234.00' form the DECIMAL columns expect. */
    private static function rupees(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }
}
