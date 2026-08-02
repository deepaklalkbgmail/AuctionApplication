# 🏏 CricAuction — Cricket Auction & Live Scoring Platform

A sports-oriented web application for running **player auctions** and **ball-by-ball live scoring**
for cricket tournaments.

**Stack:** PHP 8.2+ (OOP, PDO) · MySQL 8 · Tailwind CSS (CDN) · Alpine.js · Vanilla JS

---

## Phase 1 — What's in this repo

| # | Deliverable | Location |
|---|-------------|----------|
| 1 | Project structure | this file + folder tree below |
| 2 | Database schema | [`database/schema.sql`](database/schema.sql) (+ [`database/seed.sql`](database/seed.sql)) |
| 3 | Secure PDO connection | [`config/db.php`](config/db.php) |
| 4 | Auction dashboard UI | [`public/index.php`](public/index.php) |

---

## 1. Project structure

```
AuctionApplication/
├── app/
│   ├── Core/                  # Framework primitives (no business logic)
│   │   ├── Env.php            # .env parser (no external deps)
│   │   ├── Security.php       # e(), CSRF tokens, hardened session bootstrap
│   │   └── Auth.php           # login/logout, role gates, current user
│   ├── Controllers/           # AuctionController, ScoringController, MatchController …
│   ├── Models/                # Player, Team, AuctionLot, MatchModel, Ball …
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
│   └── seed.sql               # demo tournament, teams, players, auction lots
├── public/                    # ← the ONLY web-exposed directory (set as docroot)
│   ├── index.php              # ★ unified dashboard / live auction screen
│   └── assets/{css,js,img}/
├── storage/logs/              # app + PHP error logs (writable, never web-served)
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

### Routing plan (Phase 2)

`public/index.php` becomes a thin front controller in front of `App\Core\Router`:

| Route | Controller | Role |
|-------|-----------|------|
| `GET /auction` | `AuctionController@dashboard` | all |
| `POST /auction/bid` | `AuctionController@placeBid` | team_owner |
| `POST /auction/sell` | `AuctionController@sell` | admin |
| `GET /auction/state.json` | `AuctionController@state` | all (polled / SSE) |
| `GET /match/{id}/score` | `ScoringController@pad` | scorer |
| `POST /match/{id}/ball` | `ScoringController@recordBall` | scorer |
| `GET /match/{id}/scorecard` | `MatchController@scorecard` | all |

---

## 2. Setup

```bash
# 1. Database
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql        # optional demo data

# 2. Config
cp .env.example .env      # then edit DB_USER / DB_PASS

# 3. Run
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. The dashboard renders live from MySQL when `.env` points at a
seeded database, and falls back to a built-in demo dataset otherwise — so the UI is
reviewable before the DB exists.

**Production:** point Apache/Nginx `DocumentRoot` at `public/`, set `APP_ENV=production`
(hides errors, enables `session.cookie_secure`), and grant the MySQL user only
`SELECT, INSERT, UPDATE, DELETE` on the app schema.

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
| Secret leakage | `.env` outside docroot, git-ignored; no credentials in source | `.gitignore` |

---

## Roadmap

- **Phase 2** — Auth + router + auction write path (`placeBid` / `sell` inside a
  `SELECT … FOR UPDATE` transaction), admin CRUD for players & teams.
- **Phase 3** — Scorer pad, `ball_by_ball` ingestion, live scorecard aggregation.
- **Phase 4** — Real-time transport (SSE, polling fallback), CSV player import, PDF scorecards.
