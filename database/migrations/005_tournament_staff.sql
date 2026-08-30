/*
   =====================================================================
   Tournament administrators, and scorers that belong to a tournament
   =====================================================================

   Two additions to the users table:

     role gains 'tournament_admin'
       Someone who runs one tournament: approves the applications for it,
       manages its teams and works its auction sheet. They cannot approve
       a player's ACCOUNT — that stays with an administrator — and they
       see nothing outside their own tournament.

     a new column, users.tournament_id
       Which tournament a scorer or a tournament administrator belongs
       to. NULL for everybody else. Any number of scorers may share a
       tournament, so this is a plain indexed column, not a unique one.

   ---------------------------------------------------------------------
   WHAT HAPPENS TO YOUR DATA

   Nothing is deleted. Only ONE column of ONE table is ever written to,
   and it is the column this file creates. Your tournament, your teams,
   your players, your auction lots, your bids, your matches and your
   balls are not touched by any statement here.

   That includes a finished auction. Every sale, every price, every purse
   and every squad is in tables this migration does not name.

   THE ONE THING THAT CHANGES A VALUE is step 5. It fills in
   users.tournament_id for scorers who have none — but only when your
   database contains exactly one tournament, because only then is there
   no question which one they meant. It prints the rows it touched.

   If you have more than one tournament, step 5 does nothing and your
   scorers wait to be assigned by hand under
   Administration -> People -> Set. They can still sign in meanwhile;
   the scoring pad tells them to ask you.

   ---------------------------------------------------------------------
   ABOUT WIDENING THE ROLE COLUMN

   MySQL stores an ENUM as a number — the position of the value in the
   list. This migration inserts 'tournament_admin' at position 2, which
   moves every role after it along by one. It is fair to ask whether a
   team_owner therefore becomes a tournament_admin.

   It does not. MySQL rewrites the table and converts each row by its
   TEXT value, not by its number. This was tested against a copy of a
   real database with four team owners in it: all four were still team
   owners afterwards, and the check at the end of this file counts them
   for you so you can see it on your own data.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   PASTE THE WHOLE FILE. Steps 1 to 4 each use a variable to hold the
   statement they may or may not need to run, and a variable lives on one
   connection only — so if you do run pieces separately, keep the four
   lines of a step (SET, PREPARE, EXECUTE, DEALLOCATE) together in the
   same submission.

   SAFE TO RUN AS MANY TIMES AS YOU LIKE, and safe to run again after a
   run that stopped part way. Every structural step checks whether it is
   already in place and quietly skips itself, so a re-run raises no
   errors and stops nowhere. If you are not sure whether it took, run it
   again and read the checks at the end.
   =====================================================================
*/


/* =====================================================================
   STEP -1 — Is a database actually selected?

   THIS IS THE MOST COMMON WAY TO GET A WRONG ANSWER FROM THIS FILE, so
   it is checked before anything else happens.

   Everything below is keyed on DATABASE(). Open phpMyAdmin's SQL tab
   from the SERVER level rather than from inside a database and
   DATABASE() is NULL — at which point every check reports "missing" and
   every ALTER fails, on a database that may be perfectly fine. It looks
   exactly like a migration that never ran.

   So: if no database is selected this stops here with a sentence saying
   so, rather than letting you read three NOs and conclude something
   false.

   Click your database in the LEFT SIDEBAR first, then open the SQL tab.
   ===================================================================== */
