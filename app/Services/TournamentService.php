<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AccountException;
use Database;
use PDO;

/**
 * =====================================================================
 *  Tournaments — the season, its secret code, and who gets into it
 * =====================================================================
 *
 *  A tournament is created by an administrator with four dates and a secret
 *  code. The code is the only way a player joins: they type it in, which
 *  files an application, and an administrator approves it. **Approval is
 *  what puts a name into the auction** — it creates the player record and
 *  the auction lot in one transaction, so a player can never appear in the
 *  auction list without a named administrator having let them in, and can
 *  never be approved without a lot to be bid on.
 *
 *  The four dates
 *    start_date                 when play begins
 *    auction_date               when the hammer falls; applications close
 *                               at the end of this day
 *    end_date                   when the season finishes
 *    team_name_change_deadline  the last day an owner may rename their team
 *
 *  Deadlines are compared against CURDATE() in the database rather than
 *  PHP's clock, so the whole application agrees on what "today" is even if
 *  the web server and the database are set to different time zones.
 */
final class TournamentService
{
    // -----------------------------------------------------------------
    //  Creating and editing a tournament
    // -----------------------------------------------------------------

    /**
     * An administrator creates a tournament.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed> the new tournament, secret code included
     */
    public function create(array $in): array
    {
        $name   = $this->text($in['name'] ?? '', 'Tournament name', 3, 120);
        $season = (int) ($in['season_year'] ?? (int) date('Y'));

        if ($season < 2000 || $season > 2100) {
            throw new AccountException(AccountException::VALIDATION, 'Season year looks wrong.');
        }

        $dates = $this->dates($in);

        if ((int) Database::scalar(
            'SELECT COUNT(*) FROM tournaments WHERE name = :n AND season_year = :s',
            [':n' => $name, ':s' => $season]
        ) > 0) {
            throw new AccountException(
                AccountException::NAME_TAKEN,
                'A tournament called "' . $name . '" already exists for ' . $season . '.'
            );
        }

        $code = $this->freshSecretCode();

        Database::run(
            'INSERT INTO tournaments
                (name, season_year, secret_code, start_date, auction_date, end_date,
                 team_name_change_deadline, registration_open,
                 purse_per_team, min_squad_size, max_squad_size, max_overseas,
                 bid_increment, bid_timer_seconds, overs_per_innings, status)
             VALUES
                (:name, :season, :code, :start, :auction, :end,
                 :rename, 1,
                 :purse, :minSquad, :maxSquad, :overseas,
                 :increment, :timer, :overs, :status)',
            [
                ':name'      => $name,
                ':season'    => $season,
                ':code'      => $code,
                ':start'     => $dates['start_date'],
                ':auction'   => $dates['auction_date'],
                ':end'       => $dates['end_date'],
                ':rename'    => $dates['team_name_change_deadline'],
                ':purse'     => $this->money($in['purse_per_team'] ?? null, 'Purse per team', 10000000),
                ':minSquad'  => $this->count($in['min_squad_size'] ?? null, 'Minimum squad size', 1, 25, 11),
                ':maxSquad'  => $this->count($in['max_squad_size'] ?? null, 'Maximum squad size', 1, 25, 15),
                ':overseas'  => $this->count($in['max_overseas'] ?? null, 'Overseas limit', 0, 25, 4),
                ':increment' => $this->money($in['bid_increment'] ?? null, 'Bid increment', 500000),
                ':timer'     => $this->count($in['bid_timer_seconds'] ?? null, 'Bid timer', 5, 600, 30),
                ':overs'     => $this->count($in['overs_per_innings'] ?? null, 'Overs per innings', 1, 50, 20),
                ':status'    => 'draft',
            ]
        );

        return $this->find(Database::lastInsertId());
    }

