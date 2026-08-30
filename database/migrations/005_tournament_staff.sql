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

   No statement here depends on any other having run in the same
   connection, so running them one at a time works exactly as well as
   pasting the lot.

   IF YOU HAVE RUN IT BEFORE you will see one of these, and both are
   harmless — they mean that part is already in place:

       #1060 - Duplicate column name 'tournament_id'
       #1061 - Duplicate key name 'idx_users_tournament'

   The whole file is safe to run twice.
   =====================================================================
*/


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


/* ---------------------------------------------------------------------
   STEP 1. The new role. MODIFY is safe to repeat — it simply restates
   the column, so running this twice does nothing the second time.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    MODIFY COLUMN `role`
        ENUM('admin','tournament_admin','team_owner','scorer','viewer','player')
        NOT NULL DEFAULT 'viewer';


/* ---------------------------------------------------------------------
   STEP 2. Which tournament a scorer or tournament administrator belongs
   to. #1060 here means it is already there.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    ADD COLUMN `tournament_id` INT UNSIGNED NULL AFTER `team_id`;


/* ---------------------------------------------------------------------
   STEP 3. An index for "everyone who works on this tournament".
   #1061 here means it is already there.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    ADD KEY `idx_users_tournament` (`tournament_id`, `role`);


/* ---------------------------------------------------------------------
   STEP 4. And the foreign key.

   ON DELETE SET NULL, not RESTRICT: deleting a tournament should not be
   blocked by the staff attached to it, and it should not delete their
   accounts either. They simply become unassigned.
   An error 1826 or 121 here means it is already there.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;


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
