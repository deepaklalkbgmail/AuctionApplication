# The role guides

Five PDFs, one per role, in `docs/guides/`:

| Guide | Pages | For |
|-------|-------|-----|
| `CricAuction-Player-Guide.pdf` | 16 | Anyone who wants to be auctioned |
| `CricAuction-Team-Owner-Guide.pdf` | 16 | The one owner of each franchise |
| `CricAuction-Scorer-Guide.pdf` | 18 | Whoever is at the ground with a phone |
| `CricAuction-Viewer-Guide.pdf` | 9 | Spectators — no account needed |
| `CricAuction-Administrator-Guide.pdf` | 22 | The tournament director |

Each one is self-contained: give a player the player guide and nothing else,
and they have everything they need. Screenshots are embedded in the file, so
there is nothing to attach alongside it.

---

## Changing the wording

`docs/guide-content.mjs` holds all five guides as content, apart from the
layout. The company name is the constant `COMPANY` at the top of that file —
one line, and it changes on every cover and every page footer of all five
PDFs.

Then rebuild:

```bash
node docs/build-guides.mjs
```

That needs no database and no web server. It takes a few seconds.

---

## Changing the logo

The mark on the covers is `docs/brand/deam-logo.svg`, which is a
**reconstruction** of the supplied artwork, not the master file.

To use the real one, drop it in as **`docs/brand/deam-logo.png`** — the
build prefers the PNG whenever it exists — and rebuild. A square image of
about 600×600 or larger is ideal; it is printed at 34 mm.

---

## Re-taking the screenshots

Only needed when a screen itself changes. The pictures are real captures of
the running application, not mock-ups, which is the point of them.

```bash
# 1. A database with the demonstration season, plus something in each queue
mysql -u root cric_auction < database/schema.sql
mysql -u root cric_auction < database/reset.sql
mysql -u root cric_auction < database/demo_apl.sql
php docs/seed-guide-data.php          # pending registration + applications

# 2. The application
php -S 127.0.0.1:8088 -t public &

# 3. Capture, then rebuild
node docs/capture-screens.mjs
node docs/build-guides.mjs
```

`capture-screens.mjs` signs in as each role in turn and photographs the
screen that role actually sees. It also clicks through the opening-batters
dialogue and records four balls, so the scorer's pad is pictured as a
working keypad rather than as a modal sitting on top of one.

Two environment variables, if the defaults do not suit:

| Variable | Default | |
|---|---|---|
| `GUIDE_BASE_URL` | `http://127.0.0.1:8088` | Where the application is running |
| `GUIDE_PASSWORD` | `Guide2026x` | The password every capture account uses |
| `GUIDE_VERSION` | `1.0` | Printed on each cover |

---

## What this needs installed

* **Node** with `playwright` — supplies the headless Chromium that both
  captures the screenshots and prints the PDFs. Nothing else: no LaTeX, no
  wkhtmltopdf, no Word.
* **PHP and MySQL** — only for re-taking screenshots, not for rebuilding the
  PDFs from existing ones.

The Chromium path is set at the top of both scripts. On a machine where
Playwright manages its own browsers, delete the `executablePath` option and
it will find one itself.
