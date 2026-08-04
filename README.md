# 🏏 CricAuction — Cricket Auction & Live Scoring Platform

A sports-oriented web application for running **player auctions** and **ball-by-ball live scoring**
for cricket tournaments.

**Stack:** PHP 8.1+ (OOP, PDO) · MySQL 8.0.16+ / MariaDB 10.2.1+ · Tailwind CSS (prebuilt) · Alpine.js (vendored) — no external CDN, no build step on the server

**Using the application?** → **[USER-GUIDE.md](USER-GUIDE.md)** — all five roles,
a clean-install procedure and a demonstration script.

**Deploying to shared hosting?** → **[DEPLOY-CPANEL.md](DEPLOY-CPANEL.md)**

---

## What's in this repo

**Phase 1 — foundation**

| # | Deliverable | Location |
|---|-------------|----------|
| 1 | Project structure | this file + folder tree below |
| 2 | Database schema | [`database/schema.sql`](database/schema.sql) (+ [`database/seed.sql`](database/seed.sql)) |
| 3 | Secure PDO connection | [`config/db.php`](config/db.php) |
| 4 | Auction dashboard UI | [`public/auction.php`](public/auction.php) |

**Phase 2 — the auction engine**

| Deliverable | Location |
|-------------|----------|
| Bid / sell / unsold logic, purse + squad enforcement | [`app/Services/AuctionService.php`](app/Services/AuctionService.php) |
| Auth, CSRF, role gating, JSON responses | [`app/Controllers/AuctionController.php`](app/Controllers/AuctionController.php) |
| HTTP endpoint | [`public/api/auction.php`](public/api/auction.php) |
| Typed rejections | [`app/Exceptions/AuctionException.php`](app/Exceptions/AuctionException.php) |
| Sign-in | [`public/login.php`](public/login.php) |
| Integration tests (48 assertions) | [`tests/auction_test.php`](tests/auction_test.php) |

**Phase 4 — accounts, approvals and the tournament cycle**

| Deliverable | Location |
|-------------|----------|
| Registration, approval, profiles, staff accounts, passwords | [`app/Services/AccountService.php`](app/Services/AccountService.php) |
| Tournaments, secret codes, applications, teams and owners | [`app/Services/TournamentService.php`](app/Services/TournamentService.php) |
| Typed rejections | [`app/Exceptions/AccountException.php`](app/Exceptions/AccountException.php) |
| Player registration (with the permanence warning) | [`public/register.php`](public/register.php) |
| My details / password / join a tournament / my team | [`public/profile.php`](public/profile.php), [`public/password.php`](public/password.php), [`public/apply.php`](public/apply.php), [`public/team.php`](public/team.php) |
| Administration — people, tournaments, applications, teams | [`public/admin/`](public/admin/) |
| Shared page shell for the account screens | [`app/Views/layouts/shell.php`](app/Views/layouts/shell.php) |
| Migration for an existing installation | [`database/migrations/001_accounts_and_registration.sql`](database/migrations/001_accounts_and_registration.sql) |
| Integration tests (140 assertions) | [`tests/account_test.php`](tests/account_test.php) |

**Phase 3 — the scorer's interface**

| Deliverable | Location |
|-------------|----------|
| Ball-by-ball scoring pad (HTML + Tailwind) | [`public/score.php`](public/score.php) |
| Demo match / squads | [`database/demo_match.php`](database/demo_match.php) |
| Scoring engine (record, undo, scorecard) | [`app/Services/ScoringService.php`](app/Services/ScoringService.php) |
| Auth, CSRF, role gating, JSON | [`app/Controllers/ScoringController.php`](app/Controllers/ScoringController.php) |
| HTTP endpoint | [`public/api/scoring.php`](public/api/scoring.php) |
| Live match fixture | [`database/seed_match.sql`](database/seed_match.sql) |
| Integration tests (54 assertions) | [`tests/scoring_test.php`](tests/scoring_test.php) |

---

## 1. Project structure

