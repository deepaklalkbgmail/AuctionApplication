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

   Nothing is deleted and no existing row changes value. Widening an ENUM
   accepts everything the narrower one did, and a new nullable column
   arrives as NULL on every existing row.

   Your existing scorers will have tournament_id NULL, which means "not
   yet assigned". They can still sign in; the scoring pad will ask an
   administrator to give them a tournament. Set them under
   Administration -> People -> Edit details.

   Deliberately no CHECK constraint tying the role to the column: MySQL
   validates a new CHECK against rows that are already there, so one
   would refuse to install while any unassigned scorer exists. The
   application enforces it instead, where it can give a sentence rather
   than an error number.

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   IF YOU HAVE RUN IT BEFORE you will see one of these, and both are
   harmless — they mean that part is already in place:

       #1060 - Duplicate column name 'tournament_id'
       #1061 - Duplicate key name 'idx_users_tournament'

   The last statement is a check. Run that on its own to see where you
   stand; all three rows should read OK.
   =====================================================================
*/


/* ---------------------------------------------------------------------
   1. The new role. MODIFY is safe to repeat — it simply restates the
      column, so running this twice does nothing the second time.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    MODIFY COLUMN `role`
        ENUM('admin','tournament_admin','team_owner','scorer','viewer','player')
        NOT NULL DEFAULT 'viewer';


/* ---------------------------------------------------------------------
   2. Which tournament a scorer or tournament administrator belongs to.
      #1060 here means it is already there.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    ADD COLUMN `tournament_id` INT UNSIGNED NULL AFTER `team_id`;


/* ---------------------------------------------------------------------
   3. An index for "everyone who works on this tournament".
      #1061 here means it is already there.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    ADD KEY `idx_users_tournament` (`tournament_id`, `role`);


/* ---------------------------------------------------------------------
   4. And the foreign key.

      ON DELETE SET NULL, not RESTRICT: deleting a tournament should not
      be blocked by the staff attached to it, and it should not delete
      their accounts either. They simply become unassigned.
      An error 1826 or 121 here means it is already there.
   --------------------------------------------------------------------- */
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;


/* ---------------------------------------------------------------------
   5. Check. All three should read OK.
   --------------------------------------------------------------------- */
SET @schema := DATABASE();

SELECT 'reading database' AS `check`, IFNULL(@schema, '*** none selected ***') AS `result`
UNION ALL
SELECT 'users.role has tournament_admin',
       IF(COLUMN_TYPE LIKE '%tournament\\_admin%', 'OK', CONCAT('MISSING: ', COLUMN_TYPE))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
UNION ALL
SELECT 'users.tournament_id exists',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tournament_id'
UNION ALL
SELECT 'foreign key fk_users_tournament',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users'
   AND CONSTRAINT_NAME = 'fk_users_tournament'
UNION ALL
SELECT 'scorers with no tournament yet',
       CAST(COUNT(*) AS CHAR)
  FROM `users` WHERE `role` = 'scorer' AND `tournament_id` IS NULL;
