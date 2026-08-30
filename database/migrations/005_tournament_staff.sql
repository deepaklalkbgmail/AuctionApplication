/*
   =====================================================================
   Tournament administrators, and scorers that belong to a tournament
   =====================================================================

   Two additions to the users table:

     role gains 'tournament_admin'
       Someone who runs one tournament: approves the applications for it,
       manages its teams and works its auction sheet. They cannot approve
       a player's ACCOUNT - that stays with an administrator - and they
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
   users.tournament_id for scorers who have none - but only when your
   database contains exactly one tournament, because only then is there
   no question which one they meant.

   If you have more than one tournament, step 5 does nothing and your
   scorers wait to be assigned by hand under
   Administration -> People -> Set. They can still sign in meanwhile;
   the scoring pad tells them to ask you.

   ---------------------------------------------------------------------
   WHY EVERY STATEMENT NAMES THE DATABASE

   phpMyAdmin follows the query. Run one that reads
   information_schema.COLUMNS and the panel switches to
   "Database: yours >> Table: COLUMNS" - and the statements after it in
   the same batch resolve their table names against information_schema
   instead of against your database. An earlier version of this file
   ended with a report that hit exactly that:

       #1109 - Unknown table 'users' in information_schema

   ...on a database whose users table was perfectly fine.

   So this file captures the database name ONCE, on the first line,
   before anything can move underneath it, and every statement after
   that spells out `yourdb`.`users`. Nothing here depends on which
   database phpMyAdmin thinks is current.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   PASTE THE WHOLE FILE, in one go. Every step reads @db, which the
   first line sets, and a variable lives on one connection only.

   SAFE TO RUN AS MANY TIMES AS YOU LIKE, and safe to run again after a
   run that stopped part way. Every step checks whether its work is
   already done and quietly skips itself, so a re-run raises no errors
   and stops nowhere.

   It prints almost nothing on purpose. To see where you stand, run
   005_verify.sql afterwards - it is read-only and reports both the
   installed pieces and your row counts.
   =====================================================================
*/


/* =====================================================================
   STEP 0 - Remember which database this is, and refuse to run without
   one.

   Opened from the SERVER level rather than from inside a database,
   DATABASE() is NULL. Every check would then report "missing" and every
   ALTER would fail, on a database that may be perfectly fine. So we stop
   with a sentence instead.
   ===================================================================== */
SET @db := DATABASE();

SET @guard := IF(
    @db IS NULL,
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''STOP - no database is selected. Click your database in the LEFT SIDEBAR, then open the SQL tab and paste this file again. Nothing has been changed.''',
    'DO 0'
);
PREPARE db_guard FROM @guard;
EXECUTE db_guard;
DEALLOCATE PREPARE db_guard;


/* ---------------------------------------------------------------------
   STEP 1. The new role, 'tournament_admin'.

   'DO 0' is MySQL's way of saying "do nothing"; it is what runs when the
   piece is already in place.
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%tournament\\_admin%') > 0,
    'DO 0',
    CONCAT('ALTER TABLE `', @db, '`.`users` MODIFY COLUMN `role` ',
           'ENUM(''admin'',''tournament_admin'',''team_owner'',''scorer'',''viewer'',''player'') ',
           'NOT NULL DEFAULT ''viewer''')
);
PREPARE step1 FROM @ddl;
EXECUTE step1;
DEALLOCATE PREPARE step1;


/* ---------------------------------------------------------------------
   STEP 2. Which tournament a scorer or tournament administrator belongs
   to.
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'tournament_id') > 0,
    'DO 0',
    CONCAT('ALTER TABLE `', @db, '`.`users` ',
           'ADD COLUMN `tournament_id` INT UNSIGNED NULL AFTER `team_id`')
);
PREPARE step2 FROM @ddl;
EXECUTE step2;
DEALLOCATE PREPARE step2;


/* ---------------------------------------------------------------------
   STEP 3. An index for "everyone who works on this tournament".
   --------------------------------------------------------------------- */
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
        AND INDEX_NAME = 'idx_users_tournament') > 0,
    'DO 0',
    CONCAT('ALTER TABLE `', @db, '`.`users` ',
           'ADD KEY `idx_users_tournament` (`tournament_id`, `role`)')
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
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
        AND CONSTRAINT_NAME = 'fk_users_tournament') > 0,
    'DO 0',
    CONCAT('ALTER TABLE `', @db, '`.`users` ',
           'ADD CONSTRAINT `fk_users_tournament` FOREIGN KEY (`tournament_id`) ',
           'REFERENCES `', @db, '`.`tournaments` (`id`) ',
           'ON DELETE SET NULL ON UPDATE CASCADE')
);
PREPARE step4 FROM @ddl;
EXECUTE step4;
DEALLOCATE PREPARE step4;


/* =====================================================================
   STEP 5 - Keep your existing scorers working.

   THIS IS THE ONLY STATEMENT IN THE FILE THAT CHANGES A VALUE, and the
   only column it writes is the one step 2 created.

   A scorer now scores one tournament. Left unassigned they can sign in
   and read but not record, so an organiser upgrading in the middle of a
   season would find their scorer locked out.

   The guard is the last line: this runs only when the database holds
   exactly ONE tournament, where there is no ambiguity about which one
   was meant. With two or more it matches nothing and you assign them
   yourself under Administration -> People -> Set.

   Expect: as many rows as you have unassigned scorers, or 0.
   Safe to repeat - the second run finds nothing left to do.
   ===================================================================== */
SET @ddl := CONCAT(
    'UPDATE `', @db, '`.`users` ',
       'SET `tournament_id` = (SELECT `id` FROM `', @db, '`.`tournaments` ORDER BY `id` LIMIT 1) ',
     'WHERE `role` IN (''scorer'', ''tournament_admin'') ',
       'AND `tournament_id` IS NULL ',
       'AND (SELECT COUNT(*) FROM `', @db, '`.`tournaments`) = 1'
);
PREPARE step5 FROM @ddl;
EXECUTE step5;
DEALLOCATE PREPARE step5;


/* =====================================================================
   STEP 6 - Done. All four should read OK.

   This is the LAST statement in the file, and the only one that reads
   information_schema outside a SET. Nothing follows it, so nothing can
   be caught out by phpMyAdmin switching the panel to
   "Table: COLUMNS" afterwards.

   For your row counts before and after, run 005_verify.sql.
   ===================================================================== */
SELECT 'users.role has tournament_admin' AS `check`,
       IF(COUNT(*) = 1, 'OK', 'MISSING') AS `result`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
   AND COLUMN_TYPE LIKE '%tournament\\_admin%'
UNION ALL
SELECT 'users.tournament_id exists',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tournament_id'
UNION ALL
SELECT 'index idx_users_tournament',
       IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_tournament'
UNION ALL
SELECT 'foreign key fk_users_tournament',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
   AND CONSTRAINT_NAME = 'fk_users_tournament';