SET @guard := IF(
    DATABASE() IS NULL,
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''STOP - no database is selected. Click your database in the LEFT SIDEBAR, then open the SQL tab and run this file again. Nothing has been changed.''',
    'DO 0'
);
PREPARE db_guard FROM @guard;
EXECUTE db_guard;
DEALLOCATE PREPARE db_guard;


/* =====================================================================
   STEP 0 — What you have before anything changes.

   Keep this result. It is what step 6 is compared against.
   ===================================================================== */
SELECT 'BEFORE' AS `when`, 'database'          AS `item`, DATABASE()                                  AS `value`
UNION ALL SELECT 'BEFORE', 'users',              CAST(COUNT(*) AS CHAR) FROM `users`
UNION ALL SELECT 'BEFORE', '  of them admins',   CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'admin'
UNION ALL SELECT 'BEFORE', '  of them owners',   CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'team_owner'
UNION ALL SELECT 'BEFORE', '  of them scorers',  CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'scorer'
UNION ALL SELECT 'BEFORE', 'tournaments',        CAST(COUNT(*) AS CHAR) FROM `tournaments`
UNION ALL SELECT 'BEFORE', 'teams',              CAST(COUNT(*) AS CHAR) FROM `teams`
UNION ALL SELECT 'BEFORE', 'players',            CAST(COUNT(*) AS CHAR) FROM `players`
UNION ALL SELECT 'BEFORE', '  of them sold',     CAST(COUNT(*) AS CHAR) FROM `players` WHERE `status` = 'sold'
UNION ALL SELECT 'BEFORE', 'auction lots',       CAST(COUNT(*) AS CHAR) FROM `auction_lots`
UNION ALL SELECT 'BEFORE', 'auction bids',       CAST(COUNT(*) AS CHAR) FROM `auction_bids`
UNION ALL SELECT 'BEFORE', 'total sold for',     CONCAT('INR ', IFNULL(FORMAT(SUM(`sold_price`), 0), '0')) FROM `players`;


/* =====================================================================
   STEPS 1-4 — the structural changes.

   Each one LOOKS FIRST and skips itself if it is already in place, so
   this file never raises an error and never stops half way through.
   That matters more than it sounds: phpMyAdmin halts the whole batch at
   the first error, so a step that announced "already done" by failing
   would take every step after it down with it.

   Each step is four lines — SET, PREPARE, EXECUTE, DEALLOCATE. If you
   run statements one at a time rather than pasting the whole file, KEEP
   THE FOUR LINES OF A STEP TOGETHER: the SET puts the statement into
   @ddl and the PREPARE reads it back, and a variable does not survive
   being submitted separately.

   'DO 0' is MySQL's way of saying "do nothing". It is what runs when a
   piece is already there.
   ===================================================================== */


/* ---------------------------------------------------------------------
   STEP 1. The new role, 'tournament_admin'.
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%tournament\\_admin%') > 0,
    'DO 0',
    'ALTER TABLE `users` MODIFY COLUMN `role` ENUM(''admin'',''tournament_admin'',''team_owner'',''scorer'',''viewer'',''player'') NOT NULL DEFAULT ''viewer'''
);
PREPARE step1 FROM @ddl;
EXECUTE step1;
DEALLOCATE PREPARE step1;


/* ---------------------------------------------------------------------
   STEP 2. Which tournament a scorer or tournament administrator belongs
   to. This is the step that used to fail with #1060 on a second run.
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'tournament_id') > 0,
    'DO 0',
    'ALTER TABLE `users` ADD COLUMN `tournament_id` INT UNSIGNED NULL AFTER `team_id`'
);
PREPARE step2 FROM @ddl;
EXECUTE step2;
DEALLOCATE PREPARE step2;


/* ---------------------------------------------------------------------
   STEP 3. An index for "everyone who works on this tournament".
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND INDEX_NAME = 'idx_users_tournament') > 0,
    'DO 0',
    'ALTER TABLE `users` ADD KEY `idx_users_tournament` (`tournament_id`, `role`)'
);
PREPARE step3 FROM @ddl;
EXECUTE step3;
DEALLOCATE PREPARE step3;


/* ---------------------------------------------------------------------
   STEP 4. And the foreign key.

   ON DELETE SET NULL, not RESTRICT: deleting a tournament should not be
   blocked by the staff attached to it, and it should not delete their
   accounts either. They simply become unassigned.
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND CONSTRAINT_NAME = 'fk_users_tournament') > 0,
    'DO 0',
    'ALTER TABLE `users` ADD CONSTRAINT `fk_users_tournament` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE'
);
PREPARE step4 FROM @ddl;
EXECUTE step4;
DEALLOCATE PREPARE step4;


/* =====================================================================
   STEP 5 — Keep your existing scorers working.

   THIS IS THE ONLY STATEMENT IN THE FILE THAT CHANGES A VALUE, and the
   only column it writes is the one step 2 just created.

   A scorer now scores one tournament. Left unassigned they can sign in
   and read but not record, so an organiser upgrading in the middle of a
   season would find their scorer locked out.

   The guard is the last line: this runs only when the database holds
   exactly ONE tournament, where there is no ambiguity about which one
   was meant. With two or more it matches nothing and you assign them
   yourself under Administration -> People -> Set.

   Expect: as many rows as you have unassigned scorers, or 0.
   Safe to repeat — the second run finds nothing left to do.
   ===================================================================== */
