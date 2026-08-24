/*
   =====================================================================
   CricAuction — live match fixture for the scorer pad

   Run AFTER schema.sql and seed.sql:
   mysql -u root -p < database/seed_match.sql

   Puts match 1 (Titan Strikers v Royal Chargers) in progress with two
   named XIs and an open first innings, so public/score.php has something
   real to score. Kept separate from seed.sql so the auction fixtures — and
   the numbers tests/auction_test.php asserts — stay untouched.
   =====================================================================
*/

USE `cric_auction`;

/*
   Squad players. The auction seed only sells four players; a match needs 22.
   These are recorded as ordinary sold players so every foreign key and the
   chk_player_sold constraint hold exactly as they would in a real season.
*/
INSERT INTO `players`
    (`id`, `tournament_id`, `full_name`, `display_name`, `country`, `role`,
     `batting_style`, `bowling_style`, `is_overseas`, `base_price`,
     `status`, `team_id`, `sold_price`)
VALUES
/* Titan Strikers (team 1) */
    (11, 1, 'Aditya Rathore',   'A Rathore',   'India',        'batsman',       'right_hand', 'none',              0, 500000.00, 'sold', 1, 500000.00),
    (12, 1, 'Mario Fernandes',  'M Fernandes', 'India',        'wicket_keeper', 'right_hand', 'none',              0, 500000.00, 'sold', 1, 500000.00),
    (13, 1, 'Simran Grewal',    'S Grewal',    'India',        'all_rounder',   'left_hand',  'left_arm_orthodox', 0, 500000.00, 'sold', 1, 500000.00),
    (14, 1, 'Rohan Iyer',       'R Iyer',      'India',        'batsman',       'right_hand', 'none',              0, 500000.00, 'sold', 1, 500000.00),
    (15, 1, 'Tejas Bhat',       'T Bhat',      'India',        'bowler',        'right_hand', 'right_arm_fast',    0, 500000.00, 'sold', 1, 500000.00),
    (16, 1, 'Prakash Naidu',    'P Naidu',     'India',        'bowler',        'right_hand', 'right_arm_offbreak',0, 500000.00, 'sold', 1, 500000.00),
    (17, 1, 'Devendra Kulkarni','D Kulkarni',  'India',        'bowler',        'left_hand',  'left_arm_fast',     0, 500000.00, 'sold', 1, 500000.00),
    (18, 1, 'Harsh Shetty',     'H Shetty',    'India',        'all_rounder',   'right_hand', 'right_arm_medium',  0, 500000.00, 'sold', 1, 500000.00),
    (19, 1, 'Nikhil Kaul',      'N Kaul',      'India',        'batsman',       'left_hand',  'none',              0, 500000.00, 'sold', 1, 500000.00),
    (20, 1, 'Joel Pereira',     'J Pereira',   'India',        'bowler',        'right_hand', 'right_arm_legbreak',0, 500000.00, 'sold', 1, 500000.00),

/* Royal Chargers (team 2) */
    (21, 1, 'Ganesh Pillai',    'G Pillai',    'India',        'bowler',        'right_hand', 'right_arm_medium',  0, 500000.00, 'sold', 2, 500000.00),
    (22, 1, 'Hardik Malhotra',  'H Malhotra',  'India',        'bowler',        'right_hand', 'right_arm_offbreak',0, 500000.00, 'sold', 2, 500000.00),
    (23, 1, 'Yash Tandon',      'Y Tandon',    'India',        'bowler',        'right_hand', 'right_arm_legbreak',0, 500000.00, 'sold', 2, 500000.00),
    (24, 1, 'Brian Oduya',      'B Oduya',     'Kenya',        'bowler',        'left_hand',  'left_arm_orthodox', 1, 500000.00, 'sold', 2, 500000.00),
    (25, 1, 'Chris Whitfield',  'C Whitfield', 'England',      'all_rounder',   'right_hand', 'right_arm_medium',  1, 500000.00, 'sold', 2, 500000.00),
    (26, 1, 'Alan Sequeira',    'A Sequeira',  'India',        'bowler',        'right_hand', 'right_arm_fast',    0, 500000.00, 'sold', 2, 500000.00),
    (27, 1, 'Faizan Ali',       'F Ali',       'India',        'all_rounder',   'right_hand', 'right_arm_offbreak',0, 500000.00, 'sold', 2, 500000.00),
    (28, 1, 'Kartik Joshi',     'K Joshi',     'India',        'batsman',       'right_hand', 'none',              0, 500000.00, 'sold', 2, 500000.00),
    (29, 1, 'Elijah Mwangi',    'E Mwangi',    'Kenya',        'bowler',        'left_hand',  'left_arm_fast',     1, 500000.00, 'sold', 2, 500000.00),
    (30, 1, 'Ryan Dsouza',      'R Dsouza',    'India',        'wicket_keeper', 'right_hand', 'none',              0, 500000.00, 'sold', 2, 500000.00);

/* Match 1 goes live. Titan Strikers won the toss and chose to bat. */
UPDATE `matches`
   SET `status`              = 'live',
       `toss_winner_team_id` = 1,
       `toss_decision`       = 'bat',
       `scheduled_at`        = NOW(),
       `scorer_user_id`      = (SELECT id FROM users WHERE email = 'scorer@cricauction.test')
 WHERE `id` = 1;

/* Playing XIs. batting_order drives the "next batter in" list. */
INSERT INTO `match_squads`
    (`match_id`, `team_id`, `player_id`, `batting_order`, `is_playing_xi`, `is_captain`, `is_wicket_keeper`)
VALUES
/* Titan Strikers */
    (1, 1, 11,  1, 1, 0, 0),
    (1, 1,  9,  2, 1, 1, 0),  /* L Carter, bought at auction */
    (1, 1, 14,  3, 1, 0, 0),
    (1, 1, 19,  4, 1, 0, 0),
    (1, 1, 12,  5, 1, 0, 1),
    (1, 1, 13,  6, 1, 0, 0),
    (1, 1, 18,  7, 1, 0, 0),
    (1, 1, 15,  8, 1, 0, 0),
    (1, 1, 17,  9, 1, 0, 0),
    (1, 1, 20, 10, 1, 0, 0),
    (1, 1, 16, 11, 1, 0, 0),

/* Royal Chargers */
    (1, 2,  7,  1, 1, 1, 0),  /* V Rao, bought at auction */
    (1, 2, 28,  2, 1, 0, 0),
    (1, 2, 25,  3, 1, 0, 0),
    (1, 2, 30,  4, 1, 0, 1),
    (1, 2, 27,  5, 1, 0, 0),
    (1, 2, 21,  6, 1, 0, 0),
    (1, 2, 22,  7, 1, 0, 0),
    (1, 2, 23,  8, 1, 0, 0),
    (1, 2, 24,  9, 1, 0, 0),
    (1, 2, 26, 10, 1, 0, 0),
    (1, 2, 29, 11, 1, 0, 0);

/*
   First innings, nothing bowled yet. The scorer names the opening pair and
   the bowler with the first ball.
*/
INSERT INTO `innings`
    (`id`, `match_id`, `innings_number`, `batting_team_id`, `bowling_team_id`, `started_at`)
VALUES
    (1, 1, 1, 1, 2, NOW());
