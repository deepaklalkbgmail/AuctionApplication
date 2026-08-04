# CricAuction — User Guide

Everything needed to run a tournament, train the people using it, and
demonstrate the application.

**Read first:** [What the application does not do yet](#12-what-the-application-does-not-do-yet).
Two of those limits change how you plan a session, so it is better to know
them before the auction than during it.

---

## Contents

1. [What this application is](#1-what-this-application-is)
2. [The four roles](#2-the-four-roles)
3. [Signing in](#3-signing-in)
4. [Administrator](#4-administrator)
5. [Team Owner](#5-team-owner)
6. [Scorer](#6-scorer)
7. [Viewer](#7-viewer)
8. [Preparing a clean application](#8-preparing-a-clean-application)
9. [Demonstration script](#9-demonstration-script)
10. [Changing a password](#10-changing-a-password)
11. [Troubleshooting](#11-troubleshooting)
12. [What the application does not do yet](#12-what-the-application-does-not-do-yet)
13. [Glossary](#13-glossary)

---

## 1. What this application is

CricAuction runs the two events that bracket a club or corporate cricket
tournament:

**The auction.** Players go under the hammer one at a time. Team owners bid
against a countdown, each team has a fixed purse, and the application refuses
any bid a team cannot afford. When the hammer falls the player joins that
squad and the money moves — in one step, so a player can never be sold
without the purse being debited.

**The scoring.** Once teams exist, matches are scored ball by ball on a phone
at the ground. The scorer records what happened — a four, a wide, a wicket —
and the application works out the rest: who is on strike, when the over ends,
which runs count against the bowler. Everyone else watches the scorecard
update.

Everything runs in a web browser. There is nothing to install.

---

## 2. The four roles

| Role | Who | Can do | Needs an account |
|------|-----|--------|------------------|
| **Viewer** | Anyone | Watch the auction board and the live scorecard | No |
| **Team Owner** | One per franchise | Bid for players, see their purse and squad | Yes |
| **Scorer** | One per match | Record every ball | Yes |
| **Administrator** | Tournament director | Run the auction — open lots, sell, pass | Yes |

A person has exactly one role. An owner is tied to exactly one team and
cannot bid for another.

---

## 3. Signing in

1. Go to the application address (for example `https://deam.co.in/APL`).
2. The landing page explains the application and shows four role cards.
   Viewers can go straight in; the other three click **Sign in**.
3. Enter your email and password.

You land directly on your own screen — scorers on the scoring pad, everyone
else on the auction board.

**If sign-in returns you to the login form**, the site is not running over
HTTPS. Session cookies are marked "secure", so the browser refuses to send
them over plain HTTP. Tell your administrator.

**"Those credentials do not match our records"** is shown for both a wrong
email and a wrong password, on purpose — it stops anyone probing for valid
addresses.

---

## 4. Administrator

### 4.1 Before the auction: loading the tournament

> **Be aware:** there is no screen for adding tournaments, teams, players or
> user accounts yet. All setup is done in **cPanel → phpMyAdmin → SQL**.
> Everything from section 4.2 onward is fully driven from the application;
> only this first-time setup is not. See
> [section 12](#12-what-the-application-does-not-do-yet).

Work through these in order — each depends on the one before.

**Step 1 — the tournament.** One row defines the rules for the whole season.

```sql
INSERT INTO tournaments
  (name, season_year, purse_per_team, min_squad_size, max_squad_size,
   max_overseas, bid_increment, bid_timer_seconds, overs_per_innings, status)
VALUES
  ('APL', 2026, 50000000.00, 11, 15, 4, 500000.00, 30, 20, 'auction');
```

| Setting | Means |
|---------|-------|
| `purse_per_team` | Money each team starts with, in rupees. 50000000 = ₹5 Cr |
| `min_squad_size` | Smallest legal squad. Drives the reserve rule in 5.4 |
| `max_squad_size` | A team at this number cannot bid again |
| `max_overseas` | Overseas players one squad may hold |
| `bid_increment` | The step between bids. 500000 = ₹5 L |
| `bid_timer_seconds` | Countdown, restarted by every bid |
| `overs_per_innings` | 20 for T20 |

**Step 2 — the teams.** One row per franchise.

```sql
INSERT INTO teams (tournament_id, name, short_name, primary_color, home_venue, purse_total)
VALUES
  (1, 'Coastal Titans', 'CT', '#22c55e', 'Marine Drive Ground', 50000000.00),
  (1, 'Metro Royals',   'MR', '#f59e0b', 'City Sports Complex', 50000000.00);
```

`short_name` is the 2–3 letter badge shown throughout. `primary_color` must
be a hex colour and becomes that team's accent.

**Step 3 — the players.** One row per player in the pool.

```sql
INSERT INTO players
  (tournament_id, full_name, display_name, country, role, batting_style,
   bowling_style, is_overseas, auction_set, base_price)
VALUES
  (1, 'Aarav Sharma', 'A Sharma', 'India', 'batsman',     'right_hand', 'none',            0, 'Marquee', 2000000.00),
  (1, 'Rohan Iyer',   'R Iyer',   'India', 'bowler',      'right_hand', 'right_arm_fast',  0, 'Set A',   1000000.00),
  (1, 'Kabir Nair',   'K Nair',   'India', 'all_rounder', 'left_hand',  'left_arm_orthodox',0,'Set A',   1000000.00);
```

`role` must be one of `batsman`, `bowler`, `all_rounder`, `wicket_keeper`.
`auction_set` is a free label used to group the pool (Marquee, Set A …).

**Step 4 — the auction order.** Every player needs a lot; `lot_order` is the
sequence they come up in. This creates them all at once, cheapest sets last:

```sql
INSERT INTO auction_lots (tournament_id, player_id, lot_order, status, base_price)
SELECT 1, id, ROW_NUMBER() OVER (ORDER BY base_price DESC, id), 'queued', base_price
FROM players WHERE tournament_id = 1;
```

**Step 5 — the accounts.** Passwords must be stored hashed, and SQL cannot
hash. Generate each one first — see [section 10](#10-changing-a-password) —
then:

```sql
INSERT INTO users (name, email, password_hash, role, team_id) VALUES
  ('Tournament Director', 'director@yourclub.in', '<hash>', 'admin',      NULL),
  ('Match Scorer',        'scorer@yourclub.in',   '<hash>', 'scorer',     NULL),
  ('Coastal Titans Owner','ct@yourclub.in',       '<hash>', 'team_owner', 1);
```

An owner **must** have a `team_id`; an admin, scorer or viewer must not. The
database refuses anything else.

**Step 6 — check it.** Open the landing page. It should show your tournament
name, your team count and your player count. If it still shows old data, you
did not clear it — see [section 8](#8-preparing-a-clean-application).

### 4.2 Running the auction

Sign in as the administrator and open the auction board. Your control rail
sits under the player card.

| Button | Does | When |
|--------|------|------|
| **Sold** | Awards the player to the leading team, debits the purse | Bidding has stopped and someone leads |
| **Unsold** | Closes the lot with no winner; player returns to the pool | Countdown expired with no bid |
| **Pause** | Freezes the countdown | A dispute, or a break |
| **Reset timer** | Puts the countdown back to full | You gave the room more time |

**The rhythm of a lot:**

1. The next player appears with base price, role and career record.
2. Owners bid. Each bid restarts the countdown, so nobody wins by clicking
   last — the room decides when bidding has finished.
3. Countdown reaches zero, or the room goes quiet.
4. Press **Sold**. The purse board updates for everyone immediately.
5. The next player comes up automatically.

**Sold is final.** There is no undo on a sale. If you press it by mistake the
correction is a database edit, so pause instead when you are unsure.

**Unsold players** stay in the pool with status `unsold`. To bring them back
for a second round, re-open their existing lot — a player has exactly one lot
for the season, so do not try to insert a second one:

```sql
-- Move them to the back of the queue and clear the previous round.
UPDATE auction_lots
   SET status = 'queued', current_bid = NULL, current_bidder_team_id = NULL,
       bid_count = 0, started_at = NULL, ends_at = NULL, closed_at = NULL,
       lot_order = lot_order + 1000
 WHERE tournament_id = 1 AND status = 'unsold';

UPDATE players
   SET status = 'available'
 WHERE tournament_id = 1 AND status = 'unsold';
```

Bids from the first round stay in the log as history; the new round starts
from the base price again.

### 4.3 Setting up a match

Once two squads exist. Again phpMyAdmin, for now.

```sql
-- 1. The fixture. toss_decision is 'bat' or 'bowl'.
INSERT INTO matches
  (tournament_id, match_number, stage, team_a_id, team_b_id, venue,
   scheduled_at, overs_per_innings, toss_winner_team_id, toss_decision, status, scorer_user_id)
VALUES
  (1, 1, 'league', 1, 2, 'Marine Drive Ground', NOW(), 20, 1, 'bat', 'live',
   (SELECT id FROM users WHERE email = 'scorer@yourclub.in'));

-- 2. The playing eleven for each side. batting_order drives "next batter in".
INSERT INTO match_squads (match_id, team_id, player_id, batting_order, is_playing_xi, is_captain, is_wicket_keeper)
VALUES (1, 1, 4, 1, 1, 1, 0),
       (1, 1, 8, 2, 1, 0, 0);
       -- ... eleven rows per team

-- 3. The first innings. batting_team_id is whoever bats first.
INSERT INTO innings (match_id, innings_number, batting_team_id, bowling_team_id, started_at)
VALUES (1, 1, 1, 2, NOW());
```

The match must be `status = 'live'` and the innings must exist, or the scorer
sees a demonstration match instead of yours.

---

## 5. Team Owner

### 5.1 Reading the screen

| Area | Shows |
|------|-------|
| **Under the hammer** | The player being auctioned — role, base price, career record |
| **Current bid** | The standing bid and which team holds it |
| **Countdown** | Seconds left. Green, then amber under 10, then red under 5 |
| **Place your bid** | Four quick amounts, and the main bid button |
| **Purse board** | Every team's remaining money and squad size. Yours is outlined |
| **Bid feed** | The last bids, newest first |
| **Up next** | The players coming after this one |

The header shows your remaining purse at all times.

### 5.2 Placing a bid

Press one of the four amount buttons, or the big **Bid** button for the
smallest legal raise. That is the whole action — there is nothing to confirm.

The countdown restarts on every bid, including yours.

### 5.3 Why a bid can be refused

A greyed-out button means it is already impossible. If a bid is refused after
you press it, a message says why:

| Message | Means |
|---------|-------|
| **You already hold the highest bid** | You are leading. Wait for someone to raise |
| **The bid must be at least ₹X** | Someone got in first. The screen has already updated |
| **Insufficient purse — you can bid at most ₹X** | See 5.4 |
| **Squad is full** | You are at `max_squad_size` |
| **… has already signed N overseas players** | Overseas quota reached |
| **The hammer has already fallen on this lot** | Bidding closed before your press landed |

None of these are errors. Two owners pressing at the same instant is normal;
the application decides one winner and tells the other immediately.

### 5.4 Why you cannot spend your whole purse

You must still be able to complete a legal squad. So the most you can bid is:

> remaining purse − (players still needed × cheapest player left)

With `min_squad_size` 11, 6 players bought and ₹40 L left, you still need 5
more. If the cheapest remaining player is ₹2 L, ₹10 L is held back and your
ceiling is ₹30 L.

The refusal message always tells you the exact ceiling, so you never have to
work it out during bidding.

### 5.5 Practical advice

- The reserve shrinks as your squad fills — your last few picks can be your
  biggest.
- Watch the purse board. A rival near their cap cannot chase you.
- The countdown restart means there is no advantage to bidding late.

---

## 6. Scorer

Designed for a phone, one-handed, in sunlight. Every key is deliberately
large; a mis-tap here corrupts the match record, not just the view.

### 6.1 Before the first ball

Open the pad. It asks for three things in turn:

1. **Opening batter (on strike)**
2. **Opening batter (non-striker)**
3. **Bowler for the first over**

Scoring keys stay disabled until all three are named. The same prompts return
after every wicket and at the end of every over — this is deliberate, so the
record can never contain a ball faced by nobody.

Check the badge at the top:

- **Saving** — every ball is being written to the database
- **Demo** — you are on the demonstration match; nothing is saved. Sign in as
  the scorer, and confirm with your administrator that a live match exists

### 6.2 The pad

| Control | Use |
|---------|-----|
| **0 1 2 3 4 6** | Runs off the bat |
| **WD** | Wide. One run added automatically; the sheet asks for any extra run |
| **NB** | No ball. One run added; the sheet asks what was hit off the bat |
| **B** | Byes — legal ball, runs not credited to the batter |
| **LB** | Leg byes — same |
| **WICKET** | Opens the dismissal sheet |
| **Undo** | Removes the last ball completely |
| **Swap** | Corrects the strike (local practice mode only) |
| **Bowler** | Change the bowler |

On a laptop: `0`–`6` for runs, `W` wicket, `D` wide, `N` no ball, `U` undo.

### 6.3 Recording a wicket

**WICKET** → choose how: Bowled, Caught, LBW, Run out, Stumped, Hit wicket.

- **Caught / Stumped** — optionally name the fielder.
- **Run out** — say which batter is out (it can be the non-striker) and how
  many runs were completed first.

Confirm, then pick the next batter. Scoring resumes.

### 6.4 Undo

**Undo** removes the last ball entirely — score, over, both batters' figures
and the bowler's, all recalculated. Use it for any mistake, including the
wrong batter on strike.

There is no redo. Undo removes one ball at a time; press it twice to remove
two.

### 6.5 What the pad handles for you

You never have to work these out:

- Batters crossing on odd runs, including byes and extra wides
- Ends changing at the end of an over
- Wides and no-balls not counting toward the over
- Byes and leg byes not credited to the batter, nor charged to the bowler
- Which dismissals credit the bowler, and which (run outs) do not
- Maidens, strike rates and economy
- A bowler being blocked from bowling consecutive overs

### 6.6 What it will not let you do

Refusals are protections, not faults:

| Message | Means |
|---------|-------|
| **Name the bowler for the new over** | The over ended |
| **Name the next batter** | A wicket fell |
| **A bowler cannot bowl consecutive overs** | Pick someone else |
| **That batter is already out** | Wrong name in the list |
| **That batter is already at the crease** | They are already batting |
| **That player is not in the batting XI** | They were not named in the eleven |
| **This innings is already closed** | Ten wickets, or the overs are gone |

---

## 7. Viewer

No account needed. Open the application and choose **Watch live**, or go
directly to `/auction.php?role=viewer`.

**During the auction** you see the player under the hammer, the current bid
and who holds it, the countdown, every team's purse and squad size, and the
recent sales. It refreshes by itself.

**During a match** the scorecard shows the score, overs, run rate, both
batters, the bowler's figures and the balls of the current over.

Read-only throughout — nothing a viewer does can change anything.

---

## 8. Preparing a clean application

Two scripts, in `database/`. Both are run from
**cPanel → phpMyAdmin → select your database → SQL**.

> On shared hosting, delete any `USE` line at the top first, or run
> `deploy/strip-create-database.sh` to get pre-stripped copies.

### 8.1 A genuinely empty application

`database/reset.sql` deletes every player, team, user, bid, match and ball,
leaving the table structure untouched. It then creates a single administrator
so you can still sign in.

**Take a backup first** — phpMyAdmin → Export → Go. This cannot be undone.

Afterwards every screen says so plainly — the landing page drops its live
strip, the auction board reads **"No auction is running"** and the scoring
pad reads **"No match is being scored"**. That is correct for an empty
application, not a fault. Follow
[section 4.1](#41-before-the-auction-loading-the-tournament) to load your own
tournament.

### 8.2 A dataset for demonstrating

`database/demo_apl.sql` loads a complete, coherent tournament: six
franchises, a 60-player pool, an auction part way through with a player under
the hammer and three teams bidding, two completed squads, and a match ready
to score from the first ball.

```
Run database/reset.sql first, then database/demo_apl.sql
```

All demonstration accounts use the password `ChangeMe@2026`:

| Role | Email |
|------|-------|
| Administrator | `admin@apl.local` |
| Scorer | `scorer@apl.local` |
| Viewer | `viewer@apl.local` |
| Team owners | `ct@apl.local`, `mr@apl.local`, `hc@apl.local`, `df@apl.local`, `hw@apl.local`, `sl@apl.local` |

**This is demonstration data.** Run `reset.sql` again before real use — that
password is published in the project repository.

---

## 9. Demonstration script

A 12-minute walkthrough. Load `reset.sql` then `demo_apl.sql` immediately
beforehand so the starting state is predictable.

**Preparation.** Two browsers, or one normal and one private window — you
need to be signed in as two people at once.

- Window A: administrator, on the auction board
- Window B: a team owner (`hc@apl.local`), on the auction board
- Have the landing page ready in a third tab

### Minute 0–2 — the problem and the front door

Open the landing page. It states what the application does, shows the live
auction and match, and offers a card per role.

> "Four kinds of people use this, and each gets a screen built for what they
> actually do."

### Minute 2–6 — the auction

Window B (owner): point out the purse in the header, the player under the
hammer, the countdown.

1. Place a bid. Both windows update.
2. Bid again immediately → **"You already hold the highest bid."**
3. Press a large increment until it is refused → the message names the exact
   ceiling.

> "The purse is not advisory. A team must still be able to field a legal
> eleven, so the application reserves what the remaining slots will cost and
> tells the owner precisely what they can spend."

Window A (admin): press **Sold**. Both windows show the sale, the purse drops,
the squad count rises.

> "That is one database transaction — the lot closes, the player joins the
> squad and the money moves together, or none of it does. A player can never
> be sold without the purse being debited."

Worth saying: two owners bidding in the same millisecond is handled by a row
lock, so exactly one wins and the other is told immediately. It has been
tested by racing four bidders at the same amount.

### Minute 6–10 — the scoring

Sign in as the scorer (`scorer@apl.local`) and open the pad. Ideally on a
phone, or a narrow browser window.

1. Name the two openers and the bowler.
2. Score `1` — the strike rotates by itself.
3. Score `4` — the boundary count moves.
4. Press **WD** → 1 extra, and the over does not advance.
5. Press **WICKET** → Bowled → confirm → pick the next batter.
6. Press **Undo** — the wicket disappears and everything recalculates.

> "The scorer records what happened. Strike rotation, over changes, which
> runs are charged to the bowler — the application works those out. And every
> number on screen is derived from the ball log, which is why Undo is exact."

### Minute 10–12 — the viewer, and closing

Open a private window with no login, choose **Watch live**. The board is
there, read-only.

Close on what it means: one place from the auction to the last ball, usable
on a phone at the ground, with the tournament's rules enforced by the system
rather than by whoever is holding the spreadsheet.

**Be ready for two questions:**

- *"How do I add my own players?"* — Today, through the database. The
  player, team and fixture management screens are the next thing to build.
- *"Can it handle a full match?"* — The first innings, yes, completely. The
  second innings, target and result are not built yet.

Answering those plainly is better than being caught by them.

---

## 10. Changing a password

Two steps, because passwords are stored hashed and SQL cannot hash.

**Step 1 — make the hash.** Create a file called `hash.php` in the
application's `public/` folder:

```php
<?php echo password_hash('your-new-password', PASSWORD_BCRYPT, ['cost' => 12]);
```

Open `https://your-site/APL/hash.php`, copy the line beginning `$2y$12$…`,
then **delete the file immediately**.

**Step 2 — save it.** phpMyAdmin → SQL:

```sql
UPDATE users SET password_hash = '<paste the hash>' WHERE email = 'you@yourclub.in';
```

There is no self-service password reset, so an administrator does this for
anyone who is locked out.

---

## 11. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Buttons show all their labels at once, fields empty | The browser is blocking the application's JavaScript | A Content-Security-Policy problem. See `DEPLOY-CPANEL.md` |
| Sign-in returns to the login form | No HTTPS, so the session cookie is not sent | Enable SSL |
| **503 Service temporarily unavailable** | The database is unreachable | Check `DB_*` in `.env`, then `storage/logs/php-error.log` |
| Scorer badge says **Demo** | Not signed in as scorer/admin, or no live match | Sign in; confirm a match with `status = 'live'` and an open innings |
| Landing page shows an old tournament | Previous data still loaded | Run `reset.sql` |
| **"No auction is running" / "No match is being scored"** | The database is empty, or no lot is live and no innings is open | Expected on a clean install. Load a tournament (4.1) and open a lot (4.2), or a fixture (4.3) |
| `#1701 Cannot truncate a table referenced in a foreign key constraint` | An old copy of `reset.sql` that used TRUNCATE | Use the current `reset.sql`; it uses DELETE and works with foreign key checks on |
| Bid button greyed out | Leading, purse-blocked, or squad full | Hover for the reason; see 5.3 |
| Auction board not updating | Lost connection | It refreshes every 3 seconds; reload the page |
| "Not Found" on a page | That file was not uploaded | Re-upload `public/` |

**Where the errors are:** `storage/logs/php-error.log` in the application
folder, and cPanel → Metrics → Errors.

---

## 12. What the application does not do yet

Stated plainly so nobody is surprised mid-tournament.

**1. No setup screens.** Tournaments, teams, players, user accounts and
fixtures are created in phpMyAdmin. Everything after setup — the whole
auction, the whole first innings — is driven from the application. This is
the single biggest gap and the obvious next thing to build, along with a CSV
import so a 60-player pool does not have to be typed.

**2. Only the first innings.** Ball-by-ball scoring, the live scorecard and
undo are complete for one innings. The innings break, the second-innings
target, the chase and the match result are not implemented. A match can be
scored through the first innings and no further.

**3. No password self-service.** An administrator sets every password by hand.

**4. No sale undo.** Pressing **Sold** is final; correcting it needs a
database edit.

**5. Screens refresh every 3 seconds** rather than being pushed instantly.
Fine for a club tournament; worth revisiting for a large audience.

**6. No fixtures, points table or player statistics screens.** The data is
recorded — the schema holds fixtures, results and every ball — but there are
no pages that display it yet.

---

## 13. Glossary

| Term | Meaning |
|------|---------|
| **Lot** | One player being auctioned |
| **Base price** | The lowest a player can be bought for |
| **Purse** | Money a team has to spend |
| **Increment** | The fixed step between bids |
| **Reserve** | Purse held back so a team can still complete a legal squad |
| **Under the hammer** | The player currently being bid for |
| **Unsold** | A lot that closed with no bid; the player can be re-listed |
| **Squad cap** | The most players one team may hold |
| **Legal delivery** | A ball that counts toward the over — not a wide or no ball |
| **Extras** | Runs not scored off the bat: wides, no balls, byes, leg byes |
| **Strike rotation** | Batters swapping ends after odd runs and at the end of an over |
| **Maiden** | An over from which no runs were charged to the bowler |
| **Economy** | Runs a bowler concedes per over |
| **Strike rate** | A batter's runs per 100 balls |
