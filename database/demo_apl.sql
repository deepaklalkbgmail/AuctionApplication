/*
   =====================================================================
   APL — demonstration dataset

   A complete, coherent tournament for showing the application: six
   franchises, a 60-player pool, an auction part way through with a
   player under the hammer, and a match ready to be scored from the
   first ball.

   Load database/reset.sql FIRST — this file assumes empty tables.

   Every account here uses the password  ChangeMe@2026
   This is demonstration data. Run reset.sql again before real use.
   =====================================================================
*/

INSERT INTO `tournaments` (`id`,`name`,`season_year`,`secret_code`,`auction_date`,`start_date`,`end_date`,`team_name_change_deadline`,`registration_open`,`purse_per_team`,`min_squad_size`,`max_squad_size`,`max_overseas`,`bid_increment`,`bid_timer_seconds`,`overs_per_innings`,`balls_per_over`,`status`) VALUES
  (1, 'APL', 2026, 'BATSMAN7', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_ADD(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 1, 50000000.00, 11, 15, 4, 500000.00, 30, 20, 6, 'auction');

INSERT INTO `teams` (`id`,`tournament_id`,`name`,`short_name`,`primary_color`,`home_venue`,`purse_total`,`purse_spent`,`players_bought`,`overseas_bought`) VALUES
  (1, 1, 'Coastal Titans', 'CT', '#22c55e', 'Marine Drive Ground', 50000000.00, 33000000.00, 11, 0),
  (2, 1, 'Metro Royals', 'MR', '#f59e0b', 'City Sports Complex', 50000000.00, 28500000.00, 11, 0),
  (3, 1, 'Highland Chargers', 'HC', '#38bdf8', 'Hill View Oval', 50000000.00, 8000000.00, 4, 0),
  (4, 1, 'Desert Falcons', 'DF', '#a855f7', 'Sandstone Arena', 50000000.00, 6000000.00, 4, 0),
  (5, 1, 'Harbour Warriors', 'HW', '#f43f5e', 'Dockside Ground', 50000000.00, 4400000.00, 3, 0),
  (6, 1, 'Summit Lions', 'SL', '#14b8a6', 'Ridge Stadium', 50000000.00, 5700000.00, 3, 0);

INSERT INTO `users` (`username`,`name`,`email`,`password_hash`,`role`,`status`,`team_id`) VALUES
  ('apl.admin', 'Tournament Director', 'admin@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'admin', 'approved', NULL),
  ('apl.scorer', 'Match Scorer', 'scorer@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'scorer', 'approved', NULL),
  ('apl.viewer', 'Guest Viewer', 'viewer@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'viewer', 'approved', NULL),
  ('apl.ct', 'Coastal Titans Owner', 'ct@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'team_owner', 'approved', 1),
  ('apl.mr', 'Metro Royals Owner', 'mr@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'team_owner', 'approved', 2),
  ('apl.hc', 'Highland Chargers Owner', 'hc@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'team_owner', 'approved', 3),
  ('apl.df', 'Desert Falcons Owner', 'df@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'team_owner', 'approved', 4),
  ('apl.hw', 'Harbour Warriors Owner', 'hw@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'team_owner', 'approved', 5),
  ('apl.sl', 'Summit Lions Owner', 'sl@apl.local', '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK', 'team_owner', 'approved', 6);

INSERT INTO `players` (`id`,`tournament_id`,`full_name`,`display_name`,`country`,`role`,`batting_style`,`bowling_style`,`is_overseas`,`is_capped`,`auction_set`,`base_price`,`career_matches`,`career_runs`,`career_wickets`,`strike_rate`,`economy`,`status`,`team_id`,`sold_price`) VALUES
  (1,1,'Aditya Bhat','A Bhat','India','bowler','left_hand','right_arm_medium',0,1,'Marquee',2000000.00,23,13,20,106.70,7.10,'sold',1,3500000.00),
  (2,1,'Gaurav Ghosh','G Ghosh','India','all_rounder','right_hand','right_arm_offbreak',0,1,'Marquee',2000000.00,26,394,25,108.40,7.80,'sold',1,4000000.00),
  (3,1,'Naveen Nair','N Nair','India','wicket_keeper','left_hand','none',0,1,'Marquee',2000000.00,29,441,0,110.10,0.00,'sold',1,2500000.00),
  (4,1,'Varun Pillai','V Pillai','India','batsman','right_hand','none',0,1,'Marquee',2000000.00,32,488,0,111.80,0.00,'sold',1,3000000.00),
  (5,1,'Girish Tiwari','G Tiwari','India','bowler','left_hand','left_arm_medium',0,1,'Set A',1500000.00,35,65,40,113.50,7.30,'sold',1,3000000.00),
  (6,1,'Rakesh Patel','R Patel','India','all_rounder','right_hand','left_arm_orthodox',0,1,'Set A',1500000.00,38,582,45,115.20,8.00,'sold',1,3500000.00),
  (7,1,'Dinesh Malhotra','D Malhotra','India','wicket_keeper','left_hand','none',0,1,'Set A',1500000.00,41,629,0,116.90,0.00,'sold',2,2500000.00),
  (8,1,'Prakash Aggarwal','P Aggarwal','India','batsman','right_hand','none',0,1,'Set A',1500000.00,44,676,0,118.60,0.00,'sold',1,2500000.00),
  (9,1,'Arjun Kulkarni','A Kulkarni','India','bowler','left_hand','right_arm_offbreak',0,1,'Set A',1500000.00,47,117,60,120.30,7.50,'sold',1,3000000.00),
  (10,1,'Rahul Bose','R Bose','India','all_rounder','right_hand','right_arm_legbreak',0,1,'Set A',1500000.00,50,770,65,122.00,8.20,'sold',2,2000000.00),
  (11,1,'Jatin Verma','J Verma','India','wicket_keeper','left_hand','none',0,1,'Set A',1500000.00,53,817,0,123.70,0.00,'in_auction',NULL,NULL),
  (12,1,'Ritesh Shetty','R Shetty','India','batsman','right_hand','none',0,1,'Set A',1500000.00,56,864,0,125.40,0.00,'sold',1,2500000.00),
  (13,1,'Bhavesh Mishra','B Mishra','India','bowler','left_hand','left_arm_orthodox',0,1,'Set A',1500000.00,59,169,80,127.10,7.70,'sold',1,3000000.00),
  (14,1,'Keshav Menon','K Menon','India','all_rounder','right_hand','right_arm_fast',0,1,'Set A',1500000.00,62,958,85,128.80,8.40,'sold',2,2000000.00),
  (15,1,'Vikas Gowda','V Gowda','India','wicket_keeper','left_hand','none',0,1,'Set A',1500000.00,65,1005,0,130.50,0.00,'available',NULL,NULL),
  (16,1,'Jagdish Yadav','J Yadav','India','batsman','right_hand','none',0,1,'Set A',1500000.00,68,1052,0,132.20,0.00,'sold',1,2500000.00),
  (17,1,'Vinod Joshi','V Joshi','India','bowler','left_hand','right_arm_legbreak',0,0,'Set B',1000000.00,71,221,20,133.90,7.90,'sold',2,3000000.00),
  (18,1,'Rohan Kapoor','R Kapoor','India','all_rounder','right_hand','left_arm_fast',0,0,'Set B',1000000.00,74,1146,25,135.60,8.60,'sold',3,2000000.00),
  (19,1,'Farhan Bajaj','F Bajaj','India','wicket_keeper','left_hand','none',0,0,'Set B',1000000.00,77,1193,0,137.30,0.00,'available',NULL,NULL),
  (20,1,'Mohit Desai','M Desai','India','batsman','right_hand','none',0,0,'Set B',1000000.00,80,1240,0,139.00,0.00,'sold',2,2500000.00),
  (21,1,'Umesh Dutta','U Dutta','India','bowler','left_hand','right_arm_fast',0,0,'Set B',1000000.00,83,273,40,140.70,8.10,'sold',2,3000000.00),
  (22,1,'Eshan Iyer','E Iyer','India','all_rounder','right_hand','right_arm_medium',0,0,'Set B',1000000.00,86,1334,45,142.40,8.80,'sold',4,2500000.00),
  (23,1,'Parth Naidu','P Naidu','India','wicket_keeper','left_hand','none',0,0,'Set B',1000000.00,89,1381,0,144.10,0.00,'available',NULL,NULL),
  (24,1,'Chetan Pandey','C Pandey','India','batsman','right_hand','none',0,0,'Set B',1000000.00,92,1428,0,145.80,0.00,'sold',2,2500000.00),
  (25,1,'Naresh Reddy','N Reddy','India','bowler','left_hand','left_arm_fast',0,0,'Set B',1000000.00,95,5,60,147.50,8.30,'sold',2,3000000.00),
  (26,1,'Reyansh Chauhan','R Chauhan','India','all_rounder','right_hand','left_arm_medium',0,0,'Set B',1000000.00,98,1522,65,149.20,6.40,'sold',6,1500000.00),
  (27,1,'Nikhil Saxena','N Saxena','India','wicket_keeper','left_hand','none',0,0,'Set B',1000000.00,101,1569,0,150.90,0.00,'available',NULL,NULL),
  (28,1,'Imran Rao','I Rao','India','batsman','right_hand','none',0,0,'Set B',1000000.00,104,1616,0,152.60,0.00,'sold',2,2500000.00),
  (29,1,'Pranav Sinha','P Sinha','India','bowler','left_hand','right_arm_medium',0,0,'Set B',1000000.00,107,57,80,154.30,8.50,'sold',2,3000000.00),
  (30,1,'Abhay Sharma','A Sharma','India','all_rounder','right_hand','right_arm_offbreak',0,0,'Set B',1000000.00,20,1710,85,156.00,6.60,'available',NULL,NULL),
  (31,1,'Jayant Bhat','J Bhat','India','wicket_keeper','left_hand','none',0,0,'Set B',1000000.00,23,1757,0,157.70,0.00,'available',NULL,NULL),
  (32,1,'Tejas Ghosh','T Ghosh','India','batsman','right_hand','none',0,0,'Set B',1000000.00,26,1804,0,159.40,0.00,'sold',2,2500000.00),
  (33,1,'Hemant Nair','H Nair','India','bowler','left_hand','left_arm_medium',0,0,'Set C',500000.00,29,109,20,106.10,8.70,'sold',3,1000000.00),
  (34,1,'Suresh Pillai','S Pillai','India','all_rounder','right_hand','left_arm_orthodox',0,0,'Set C',500000.00,32,1898,25,107.80,6.80,'available',NULL,NULL),
  (35,1,'Kabir Tiwari','K Tiwari','India','wicket_keeper','left_hand','none',0,0,'Set C',500000.00,35,1945,0,109.50,0.00,'available',NULL,NULL),
  (36,1,'Devan Patel','D Patel','India','batsman','right_hand','none',0,0,'Set C',500000.00,38,1992,0,111.20,0.00,'sold',3,2500000.00),
  (37,1,'Lakshay Malhotra','L Malhotra','India','bowler','left_hand','right_arm_offbreak',0,0,'Set C',500000.00,41,161,40,112.90,8.90,'sold',4,1500000.00),
  (38,1,'Tarun Aggarwal','T Aggarwal','India','all_rounder','right_hand','right_arm_legbreak',0,0,'Set C',500000.00,44,2086,45,114.60,7.00,'available',NULL,NULL),
  (39,1,'Dhruv Kulkarni','D Kulkarni','India','wicket_keeper','left_hand','none',0,0,'Set C',500000.00,47,2133,0,116.30,0.00,'available',NULL,NULL),
  (40,1,'Nitin Bose','N Bose','India','batsman','right_hand','none',0,0,'Set C',500000.00,50,2180,0,118.00,0.00,'sold',3,2500000.00),
  (41,1,'Bharat Verma','B Verma','India','bowler','left_hand','left_arm_orthodox',0,0,'Set C',500000.00,53,213,60,119.70,6.50,'sold',5,2000000.00),
  (42,1,'Mahesh Shetty','M Shetty','India','all_rounder','right_hand','right_arm_fast',0,0,'Set C',500000.00,56,2274,65,121.40,7.20,'available',NULL,NULL),
  (43,1,'Vihaan Mishra','V Mishra','India','wicket_keeper','left_hand','none',0,0,'Set C',500000.00,59,2321,0,123.10,0.00,'available',NULL,NULL),
  (44,1,'Karan Menon','K Menon','India','batsman','right_hand','none',0,0,'Set C',500000.00,62,2368,0,124.80,0.00,'sold',4,1000000.00),
  (45,1,'Harsh Gowda','H Gowda','India','bowler','left_hand','right_arm_legbreak',0,0,'Set C',500000.00,65,265,80,126.50,6.70,'sold',6,2500000.00),
  (46,1,'Omkar Yadav','O Yadav','India','all_rounder','right_hand','left_arm_fast',0,0,'Set C',500000.00,68,2462,85,128.20,7.40,'available',NULL,NULL),
  (47,1,'Yash Joshi','Y Joshi','India','wicket_keeper','left_hand','none',0,0,'Set C',500000.00,71,2509,0,129.90,0.00,'available',NULL,NULL),
  (48,1,'Hitesh Kapoor','H Kapoor','India','batsman','right_hand','none',0,0,'Set C',500000.00,74,2556,0,131.60,0.00,'sold',4,1000000.00),
  (49,1,'Sahil Bajaj','S Bajaj','India','bowler','left_hand','right_arm_fast',0,0,'Set D',200000.00,77,317,20,133.30,6.90,'available',NULL,NULL),
  (50,1,'Ganesh Desai','G Desai','India','all_rounder','right_hand','right_arm_medium',0,0,'Set D',200000.00,80,2650,25,135.00,7.60,'available',NULL,NULL),
  (51,1,'Ramesh Dutta','R Dutta','India','wicket_keeper','left_hand','none',0,0,'Set D',200000.00,83,2697,0,136.70,0.00,'available',NULL,NULL),
  (52,1,'Ishaan Iyer','I Iyer','India','batsman','right_hand','none',0,0,'Set D',200000.00,86,2744,0,138.40,0.00,'sold',5,1200000.00),
  (53,1,'Manav Naidu','M Naidu','India','bowler','left_hand','left_arm_fast',0,0,'Set D',200000.00,89,49,40,140.10,7.10,'available',NULL,NULL),
  (54,1,'Kunal Pandey','K Pandey','India','all_rounder','right_hand','left_arm_medium',0,0,'Set D',200000.00,92,2838,45,141.80,7.80,'available',NULL,NULL),
  (55,1,'Sameer Reddy','S Reddy','India','wicket_keeper','left_hand','none',0,0,'Set D',200000.00,95,2885,0,143.50,0.00,'available',NULL,NULL),
  (56,1,'Chirag Chauhan','C Chauhan','India','batsman','right_hand','none',0,0,'Set D',200000.00,98,332,0,145.20,0.00,'sold',5,1200000.00),
  (57,1,'Mayank Saxena','M Saxena','India','bowler','left_hand','right_arm_medium',0,0,'Set D',200000.00,101,101,60,146.90,7.30,'available',NULL,NULL),
  (58,1,'Anand Rao','A Rao','India','all_rounder','right_hand','right_arm_offbreak',0,0,'Set D',200000.00,104,426,65,148.60,8.00,'available',NULL,NULL),
  (59,1,'Kiran Sinha','K Sinha','India','wicket_keeper','left_hand','none',0,0,'Set D',200000.00,107,473,0,150.30,0.00,'available',NULL,NULL),
  (60,1,'Aarav Sharma','A Sharma','India','batsman','right_hand','none',0,0,'Set D',200000.00,20,520,0,152.00,0.00,'sold',6,1700000.00);

INSERT INTO `auction_lots` (`tournament_id`,`player_id`,`lot_order`,`status`,`base_price`,`current_bid`,`current_bidder_team_id`,`bid_count`,`started_at`,`ends_at`,`closed_at`,`sold_to_team_id`,`sold_price`) VALUES
  (1,4,1,'sold',2000000.00,3000000.00,1,3,NOW() - INTERVAL 119 MINUTE,NULL,NOW() - INTERVAL 117 MINUTE,1,3000000.00),
  (1,8,2,'sold',1500000.00,2500000.00,1,3,NOW() - INTERVAL 118 MINUTE,NULL,NOW() - INTERVAL 116 MINUTE,1,2500000.00),
  (1,12,3,'sold',1500000.00,2500000.00,1,3,NOW() - INTERVAL 117 MINUTE,NULL,NOW() - INTERVAL 115 MINUTE,1,2500000.00),
  (1,16,4,'sold',1500000.00,2500000.00,1,3,NOW() - INTERVAL 116 MINUTE,NULL,NOW() - INTERVAL 114 MINUTE,1,2500000.00),
  (1,1,5,'sold',2000000.00,3500000.00,1,4,NOW() - INTERVAL 115 MINUTE,NULL,NOW() - INTERVAL 113 MINUTE,1,3500000.00),
  (1,5,6,'sold',1500000.00,3000000.00,1,4,NOW() - INTERVAL 114 MINUTE,NULL,NOW() - INTERVAL 112 MINUTE,1,3000000.00),
  (1,9,7,'sold',1500000.00,3000000.00,1,4,NOW() - INTERVAL 113 MINUTE,NULL,NOW() - INTERVAL 111 MINUTE,1,3000000.00),
  (1,13,8,'sold',1500000.00,3000000.00,1,4,NOW() - INTERVAL 112 MINUTE,NULL,NOW() - INTERVAL 110 MINUTE,1,3000000.00),
  (1,2,9,'sold',2000000.00,4000000.00,1,5,NOW() - INTERVAL 111 MINUTE,NULL,NOW() - INTERVAL 109 MINUTE,1,4000000.00),
  (1,6,10,'sold',1500000.00,3500000.00,1,5,NOW() - INTERVAL 110 MINUTE,NULL,NOW() - INTERVAL 108 MINUTE,1,3500000.00),
  (1,3,11,'sold',2000000.00,2500000.00,1,2,NOW() - INTERVAL 109 MINUTE,NULL,NOW() - INTERVAL 107 MINUTE,1,2500000.00),
  (1,20,12,'sold',1000000.00,2500000.00,2,4,NOW() - INTERVAL 108 MINUTE,NULL,NOW() - INTERVAL 106 MINUTE,2,2500000.00),
  (1,24,13,'sold',1000000.00,2500000.00,2,4,NOW() - INTERVAL 107 MINUTE,NULL,NOW() - INTERVAL 105 MINUTE,2,2500000.00),
  (1,28,14,'sold',1000000.00,2500000.00,2,4,NOW() - INTERVAL 106 MINUTE,NULL,NOW() - INTERVAL 104 MINUTE,2,2500000.00),
  (1,32,15,'sold',1000000.00,2500000.00,2,4,NOW() - INTERVAL 105 MINUTE,NULL,NOW() - INTERVAL 103 MINUTE,2,2500000.00),
  (1,17,16,'sold',1000000.00,3000000.00,2,5,NOW() - INTERVAL 104 MINUTE,NULL,NOW() - INTERVAL 102 MINUTE,2,3000000.00),
  (1,21,17,'sold',1000000.00,3000000.00,2,5,NOW() - INTERVAL 103 MINUTE,NULL,NOW() - INTERVAL 101 MINUTE,2,3000000.00),
  (1,25,18,'sold',1000000.00,3000000.00,2,5,NOW() - INTERVAL 102 MINUTE,NULL,NOW() - INTERVAL 100 MINUTE,2,3000000.00),
  (1,29,19,'sold',1000000.00,3000000.00,2,5,NOW() - INTERVAL 101 MINUTE,NULL,NOW() - INTERVAL 99 MINUTE,2,3000000.00),
  (1,10,20,'sold',1500000.00,2000000.00,2,2,NOW() - INTERVAL 100 MINUTE,NULL,NOW() - INTERVAL 98 MINUTE,2,2000000.00),
  (1,14,21,'sold',1500000.00,2000000.00,2,2,NOW() - INTERVAL 99 MINUTE,NULL,NOW() - INTERVAL 97 MINUTE,2,2000000.00),
  (1,7,22,'sold',1500000.00,2500000.00,2,3,NOW() - INTERVAL 98 MINUTE,NULL,NOW() - INTERVAL 96 MINUTE,2,2500000.00),
  (1,36,23,'sold',500000.00,2500000.00,3,5,NOW() - INTERVAL 97 MINUTE,NULL,NOW() - INTERVAL 95 MINUTE,3,2500000.00),
  (1,40,24,'sold',500000.00,2500000.00,3,5,NOW() - INTERVAL 96 MINUTE,NULL,NOW() - INTERVAL 94 MINUTE,3,2500000.00),
  (1,33,25,'sold',500000.00,1000000.00,3,2,NOW() - INTERVAL 95 MINUTE,NULL,NOW() - INTERVAL 93 MINUTE,3,1000000.00),
  (1,18,26,'sold',1000000.00,2000000.00,3,3,NOW() - INTERVAL 94 MINUTE,NULL,NOW() - INTERVAL 92 MINUTE,3,2000000.00),
  (1,44,27,'sold',500000.00,1000000.00,4,2,NOW() - INTERVAL 93 MINUTE,NULL,NOW() - INTERVAL 91 MINUTE,4,1000000.00),
  (1,48,28,'sold',500000.00,1000000.00,4,2,NOW() - INTERVAL 92 MINUTE,NULL,NOW() - INTERVAL 90 MINUTE,4,1000000.00),
  (1,37,29,'sold',500000.00,1500000.00,4,3,NOW() - INTERVAL 91 MINUTE,NULL,NOW() - INTERVAL 89 MINUTE,4,1500000.00),
  (1,22,30,'sold',1000000.00,2500000.00,4,4,NOW() - INTERVAL 90 MINUTE,NULL,NOW() - INTERVAL 88 MINUTE,4,2500000.00),
  (1,52,31,'sold',200000.00,1200000.00,5,3,NOW() - INTERVAL 89 MINUTE,NULL,NOW() - INTERVAL 87 MINUTE,5,1200000.00),
  (1,56,32,'sold',200000.00,1200000.00,5,3,NOW() - INTERVAL 88 MINUTE,NULL,NOW() - INTERVAL 86 MINUTE,5,1200000.00),
  (1,41,33,'sold',500000.00,2000000.00,5,4,NOW() - INTERVAL 87 MINUTE,NULL,NOW() - INTERVAL 85 MINUTE,5,2000000.00),
  (1,60,34,'sold',200000.00,1700000.00,6,4,NOW() - INTERVAL 86 MINUTE,NULL,NOW() - INTERVAL 84 MINUTE,6,1700000.00),
  (1,45,35,'sold',500000.00,2500000.00,6,5,NOW() - INTERVAL 85 MINUTE,NULL,NOW() - INTERVAL 83 MINUTE,6,2500000.00),
  (1,26,36,'sold',1000000.00,1500000.00,6,2,NOW() - INTERVAL 84 MINUTE,NULL,NOW() - INTERVAL 82 MINUTE,6,1500000.00),
  (1,11,37,'live',1500000.00,2500000.00,3,3,NOW() - INTERVAL 1 MINUTE,NOW() + INTERVAL 25 SECOND,NULL,NULL,NULL),
  (1,15,38,'queued',1500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,19,39,'queued',1000000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,23,40,'queued',1000000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,27,41,'queued',1000000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,30,42,'queued',1000000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,31,43,'queued',1000000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,34,44,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,35,45,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,38,46,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,39,47,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,42,48,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,43,49,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,46,50,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,47,51,'queued',500000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,49,52,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,50,53,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,51,54,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,53,55,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,54,56,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,55,57,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,57,58,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,58,59,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL),
  (1,59,60,'queued',200000.00,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL);

/* Bid ladder on the live lot: three teams, on the ₹5 L increment grid. */
SET @live_lot := (SELECT id FROM auction_lots WHERE player_id = 11);
INSERT INTO `auction_bids` (`lot_id`,`player_id`,`team_id`,`bid_amount`,`placed_at`) VALUES
  (@live_lot,11,3,1500000.00,NOW() - INTERVAL 46 SECOND),
  (@live_lot,11,5,2000000.00,NOW() - INTERVAL 28 SECOND),
  (@live_lot,11,3,2500000.00,NOW() - INTERVAL  6 SECOND);

/* A fixture between the two completed squads, ready to score from ball 1. */
INSERT INTO `matches` (`id`,`tournament_id`,`match_number`,`stage`,`team_a_id`,`team_b_id`,`venue`,`scheduled_at`,`overs_per_innings`,`toss_winner_team_id`,`toss_decision`,`status`,`scorer_user_id`) VALUES
  (1, 1, 1, 'league', 1, 2, 'Marine Drive Ground', NOW(), 20, 1, 'bat', 'live', (SELECT id FROM users WHERE email = 'scorer@apl.local'));

INSERT INTO `match_squads` (`match_id`,`team_id`,`player_id`,`batting_order`,`is_playing_xi`,`is_captain`,`is_wicket_keeper`) VALUES
  (1,1,4,1,1,1,0),
  (1,1,8,2,1,0,0),
  (1,1,12,3,1,0,0),
  (1,1,16,4,1,0,0),
  (1,1,1,5,1,0,0),
  (1,1,5,6,1,0,0),
  (1,1,9,7,1,0,0),
  (1,1,13,8,1,0,0),
  (1,1,2,9,1,0,0),
  (1,1,6,10,1,0,0),
  (1,1,3,11,1,0,1),
  (1,2,20,1,1,1,0),
  (1,2,24,2,1,0,0),
  (1,2,28,3,1,0,0),
  (1,2,32,4,1,0,0),
  (1,2,17,5,1,0,0),
  (1,2,21,6,1,0,0),
  (1,2,25,7,1,0,0),
  (1,2,29,8,1,0,0),
  (1,2,10,9,1,0,0),
  (1,2,14,10,1,0,0),
  (1,2,7,11,1,0,1);

INSERT INTO `innings` (`id`,`match_id`,`innings_number`,`batting_team_id`,`bowling_team_id`,`started_at`) VALUES
  (1, 1, 1, 1, 2, NOW());

