/**
 * Capture the screenshots the user guides are illustrated with.
 *
 *     php -S 127.0.0.1:8088 -t public &
 *     node docs/capture-screens.mjs
 *
 * Expects the demonstration data loaded (database/demo_apl.sql) plus a
 * couple of pending items, so the administrator's queues are not empty in
 * the pictures. See docs/README-guides.md.
 *
 * Images land in docs/screens/ and are embedded into the PDFs by
 * docs/build-guides.mjs.
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT  = join(HERE, 'screens');
const BASE = process.env.GUIDE_BASE_URL || 'http://127.0.0.1:8088';
const PW   = process.env.GUIDE_PASSWORD || 'Guide2026x';

mkdirSync(OUT, { recursive: true });

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const browser = await chromium.launch({ executablePath: CHROME });

/** A context signed in as the given account, or signed out when null. */
async function session(username) {
    const ctx  = await browser.newContext({
        viewport: { width: 1180, height: 820 },
        deviceScaleFactor: 1,
    });
    const page = await ctx.newPage();

    if (username !== null) {
        await page.goto(`${BASE}/login.php`);
        await page.fill('#identifier', username);
        await page.fill('#password', PW);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }

    return { ctx, page };
}

const shots = [];

/**
 * Dismiss the opening-batters and opening-bowler dialogues by choosing the
 * first option each time, so the keypad itself is visible.
 */
async function openTheInnings(page) {
    for (let i = 0; i < 6; i++) {
        const batter = page.locator('h3:text("Next batter in")');
        const bowler = page.locator('h3:text("Bowler for the next over")');

        if (await batter.isVisible().catch(() => false)) {
            await page.locator('h3:text("Next batter in") ~ div button').first().click();
        } else if (await bowler.isVisible().catch(() => false)) {
            await page.locator('h3:text("Bowler for the next over") ~ div button:not([disabled])')
                      .first().click();
        } else {
            return;
        }

        await page.waitForTimeout(500);
    }
}

async function shot(page, path, name, { full = false, settle = 400 } = {}) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(settle);
    const file = join(OUT, `${name}.png`);
    await page.screenshot({ path: file, fullPage: full });
    shots.push(name);
    console.log(`  ${name.padEnd(24)} ${path}`);
}

// ---------------------------------------------------------------- guest
{
    const { ctx, page } = await session(null);
    // Not fullPage: the landing page is 2,400px tall, and scaled to the
    // width of a printed page that is taller than the page itself. A tall
    // viewport captures the part that matters at a usable aspect ratio.
    await page.setViewportSize({ width: 1180, height: 1080 });
    await shot(page, '/index.php', 'landing');
    await page.setViewportSize({ width: 1180, height: 820 });
    await shot(page, '/login.php', 'login');
    await shot(page, '/register.php', 'register-form', { full: true });

    // Step two of registration — the screen that states, before anything is
    // saved, that the name and email are permanent. The player guide leans
    // on this picture, so it has to be the real one.
    await page.goto(`${BASE}/register.php`);
    await page.fill('#f_name', 'Nikhil Rao');
    await page.fill('#f_email', 'nikhil.rao.demo@club.test');
    await page.fill('#f_username', 'nikhil.rao.demo');
    await page.fill('#f_phone', '9876543210');
    await page.fill('#f_address', '22 Fort Road, Kochi');
    await page.selectOption('#f_player_type', 'all_rounder');
    await page.fill('#f_password', 'Guide2026x');
    await page.fill('#f_password_confirm', 'Guide2026x');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.screenshot({ path: join(OUT, 'register-confirm.png'), fullPage: true });
    shots.push('register-confirm');
    console.log('  register-confirm         (step two)');

    await ctx.close();
}

// --------------------------------------------------------------- player
{
    const { ctx, page } = await session('nikhil.rao');
    await shot(page, '/profile.php', 'player-profile', { full: true });
    await shot(page, '/apply.php', 'player-apply', { full: true });
    await shot(page, '/password.php', 'password', { full: true });
    await ctx.close();
}

// ---------------------------------------------------------------- owner
{
    const { ctx, page } = await session('apl.hc');
    await shot(page, '/team.php', 'owner-team', { full: true });
    await shot(page, '/auction.php', 'auction-board', { settle: 1800 });
    await ctx.close();
}

// --------------------------------------------------------------- scorer
//
//  An innings that has not started opens on the "choose the openers"
//  modal, which covers the entire pad. Clicking through it is not
//  cosmetic: the guides are illustrated with the keypad, not with a
//  dialogue box sitting on top of it.
{
    const { ctx, page } = await session('apl.scorer');

    await page.goto(`${BASE}/score.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await openTheInnings(page);

    // A few balls, so the scorecard shows a real score rather than 0/0.
    // Four, not six: a completed over pops the next-bowler dialogue, which
    // is the very thing we just clicked away.
    for (const runs of [1, 4, 0, 2]) {
        const key = page.locator(`button[aria-label="${runs} runs"]`).first();
        if (await key.count() === 0) break;
        await key.click();
        await page.waitForTimeout(320);
    }
    await page.waitForTimeout(600);
    await openTheInnings(page);   // belt and braces

    await page.screenshot({ path: join(OUT, 'scorer-pad.png') });
    shots.push('scorer-pad');
    console.log('  scorer-pad               /score.php');

    // The pad on a phone, which is how it is actually used.
    await page.setViewportSize({ width: 420, height: 900 });
    await page.goto(`${BASE}/score.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await openTheInnings(page);
    await page.screenshot({ path: join(OUT, 'scorer-phone.png') });
    shots.push('scorer-phone');
    console.log('  scorer-phone             (420px wide)');
    await ctx.close();
}

// ---------------------------------------------------------------- admin
{
    const { ctx, page } = await session('apl.admin');
    await shot(page, '/admin/index.php', 'admin-hub', { full: true });
    await shot(page, '/admin/users.php?status=pending', 'admin-people', { full: true });
    await shot(page, '/admin/tournaments.php', 'admin-tournaments', { full: true });
    await shot(page, '/admin/applications.php', 'admin-applications', { full: true });
    await page.setViewportSize({ width: 1180, height: 1080 });
    await shot(page, '/admin/teams.php', 'admin-teams');
    await page.setViewportSize({ width: 1180, height: 820 });
    await ctx.close();
}

// --------------------------------------------------------------- viewer
{
    const { ctx, page } = await session(null);
    await shot(page, '/auction.php?role=viewer', 'viewer-auction', { settle: 1800 });
    await shot(page, '/score.php', 'viewer-scorecard', { settle: 1500 });
    await ctx.close();
}

await browser.close();
console.log(`\n${shots.length} screenshots in docs/screens/`);
