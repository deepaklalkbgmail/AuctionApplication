/*
   =====================================================================
   Change a player's base price and auction set, after approval
   =====================================================================

   Base price and auction set are chosen once, on the approval form in
   Administration -> Applications. There is no screen that changes them
   afterwards, so this file is how it is done today.

   Fill in the four values in STEP 2 and run the whole thing.

   ---------------------------------------------------------------------
   WHY TWO TABLES

   A player's base price is written in two places, and both have to move
   or the screens disagree with each other:

       players.base_price       what the player is worth. The auction
                                uses it to work out how much purse a team
                                must hold back for the slots it still has
                                to fill.

       auction_lots.base_price  the floor for THIS auction. It is the
                                figure the auctioneer's sheet prints, and
                                the minimum the Price box will accept.

   Update only the first and the sheet keeps showing the old number —
   which is exactly what it looks like when nothing happened at all.

   The auction set lives in one place, players.auction_set. "Marquee" is
   not a special word to the database; the sheet's "Marquee first"
   ordering matches it case-insensitively, so Marquee, MARQUEE and
   marquee all sort to the top. Anything else — Set A, Set B — sorts
   after it, alphabetically.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   If nothing comes back at the end, nobody matched: check the mobile
   number and the tournament code. Nothing is changed in that case.

   A player who has already been SOLD keeps their lot's base price — the
   sale is done and the floor no longer means anything. Undo the sale on
   the auction sheet first if you really need to change it.
   =====================================================================
*/


/* ---------------------------------------------------------------------
   STEP 1 — Who is this? Run this on its own first if you want to be
   sure you have the right person before changing anything.
   --------------------------------------------------------------------- */
SET @mobile := '7798093786';
SET @code   := 'JFG24YH2';

SELECT p.id                            AS player_id,
       p.full_name,
       u.phone,
       t.name                          AS tournament,
       p.base_price                    AS players_base_price,
       l.base_price                    AS lot_base_price,
       IFNULL(p.auction_set, '(none)') AS auction_set,
       l.status                        AS lot_status
  FROM players p
  JOIN users       u ON u.id = p.user_id
  JOIN tournaments t ON t.id = p.tournament_id
  LEFT JOIN auction_lots l ON l.player_id = p.id
 WHERE RIGHT(REGEXP_REPLACE(u.phone, '[^0-9]', ''), 10) = @mobile
   AND t.secret_code = @code;


/* ---------------------------------------------------------------------
   STEP 2 — What it should become.

   The mobile is matched on its last ten digits with everything that is
   not a digit thrown away, so it finds the player whether they typed
   7798093786, +91 77980 93786 or 077980-93786.

   base_price is in RUPEES, and it is the whole figure — not lakhs, not
   thousands:

       5000     five thousand
       200000   two lakh
       500000   five lakh

   It must be greater than zero; the database refuses 0 outright.
   --------------------------------------------------------------------- */
SET @mobile := '7798093786';
SET @code   := 'JFG24YH2';
SET @base   := 5000;
SET @set    := 'Marquee';


/* ---------------------------------------------------------------------
   STEP 3 — Find them once, then change both tables.
   If nobody matches, @player is NULL and neither UPDATE touches a row.
   --------------------------------------------------------------------- */
SET @player := (
    SELECT p.id
      FROM players p
      JOIN users       u ON u.id = p.user_id
      JOIN tournaments t ON t.id = p.tournament_id
     WHERE RIGHT(REGEXP_REPLACE(u.phone, '[^0-9]', ''), 10) = @mobile
       AND t.secret_code = @code
     LIMIT 1
);

UPDATE `players`
   SET `base_price`  = @base,
       `auction_set` = @set
 WHERE `id` = @player;

UPDATE `auction_lots`
   SET `base_price` = @base
 WHERE `player_id` = @player
   AND `status` <> 'sold';


/* ---------------------------------------------------------------------
   STEP 4 — Prove it. The two base prices must agree.
   No rows means nobody matched and nothing was changed.
   --------------------------------------------------------------------- */
SELECT p.id                            AS player_id,
       p.full_name,
       p.base_price                    AS players_base_price,
       l.base_price                    AS lot_base_price,
       IF(p.base_price = l.base_price, 'in step', '*** MISMATCH ***') AS agree,
       IFNULL(p.auction_set, '(none)') AS auction_set,
       l.status                        AS lot_status
  FROM players p
  LEFT JOIN auction_lots l ON l.player_id = p.id
 WHERE p.id = @player;