    /**
     * An administrator edits a tournament. Only the keys present in $in are
     * touched, so a form that shows three fields cannot blank the rest.
     *
     * @param array<string,mixed> $in
     */
    public function update(int $tournamentId, array $in): array
    {
        $current = $this->find($tournamentId);

        $set    = [];
        $params = [':id' => $tournamentId];

        if (array_key_exists('name', $in)) {
            $name = $this->text($in['name'], 'Tournament name', 3, 120);

            if ((int) Database::scalar(
                'SELECT COUNT(*) FROM tournaments
                  WHERE name = :n AND season_year = :s AND id <> :id',
                [':n' => $name, ':s' => (int) $current['season_year'], ':id' => $tournamentId]
            ) > 0) {
                throw new AccountException(AccountException::NAME_TAKEN, 'Another tournament already has that name this season.');
            }

            $set[]          = 'name = :name';
            $params[':name'] = $name;
        }

        // Validate the four dates as a set, using the stored values for any
        // the form did not send — otherwise moving one date could put it the
        // wrong side of another without anything noticing.
        $dateKeys = ['start_date', 'auction_date', 'end_date', 'team_name_change_deadline'];

        if (array_intersect($dateKeys, array_keys($in)) !== []) {
            $merged = [];

            foreach ($dateKeys as $key) {
                $merged[$key] = array_key_exists($key, $in) ? $in[$key] : $current[$key];
            }

            foreach ($this->dates($merged) as $key => $value) {
                $set[]                 = "`{$key}` = :{$key}";
                $params[":{$key}"]     = $value;
            }
        }

        if (array_key_exists('registration_open', $in)) {
            $set[]                        = 'registration_open = :regOpen';
            $params[':regOpen']           = $in['registration_open'] ? 1 : 0;
        }

        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['draft', 'auction', 'ongoing', 'completed'], true)) {
                throw new AccountException(AccountException::VALIDATION, 'That is not a tournament status.');
            }

            $set[]              = 'status = :status';
            $params[':status']  = $in['status'];
        }

        foreach ([
            'purse_per_team'    => ['money', 'Purse per team'],
            'bid_increment'     => ['money', 'Bid increment'],
            'min_squad_size'    => ['count', 'Minimum squad size', 1, 25],
            'max_squad_size'    => ['count', 'Maximum squad size', 1, 25],
            'max_overseas'      => ['count', 'Overseas limit', 0, 25],
            'bid_timer_seconds' => ['count', 'Bid timer', 5, 600],
            'overs_per_innings' => ['count', 'Overs per innings', 1, 50],
        ] as $column => $rule) {
            if (!array_key_exists($column, $in)) {
                continue;
            }

            $set[]             = "`{$column}` = :{$column}";
            $params[":{$column}"] = $rule[0] === 'money'
                ? $this->money($in[$column], $rule[1])
                : $this->count($in[$column], $rule[1], $rule[2], $rule[3]);
        }

        if ($set === []) {
            return $current;
        }

        Database::exec('UPDATE tournaments SET ' . implode(', ', $set) . ' WHERE id = :id', $params);

        return $this->find($tournamentId);
    }

    /**
     * Issue a new secret code — for when the old one has been shared too
     * widely. Applications already filed are unaffected; only new ones need
     * the new code.
     */
    public function regenerateSecretCode(int $tournamentId): string
    {
        $this->find($tournamentId);

        $code = $this->freshSecretCode();

        Database::exec(
            'UPDATE tournaments SET secret_code = :code WHERE id = :id',
            [':code' => $code, ':id' => $tournamentId]
        );

        return $code;
    }

    // -----------------------------------------------------------------
    //  Applying with the secret code
    // -----------------------------------------------------------------

    /**
     * A player joins a tournament by typing its secret code.
     *
     * This files an application. It does **not** put them in the auction —
     * decideApplication() does, and only an administrator can call it.
     *
     * @return array<string,mixed>
     */
    public function apply(int $userId, string $secretCode): array
    {
        $user = Database::one('SELECT id, name, role, status FROM users WHERE id = :id', [':id' => $userId]);

        if ($user === null) {
            throw new AccountException(AccountException::NOT_FOUND, 'No such account.', [], 404);
        }

        // Two separate approvals guard the auction list: the administrator
        // approves the person once, here we check that has happened.
        if ($user['status'] !== 'approved') {
            throw new AccountException(
                AccountException::NOT_APPROVED,
                $user['status'] === 'pending'
                    ? 'Your registration is still waiting for an administrator. You can apply once it is approved.'
                    : 'This account cannot join a tournament.',
                ['status' => $user['status']],
                403
            );
        }

        // A team owner may play as well, so they are allowed in here too.
        if (!in_array($user['role'], ['player', 'team_owner'], true)) {
            throw new AccountException(
                AccountException::VALIDATION,
                'Only players can enter an auction.',
                ['role' => $user['role']],
                403
            );
        }

        $tournament = $this->findByCode($secretCode);

        if ((int) $tournament['registration_open'] !== 1) {
            throw new AccountException(
                AccountException::REGISTRATION_SHUT,
                'Registration for ' . $tournament['name'] . ' is closed.'
            );
        }

        // Applications close at the end of auction day — after that the lots
        // are already ordered and a late entry would land behind the hammer.
        if ($tournament['auction_date'] !== null && $this->isPast($tournament['auction_date'])) {
            throw new AccountException(
                AccountException::DEADLINE_PASSED,
                'The auction for ' . $tournament['name'] . ' was on '
                    . $this->pretty($tournament['auction_date']) . '. Entries are closed.'
            );
        }

        $tournamentId = (int) $tournament['id'];

        $existing = Database::one(
            'SELECT id, status FROM tournament_registrations
              WHERE tournament_id = :t AND user_id = :u',
            [':t' => $tournamentId, ':u' => $userId]
        );

        if ($existing !== null && in_array($existing['status'], ['pending', 'approved'], true)) {
            throw new AccountException(
                AccountException::ALREADY_APPLIED,
                $existing['status'] === 'approved'
                    ? 'You are already in ' . $tournament['name'] . '.'
                    : 'You have already applied. An administrator will review it.',
                ['status' => $existing['status']]
            );
        }

        if ($existing !== null) {
            // Rejected or withdrawn: let them try again on the same row, and
            // clear the old decision so it re-enters the queue clean.
            Database::exec(
                "UPDATE tournament_registrations
                    SET status = 'pending', applied_at = NOW(),
                        decided_by = NULL, decided_at = NULL, note = NULL
                  WHERE id = :id",
                [':id' => (int) $existing['id']]
            );

            $registrationId = (int) $existing['id'];
        } else {
            Database::run(
                'INSERT INTO tournament_registrations (tournament_id, user_id) VALUES (:t, :u)',
                [':t' => $tournamentId, ':u' => $userId]
            );

            $registrationId = Database::lastInsertId();
        }

        return [
            'ok'              => true,
            'registration_id' => $registrationId,
            'tournament_id'   => $tournamentId,
            'tournament'      => $tournament['name'],
            'status'          => 'pending',
        ];
    }

    /** A player pulls out of a tournament they have applied to. */
    public function withdraw(int $userId, int $tournamentId): void
    {
        $affected = Database::exec(
            "UPDATE tournament_registrations
                SET status = 'withdrawn', decided_at = NOW()
              WHERE tournament_id = :t AND user_id = :u AND status = 'pending'",
            [':t' => $tournamentId, ':u' => $userId]
        );

        if ($affected === 0) {
            throw new AccountException(
                AccountException::ALREADY_DECIDED,
                'There is no pending application to withdraw.'
            );
        }
    }

    /**
     * An administrator decides on an application.
     *
     * On approval this writes the player into the auction pool and gives
     * them a lot at the back of the queue, in one transaction with the
     * decision itself. Approved and "in the auction list" are therefore the
     * same event — there is no window in which one is true and the other is
     * not.
     *
     * @param array<string,mixed> $overrides base_price, country, is_overseas,
     *                                       batting_style, auction_set…
     * @return array<string,mixed>
     */
    public function decideApplication(
        int $registrationId,
        bool $approve,
        int $adminId,
        ?string $note = null,
        array $overrides = [],
    ): array {
        return Database::transaction(function (PDO $pdo) use ($registrationId, $approve, $adminId, $note, $overrides): array {
            $reg = Database::one(
                'SELECT r.id, r.tournament_id, r.user_id, r.status,
                        u.name, u.email, u.phone, u.photo_path, u.player_type, u.status AS user_status
                   FROM tournament_registrations r
                   JOIN users u ON u.id = r.user_id
                  WHERE r.id = :id
                  LIMIT 1
                    FOR UPDATE',
                [':id' => $registrationId]
            );

            if ($reg === null) {
                throw new AccountException(AccountException::NOT_FOUND, 'No such application.', [], 404);
            }

            if ($reg['status'] !== 'pending') {
                throw new AccountException(
                    AccountException::ALREADY_DECIDED,
                    'That application has already been ' . $reg['status'] . '.'
                );
            }

            if ($approve && $reg['user_status'] !== 'approved') {
                throw new AccountException(
                    AccountException::NOT_APPROVED,
                    'Approve the account itself before letting it into a tournament.'
                );
            }

            Database::exec(
                'UPDATE tournament_registrations
                    SET status = :status, decided_by = :admin, decided_at = NOW(), note = :note
                  WHERE id = :id',
                [
                    ':status' => $approve ? 'approved' : 'rejected',
                    ':admin'  => $adminId,
                    ':note'   => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                    ':id'     => $registrationId,
                ]
            );

            if (!$approve) {
                return [
                    'ok'      => true,
                    'status'  => 'rejected',
                    'user_id' => (int) $reg['user_id'],
                    'player'  => $reg['name'],
                ];
            }

            $playerId = $this->enterAuctionPool($reg, $overrides);
            $lotId    = $this->queueLot((int) $reg['tournament_id'], $playerId);

            return [
                'ok'        => true,
                'status'    => 'approved',
                'user_id'   => (int) $reg['user_id'],
                'player'    => $reg['name'],
                'player_id' => $playerId,
                'lot_id'    => $lotId,
            ];
        });
    }

    /**
     * Write an approved applicant into players, or reuse the row if they are
     * already there (an admin may have entered them by hand first).
     *
     * @param array<string,mixed> $reg
     * @param array<string,mixed> $overrides
     */
    private function enterAuctionPool(array $reg, array $overrides): int
    {
        $tournamentId = (int) $reg['tournament_id'];
        $userId       = (int) $reg['user_id'];

        $existing = Database::one(
            'SELECT id FROM players WHERE tournament_id = :t AND user_id = :u',
            [':t' => $tournamentId, ':u' => $userId]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        Database::run(
            'INSERT INTO players
                (tournament_id, user_id, full_name, display_name, photo_url, country,
                 role, batting_style, bowling_style, is_overseas, is_capped,
                 auction_set, base_price, status)
             VALUES
                (:t, :u, :name, :display, :photo, :country,
                 :role, :batting, :bowling, :overseas, :capped,
                 :set, :base, :status)',
            [
                ':t'       => $tournamentId,
                ':u'       => $userId,
                ':name'    => $reg['name'],
                ':display' => mb_substr((string) $reg['name'], 0, 60),
                ':photo'   => $reg['photo_path'],
                ':country' => $this->text($overrides['country'] ?? 'India', 'Country', 2, 60),
                // player_type is what they registered as; an admin can correct
                // it on the approval form.
                ':role'    => $this->playerRole($overrides['role'] ?? $reg['player_type'] ?? 'batsman'),
                ':batting' => in_array($overrides['batting_style'] ?? '', ['right_hand', 'left_hand'], true)
                                ? $overrides['batting_style'] : null,
                ':bowling' => $overrides['bowling_style'] ?? 'none',
                ':overseas' => !empty($overrides['is_overseas']) ? 1 : 0,
                ':capped'   => !empty($overrides['is_capped']) ? 1 : 0,
                ':set'      => isset($overrides['auction_set']) && trim((string) $overrides['auction_set']) !== ''
                                ? mb_substr(trim((string) $overrides['auction_set']), 0, 40) : null,
                ':base'     => $this->money($overrides['base_price'] ?? 200000, 'Base price'),
                ':status'   => 'available',
            ]
        );

        return Database::lastInsertId();
    }

    /** Give a player a lot at the back of the auction queue. */
    private function queueLot(int $tournamentId, int $playerId): int
    {
        $existing = Database::one(
            'SELECT id FROM auction_lots WHERE tournament_id = :t AND player_id = :p',
            [':t' => $tournamentId, ':p' => $playerId]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $order = 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(lot_order), 0) FROM auction_lots WHERE tournament_id = :t',
            [':t' => $tournamentId]
        );

        Database::run(
            'INSERT INTO auction_lots (tournament_id, player_id, lot_order, status, base_price)
             SELECT :t, :p, :order, :status, base_price FROM players WHERE id = :p2',
            [':t' => $tournamentId, ':p' => $playerId, ':order' => $order,
             ':status' => 'queued', ':p2' => $playerId]
        );

        return Database::lastInsertId();
    }

    // -----------------------------------------------------------------
    //  Teams — one owner each, renameable until the deadline
    // -----------------------------------------------------------------

    /**
     * A team owner names their team. The account becomes a team_owner and is
     * bound to that team; the unique index on users.team_id means a second
     * owner for the same team is refused by the database, not just by this
     * method.
     *
     * @param array<string,mixed> $in name, short_name, primary_color, home_venue
     * @return array<string,mixed>
     */
    public function createTeam(int $ownerUserId, int $tournamentId, array $in): array
    {
        return Database::transaction(function (PDO $pdo) use ($ownerUserId, $tournamentId, $in): array {
            $user = Database::one(
                'SELECT id, name, role, status, team_id FROM users WHERE id = :id LIMIT 1 FOR UPDATE',
                [':id' => $ownerUserId]
            );

            if ($user === null) {
                throw new AccountException(AccountException::NOT_FOUND, 'No such account.', [], 404);
            }

            if ($user['status'] !== 'approved') {
                throw new AccountException(
                    AccountException::NOT_APPROVED,
                    'This account is not approved yet.',
                    [],
                    403
                );
            }

            if ($user['team_id'] !== null) {
                throw new AccountException(
                    AccountException::ALREADY_APPLIED,
                    'You already own a team. Rename it instead of creating another.',
                    ['team_id' => (int) $user['team_id']]
                );
            }

            $tournament = $this->find($tournamentId);
            $name       = $this->teamName($in['name'] ?? '', $tournamentId);
            $short      = $this->shortName($in['short_name'] ?? '', $tournamentId);

            Database::run(
                'INSERT INTO teams
                    (tournament_id, name, short_name, primary_color, home_venue, purse_total)
                 VALUES (:t, :name, :short, :color, :venue, :purse)',
                [
                    ':t'     => $tournamentId,
                    ':name'  => $name,
                    ':short' => $short,
                    ':color' => $this->colour($in['primary_color'] ?? '#22c55e'),
                    ':venue' => isset($in['home_venue']) && trim((string) $in['home_venue']) !== ''
                                    ? mb_substr(trim((string) $in['home_venue']), 0, 120) : null,
                    ':purse' => $tournament['purse_per_team'],
                ]
            );

            $teamId = Database::lastInsertId();

            // role and team_id move together: chk_users_team_role refuses a
            // team_owner without a team, and anyone else with one.
            Database::exec(
                "UPDATE users SET role = 'team_owner', team_id = :team WHERE id = :id",
                [':team' => $teamId, ':id' => $ownerUserId]
            );

            return ['ok' => true, 'team_id' => $teamId, 'name' => $name, 'short_name' => $short];
        });
    }

    /**
     * Rename a team.
     *
     * An owner may do this until the end of the tournament's
     * team_name_change_deadline. An administrator is not bound by it —
     * that is the point of having an administrator.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function renameTeam(int $teamId, int $actorUserId, array $in, bool $actorIsAdmin = false): array
    {
        $team = Database::one(
            'SELECT t.id, t.tournament_id, t.name, t.short_name,
                    tr.name AS tournament_name, tr.team_name_change_deadline
               FROM teams t
               JOIN tournaments tr ON tr.id = t.tournament_id
              WHERE t.id = :id',
            [':id' => $teamId]
        );

        if ($team === null) {
            throw new AccountException(AccountException::NOT_FOUND, 'No such team.', [], 404);
        }

        if (!$actorIsAdmin) {
            $owns = (int) Database::scalar(
                'SELECT COUNT(*) FROM users WHERE id = :u AND team_id = :t',
                [':u' => $actorUserId, ':t' => $teamId]
            );

            if ($owns === 0) {
                throw new AccountException(
                    AccountException::NOT_YOUR_TEAM,
                    'You can only rename your own team.',
                    [],
                    403
                );
            }

            $deadline = $team['team_name_change_deadline'];

            if ($deadline !== null && $this->isPast($deadline)) {
                throw new AccountException(
                    AccountException::DEADLINE_PASSED,
                    'Team names were locked on ' . $this->pretty($deadline)
                        . '. Ask an administrator if it really has to change.',
                    ['deadline' => $deadline]
                );
            }
        }

        $tournamentId = (int) $team['tournament_id'];

        $set    = [];
        $params = [':id' => $teamId];

        if (array_key_exists('name', $in)) {
            $name = $this->teamName($in['name'], $tournamentId, $teamId);

            if ($name !== $team['name']) {
                $set[]           = 'name = :name';
                $params[':name'] = $name;
            }
        }

        if (array_key_exists('short_name', $in) && trim((string) $in['short_name']) !== '') {
            $short = $this->shortName($in['short_name'], $tournamentId, $teamId);

            if ($short !== $team['short_name']) {
                $set[]            = 'short_name = :short';
                $params[':short'] = $short;
            }
        }

        if (array_key_exists('primary_color', $in)) {
            $set[]            = 'primary_color = :color';
            $params[':color'] = $this->colour($in['primary_color']);
        }

        if (array_key_exists('home_venue', $in)) {
            $set[]            = 'home_venue = :venue';
            $params[':venue'] = trim((string) $in['home_venue']) !== ''
                ? mb_substr(trim((string) $in['home_venue']), 0, 120) : null;
        }

        if ($set !== []) {
            Database::exec('UPDATE teams SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        }

        return ['ok' => true, 'team_id' => $teamId] + (array) Database::one(
            'SELECT name, short_name, primary_color, home_venue FROM teams WHERE id = :id',
            [':id' => $teamId]
        );
    }

    /**
     * An administrator hands a team to an owner (or moves it to a new one).
     * The previous owner is released first, because users.team_id is unique.
     */
    public function assignOwner(int $teamId, int $userId): array
    {
        return Database::transaction(function (PDO $pdo) use ($teamId, $userId): array {
            $team = Database::one('SELECT id, name FROM teams WHERE id = :id', [':id' => $teamId]);

            if ($team === null) {
                throw new AccountException(AccountException::NOT_FOUND, 'No such team.', [], 404);
            }

            $user = Database::one('SELECT id, name, role, status FROM users WHERE id = :id', [':id' => $userId]);

            if ($user === null) {
                throw new AccountException(AccountException::NOT_FOUND, 'No such account.', [], 404);
            }

            if ($user['status'] !== 'approved') {
                throw new AccountException(AccountException::NOT_APPROVED, 'Approve the account first.', [], 403);
            }

            // Release the outgoing owner before claiming the row, so the
            // unique index never sees two rows holding the same team.
            Database::exec(
                "UPDATE users SET role = 'viewer', team_id = NULL
                  WHERE team_id = :team AND id <> :user",
                [':team' => $teamId, ':user' => $userId]
            );

            Database::exec(
                "UPDATE users SET role = 'team_owner', team_id = :team WHERE id = :user",
                [':team' => $teamId, ':user' => $userId]
            );

            return ['ok' => true, 'team_id' => $teamId, 'team' => $team['name'],
                    'owner_id' => $userId, 'owner' => $user['name']];
        });
    }

    // -----------------------------------------------------------------
    //  Reads
    // -----------------------------------------------------------------

    /**
     * Which tournament the auction screens are looking at.
     *
     * Every public auction page used to assume tournament 1. That holds
     * only until somebody creates a second tournament, or deletes their
     * first and starts again — the ids move on, id 1 is empty, and the
     * live auction board reports "No auction is running" through an
     * entire auction that is plainly happening. This resolves it instead
     * of assuming it.
     *
     * In order of authority:
     *   1. what the caller asked for, if it exists
     *   2. whichever tournament has a lot under the hammer right now
     *   3. the newest tournament that has an auction list at all
     *   4. the newest tournament, so a brand new install still has a name
     *
     * Null only when there are no tournaments whatsoever.
     */
    public function currentAuctionId(?int $preferred = null): ?int
    {
        if ($preferred !== null && $preferred > 0) {
            $asked = (int) Database::scalar(
                'SELECT id FROM tournaments WHERE id = :id',
                [':id' => $preferred]
            );

            if ($asked > 0) {
                return $asked;
            }
        }

        $live = (int) Database::scalar(
            "SELECT tournament_id FROM auction_lots WHERE status = 'live' ORDER BY id DESC LIMIT 1"
        );

        if ($live > 0) {
            return $live;
        }

        $withLots = (int) Database::scalar(
            'SELECT t.id
               FROM tournaments t
               JOIN auction_lots l ON l.tournament_id = t.id
           GROUP BY t.id, t.season_year
           ORDER BY t.season_year DESC, t.id DESC
              LIMIT 1'
        );

        if ($withLots > 0) {
            return $withLots;
        }

        $newest = (int) Database::scalar(
            'SELECT id FROM tournaments ORDER BY season_year DESC, id DESC LIMIT 1'
        );

        return $newest > 0 ? $newest : null;
    }

    /** @return array<string,mixed> */
    public function find(int $tournamentId): array
    {
        $row = Database::one('SELECT * FROM tournaments WHERE id = :id', [':id' => $tournamentId]);

        if ($row === null) {
            throw new AccountException(AccountException::NOT_FOUND, 'No such tournament.', [], 404);
        }

        return $row;
    }

    /**
     * Look a tournament up by its secret code.
     *
     * The code is compared case-insensitively and with spaces and hyphens
     * stripped, because it will be read aloud in a room and typed by hand.
     *
     * @return array<string,mixed>
     */
    public function findByCode(string $code): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        if ($code === '') {
            throw new AccountException(AccountException::BAD_SECRET_CODE, 'Enter the tournament code.');
        }

        $row = Database::one(
            'SELECT * FROM tournaments WHERE secret_code = :c LIMIT 1',
            [':c' => $code]
        );

        if ($row === null) {
            throw new AccountException(
                AccountException::BAD_SECRET_CODE,
                'That code does not match any tournament. Check it with whoever gave it to you.'
            );
        }

        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function listTournaments(): array
    {
        return Database::all(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM teams   WHERE tournament_id = t.id) AS team_count,
                    (SELECT COUNT(*) FROM players WHERE tournament_id = t.id) AS player_count,
                    (SELECT COUNT(*) FROM tournament_registrations
                      WHERE tournament_id = t.id AND status = \'pending\')    AS pending_count
               FROM tournaments t
           ORDER BY t.season_year DESC, t.name'
        );
    }

    /**
     * The approval queue.
     *
     * @return array<int,array<string,mixed>>
     */
    public function applications(int $tournamentId, string $status = 'pending'): array
    {
        return Database::all(
            'SELECT r.id, r.status, r.applied_at, r.note,
                    u.id AS user_id, u.username, u.name, u.email, u.phone, u.address,
                    u.photo_path, u.player_type, u.status AS user_status,
                    d.name AS decided_by_name, r.decided_at
               FROM tournament_registrations r
               JOIN users u ON u.id = r.user_id
          LEFT JOIN users d ON d.id = r.decided_by
              WHERE r.tournament_id = :t
                AND (:all = 1 OR r.status = :status)
           ORDER BY r.applied_at',
            [':t' => $tournamentId, ':all' => $status === 'all' ? 1 : 0, ':status' => $status]
        );
    }

    /**
     * Every tournament one person has applied to, with the decision.
     *
     * @return array<int,array<string,mixed>>
     */
    public function myApplications(int $userId): array
    {
        return Database::all(
            'SELECT r.id, r.status, r.applied_at, r.decided_at, r.note,
                    t.id AS tournament_id, t.name, t.season_year,
                    t.start_date, t.auction_date, t.end_date,
                    p.id AS player_id, p.status AS player_status, p.sold_price,
                    tm.name AS team_name
               FROM tournament_registrations r
               JOIN tournaments t ON t.id = r.tournament_id
          LEFT JOIN players p  ON p.tournament_id = t.id AND p.user_id = r.user_id
          LEFT JOIN teams   tm ON tm.id = p.team_id
              WHERE r.user_id = :u
           ORDER BY t.season_year DESC, r.applied_at DESC',
            [':u' => $userId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function teams(int $tournamentId): array
    {
        return Database::all(
            'SELECT t.*, u.id AS owner_id, u.name AS owner_name, u.email AS owner_email
               FROM teams t
          LEFT JOIN users u ON u.team_id = t.id
              WHERE t.tournament_id = :t
           ORDER BY t.name',
            [':t' => $tournamentId]
        );
    }

    /** Can this team still be renamed by its owner today? */
    public function canRenameTeam(int $tournamentId): bool
    {
        $deadline = Database::scalar(
            'SELECT team_name_change_deadline FROM tournaments WHERE id = :t',
            [':t' => $tournamentId]
        );

        return $deadline === null || !$this->isPast((string) $deadline);
    }

    // -----------------------------------------------------------------
    //  Validation
    // -----------------------------------------------------------------

    /**
     * The four dates, validated as one set.
     *
     * @param array<string,mixed> $in
     * @return array{start_date:?string,auction_date:?string,end_date:?string,team_name_change_deadline:?string}
     */
    private function dates(array $in): array
    {
        $start   = $this->date($in['start_date'] ?? null, 'Start date');
        $auction = $this->date($in['auction_date'] ?? null, 'Auction date');
        $end     = $this->date($in['end_date'] ?? null, 'End date');
        $rename  = $this->date($in['team_name_change_deadline'] ?? null, 'Team name change deadline');

        if ($start !== null && $end !== null && $end < $start) {
            throw new AccountException(
                AccountException::VALIDATION,
                'The end date cannot be before the start date.'
            );
        }

        // The auction is what fills the squads, so it belongs on or before
        // the first ball. It may share the day with the start.
        if ($auction !== null && $start !== null && $auction > $start) {
            throw new AccountException(
                AccountException::VALIDATION,
                'The auction date must be on or before the start date.'
            );
        }

        if ($rename !== null && $end !== null && $rename > $end) {
            throw new AccountException(
                AccountException::VALIDATION,
                'The team name change deadline must fall on or before the end date.'
            );
        }

        return [
            'start_date'                => $start,
            'auction_date'              => $auction,
            'end_date'                  => $end,
            'team_name_change_deadline' => $rename,
        ];
    }

    private function date(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);
        $parts = date_parse_from_format('Y-m-d', $value);

        if ($parts['error_count'] > 0 || $parts['warning_count'] > 0
            || !checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
        ) {
            throw new AccountException(
                AccountException::VALIDATION,
                "{$label} must be a real date in the form YYYY-MM-DD."
            );
        }

        return sprintf('%04d-%02d-%02d', $parts['year'], $parts['month'], $parts['day']);
    }

    /** True when the given date is strictly before today, per the database. */
    private function isPast(string $date): bool
    {
        return (int) Database::scalar('SELECT (:d < CURDATE())', [':d' => $date]) === 1;
    }

    private function pretty(string $date): string
    {
        $ts = strtotime($date);

        return $ts === false ? $date : date('j M Y', $ts);
    }

    private function text(mixed $value, string $label, int $min, int $max): string
    {
        $value = trim((string) $value);

        if (mb_strlen($value) < $min) {
            throw new AccountException(AccountException::VALIDATION, "{$label} is required.");
        }

        return mb_substr($value, 0, $max);
    }

    private function teamName(mixed $value, int $tournamentId, ?int $exceptTeamId = null): string
    {
        $name = $this->text($value, 'Team name', 3, 100);

        $clash = (int) Database::scalar(
            'SELECT COUNT(*) FROM teams
              WHERE tournament_id = :t AND name = :n AND (:except = 0 OR id <> :except2)',
            [':t' => $tournamentId, ':n' => $name,
             ':except' => $exceptTeamId ?? 0, ':except2' => $exceptTeamId ?? 0]
        );

        if ($clash > 0) {
            throw new AccountException(
                AccountException::NAME_TAKEN,
                'Another team in this tournament is already called "' . $name . '".'
            );
        }

        return $name;
    }

    private function shortName(mixed $value, int $tournamentId, ?int $exceptTeamId = null): string
    {
        $short = strtoupper(trim((string) $value));

        if (!preg_match('/^[A-Z0-9]{2,6}$/', $short)) {
            throw new AccountException(
                AccountException::VALIDATION,
                'The short name is 2 to 6 letters or digits, like MI or CSK.'
            );
        }

        $clash = (int) Database::scalar(
            'SELECT COUNT(*) FROM teams
              WHERE tournament_id = :t AND short_name = :s AND (:except = 0 OR id <> :except2)',
            [':t' => $tournamentId, ':s' => $short,
             ':except' => $exceptTeamId ?? 0, ':except2' => $exceptTeamId ?? 0]
        );

        if ($clash > 0) {
            throw new AccountException(
                AccountException::NAME_TAKEN,
                'Another team is already using the short name ' . $short . '.'
            );
        }

        return $short;
    }

    private function colour(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : '#22c55e';
    }

    /**
     * Returns a DECIMAL(14,2)-safe string, never a float.
     *
     * A blank falls back to $default. A browser posts an empty string for a
     * field the person cleared, not a missing key — so `?? $default` at the
     * call site never fires, and a field the form calls optional would be
     * refused for being empty. It was.
     */
    private function money(mixed $value, string $label, float|int|string|null $default = null): string
    {
        if ($default !== null && (is_string($value) ? trim($value) === '' : $value === null)) {
            $value = $default;
        }

        $value = is_string($value) ? str_replace([',', ' ', '₹'], '', trim($value)) : $value;

        if (!is_numeric($value) || (float) $value <= 0) {
            throw new AccountException(AccountException::VALIDATION, "{$label} must be a positive amount.");
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function count(mixed $value, string $label, int $min, int $max, ?int $default = null): int
    {
        if ($default !== null && (is_string($value) ? trim($value) === '' : $value === null)) {
            $value = $default;
        }

        if (!is_numeric($value)) {
            throw new AccountException(AccountException::VALIDATION, "{$label} must be a number.");
        }

        $n = (int) $value;

        if ($n < $min || $n > $max) {
            throw new AccountException(
                AccountException::VALIDATION,
                "{$label} must be between {$min} and {$max}."
            );
        }

        return $n;
    }

    private function playerRole(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array($value, ['batsman', 'bowler', 'all_rounder', 'wicket_keeper'], true)
            ? $value
            : 'batsman';
    }

    /**
     * A code no two tournaments share.
     *
     * The alphabet lives in AccountService and leaves out every character
     * that gets misread when a code is read out loud — 0/O/o and 1/I/l/i.
     */
    private function freshSecretCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = AccountService::generateCode(8);

            if ((int) Database::scalar(
                'SELECT COUNT(*) FROM tournaments WHERE secret_code = :c',
                [':c' => $code]
            ) === 0) {
                return $code;
            }
        }

        throw new AccountException(
            AccountException::VALIDATION,
            'Could not generate a unique tournament code. Try again.'
        );
    }
}
