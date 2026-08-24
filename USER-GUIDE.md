# CricAuction — User Guide

Everything needed to run a tournament, train the people using it, and
demonstrate the application.

**Read first:** [What the application does not do yet](#14-what-the-application-does-not-do-yet).
Two of those limits change how you plan a session, so it is better to know
them before the auction than during it.

---

## Contents

1. [What this application is](#1-what-this-application-is)
2. [The five roles](#2-the-five-roles)
3. [How a season runs, start to finish](#3-how-a-season-runs-start-to-finish)
4. [Signing in](#4-signing-in)
5. [Player](#5-player)
6. [Administrator](#6-administrator)
7. [Team Owner](#7-team-owner)
8. [Scorer](#8-scorer)
9. [Viewer](#9-viewer)
10. [Preparing a clean application](#10-preparing-a-clean-application)
11. [Demonstration script](#11-demonstration-script)
12. [Passwords](#12-passwords)
13. [Troubleshooting](#13-troubleshooting)
14. [What the application does not do yet](#14-what-the-application-does-not-do-yet)
15. [Glossary](#15-glossary)

---

## 1. What this application is

CricAuction runs a club or corporate cricket tournament from the first
registration to the last ball.

**The people.** Players register themselves — name, address, mobile, photo,
what kind of cricketer they are — and an administrator approves them. Nobody
reaches an auction without that approval, and a player's name and email are
fixed from the moment they are approved, so what the administrator agreed to
is what stays on the sheet.

**The tournament.** An administrator creates a season with its four dates and
a secret code. Players join with the code; approving an application is what
puts a name into the auction list.

**The auction.** Players go under the hammer one at a time. Team owners bid
against a countdown, each team has a fixed purse, and the application refuses
any bid a team cannot afford. When the hammer falls the player joins that
squad and the money moves — in one step, so a player can never be sold
without the purse being debited.

**The scoring.** Once squads exist, matches are scored ball by ball on a phone
at the ground. The scorer records what happened — a four, a wide, a wicket —
and the application works out the rest: who is on strike, when the over ends,
which runs count against the bowler. Everyone else watches the scorecard
update.

Everything runs in a web browser. There is nothing to install.

---

## 2. The five roles

| Role | Who | Can do | How the account is made |
|------|-----|--------|--------------------------|
| **Viewer** | Anyone | Watch the auction board and the live scorecard | No account needed |
| **Player** | Anyone who wants to be auctioned | Register, join a tournament with its code, keep their details current | Registers themselves; an administrator approves |
| **Team Owner** | One per franchise | Name their team, bid for players, see their purse and squad | An administrator creates the team and names the owner |
| **Scorer** | One per match | Record every ball | An administrator creates it and hands over the credentials |
| **Administrator** | Tournament director | Everything: approvals, tournaments, teams, the hammer | Created with the database, or by another administrator |

**One team has exactly one owner.** The database enforces it, not just the
screens — two accounts cannot hold the same team.

**A team owner may also be a player.** It is not required. An owner who wants
to be auctioned applies to the tournament like anybody else, and is approved
like anybody else.

---

## 3. How a season runs, start to finish

Read this once and the rest of the guide is a reference.

```
  ADMINISTRATOR                    PLAYER
  ─────────────                    ──────
  Creates the tournament   ──▶     (4 dates + a secret code)
       │
       │  gives out the code       Registers
       │                             │  name, address, mobile,
       │                             │  photo, kind of player, email
       ▼                             ▼
  Approves the account     ◀──     waits
       │                             │
       │                             ▼
       │                           Applies with the secret code
       ▼                             │
  Approves the application ────────▶ IN THE AUCTION LIST
       │
       ▼
  Creates each team, names its one owner
       │
       ▼                           OWNER
  Runs the auction         ◀──▶    names the team, bids
       │                             │
       │                             │  may rename until the deadline
       ▼                             ▼
  Sets up matches          ──▶     SCORER records every ball
```

**Two approvals, and they are different.** The first says *this is a real
person*. The second says *this person is in this tournament* — and it is the
one that puts a name into the auction list. Approving an application creates
the player record and the auction lot in the same instant, so there is no
third step to forget.

**What a player can and cannot change.**

| Detail | Player | Administrator |
|--------|--------|---------------|
| Full name | **No** | Yes |
| Email address | **No** | Yes |
| Username | No | Yes (via the database) |
| Mobile number | Yes | Yes |
| Address | Yes | Yes |
| Photo | Yes | Yes |
| Kind of player | Yes | Yes |

The registration form says so twice before anything is saved, and a third
time on the confirmation step. The rule is not enforced by a disabled input —
the method that saves a player's own changes has no name or email parameter
at all, so re-enabling the field in a browser achieves nothing.

---

## 4. Signing in

1. Go to the application address (for example `https://deam.co.in/APL`).
2. The landing page explains the application and shows five role cards.
   Viewers can go straight in; players click **Register**; everyone else
   clicks **Sign in**.
3. Enter **your username or your email address** — either works — and your
   password.

You land on your own screen: administrators on the administration hub,
scorers on the scoring pad, owners on their team, players on their details.

**"Your registration is still waiting for an administrator to approve it."**
Your password was right; the account simply is not approved yet. Nothing to
do but wait, or ask the organisers.

**"Those credentials do not match our records"** is shown for both an unknown
username and a wrong password, on purpose — it stops anyone probing for valid
accounts.

**If you are sent straight to a "Change password" screen**, you are signed in
with a password an administrator issued. Replace it and you will be let
through. Nothing else is reachable until you do.

**If sign-in returns you to the login form**, the site is not running over
HTTPS. Session cookies are marked "secure", so the browser refuses to send
them over plain HTTP. Tell your administrator.

---

## 5. Player

### 5.1 Registering

From the landing page choose **Register as a player**.

You will be asked for your full name, email address, a username, your mobile
number, your address, what kind of player you are, and a password. A photo is
optional and can be added later.

> **Your full name and email address are permanent.** Read them on the
> confirmation screen before you press **Confirm and register**. After that
> only an administrator can change them. Everything else is yours to update
> whenever you like.

A username is 3 to 40 characters — letters, numbers, dot, underscore or
hyphen, no spaces. A password needs at least 8 characters with a letter and a
number in it.

When you submit, the screen says **Registration received**. You cannot sign
in yet: an administrator has to approve the account first.

### 5.2 Joining a tournament

Once approved, sign in and choose **Join a tournament**.

The organisers will give you a **secret code** — eight characters, on a
WhatsApp message or read out at a meeting. Type it in and press **Apply**.

Codes never contain `0`, `O`, `o`, `1`, `I`, `l` or `i`. Those are the
characters people mistake for each other, so they are simply not used. If you
think you see a zero, it is the letter O — and if you think you see the
letter O, look again, because it is neither.

Case does not matter, and spaces or hyphens are ignored: `kxq7 rbtm` and
`KXQ7RBTM` are the same code.

Applying does **not** put you in the auction. An administrator reviews the
application; your name enters the auction list when they approve it. **My
details** shows where each of your applications stands.

### 5.3 Keeping your details current

**My details** lets you change your mobile number, address, photo and what
kind of player you are, at any time. Your name, email and username are shown
but greyed out — ask an administrator if one of them is genuinely wrong.

### 5.4 After the auction

**My details** shows the outcome for each tournament: still in the pool, sold
to a named team, or unsold. An unsold player can be re-listed by the
administrator in a later round.

---

## 6. Administrator

Everything an administrator does now has a screen. Sign in and you land on
the **administration hub**, which shows the two queues that hold everything
else up — registrations waiting to be approved, and applications waiting to
be let into a tournament — and links to the rest.

### 6.1 Creating a tournament

**Administration → Tournaments → Create a tournament.**

| Field | Means |
|-------|-------|
| Tournament name | Shown everywhere. Unique within a season |
| Season | The year |
| **Auction date** | When the hammer falls. **Entries close at the end of this day** |
| **Start date** | First ball of the season. Must be on or after the auction |
| **End date** | Last day of the season |
| **Team name change deadline** | The last day an owner may rename their own team |
| Purse per team | Money each team starts with, in rupees. 5000000 = ₹50 L |
| Bid increment | The step between bids. 50000 = ₹50,000 |
| Minimum squad | Smallest legal squad. Drives the reserve rule in 7.5 |
| Maximum squad | A team at this number cannot bid again |
| Overseas limit | Overseas players one squad may hold |
| Overs per innings | 20 for T20 |

The dates are checked against each other: the end cannot precede the start,
the auction cannot fall after the first ball, and the name deadline cannot
outlast the season. Any of them may be left blank while the calendar is still
being settled, and filled in later.

**The secret code is generated for you** and shown on the tournament card in
large type. That code is the only way a player joins. Give it out however you
like — a WhatsApp group, a poster, read out at a meeting.

Why the name change deadline exists: a team is usually named before its squad
is known. Setting the deadline a few days after the auction lets an owner
settle on a name with the players they actually signed, and then freezes it
before fixtures are printed.

### 6.2 Approving registrations

**Administration → People.**

The **Waiting** tab lists everyone who has registered and not yet been
decided on. Each entry shows the name, email, mobile, address, kind of player
and photo — everything they submitted.

- **Approve** — they can now sign in and apply to a tournament.
- **Reject** — they cannot sign in. The screen tells them so plainly.

Approving here does **not** put anybody in an auction. It says only that the
person is real.

**Edit details** opens the same person for correction. This is the only place
a name or email can be changed, which is the point: a player cannot quietly
become somebody else, but a genuine typo is still fixable.

**Account status** can be set to Suspended at any time. A suspended account is
signed out on its very next click, not whenever its session happens to
expire.

### 6.3 Letting players into a tournament

**Administration → Applications.**

Pick the tournament along the top; the badge is how many are waiting. Each
application shows the player's details and, on the same row, the three things
you set at the moment of approval:

| Field | Means |
|-------|-------|
| Base price | The lowest anyone may bid for them. Defaults to ₹2,00,000 |
| Auction set | A free label used to group the pool — Marquee, Set A … |
| Overseas | Counts against the tournament's overseas limit |
| Note | Kept on the record with your name and the date |

**Approve** creates the player record and their auction lot in one step, at
the back of the queue. The moment you press it they are in the auction list.
**Reject** creates nothing; the player may apply again.

### 6.4 Creating teams and naming owners

**Administration → Teams.**

Choose the person who will own the team, give it a working name and a 2–6
character short name, and press **Create team**. The purse comes from the
tournament.

The name really can be a placeholder — the owner sets the real one
themselves, and can change it up to the deadline. That is what the deadline
is for.

**Assign** hands a team to a different owner. The outgoing owner is released
in the same step, because one team may only ever have one owner.

### 6.5 Creating scorer accounts

**Administration → People → Create a scorer or administrator.**

Fill in a name, a username, an email and the role. Leave the password blank
and one is generated — two four-character groups with none of the confusable
characters in them, so it can be read down a phone line.

The credentials appear once, on the next screen. **They are not stored
anywhere readable**, so write them down or send them before you navigate
away. The account is forced to change the password at first sign-in, so what
you hand over never stays in use.

**Reset password** on any person does the same thing for an account that is
locked out.

### 6.6 Running the auction

> **The auction is called aloud in the room.** The application is the record
> of it, not the bidding floor. Open **Administration → Auction**: for each
> player, choose the team and type the price that was called, then press
> **Sold**. The purse board across the top is always visible, and every sale
> has an **Undo** beside it.
>
> The live bidding board described below still works, for anyone who would
> rather owners bid on screen — but the sheet is the supported method.

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

### 6.7 Setting up a match

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

## 7. Team Owner

### 7.1 Naming your team

An administrator creates your team and names you as its owner. It then
appears under **My team**.

Set the **team name**, the **short name** (2 to 6 letters or digits — the
badge on the scoreboard, like MI or CSK), the team colour and, if you like, a
home ground.

You may change all of it as often as you want **until the team name change
deadline**, which the screen states in plain words:

> You can change the name until 10 September 2026. After that only an
> administrator can.

Once that day has passed the name and short name are shown greyed out and the
screen says so. The colour and home ground stay editable. This is deliberate:
a team is usually named before its squad is known, and the deadline gives you
room to settle a name with the players you actually signed, then freezes it
before fixtures are printed.

A name has to be unique within the tournament. A team in a different
tournament may use it — that is not a clash.

**My team** also shows your remaining purse and everyone you have bought.

### 7.2 Reading the auction screen

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

### 7.3 Placing a bid

Press one of the four amount buttons, or the big **Bid** button for the
smallest legal raise. That is the whole action — there is nothing to confirm.

The countdown restarts on every bid, including yours.

### 7.4 Why a bid can be refused

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

### 7.5 Why you cannot spend your whole purse

You must still be able to complete a legal squad. So the most you can bid is:

> remaining purse − (players still needed × cheapest player left)

With `min_squad_size` 11, 6 players bought and ₹40 L left, you still need 5
more. If the cheapest remaining player is ₹2 L, ₹10 L is held back and your
ceiling is ₹30 L.

The refusal message always tells you the exact ceiling, so you never have to
work it out during bidding.

### 7.6 Practical advice

- The reserve shrinks as your squad fills — your last few picks can be your
  biggest.
- Watch the purse board. A rival near their cap cannot chase you.
- The countdown restart means there is no advantage to bidding late.

---

## 8. Scorer

Designed for a phone, one-handed, in sunlight. Every key is deliberately
large; a mis-tap here corrupts the match record, not just the view.

### 8.1 Before the first ball

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

### 8.2 The pad

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

### 8.3 Recording a wicket

**WICKET** → choose how: Bowled, Caught, LBW, Run out, Stumped, Hit wicket.

- **Caught / Stumped** — optionally name the fielder.
- **Run out** — say which batter is out (it can be the non-striker) and how
  many runs were completed first.

Confirm, then pick the next batter. Scoring resumes.

### 8.4 Undo

**Undo** removes the last ball entirely — score, over, both batters' figures
and the bowler's, all recalculated. Use it for any mistake, including the
wrong batter on strike.

There is no redo. Undo removes one ball at a time; press it twice to remove
two.

### 8.5 What the pad handles for you

You never have to work these out:

- Batters crossing on odd runs, including byes and extra wides
- Ends changing at the end of an over
- Wides and no-balls not counting toward the over
- Byes and leg byes not credited to the batter, nor charged to the bowler
- Which dismissals credit the bowler, and which (run outs) do not
- Maidens, strike rates and economy
- A bowler being blocked from bowling consecutive overs

### 8.6 What it will not let you do

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

## 9. Viewer

No account needed. Open the application and choose **Watch live**, or go
directly to `/auction.php?role=viewer`.

**During the auction** you see the player under the hammer, the current bid
and who holds it, the countdown, every team's purse and squad size, and the
recent sales. It refreshes by itself.

**During a match** the scorecard shows the score, overs, run rate, both
batters, the bowler's figures and the balls of the current over.

Read-only throughout — nothing a viewer does can change anything.

---

## 10. Preparing a clean application

Two scripts, in `database/`. Both are run from
**cPanel → phpMyAdmin → select your database → SQL**.

> On shared hosting, delete any `USE` line at the top first, or run
> `deploy/strip-create-database.sh` to get pre-stripped copies.

### 10.1 A genuinely empty application

`database/reset.sql` deletes every player, team, user, bid, match and ball,
leaving the table structure untouched. It then creates a single administrator
so you can still sign in.

**Take a backup first** — phpMyAdmin → Export → Go. This cannot be undone.

Afterwards every screen says so plainly — the landing page drops its live
strip, the auction board reads **"No auction is running"** and the scoring
pad reads **"No match is being scored"**. That is correct for an empty
application, not a fault. Follow
[section 6.1](#61-creating-a-tournament) to load your own
tournament.

### 10.2 A dataset for demonstrating

`database/demo_apl.sql` loads a complete, coherent tournament: six
franchises, a 60-player pool, an auction part way through with a player under
the hammer and three teams bidding, two completed squads, and a match ready
to score from the first ball.

```
Run database/reset.sql first, then database/demo_apl.sql
```

All demonstration accounts use the password `ChangeMe@2026`, and none of them
is forced to change it — a demonstration should not open on a password
prompt. Sign in with the username or the email, whichever you prefer.

| Role | Username | Email |
|------|----------|-------|
| Administrator | `apl.admin` | `admin@apl.local` |
| Scorer | `apl.scorer` | `scorer@apl.local` |
| Viewer | `apl.viewer` | `viewer@apl.local` |
| Team owners | `apl.ct`, `apl.mr`, `apl.hc`, `apl.df`, `apl.hw`, `apl.sl` | `ct@apl.local` … `sl@apl.local` |

The usernames are prefixed so this file can be imported straight on top of
the single `admin` account `reset.sql` creates.

**The demonstration tournament's secret code is `BATSMAN7`** — fixed and
memorable for a demonstration, but still drawn only from the legal alphabet.
Real codes are generated. Its four dates are written relative to the day you
import it: the auction is today, the season starts in a fortnight, team names
lock in a week. So the demonstration never opens on a season that finished
last year.

**This is demonstration data.** Run `reset.sql` again before real use — that
password is published in the project repository.

---

## 11. Demonstration script

A 16-minute walkthrough. Load `reset.sql` then `demo_apl.sql` immediately
beforehand so the starting state is predictable.

**Preparation.** Two browsers, or one normal and one private window — you
need to be signed in as two people at once.

- Window A: administrator (`apl.admin`), on the administration hub
- Window B: a team owner (`apl.hc`), on the auction board
- Have the landing page ready in a third tab
- Know the demonstration secret code: **`BATSMAN7`**

### Minute 0–2 — the problem and the front door

Open the landing page. It states what the application does, shows the live
auction and match, and offers a card per role.

> "Five kinds of people use this, and each gets a screen built for what they
> actually do."

### Minute 2–6 — a player joins, and an administrator lets them in

This is the part investors ask about, because it is where the trust is.

Third tab: **Register as a player**. Fill it in quickly — invent a name, use
an obviously fake email. Press **Continue**.

> "Notice what it does before saving anything. It shows the name and the
> email back and says, in as many words, that these two are permanent. A
> player can change their mobile, address and photo whenever they like. Not
> their identity."

Press **Confirm and register**. Point at the message: nothing is open to them
yet.

Try to sign in as the new player. It refuses — and says why: still waiting
for an administrator.

> "The password was correct. It says so only *after* the password is right,
> so it cannot be used to find out who has an account here."

Window A: **People → Waiting**. The registration is there with the photo and
every detail. Press **Approve**.

Sign in as the player. **Join a tournament** → type `BATSMAN7` → **Apply**.

> "Applying is not entry. The organisers hand out that code, and the code
> gets you as far as a queue."

Window A: **Applications**. Set a base price, press **Approve**.

> "That one press wrote the player record and their auction lot in a single
> transaction. Approved and 'in the auction list' are the same event — there
> is no third step for a volunteer to forget on the morning of the auction."

### Minute 6–10 — the auction

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

### Minute 10–14 — the scoring

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

### Minute 14–16 — the viewer, and closing

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

## 12. Passwords

### Changing your own

**Password** in the top navigation, on every signed-in screen. You need your
current password, and the new one needs at least 8 characters with a letter
and a number. It cannot be the same as the old one.

### An account that was issued a password

When an administrator creates a scorer, or resets anybody's password, the
account is marked as needing to choose its own. The next sign-in goes
straight to the change-password screen and stays there — no other screen will
open until the password has been replaced. A password read out over the phone
therefore never survives its first use.

### Somebody is locked out

**Administration → People → Reset password** on their row. A new password is
generated and shown once. Read it to them; they will be asked to change it
immediately.

### The very first administrator

`database/reset.sql` creates one account:

| Username | `admin` |
|----------|---------|
| Email | `admin@example.com` |
| Password | `ChangeMe@2026` |

That password is published in the project repository, so the account is
created with the forced-change flag set: the first sign-in cannot get past
the change-password screen. Change it, then edit the name and email under
**People**.

---

## 13. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Buttons show all their labels at once, fields empty | The browser is blocking the application's JavaScript | A Content-Security-Policy problem. See `DEPLOY-CPANEL.md` |
| Sign-in returns to the login form | No HTTPS, so the session cookie is not sent | Enable SSL |
| **503 Service temporarily unavailable** | The database is unreachable | Check `DB_*` in `.env`, then `storage/logs/php-error.log` |
| Scorer badge says **Demo** | Not signed in as scorer/admin, or no live match | Sign in; confirm a match with `status = 'live'` and an open innings |
| Landing page shows an old tournament | Previous data still loaded | Run `reset.sql` |
| **"Your registration is still waiting for an administrator"** | The account is real but not approved | Administration → People → Waiting → Approve |
| Sign-in always lands on "Change password" | The password was issued or reset by an administrator | Change it; every other screen opens afterwards |
| A player applied but is not in the auction | The application has not been approved | Administration → Applications → Approve. That is the step that creates the lot |
| **"That code does not match any tournament"** | Wrong code, or the code was re-issued | Check it; Administration → Tournaments shows the current one |
| **"Entries are closed"** | The auction date has passed, or entries were closed by hand | Administration → Tournaments → Open entries, or move the auction date |
| An owner cannot rename their team | The team name change deadline has passed | An administrator can still rename it, under Administration → Teams |
| **"Another team in this tournament is already called…"** | Names are unique within a tournament | Pick a different one; the same name in a different season is fine |
| Signed in, but told "You do not own a team" | The team was assigned in another window | It refreshes on the next click; if not, sign out and in |
| **Sign out appears to do nothing** — you land back on your own screen | An old copy of the application, where the header linked to `logout.php` with a plain link and the page ignored it | Upload the current `app/Views/layouts/shell.php`, `public/logout.php`, `public/login.php`, `public/auction.php` and `public/score.php` |
| **"No auction is running" / "No match is being scored"** | The database is empty, or no lot is live and no innings is open | Expected on a clean install. Create a tournament (6.1), let players in (6.3) and open a lot (6.6), or set up a fixture (6.7) |
| `#1701 Cannot truncate a table referenced in a foreign key constraint` | An old copy of `reset.sql` that used TRUNCATE | Use the current `reset.sql`; it uses DELETE and works with foreign key checks on |
| Bid button greyed out | Leading, purse-blocked, or squad full | Hover for the reason; see 7.4 |
| Auction board not updating | Lost connection | It refreshes every 3 seconds; reload the page |
| "Not Found" on a page | That file was not uploaded | Re-upload `public/` |

**Where the errors are:** `storage/logs/php-error.log` in the application
folder, and cPanel → Metrics → Errors.

---

## 14. What the application does not do yet

Stated plainly so nobody is surprised mid-tournament.

**1. No bulk player import.** Registrations, approvals, tournaments, teams
and owners all have screens now. What is still missing is a CSV import, so a
club moving an existing 60-player pool across has to have each player
register, or an administrator add them in phpMyAdmin.

**2. Fixtures are still set up in the database.** Creating a match, the
playing elevens and the first innings is SQL — see
[section 6.7](#67-setting-up-a-match). Everything from the first ball onward
is driven from the application.

**3. Only the first innings.** Ball-by-ball scoring, the live scorecard and
undo are complete for one innings. The innings break, the second-innings
target, the chase and the match result are not implemented. A match can be
scored through the first innings and no further.

**4. No live on-screen bidding.** By design. The auction is called aloud in
the room and recorded on **Administration → Auction**; the administrator
types the price and the buying team. Every sale has an **Undo** beside it.

**5. No email.** Approvals, rejections and issued passwords are not emailed —
somebody has to tell the person. Every state is visible on their own screen
when they next sign in.

**6. No password reset by email.** An administrator resets it and reads out
the new one.

**7. Screens refresh every 3 seconds** rather than being pushed instantly.
Fine for a club tournament; worth revisiting for a large audience.

**8. No fixtures, points table or player statistics screens.** The data is
recorded — the schema holds fixtures, results and every ball — but there are
no pages that display it yet.

---

## 15. Glossary

| Term | Meaning |
|------|---------|
| **Secret code** | The eight characters a player types to join a tournament. Never contains 0, O, o, 1, I, l or i |
| **Application** | A player asking to join a tournament. Approving one is what creates their auction lot |
| **Approved** | Two meanings, deliberately separate: an approved *account* is a real person; an approved *application* is in the auction |
| **Name change deadline** | The last day an owner may rename their own team |
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
