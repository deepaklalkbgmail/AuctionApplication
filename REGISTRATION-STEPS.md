# From registration to the auction list

The real path: a player registers, an administrator approves them, they join
a tournament with its secret code, and the administrator lets them in. Only
that last approval puts a name in front of the bidders.

Every message quoted below is the actual text, taken from a run against a
clean install.

---

## Step 0 — Remove the nine dummy players

Run this first if you used `database/add_players.sql`.

phpMyAdmin → click **`deamco_APL` in the left sidebar** → **SQL** tab → paste
→ **Go**.

```sql
DELETE l FROM `auction_lots` l
  JOIN `players` p ON p.`id` = l.`player_id`
 WHERE p.`full_name` IN ('Arjun Menon','Rohit Pillai','Vishnu Nair','Sandeep Kurup',
                         'Anand Varma','Faisal Rahman','Deepak Thomas','Joel Fernandes','Cameron Blake')
   AND p.`user_id` IS NULL
   AND l.`status` <> 'sold'
   AND NOT EXISTS (SELECT 1 FROM `auction_bids` b WHERE b.`lot_id` = l.`id`);

DELETE FROM `players`
 WHERE `full_name` IN ('Arjun Menon','Rohit Pillai','Vishnu Nair','Sandeep Kurup',
                       'Anand Varma','Faisal Rahman','Deepak Thomas','Joel Fernandes','Cameron Blake')
   AND `user_id` IS NULL
   AND `status` <> 'sold'
   AND `id` NOT IN (SELECT * FROM (SELECT `player_id` FROM `auction_lots`) keep);
```

It removes only those nine, and only while they are unsold and have no bids
against them. Anyone who registered properly is untouched — they have an
account, and the delete skips accounts.

**Check afterwards:**

```sql
SELECT id, full_name, IF(user_id IS NULL, 'no account', 'registered') AS kind
  FROM players;
```

Expect an empty result, or only players you recognise.

---

## What you need before you start

| | |
|---|---|
| A tournament | **Administration → Tournaments**. It must have a **secret code** |
| The secret code | Shown in large type on the tournament card |
| Entries open | The card should read **entries open** |
| The auction date not yet past | Entries close at the end of auction day |

If the tournament predates the account system it will have no code: press
**Issue a new code** on its card, then **Edit** to fill in the four dates.

---

## Step 1 — The player registers

**They do this themselves.** You do not create player accounts.

Send them the address — `https://deam.co.in/APL` — and they choose
**Register as a player**.

They are asked for:

| Field | Notes |
|---|---|
| Full name | **Permanent** |
| Email address | **Permanent** |
| Username | What they sign in with. 3–40 characters: letters, numbers, dot, underscore, hyphen. No spaces |
| Mobile number | 7 to 15 digits |
| Address | |
| Kind of player | Batsman, bowler, all-rounder or wicket-keeper |
| Password | At least 8 characters, with a letter and a number |
| Photo | Optional. JPEG, PNG or WebP, up to 3 MB |

**Two steps, on purpose.** Pressing **Continue** saves nothing. It shows what
they typed, with the name and email marked **PERMANENT** in amber, and offers
**Go back and edit**. Only **Confirm and register** creates the account.

They finish on **"Registration received"** and **cannot sign in yet**.

If they try, they get:

> Your registration is still waiting for an administrator to approve it.

That means the password was right. Nothing is wrong; they are waiting for you.

### What they will be refused

| They typed | Message |
|---|---|
| An email already registered | "That email address is already registered." |
| A username already taken | "That username is taken." |
| A username with a space | "A username is 3 to 40 characters: letters, numbers, dot, underscore or hyphen." |
| A short mobile number | "Enter a mobile number of 7 to 15 digits." |
| Two different passwords | "The two passwords do not match." |
| No kind of player | "Choose what kind of player you are." |
| A PDF as the photo | "The photo must be a JPEG, PNG or WebP image." |

---

## Step 2 — You approve the registration

Sign in as the administrator. The hub shows **Registrations to approve** in
amber with a count. Click it, or go to **Administration → People →
Waiting**.

Each entry shows everything they submitted — name, email, mobile, address,
kind of player and their photo.

| Button | Does |
|---|---|
| **Approve** | They can now sign in and apply to a tournament |
| **Reject** | They cannot sign in. The sign-in screen tells them so |
| **Edit details** | Correct anything — including the name and email a player cannot change |
| **Reset password** | Issues a new one, shown once |

Approving says **"Registration approved."** and they leave the Waiting list.

> **This does not put anybody in an auction.** It says only that the person is
> real. That is the whole job of this step.

**Account status** on the edit form can be set to **Suspended** at any time. A
suspended account is turned away on its very next click, not whenever its
session happens to expire.

---

## Step 3 — The player joins the tournament

Once approved, they sign in — **username or email address, either works** —
and choose **Join a tournament**.

They type the **secret code** and press **Apply**.

Case does not matter, and spaces and hyphens are ignored: `kxq7 rbtm` and
`KXQ7RBTM` are the same code. Codes never contain `0`, `O`, `o`, `1`, `I`,
`l` or `i` — the characters people misread when a code is read aloud.

They see **"Applied to <tournament>"**, and **My details** lists it as
**pending**.

### What they will be refused

| Situation | Message |
|---|---|
| Wrong code | "That code does not match any tournament. Check it with whoever gave it to you." |
| Applying twice | "You have already applied. An administrator will review it." |
| Entries closed by hand | "Registration for <tournament> is closed." |
| Auction day has passed | "The auction for <tournament> was on <date>. Entries are closed." |
| Account not approved yet | "Your registration is still waiting for an administrator…" |

---

## Step 4 — You let them into the auction

**Administration → Applications.** Pick the tournament along the top; the
badge is how many are waiting.

Three things are set at this moment, because it is the only point at which
somebody is actually looking at the player:

| Field | Notes |
|---|---|
| **Base price** | The lowest anyone may bid. Defaults to ₹2,00,000 |
| **Auction set** | A free label for grouping — Marquee, Set A … |
| **Overseas** | Counts against the tournament's overseas limit |
| **Note** | Kept on the record with your name and the date |

Press **Approve — add to the auction**.

> **This is the step that matters.** It creates the player record *and* their
> auction lot in one transaction. The moment you press it they are in the
> auction list — there is no third step to forget on the morning of the
> auction.

You will see:

> \<name\> is approved and is now in the auction list.

**Reject** creates nothing, and the player may apply again with the same
code.

### Proving it worked

```sql
SELECT p.full_name, p.base_price, l.lot_order, l.status
  FROM auction_lots l
  JOIN players p ON p.id = l.player_id
 ORDER BY l.lot_order;
```

Every approved player should appear with a lot and status `queued`. If a
player is in `players` but not here, something has gone wrong — tell me.

---

## Where that leaves you

| | |
|---|---|
| **Administration → Auction** | Every approved player under **Still to call**, with the purse board above |
| **auction.php** | The same, read-only, for anyone without an account |

From here it is teams and owners (**Administration → Teams**), then running
the auction. `TEST-PLAN.md` Modules 7 to 10 cover both, and the
Administrator Guide has the detail.

---

## The two approvals, in one line

**Approving an account** says *this is a real person*.
**Approving an application** says *this person is in this tournament* — and
that is the one that fills the auction list.

It is normal for a player to wait between the two.

---

*Prepared by Deam Software Solutios.*
