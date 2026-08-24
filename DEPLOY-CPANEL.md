# Deploying CricAuction on cPanel

Written for a standard shared-hosting cPanel account. Roughly 30–45 minutes
the first time.

Read **[Step 0](#step-0--check-the-two-things-that-can-stop-you)** before you
buy or commit to a host: two server versions decide whether this app can run
there at all.

---

## Step 0 — Check the two things that can stop you

### PHP must be 8.1 or newer

The code uses readonly properties, `never` return types and `new` in
initialisers. On PHP 8.0 or below it will not even parse — you get a blank
white page or a 500.

**cPanel → Software → MultiPHP Manager.** Tick your domain, choose **PHP 8.2**
(or 8.1/8.3), click **Apply**.

Then **cPanel → Select PHP Version → Extensions** and confirm these are
ticked:

| Extension | Needed for |
|-----------|-----------|
| `pdo_mysql` | every database call — without it nothing works |
| `mbstring` | UTF-8 team and player names |
| `json` | the fetch API responses |
| `openssl` | CSRF tokens (`random_bytes`) |

### MySQL must be 8.0.16+, or MariaDB 10.2.1+

This is the one that quietly bites. The schema puts real integrity rules in
the database — a team cannot overspend its purse, a wide cannot be counted as
a legal ball, a sold player must have a team and a price. Those are `CHECK`
constraints, and **MySQL 5.7 parses them and silently ignores them.** The
import "succeeds" and you lose every guarantee without a single error message.

Check it: **cPanel → phpMyAdmin → SQL tab**, run:

```sql
SELECT VERSION();
```

| Result | Verdict |
|--------|---------|
| `8.0.16` or higher | Good |
| `10.2.1`+ MariaDB | Good (developed and tested against MariaDB 10.11) |
| `8.0.0`–`8.0.15` | `CHECK` unsupported — ask your host to upgrade |
| `5.7.x` or `5.6.x` | **Do not deploy.** Constraints are silently dropped |

If you are on 5.7 and cannot upgrade, tell me — the constraints can be moved
into the PHP service layer, but that is a real change and it is strictly
weaker than having the database enforce them.

---

## Step 1 — Create the database and user

**cPanel → Databases → MySQL® Databases.**

On this account the prefix `deamco_` is mandatory — cPanel adds it for you and
it cannot be removed.

1. **Create New Database** — type `APL` in the box. cPanel shows the prefix
   beside it, so the database is created as **`deamco_APL`**.
2. **Add New User** — type `dpk`, giving **`deamco_dpk`**. Set the password and
   save it somewhere safe (a password manager, not a text file on your desktop).
3. **Add User To Database** — pick `deamco_dpk` and `deamco_APL`, grant
   **ALL PRIVILEGES**.

> **Case matters.** On Linux, MySQL database names are case-sensitive:
> `deamco_APL` and `deamco_apl` are two different databases. Copy the name
> exactly as cPanel displays it.

> The app itself only needs `SELECT, INSERT, UPDATE, DELETE`. The import in
> Step 2 additionally needs `CREATE`, `INDEX` and `REFERENCES`. Grant ALL for
> the import; you can revoke the DDL rights afterwards once the tables exist.

---

## Step 2 — Import the schema

The SQL files start with `CREATE DATABASE` and `USE`. **Both will fail on
cPanel** — you cannot create databases from SQL there, and the real name is
prefixed. Strip them first.

**If you have SSH or cPanel → Terminal:**

```bash
cd ~/cricauction        # wherever you put the repo
./deploy/strip-create-database.sh
# writes deploy/out/schema.sql, seed.sql, seed_match.sql
```

**If you have no shell**, open `database/schema.sql` in a text editor and
delete the block marked `SHARED HOSTING … DELETE the next two statements` —
the `CREATE DATABASE …;` statement and the `USE \`cric_auction\`;` line. Do the
same `USE` line in `seed.sql` and `seed_match.sql`.

Then **phpMyAdmin → select `deamco_APL` in the left sidebar → Import**,
and import **in this order**:

| # | File | Required? |
|---|------|-----------|
| 1 | `schema.sql` | Yes — 10 tables and 3 views |
| 2 | `seed.sql` | Optional — demo tournament, teams, players |
| 3 | `seed_match.sql` | Optional — a live match for the scorer pad |

Order matters: the foreign keys depend on it.

Confirm afterwards — the sidebar should show **14 objects** (11 tables +
3 views). If `users` is missing, your MySQL rejected a constraint; check the
version in Step 0.

### Already have the application running?

If your database was created before registrations and tournaments existed,
do **not** re-import `schema.sql` — it drops every table. Import
`database/migrations/001_accounts_and_registration.sql` instead.

**Check first whether it has already been applied.** The migration is not
repeatable: run it twice and the second run stops at `#1060 - Duplicate
column name 'username'`, which is MySQL saying the work is already done.
`database/migrations/001_verify.sql` is read-only and tells you which state
you are in — every row should read `OK`.

> **Select the database before running any of this.** In phpMyAdmin, click
> the database in the left sidebar *first*, then open the SQL tab. Opened
> from the server level no database is selected, `DATABASE()` is NULL, and
> anything keyed on it reports nothing — which looks exactly like a
> database that was never migrated. The verify script prints which
> database it is reading as its first row, so that mistake is visible
> rather than silent. It is
additive: it adds the new columns and the `tournament_registrations` table,
gives every existing account a username derived from its email address, and
deletes nothing. A season already in progress survives it.

Back the database up first — phpMyAdmin → Export → Go — as with any change.

> **Production:** skip files 2 and 3. They create demo accounts with the
> published password `Passw0rd!`. If you do import them to try things out,
> Step 9 tells you how to clear them.

---

## Step 3 — Upload the code

**Where it goes depends on Step 4.** Decide that first:

| Situation | Upload to | URL |
|-----------|-----------|-----|
| The domain is free, or you can add a subdomain | `/home/deamco/cricauction` (outside `public_html`) | `https://auction.yourdomain.com` |
| The domain already runs another site | `/home/deamco/public_html/APL` | `https://deam.co.in/APL` |

The first is safer — the app sits physically outside the web root. Use the
second only when the domain is already taken; the guards described in Step 4
make it safe, but they depend on Apache honouring `.htaccess`.

Then pick whichever upload method you're comfortable with.

### Option A — cPanel Git™ Version Control (best for updating later)

1. **cPanel → Files → Git™ Version Control → Create.**
2. Tick **Clone a Repository**, paste the HTTPS or SSH URL, set
   **Repository Path** to `/home/deamco/cricauction`.
3. `.cpanel.yml` is already set to `/home/deamco/public_html/APL`. Change
   `DEPLOYPATH` if you are deploying elsewhere, then commit and push.
4. Back in cPanel: **Manage → Pull or Deploy → Update from Remote**, then
   **Deploy HEAD Commit**.

Later updates are then two clicks.

### Option B — Zip upload (simplest)

1. Download the repo as a ZIP from GitHub.
2. **cPanel → File Manager**, navigate to your target from the table above,
   **Upload**, then **Extract**.
3. Rename the extracted folder (`cricauction`, or `APL` for a subfolder
   install).

GitHub's ZIP nests everything inside a folder like
`AuctionApplication-main/` — make sure `public/`, `app/` and `.htaccess` end
up directly inside your target folder, not one level deeper. Turn on
**Settings → Show Hidden Files** first, or the `.htaccess` files will not be
extracted where you can see them.

### Option C — SFTP

Upload the whole project to `/home/deamco/cricauction`.

**Do not upload:** `tests/`, `deploy/`, `resources/`, `tailwind.config.js`,
`.git/`, `node_modules/` — build-time or development only, harmless
but pointless in production. **Do upload every `.htaccess`**: the one at the
project root and the ones inside `app/`, `config/`, `database/` and
`storage/`. On a subfolder install they are what keeps your credentials
private.

---

## Step 4 — Point the domain at `public/`

The web server must serve **`cricauction/public`**, not the project root.

### Recommended: a subdomain

**cPanel → Domains → Create A New Domain.**

- Domain: `auction.yourdomain.com`
- Untick *"Share document root"*
- **Document Root:** `/home/deamco/cricauction/public`

That's it — this is the layout the app was designed for.

### Existing domain

**cPanel → Domains**, find the domain, click the pencil / **Manage** beside
its document root, and change it to `/home/deamco/cricauction/public`.

### Subfolder of an existing site (e.g. `deam.co.in/APL`)

If the domain already serves another website, you cannot repoint its
document root — the other site would break. Install into a subfolder of
`public_html` instead:

```
/home/deamco/public_html/
├── index.php  …            ← the existing deam.co.in site, untouched
└── APL/                    ← this app
    ├── .htaccess           ← rewrites into public/ and guards the rest
    ├── app/ config/ database/ storage/   ← each has its own deny
    ├── .env
    └── public/
```

The URL stays clean — **`https://deam.co.in/APL`**, not `/APL/public`. The
root `.htaccess` rewrites every request into `public/` internally, so the
browser never sees that directory.

**Why the guards are shaped the way they are.** Apache evaluates
authorization *before* mod_rewrite's per-directory fixup runs. A blanket
`Require all denied` at the project root would therefore reject the request
before the rewrite ever happened, and the whole app would 403 — I hit
exactly that while testing. So the private directories each carry their own
`.htaccess` deny instead. Those are authorization-phase rules, which means
they hold whether or not mod_rewrite is available.

An `.htaccess` governs HTTP requests only; PHP including `config/config.php`
from disk is unaffected.

Set `APP_URL=https://deam.co.in/APL` in Step 5 — with the subfolder, and no
trailing slash. Login redirects are built from it.

Tested against Apache 2.4 with `AllowOverride All` in exactly this layout:
the existing root site keeps serving; `/APL/`, `/APL/score.php`,
`/APL/login.php` and `/APL/api/*.php` all resolve; `/APL` without the slash
301s to `/APL/` so relative asset and API paths resolve correctly; and
`/APL/.env`, `/APL/config/db.php`, `/APL/app/Core/Auth.php`,
`/APL/database/seed.sql`, `/APL/tests/`, the log and every dotfile return
**403** with nothing disclosed.

One caveat: this relies on the host honouring `.htaccess`
(`AllowOverride All`, cPanel's default) and having `mod_rewrite` enabled —
both are near-universal on cPanel. A subdomain with its own document root
needs neither, so prefer that when the domain is free.

---

## Step 5 — Create `.env`

**File Manager → `/home/deamco/cricauction`**, copy `.env.example` to `.env`
(File Manager hides dotfiles until you tick *Show Hidden Files* under
Settings), then edit:

```ini
APP_NAME="CricAuction"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://auction.yourdomain.com
APP_TIMEZONE=Asia/Kolkata

DB_HOST=localhost
DB_PORT=3306
DB_NAME=deamco_APL
DB_USER=deamco_dpk
DB_PASS=your-database-password
DB_CHARSET=utf8mb4
```

Three of these matter more than they look:

- **`DB_HOST=localhost`** — not `127.0.0.1`. On cPanel `localhost` uses the
  MySQL socket; `127.0.0.1` forces TCP and is often blocked.
- **`APP_URL`** — with no trailing slash, and it must match how you actually
  reach the site. Login redirects are built from it, so a wrong value sends
  people to a 404 after signing in.
- **`APP_ENV=production`** — turns off error display (stack traces would
  otherwise show your file paths to visitors) and switches session cookies to
  `Secure`, which requires HTTPS. See Step 6.

---

### Changing the host, username or password later

**`.env` is the only file you ever edit.** There is no host, username or
password anywhere in the PHP source — `config/db.php` reads all of them from
this one file at runtime.

| If this changes | Edit | Then |
|-----------------|------|------|
| Database password | `DB_PASS` in `.env` | Nothing else — takes effect on the next request |
| Database user | `DB_USER` (and `DB_PASS`) | Re-attach the user to the database in cPanel → MySQL® Databases → **Add User To Database** |
| Database name | `DB_NAME` | Import the schema into the new database first |
| MySQL host | `DB_HOST` | Only if your host moves you to a remote MySQL server; add your IP under **Remote MySQL** if so |
| Site URL / domain | `APP_URL` | Update the document root too if the domain changed (Step 4) |

Three things worth knowing:

- **No restart, no cache to clear.** PHP reads `.env` on every request, so a
  saved change is live immediately.
- **`.env` is git-ignored and `.cpanel.yml` never copies it**, so pulling a new
  version of the code will not overwrite your credentials.
- **Never put the real password in `.env.example`.** That file *is* committed —
  anything written there is published to GitHub and stays in the git history
  even after you delete it. If a password ever lands in a commit, rotate it in
  cPanel; deleting the line is not enough.

If a credential change breaks the site, the symptom tells you which one:
`SQLSTATE[HY000] [1045]` is a wrong user or password, `[1049]` is a wrong
database name, and `[2002]` is a wrong host.

---

### A note on Content-Security-Policy

If the site already at the domain root sets a CSP, Apache cascades it into
`/APL` too — and a policy of `script-src 'self'` blocks this app's
JavaScript, leaving an unstyled, inert page.

`public/.htaccess` handles this: it unsets whatever was inherited and
declares the app's own policy. `unset` has to come first, because Apache
appends this file's directives after the parent's; without it both policies
apply and the browser enforces the stricter intersection.

The app ships no external dependencies at all — Tailwind is prebuilt into
`public/assets/css/app.css`, Alpine is vendored at
`public/assets/js/alpine.js`, and there are no web fonts — so its policy
allows no third-party origin. Two relaxations remain, both documented inline
in `public/.htaccess`: `'unsafe-eval'` because Alpine compiles `x-data` and
`@click` expressions with the `Function` constructor, and `'unsafe-inline'`
for **styles only**, because a few `style=""` attributes carry a team's
colour straight from the database. `script-src 'unsafe-inline'` — the one
that actually matters for injection — is not granted.

If you change any markup, rebuild the stylesheet, or classes you added will
be missing:

```bash
npx tailwindcss@3 -c tailwind.config.js -i resources/app.css \
    -o public/assets/css/app.css --minify
```

---

## Step 6 — Enable HTTPS (not optional)

With `APP_ENV=production` the session cookie is marked `Secure`, so the
browser refuses to send it over plain HTTP. **Without SSL, logging in appears
to succeed and then bounces you straight back to the login page** — the app is
not broken, the cookie is simply never returned.

1. **cPanel → Security → SSL/TLS Status.** Tick the domain, **Run AutoSSL**.
   Wait for the padlock (usually a few minutes).
2. **cPanel → Domains**, switch on **Force HTTPS Redirect** for the domain.

If your cPanel has no such toggle, uncomment the HTTPS rewrite block at the
bottom of `public/.htaccess` instead.

---

## Step 7 — Permissions

Via **File Manager → Permissions**, or Terminal:

```bash
cd ~/cricauction
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 755 storage storage/logs
chmod 755 public/assets/img/uploads    # players' photos are written here
chmod 600 .env                 # owner-only; nothing else needs to read it
```

**Never use 777.** On shared hosting that makes your files writable by other
accounts on the same server, and many hosts refuse to execute PHP in a
world-writable directory anyway.

`storage/logs/` must be writable or PHP cannot write its error log, and
`public/assets/img/uploads/` must be writable or a player cannot upload a
photo. That folder ships with its own `.htaccess` that strips the PHP handler
and serves image extensions only — make sure it uploaded with the rest.

---

## Step 8 — Test it

Visit `https://auction.yourdomain.com`. You should get the auction dashboard.

| Check | Expected |
|-------|----------|
| Dashboard loads | Player under the hammer, purse board, bid feed |
| Badge top-right | **MySQL** — proves the database is connected |
| `/login.php` | Sign-in form |
| Sign in as admin | Lands back on the dashboard, name shown top-right |
| `/score.php` as the scorer | Badge reads **Saving**, not "Demo" |
| `/.env` in the browser | **403 or 404** — never the file contents |

**If the database is unreachable, what you see depends on `APP_ENV`:**

- **`production`** — a bare **`Service temporarily unavailable.`** page with
  HTTP **503**. This is deliberate: a production site must not silently serve
  fabricated demo data as if it were real. The actual reason (wrong password,
  wrong database name) is written to `storage/logs/php-error.log` and never
  shown to the visitor.
- **`local`** — the page still renders with a **"Demo data"** badge, using the
  bundled fixtures.

So on a live cPanel deployment, **a 503 almost always means the `DB_*` values
in `.env` are wrong.** Open `storage/logs/php-error.log` — the `SQLSTATE`
code on the last line tells you which one.

Seeded logins (only if you imported `seed.sql`) — all with password
`Passw0rd!`:

| Role | Email |
|------|-------|
| Admin | `admin@cricauction.test` |
| Scorer | `scorer@cricauction.test` |
| Team owner | `owner.ts@cricauction.test` (also `.rc`, `.ck`, `.df`) |
| Viewer | `viewer@cricauction.test` |

---

## Step 9 — Lock it down before real use

**Do this before anyone else has the URL.**

1. **Delete the demo accounts and set real passwords.** The seeded password is
   published in this repository. In phpMyAdmin:

   ```sql
   DELETE FROM users WHERE email LIKE '%@cricauction.test';
   ```

   Then create your real admin. PHP cannot hash from phpMyAdmin, so generate
   the hash once — put this in a temporary file under `public/`, load it,
   copy the output, then **delete the file**:

   ```php
   <?php echo password_hash('your-real-password', PASSWORD_BCRYPT, ['cost' => 12]);
   ```

   ```sql
   INSERT INTO users (name, email, password_hash, role)
   VALUES ('Your Name', 'you@yourdomain.com', '<paste the hash>', 'admin');
   ```

2. **Remove the fixtures from the server** — `database/seed.sql`,
   `database/seed_match.sql`, `deploy/out/`, and `tests/` if you uploaded them.

3. **Confirm `APP_DEBUG=false` and `APP_ENV=production`.**

4. **Set up backups** — cPanel → Backup Wizard, or at minimum a weekly
   phpMyAdmin export. The auction is irreplaceable once it has been run.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Blank white page | PHP < 8.1, or a fatal error with display off | MultiPHP Manager → 8.2; read `storage/logs/php-error.log` |
| **500 Internal Server Error** | Usually permissions, or an `.htaccess` directive the host disallows | Files 644 / dirs 755; check cPanel → Metrics → **Errors** |
| **503 "Service temporarily unavailable."** | The database is unreachable — the usual first-deploy failure | Check `DB_*` in `.env`, then read the `SQLSTATE` code in `storage/logs/php-error.log` |
| Badge says "Demo data" | Same cause, but `APP_ENV=local` so it fell back to fixtures | Fix `DB_*`; set `APP_ENV=production` for a live site |
| `SQLSTATE[HY000] [1045]` | Wrong DB user or password | Reset it in MySQL® Databases; re-add the user to the database |
| `SQLSTATE[HY000] [1049]` | Wrong database name | Must be the full `deamco_APL`, prefix included and case-exact |
| `SQLSTATE[HY000] [2002]` | Wrong host | Use `DB_HOST=localhost`, not `127.0.0.1` |
| Login loops back to the form | `Secure` cookie without HTTPS | Finish Step 6 |
| Login works, then 404 | `APP_URL` wrong | Match it to the real URL, no trailing slash |
| Import: *"Access denied … CREATE DATABASE"* | Didn't strip the header | Step 2 |
| Import: *errno 150 / foreign key* | Wrong import order | Drop all tables, re-import schema → seed → seed_match |
| `CHECK` errors on import | MySQL too old, or partially imported | Step 0 |
| Bids/balls don't save, page looks fine | Session or CSRF failure | Confirm HTTPS, and that `session.save_path` is writable |
| 403 on every page | Document root points at the project root, not `public/` | Step 4 |
| Unstyled page + **"Refused to load … violates Content-Security-Policy"** in the console | A parent site's `.htaccess`, or a server-level policy, is cascading a stricter CSP into this directory | `public/.htaccess` already unsets the inherited policy and sets its own. Make sure that file was uploaded and that `mod_headers` is enabled |

**Where the errors are:** `storage/logs/php-error.log` (the app's own log) and
**cPanel → Metrics → Errors** (Apache's). Between them they explain nearly
every failure above.

---

## Updating later

**With Git Version Control:** Manage → Update from Remote → Deploy HEAD Commit.

**Manually:** upload the changed files. Never overwrite `.env`.

If a release changes the schema, back up the database first, then apply the
migration from `database/migrations/` in numerical order. There is no
migration runner yet — they are hand-applied through phpMyAdmin. Each one is
additive and safe to run on a database that already holds a season; none of
them drops anything.

---

## What is not covered

Being straight about the gaps, since a deployment guide that overstates
readiness is worse than none:

- **No migration system.** Schema changes are manual SQL.
- **No rate limiting on login.** Brute-force protection relies on your host's
  (cPanel usually runs cPHulk — check Security → cPHulk Brute Force
  Protection is on).
- **The live auction and scorer poll over plain HTTP requests**, one every
  three seconds per open dashboard. Fine for a club tournament; if you expect
  dozens of simultaneous viewers, ask your host about the account's entry
  process limit before auction day.
- **Second-innings targets and match results are not implemented yet**, so a
  match can be scored through the first innings only.
