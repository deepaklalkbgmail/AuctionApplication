/*
   =====================================================================
   CricAuction — Cricket Auction & Live Scoring Platform
   Schema for MySQL 8.0.16+  (CHECK constraints and generated columns
   are enforced from 8.0.16 onward)

   Conventions
   * InnoDB + utf8mb4_unicode_ci everywhere (emoji-safe team names)
   * Money stored as DECIMAL(14,2) — never FLOAT
   * Every FK is named fk_<table>_<column> for readable error messages
   * ON DELETE RESTRICT by default; CASCADE only where the child row is
   meaningless without its parent (bids, balls, squads)
   * A foreign key whose column also appears in a CHECK constraint carries
   NO referential action at all — both MySQL 8 and MariaDB reject that
   combination outright (ERROR 1901), because a cascade could silently
   drive the row through its own CHECK. Those FKs fall back to the
   default NO ACTION, which is what we want anyway: you should not be
   able to delete a team out from under its owner or its sold players.

   Load order matters (FK dependencies):
   tournaments -> teams -> users -> players -> auction_lots ->
   auction_bids -> matches -> match_squads -> innings -> ball_by_ball
   =====================================================================
*/

/*
   ---------------------------------------------------------------------
   SHARED HOSTING (cPanel / Plesk): DELETE the next two statements.

   cPanel creates the database for you and prefixes its name
   (deamco_APL), and the MySQL user it issues has no
   CREATE DATABASE privilege — so both statements will fail. Create the
   database in cPanel first, then import the rest of this file into it.

   deploy/strip-create-database.sh   does this for you.
   ---------------------------------------------------------------------
*/
CREATE DATABASE IF NOT EXISTS `cric_auction`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `cric_auction`;
/* --------------------------- end of the shared-hosting cut ------------ */

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `ball_by_ball`;
DROP TABLE IF EXISTS `innings`;
DROP TABLE IF EXISTS `match_squads`;
DROP TABLE IF EXISTS `matches`;
DROP TABLE IF EXISTS `auction_bids`;
DROP TABLE IF EXISTS `auction_lots`;
DROP TABLE IF EXISTS `players`;
DROP TABLE IF EXISTS `tournament_registrations`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `teams`;
DROP TABLE IF EXISTS `tournaments`;
SET FOREIGN_KEY_CHECKS = 1;


