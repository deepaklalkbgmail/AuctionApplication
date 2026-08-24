# CricAuction — live test plan

A module-by-module walkthrough for testing the application on
`https://deam.co.in/APL` from an empty database.

Every step below was run against a clean install before this was written, so
the button labels and the messages in the "Should happen" column are the real
ones. If you see something different, that is worth reporting.

**Time:** about 45 minutes for Modules 1–9. Module 10 (scoring) needs a match
set up in the database and adds 20 minutes.

---

## Before you start

### What you need open

| | |
|---|---|
| Two browsers | Or one normal window and one private window. Several modules need you signed in as two people at once |
| phpMyAdmin | For the reset in Module 0, and nothing else |
| This document | Tick as you go |

### Write your test accounts here as you create them

| Role | Username | Password | Made in |
|------|----------|----------|---------|
| Administrator | `admin` | | Module 0 |
| Player 1 | | | Module 3 |
| Player 2 (becomes an owner) | | | Module 6 |
| Player 3 (becomes an owner) | | | Module 6 |
| Player 4 | | | Module 3 |
| Scorer | | | Module 9 |

**Secret code:** ________________ *(from Module 2)*

> Use a real email address you can reach for at least one player. Nothing is
> emailed by the application, but it keeps the test honest.

---

## Module 0 — Clean slate

**Goal:** an empty database with one administrator, and nothing else.

> **Take a backup first.** phpMyAdmin → `deamco_APL` → **Export** → **Go**.
> Module 0 deletes every player, team, user, bid, match and ball. It cannot
> be undone.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 0.1 | phpMyAdmin → click **`deamco_APL` in the left sidebar** → **Export** → **Go** | A `.sql` file downloads. Keep it | ☐ |
| 0.2 | With `deamco_APL` still selected, open the **SQL** tab. Do **not** open SQL from the server level — nothing works keyed on a database that is not selected | The SQL box appears with the database name shown above it | ☐ |
| 0.3 | Import `database/reset.sql`. Use **Import → Choose File** rather than pasting | "Your SQL query has been executed successfully" | ☐ |
| 0.4 | Run: `SELECT COUNT(*) FROM users;` | `1` | ☐ |
| 0.5 | Run: `SELECT COUNT(*) FROM tournaments;` and the same for `teams`, `players`, `auction_lots` | `0` every time | ☐ |
| 0.6 | Open `https://deam.co.in/APL/` in a signed-out browser | The landing page, five role cards, a **Register** button | ☐ |
| 0.7 | Open `https://deam.co.in/APL/auction.php` | **"No auction is running"** — correct for an empty database, not a fault | ☐ |

> **Do not run `schema.sql`.** It drops every table and it contains
> `USE cric_auction`, so it will not even go into the database you think it
> is going into. `reset.sql` is the one that empties without destroying.

---

## Module 1 — The administrator account

**Goal:** the published password cannot survive, and the admin identity is
yours.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 1.1 | Go to **Sign in**. Enter `admin` / `ChangeMe@2026` | You land on **Change password**, not on the hub | ☐ |
| 1.2 | Try to navigate to `admin/index.php` directly | You are sent straight back to Change password | ☐ |
| 1.3 | Current password `ChangeMe@2026`, new password `Testing2026` twice → **Change password** | "Password changed" | ☐ |
| 1.4 | Press **Continue** | The administration hub, both queues showing **0** | ☐ |
| 1.5 | **People** → **Edit details** on your own row. Set your real name and a real email → **Save details** | "Details saved." | ☐ |
| 1.6 | Sign out, then sign in with your **new email address** instead of `admin` | Signs in. Username *or* email both work | ☐ |

**Negative checks**

| # | Try this | Should be refused with | ✓ |
|---|----------|------------------------|---|
| 1.7 | Sign in with the old `ChangeMe@2026` | "Those credentials do not match our records." | ☐ |
| 1.8 | Sign in as `admin` with a wrong password | The same message — it must not reveal that the account exists | ☐ |
| 1.9 | Change password using a wrong current password | "Your current password is not correct." | ☐ |
| 1.10 | Change password to `abc` | "Use at least 8 characters." | ☐ |
| 1.11 | Change password to `cricketing` (no digit) | "Use at least one letter and one number." | ☐ |