```
AuctionApplication/
├── app/
│   ├── Core/                  # Framework primitives (no business logic)
│   │   ├── Env.php            # .env parser (no external deps)
│   │   ├── Security.php       # e(), CSRF tokens, hardened session bootstrap
│   │   └── Auth.php           # login/logout, role gates, current user
│   ├── Controllers/
│   │   ├── AuctionController.php   # auth + CSRF + JSON; no business rules
│   │   └── ScoringController.php
│   ├── Services/
│   │   ├── AuctionService.php      # ★ the auction engine (transactional)
│   │   └── ScoringService.php      # ★ the scoring engine (transactional)
│   ├── Exceptions/
│   │   ├── AuctionException.php    # typed, machine-readable rejections
│   │   └── ScoringException.php
│   ├── Models/                # Player, Team, MatchModel, Ball … (Phase 3)
│   └── Views/
│       ├── layouts/           # app.php (shell), partials (nav, toasts)
│       ├── auction/           # dashboard.php, player-pool.php, results.php
│       ├── scoring/           # setup.php, live.php (scorer pad), scorecard.php
│       └── auth/              # login.php
├── config/
│   ├── config.php             # app constants, timezone, error handling, autoloader
│   └── db.php                 # ★ PDO singleton (prepared statements, utf8mb4)
├── database/
│   ├── schema.sql             # ★ full DDL: 10 tables, FKs, CHECKs, indexes, views
│   ├── reset.sql              # ★ wipe to a clean, empty application
│   ├── demo_apl.sql           # ★ 6 teams, 60 players, ready to demonstrate
│   └── seed.sql               # fixtures the test suites run against
├── public/                    # ← the ONLY web-exposed directory (set as docroot)
│   ├── index.php              # ★ landing page — roles, features, live status (no JS)
│   ├── auction.php            # ★ live auction dashboard
│   ├── login.php              # sign-in; logout.php ends the session
│   ├── score.php              # ★ scorer's pad (ball-by-ball entry)
│   ├── api/auction.php        # ★ bid / sell / unsold / next / state
│   ├── api/scoring.php        # ★ ball / undo / scorecard
│   └── assets/{css,js,img}/
├── tests/
│   ├── auction_test.php       # integration tests against a real database
│   └── scoring_test.php
├── storage/logs/              # app + PHP error logs (writable, never web-served)
├── deploy/
│   └── strip-create-database.sh   # makes the SQL importable on cPanel
├── .htaccess                  # deny-all guard (see DEPLOY-CPANEL.md)
├── public/.htaccess           # the only directory the web may reach
├── .cpanel.yml                # cPanel Git Version Control deploy tasks
├── DEPLOY-CPANEL.md           # ★ shared-hosting deployment guide
├── .env.example               # copy to .env and fill in
└── .gitignore
```

**Why this shape**

- `public/` is the document root, so `config/`, `app/`, `storage/` and `.env` can never be
  fetched over HTTP even if PHP stops executing.
- `app/Core` holds infrastructure, `app/Models` holds data access (one class per table
  aggregate), `app/Controllers` holds request handling, `app/Views` holds presentation only.
  Views receive arrays — they never open a PDO connection.
- The autoloader in `config/config.php` maps `App\…` namespaces to `app/…`, so adding
  `app/Models/Player.php` (`namespace App\Models;`) requires zero registration.

### Auction API

All writes are `POST` and require a valid CSRF token, sent either as a
`csrf_token` field or an `X-CSRF-Token` header.

| Action | Params | Role | Does |
|--------|--------|------|------|
| `bid` | `lot_id`, `amount` | team_owner | Validates and records a bid |
| `sell` | `lot_id` | admin | Awards the lot, debits the purse, marks the player sold |
| `unsold` | `lot_id` | admin | Closes the lot with no winner |
| `next` | `tournament_id` | admin | Puts the next queued player under the hammer |
| `state` | `tournament_id` | any signed-in user | `GET` — live lot, purse board, bid feed |

Success is `{"ok": true, …}`. A rejection is `{"ok": false, "error": CODE,
"message": …, "context": {…}}` with one of:

`LOT_NOT_FOUND` · `LOT_NOT_LIVE` · `LOT_EXPIRED` · `LOT_ALREADY_OPEN` ·
`ALREADY_LEADING` · `BID_TOO_LOW` · `BID_NOT_ALIGNED` · `INSUFFICIENT_PURSE` ·
`SQUAD_FULL` · `OVERSEAS_LIMIT` · `NO_BIDS` · `NOTHING_QUEUED` ·
`WRONG_TOURNAMENT` · `CSRF_FAILED` · `BAD_REQUEST`

`INSUFFICIENT_PURSE` returns the affordable ceiling in `context.max_bid`, so
the UI can say *how much* short the team is rather than just "no".

**The bidding team is never read from the request** — it comes from the
session, so an owner cannot spend another franchise's purse by editing a form
field.

### How a bid stays correct under load

Every write takes a row lock before it reads anything it intends to act on:

```sql
SELECT … FROM auction_lots WHERE id = ? FOR UPDATE
```

Two owners clicking *Bid* in the same millisecond both reach `placeBid()`.
The first to acquire the lot row proceeds; the second blocks until that
commits, then re-reads a `current_bid` that already includes the first bid —
so it fails `BID_TOO_LOW` instead of overwriting. Without the lock both would
read the stale bid and the later write would silently win.

