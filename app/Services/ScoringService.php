<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ScoringException;
use Database;
use PDO;

/**
 * =====================================================================
 *  Ball-by-ball scoring engine
 * =====================================================================
 *
 *  `ball_by_ball` is the source of truth. The `innings` row is a cache of
 *  the running totals, refreshed from the log after every write — so the
 *  two can never drift, and a corrupted cache can be rebuilt by replaying
 *  the log.
 *
 *  Who is on strike is NOT sent by the client on every ball. The server
 *  derives it from the previous ball plus the laws:
 *
 *      odd runs (off the bat, byes, or extra wides) → batters cross
 *      end of a legal over                          → batters cross
 *      wicket                                       → the end is vacant
 *
 *  The client only supplies facts it alone knows: the opening pair, a new
 *  batter after a wicket, and the bowler for a new over. Everything else is
 *  computed here, so a buggy or hostile client cannot credit runs to the
 *  wrong batter.
 *
 *  Each write takes SELECT … FOR UPDATE on the innings row, which serialises
 *  ball_sequence assignment even if two devices are scoring the same match.
 */
final class ScoringService
{
    /**
     * Record one delivery.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> the full scorecard after the ball
     */
    public function recordBall(int $inningsId, array $input, ?int $userId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($inningsId, $input, $userId): array {
            $innings = $this->lockInnings($inningsId);
            $this->assertScorable($innings);

            $ballsPerOver = (int) $innings['balls_per_over'];
            $state        = $this->derive($inningsId, $ballsPerOver);

            // --- resolve who is in the middle --------------------------------
            [$strikerId, $nonStrikerId, $bowlerId] = $this->resolvePositions($innings, $state, $input);

            // --- validate the delivery itself --------------------------------
            $extraType  = (string) ($input['extra_type'] ?? 'none');
            $runsOffBat = (int) ($input['runs_off_bat'] ?? 0);
            $extraRuns  = (int) ($input['extra_runs'] ?? 0);

            if (!in_array($extraType, ['none', 'wide', 'no_ball', 'bye', 'leg_bye', 'penalty'], true)) {
                throw new ScoringException(ScoringException::BAD_BALL, 'Unknown extra type.', [], 400);
            }

            if ($runsOffBat < 0 || $runsOffBat > 7 || $extraRuns < 0 || $extraRuns > 10) {
                throw new ScoringException(ScoringException::BAD_BALL, 'Runs out of range for one delivery.', [], 400);
            }

            // A wide or no-ball always carries its one-run penalty.
            if (in_array($extraType, ['wide', 'no_ball'], true) && $extraRuns < 1) {
                throw new ScoringException(
                    ScoringException::BAD_BALL,
                    'A wide or no-ball must include its penalty run.',
                    [],
                    400
                );
            }

            // Runs off the bat are impossible on a wide.
            if ($extraType === 'wide' && $runsOffBat > 0) {
                throw new ScoringException(ScoringException::BAD_BALL, 'No runs can be scored off the bat on a wide.', [], 400);
            }

            $isLegal = !in_array($extraType, ['wide', 'no_ball'], true);

            // --- wicket ------------------------------------------------------
            $isWicket      = (bool) ($input['is_wicket'] ?? false);
            $dismissalType = $isWicket ? (string) ($input['dismissal_type'] ?? '') : null;
            $dismissedId   = null;
            $fielderId     = isset($input['fielder_id']) && $input['fielder_id'] !== ''
                ? (int) $input['fielder_id']
                : null;

            if ($isWicket) {
                $legalTypes = ['bowled', 'caught', 'lbw', 'run_out', 'stumped', 'hit_wicket',
                               'retired_hurt', 'obstructing_field'];

                if (!in_array($dismissalType, $legalTypes, true)) {
                    throw new ScoringException(ScoringException::BAD_BALL, 'Unknown dismissal type.', [], 400);
                }

                // Only a run-out can take the non-striker.
                $dismissedId = isset($input['dismissed_player_id'])
                    ? (int) $input['dismissed_player_id']
                    : $strikerId;

                if ($dismissedId !== $strikerId && $dismissedId !== $nonStrikerId) {
                    throw new ScoringException(
                        ScoringException::BAD_BALL,
                        'The dismissed player is not at the crease.',
                        [],
                        400
                    );
                }

                if ($dismissedId === $nonStrikerId && $dismissalType !== 'run_out') {
                    throw new ScoringException(
                        ScoringException::BAD_BALL,
                        'Only a run-out can dismiss the non-striker.',
                        [],
                        400
                    );
                }
            }

            // --- position in the innings -------------------------------------
            $legalBefore = $state['legal_balls'];
            $overNumber  = intdiv($legalBefore, $ballsPerOver);
            $ballInOver  = min($ballsPerOver, ($legalBefore % $ballsPerOver) + 1);

            $sequence = (int) (Database::scalar(
                'SELECT COALESCE(MAX(ball_sequence), 0) + 1 FROM ball_by_ball WHERE innings_id = :i',
                [':i' => $inningsId]
            ) ?? 1);

            Database::run(
                'INSERT INTO ball_by_ball
                    (match_id, innings_id, ball_sequence, over_number, ball_in_over,
                     striker_id, non_striker_id, bowler_id,
                     runs_off_bat, extra_runs, extra_type, is_legal_delivery,
                     is_boundary_four, is_boundary_six,
                     is_wicket, dismissal_type, dismissed_player_id, fielder_id,
                     scored_by_user_id)
                 VALUES
                    (:match, :innings, :seq, :over, :ballInOver,
                     :striker, :nonStriker, :bowler,
                     :runs, :extras, :extraType, :legal,
                     :four, :six,
                     :wicket, :dismissal, :dismissed, :fielder,
                     :user)',
                [
                    ':match'      => (int) $innings['match_id'],
                    ':innings'    => $inningsId,
                    ':seq'        => $sequence,
                    ':over'       => $overNumber,
                    ':ballInOver' => $ballInOver,
                    ':striker'    => $strikerId,
                    ':nonStriker' => $nonStrikerId,
                    ':bowler'     => $bowlerId,
                    ':runs'       => $runsOffBat,
                    ':extras'     => $extraRuns,
                    ':extraType'  => $extraType,
                    ':legal'      => $isLegal ? 1 : 0,
                    ':four'       => $runsOffBat === 4 && $extraType === 'none' ? 1 : 0,
                    ':six'        => $runsOffBat === 6 && $extraType === 'none' ? 1 : 0,
                    ':wicket'     => $isWicket ? 1 : 0,
                    ':dismissal'  => $dismissalType,
                    ':dismissed'  => $dismissedId,
                    ':fielder'    => $fielderId,
                    ':user'       => $userId,
                ]
            );

            $this->refreshInningsCache($inningsId, $ballsPerOver, (int) $innings['overs_per_innings']);

            return $this->scorecard($inningsId);
        });
    }

    /**
     * Remove the most recent delivery.
     *
     * Because every derived number comes from the log, deleting the last row
     * is a complete undo — there is no separate state to unwind.
     *
     * @return array<string,mixed>
     */
    public function undoLastBall(int $inningsId, ?int $userId = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($inningsId): array {
            $innings = $this->lockInnings($inningsId);

            $last = Database::one(
                'SELECT id, ball_sequence FROM ball_by_ball
                  WHERE innings_id = :i ORDER BY ball_sequence DESC LIMIT 1',
                [':i' => $inningsId]
            );

            if ($last === null) {
                throw new ScoringException(ScoringException::NOTHING_TO_UNDO, 'No balls to undo.');
            }

            Database::exec('DELETE FROM ball_by_ball WHERE id = :id', [':id' => (int) $last['id']]);

            $this->refreshInningsCache(
                $inningsId,
                (int) $innings['balls_per_over'],
                (int) $innings['overs_per_innings']
            );

            return $this->scorecard($inningsId);
        });
    }

    /**
     * Everything the pad and the viewer scorecard need, in one read.
     *
     * @return array<string,mixed>
     */
    public function scorecard(int $inningsId): array
    {
        $innings = Database::one(
            'SELECT i.*, m.overs_per_innings, t.balls_per_over,
                    bat.name AS batting_team, bat.short_name AS batting_short, bat.primary_color AS batting_color,
                    bwl.name AS bowling_team, bwl.short_name AS bowling_short,
                    m.status AS match_status, m.venue
               FROM innings i
               JOIN matches m     ON m.id = i.match_id
               JOIN tournaments t ON t.id = m.tournament_id
               JOIN teams bat     ON bat.id = i.batting_team_id
               JOIN teams bwl     ON bwl.id = i.bowling_team_id
              WHERE i.id = :i',
            [':i' => $inningsId]
        );

        if ($innings === null) {
            throw new ScoringException(ScoringException::INNINGS_NOT_FOUND, 'Innings not found.', [], 404);
        }

        $ballsPerOver = (int) $innings['balls_per_over'];

        return [
            'ok'      => true,
            'innings' => [
                'id'             => (int) $innings['id'],
                'innings_number' => (int) $innings['innings_number'],
                'batting_team'   => $innings['batting_team'],
                'batting_short'  => $innings['batting_short'],
                'bowling_team'   => $innings['bowling_team'],
                'bowling_short'  => $innings['bowling_short'],
                'total_runs'     => (int) $innings['total_runs'],
                'total_wickets'  => (int) $innings['total_wickets'],
                'legal_balls'    => (int) $innings['legal_balls'],
                'overs'          => $this->oversText((int) $innings['legal_balls'], $ballsPerOver),
                'extras'         => (int) $innings['extras_wide'] + (int) $innings['extras_no_ball']
                                    + (int) $innings['extras_bye'] + (int) $innings['extras_leg_bye']
                                    + (int) $innings['extras_penalty'],
                'extras_wide'    => (int) $innings['extras_wide'],
                'extras_no_ball' => (int) $innings['extras_no_ball'],
                'extras_bye'     => (int) $innings['extras_bye'],
                'extras_leg_bye' => (int) $innings['extras_leg_bye'],
                'target'         => $innings['target'] !== null ? (int) $innings['target'] : null,
                'is_completed'   => (int) $innings['is_completed'] === 1,
                'overs_limit'    => (int) $innings['overs_per_innings'],
                'balls_per_over' => $ballsPerOver,
            ],
            'balls' => $this->ballLog($inningsId),
            'state' => $this->derive($inningsId, $ballsPerOver),
            'squads' => $this->squads((int) $innings['match_id'], (int) $innings['batting_team_id']),
        ];
    }

    // -----------------------------------------------------------------
    //  Deriving the middle
    // -----------------------------------------------------------------

    /**
     * Current striker, non-striker, bowler and what the server still needs,
     * computed from the ball log alone.
     *
     * @return array<string,mixed>
     */
    private function derive(int $inningsId, int $ballsPerOver): array
    {
        $balls = $this->ballLog($inningsId);

        $blank = [
            'striker_id' => null, 'non_striker_id' => null, 'bowler_id' => null,
            'needs_opening' => true, 'needs_batter' => false, 'needs_bowler' => false,
            'legal_balls' => 0, 'out' => [],
        ];

        if ($balls === []) {
            return $blank;
        }

        $last  = $balls[count($balls) - 1];
        $legal = 0;
        $out   = [];

        foreach ($balls as $b) {
            if ($b['is_legal']) {
                $legal++;
            }
            if ($b['is_wicket'] && $b['dismissed_player_id'] !== null) {
                $out[] = $b['dismissed_player_id'];
            }
        }

        $striker    = $last['striker_id'];
        $nonStriker = $last['non_striker_id'];

        // 1. Batters cross on odd runs — off the bat, run as byes, or run
        //    beyond the one-run penalty on a wide.
        $ran = $last['runs_off_bat'];

        if ($last['extra_type'] === 'bye' || $last['extra_type'] === 'leg_bye') {
            $ran += $last['extra_runs'];
        }
        if ($last['extra_type'] === 'wide') {
            $ran += $last['extra_runs'] - 1;
        }

        if ($ran % 2 === 1) {
            [$striker, $nonStriker] = [$nonStriker, $striker];
        }

        // 2. A wicket leaves one end vacant.
        $needsBatter = false;

        if ($last['is_wicket'] && $last['dismissed_player_id'] !== null) {
            $needsBatter = true;

            if ($striker === $last['dismissed_player_id']) {
                $striker = null;
            } elseif ($nonStriker === $last['dismissed_player_id']) {
                $nonStriker = null;
            }
        }

        // 3. Ends change at the end of a legal over.
        $needsBowler = false;

        if ($last['is_legal'] && $legal > 0 && $legal % $ballsPerOver === 0) {
            [$striker, $nonStriker] = [$nonStriker, $striker];
            $needsBowler = true;
        }

        return [
            'striker_id'     => $striker,
            'non_striker_id' => $nonStriker,
            'bowler_id'      => $last['bowler_id'],
            'needs_opening'  => false,
            'needs_batter'   => $needsBatter,
            'needs_bowler'   => $needsBowler,
            'legal_balls'    => $legal,
            'out'            => $out,
        ];
    }

    /**
     * Fill the striker / non-striker / bowler slots, taking from the client
     * only what the server cannot know.
     *
     * @param array<string,mixed> $innings
     * @param array<string,mixed> $state
     * @param array<string,mixed> $input
     * @return array{0:int,1:int,2:int}
     */
    private function resolvePositions(array $innings, array $state, array $input): array
    {
        $matchId    = (int) $innings['match_id'];
        $battingTid = (int) $innings['batting_team_id'];
        $bowlingTid = (int) $innings['bowling_team_id'];

        if ($state['needs_opening']) {
            foreach (['striker_id', 'non_striker_id', 'bowler_id'] as $key) {
                if (empty($input[$key])) {
                    throw new ScoringException(
                        ScoringException::NEEDS_OPENING,
                        'Name the opening batters and the bowler before the first ball.'
                    );
                }
            }

            $striker    = (int) $input['striker_id'];
            $nonStriker = (int) $input['non_striker_id'];
            $bowler     = (int) $input['bowler_id'];
        } else {
            $striker    = $state['striker_id'];
            $nonStriker = $state['non_striker_id'];
            $bowler     = $state['bowler_id'];

            if ($state['needs_batter']) {
                if (empty($input['new_batter_id'])) {
                    throw new ScoringException(
                        ScoringException::NEEDS_BATTER,
                        'Name the next batter before the next ball.',
                        ['out' => $state['out']]
                    );
                }

                $incoming = (int) $input['new_batter_id'];

                if (in_array($incoming, $state['out'], true)) {
                    throw new ScoringException(
                        ScoringException::ALREADY_OUT,
                        'That batter is already out.',
                        ['player_id' => $incoming]
                    );
                }

                // Without this the incoming name silently overwrites the
                // vacant end and both ends end up holding the same player,
                // which surfaces as a baffling SAME_BATTER further down.
                if ($incoming === $striker || $incoming === $nonStriker) {
                    throw new ScoringException(
                        ScoringException::SAME_BATTER,
                        'That batter is already at the crease.',
                        ['player_id' => $incoming],
                        400
                    );
                }

                // The incoming batter takes the vacant end.
                if ($striker === null) {
                    $striker = $incoming;
                } else {
                    $nonStriker = $incoming;
                }
            }

            if ($state['needs_bowler']) {
                if (empty($input['bowler_id'])) {
                    throw new ScoringException(
                        ScoringException::NEEDS_BOWLER,
                        'Name the bowler for the new over.',
                        ['last_bowler_id' => $state['bowler_id']]
                    );
                }

                $bowler = (int) $input['bowler_id'];

                if ($bowler === $state['bowler_id']) {
                    throw new ScoringException(
                        ScoringException::CONSECUTIVE_OVERS,
                        'A bowler cannot bowl consecutive overs.',
                        ['player_id' => $bowler]
                    );
                }
            }
        }

        if ($striker === null || $nonStriker === null || $bowler === null) {
            throw new ScoringException(ScoringException::NEEDS_BATTER, 'Both ends must be occupied.');
        }

        if ($striker === $nonStriker) {
            throw new ScoringException(ScoringException::SAME_BATTER, 'One player cannot be at both ends.', [], 400);
        }

        $this->assertInSquad($matchId, $battingTid, $striker, 'batting');
        $this->assertInSquad($matchId, $battingTid, $nonStriker, 'batting');
        $this->assertInSquad($matchId, $bowlingTid, $bowler, 'bowling');

        foreach ([$striker, $nonStriker] as $batter) {
            if (in_array($batter, $state['out'], true)) {
                throw new ScoringException(
                    ScoringException::ALREADY_OUT,
                    'That batter is already out.',
                    ['player_id' => $batter]
                );
            }
        }

        return [$striker, $nonStriker, $bowler];
    }

    private function assertInSquad(int $matchId, int $teamId, int $playerId, string $side): void
    {
        $found = Database::scalar(
            'SELECT COUNT(*) FROM match_squads
              WHERE match_id = :m AND team_id = :t AND player_id = :p AND is_playing_xi = 1',
            [':m' => $matchId, ':t' => $teamId, ':p' => $playerId]
        );

        if ((int) $found === 0) {
            throw new ScoringException(
                ScoringException::NOT_IN_SQUAD,
                "That player is not in the {$side} XI.",
                ['player_id' => $playerId],
                400
            );
        }
    }

    // -----------------------------------------------------------------
    //  Reads
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function ballLog(int $inningsId): array
    {
        $rows = Database::all(
            'SELECT ball_sequence, over_number, ball_in_over,
                    striker_id, non_striker_id, bowler_id,
                    runs_off_bat, extra_runs, extra_type, is_legal_delivery,
                    is_boundary_four, is_boundary_six,
                    is_wicket, dismissal_type, dismissed_player_id, fielder_id
               FROM ball_by_ball
              WHERE innings_id = :i
           ORDER BY ball_sequence',
            [':i' => $inningsId]
        );

        // Normalise types once here so neither the client nor the derivation
        // below has to think about PDO returning "1" instead of 1.
        return array_map(static fn (array $r): array => [
            'seq'                 => (int) $r['ball_sequence'],
            'over'                => (int) $r['over_number'],
            'ball_in_over'        => (int) $r['ball_in_over'],
            'striker_id'          => (int) $r['striker_id'],
            'non_striker_id'      => (int) $r['non_striker_id'],
            'bowler_id'           => (int) $r['bowler_id'],
            'runs_off_bat'        => (int) $r['runs_off_bat'],
            'extra_runs'          => (int) $r['extra_runs'],
            'extra_type'          => $r['extra_type'],
            'is_legal'            => (int) $r['is_legal_delivery'] === 1,
            'is_four'             => (int) $r['is_boundary_four'] === 1,
            'is_six'              => (int) $r['is_boundary_six'] === 1,
            'is_wicket'           => (int) $r['is_wicket'] === 1,
            'dismissal_type'      => $r['dismissal_type'],
            'dismissed_player_id' => $r['dismissed_player_id'] !== null ? (int) $r['dismissed_player_id'] : null,
            'fielder_id'          => $r['fielder_id'] !== null ? (int) $r['fielder_id'] : null,
        ], $rows);
    }

    /**
     * Both XIs, with the batting side ordered by batting position so the pad
     * can offer "next batter in" without a second request.
     *
     * @return array{batting:array<int,array<string,mixed>>,bowling:array<int,array<string,mixed>>}
     */
    private function squads(int $matchId, int $battingTeamId): array
    {
        $rows = Database::all(
            'SELECT s.team_id, s.player_id, s.batting_order, s.is_captain, s.is_wicket_keeper,
                    COALESCE(p.display_name, p.full_name) AS name, p.bowling_style
               FROM match_squads s
               JOIN players p ON p.id = s.player_id
              WHERE s.match_id = :m AND s.is_playing_xi = 1
           ORDER BY s.team_id, s.batting_order',
            [':m' => $matchId]
        );

        $batting = [];
        $bowling = [];

        foreach ($rows as $r) {
            $entry = [
                'id'    => (int) $r['player_id'],
                'name'  => $r['name'],
                'order' => $r['batting_order'] !== null ? (int) $r['batting_order'] : null,
                'style' => $this->styleLabel((string) $r['bowling_style']),
            ];

            if ((int) $r['team_id'] === $battingTeamId) {
                $batting[] = $entry;
            } else {
                $bowling[] = $entry;
            }
        }

        return ['batting' => $batting, 'bowling' => $bowling];
    }

    private function styleLabel(string $style): string
    {
        return $style === 'none' ? '' : ucwords(str_replace('_', ' ', $style));
    }

    /** @return array<string,mixed> */
    private function lockInnings(int $inningsId): array
    {
        $innings = Database::one(
            'SELECT i.id, i.match_id, i.batting_team_id, i.bowling_team_id, i.is_completed,
                    m.status AS match_status, m.overs_per_innings, t.balls_per_over
               FROM innings i
               JOIN matches m     ON m.id = i.match_id
               JOIN tournaments t ON t.id = m.tournament_id
              WHERE i.id = :i
              LIMIT 1
                FOR UPDATE',
            [':i' => $inningsId]
        );

        if ($innings === null) {
            throw new ScoringException(ScoringException::INNINGS_NOT_FOUND, 'Innings not found.', [], 404);
        }

        return $innings;
    }

    /** @param array<string,mixed> $innings */
    private function assertScorable(array $innings): void
    {
        if ((int) $innings['is_completed'] === 1) {
            throw new ScoringException(ScoringException::INNINGS_CLOSED, 'This innings is already closed.');
        }

        if (!in_array($innings['match_status'], ['live', 'toss', 'innings_break'], true)) {
            throw new ScoringException(
                ScoringException::MATCH_NOT_LIVE,
                'This match is not in progress.',
                ['status' => $innings['match_status']]
            );
        }
    }

    /**
     * Rebuild the innings cache from the ball log.
     *
     * Recomputing rather than incrementing means an undo, a correction or a
     * direct edit can never leave the totals wrong — the log is always the
     * arbiter.
     */
    private function refreshInningsCache(int $inningsId, int $ballsPerOver, int $oversLimit): void
    {
        $agg = Database::one(
            "SELECT COALESCE(SUM(runs_off_bat + extra_runs), 0)                              AS runs,
                    COALESCE(SUM(is_wicket), 0)                                              AS wickets,
                    COALESCE(SUM(is_legal_delivery), 0)                                      AS legal,
                    COALESCE(SUM(CASE WHEN extra_type = 'wide'    THEN extra_runs ELSE 0 END), 0) AS wd,
                    COALESCE(SUM(CASE WHEN extra_type = 'no_ball' THEN extra_runs ELSE 0 END), 0) AS nb,
                    COALESCE(SUM(CASE WHEN extra_type = 'bye'     THEN extra_runs ELSE 0 END), 0) AS b,
                    COALESCE(SUM(CASE WHEN extra_type = 'leg_bye' THEN extra_runs ELSE 0 END), 0) AS lb,
                    COALESCE(SUM(CASE WHEN extra_type = 'penalty' THEN extra_runs ELSE 0 END), 0) AS pen
               FROM ball_by_ball
              WHERE innings_id = :i",
            [':i' => $inningsId]
        ) ?? [];

        $legal   = (int) ($agg['legal'] ?? 0);
        $wickets = (int) ($agg['wickets'] ?? 0);

        // All out, or the overs are gone.
        $complete = $wickets >= 10 || $legal >= $oversLimit * $ballsPerOver;

        Database::exec(
            'UPDATE innings
                SET total_runs     = :runs,
                    total_wickets  = :wickets,
                    legal_balls    = :legal,
                    extras_wide    = :wd,
                    extras_no_ball = :nb,
                    extras_bye     = :b,
                    extras_leg_bye = :lb,
                    extras_penalty = :pen,
                    is_completed   = :done,
                    ended_at       = CASE WHEN :done2 = 1 THEN NOW() ELSE NULL END
              WHERE id = :i',
            [
                ':runs' => (int) ($agg['runs'] ?? 0), ':wickets' => $wickets, ':legal' => $legal,
                ':wd' => (int) ($agg['wd'] ?? 0), ':nb' => (int) ($agg['nb'] ?? 0),
                ':b' => (int) ($agg['b'] ?? 0), ':lb' => (int) ($agg['lb'] ?? 0),
                ':pen' => (int) ($agg['pen'] ?? 0),
                ':done' => $complete ? 1 : 0, ':done2' => $complete ? 1 : 0,
                ':i' => $inningsId,
            ]
        );
    }

    private function oversText(int $legalBalls, int $ballsPerOver): string
    {
        return intdiv($legalBalls, $ballsPerOver) . '.' . ($legalBalls % $ballsPerOver);
    }
}