---

## Module 2 — Creating the tournament

**Goal:** a season with four dates and a secret code.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 2.1 | **Administration → Tournaments** | "No tournament yet" and the create form | ☐ |
| 2.2 | Fill in: name `APL Test`, season `2026`, **auction date** 3 days from today, **start date** 14 days, **end date** 45 days, **team name change deadline** 10 days | | ☐ |
| 2.3 | Set purse per team `5000000`, bid increment `50000`, **minimum squad `3`**, **maximum squad `6`**, overseas `2` | Small squad sizes keep the test short | ☐ |
| 2.4 | **Create tournament** | "Created "APL Test". Its code is XXXXXXXX — give that to the players." | ☐ |
| 2.5 | **Write the code in the box at the top of this document** | 8 characters | ☐ |
| 2.6 | Check the code | No `0`, `O`, `o`, `1`, `I`, `l` or `i` anywhere in it | ☐ |
| 2.7 | Read the card | Four dates shown, status `draft`, **entries open** | ☐ |

**Negative checks**

| # | Try this | Should be refused with | ✓ |
|---|----------|------------------------|---|
| 2.8 | Create another tournament called `APL Test` for 2026 | "A tournament called "APL Test" already exists for 2026." | ☐ |
| 2.9 | Create one with an end date **before** the start date | "The end date cannot be before the start date." | ☐ |
| 2.10 | Create one with an auction date **after** the start date | "The auction date must be on or before the start date." | ☐ |
| 2.11 | Create one with date `2026-02-31` | "…must be a real date in the form YYYY-MM-DD." | ☐ |
| 2.12 | Create one leaving purse, increment and squad sizes **blank** | Accepted, with the defaults filled in — blank means "use the default" | ☐ |

> Delete any extra tournaments you created here before moving on, or just
> ignore them — every later screen lets you pick which tournament you are
> working in.

---

## Module 3 — Player registration

**Goal:** players register themselves, and the two permanent fields are
stated plainly before anything is saved.

Use the **second browser** for this module. You should not be signed in as
the administrator.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 3.1 | Landing page → **Register as a player** | The form, with an amber **"Before you start"** box saying name and email are permanent | ☐ |
| 3.2 | Check the two fields | "Full name" and "Email address" each carry a *Permanent* note underneath | ☐ |
| 3.3 | Fill it in for **Player 1**. Note the username and password in the table above | | ☐ |
| 3.4 | **Continue** | **"Check these before you continue"** — nothing saved yet | ☐ |
| 3.5 | Read that screen | Name and email are marked **PERMANENT** in amber; there is a **Go back and edit** link | ☐ |
| 3.6 | Press **Go back and edit** | The form comes back with everything you typed still in it | ☐ |
| 3.7 | **Continue** → optionally attach a photo → **Confirm and register** | **"Registration received"** | ☐ |
| 3.8 | Try to sign in as Player 1 now | "Your registration is still waiting for an administrator to approve it." | ☐ |

**Negative checks** — each should refuse before anything is created

| # | Try this | Should be refused with | ✓ |
|---|----------|------------------------|---|
| 3.9 | Register again with the **same email** | "That email address is already registered." | ☐ |
| 3.10 | Register with the **same username**, different email | "That username is taken." | ☐ |
| 3.11 | Username `my name` (with a space) | "A username is 3 to 40 characters: letters, numbers, dot, underscore or hyphen." | ☐ |
| 3.12 | Mobile `12345` | "Enter a mobile number of 7 to 15 digits." | ☐ |
| 3.13 | Two different passwords | "The two passwords do not match." | ☐ |
| 3.14 | Leave **Kind of player** unchosen | "Choose what kind of player you are." | ☐ |
| 3.15 | Upload a `.pdf` as the photo | "The photo must be a JPEG, PNG or WebP image." | ☐ |

**Now register three more players** (3.3 → 3.7 each): Player 2, Player 3,
Player 4. Two of them become team owners in Module 6.

---

## Module 4 — Approving registrations

**Goal:** nobody reaches an auction without a named administrator letting
them in.

