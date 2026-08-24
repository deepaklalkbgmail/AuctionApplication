/*
   =====================================================================
   Reset to a clean, empty application
   =====================================================================

   Removes every row of tournament data — players, teams, users, bids,
   matches, balls — and leaves the tables and their constraints intact.
   Nothing is dropped, so you do not need to re-import schema.sql.

   After this the application is genuinely empty: the landing page shows
   no live auction and no match, and the only way in is the one
   administrator account created at the bottom of this file.

   USE IT WHEN
   • clearing the demonstration data before real use
   • starting a new season from scratch

   THIS DELETES EVERYTHING. Take a backup first:
   cPanel -> phpMyAdmin -> select the database -> Export -> Go

   On shared hosting run deploy/strip-create-database.sh first, or delete
   any USE statement, and import into your prefixed database.

   ---------------------------------------------------------------------
   Why DELETE and not TRUNCATE

   TRUNCATE is refused on any table another table points at with a
   foreign key — MySQL error 1701 — and phpMyAdmin re-asserts
   FOREIGN_KEY_CHECKS = 1 during an import, so switching the flag off in
   this file would not survive. DELETE has no such restriction, and
   running child tables before their parents means no foreign key is ever
   left dangling. It works with the checks left on, which is what
   phpMyAdmin gives you.
   ---------------------------------------------------------------------
*/

/* Children first ------------------------------------------------------ */
DELETE FROM `ball_by_ball`;
DELETE FROM `innings`;
DELETE FROM `match_squads`;
DELETE FROM `matches`;
DELETE FROM `auction_bids`;
DELETE FROM `auction_lots`;
DELETE FROM `tournament_registrations`;

/* players point at users, so players go first -------------------------- */
DELETE FROM `players`;

/*
   users.approved_by points at users. InnoDB checks a foreign key row by
   row, so "delete every user" can still trip over an administrator who is
   named as somebody's approver. Clearing the column first removes the
   reference, and the audit trail it recorded is about to be deleted too.
*/
UPDATE `users` SET `approved_by` = NULL;
DELETE FROM `users`;

/* users and players both pointed at teams, so teams go after both ------ */
DELETE FROM `teams`;
DELETE FROM `tournaments`;

/* Start the next season's ids at 1 rather than continuing the old count. */
ALTER TABLE `ball_by_ball`  AUTO_INCREMENT = 1;
ALTER TABLE `innings`       AUTO_INCREMENT = 1;
ALTER TABLE `match_squads`  AUTO_INCREMENT = 1;
ALTER TABLE `matches`       AUTO_INCREMENT = 1;
ALTER TABLE `auction_bids`  AUTO_INCREMENT = 1;
ALTER TABLE `auction_lots`  AUTO_INCREMENT = 1;
ALTER TABLE `tournament_registrations` AUTO_INCREMENT = 1;
ALTER TABLE `users`         AUTO_INCREMENT = 1;
ALTER TABLE `players`       AUTO_INCREMENT = 1;
ALTER TABLE `teams`         AUTO_INCREMENT = 1;
ALTER TABLE `tournaments`   AUTO_INCREMENT = 1;

/*
   ---------------------------------------------------------------------
   One administrator, so you can sign in.

   Username:            admin
   Temporary password:  ChangeMe@2026

   CHANGE IT IMMEDIATELY. This password is published in the project
   repository, so anyone who can read the source can sign in as the
   administrator until you replace it. The User Guide, section
   "Changing a password", has the two-step procedure.

   Edit the name and email below before running this.
   Sign in with either the username or the email address.

   must_change_password is set, so the first sign-in goes straight to the
   change-password screen and this published password cannot survive it.
   ---------------------------------------------------------------------
*/
INSERT INTO `users`
    (`username`, `name`, `email`, `password_hash`, `role`, `status`, `must_change_password`)
VALUES
    ('admin',
     'Administrator',
     'admin@example.com',
     '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK',
     'admin',
     'approved',
     1);
