/*
   =====================================================================
   CricAuction — demo data
   Run AFTER schema.sql:   mysql -u root -p < database/seed.sql

   Every seeded password is:  Passw0rd!
   (bcrypt hash below was produced with password_hash('Passw0rd!', PASSWORD_BCRYPT).
   Rotate these before any deployment that is reachable from a network.)
   =====================================================================
*/

USE `cric_auction`;

SET @PW := '$2y$12$/3GpV2gZUFU.FsEHVd.Xb.2zu/YztqYJ5O7Wy508XZcWgiKGkf2iW';

/* ---------------------------------------------------------------- tournament */
INSERT INTO `tournaments`
    (`id`, `name`, `season_year`, `purse_per_team`, `min_squad_size`, `max_squad_size`,
     `max_overseas`, `bid_increment`, `bid_timer_seconds`, `overs_per_innings`, `status`)
VALUES
    (1, 'Corporate Premier League', 2026, 10000000.00, 11, 15, 4, 500000.00, 30, 20, 'auction');

/* --------------------------------------------------------------------- teams */
INSERT INTO `teams`
    (`id`, `tournament_id`, `name`, `short_name`, `primary_color`, `home_venue`,
     `purse_total`, `purse_spent`, `players_bought`, `overseas_bought`)
VALUES
    (1, 1, 'Titan Strikers',  'TS',  '#22c55e', 'Chinnaswamy',  10000000.00, 4250000.00, 6, 1),
    (2, 1, 'Royal Chargers',  'RC',  '#f59e0b', 'Wankhede',     10000000.00, 6100000.00, 7, 2),
    (3, 1, 'Coastal Kings',   'CK',  '#38bdf8', 'Chepauk',      10000000.00, 2900000.00, 5, 1),
    (4, 1, 'Desert Falcons',  'DF',  '#a855f7', 'Eden Gardens', 10000000.00, 7350000.00, 8, 3);

/*
   --------------------------------------------------------------------- users
   Usernames are set here too. Sign-in takes a username or an email, and a
   seeded database that only answers to an email quietly contradicts every
   screen and guide that says either will do.
*/
INSERT INTO `users` (`username`, `name`, `email`, `password_hash`, `role`, `status`, `team_id`) VALUES
    ('admin',    'Arjun Mehta',   'admin@cricauction.test',    @PW, 'admin',      'approved', NULL),
    ('scorer',   'Priya Nair',    'scorer@cricauction.test',   @PW, 'scorer',     'approved', NULL),
    ('viewer',   'Guest Viewer',  'viewer@cricauction.test',   @PW, 'viewer',     'approved', NULL),
    ('owner.ts', 'Rahul Verma',   'owner.ts@cricauction.test', @PW, 'team_owner', 'approved', 1),
    ('owner.rc', 'Sneha Iyer',    'owner.rc@cricauction.test', @PW, 'team_owner', 'approved', 2),
    ('owner.ck', 'Imran Qureshi', 'owner.ck@cricauction.test', @PW, 'team_owner', 'approved', 3),
    ('owner.df', 'Meera Kapoor',  'owner.df@cricauction.test', @PW, 'team_owner', 'approved', 4);

/* ------------------------------------------------------------------- players */
INSERT INTO `players`
    (`id`, `tournament_id`, `full_name`, `display_name`, `country`, `role`, `batting_style`,
     `bowling_style`, `is_overseas`, `is_capped`, `auction_set`, `base_price`,
     `career_matches`, `career_runs`, `career_wickets`, `strike_rate`, `economy`,
     `status`, `team_id`, `sold_price`)
VALUES
/* currently under the hammer */
    (1, 1, 'Kabir Anand',   'K Anand',   'India',        'batting_all_rounder',   'right_hand', 'right_arm_medium',  0, 1, 'Marquee', 2000000.00, 92, 2148,  74, 148.60, 7.85, 'in_auction', NULL, NULL),
/* queued */
    (2, 1, 'Ryan Fletcher', 'R Fletcher','Australia',    'batsman',       'left_hand',  'none',              1, 1, 'Marquee', 2000000.00, 71, 2610,   2, 141.20, 0.00, 'available',  NULL, NULL),
    (3, 1, 'Dev Sharma',    'D Sharma',  'India',        'bowler',        'right_hand', 'right_arm_fast',    0, 0, 'Set A',   1000000.00, 44,  180,  61,  96.40, 7.42, 'available',  NULL, NULL),
    (4, 1, 'Thabo Nkosi',   'T Nkosi',   'South Africa', 'wicket_keeper', 'right_hand', 'none',              1, 1, 'Set A',   1500000.00, 58, 1466,   0, 133.80, 0.00, 'available',  NULL, NULL),
    (5, 1, 'Aarav Menon',   'A Menon',   'India',        'bowling_all_rounder',   'left_hand',  'left_arm_orthodox', 0, 0, 'Set A',    500000.00, 26,  412,  23, 127.10, 7.10, 'available',  NULL, NULL),
    (6, 1, 'Jos Hartley',   'J Hartley', 'England',      'bowler',        'right_hand', 'right_arm_fast',    1, 1, 'Set B',   1200000.00, 63,  240,  88,  88.20, 8.05, 'available',  NULL, NULL),
