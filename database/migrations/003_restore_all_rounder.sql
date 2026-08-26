/*
   =====================================================================
   Put plain "All-rounder" back, alongside the two leaning ones
   =====================================================================

   There are now SIX kinds of player:

       Batsman
       Batting all-rounder
       All-rounder            <- this file adds it back
       Bowling all-rounder
       Wicket-keeper
       Bowler

   The two leaning kinds stay: a middle-order batter who bowls four overs
   is not a fourth seamer who can bat. Plain "all-rounder" is for the
   genuine article who is neither more one nor the other, and for anyone
   registering who does not want to claim a leaning.

   ---------------------------------------------------------------------
   WHICH FILES YOU NEED TO RUN

   If you have NOT yet run 002_split_all_rounder.sql:
       run this file ONLY. It reaches the same six-value list from the
       original three, and — unlike 002 — it changes no rows, so anyone
       already recorded as an all-rounder simply stays one. Skip 002
       entirely.

   If you HAVE already run 002:
       run this file. Note that 002 turned every existing all-rounder
       into a BATTING all-rounder. Anyone who should be plain
       "All-rounder" can be put back under
       Administration -> People -> Edit details.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   Safe to run twice, and safe to run from either starting point: it is
   two MODIFY statements to the final list and nothing else. No row is
   read, changed or deleted.
   =====================================================================
*/

ALTER TABLE `users`
    MODIFY COLUMN `player_type`
        ENUM('batsman','batting_all_rounder','all_rounder',
             'bowling_all_rounder','wicket_keeper','bowler') NULL;

ALTER TABLE `players`
    MODIFY COLUMN `role`
        ENUM('batsman','batting_all_rounder','all_rounder',
             'bowling_all_rounder','wicket_keeper','bowler') NOT NULL;


/* ---------------------------------------------------------------------
   Check it worked. Both rows should read OK.
   --------------------------------------------------------------------- */
SET @schema := DATABASE();

SELECT 'reading database' AS `check`, IFNULL(@schema, '*** none selected ***') AS `result`
UNION ALL
SELECT 'users.player_type',
       IF(COLUMN_TYPE LIKE '%''all\\_rounder''%'
          AND COLUMN_TYPE LIKE '%batting\\_all\\_rounder%'
          AND COLUMN_TYPE LIKE '%bowling\\_all\\_rounder%', 'OK', CONCAT('WRONG: ', COLUMN_TYPE))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'player_type'
UNION ALL
SELECT 'players.role',
       IF(COLUMN_TYPE LIKE '%''all\\_rounder''%'
          AND COLUMN_TYPE LIKE '%batting\\_all\\_rounder%'
          AND COLUMN_TYPE LIKE '%bowling\\_all\\_rounder%', 'OK', CONCAT('WRONG: ', COLUMN_TYPE))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'players' AND COLUMN_NAME = 'role'
UNION ALL
SELECT 'all-rounders (plain)',      CAST(COUNT(*) AS CHAR) FROM `players` WHERE `role` = 'all_rounder'
UNION ALL
SELECT 'batting all-rounders',      CAST(COUNT(*) AS CHAR) FROM `players` WHERE `role` = 'batting_all_rounder'
UNION ALL
SELECT 'bowling all-rounders',      CAST(COUNT(*) AS CHAR) FROM `players` WHERE `role` = 'bowling_all_rounder';
