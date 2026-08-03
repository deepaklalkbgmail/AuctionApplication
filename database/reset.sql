-- =====================================================================
--  Reset to a clean, empty application
-- =====================================================================
--
--  Removes every row of tournament data — players, teams, users, bids,
--  matches, balls — and leaves the tables and their constraints intact.
--  Nothing is dropped, so you do not need to re-import schema.sql.
--
--  After this the application is genuinely empty: the landing page shows
--  no live auction and no match, and the only way in is the one
--  administrator account created at the bottom of this file.
--
--  USE IT WHEN
--    • clearing the demonstration data before real use
--    • starting a new season from scratch
--
--  THIS DELETES EVERYTHING. Take a backup first:
--    cPanel -> phpMyAdmin -> select the database -> Export -> Go
--
--  On shared hosting run deploy/strip-create-database.sh first, or delete
--  any USE statement, and import into your prefixed database.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `ball_by_ball`;
TRUNCATE TABLE `innings`;
TRUNCATE TABLE `match_squads`;
TRUNCATE TABLE `matches`;
TRUNCATE TABLE `auction_bids`;
TRUNCATE TABLE `auction_lots`;
TRUNCATE TABLE `players`;
TRUNCATE TABLE `users`;
TRUNCATE TABLE `teams`;
TRUNCATE TABLE `tournaments`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  One administrator, so you can sign in.
--
--  Temporary password:  ChangeMe@2026
--
--  CHANGE IT IMMEDIATELY. This password is published in the project
--  repository, so anyone who can read the source can sign in as the
--  administrator until you replace it. The User Guide, section
--  "Changing a password", has the two-step procedure.
--
--  Edit the name and email below before running this.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
    ('Administrator',
     'admin@example.com',
     '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK',
     'admin');
