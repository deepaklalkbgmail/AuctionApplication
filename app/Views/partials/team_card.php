<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  The team card — who a team is, and who they have bought
 * =====================================================================
 *
 *  Shown when anybody watching the board clicks a team's name: the
 *  owner, the purse, and the squad so far, with what each player cost
 *  and what kind of player they are.
 *
 *  It is the same panel during the auction and after it. During, it
 *  answers "what have they got left, and who have they spent it on";
 *  after, it is the finished squad — which is what people come back to
 *  look at, and the reason it is worth a permanent, linkable address
 *  (#team-3) rather than something that only exists while a page is
 *  open.
 *
 *  No JavaScript, on the same reasoning as the player card: a :target
 *  pop-up, closed by a backdrop that links to an id matching nothing.
 *  The board's live refresh re-renders around it without disturbing it,
 *  because the fragment lives in the address, not in a variable.
 *
 *  Expects, per team:
 *    $team    a teams row, plus overseas_bought
 *    $owner   the owner's name, or null
 *    $squad   rows of full_name, role, is_overseas, sold_price
 *    $limits  ['max_squad_size' => int, 'max_overseas' => int]
 *
 *  $showPrices is off by default. What a player fetched is not shown to
 *  the room: the squad reads as names and kinds of player. An
 *  administrative screen that ought to see the figures passes true.
 */

require_once __DIR__ . '/player_kinds.php';

if (!function_exists('team_card_styles')) {

    function team_card_styles(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        ?>
        <style>
        .tc-name{color:inherit;text-decoration:underline;text-decoration-color:rgba(52,211,153,.4);
                 text-underline-offset:3px;transition:text-decoration-color .15s}
        .tc-name:hover{text-decoration-color:#34d399}

        /* :target is the whole mechanism with scripting off. The extra class
           is for the board's live refresh: replacing the panel underneath an
           open card removes the element the browser considered the target,
           and the browser does not re-match the new one — the card would
           vanish mid-refresh while somebody was reading it. board-live.js
           re-marks it from the address after each swap. Measured; :target
           alone does not survive the swap. */
        .tc-modal{display:none}
        .tc-modal:target,
        .tc-modal.is-open{display:grid;position:fixed;inset:0;z-index:60;place-items:center;padding:1rem}
        .tc-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.86);
                     backdrop-filter:blur(3px);cursor:default}

        .tc-panel{position:relative;width:min(46rem,100%);max-height:92vh;overflow-y:auto;
                  border-radius:1.25rem;border:1px solid rgba(255,255,255,.12);
                  background:#0b1220;box-shadow:0 25px 60px rgba(0,0,0,.6);padding:1.25rem}

        .tc-head{display:flex;align-items:flex-start;gap:1rem;justify-content:space-between}
        .tc-badge{display:grid;place-items:center;height:2.6rem;width:2.6rem;flex:none;
                  border-radius:.7rem;font-size:.8rem;font-weight:900;color:#020617}
        .tc-title{font-size:1.35rem;font-weight:900;letter-spacing:-.02em;color:#fff;line-height:1.15}
        .tc-sub{margin-top:.15rem;font-size:.8rem;color:#94a3b8}
        .tc-close{flex:none;border-radius:.6rem;border:1px solid rgba(255,255,255,.14);
                  padding:.35rem .75rem;font-size:.72rem;font-weight:800;text-transform:uppercase;
                  letter-spacing:.06em;color:#cbd5e1;text-decoration:none}
        .tc-close:hover{background:rgba(244,63,94,.12);border-color:rgba(244,63,94,.35);color:#fda4af}

        .tc-figures{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem;margin-top:1rem}
        @media (min-width:34rem){.tc-figures{grid-template-columns:repeat(4,minmax(0,1fr))}}
        .tc-figure{border-radius:.7rem;border:1px solid rgba(255,255,255,.07);
                   background:rgba(255,255,255,.03);padding:.55rem .6rem}
        .tc-figure dt{font-size:.6rem;font-weight:800;text-transform:uppercase;
                      letter-spacing:.06em;color:#64748b}
        .tc-figure dd{margin:.2rem 0 0;font-size:1rem;font-weight:900;color:#fff}
        .tc-figure dd.tc-money{color:#34d399}

        .tc-shape{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.9rem}
        .tc-chip{border-radius:999px;border:1px solid rgba(255,255,255,.12);
                 padding:.2rem .6rem;font-size:.7rem;font-weight:700;color:#cbd5e1}
        .tc-chip-short{border-color:rgba(251,191,36,.35);background:rgba(251,191,36,.1);color:#fcd34d}

        .tc-section{margin-top:1.2rem;font-size:.65rem;font-weight:900;text-transform:uppercase;
                    letter-spacing:.14em;color:#64748b}

        .tc-squad{width:100%;margin-top:.5rem;border-collapse:collapse;font-size:.83rem}
        .tc-squad th{text-align:left;padding:.35rem .5rem;font-size:.6rem;font-weight:800;
                     text-transform:uppercase;letter-spacing:.06em;color:#64748b;
                     border-bottom:1px solid rgba(255,255,255,.1)}
        .tc-squad td{padding:.45rem .5rem;border-bottom:1px solid rgba(255,255,255,.05);color:#e2e8f0}
        .tc-squad tr:last-child td{border-bottom:0}
        .tc-squad .tc-role{color:#94a3b8;font-size:.75rem}
        .tc-squad .tc-price{text-align:right;font-weight:800;color:#34d399;
                            font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .tc-os{margin-left:.35rem;font-size:.62rem;font-weight:800;color:#7dd3fc;
               text-transform:uppercase;letter-spacing:.06em}

        .tc-empty{margin-top:.5rem;border-radius:.7rem;border:1px dashed rgba(255,255,255,.12);
                  padding:1.1rem;text-align:center;font-size:.8rem;color:#64748b}
        </style>
        <?php
    }

    /**
     * The clickable team name.
     *
     * @param array<string,mixed> $team
     */
    function team_card_link(array $team): string
    {
        return sprintf(
            '<a href="#team-%d" class="tc-name" title="See %s\'s squad and owner">%s</a>',
            (int) $team['id'],
            e((string) $team['name']),
            e((string) $team['name'])
        );
    }

    /**
     * @param array<string,mixed>              $team
     * @param array<int,array<string,mixed>>   $squad
     * @param array{max_squad_size:int,max_overseas:int} $limits
     */
    function team_card(array $team, ?string $owner, array $squad, array $limits, bool $showPrices = false): void
    {
        $bought   = count($squad);
        $slots    = max(0, $limits['max_squad_size'] - $bought);
        $overseas = (int) ($team['overseas_bought'] ?? 0);

        /* What the squad is made of — the question everybody asks of a
           half-finished team, and the first thing looked at once it is
           finished. */
        $shape = array_fill_keys(array_keys(\App\Services\AccountService::PLAYER_KINDS), 0);

        foreach ($squad as $player) {
            $role = (string) $player['role'];
            $shape[$role] = ($shape[$role] ?? 0) + 1;
        }

        ?>
        <div class="tc-modal" id="team-<?= (int) $team['id'] ?>" role="dialog" aria-modal="true"
             aria-label="<?= e((string) $team['name']) ?> — squad and owner">

            <a class="tc-backdrop" href="#closed" aria-label="Close"></a>

            <div class="tc-panel">
                <div class="tc-head">
                    <div style="display:flex;gap:.75rem;align-items:center;min-width:0">
                        <span class="tc-badge" style="background: <?= e((string) $team['primary_color']) ?>">
                            <?= e((string) $team['short_name']) ?>
                        </span>
                        <div style="min-width:0">
                            <p class="tc-title"><?= e((string) $team['name']) ?></p>
                            <p class="tc-sub">
                                <?= $owner !== null && $owner !== ''
                                    ? 'Owner: ' . e($owner)
                                    : 'No owner assigned yet' ?>
                                <?php if (!empty($team['home_venue'])): ?>
                                    · <?= e((string) $team['home_venue']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <a class="tc-close" href="#closed">Close</a>
                </div>

                <dl class="tc-figures">
                    <div class="tc-figure">
                        <dt>Purse left</dt>
                        <dd class="tc-money"><?= e(board_rupees($team['purse_remaining'])) ?></dd>
                    </div>
                    <div class="tc-figure">
                        <dt>Spent</dt>
                        <dd><?= e(board_rupees($team['purse_spent'])) ?></dd>
                    </div>
                    <div class="tc-figure">
                        <dt>Bought</dt>
                        <dd><?= $bought ?> <span style="font-size:.7rem;color:#64748b">of <?= (int) $limits['max_squad_size'] ?></span></dd>
                    </div>
                    <div class="tc-figure">
                        <dt>Overseas</dt>
                        <dd><?= $overseas ?> <span style="font-size:.7rem;color:#64748b">of <?= (int) $limits['max_overseas'] ?></span></dd>
                    </div>
                </dl>

                <div class="tc-shape">
                    <?php foreach ($shape as $role => $count): ?>
                        <span class="tc-chip"><?= e(player_kind($role)) ?> <strong><?= $count ?></strong></span>
                    <?php endforeach; ?>
                    <?php if ($slots > 0): ?>
                        <span class="tc-chip tc-chip-short"><?= $slots ?> slot<?= $slots === 1 ? '' : 's' ?> left</span>
                    <?php else: ?>
                        <span class="tc-chip">Squad complete</span>
                    <?php endif; ?>
                </div>

                <p class="tc-section">Squad <?= $bought > 0 ? '(' . $bought . ')' : '' ?></p>

                <?php if ($squad === []): ?>
                    <p class="tc-empty">Nobody bought yet. Their first signing appears here as soon as it is recorded.</p>
                <?php else: ?>
                    <table class="tc-squad">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>Kind of player</th>
                                <?php if ($showPrices): ?><th style="text-align:right">Price</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($squad as $player): ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) $player['full_name']) ?></strong>
                                        <?php if ((int) $player['is_overseas'] === 1): ?>
                                            <span class="tc-os">overseas</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tc-role"><?= e(player_kind((string) $player['role'])) ?></td>
                                    <?php if ($showPrices): ?>
                                        <td class="tc-price"><?= e(board_rupees($player['sold_price'])) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
