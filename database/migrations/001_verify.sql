/*
   =====================================================================
   Is migration 001 already applied?
   =====================================================================

   Read-only. It changes nothing, and it is safe to run as often as you
   like. Paste it into phpMyAdmin -> your database -> SQL -> Go.

   Migration 001 is NOT repeatable: run it twice and the second run stops
   at "#1060 - Duplicate column name 'username'", which is MySQL saying
   the work is already done rather than anything being wrong. This tells
   you which it is, before you touch anything.

   Every row should read OK. Anything reading MISSING means that piece
   did not get applied.
   =====================================================================
*/
SELECT 'users.username'              AS piece, IF(COUNT(*) = 1, 'OK', 'MISSING') AS state FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username'
UNION ALL SELECT 'users.address',              IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'address'
UNION ALL SELECT 'users.photo_path',           IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'photo_path'
UNION ALL SELECT 'users.player_type',          IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'player_type'
UNION ALL SELECT 'users.status',               IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status'
UNION ALL SELECT 'users.approved_by',          IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approved_by'
UNION ALL SELECT 'users.approved_at',          IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approved_at'
UNION ALL SELECT 'users.must_change_password', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'
UNION ALL SELECT 'users role has player',      IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%player%'
UNION ALL SELECT 'index uq_users_username',    IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_username'
UNION ALL SELECT 'index uq_users_owner_team',  IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_owner_team'
UNION ALL SELECT 'index idx_users_status',     IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_status'
UNION ALL SELECT 'fk fk_users_approved_by',    IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_approved_by'
UNION ALL SELECT 'tournaments.secret_code',    IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'secret_code'
UNION ALL SELECT 'tournaments 4 dates',        IF(COUNT(*) = 4, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME IN ('start_date','auction_date','end_date','team_name_change_deadline')
UNION ALL SELECT 'tournaments.registration_open', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'registration_open'
UNION ALL SELECT 'index uq_tournament_secret', IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND INDEX_NAME = 'uq_tournament_secret'
UNION ALL SELECT 'players.user_id',            IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players' AND COLUMN_NAME = 'user_id'
UNION ALL SELECT 'fk fk_players_user',         IF(COUNT(*) > 0, 'OK', 'MISSING') FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players' AND CONSTRAINT_NAME = 'fk_players_user'
UNION ALL SELECT 'table tournament_registrations', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournament_registrations';

/*
   ---------------------------------------------------------------------
   Second query: did every existing account get a username?

   Run this only once the list above reads OK throughout. Before the
   migration the users.username column does not exist, so this one
   answers "#1054 - Unknown column" — which is not a fault, just this
   query asking a question the database cannot answer yet.
   ---------------------------------------------------------------------
*/
SELECT COUNT(*) AS accounts_without_a_username FROM `users` WHERE `username` IS NULL OR `username` = '';