Back to the **administrator browser**.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 4.1 | Administration hub | **Registrations to approve: 4** in amber | ☐ |
| 4.2 | Click that panel | **People → Waiting**, four entries, each showing name, email, mobile, address, kind of player and photo | ☐ |
| 4.3 | **Approve** Player 1 | "Registration approved." and they leave the Waiting list | ☐ |
| 4.4 | Approve Players 2, 3 and 4 | The queue empties, hub shows **0** | ☐ |
| 4.5 | In the player browser, sign in as Player 1 | Signs in, lands on **My details** | ☐ |

**Also check**

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 4.6 | People → **Edit details** on Player 1. Change the **name** and **email** → Save | "Details saved." Only an administrator can do this | ☐ |
| 4.7 | Set Player 4's **Account status** to **Suspended** → Save | Saved | ☐ |
| 4.8 | Try to sign in as Player 4 | "This account has been suspended. Please contact the organisers." | ☐ |
| 4.9 | Set Player 4 back to **Approved** | They can sign in again | ☐ |

---

## Module 5 — Joining the tournament

**Goal:** the secret code is the only way in, and applying is not the same
as being in.

In the **player browser**, signed in as Player 1.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 5.1 | **Join a tournament** | A large code box | ☐ |
| 5.2 | Type the code **in lower case, with a space in the middle** | Accepted — "Applied to APL Test" | ☐ |
| 5.3 | **My details** | The tournament listed with status **pending** | ☐ |
| 5.4 | Administrator browser → hub | **Tournament applications: 1** | ☐ |
| 5.5 | Sign in as Players 2, 3 and 4 in turn and apply with the same code | Four applications waiting | ☐ |

**Negative checks**

| # | Try this | Should be refused with | ✓ |
|---|----------|------------------------|---|
| 5.6 | Apply with code `ZZZZZZZZ` | "That code does not match any tournament…" | ☐ |
| 5.7 | Apply a second time with the right code | "You have already applied. An administrator will review it." | ☐ |
| 5.8 | Admin: Tournaments → **Close entries**. Then have a player apply | "Registration for APL Test is closed." | ☐ |
| 5.9 | Admin: **Open entries** again | Applications work again | ☐ |
| 5.10 | Admin: Tournaments → **Issue a new code**. Try the **old** code as a player | Refused. The new code works | ☐ |

> If you do 5.10, update the code in the box at the top of this document.

---

## Module 6 — Letting players into the auction

**Goal:** approving an application is what creates the player and their lot.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 6.1 | Before approving anything, run in phpMyAdmin: `SELECT COUNT(*) FROM players;` | `0` — applying created nothing | ☐ |
| 6.2 | **Administration → Applications** | Four applicants, each with **Base price**, **Auction set**, **Note** and an **Overseas** tick box | ☐ |
| 6.3 | Approve Player 1 with base price `200000`, auction set `Set A` | "Player 1 is approved and is now in the auction list." | ☐ |
| 6.4 | Run `SELECT COUNT(*) FROM players;` and `SELECT COUNT(*) FROM auction_lots;` | `1` and `1` — both created by that one press | ☐ |
| 6.5 | Approve the other three | Four players, four lots | ☐ |
| 6.6 | Try to approve Player 1 again (use the **All** tab) | "That application has already been approved." | ☐ |

**Rejection and re-applying** — optional, do it with Player 4 if you want the
full picture

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 6.7 | Reject an application with note `Unverified` | "…application was rejected." No player row created | ☐ |
| 6.8 | That player applies again with the code | Accepted — a rejected player may re-apply | ☐ |
| 6.9 | Approve them | Now in the auction list | ☐ |

---

## Module 7 — Teams and owners

**Goal:** one team, one owner, enforced by the database.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 7.1 | **Administration → Teams** | "No teams in this tournament yet" and the create form | ☐ |
| 7.2 | Create a team: owner **Player 2**, name `Team One`, short name `T1` | "Team One created. Its owner can rename it until the deadline." | ☐ |
| 7.3 | Read the card | Owner named, **Purse left ₹50,00,000** — the tournament's purse | ☐ |
| 7.4 | Create a second team: owner **Player 3**, name `Team Two`, short `T2` | Created | ☐ |
| 7.5 | In the player browser, sign in as **Player 2** | Signs in. The top bar now shows **My team** | ☐ |

