/*
   =====================================================================
   An activity log — who changed what, and what it was before
   =====================================================================

   One new table. Nothing else is touched: no existing table is altered,
   no existing row is read, written, moved or deleted. If you ran this
   against a database in the middle of an auction, the auction would not
   notice.

   ---------------------------------------------------------------------
   WHAT IT IS FOR

   Every administrative change now writes a line here first: approving
   somebody, editing a player, moving a base price, selling a lot,
   handing a team to a new owner, cancelling a tournament.

   Each line carries the fields that actually moved, with their old and
   new values. So "who dropped that base price, and what was it before?"
   is a question the application can answer, rather than one that needs
   last night's backup.

   You read it at  Administration -> Activity.

   The same line is also written to the server's PHP error log, so it is
   there even if the database is the thing that went wrong. On cPanel
   that is the error_log file in your account's root or in public_html.

   ---------------------------------------------------------------------
   WHY IT HAS NO FOREIGN KEYS

   On purpose. A log whose rows vanish when the thing they describe is
   deleted is not a log — the moment you most want to know who removed
   the team is exactly the moment a foreign key would have removed the
   evidence with it. The actor's name is copied in for the same reason.

   Nothing in the application ever updates or deletes a row here.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   SAFE TO RUN AS MANY TIMES AS YOU LIKE. The table is created only if
   it is absent and the first log line only if it is not already there,
   and every statement names your database rather than inheriting
   whichever one phpMyAdmin happens to be showing.
   =====================================================================
*/


/* =====================================================================
   STEP -1 — Is a database actually selected?

   THIS IS THE MOST COMMON WAY TO GET A WRONG ANSWER FROM THIS FILE, so
   it is checked before anything else happens. A system schema counts as
   'wrong' here, not only no schema at all.

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
SET @db := DATABASE();

SET @guard := IF(
    @db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
    CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''STOP - the selected database is ',
           IFNULL(@db, 'none at all'),
           ', which is not yours. Click YOUR database in the LEFT SIDEBAR, then run this file again. Nothing has been changed.'''),
    'DO 0'
);
PREPARE db_guard FROM @guard;
EXECUTE db_guard;
DEALLOCATE PREPARE db_guard;


/* ---------------------------------------------------------------------
   1. The table.

   Built as a string and prepared, so the database name is spelled out
   rather than inherited. Run this file a second time and phpMyAdmin may
   still be parked on "Table: TABLES" from the first run's last
   statement, at which point a bare CREATE TABLE means
   information_schema.activity_log and you get

       #1044 - Access denied ... to database 'information_schema'

   Naming the database removes the question.
   --------------------------------------------------------------------- */
SET @ddl := CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.`activity_log` (',
        '`id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,',
        '`at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,',
        '`actor_user_id` INT UNSIGNED     NULL,',
        '`actor_name`    VARCHAR(120) NOT NULL DEFAULT ''system'',',
        '`actor_role`    VARCHAR(40)  NOT NULL DEFAULT ''system'',',
        '`action`        VARCHAR(40)  NOT NULL,',
        '`subject_type`  VARCHAR(30)  NOT NULL,',
        '`subject_id`    INT UNSIGNED     NULL,',
        '`subject_label` VARCHAR(160) NOT NULL DEFAULT '''',',
        '`tournament_id` INT UNSIGNED     NULL,',
        '`changes`       TEXT             NULL,',
        '`note`          VARCHAR(255)     NULL,',
        '`ip`            VARCHAR(45)      NULL,',
        'PRIMARY KEY (`id`),',
        'KEY `idx_log_at`         (`at`),',
        'KEY `idx_log_tournament` (`tournament_id`, `at`),',
        'KEY `idx_log_subject`    (`subject_type`, `subject_id`, `at`),',
        'KEY `idx_log_actor`      (`actor_user_id`, `at`)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE make_log FROM @ddl;
EXECUTE make_log;
DEALLOCATE PREPARE make_log;


/* ---------------------------------------------------------------------
   2. A first line, so the log is never an empty screen and so you have
      a dated marker for when logging began. This is the only row this
      file writes, and it writes it to the table it just created.

      Named the same way, and guarded so a second run does not add a
      second marker.
   --------------------------------------------------------------------- */
SET @ddl := CONCAT(
    'INSERT INTO `', @db, '`.`activity_log` ',
        '(`actor_name`, `actor_role`, `action`, `subject_type`, `subject_label`, `note`) ',
    'SELECT ''system'', ''system'', ''log.enabled'', ''system'', ''Activity log'', ',
           '''Logging switched on. Changes made before this line were not recorded.'' ',
     'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`activity_log` WHERE `action` = ''log.enabled'')'
);
PREPARE first_line FROM @ddl;
EXECUTE first_line;
DEALLOCATE PREPARE first_line;


/* ---------------------------------------------------------------------
   3. Check.

   Two statements, and the order matters. The one that reads your own
   tables runs FIRST, while the current database is still yours; the one
   that reads information_schema runs LAST, so nothing follows it that
   could be caught out by phpMyAdmin switching the panel to
   "Table: TABLES" afterwards. That is the trap that produced

       #1109 - Unknown table 'users' in information_schema

   in an earlier migration.
   --------------------------------------------------------------------- */
SET @ddl := CONCAT(
    'SELECT ''lines recorded so far'' AS `check`, CAST(COUNT(*) AS CHAR) AS `result` ',
      'FROM `', @db, '`.`activity_log` ',
    'UNION ALL SELECT ''your players (unchanged by this file)'', CAST(COUNT(*) AS CHAR) ',
      'FROM `', @db, '`.`players` ',
    'UNION ALL SELECT ''your sold lots (unchanged by this file)'', CAST(COUNT(*) AS CHAR) ',
      'FROM `', @db, '`.`auction_lots` WHERE `status` = ''sold'''
);
PREPARE counts FROM @ddl;
EXECUTE counts;
DEALLOCATE PREPARE counts;


SELECT 'activity_log exists' AS `check`,
       IF(COUNT(*) = 1, 'OK', 'MISSING') AS `result`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'activity_log'
UNION ALL
SELECT 'it has its indexes',
       IF(COUNT(DISTINCT INDEX_NAME) >= 5, 'OK', 'INCOMPLETE')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'activity_log';
