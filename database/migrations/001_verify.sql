/*
   =====================================================================
   Is migration 001 already applied?
   =====================================================================

   Read-only. It changes nothing and is safe to run as often as you like.

   Migration 001 is NOT repeatable. Run it a second time and it stops at
   "#1060 - Duplicate column name 'username'" — which is MySQL saying the
   work is already done, not that anything is wrong. This tells you which
   it is, before you touch anything.

   ---------------------------------------------------------------------
   SELECT YOUR DATABASE FIRST.

   In phpMyAdmin, click the database in the left sidebar and THEN open the
   SQL tab. Opened from the server level, no database is selected,
   DATABASE() is NULL, and every check below would read MISSING however
   healthy the database actually is.

   The first row of the result tells you which database is being read. If
   it says NO DATABASE SELECTED, either select one, or set the name here
   by hand:

       SET @schema := 'deamco_APL';
   ---------------------------------------------------------------------
*/
SET @schema := DATABASE();

SELECT 'reading database' AS piece,
       IFNULL(@schema, '*** NO DATABASE SELECTED - see the note above ***') AS state
UNION ALL SELECT 'that database exists', IF(COUNT(*) = 1, 'OK', 'NOT FOUND') FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = @schema
UNION ALL SELECT 'table tournament_registrations', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.TABLES WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'tournament_registrations'
UNION ALL SELECT 'users.username', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username'
UNION ALL SELECT 'users.address', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'address'
UNION ALL SELECT 'users.photo_path', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'photo_path'
UNION ALL SELECT 'users.player_type', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'player_type'
UNION ALL SELECT 'users.status', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status'
UNION ALL SELECT 'users.approved_by', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approved_by'
UNION ALL SELECT 'users.approved_at', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approved_at'
UNION ALL SELECT 'users.must_change_password', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'
UNION ALL SELECT "users role allows 'player'", IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%player%'
UNION ALL SELECT 'index uq_users_username', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_username'
UNION ALL SELECT 'index uq_users_owner_team', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_owner_team'
UNION ALL SELECT 'index idx_users_status', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_status'
UNION ALL SELECT 'fk fk_users_approved_by', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_approved_by'
UNION ALL SELECT 'tournaments.secret_code', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'secret_code'
UNION ALL SELECT 'tournaments 4 dates', IF(COUNT(*) = 4, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'tournaments' AND COLUMN_NAME IN ('start_date','auction_date','end_date','team_name_change_deadline')
UNION ALL SELECT 'tournaments.registration_open', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'registration_open'
UNION ALL SELECT 'index uq_tournament_secret', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'tournaments' AND INDEX_NAME = 'uq_tournament_secret'
UNION ALL SELECT 'players.user_id', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'players' AND COLUMN_NAME = 'user_id'
UNION ALL SELECT 'fk fk_players_user', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'players' AND CONSTRAINT_NAME = 'fk_players_user';

/*
   ---------------------------------------------------------------------
   Second query: did every existing account get a username?

   Run it only once the list above reads OK throughout, and only with the
   database selected. Before the migration users.username does not exist,
   so this answers "#1054 - Unknown column" — the query asking something
   the database cannot answer yet, not a fault.
   ---------------------------------------------------------------------
*/
SELECT COUNT(*) AS accounts_without_a_username
  FROM `users`
 WHERE `username` IS NULL OR `username` = '';