/*
   ---------------------------------------------------------------------
   tournaments — the scoping root. Teams, players, auctions and matches
   all belong to exactly one tournament, so multiple seasons can coexist.
   ---------------------------------------------------------------------
*/
CREATE TABLE `tournaments` (
    `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`               VARCHAR(120)    NOT NULL,
    `season_year`        SMALLINT UNSIGNED NOT NULL,
    `logo_url`           VARCHAR(255)        NULL,

/*
   The code players join with. Generated from an alphabet that excludes
   0/O/o and 1/I/l/i, so it survives being read out in a crowded room.
*/
    `secret_code`        VARCHAR(16)         NULL,

/*
   The season's four dates. Nullable so a tournament can be sketched out
   before the calendar is settled.
*/
    `start_date`         DATE                NULL,
    `auction_date`       DATE                NULL,  /* entries close at end of day */
    `end_date`           DATE                NULL,
/*
   The last day an owner may rename their own team. After it, only an
   administrator can.
*/
    `team_name_change_deadline` DATE          NULL,
    `registration_open`  TINYINT(1)      NOT NULL DEFAULT 1,

/* Auction rules */
    `purse_per_team`     DECIMAL(14,2)   NOT NULL DEFAULT 10000000.00,
    `min_squad_size`     TINYINT UNSIGNED NOT NULL DEFAULT 11,
    `max_squad_size`     TINYINT UNSIGNED NOT NULL DEFAULT 15,
    `max_overseas`       TINYINT UNSIGNED NOT NULL DEFAULT 4,
    `bid_increment`      DECIMAL(14,2)   NOT NULL DEFAULT 500000.00,
    `bid_timer_seconds`  SMALLINT UNSIGNED NOT NULL DEFAULT 30,

/* Match rules */
    `overs_per_innings`  TINYINT UNSIGNED NOT NULL DEFAULT 20,
    `balls_per_over`     TINYINT UNSIGNED NOT NULL DEFAULT 6,

    `status`             ENUM('draft','auction','ongoing','completed') NOT NULL DEFAULT 'draft',
    `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tournament_name_season` (`name`, `season_year`),
    UNIQUE KEY `uq_tournament_secret` (`secret_code`),
    KEY `idx_tournament_status` (`status`),

    CONSTRAINT `chk_tournament_squad`   CHECK (`max_squad_size` >= `min_squad_size`),
    CONSTRAINT `chk_tournament_purse`   CHECK (`purse_per_team` > 0),
    CONSTRAINT `chk_tournament_overs`   CHECK (`overs_per_innings` BETWEEN 1 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   teams — franchises bidding in the auction.
   purse_remaining is a GENERATED column so "money left" can never drift
   out of sync with what has been spent; the CHECK makes overspending a
   database-level error, not just an application-level one.
   ---------------------------------------------------------------------
*/
CREATE TABLE `teams` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tournament_id`  INT UNSIGNED  NOT NULL,
    `name`           VARCHAR(100)  NOT NULL,
    `short_name`     VARCHAR(6)    NOT NULL,  /* 'MI', 'CSK' */
    `logo_url`       VARCHAR(255)      NULL,
    `primary_color`  CHAR(7)       NOT NULL DEFAULT '#22c55e',  /* hex, drives the UI accent */
    `home_venue`     VARCHAR(120)      NULL,

    `purse_total`    DECIMAL(14,2) NOT NULL DEFAULT 10000000.00,
    `purse_spent`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `purse_remaining` DECIMAL(14,2) AS (`purse_total` - `purse_spent`) STORED,
    `players_bought` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `overseas_bought` TINYINT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_team_name`  (`tournament_id`, `name`),
    UNIQUE KEY `uq_team_short` (`tournament_id`, `short_name`),
    KEY `idx_team_purse` (`tournament_id`, `purse_remaining`),

    CONSTRAINT `fk_teams_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `chk_team_spent`  CHECK (`purse_spent` >= 0 AND `purse_spent` <= `purse_total`),
    CONSTRAINT `chk_team_colour` CHECK (`primary_color` REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   users — a person, not just a login. Five roles: a team_owner is linked
   to exactly one team; admins / scorers / viewers / players have team_id
   NULL. Passwords are bcrypt hashes from password_hash(), never plaintext.

   A 'player' is someone who registered themselves and wants to be
   auctioned. They arrive as 'pending' and reach no auction until an
   administrator approves them.

   name and email are set once, at registration, and only an administrator
   may change them afterwards — they are what the administrator approved.
   ---------------------------------------------------------------------
*/
CREATE TABLE `users` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`       VARCHAR(40)      NULL,  /* sign in with this or the email */
    `name`           VARCHAR(120) NOT NULL,
    `email`          VARCHAR(190) NOT NULL,  /* 190 keeps the index under utf8mb4 limits */
    `phone`          VARCHAR(20)      NULL,
    `address`        VARCHAR(255)     NULL,
    `photo_path`     VARCHAR(255)     NULL,  /* relative to public/ */
    `player_type`    ENUM('batsman','batting_all_rounder','bowling_all_rounder','wicket_keeper','bowler') NULL,
    `password_hash`  VARCHAR(255) NOT NULL,
/*
   Set when an administrator issues or resets a password; cleared as
   soon as the person chooses their own.
*/
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `role`           ENUM('admin','team_owner','scorer','viewer','player') NOT NULL DEFAULT 'viewer',
    `status`         ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'approved',
    `approved_by`    INT UNSIGNED     NULL,
    `approved_at`    DATETIME         NULL,
    `team_id`        INT UNSIGNED     NULL,  /* required for team_owner, NULL otherwise */
    `avatar_url`     VARCHAR(255)     NULL,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at`  DATETIME         NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_username` (`username`),
/*
   One owner per team. team_id is NULL for everyone who is not an owner,
   and a unique index permits any number of NULLs — so this says exactly
   "no two owners may hold the same team", and nothing more.
*/
    UNIQUE KEY `uq_users_owner_team` (`team_id`),
    KEY `idx_users_role` (`role`, `is_active`),
    KEY `idx_users_status` (`status`, `role`),

    CONSTRAINT `fk_users_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
    CONSTRAINT `fk_users_approved_by`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),

/* A team owner must have a team; nobody else may have one. */
    CONSTRAINT `chk_users_team_role` CHECK (
        (`role` = 'team_owner' AND `team_id` IS NOT NULL)
        OR (`role` <> 'team_owner' AND `team_id` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   tournament_registrations — the application, and the decision on it.

   A player applies with the tournament's secret code; an administrator
   approves or rejects. Approval is what creates the players row and the
   auction lot, so this table is the audit trail for who let each player
   into the auction, and when.
   ---------------------------------------------------------------------
*/
CREATE TABLE `tournament_registrations` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_id` INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `status`        ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    `applied_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decided_by`    INT UNSIGNED     NULL,
    `decided_at`    DATETIME         NULL,
    `note`          VARCHAR(255)     NULL,

    PRIMARY KEY (`id`),
/* One application per person per tournament. Re-applying reuses the row. */
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
   players — the auction pool. team_id / sold_price stay NULL until the
   hammer falls; they are the denormalised "current owner" for fast reads,
   while auction_lots keeps the authoritative sale record.
   ---------------------------------------------------------------------
*/
CREATE TABLE `players` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tournament_id`  INT UNSIGNED  NOT NULL,
/*
   The account that registered. Nullable: an administrator can still
   enter a player who has no login of their own.
*/
    `user_id`        INT UNSIGNED      NULL,
    `full_name`      VARCHAR(120)  NOT NULL,
    `display_name`   VARCHAR(60)       NULL,  /* short name for the scorecard */
    `photo_url`      VARCHAR(255)      NULL,
    `country`        VARCHAR(60)   NOT NULL DEFAULT 'India',
    `date_of_birth`  DATE              NULL,

    `role`           ENUM('batsman','batting_all_rounder','bowling_all_rounder','wicket_keeper','bowler') NOT NULL,
    `batting_style`  ENUM('right_hand','left_hand')    NULL,
    `bowling_style`  ENUM('right_arm_fast','right_arm_medium','right_arm_offbreak',
                          'right_arm_legbreak','left_arm_fast','left_arm_medium',
                          'left_arm_orthodox','left_arm_chinaman','none') NOT NULL DEFAULT 'none',

    `is_overseas`    TINYINT(1)    NOT NULL DEFAULT 0,
    `is_capped`      TINYINT(1)    NOT NULL DEFAULT 0,
    `auction_set`    VARCHAR(40)       NULL,  /* 'Marquee', 'Set A', … */
    `base_price`     DECIMAL(14,2) NOT NULL DEFAULT 200000.00,

/* Career form shown on the auction card */
    `career_matches` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `career_runs`    MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `career_wickets` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `strike_rate`    DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    `economy`        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,

/* Auction outcome */
    `status`         ENUM('available','in_auction','sold','unsold','withdrawn')
                     NOT NULL DEFAULT 'available',
    `team_id`        INT UNSIGNED      NULL,
    `sold_price`     DECIMAL(14,2)     NULL,
    `jersey_number`  TINYINT UNSIGNED  NULL,

    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_players_pool`   (`tournament_id`, `status`, `role`),
    KEY `idx_players_team`   (`team_id`),
    KEY `idx_players_set`    (`tournament_id`, `auction_set`),
    UNIQUE KEY `uq_player_jersey` (`team_id`, `jersey_number`),
/* One account appears at most once per tournament. */
    UNIQUE KEY `uq_player_user_tournament` (`tournament_id`, `user_id`),

    CONSTRAINT `fk_players_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_players_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_players_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),

    CONSTRAINT `chk_player_base_price` CHECK (`base_price` > 0),
/* A sold player must have both a team and a price >= base price. */
    CONSTRAINT `chk_player_sold` CHECK (
        (`status` = 'sold' AND `team_id` IS NOT NULL AND `sold_price` >= `base_price`)
        OR (`status` <> 'sold' AND `sold_price` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   auction_lots — one row per player put under the hammer. This is the
   live-state table the auction dashboard polls: exactly one lot per
   tournament may be 'live' at a time (enforced in the service layer via
   SELECT … FOR UPDATE).
   ---------------------------------------------------------------------
*/
CREATE TABLE `auction_lots` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_id`         INT UNSIGNED  NOT NULL,
    `player_id`             INT UNSIGNED  NOT NULL,
    `lot_order`             SMALLINT UNSIGNED NOT NULL,

    `status`                ENUM('queued','live','paused','sold','unsold') NOT NULL DEFAULT 'queued',
    `base_price`            DECIMAL(14,2) NOT NULL,
    `current_bid`           DECIMAL(14,2)     NULL,
    `current_bidder_team_id` INT UNSIGNED     NULL,
    `bid_count`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    `started_at`            DATETIME          NULL,
    `ends_at`               DATETIME          NULL,  /* countdown target; extended on each bid */
    `closed_at`             DATETIME          NULL,

    `sold_to_team_id`       INT UNSIGNED      NULL,
    `sold_price`            DECIMAL(14,2)     NULL,
    `closed_by_user_id`     INT UNSIGNED      NULL,  /* admin who hit Sold / Unsold */

    `created_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lot_player` (`tournament_id`, `player_id`),
    UNIQUE KEY `uq_lot_order`  (`tournament_id`, `lot_order`),
    KEY `idx_lot_live`  (`tournament_id`, `status`),
    KEY `idx_lot_queue` (`tournament_id`, `status`, `lot_order`),

    CONSTRAINT `fk_lots_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_lots_player`
        FOREIGN KEY (`player_id`) REFERENCES `players` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_lots_bidder`
        FOREIGN KEY (`current_bidder_team_id`) REFERENCES `teams` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_lots_sold_to`
        FOREIGN KEY (`sold_to_team_id`) REFERENCES `teams` (`id`),
    CONSTRAINT `fk_lots_closed_by`
        FOREIGN KEY (`closed_by_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `chk_lot_bid_floor` CHECK (`current_bid` IS NULL OR `current_bid` >= `base_price`),
    CONSTRAINT `chk_lot_sold`      CHECK (
        (`status` = 'sold' AND `sold_to_team_id` IS NOT NULL AND `sold_price` IS NOT NULL)
        OR (`status` <> 'sold' AND `sold_price` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   auction_bids — append-only bid log (audit trail + the live ticker).
   Rows are never updated; the winning bid is flagged when the lot closes.
   ---------------------------------------------------------------------
*/
CREATE TABLE `auction_bids` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lot_id`       BIGINT UNSIGNED NOT NULL,
    `player_id`    INT UNSIGNED    NOT NULL,  /* denormalised for player bid history */
    `team_id`      INT UNSIGNED    NOT NULL,
    `user_id`      INT UNSIGNED        NULL,  /* owner who clicked (NULL = admin proxy bid) */
    `bid_amount`   DECIMAL(14,2)   NOT NULL,
    `is_winning`   TINYINT(1)      NOT NULL DEFAULT 0,
    `placed_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),  /* ms precision: bids race */
    `ip_address`   VARBINARY(16)       NULL,  /* INET6_ATON(), for dispute resolution */

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bid_lot_amount` (`lot_id`, `bid_amount`),  /* no two teams at the same number */
    KEY `idx_bids_lot_time`   (`lot_id`, `placed_at` DESC),
    KEY `idx_bids_team`       (`team_id`, `placed_at` DESC),
    KEY `idx_bids_player`     (`player_id`),

    CONSTRAINT `fk_bids_lot`
        FOREIGN KEY (`lot_id`) REFERENCES `auction_lots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bids_player`
        FOREIGN KEY (`player_id`) REFERENCES `players` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bids_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_bids_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `chk_bid_amount` CHECK (`bid_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   matches — schedule + toss + result.
   ---------------------------------------------------------------------
*/
CREATE TABLE `matches` (
    `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tournament_id`      INT UNSIGNED  NOT NULL,
    `match_number`       SMALLINT UNSIGNED NOT NULL,
    `stage`              ENUM('league','qualifier','eliminator','semi_final','final')
                         NOT NULL DEFAULT 'league',

    `team_a_id`          INT UNSIGNED  NOT NULL,
    `team_b_id`          INT UNSIGNED  NOT NULL,
    `venue`              VARCHAR(150)      NULL,
    `scheduled_at`       DATETIME      NOT NULL,
    `overs_per_innings`  TINYINT UNSIGNED NOT NULL DEFAULT 20,

    `toss_winner_team_id` INT UNSIGNED     NULL,
    `toss_decision`      ENUM('bat','bowl') NULL,

    `status`             ENUM('scheduled','toss','live','innings_break','completed','abandoned')
                         NOT NULL DEFAULT 'scheduled',
    `winner_team_id`     INT UNSIGNED      NULL,  /* NULL for tie / no result */
    `result_text`        VARCHAR(180)      NULL,  /* "MI won by 6 wickets" */
    `player_of_match_id` INT UNSIGNED      NULL,
    `scorer_user_id`     INT UNSIGNED      NULL,  /* assigned scorer */

    `created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_match_number` (`tournament_id`, `match_number`),
    KEY `idx_match_schedule` (`tournament_id`, `scheduled_at`),
    KEY `idx_match_status`   (`status`),
    KEY `idx_match_teams`    (`team_a_id`, `team_b_id`),

    CONSTRAINT `fk_matches_tournament`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_matches_team_a`
        FOREIGN KEY (`team_a_id`) REFERENCES `teams` (`id`),
    CONSTRAINT `fk_matches_team_b`
        FOREIGN KEY (`team_b_id`) REFERENCES `teams` (`id`),
    CONSTRAINT `fk_matches_toss_winner`
        FOREIGN KEY (`toss_winner_team_id`) REFERENCES `teams` (`id`),
    CONSTRAINT `fk_matches_winner`
        FOREIGN KEY (`winner_team_id`) REFERENCES `teams` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_matches_potm`
        FOREIGN KEY (`player_of_match_id`) REFERENCES `players` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_matches_scorer`
        FOREIGN KEY (`scorer_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `chk_match_distinct_teams` CHECK (`team_a_id` <> `team_b_id`),
    CONSTRAINT `chk_match_toss` CHECK (
        (`toss_winner_team_id` IS NULL AND `toss_decision` IS NULL)
        OR (`toss_winner_team_id` IS NOT NULL AND `toss_decision` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   match_squads — the playing XI per match. Guarantees a player can't be
   listed twice in one match and that only one captain/keeper per side.
   ---------------------------------------------------------------------
*/
CREATE TABLE `match_squads` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_id`       INT UNSIGNED NOT NULL,
    `team_id`        INT UNSIGNED NOT NULL,
    `player_id`      INT UNSIGNED NOT NULL,
    `batting_order`  TINYINT UNSIGNED NULL,
    `is_playing_xi`  TINYINT(1)   NOT NULL DEFAULT 1,
    `is_captain`     TINYINT(1)   NOT NULL DEFAULT 0,
    `is_wicket_keeper` TINYINT(1) NOT NULL DEFAULT 0,
    `is_impact_sub`  TINYINT(1)   NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_squad_player` (`match_id`, `player_id`),
    KEY `idx_squad_team` (`match_id`, `team_id`, `batting_order`),

    CONSTRAINT `fk_squads_match`
        FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_squads_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_squads_player`
        FOREIGN KEY (`player_id`) REFERENCES `players` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   innings — running totals, maintained incrementally as balls arrive so
   the live scorecard never has to aggregate the whole ball log.
   ---------------------------------------------------------------------
*/
CREATE TABLE `innings` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_id`         INT UNSIGNED NOT NULL,
    `innings_number`   TINYINT UNSIGNED NOT NULL,  /* 1 or 2 (3/4 reserved for super over) */
    `batting_team_id`  INT UNSIGNED NOT NULL,
    `bowling_team_id`  INT UNSIGNED NOT NULL,

    `total_runs`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_wickets`    TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    `legal_balls`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,  /* overs = legal_balls DIV 6 */
    `extras_wide`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `extras_no_ball`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `extras_bye`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `extras_leg_bye`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `extras_penalty`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    `target`           SMALLINT UNSIGNED NULL,  /* set for the 2nd innings */
    `is_completed`     TINYINT(1) NOT NULL DEFAULT 0,
    `started_at`       DATETIME NULL,
    `ended_at`         DATETIME NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_innings` (`match_id`, `innings_number`),
    KEY `idx_innings_batting` (`batting_team_id`),

    CONSTRAINT `fk_innings_match`
        FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_innings_batting_team`
        FOREIGN KEY (`batting_team_id`) REFERENCES `teams` (`id`),
    CONSTRAINT `fk_innings_bowling_team`
        FOREIGN KEY (`bowling_team_id`) REFERENCES `teams` (`id`),

    CONSTRAINT `chk_innings_teams`   CHECK (`batting_team_id` <> `bowling_team_id`),
    CONSTRAINT `chk_innings_wickets` CHECK (`total_wickets` <= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   ---------------------------------------------------------------------
   ball_by_ball — the append-only event log. Every scorecard number in the
   app is derivable from this table alone; `innings` is just a cache.

   runs_scored = runs_off_bat + extra_runs
   is_legal_delivery = 0 for wide / no-ball (they don't advance the over)
   ball_sequence is a monotonic counter per innings, so an "Undo" in the
   scorer pad is DELETE … ORDER BY ball_sequence DESC LIMIT 1.
   ---------------------------------------------------------------------
*/
CREATE TABLE `ball_by_ball` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_id`         INT UNSIGNED    NOT NULL,
    `innings_id`       BIGINT UNSIGNED NOT NULL,
    `ball_sequence`    SMALLINT UNSIGNED NOT NULL,  /* 1..n across the innings, incl. extras */
    `over_number`      TINYINT UNSIGNED  NOT NULL,  /* 0-based: over_number 0 = 1st over */
    `ball_in_over`     TINYINT UNSIGNED  NOT NULL,  /* 1..6 for legal deliveries */

    `striker_id`       INT UNSIGNED NOT NULL,
    `non_striker_id`   INT UNSIGNED NOT NULL,
    `bowler_id`        INT UNSIGNED NOT NULL,

    `runs_off_bat`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `extra_runs`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `extra_type`       ENUM('none','wide','no_ball','bye','leg_bye','penalty')
                       NOT NULL DEFAULT 'none',
    `is_legal_delivery` TINYINT(1) NOT NULL DEFAULT 1,
    `is_boundary_four` TINYINT(1) NOT NULL DEFAULT 0,
    `is_boundary_six`  TINYINT(1) NOT NULL DEFAULT 0,

    `is_wicket`        TINYINT(1) NOT NULL DEFAULT 0,
    `dismissal_type`   ENUM('bowled','caught','lbw','run_out','stumped','hit_wicket',
                            'retired_hurt','obstructing_field') NULL,
    `dismissed_player_id` INT UNSIGNED NULL,
    `fielder_id`       INT UNSIGNED NULL,

    `commentary`       VARCHAR(255) NULL,
    `scored_by_user_id` INT UNSIGNED NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ball_sequence` (`innings_id`, `ball_sequence`),
    KEY `idx_ball_innings_over` (`innings_id`, `over_number`, `ball_in_over`),
    KEY `idx_ball_match`        (`match_id`),
    KEY `idx_ball_striker`      (`innings_id`, `striker_id`),  /* batting card */
    KEY `idx_ball_bowler`       (`innings_id`, `bowler_id`),  /* bowling figures */

    CONSTRAINT `fk_balls_match`
        FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_balls_innings`
        FOREIGN KEY (`innings_id`) REFERENCES `innings` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_balls_striker`
        FOREIGN KEY (`striker_id`) REFERENCES `players` (`id`),
    CONSTRAINT `fk_balls_non_striker`
        FOREIGN KEY (`non_striker_id`) REFERENCES `players` (`id`),
    CONSTRAINT `fk_balls_bowler`
        FOREIGN KEY (`bowler_id`) REFERENCES `players` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_balls_dismissed`
        FOREIGN KEY (`dismissed_player_id`) REFERENCES `players` (`id`),
    CONSTRAINT `fk_balls_fielder`
        FOREIGN KEY (`fielder_id`) REFERENCES `players` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_balls_scorer`
        FOREIGN KEY (`scored_by_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `chk_ball_batters`   CHECK (`striker_id` <> `non_striker_id`),
    CONSTRAINT `chk_ball_runs`      CHECK (`runs_off_bat` <= 7 AND `extra_runs` <= 10),
    CONSTRAINT `chk_ball_wicket`    CHECK (
        (`is_wicket` = 1 AND `dismissal_type` IS NOT NULL AND `dismissed_player_id` IS NOT NULL)
        OR (`is_wicket` = 0 AND `dismissal_type` IS NULL AND `dismissed_player_id` IS NULL)
    ),
/* Wides and no-balls never count towards the over. */
    CONSTRAINT `chk_ball_legality`  CHECK (
        (`extra_type` IN ('wide','no_ball') AND `is_legal_delivery` = 0)
        OR (`extra_type` NOT IN ('wide','no_ball') AND `is_legal_delivery` = 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/*
   =====================================================================
   Read models — the dashboard queries these instead of hand-rolling joins
   =====================================================================
*/

/* Live auction state: one row per tournament currently under the hammer. */
CREATE OR REPLACE VIEW `v_live_auction` AS
SELECT  l.`id`                AS lot_id,
        l.`tournament_id`,
        l.`status`            AS lot_status,
        l.`lot_order`,
        l.`base_price`,
        l.`current_bid`,
        l.`bid_count`,
        l.`ends_at`,
        p.`id`                AS player_id,
        p.`full_name`,
        p.`display_name`,
        p.`photo_url`,
        p.`role`,
        p.`country`,
        p.`is_overseas`,
        p.`auction_set`,
        p.`career_matches`,
        p.`career_runs`,
        p.`career_wickets`,
        p.`strike_rate`,
        p.`economy`,
        t.`id`                AS bidder_team_id,
        t.`name`              AS bidder_team_name,
        t.`short_name`        AS bidder_team_short,
        t.`primary_color`     AS bidder_team_color
FROM    `auction_lots` l
JOIN    `players` p ON p.`id` = l.`player_id`
LEFT JOIN `teams` t ON t.`id` = l.`current_bidder_team_id`
WHERE   l.`status` IN ('live','paused');

/* Batting card: derived purely from the ball log. */
CREATE OR REPLACE VIEW `v_batting_card` AS
SELECT  b.`innings_id`,
        b.`striker_id`                                   AS player_id,
        p.`display_name`,
        SUM(b.`runs_off_bat`)                            AS runs,
        SUM(b.`is_legal_delivery`)                       AS balls_faced,
        SUM(b.`is_boundary_four`)                        AS fours,
        SUM(b.`is_boundary_six`)                         AS sixes,
        ROUND(SUM(b.`runs_off_bat`) * 100
              / NULLIF(SUM(b.`is_legal_delivery`), 0), 2) AS strike_rate
FROM    `ball_by_ball` b
JOIN    `players` p ON p.`id` = b.`striker_id`
GROUP BY b.`innings_id`, b.`striker_id`, p.`display_name`;

/* Bowling figures: overs, maidens excluded here (computed in the model). */
CREATE OR REPLACE VIEW `v_bowling_card` AS
SELECT  b.`innings_id`,
        b.`bowler_id`                                    AS player_id,
        p.`display_name`,
        SUM(b.`is_legal_delivery`)                       AS balls_bowled,
        CONCAT(FLOOR(SUM(b.`is_legal_delivery`) / 6), '.',
               MOD(SUM(b.`is_legal_delivery`), 6))       AS overs,
        SUM(b.`runs_off_bat`
            + CASE WHEN b.`extra_type` IN ('wide','no_ball')
                   THEN b.`extra_runs` ELSE 0 END)       AS runs_conceded,
        SUM(CASE WHEN b.`is_wicket` = 1
                  AND b.`dismissal_type` IN ('bowled','caught','lbw','stumped','hit_wicket')
                 THEN 1 ELSE 0 END)                      AS wickets,
        ROUND(SUM(b.`runs_off_bat`
              + CASE WHEN b.`extra_type` IN ('wide','no_ball')
                     THEN b.`extra_runs` ELSE 0 END) * 6.0
              / NULLIF(SUM(b.`is_legal_delivery`), 0), 2) AS economy
FROM    `ball_by_ball` b
JOIN    `players` p ON p.`id` = b.`bowler_id`
GROUP BY b.`innings_id`, b.`bowler_id`, p.`display_name`;
