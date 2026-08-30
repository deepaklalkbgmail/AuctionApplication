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

   IF YOU HAVE RUN IT BEFORE, CREATE TABLE IF NOT EXISTS does nothing
   the second time and reports success. The file is safe to run twice.
   =====================================================================
*/


/* ---------------------------------------------------------------------
   1. The table.
   --------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    `actor_user_id` INT UNSIGNED     NULL,
    `actor_name`    VARCHAR(120) NOT NULL DEFAULT 'system',
    `actor_role`    VARCHAR(40)  NOT NULL DEFAULT 'system',

    `action`        VARCHAR(40)  NOT NULL,
    `subject_type`  VARCHAR(30)  NOT NULL,
    `subject_id`    INT UNSIGNED     NULL,
    `subject_label` VARCHAR(160) NOT NULL DEFAULT '',

    `tournament_id` INT UNSIGNED     NULL,
    `changes`       TEXT             NULL,
    `note`          VARCHAR(255)     NULL,
    `ip`            VARCHAR(45)      NULL,

    PRIMARY KEY (`id`),
    KEY `idx_log_at`         (`at`),
    KEY `idx_log_tournament` (`tournament_id`, `at`),
    KEY `idx_log_subject`    (`subject_type`, `subject_id`, `at`),
    KEY `idx_log_actor`      (`actor_user_id`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/* ---------------------------------------------------------------------
   2. A first line, so the log is never an empty screen and so you have
      a dated marker for when logging began. This is the only row this
      file writes, and it writes it to the table it just created.
   --------------------------------------------------------------------- */
INSERT INTO `activity_log`
    (`actor_name`, `actor_role`, `action`, `subject_type`, `subject_label`, `note`)
SELECT 'system', 'system', 'log.enabled', 'system', 'Activity log',
       'Logging switched on. Changes made before this line were not recorded.'
 WHERE NOT EXISTS (SELECT 1 FROM `activity_log` WHERE `action` = 'log.enabled');


/* ---------------------------------------------------------------------
   3. Check. Both should read OK, and nothing else in your database
      should have moved.
   --------------------------------------------------------------------- */
SELECT 'activity_log exists' AS `check`,
       IF(COUNT(*) = 1, 'OK', 'MISSING') AS `result`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'
UNION ALL
SELECT 'it has its indexes',
       IF(COUNT(DISTINCT INDEX_NAME) >= 5, 'OK', 'INCOMPLETE')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'
UNION ALL
SELECT 'lines recorded so far',
       CAST(COUNT(*) AS CHAR) FROM `activity_log`
UNION ALL
SELECT 'your players (unchanged by this file)',
       CAST(COUNT(*) AS CHAR) FROM `players`
UNION ALL
SELECT 'your sold lots (unchanged by this file)',
       CAST(COUNT(*) AS CHAR) FROM `auction_lots` WHERE `status` = 'sold';
