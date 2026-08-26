/*
   =====================================================================
   Let a tournament be cancelled
   =====================================================================

   Adds one value, 'cancelled', to tournaments.status. That is the whole
   change.

   ---------------------------------------------------------------------
   NOTHING IN YOUR DATABASE MOVES

   This is a single ALTER that widens a list of permitted values. It

       reads no row
       writes no row
       deletes nothing
       drops nothing
       changes no default

   Every tournament keeps the status it has. A widened ENUM accepts
   everything the narrower one did, so nothing that was valid a moment
   ago becomes invalid. Safe to run on a live site with an auction in
   progress, and safe to run twice.

   Cancelling itself never deletes anything either — see below.

   ---------------------------------------------------------------------
   WHAT CANCELLING DOES, ONCE THIS IS IN

   From Administration -> Tournaments, Cancel sets the status and closes
   entries. It keeps every player, team, application, lot and sale
   exactly as they are, so a cancellation made by mistake is undone with
   Reinstate and nothing has been lost.

   A cancelled tournament:
     • is refused when a player tries to join with its code
     • is skipped by the public auction board, which moves to the newest
       tournament that has not been cancelled
     • still appears in Administration, marked cancelled

   ---------------------------------------------------------------------
   HOW TO RUN IT
     phpMyAdmin -> click your database in the LEFT SIDEBAR first
                -> SQL tab -> paste this whole file -> Go
   =====================================================================
*/

ALTER TABLE `tournaments`
    MODIFY COLUMN `status`
        ENUM('draft','auction','ongoing','completed','cancelled')
        NOT NULL DEFAULT 'draft';


/* ---------------------------------------------------------------------
   Check it worked, and that every tournament is where it was.
   --------------------------------------------------------------------- */
SET @schema := DATABASE();

SELECT 'reading database' AS `check`, IFNULL(@schema, '*** none selected ***') AS `result`
UNION ALL
SELECT 'tournaments.status',
       IF(COLUMN_TYPE LIKE '%cancelled%', 'OK', CONCAT('WRONG: ', COLUMN_TYPE))
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'status'
UNION ALL
SELECT CONCAT('tournaments now ', `status`), CAST(COUNT(*) AS CHAR)
  FROM `tournaments` GROUP BY `status`;