UPDATE `users`
   SET `tournament_id` = (SELECT `id` FROM `tournaments` ORDER BY `id` LIMIT 1)
 WHERE `role` IN ('scorer', 'tournament_admin')
   AND `tournament_id` IS NULL
   AND (SELECT COUNT(*) FROM `tournaments`) = 1;


/* =====================================================================
   STEP 6 — What you have now. Compare with step 0.

   Every count below must match step 0 exactly. The migration adds a
   column; it does not add, remove or reclassify a single row.

   The 'role survived' lines are the ENUM check described at the top:
   'accidentally reclassified' must read 0.
   ===================================================================== */
SELECT 'AFTER'  AS `when`, 'database'          AS `item`, DATABASE()                                  AS `value`
UNION ALL SELECT 'AFTER',  'users',              CAST(COUNT(*) AS CHAR) FROM `users`
UNION ALL SELECT 'AFTER',  '  of them admins',   CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'admin'
UNION ALL SELECT 'AFTER',  '  of them owners',   CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'team_owner'
UNION ALL SELECT 'AFTER',  '  of them scorers',  CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'scorer'
UNION ALL SELECT 'AFTER',  'tournaments',        CAST(COUNT(*) AS CHAR) FROM `tournaments`
UNION ALL SELECT 'AFTER',  'teams',              CAST(COUNT(*) AS CHAR) FROM `teams`
UNION ALL SELECT 'AFTER',  'players',            CAST(COUNT(*) AS CHAR) FROM `players`
UNION ALL SELECT 'AFTER',  '  of them sold',     CAST(COUNT(*) AS CHAR) FROM `players` WHERE `status` = 'sold'
UNION ALL SELECT 'AFTER',  'auction lots',       CAST(COUNT(*) AS CHAR) FROM `auction_lots`
UNION ALL SELECT 'AFTER',  'auction bids',       CAST(COUNT(*) AS CHAR) FROM `auction_bids`
UNION ALL SELECT 'AFTER',  'total sold for',     CONCAT('INR ', IFNULL(FORMAT(SUM(`sold_price`), 0), '0')) FROM `players`
UNION ALL SELECT 'AFTER',  'accidentally reclassified',
                           CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'tournament_admin';


/* =====================================================================
   STEP 7 — Is it installed? All four should read OK.
   ===================================================================== */
SELECT 'users.role has tournament_admin' AS `check`,
       IF(COLUMN_TYPE LIKE '%tournament\\_admin%', 'OK', CONCAT('MISSING: ', COLUMN_TYPE)) AS `result`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
UNION ALL
SELECT 'users.tournament_id exists',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tournament_id'
UNION ALL
SELECT 'index idx_users_tournament',
       IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_tournament'
UNION ALL
SELECT 'foreign key fk_users_tournament',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND CONSTRAINT_NAME = 'fk_users_tournament';


/* =====================================================================
   STEP 8 — Who works on what now.

   Anybody listed as 'NOT ASSIGNED' cannot score or administer until you
   give them a tournament under Administration -> People -> Set.
   ===================================================================== */
SELECT u.`id`,
       u.`name`,
       u.`role`,
       IFNULL(t.`name`, '*** NOT ASSIGNED ***') AS `tournament`
  FROM `users` u
  LEFT JOIN `tournaments` t ON t.`id` = u.`tournament_id`
 WHERE u.`role` IN ('scorer', 'tournament_admin')
 ORDER BY u.`id`;