Behind it sit two more layers: `UNIQUE (lot_id, bid_amount)` makes a
duplicate bid a key error rather than a lost update, and `chk_team_spent`
makes an overdraft impossible even if the service layer were bypassed
entirely. Lock order is always lot → team, which is what keeps it
deadlock-free.

Verified by racing four concurrent bidders at the same amount, six times:
one winner, three `BID_TOO_LOW`, one bid row, every time.

### The scorer's pad

`public/score.php` is built for one situation: a scorer standing at the
boundary rope, holding a phone in one hand, in sunlight. Every layout
decision follows from that.

| Decision | Why |
|----------|-----|
| Every scoring control ≥ 64px tall; run keys 76–88px | Well above the 44px minimum tap target — a mis-tap here corrupts the match, not just the view |
| Six run keys in a fixed 3×2 grid in the lower half | Inside thumb reach, and they never move or reflow between balls, so the scorer builds muscle memory |
| Wicket isolated below the extras row, outlined in red | Rare and destructive; it must not sit adjacent to the key pressed 95% of the time |
| Score pinned to the top | The scorer must never scroll to confirm what they just entered |
| Extras open a second sheet for the runs run | A wide with 2 extra is two taps, and neither tap is ambiguous |
| Blocking prompt after a wicket / end of over | Scoring is disabled until the new batter or bowler is named, so the log can't record balls faced by nobody |
| Colour never carries meaning alone | Every key has a text label; the over chips are readable in greyscale |
| Keyboard shortcuts (`0–6`, `W`, `D`, `N`, `U`) | The same page doubles as a desktop scoring console |

**State model.** The innings is an append-only array of balls shaped exactly
like a `ball_by_ball` row. Totals, both batting cards, bowling figures, the
over chips and the commentary are all *derived* from that array on read —
never stored alongside it. That is the same relationship `ball_by_ball` has
with the `innings` cache table, and it is what makes Undo a one-line pop plus
restoring the snapshot taken before the ball (who was on strike, who was
bowling, who was out) rather than trying to reverse the rules.

Rules currently handled: strike rotation on odd runs including byes and extra
wides, end-of-over rotation, wides and no-balls not counting toward the over,
byes and leg-byes not credited to the batter or charged to the bowler, maidens,
bowler-credited vs. run-out dismissals, and a bowler being unable to bowl
consecutive overs.

### Scoring API

| Action | Params | Role | Does |
|--------|--------|------|------|
| `ball` | `innings_id` + the delivery | scorer, admin | Records one ball |
| `undo` | `innings_id` | scorer, admin | Removes the most recent ball |
| `scorecard` | `innings_id` | any signed-in user | `GET` — the whole card, safe to poll |

The delivery itself is `runs_off_bat`, or `extra_type` + `extra_runs`, or
`is_wicket` + `dismissal_type` (+ `dismissed_player_id`, `fielder_id`).

**The client does not send who is on strike.** The server derives it from the
previous ball and the laws — batters cross on odd runs (off the bat, run as
byes, or run beyond the penalty on a wide), ends change at the end of a legal
over, and a wicket leaves an end vacant. The pad supplies only what the server
cannot know: `striker_id` + `non_striker_id` + `bowler_id` on the first ball,
`new_batter_id` after a wicket, and `bowler_id` at the start of an over. A
buggy or hostile client therefore cannot credit runs to the wrong batter.

When the server needs one of those, it says so with `NEEDS_OPENING`,
`NEEDS_BATTER` or `NEEDS_BOWLER`, and the pad opens the matching picker.
Other codes: `INNINGS_CLOSED` · `MATCH_NOT_LIVE` · `NOT_IN_SQUAD` ·
`ALREADY_OUT` · `SAME_BATTER` · `CONSECUTIVE_OVERS` · `BAD_BALL` ·
`NOTHING_TO_UNDO`.

### Updating the scorecard without a reload

Every response — from `ball`, `undo` and `scorecard` alike — is the **complete
scorecard**, never a delta. The pad calls `fetch()`, then replaces its state
wholesale in `hydrate()`. Three things follow:

- A dropped, duplicated or out-of-order response cannot leave the pad
  half-updated, because there is no accumulated local state to corrupt.
- The client and the database cannot disagree; the browser is a view.
- Undo needs no special client handling — it is just another scorecard.

Nothing is applied optimistically: a ball the database rejected must never
look like it was scored. A `busy` flag blocks a double-tap while a ball is in
flight, which on a phone is a real way to score the same delivery twice.