**Negative checks**

| # | Try this | Should be refused with | ✓ |
|---|----------|------------------------|---|
| 7.6 | Create a third team named `Team One` | "Another team in this tournament is already called "Team One"." | ☐ |
| 7.7 | Create one with short name `T1` | "Another team is already using the short name T1." | ☐ |
| 7.8 | Create one with short name `TOO LONG` | "The short name is 2 to 6 letters or digits, like MI or CSK." | ☐ |
| 7.9 | Check the **Owner** dropdown | Player 2 and Player 3 no longer appear — they already hold a team | ☐ |

---

## Module 8 — The owner names their team

**Goal:** the owner controls the name, until the deadline.

In the **player browser**, signed in as Player 2.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 8.1 | **My team** | The team, and a line reading **"You can change the name until <date>. After that only an administrator can."** | ☐ |
| 8.2 | Rename it to `Backwater Blasters`, short name `BWB`, pick a colour → **Save** | "Your team has been updated." | ☐ |
| 8.3 | Check the purse panel | **₹50,00,000** left of ₹50,00,000, 0 players bought | ☐ |
| 8.4 | Try renaming it to `Team Two` | "Another team in this tournament is already called "Team Two"." | ☐ |

**Testing the deadline** — this needs one SQL statement, because the deadline
is a date

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 8.5 | phpMyAdmin: `UPDATE tournaments SET team_name_change_deadline = DATE_SUB(CURDATE(), INTERVAL 1 DAY);` | 1 row changed | ☐ |
| 8.6 | Owner: reload **My team** | Amber box: "The name change deadline … has passed". Name and short name greyed out | ☐ |
| 8.7 | Owner: try to save a new name anyway | "Team names were locked on <date>…" | ☐ |
| 8.8 | Admin: **Teams** → rename that team → **Save** | Works. An administrator is not bound by the deadline | ☐ |
| 8.9 | Put the deadline back: `UPDATE tournaments SET team_name_change_deadline = DATE_ADD(CURDATE(), INTERVAL 10 DAY);` | The owner can rename again | ☐ |

---

## Module 9 — The auction

**Goal:** the auction is called in the room; the application records it.

This is the module worth taking slowly. Sign in as the administrator.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 9.1 | **Administration → Auction** | **Purse board** at the top with both teams, then **Still to call (4)** | ☐ |
| 9.2 | Read the purse board | Each team ₹50,00,000, 0 bought, an empty spend bar | ☐ |
| 9.3 | Read a player row | Lot number, name, kind of player, base price, auction set | ☐ |
| 9.4 | Open the **Sold to** dropdown | Each team shows what it has left, e.g. "Backwater Blasters (₹50,00,000 left)" | ☐ |
| 9.5 | Record: first player → Backwater Blasters → `460000` → **Sold** | "SOLD — <name> to Backwater Blasters for ₹4,60,000." | ☐ |
| 9.6 | Check the purse board | Backwater Blasters now **₹45,40,000**, 1 bought, bar moved | ☐ |
| 9.7 | Look at the **Sold** table | The sale listed with an **Undo** beside it | ☐ |
| 9.8 | Record a second player to the other team at `1200000` | Both purses updated | ☐ |

**A price off the increment ladder is deliberate** — ₹4,60,000 against a
₹50,000 step. It must be accepted, because the room calls what it calls.

**Negative checks**

| # | Try this | Should be refused with | ✓ |
|---|----------|------------------------|---|
| 9.9 | Sell a player for `1000` (under the ₹2,00,000 base) | "The price cannot be below the base price of ₹2,00,000." | ☐ |
| 9.10 | Sell a player for `99000000` | "<Team> only has ₹45,40,000 left, so it cannot pay ₹9,90,00,000." | ☐ |
| 9.11 | Submit with no team chosen | The browser will not let you submit | ☐ |

**Undo**

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 9.12 | Press **Undo** on the first sale | "Undone — <name> is back in the pool and ₹4,60,000 returned to Backwater Blasters." | ☐ |
| 9.13 | Check the purse board | Back to ₹50,00,000, 0 bought | ☐ |
| 9.14 | Check **Still to call** | The player is back in the list | ☐ |
| 9.15 | Record them again at a different price | Works | ☐ |

