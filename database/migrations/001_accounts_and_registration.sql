/*
   =====================================================================
   Migration 001 — accounts, self-registration and the tournament cycle
   =====================================================================

   Additive only. Nothing is dropped and no existing row is deleted, so an
   installation that already holds a season can run this and keep it.

   Run once, on an existing database:
   cPanel -> phpMyAdmin -> your database -> SQL -> paste -> Go

   A brand-new installation does not need this file: database/schema.sql
   already contains everything here.

   What it adds
   users        a username to sign in with, the profile fields a player
   registers with, an approval state, and a flag that forces
   a password change on first sign-in
   tournaments  the four dates, and the secret code players join with
   players      a link back to the person who registered
   teams        one owner per team, enforced by the database
   NEW TABLE    tournament_registrations — who applied, and the decision
   =====================================================================
*/


/*
   ---------------------------------------------------------------------
   users — a person, not just a login
   ---------------------------------------------------------------------
*/

/*
   'player' joins the existing roles. A player is someone who registered
   themselves and wants to be auctioned.
*/
ALTER TABLE `users`
    MODIFY COLUMN `role`
    ENUM('admin','team_owner','scorer','viewer','player') NOT NULL DEFAULT 'viewer';

ALTER TABLE `users`
    ADD COLUMN `username` VARCHAR(40) NULL AFTER `id`,
    ADD COLUMN `address` VARCHAR(255) NULL AFTER `phone`,
    ADD COLUMN `photo_path` VARCHAR(255) NULL AFTER `address`,
/* What kind of cricketer they are. NULL for admins and scorers. */
    ADD COLUMN `player_type` ENUM('batsman','bowler','all_rounder','wicket_keeper') NULL AFTER `photo_path`,
/* Registration state. Nobody reaches an auction until an admin approves. */
    ADD COLUMN `status` ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'approved' AFTER `role`,
    ADD COLUMN `approved_by` INT UNSIGNED NULL AFTER `status`,
    ADD COLUMN `approved_at` DATETIME NULL AFTER `approved_by`,
/* Set when an admin issues or resets a password; cleared once changed. */
    ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`;

/* Sign in with either a username or an email address. */
ALTER TABLE `users`
    ADD UNIQUE KEY `uq_users_username` (`username`);

/*
   One owner per team. team_id is NULL for everyone who is not an owner, and
   MySQL allows any number of NULLs in a unique index — so this says exactly
   "no two owners may hold the same team" and nothing more.
*/
ALTER TABLE `users`
    ADD UNIQUE KEY `uq_users_owner_team` (`team_id`);

/*
   The old plain index on team_id is now redundant: the unique index above
   covers the same column and still satisfies fk_users_team.
*/
ALTER TABLE `users`
    DROP INDEX `idx_users_team`;

ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_approved_by`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

ALTER TABLE `users`
    ADD KEY `idx_users_status` (`status`, `role`);


/*
   ---------------------------------------------------------------------
   tournaments — dates, and the code players join with
   ---------------------------------------------------------------------
*/
ALTER TABLE `tournaments`
    ADD COLUMN `secret_code` VARCHAR(16) NULL AFTER `logo_url`,
    ADD COLUMN `start_date` DATE NULL AFTER `season_year`,
    ADD COLUMN `auction_date` DATE NULL AFTER `start_date`,
    ADD COLUMN `end_date` DATE NULL AFTER `auction_date`,
/*
   Owners may rename their team up to the end of this day. It exists so a
   name can be settled with the squad after the auction, then frozen.
*/
    ADD COLUMN `team_name_change_deadline` DATE NULL AFTER `end_date`,
    ADD COLUMN `registration_open` TINYINT(1) NOT NULL DEFAULT 1 AFTER `team_name_change_deadline`;

ALTER TABLE `tournaments`
    ADD UNIQUE KEY `uq_tournament_secret` (`secret_code`);


/*
   ---------------------------------------------------------------------
   players — the auction entry, linked to the person who registered

   user_id stays nullable: an admin can still enter a player who has no
   account. One account may appear once per tournament, no more.
   ---------------------------------------------------------------------
*/
ALTER TABLE `players`
    ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `tournament_id`;

ALTER TABLE `players`
    ADD UNIQUE KEY `uq_player_user_tournament` (`tournament_id`, `user_id`),
    ADD CONSTRAINT `fk_players_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);


/*
   ---------------------------------------------------------------------
   tournament_registrations — the application and the decision

   A player applies with the tournament's secret code; an admin approves or
   rejects. Approval is what puts them in the auction pool, so this table is
   the audit trail for who let each player in, and when.
   ---------------------------------------------------------------------
*/
CREATE TABLE IF NOT EXISTS `tournament_registrations` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_id` INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `status`        ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    `applied_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decided_by`    INT UNSIGNED     NULL,
    `decided_at`    DATETIME         NULL,
    `note`          VARCHAR(255)     NULL,

    PRIMARY KEY (`id`),
/* One application per person per tournament. Re-applying updates the row. */
    UNIQUE KEY `uq_registration` (`tournament_id`, `user_id`),
    KEY `idx_registration_queue` (`tournament_id`, `status`),

    CONSTRAINT `fk_reg_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reg_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reg_decided_by`
        FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   Existing rows

   Accounts that already existed were created by an administrator, so they
   are approved. Give each one a username derived from its email so it can
   still sign in either way.

   Nobody's password changes, and nobody is forced to change one — an
   existing installation must not lock its own staff out on upgrade day.
   ---------------------------------------------------------------------
*/
UPDATE `users`
   SET `status` = 'approved'
 WHERE `status` IS NULL OR `status` = '';

/*
   Step 1: the straightforward ones. Only an email whose local part is
   unique across the table becomes a username directly. Two accounts on
   admin@one.com and admin@two.com would both want "admin", and the unique
   index would reject the second — so they are left for step 2 rather than
   failing the migration half way through.
*/
UPDATE `users` u
  JOIN (
        SELECT LEFT(SUBSTRING_INDEX(`email`, '@', 1), 40) AS handle
          FROM `users`
         GROUP BY handle
        HAVING COUNT(*) = 1
       ) unique_handles
    ON unique_handles.handle = LEFT(SUBSTRING_INDEX(u.`email`, '@', 1), 40)
   SET u.`username` = unique_handles.handle
 WHERE u.`username` IS NULL;

/*
   Step 2: whatever is left, disambiguated by the account's own id, which
   is unique by definition. The result is ugly but it works, and an
   administrator can tidy it under Administration -> People afterwards.
*/
UPDATE `users`
   SET `username` = CONCAT(LEFT(SUBSTRING_INDEX(`email`, '@', 1), 30), '.', `id`)
 WHERE `username` IS NULL;
