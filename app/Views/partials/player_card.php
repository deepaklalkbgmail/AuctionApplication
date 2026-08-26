<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  The player card — who is standing in front of you
 * =====================================================================
 *
 *  A pop-up shown when the auctioneer clicks a player's name on the
 *  sheet: a large, uncropped photo and everything known about them, so
 *  the person being called and the person being sold are certainly the
 *  same person.
 *
 *  No JavaScript. It is a :target pop-up — the name is a link to the
 *  card's id, and the backdrop and Close button link to an id that
 *  matches nothing, which hides it again. Three reasons:
 *
 *    • the sheet is used on a laptop in a hall, often on a poor
 *      connection, and everything else on it works without scripting
 *    • the deployed Content-Security-Policy allows no inline <script>,
 *      so a script would mean another file to keep in step
 *    • it cannot break. There is no state to get wrong.
 *
 *  The photo is never cropped. A face cut out of frame by object-fit
 *  defeats the whole point, so it is fitted whole into a fixed box, with
 *  a link to the original underneath for anyone who wants to zoom.
 *
 *  Usage, from a page that has already selected the columns below:
 *
 *      require_once BASE_PATH . '/app/Views/partials/player_card.php';
 *      player_card_styles();                  // once per page
 *      echo player_card_link($lot, '../');    // the clickable name
 *      player_card($lot, '../');              // the card itself
 */

require_once __DIR__ . '/player_kinds.php';