**Unsold and re-listing**

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 9.16 | Press **Unsold** on a player | "<name> passed over — unsold." They appear under **Passed over** | ☐ |
| 9.17 | Click their name under Passed over | "Back in the queue." They return to **Still to call** | ☐ |

**Squad cap** — the maximum squad is 6, so this needs a small tournament

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 9.18 | Sell players to one team until it reaches 6 | The seventh is refused: "…already has a full squad of 6." | ☐ |

**Search**

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 9.19 | Type part of a player's name in **Find a player** → Search | Only matching players shown, in all three sections | ☐ |
| 9.20 | **Clear** | Everybody back | ☐ |

---

## Module 10 — The viewer's board

**Goal:** anyone can follow the auction without an account.

Use a **third browser or a private window** — signed out entirely.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 10.1 | Go to `https://deam.co.in/APL/auction.php` | **Auction board** — no sign-in needed | ☐ |
| 10.2 | Read the header line | "N sold · ₹X spent · M still to call" | ☐ |
| 10.3 | Check the purse board | Every team, remaining purse, spend bar, players bought | ☐ |
| 10.4 | Check **Sold** | Every sale: player, team, price | ☐ |
| 10.5 | Check **Still to call** | The remaining players with their base prices | ☐ |
| 10.6 | Record another sale as the administrator, then reload this page | The new sale appears | ☐ |
| 10.7 | Look for controls | There are none. A viewer can change nothing | ☐ |
| 10.8 | Open the same page on a phone | Readable, no sideways scrolling | ☐ |

---

## Module 11 — The scorer

**Goal:** a scorer account is issued, not registered, and its password
cannot stay as issued.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 11.1 | Admin → **People** → scroll to **Create a scorer or administrator** | The form | ☐ |
| 11.2 | Name, username `test.scorer`, an email, role **Scorer**, **leave the password blank** → **Create account** | A green panel: "Account created — read this out now" | ☐ |
| 11.3 | Write down the username and password shown | Something like `AW93-39FR` | ☐ |
| 11.4 | Check the password | No `0`, `O`, `1`, `I` or `l`, and it contains at least one digit | ☐ |
| 11.5 | Navigate away and come back | The password is **not** shown again — it is not stored readably | ☐ |
| 11.6 | Sign in as the scorer in another browser | Lands on **Change password**, "Choose your own password" | ☐ |
| 11.7 | Change it | "Password changed" | ☐ |
| 11.8 | Go to **score.php** | **"No match is being scored"** — correct, no match exists yet | ☐ |
| 11.9 | Admin → People → **Reset password** on the scorer | A new password shown once; the scorer must change it again | ☐ |

### Optional: scoring an actual match

Fixtures are not on a screen yet — this part is SQL. Skip it unless you want
to test the scoring pad.

First find your ids:

```sql
SELECT id, name FROM teams;
SELECT id, full_name, team_id FROM players WHERE team_id IS NOT NULL ORDER BY team_id;
SELECT id, username FROM users WHERE role = 'scorer';
```

Then, substituting your own ids:

```sql
INSERT INTO matches
  (tournament_id, match_number, stage, team_a_id, team_b_id, venue,
   scheduled_at, overs_per_innings, toss_winner_team_id, toss_decision,
   status, scorer_user_id)
VALUES (1, 1, 'league', 1, 2, 'Test Ground', NOW(), 20, 1, 'bat', 'live', 5);

INSERT INTO match_squads (match_id, team_id, player_id, batting_order, is_playing_xi)
VALUES (1, 1, 1, 1, 1), (1, 1, 2, 2, 1),
       (1, 2, 3, 1, 1), (1, 2, 4, 2, 1);

INSERT INTO innings (match_id, innings_number, batting_team_id, bowling_team_id, started_at)
VALUES (1, 1, 1, 2, NOW());
```

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 11.10 | Scorer opens **score.php** | The pad. A dialogue asks for the opening batters | ☐ |
| 11.11 | Choose two batters and a bowler | The keypad appears | ☐ |
| 11.12 | Check the badge in the header | **SAVING** — not *Demo*. If it says Demo, nothing is being recorded | ☐ |
| 11.13 | Press `1` | Score 1/0, strike swaps to the other batter | ☐ |
| 11.14 | Press `4` | Score 5/0, striker's fours count rises | ☐ |
| 11.15 | Press **WD** | One run added, **the ball count does not move** | ☐ |
| 11.16 | Press six legal balls | Over ends, strike swaps, the next-bowler dialogue appears | ☐ |
| 11.17 | Press **UNDO** | The last ball is removed completely — runs, strike and ball count | ☐ |
| 11.18 | Open score.php in the signed-out browser | The same score, read-only, badge not *Saving* | ☐ |