`ball_by_ball` remains the source of truth. The `innings` row is a cache,
recomputed from the log after every write rather than incremented — so undo,
a correction, or a direct edit can never leave the totals wrong.

### Still to route (Phase 3)

`public/index.php` becomes a thin front controller in front of
`App\Core\Router`:

| Route | Controller | Role |
|-------|-----------|------|
| `GET /match/{id}/scorecard` | `MatchController@scorecard` | all |
| `GET /match/{id}` | `MatchController@show` | all |

A read-only viewer scorecard can be built on `action=scorecard` today — it is
already open to any signed-in user and safe to poll.

---

## 2. Setup

```bash
# 1. Database
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql        # optional demo data
mysql -u root -p < database/seed_match.sql  # optional live match for the scorer

# 2. Config
cp .env.example .env      # then edit DB_USER / DB_PASS

# 3. Run
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. The dashboard renders live from MySQL when `.env` points at a
seeded database, and falls back to a built-in demo dataset otherwise — so the UI is
reviewable before the DB exists.

Seeded accounts all use the password `Passw0rd!`:
`admin@cricauction.test`, `scorer@cricauction.test`, `viewer@cricauction.test`,
and one owner per team (`owner.ts@`, `owner.rc@`, `owner.ck@`, `owner.df@`).
**Rotate these before exposing the app to a network.**

### Tests

```bash
php tests/auction_test.php     # 48 assertions
php tests/scoring_test.php     # 54 assertions
```

The auction suite covers bid validation, purse and squad enforcement, the sale
transaction and the read model. The scoring suite covers strike rotation,
extras, over completion, wickets, undo and the innings cache. Both reload the
SQL files on every run, so they are destructive to the `cric_auction` database
and safe to re-run.

**Production:** point Apache/Nginx `DocumentRoot` at `public/`, set `APP_ENV=production`
(hides errors, enables `session.cookie_secure`), and grant the MySQL user only
`SELECT, INSERT, UPDATE, DELETE` on the app schema.

For cPanel or other shared hosting, follow **[DEPLOY-CPANEL.md](DEPLOY-CPANEL.md)** —
it covers the two version requirements that decide whether the app can run at all,
the `CREATE DATABASE` statements that must be stripped before importing, and the
document-root setup.

---

## 3. Security posture

| Threat | Mitigation | Where |
|--------|-----------|-------|
| SQL injection | PDO prepared statements, `ATTR_EMULATE_PREPARES => false` (real server-side binds) | `config/db.php` |
| XSS | `e()` — `htmlspecialchars` with `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8; every echo goes through it | `app/Core/Security.php` |
| CSRF | Per-session token, `hash_equals()` comparison, required on every POST | `app/Core/Security.php` |
| Session hijacking | `HttpOnly`, `SameSite=Lax`, `Secure` in prod, strict mode, ID regenerated on login | `app/Core/Security.php` |
| Credential theft | `password_hash()` / `password_verify()` (bcrypt), never plaintext | `app/Core/Auth.php` |
| Privilege escalation | `Auth::require('admin')` role gate on every mutating controller action | `app/Core/Auth.php` |
| Overspending / negative purse | `purse_remaining` is a generated column + `CHECK (purse_spent <= purse_total)`; bids validated in a transaction | `database/schema.sql` |
| Bidding as another team | Team read from the session, never from the request body | `app/Controllers/AuctionController.php` |
| Lost updates / double sale | `SELECT … FOR UPDATE` on the lot + `UNIQUE (lot_id, bid_amount)` | `app/Services/AuctionService.php` |
| Money rounding | Compared in integer paise; DECIMAL columns, never FLOAT | `app/Services/AuctionService.php` |
| Secret leakage | `.env` outside docroot, git-ignored; no credentials in source | `.gitignore` |

---

## Roadmap

- ~~**Phase 2** — auction write path inside a `SELECT … FOR UPDATE` transaction~~ ✅
- ~~**Phase 3** — scorer pad UI, `ball_by_ball` ingestion, live scorecard~~ ✅
- ~~**Phase 4** — self-registration, approvals, tournaments and their secret
  codes, teams and owners; the screens for all of it~~ ✅
- **Phase 4b** — admin CRUD for the player pool itself (base prices, auction
  sets, re-listing unsold players) and a CSV import, so an existing pool does
  not have to register one at a time.
- **Phase 3b** — innings break, second-innings target and result calculation;
  a read-only viewer scorecard on `action=scorecard`.
- **Phase 5** — Replace the 3-second poll with SSE; PDF scorecards; email for
  approvals and password resets.
