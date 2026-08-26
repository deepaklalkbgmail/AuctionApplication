/*
   =====================================================================
   Add nine players to the auction, from the database
   =====================================================================

   For when you want a pool without nine people registering first — a
   rehearsal, a demonstration, or a club moving an existing squad list
   across.

   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   Selecting the database matters. Run from the server level and @tournament
   below resolves against nothing.

   ---------------------------------------------------------------------
   A player needs TWO rows, not one

   players       who they are, and their base price
   auction_lots  their place in the auction

   A players row on its own does nothing: the auction sheet lists lots, so a
   player without one is invisible. This file writes both, which is exactly
   what pressing Approve on an application does.

   ---------------------------------------------------------------------
   Notes

   user_id is left NULL. These players have no login and cannot sign in;
   they exist only to be auctioned, which is all a rehearsal needs. A
   player who registers properly gets an account and a user_id.

   Safe to run twice. The second run adds nine more players with the same
   names — it does not overwrite. To undo, see the bottom of this file.

   Edit the names, roles and base prices freely. Keep to these values:
     role           batsman | batting_all_rounder | bowling_all_rounder | wicket_keeper | bowler
     batting_style  right_hand | left_hand | NULL
     bowling_style  right_arm_fast | right_arm_medium | right_arm_offbreak |
                    right_arm_legbreak | left_arm_fast | left_arm_medium |
                    left_arm_orthodox | left_arm_chinaman | none
     base_price     must be greater than 0
     is_overseas    1 counts against the tournament's overseas limit
   =====================================================================
*/

/*
   Which tournament. The newest one by default — set it by hand if you
   have more than one and want an older:   SET @tournament := 2;
*/
SET @tournament := (SELECT id FROM tournaments ORDER BY id DESC LIMIT 1);

/* Where this batch starts in the running order, after anything already there. */
SET @lot_from := IFNULL((SELECT MAX(lot_order) FROM auction_lots WHERE tournament_id = @tournament), 0);


/* ------------------------------------------------------------------- */
/* 1. The nine players                                                  */
/* ------------------------------------------------------------------- */
INSERT INTO `players`
    (`tournament_id`, `user_id`, `full_name`, `display_name`, `country`,
     `role`, `batting_style`, `bowling_style`, `is_overseas`, `is_capped`,
     `auction_set`, `base_price`,
     `career_matches`, `career_runs`, `career_wickets`, `strike_rate`, `economy`,
     `status`)
VALUES
    (@tournament, NULL, 'Arjun Menon',      'A Menon',   'India',     'batsman',       'right_hand', 'none',               0, 1, 'Marquee', 2000000.00, 48, 1420,  2, 138.50, 0.00,  'available'),
    (@tournament, NULL, 'Rohit Pillai',     'R Pillai',  'India',     'batsman',       'left_hand',  'right_arm_offbreak', 0, 0, 'Marquee', 1500000.00, 35,  980,  9, 129.20, 7.40,  'available'),
    (@tournament, NULL, 'Vishnu Nair',      'V Nair',    'India',     'batting_all_rounder',   'right_hand', 'right_arm_medium',   0, 1, 'Set A',   1500000.00, 52, 1105, 47, 132.10, 7.90,  'available'),
    (@tournament, NULL, 'Sandeep Kurup',    'S Kurup',   'India',     'bowling_all_rounder',   'left_hand',  'left_arm_orthodox',  0, 0, 'Set A',   1000000.00, 29,  610, 31, 118.60, 6.80,  'available'),
    (@tournament, NULL, 'Anand Varma',      'A Varma',   'India',     'bowler',        'right_hand', 'right_arm_fast',     0, 1, 'Set A',   1000000.00, 41,  180, 62,  92.40, 7.10,  'available'),
    (@tournament, NULL, 'Faisal Rahman',    'F Rahman',  'India',     'bowler',        'right_hand', 'right_arm_legbreak', 0, 0, 'Set B',    800000.00, 26,   95, 38,  88.00, 6.95,  'available'),
    (@tournament, NULL, 'Deepak Thomas',    'D Thomas',  'India',     'wicket_keeper', 'right_hand', 'none',               0, 0, 'Set B',    800000.00, 33,  845,  0, 125.70, 0.00,  'available'),
    (@tournament, NULL, 'Joel Fernandes',   'J Fernandes','India',    'wicket_keeper', 'left_hand',  'none',               0, 0, 'Set B',    500000.00, 18,  402,  0, 119.30, 0.00,  'available'),
    (@tournament, NULL, 'Cameron Blake',    'C Blake',   'Australia', 'batting_all_rounder',   'right_hand', 'right_arm_fast',     1, 1, 'Overseas', 2000000.00, 60, 1310, 55, 141.80, 7.60,  'available');


/* ------------------------------------------------------------------- */
/* 2. A lot for each of them, at the back of the running order          */
/*                                                                      */
/*    Matches every player in this tournament that has no lot yet, so    */
/*    it also picks up anyone you added by hand earlier and forgot.      */
/*    The derived table is deliberate: MySQL will not read the table     */
/*    being inserted into directly inside the same statement.            */
/* ------------------------------------------------------------------- */
INSERT INTO `auction_lots`
    (`tournament_id`, `player_id`, `lot_order`, `status`, `base_price`)
SELECT @tournament,
       p.`id`,
       @lot_from + ROW_NUMBER() OVER (ORDER BY p.`id`),
       'queued',
       p.`base_price`
  FROM `players` p
 WHERE p.`tournament_id` = @tournament
   AND p.`id` NOT IN (SELECT * FROM (SELECT `player_id` FROM `auction_lots`) already);


/* ------------------------------------------------------------------- */
/* 3. Check what you got                                                */
/* ------------------------------------------------------------------- */
SELECT l.`lot_order`      AS `lot`,
       p.`full_name`      AS `player`,
       p.`role`,
       p.`auction_set`    AS `set`,
       p.`base_price`     AS `base`,
       IF(p.`is_overseas` = 1, 'yes', '') AS `overseas`,
       l.`status`
  FROM `auction_lots` l
  JOIN `players` p ON p.`id` = l.`player_id`
 WHERE l.`tournament_id` = @tournament
 ORDER BY l.`lot_order`;


/*
   =====================================================================
   To undo this batch

   Removes only players that have never been sold and have no bids
   against them, so it cannot unpick a real auction. Run the two
   statements in this order.
   =====================================================================

   DELETE l FROM `auction_lots` l
     JOIN `players` p ON p.`id` = l.`player_id`
    WHERE p.`user_id` IS NULL
      AND l.`status` <> 'sold'
      AND NOT EXISTS (SELECT 1 FROM `auction_bids` b WHERE b.`lot_id` = l.`id`);

   DELETE FROM `players`
    WHERE `user_id` IS NULL
      AND `status` <> 'sold'
      AND `id` NOT IN (SELECT * FROM (SELECT `player_id` FROM `auction_lots`) keep);
*/