---

## Module 12 — Signing out

**Goal:** signing out actually signs you out, from every screen.

Do this for **each role** you have: administrator, player, team owner,
scorer.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 12.1 | Signed in, press **Sign out** in the top bar | The sign-in page with **"You have been signed out."** | ☐ |
| 12.2 | Press the browser **Back** button | You are not signed back in | ☐ |
| 12.3 | Type a gated address directly, e.g. `admin/index.php` | Sent to the sign-in page | ☐ |
| 12.4 | Repeat 12.1–12.3 as a player (`profile.php`), an owner (`team.php`), a scorer (`score.php`) | Same behaviour each time | ☐ |

---

## Module 13 — Security spot-checks

Quick, and worth doing once.

| # | Do this | Should happen | ✓ |
|---|---------|---------------|---|
| 13.1 | Signed in as a **player**, type `admin/index.php` in the address bar | **403 — You do not have permission to perform this action** | ☐ |
| 13.2 | Signed in as a **team owner**, try `admin/auction.php` | 403 | ☐ |
| 13.3 | Try `https://deam.co.in/APL/.env` | Forbidden or Not Found — never the file contents | ☐ |
| 13.4 | Try `https://deam.co.in/APL/config/db.php` | Forbidden or Not Found | ☐ |
| 13.5 | Try `https://deam.co.in/APL/database/schema.sql` | Forbidden or Not Found | ☐ |
| 13.6 | Leave a form open for an hour, then submit it | "Your session expired. Please try again." — not a crash | ☐ |
| 13.7 | Check every page is `https://`, not `http://` | The padlock, everywhere | ☐ |
| 13.8 | As a player, edit **My details** and confirm name/email are greyed out | They cannot be changed by the player | ☐ |

---

## Module 14 — Before you go live for real

Once testing is finished and you are happy.

| # | Do this | ✓ |
|---|---------|---|
| 14.1 | Export a backup of the test database, if you want to keep the evidence | ☐ |
| 14.2 | Run `database/reset.sql` again — this deletes all of your test data | ☐ |
| 14.3 | Sign in as `admin` / `ChangeMe@2026`, change the password, set your real name and email | ☐ |
| 14.4 | Create the real tournament, with the real dates | ☐ |
| 14.5 | Give the real secret code to the real players | ☐ |
| 14.6 | Create the real teams and name their owners | ☐ |
| 14.7 | Create the scorer accounts and hand over the credentials | ☐ |

---

## What to report, and how

For anything that does not match the "Should happen" column, note:

1. **Which step number** — e.g. 9.10
2. **What you did** — exact values you typed
3. **What you expected** and **what happened instead**
4. **A photograph or screenshot** of the screen, including any message
5. **Which role** you were signed in as

## Known limitations — not faults

These are missing on purpose or not built yet. Do not report them as bugs.

| | |
|---|---|
| **No bulk player import** | Every player registers themselves, or an administrator adds them in phpMyAdmin |
| **Fixtures are set up in SQL** | Creating a match and its playing elevens is not on a screen — see Module 11 |
| **Only the first innings** | The innings break, the second-innings target and the result are not implemented |
| **No email** | Approvals, rejections and issued passwords are not emailed. Somebody has to tell the person |
| **No password reset by email** | An administrator resets it and reads out the new one |
| **No live on-screen bidding** | By design — the auction is called in the room and recorded on the auction sheet |
| **No fixtures, points table or statistics screens** | The data is recorded; the pages that display it are not built |

---

*Prepared by Deam Software Solutios.*
