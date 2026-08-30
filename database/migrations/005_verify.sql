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

/* ---------------------------------------------------------------------
   Your data, so this file also serves as the before-and-after check.

   Run it BEFORE the migration and again AFTER. Every number must be
   identical: the migration adds a column, it does not add, remove or
   reclassify a single row.

   'reclassified as tournament_admin' is the ENUM check. MySQL stores an
   ENUM as a position, and migration 005 inserts 'tournament_admin' at
   position 2, moving every role after it along by one. MySQL converts
   by text rather than by position, so nothing is reclassified - this
   line proves it on your own data, and must read 0 until you actually
   create a tournament administrator.

   Fully qualified with @db, like everything above, so it does not
   matter which database phpMyAdmin currently thinks is selected.
   --------------------------------------------------------------------- */
SET @counts := CONCAT(
    'SELECT ''users'' AS item, CAST(COUNT(*) AS CHAR) AS n FROM `', @db, '`.`users` ',
    'UNION ALL SELECT ''  of them admins'',  CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`users` WHERE `role` = ''admin'' ',
    'UNION ALL SELECT ''  of them owners'',  CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`users` WHERE `role` = ''team_owner'' ',
    'UNION ALL SELECT ''  of them scorers'', CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`users` WHERE `role` = ''scorer'' ',
    'UNION ALL SELECT ''reclassified as tournament_admin'', CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`users` WHERE `role` = ''tournament_admin'' ',
    'UNION ALL SELECT ''tournaments'',   CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`tournaments` ',
    'UNION ALL SELECT ''teams'',         CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`teams` ',
    'UNION ALL SELECT ''players'',       CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`players` ',
    'UNION ALL SELECT ''  of them sold'', CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`players` WHERE `status` = ''sold'' ',
    'UNION ALL SELECT ''auction lots'',   CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`auction_lots` ',
    'UNION ALL SELECT ''auction bids'',   CAST(COUNT(*) AS CHAR) FROM `', @db, '`.`auction_bids` ',
    'UNION ALL SELECT ''total sold for'', CONCAT(''INR '', IFNULL(FORMAT(SUM(`sold_price`), 0), ''0'')) FROM `', @db, '`.`players`'
);
PREPARE counts FROM @counts;
EXECUTE counts;
DEALLOCATE PREPARE counts;


/* ---------------------------------------------------------------------
   Who works on which tournament.

   Anybody listed as NOT ASSIGNED cannot score or administer until you
   give them a tournament under Administration -> People -> Set.
   An empty result means you have no scorers or tournament
   administrators yet.
   --------------------------------------------------------------------- */
SET @staff := CONCAT(
    'SELECT u.`id`, u.`name`, u.`role`, ',
           'IFNULL(t.`name`, ''*** NOT ASSIGNED ***'') AS `tournament` ',
      'FROM `', @db, '`.`users` u ',
      'LEFT JOIN `', @db, '`.`tournaments` t ON t.`id` = u.`tournament_id` ',
     'WHERE u.`role` IN (''scorer'', ''tournament_admin'') ',
     'ORDER BY u.`id`'
);
PREPARE staff FROM @staff;
EXECUTE staff;
DEALLOCATE PREPARE staff;
