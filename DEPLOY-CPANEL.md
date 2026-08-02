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

1. **Create New Database** — name it `cricauction`. cPanel prefixes it, so you
   end up with something like `cpuser_cricauction`. **Write the full name
   down**, prefix included.
2. **Add New User** — e.g. `cricapp`, becoming `cpuser_cricapp`. Use the
   password generator and save it somewhere safe.
3. **Add User To Database** — grant **ALL PRIVILEGES**.

> The app itself only needs `SELECT, INSERT, UPDATE, DELETE`. But the import in
> Step 2 needs `CREATE`, `INDEX` and `REFERENCES`. Grant ALL for the import,
> and if you want to tighten it afterwards, revoke the DDL rights once the
> tables exist.

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

Then **phpMyAdmin → select `cpuser_cricauction` in the left sidebar → Import**,
and import **in this order**:

| # | File | Required? |
|---|------|-----------|
| 1 | `schema.sql` | Yes — 10 tables and 3 views |
| 2 | `seed.sql` | Optional — demo tournament, teams, players |
| 3 | `seed_match.sql` | Optional — a live match for the scorer pad |

Order matters: the foreign keys depend on it.

Confirm afterwards — the sidebar should show **13 objects** (10 tables +
3 views). If `users` is missing, your MySQL rejected a constraint; check the
version in Step 0.

> **Production:** skip files 2 and 3. They create demo accounts with the
> published password `Passw0rd!`. If you do import them to try things out,
> Step 9 tells you how to clear them.

---

## Step 3 — Upload the code

Pick whichever you're comfortable with.

### Option A — cPanel Git™ Version Control (best for updating later)

1. **cPanel → Files → Git™ Version Control → Create.**
2. Tick **Clone a Repository**, paste the HTTPS or SSH URL, set
   **Repository Path** to `/home/CPUSER/cricauction`.
3. Edit `.cpanel.yml` in the repo — replace `CPUSER` with your cPanel username
   — commit and push.
4. Back in cPanel: **Manage → Pull or Deploy → Update from Remote**, then
   **Deploy HEAD Commit**.

Later updates are then two clicks.

### Option B — Zip upload (simplest)

1. Download the repo as a ZIP from GitHub.
2. **cPanel → File Manager**, navigate to `/home/CPUSER` (**not**
   `public_html`), **Upload**, then **Extract**.
3. Rename the extracted folder to `cricauction`.

### Option C — SFTP

Upload the whole project to `/home/CPUSER/cricauction`.

### Where it goes, and why

```
/home/CPUSER/
├── cricauction/          ← the whole app lives here, OUTSIDE public_html
│   ├── app/  config/  database/  storage/
│   ├── .env              ← created in Step 5
│   └── public/           ← only THIS is web-facing
└── public_html/
```

Keeping the app above `public_html` means `.env`, your database password and
every PHP source file are physically unreachable over HTTP, no configuration
required. It is the single most valuable thing you can do for security here.

**Do not upload:** `tests/`, `deploy/`, `.git/`, `node_modules/`. They are
harmless but pointless on a production server.

---

## Step 4 — Point the domain at `public/`

The web server must serve **`cricauction/public`**, not the project root.

### Recommended: a subdomain

**cPanel → Domains → Create A New Domain.**

- Domain: `auction.yourdomain.com`
- Untick *"Share document root"*
- **Document Root:** `/home/CPUSER/cricauction/public`

That's it — this is the layout the app was designed for.

### Existing domain

**cPanel → Domains**, find the domain, click the pencil / **Manage** beside
its document root, and change it to `/home/CPUSER/cricauction/public`.

### If your host won't let you change the document root

Then the app has to sit inside `public_html`. Put the whole project at
`public_html/cricauction/` and reach it at
`yourdomain.com/cricauction/public/`.

This is why the repo ships **two `.htaccess` files**: the one at the project
root denies everything, and `public/.htaccess` re-grants only that directory.
I tested exactly this layout — `/config/db.php`, `/.env`, `/database/seed.sql`,
`/app/Core/Auth.php` and the logs all return **403**, while `public/index.php`
serves normally.

It works, but it depends on Apache honouring `.htaccess` (`AllowOverride All`,
which cPanel sets by default). One misconfiguration and your database password
is a public URL. Use a subdomain if you possibly can.

---

## Step 5 — Create `.env`

**File Manager → `/home/CPUSER/cricauction`**, copy `.env.example` to `.env`
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
DB_NAME=cpuser_cricauction
DB_USER=cpuser_cricapp
DB_PASS=the-password-from-step-1
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
chmod 600 .env                 # owner-only; nothing else needs to read it
```

**Never use 777.** On shared hosting that makes your files writable by other
accounts on the same server, and many hosts refuse to execute PHP in a
world-writable directory anyway.

`storage/logs/` must be writable or PHP cannot write its error log.

---

## Step 8 — Test it

Visit `https://auction.yourdomain.com`. You should get the auction dashboard.

| Check | Expected |
|-------|----------|
| Dashboard loads | Player under the hammer, purse board, bid feed |
| Badge top-right | **MySQL** (not "Demo data") — proves the DB is connected |
| `/login.php` | Sign-in form |
| Sign in as admin | Lands back on the dashboard, name shown top-right |
| `/score.php` as the scorer | Badge reads **Saving**, not "Demo" |
| `/.env` in the browser | **403 or 404** — never the file contents |

A **"Demo data"** badge with everything otherwise working means PHP could not
reach MySQL and fell back to the bundled fixtures. Check `DB_*` in `.env` and
look at `storage/logs/php-error.log`.

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
| Badge says "Demo data" | PHP cannot reach MySQL | `DB_HOST=localhost`, verify the **prefixed** DB name and that the user is attached to the database |
| `SQLSTATE[HY000] [1045]` | Wrong DB user or password | Reset it in MySQL® Databases; re-add the user to the database |
| `SQLSTATE[HY000] [1049]` | Wrong database name | It must include the `cpuser_` prefix |
| Login loops back to the form | `Secure` cookie without HTTPS | Finish Step 6 |
| Login works, then 404 | `APP_URL` wrong | Match it to the real URL, no trailing slash |
| Import: *"Access denied … CREATE DATABASE"* | Didn't strip the header | Step 2 |
| Import: *errno 150 / foreign key* | Wrong import order | Drop all tables, re-import schema → seed → seed_match |
| `CHECK` errors on import | MySQL too old, or partially imported | Step 0 |
| Bids/balls don't save, page looks fine | Session or CSRF failure | Confirm HTTPS, and that `session.save_path` is writable |
| 403 on every page | Document root points at the project root, not `public/` | Step 4 |

**Where the errors are:** `storage/logs/php-error.log` (the app's own log) and
**cPanel → Metrics → Errors** (Apache's). Between them they explain nearly
every failure above.

---

## Updating later

**With Git Version Control:** Manage → Update from Remote → Deploy HEAD Commit.

**Manually:** upload the changed files. Never overwrite `.env`.

If a release changes the schema, back up the database first, then apply the
migration. There is no migration runner yet — schema changes are hand-applied
SQL for now.

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
