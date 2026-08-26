/*
   =====================================================================
   Split "all-rounder" into batting and bowling all-rounder
   =====================================================================

   A team buying a middle-order batter who bowls four overs is not buying
   the same player as a fourth seamer who can bat. One label for both hid
   the difference at exactly the moment it mattered — while the player was
   being called.

   Two columns hold the kind of player and both change here:

     users.player_type    what somebody registered as
     players.role         what they were approved into the auction as

   WHAT HAPPENS TO EXISTING PLAYERS
   Anyone already recorded as 'all_rounder' becomes a BATTING all-rounder.
   There is no way to tell from the data which they were, and batting is
   the commoner reading. Correct any that are wrong afterwards:
   Administration -> People -> Edit details, or Applications before they
   are approved.

   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   Safe to run twice. Every statement is idempotent: the second pass
   widens an already-wide column, updates no rows, and narrows it back.

   Nothing is dropped and no row is deleted.
   =====================================================================
*/

/* ---------------------------------------------------------------------
   1. Widen both columns so old and new values are legal at once.
      A single ALTER straight to the final list would be refused while
      rows still hold 'all_rounder'.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    MODIFY COLUMN `player_type`
        ENUM('batsman','bowler','all_rounder','batting_all_rounder',
             'bowling_all_rounder','wicket_keeper') NULL;

ALTER TABLE `players`
    MODIFY COLUMN `role`
        ENUM('batsman','bowler','all_rounder','batting_all_rounder',
             'bowling_all_rounder','wicket_keeper') NOT NULL;


/* ---------------------------------------------------------------------
   2. Move everybody across.
   --------------------------------------------------------------------- */
UPDATE `users`
   SET `player_type` = 'batting_all_rounder'
 WHERE `player_type` = 'all_rounder';

UPDATE `players`
   SET `role` = 'batting_all_rounder'
 WHERE `role` = 'all_rounder';


/* ---------------------------------------------------------------------
   3. Narrow to the five kinds the application knows about, so nothing
      can write the old value again.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    MODIFY COLUMN `player_type`
        ENUM('batsman','batting_all_rounder','bowling_all_rounder',
             'wicket_keeper','bowler') NULL;

ALTER TABLE `players`
    MODIFY COLUMN `role`
        ENUM('batsman','batting_all_rounder','bowling_all_rounder',
             'wicket_keeper','bowler') NOT NULL;


/* ---------------------------------------------------------------------
   4. Check it worked. Both rows should read OK.
   --------------------------------------------------------------------- */
SET @schema := DATABASE();

SELECT 'reading database' AS `check`, IFNULL(@schema, '*** none selected ***') AS `result`
UNION ALL
SELECT 'users.player_type',
       IF(COLUMN_TYPE LIKE '%batting\\_all\\_rounder%'
          AND COLUMN_TYPE NOT LIKE '%''all\\_rounder''%', 'OK', CONCAT('WRONG: ', COLUMN_TYPE))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'player_type'
UNION ALL
SELECT 'players.role',
       IF(COLUMN_TYPE LIKE '%bowling\\_all\\_rounder%'
          AND COLUMN_TYPE NOT LIKE '%''all\\_rounder''%', 'OK', CONCAT('WRONG: ', COLUMN_TYPE))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'players' AND COLUMN_NAME = 'role'
UNION ALL
SELECT 'batting all-rounders now', CAST(COUNT(*) AS CHAR)
  FROM `players` WHERE `role` = 'batting_all_rounder'
UNION ALL
SELECT 'bowling all-rounders now', CAST(COUNT(*) AS CHAR)
  FROM `players` WHERE `role` = 'bowling_all_rounder';
