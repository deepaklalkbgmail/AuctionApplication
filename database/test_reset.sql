/*
   =====================================================================
   Clean the database for a round of testing
   =====================================================================

              Username:  admin
              Password:  admin

   The same two words every time you run this, so you never have to look
   them up again and never have to change the password before you can get
   to a screen. That is the whole difference between this file and
   reset.sql, which issues a temporary password and forces you to change
   it at the first sign-in.

   ---------------------------------------------------------------------
   *** TESTING ONLY ***

   "admin" is a guessable password on a site anyone can reach. Fine while
   you are the only person using it; not fine the day it carries a real
   auction. Before the tournament goes live, either

       run database/reset.sql instead, which forces a password change, or
       sign in and change the password under Password.

   Nothing else in the application treats this account specially — it is
   an ordinary administrator with a weak password, and changing the
   password is all it takes to make it safe.
   ---------------------------------------------------------------------

   WHAT IT DOES
   Deletes every row of tournament data — players, teams, users,
   applications, bids, matches, balls — restarts the ids at 1, and leaves
   one administrator so you can sign in. The tables and their constraints
   are untouched, so there is no need to re-import schema.sql.

   WHAT IT DOES NOT DO
   Photographs already uploaded stay on disk in
   public/assets/img/uploads/. They are harmless: no row points at them
   any more. Delete them in cPanel -> File Manager if you want the
   folder tidy.

   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go

   Selecting the database first matters. There is deliberately no USE
   statement in this file, so it cannot rebuild the wrong database — but
   that also means it needs one chosen for it.

   THIS DELETES EVERYTHING. If there is anything you want to keep, export
   first: phpMyAdmin -> select the database -> Export -> Go.

   Safe to run as often as you like.
   =====================================================================
*/

/* ---------------------------------------------------------------------
   Children first, then parents, so no foreign key is ever left dangling
   and the checks can stay on — which is what phpMyAdmin gives you.

   DELETE rather than TRUNCATE: TRUNCATE is refused on any table another
   table points at with a foreign key (error 1701).
   --------------------------------------------------------------------- */
DELETE FROM `ball_by_ball`;
DELETE FROM `innings`;
DELETE FROM `match_squads`;
DELETE FROM `matches`;
DELETE FROM `auction_bids`;
DELETE FROM `auction_lots`;
DELETE FROM `tournament_registrations`;

/* Players point at users and at teams. */
DELETE FROM `players`;

/* An administrator approved other accounts, and an owner holds a team.
   Both references have to let go before the rows themselves can. */
UPDATE `users` SET `approved_by` = NULL;
DELETE FROM `users`;

/* users and players both pointed at teams, so teams go after both. */
DELETE FROM `teams`;
DELETE FROM `tournaments`;


/* ---------------------------------------------------------------------
   Start the next run's ids at 1 rather than continuing the old count.
   Not cosmetic: the public auction board picks the newest tournament
   that has an auction, so ids that keep climbing make it harder to tell
   at a glance which run you are looking at.
   --------------------------------------------------------------------- */
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


/* ---------------------------------------------------------------------
   The one account.

   must_change_password is 0 on purpose. reset.sql sets it, so its
   temporary password cannot survive the first sign-in; here the point is
   that the password does survive, run after run, unchanged.

   The hash below is bcrypt of the word "admin". Nothing else.
   --------------------------------------------------------------------- */
INSERT INTO `users`
    (`username`, `name`, `email`, `password_hash`, `role`, `status`, `must_change_password`)
VALUES
    ('admin',
     'Administrator',
     'admin@example.com',
     '$2y$12$ZI/00GsoSB1YEaLUcFaoXe2DqxTmwybyM9syBaB2arNkwB5zlpKA2',
     'admin',
     'approved',
     0);


/* ---------------------------------------------------------------------
   Prove it. Every count should read 0, and the last row should name the
   account you are about to sign in with.
   --------------------------------------------------------------------- */
SELECT 'tournaments'  AS `table`, CAST(COUNT(*) AS CHAR) AS `rows` FROM `tournaments`
UNION ALL SELECT 'teams',         CAST(COUNT(*) AS CHAR) FROM `teams`
UNION ALL SELECT 'players',       CAST(COUNT(*) AS CHAR) FROM `players`
UNION ALL SELECT 'applications',  CAST(COUNT(*) AS CHAR) FROM `tournament_registrations`
UNION ALL SELECT 'auction lots',  CAST(COUNT(*) AS CHAR) FROM `auction_lots`
UNION ALL SELECT 'matches',       CAST(COUNT(*) AS CHAR) FROM `matches`
UNION ALL SELECT 'sign in as',    CONCAT(`username`, '  /  admin') FROM `users` WHERE `role` = 'admin';

/*
   ---------------------------------------------------------------------
   The activity log, last of all.

   Deliberately at the END. If your database has not had migration 006
   run against it there is no activity_log table, these two statements
   fail with "Table doesn't exist", and NOTHING ABOVE IS AFFECTED — the
   reset has already finished by the time they run. Ignore the error.

   The log is cleared because a reset is a fresh start: lines about
   players who no longer exist are noise, not evidence. The first line
   below records the reset itself, so the trail begins where the data
   does.
   ---------------------------------------------------------------------
*/
DELETE FROM `activity_log`;
ALTER TABLE `activity_log` AUTO_INCREMENT = 1;

INSERT INTO `activity_log`
    (`actor_name`, `actor_role`, `action`, `subject_type`, `subject_label`, `note`)
VALUES
    ('system', 'system', 'log.enabled', 'system', 'Database reset',
     'Everything was emptied and one administrator created. The trail starts here.');
