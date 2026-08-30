/*
   =====================================================================
   Where does migration 005 actually stand?
   =====================================================================

   READ-ONLY. This file changes nothing. Run it any time.

   ---------------------------------------------------------------------
   IT NAMES YOUR DATABASE ON THE FIRST LINE, ON PURPOSE

   The obvious way to write this check is to key it on DATABASE(). That
   is a trap: open phpMyAdmin's SQL tab from the SERVER level rather than
   from inside a database and DATABASE() is NULL, every row reports
   "NO", and a database that is perfectly fine looks like one that was
   never migrated.

   So the database name is typed in below instead. Set it once, and the
   answer cannot depend on where you happened to click.

   >>> EDIT THIS LINE if your database is not called deamco_APL <<<
   =====================================================================
*/
SET @db := 'deamco_APL';


/* ---------------------------------------------------------------------
   The report.

   'yes' on all four = migration 005 is fully installed.
   Any 'NO'          = run 005_tournament_staff.sql again; it will add
                       only the missing pieces and skip the rest.

   'database exists' reading NO means the name on the line above is
   wrong — check the spelling against the left sidebar. It is
   case-sensitive on most servers.
   --------------------------------------------------------------------- */
SELECT 'database exists' AS piece,
       IF(COUNT(*) = 1, CONCAT('yes  (', @db, ')'), CONCAT('NO  - no database called "', @db, '"')) AS present
  FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = @db

UNION ALL
SELECT 'users table',
       IF(COUNT(*) = 1, 'yes', 'NO')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'

UNION ALL
SELECT 'role has tournament_admin',
       IFNULL(MAX(IF(COLUMN_TYPE LIKE '%tournament\\_admin%', 'yes', 'NO')), 'NO - no such column')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'

UNION ALL
SELECT 'tournament_id column',
       IF(COUNT(*) = 1, 'yes', 'NO')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tournament_id'

UNION ALL
SELECT 'index idx_users_tournament',
       IF(COUNT(*) > 0, 'yes', 'NO')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_tournament'

UNION ALL
SELECT 'foreign key fk_users_tournament',
       IF(COUNT(*) = 1, 'yes', 'NO')
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_tournament'

UNION ALL
SELECT 'activity_log table (migration 006)',
       IF(COUNT(*) = 1, 'yes', 'NO - run 006_activity_log.sql')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'activity_log';
