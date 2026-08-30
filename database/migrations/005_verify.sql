/*
   =====================================================================
   Where do migrations 005 and 006 stand, and did the data move?
   =====================================================================

   READ-ONLY. This file changes nothing. Run it any time - before a
   migration, after one, or when you are simply not sure.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   Nothing here is a prepared statement. phpMyAdmin cannot parse
   EXECUTE, so a prepared statement that returns rows makes it print

       Warning ... Undefined array key "statement"
       Warning ... Undefined array key "parser"
       Warning ... Attempt to read property "list" on null

   while it tries to build the column sort links. The rows come back
   anyway, but it reads like something broke. Plain SQL avoids it, and
   plain SQL is enough here: the database name is only ever compared as a
   VALUE, never used as a table name.

   ---------------------------------------------------------------------
   AFTER RUNNING THIS, CLICK YOUR DATABASE AGAIN

   This file has to end on information_schema - that is where the
   "what is installed" answer lives, and it has to come after the parts
   that read your own tables. phpMyAdmin then parks itself on
   "Table: COLUMNS" and carries that into the next request.

   So before you run anything else, click your database in the LEFT
   SIDEBAR again. Forget, and the next file stops with

       #1644 - STOP - the selected database is information_schema

   which is the guard doing its job rather than anything being wrong.
   The migrations themselves no longer end this way; only this file
   does, because only this file has to.

   ---------------------------------------------------------------------
   READING THE RESULT

   Three tables come back.

     1. what is installed   every row should read 'yes' once both
                            migrations have run
     2. your data           run this before AND after a migration; every
                            number must be identical. 'reclassified as
                            tournament_admin' must read 0 until you
                            deliberately create one
     3. who works on what   anybody shown as NOT ASSIGNED cannot score or
                            administer until you give them a tournament
                            under Administration -> People -> Set
   =====================================================================
*/


/* =====================================================================
   STEP 0 - Refuse to answer about the wrong database.

   Everything below is keyed on DATABASE(). Open the SQL tab from the
   SERVER level and DATABASE() is NULL; land on it straight after a query
   that read information_schema and DATABASE() is that instead. Either
   way every row would read 'no' for a database that is perfectly fine -
   which is exactly the false alarm this file exists to prevent.

   This is the one prepared statement in the file, and it returns no
   rows, so phpMyAdmin has nothing to choke on.
   ===================================================================== */
SET @db := DATABASE();

SET @guard := IF(
    @db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
    CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''STOP - the selected database is ',
           IFNULL(@db, 'none at all'),
           ', which is not yours. Click YOUR database in the LEFT SIDEBAR, then run this file again.'''),
    'DO 0'
);
PREPARE db_guard FROM @guard;
EXECUTE db_guard;
DEALLOCATE PREPARE db_guard;


/* =====================================================================
   1 - YOUR DATA.

   This runs FIRST, on purpose. It reads your own tables, and it has to
   do that while the current database is still yours - the checks in
   part 3 read information_schema, and phpMyAdmin follows the query.

   Run this file before and after a migration and compare. Nothing here
   should change: the migrations add a column and a table, they do not
   add, remove or reclassify a single row.
   ===================================================================== */
SELECT 'users'                            AS item, CAST(COUNT(*) AS CHAR) AS n FROM `users`
UNION ALL SELECT '  of them admins',            CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'admin'
UNION ALL SELECT '  of them owners',            CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'team_owner'
UNION ALL SELECT '  of them scorers',           CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'scorer'
UNION ALL SELECT 'reclassified as tournament_admin',
                                                CAST(COUNT(*) AS CHAR) FROM `users` WHERE `role` = 'tournament_admin'
UNION ALL SELECT 'tournaments',                 CAST(COUNT(*) AS CHAR) FROM `tournaments`
UNION ALL SELECT 'teams',                       CAST(COUNT(*) AS CHAR) FROM `teams`
UNION ALL SELECT 'players',                     CAST(COUNT(*) AS CHAR) FROM `players`
UNION ALL SELECT '  of them sold',              CAST(COUNT(*) AS CHAR) FROM `players` WHERE `status` = 'sold'
UNION ALL SELECT 'auction lots',                CAST(COUNT(*) AS CHAR) FROM `auction_lots`
UNION ALL SELECT 'auction bids',                CAST(COUNT(*) AS CHAR) FROM `auction_bids`
UNION ALL SELECT 'total sold for',
                 CONCAT('INR ', IFNULL(FORMAT(SUM(`sold_price`), 0), '0')) FROM `players`;


/* =====================================================================
   2 - WHO WORKS ON WHAT.

   Still your own tables, so still before part 3.
   An empty result means you have no scorers or tournament
   administrators yet, which is fine.
   ===================================================================== */
SELECT u.`id`,
       u.`name`,
       u.`role`,
       IFNULL(t.`name`, '*** NOT ASSIGNED ***') AS `tournament`
  FROM `users` u
  LEFT JOIN `tournaments` t ON t.`id` = u.`tournament_id`
 WHERE u.`role` IN ('scorer', 'tournament_admin')
 ORDER BY u.`id`;


/* =====================================================================
   3 - WHAT IS INSTALLED.

   LAST, because these read information_schema and phpMyAdmin will
   switch the panel to "Table: COLUMNS" afterwards. Nothing follows
   them, so nothing can be caught out by it.

   All seven 'yes' = both migrations are fully installed.
   Any 'no'        = run that migration again; it adds only the missing
                     pieces and skips the rest.
   ===================================================================== */
SELECT 'database being checked' AS piece, @db AS present
UNION ALL
SELECT 'users table',
       IF(COUNT(*) = 1, 'yes', 'no')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
UNION ALL
SELECT '005: role has tournament_admin',
       IF(COUNT(*) = 1, 'yes', 'no')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
   AND COLUMN_TYPE LIKE '%tournament\\_admin%'
UNION ALL
SELECT '005: tournament_id column',
       IF(COUNT(*) = 1, 'yes', 'no')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tournament_id'
UNION ALL
SELECT '005: index idx_users_tournament',
       IF(COUNT(*) > 0, 'yes', 'no')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_tournament'
UNION ALL
SELECT '005: foreign key fk_users_tournament',
       IF(COUNT(*) = 1, 'yes', 'no')
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
   AND CONSTRAINT_NAME = 'fk_users_tournament'
UNION ALL
SELECT '006: activity_log table',
       IF(COUNT(*) = 1, 'yes', 'no')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'activity_log';