if (!function_exists('player_card_styles')) {

    /** The stylesheet, written once however many cards a page holds. */
    function player_card_styles(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        ?>
        <style>
        .pc-name{background:none;border:0;padding:0;font:inherit;color:#fff;cursor:pointer;
                 text-decoration:underline;text-decoration-color:rgba(52,211,153,.45);
                 text-underline-offset:3px;transition:text-decoration-color .15s}
        .pc-name:hover{text-decoration-color:#34d399}

        .pc-thumb{height:2.5rem;width:2.5rem;flex:none;border-radius:.6rem;object-fit:cover;
                  background:#0f172a;border:1px solid rgba(255,255,255,.12)}
        .pc-thumb-blank{display:grid;place-items:center;font-size:.8rem;font-weight:800;color:#64748b}

        /* The pop-up itself: hidden until its id is the fragment. */
        .pc-modal{display:none}
        .pc-modal:target{display:grid;position:fixed;inset:0;z-index:60;place-items:center;padding:1rem}
        .pc-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.86);
                     backdrop-filter:blur(3px);cursor:default}

        .pc-panel{position:relative;width:min(44rem,100%);max-height:92vh;overflow-y:auto;
                  border-radius:1.25rem;border:1px solid rgba(255,255,255,.12);
                  background:#0b1220;box-shadow:0 25px 60px rgba(0,0,0,.6);padding:1.25rem}

        .pc-head{display:flex;align-items:flex-start;gap:1rem;justify-content:space-between}
        .pc-title{font-size:1.4rem;font-weight:900;letter-spacing:-.02em;color:#fff;line-height:1.15}
        .pc-sub{margin-top:.25rem;font-size:.8rem;color:#94a3b8}
        .pc-close{flex:none;border-radius:.6rem;border:1px solid rgba(255,255,255,.14);
                  padding:.35rem .75rem;font-size:.72rem;font-weight:800;text-transform:uppercase;
                  letter-spacing:.06em;color:#cbd5e1;text-decoration:none}
        .pc-close:hover{background:rgba(244,63,94,.12);border-color:rgba(244,63,94,.35);color:#fda4af}

        .pc-body{display:grid;gap:1.1rem;margin-top:1.1rem}
        @media (min-width:44rem){.pc-body{grid-template-columns:17rem minmax(0,1fr)}}

        /* Whole photo, never cropped — a cut-off face helps nobody. */
        .pc-photo-box{display:flex;align-items:center;justify-content:center;min-height:11rem;
                      padding:.5rem;border-radius:1rem;border:1px solid rgba(255,255,255,.1);
                      background:#020617;overflow:hidden}
        /* The cap is in rem, not a percentage. A percentage height inside a
           box whose track is content-sized has nothing definite to resolve
           against, so it is ignored — and a tall portrait then overflows and
           has its face cut off, which is the one thing this card must never
           do. An absolute cap always resolves; the photo keeps its own shape
           inside it and is never cropped. */
        .pc-photo{max-height:17rem;max-width:100%;width:auto;height:auto;border-radius:.6rem}
        .pc-photo-none{text-align:center;color:#475569;font-size:.8rem;padding:1rem}
        .pc-photo-initials{font-size:3.5rem;font-weight:900;color:#1e293b;line-height:1}
        .pc-photo-link{display:block;margin-top:.5rem;text-align:center;font-size:.72rem;
                       font-weight:700;color:#64748b;text-decoration:none}
        .pc-photo-link:hover{color:#34d399}

        .pc-badges{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.9rem}
        .pc-badge{border-radius:999px;padding:.22rem .6rem;font-size:.68rem;font-weight:800;
                  text-transform:uppercase;letter-spacing:.06em;
                  border:1px solid rgba(255,255,255,.12);color:#cbd5e1}
        .pc-badge-os{border-color:rgba(56,189,248,.35);background:rgba(56,189,248,.1);color:#7dd3fc}
        .pc-badge-cap{border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.1);color:#6ee7b7}
        .pc-badge-sold{border-color:rgba(52,211,153,.4);background:rgba(52,211,153,.14);color:#34d399}

        .pc-rows{display:grid;grid-template-columns:auto minmax(0,1fr);gap:.45rem 1rem;font-size:.83rem}
        .pc-rows dt{color:#64748b;white-space:nowrap}
        .pc-rows dd{color:#e2e8f0;margin:0;font-weight:600}

        .pc-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.4rem;margin-top:1rem}
        .pc-stat{border-radius:.7rem;border:1px solid rgba(255,255,255,.07);
                 background:rgba(255,255,255,.03);padding:.5rem .3rem;text-align:center}
        .pc-stat dt{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b}
        .pc-stat dd{margin:.2rem 0 0;font-size:.95rem;font-weight:900;color:#fff;
                    font-family:ui-monospace,SFMono-Regular,Menlo,monospace}

        .pc-note{margin-top:1rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.08);
                 background:rgba(255,255,255,.03);padding:.6rem .8rem;font-size:.76rem;color:#94a3b8}
        </style>
        <?php
    }

    /** How a value reads when there is nothing there. */
    function pc_or_dash(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '—' : $text;
    }

    /** right_arm_fast -> "right arm fast" */
    function pc_words(mixed $value): string
    {
        $text = trim(str_replace('_', ' ', (string) ($value ?? '')));

        return $text === '' || $text === 'none' ? '—' : $text;
    }

    /**
     * The clickable name. Returns markup rather than echoing so it can sit
     * inside whatever heading or table cell the calling page uses.
     *
     * @param array<string,mixed> $lot
     */
    function player_card_link(array $lot, string $classes = 'pc-name'): string
    {
        return sprintf(
            '<a href="#player-%d" class="%s" title="See %s\'s details and photo">%s</a>',
            (int) $lot['lot_id'],
            e($classes),
            e((string) $lot['full_name']),
            e((string) $lot['full_name'])
        );
    }

    /**
     * A small photo beside the name in a list, so a face is visible before
     * anybody clicks anything.
     *
     * @param array<string,mixed> $lot
     */
    function player_card_thumb(array $lot, string $up = ''): string
    {
        if (!empty($lot['photo_url'])) {
            return sprintf(
                '<img src="%s" alt="" class="pc-thumb" loading="lazy" width="40" height="40">',
                e($up . (string) $lot['photo_url'])
            );
        }

        return sprintf(
            '<span class="pc-thumb pc-thumb-blank" aria-hidden="true">%s</span>',
            e(mb_strtoupper(mb_substr((string) $lot['full_name'], 0, 1)))
        );
    }

    /**
     * The pop-up. Put it anywhere on the page; it is invisible until its
     * name is clicked.
     *
     * @param array<string,mixed> $lot a row from the auction sheet's query
     * @param string              $up  path back to public/, e.g. '../'
     */
    function player_card(array $lot, string $up = ''): void
    {
        $id     = 'player-' . (int) $lot['lot_id'];
        $name   = (string) $lot['full_name'];
        $photo  = !empty($lot['photo_url']) ? $up . (string) $lot['photo_url'] : null;
        $status = (string) ($lot['status'] ?? '');

        ?>
        <div class="pc-modal" id="<?= e($id) ?>" role="dialog" aria-modal="true"
             aria-label="<?= e($name) ?> — player details">

            <?php /* Clicking anywhere outside closes it: #closed matches no
                     element, so the pop-up simply stops being :target. */ ?>
            <a class="pc-backdrop" href="#closed" aria-label="Close"></a>

            <div class="pc-panel">
                <div class="pc-head">
                    <div>
                        <p class="pc-title"><?= e($name) ?></p>
                        <p class="pc-sub">
                            Lot <?= (int) $lot['lot_order'] ?>
                            <?php if (!empty($lot['auction_set'])): ?>
                                · <?= e((string) $lot['auction_set']) ?>
                            <?php endif; ?>
                            · base <?= rupees($lot['base_price']) ?>
                        </p>
                    </div>
                    <a class="pc-close" href="#closed">Close</a>
                </div>

                <div class="pc-body">
                    <div>
                        <div class="pc-photo-box">
                            <?php if ($photo !== null): ?>
                                <img class="pc-photo" src="<?= e($photo) ?>" loading="lazy"
                                     alt="Photograph of <?= e($name) ?>">
                            <?php else: ?>
                                <div class="pc-photo-none">
                                    <p class="pc-photo-initials"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></p>
                                    <p style="margin-top:.6rem">No photograph was supplied</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($photo !== null): ?>
                            <a class="pc-photo-link" href="<?= e($photo) ?>" target="_blank" rel="noopener">
                                Open the full-size photo
                            </a>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="pc-badges">
                            <span class="pc-badge"><?= e(player_kind((string) $lot['role'])) ?></span>
                            <?php if ((int) ($lot['is_overseas'] ?? 0) === 1): ?>
                                <span class="pc-badge pc-badge-os">Overseas</span>
                            <?php endif; ?>
                            <?php if ((int) ($lot['is_capped'] ?? 0) === 1): ?>
                                <span class="pc-badge pc-badge-cap">Capped</span>
                            <?php endif; ?>
                            <?php if ($status === 'sold'): ?>
                                <span class="pc-badge pc-badge-sold">
                                    Sold · <?= e((string) $lot['team_name']) ?> · <?= rupees($lot['sold_price']) ?>
                                </span>
                            <?php elseif ($status === 'unsold'): ?>
                                <span class="pc-badge">Passed over</span>
                            <?php endif; ?>
                        </div>

                        <dl class="pc-rows">
                            <dt>Batting</dt><dd><?= e(pc_words($lot['batting_style'] ?? null)) ?></dd>
                            <dt>Bowling</dt><dd><?= e(pc_words($lot['bowling_style'] ?? null)) ?></dd>
                            <dt>Country</dt><dd><?= e(pc_or_dash($lot['country'] ?? null)) ?></dd>
                            <?php if (!empty($lot['phone'])): ?>
                                <dt>Mobile</dt><dd><?= e((string) $lot['phone']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($lot['email'])): ?>
                                <dt>Email</dt><dd><?= e((string) $lot['email']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($lot['address'])): ?>
                                <dt>Address</dt><dd><?= e((string) $lot['address']) ?></dd>
                            <?php endif; ?>
                        </dl>

                        <dl class="pc-stats">
                            <div class="pc-stat"><dt>Matches</dt><dd><?= (int) ($lot['career_matches'] ?? 0) ?></dd></div>
                            <div class="pc-stat"><dt>Runs</dt><dd><?= (int) ($lot['career_runs'] ?? 0) ?></dd></div>
                            <div class="pc-stat"><dt>Wickets</dt><dd><?= (int) ($lot['career_wickets'] ?? 0) ?></dd></div>
                            <div class="pc-stat"><dt>SR</dt><dd><?= e(number_format((float) ($lot['strike_rate'] ?? 0), 1)) ?></dd></div>
                            <div class="pc-stat"><dt>Econ</dt><dd><?= e(number_format((float) ($lot['economy'] ?? 0), 2)) ?></dd></div>
                        </dl>

                        <?php if (empty($lot['user_id'])): ?>
                            <p class="pc-note">
                                No account of their own — this player was entered by an administrator,
                                so there is no registration photograph or contact number on file.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