/* already sold */
    (7, 1, 'Vikram Rao',    'V Rao',     'India',        'batsman',       'right_hand', 'none',              0, 1, 'Marquee', 2000000.00, 108, 3204,  1, 139.90, 0.00, 'sold', 2, 3400000.00),
    (8, 1, 'Sameer Khan',   'S Khan',    'India',        'bowler',        'left_hand',  'left_arm_fast',     0, 0, 'Set A',   1000000.00,  39,   96, 52,  74.30, 7.68, 'sold', 4, 2600000.00),
    (9, 1, 'Liam Carter',   'L Carter',  'New Zealand',  'all_rounder',   'right_hand', 'right_arm_medium',  1, 1, 'Set A',   1500000.00,  67, 1180, 45, 132.40, 8.20, 'sold', 1, 1800000.00),
    (10,1, 'Nikhil Bose',   'N Bose',    'India',        'wicket_keeper', 'right_hand', 'none',              0, 0, 'Set B',    500000.00,  31,  684,  0, 124.60, 0.00, 'sold', 3, 900000.00);

/* --------------------------------------------------------------- auction lots */
INSERT INTO `auction_lots`
    (`id`, `tournament_id`, `player_id`, `lot_order`, `status`, `base_price`,
     `current_bid`, `current_bidder_team_id`, `bid_count`, `started_at`, `ends_at`,
     `sold_to_team_id`, `sold_price`, `closed_at`)
VALUES
    (1, 1,  7, 1, 'sold',   2000000.00, 3400000.00, 2, 9, NOW() - INTERVAL 40 MINUTE, NULL, 2, 3400000.00, NOW() - INTERVAL 38 MINUTE),
    (2, 1,  8, 2, 'sold',   1000000.00, 2600000.00, 4, 7, NOW() - INTERVAL 32 MINUTE, NULL, 4, 2600000.00, NOW() - INTERVAL 30 MINUTE),
    (3, 1,  9, 3, 'sold',   1500000.00, 1800000.00, 1, 4, NOW() - INTERVAL 24 MINUTE, NULL, 1, 1800000.00, NOW() - INTERVAL 22 MINUTE),
    (4, 1, 10, 4, 'sold',    500000.00,  900000.00, 3, 5, NOW() - INTERVAL 16 MINUTE, NULL, 3,  900000.00, NOW() - INTERVAL 14 MINUTE),
    (5, 1,  1, 5, 'live',   2000000.00, 3500000.00, 1, 4, NOW() - INTERVAL 2 MINUTE, NOW() + INTERVAL 22 SECOND, NULL, NULL, NULL),
    (6, 1,  2, 6, 'queued', 2000000.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
    (7, 1,  4, 7, 'queued', 1500000.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
    (8, 1,  3, 8, 'queued', 1000000.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
    (9, 1,  6, 9, 'queued', 1200000.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
    (10,1,  5,10, 'queued',  500000.00, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL);

/*
   ---------------------------------------------------- bids on the live lot (5)
   The ladder sits on the tournament's ₹5 L increment grid starting from the
   ₹20 L base price, and every team in it can actually cover its own bid:
   Royal Chargers and Desert Falcons are already too committed to compete here.
*/
INSERT INTO `auction_bids` (`lot_id`, `player_id`, `team_id`, `user_id`, `bid_amount`, `placed_at`) VALUES
    (5, 1, 3, 6, 2000000.00, NOW() - INTERVAL 110 SECOND),
    (5, 1, 1, 4, 2500000.00, NOW() - INTERVAL  74 SECOND),
    (5, 1, 3, 6, 3000000.00, NOW() - INTERVAL  33 SECOND),
    (5, 1, 1, 4, 3500000.00, NOW() - INTERVAL   9 SECOND);

/* ------------------------------------------------------------------- fixtures */
INSERT INTO `matches`
    (`id`, `tournament_id`, `match_number`, `stage`, `team_a_id`, `team_b_id`, `venue`,
     `scheduled_at`, `overs_per_innings`, `toss_winner_team_id`, `toss_decision`,
     `status`, `scorer_user_id`)
VALUES
    (1, 1, 1, 'league', 1, 2, 'Chinnaswamy', NOW() + INTERVAL 3 DAY, 20, NULL,  NULL,  'scheduled', 2),
    (2, 1, 2, 'league', 3, 4, 'Chepauk',     NOW() + INTERVAL 4 DAY, 20, NULL,  NULL,  'scheduled', 2);
