<?php
declare(strict_types=1);

$PW = '$2y$12$6tzGcvAhLivGnGxDN1nELOu6jqFLXZvbaKS3b8Ali.H1jaUpztVhK'; // ChangeMe@2026
$q  = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";

$teams = [
    [1, 'Coastal Titans',   'CT', '#22c55e', 'Marine Drive Ground'],
    [2, 'Metro Royals',     'MR', '#f59e0b', 'City Sports Complex'],
    [3, 'Highland Chargers','HC', '#38bdf8', 'Hill View Oval'],
    [4, 'Desert Falcons',   'DF', '#a855f7', 'Sandstone Arena'],
    [5, 'Harbour Warriors', 'HW', '#f43f5e', 'Dockside Ground'],
    [6, 'Summit Lions',     'SL', '#14b8a6', 'Ridge Stadium'],
];

$first = ['Aarav','Vihaan','Reyansh','Arjun','Ishaan','Kabir','Rohan','Aditya','Karan','Nikhil',
          'Rahul','Manav','Devan','Farhan','Gaurav','Harsh','Imran','Jatin','Kunal','Lakshay',
          'Mohit','Naveen','Omkar','Pranav','Ritesh','Sameer','Tarun','Umesh','Varun','Yash',
          'Abhay','Bhavesh','Chirag','Dhruv','Eshan','Girish','Hitesh','Jayant','Keshav','Mayank',
          'Nitin','Parth','Rakesh','Sahil','Tejas','Vikas','Anand','Bharat','Chetan','Dinesh',
          'Ganesh','Hemant','Jagdish','Kiran','Mahesh','Naresh','Prakash','Ramesh','Suresh','Vinod'];
$last  = ['Sharma','Verma','Iyer','Nair','Menon','Reddy','Patel','Joshi','Rao','Kulkarni',
          'Desai','Bhat','Shetty','Naidu','Pillai','Gowda','Chauhan','Malhotra','Kapoor','Sinha',
          'Bose','Dutta','Ghosh','Mishra','Pandey','Tiwari','Yadav','Saxena','Aggarwal','Bajaj'];

$sets = [
    ['Marquee', 2000000, 4],
    ['Set A',   1500000, 12],
    ['Set B',   1000000, 16],
    ['Set C',    500000, 16],
    ['Set D',    200000, 12],
];
$roles   = ['batsman','bowler','all_rounder','wicket_keeper'];
$batting = ['right_hand','left_hand'];
$bowl    = ['right_arm_fast','right_arm_medium','right_arm_offbreak','right_arm_legbreak',
            'left_arm_fast','left_arm_medium','left_arm_orthodox'];

mt_srand(20260803);

// ---- build the player pool -------------------------------------------------
$players = []; $id = 0;
foreach ($sets as [$setName, $base, $count]) {
    for ($i = 0; $i < $count; $i++) {
        $id++;
        $fn = $first[($id * 7) % count($first)];
        $ln = $last[($id * 11) % count($last)];
        $role = $roles[$id % 4];
        $players[$id] = [
            'id' => $id,
            'full' => "$fn $ln",
            'disp' => substr($fn, 0, 1) . " $ln",
            'role' => $role,
            'bat'  => $batting[$id % 2],
            'bowl' => in_array($role, ['bowler','all_rounder'], true) ? $bowl[$id % count($bowl)] : 'none',
            'set'  => $setName,
            'base' => $base,
            'matches' => 20 + ($id * 3) % 90,
            'runs'    => in_array($role, ['batsman','all_rounder','wicket_keeper'], true) ? 300 + ($id * 47) % 2600 : ($id * 13) % 320,
            'wkts'    => in_array($role, ['bowler','all_rounder'], true) ? 15 + ($id * 5) % 80 : 0,
            'sr'      => round(105 + (($id * 17) % 550) / 10, 2),
            'econ'    => in_array($role, ['bowler','all_rounder'], true) ? round(6.4 + (($id * 7) % 26) / 10, 2) : 0.00,
            'status'  => 'available', 'team' => null, 'price' => null,
        ];
    }
}
$total = $id;

// ---- the auction so far ----------------------------------------------------
// Teams 1 and 2 are complete (11 each) so a match can be played; the rest are
// part way through, which is what an auction in progress actually looks like.
$squadTarget = [1 => 11, 2 => 11, 3 => 4, 4 => 4, 5 => 3, 6 => 3];
$spent = array_fill_keys(array_keys($squadTarget), 0);
$squads = array_fill_keys(array_keys($squadTarget), []);

// Give each squad a sensible shape: 4 batters, 4 bowlers, 2 all-rounders, 1 keeper.
$shape = ['batsman' => 4, 'bowler' => 4, 'all_rounder' => 2, 'wicket_keeper' => 1];
$pool  = $players;
$lotOrder = 0; $lots = []; $bids = [];

foreach ($squadTarget as $team => $need) {
    $want = $need === 11 ? $shape : ['batsman' => 2, 'bowler' => 1, 'all_rounder' => 1, 'wicket_keeper' => 0];
    $taken = 0;
    foreach ($want as $role => $n) {
        for ($k = 0; $k < $n && $taken < $need; $k++) {
            foreach ($pool as $pid => $p) {
                if ($p['status'] !== 'available' || $p['role'] !== $role) continue;
                $steps = 1 + (($pid + $team) % 4);
                $price = $p['base'] + $steps * 500000;
                if ($spent[$team] + $price > 45000000) { $price = $p['base']; }
                $pool[$pid]['status'] = 'sold'; $pool[$pid]['team'] = $team; $pool[$pid]['price'] = $price;
                $spent[$team] += $price; $squads[$team][] = $pid; $taken++;
                $lots[] = ['player' => $pid, 'order' => ++$lotOrder, 'status' => 'sold',
                           'base' => $p['base'], 'price' => $price, 'team' => $team, 'steps' => $steps];
                break;
            }
        }
    }
    while ($taken < $need) {                       // top up if a role ran dry
        foreach ($pool as $pid => $p) {
            if ($p['status'] !== 'available') continue;
            $price = $p['base'] + 500000;
            $pool[$pid]['status'] = 'sold'; $pool[$pid]['team'] = $team; $pool[$pid]['price'] = $price;
            $spent[$team] += $price; $squads[$team][] = $pid; $taken++;
            $lots[] = ['player' => $pid, 'order' => ++$lotOrder, 'status' => 'sold',
                       'base' => $p['base'], 'price' => $price, 'team' => $team, 'steps' => 1];
            break;
        }
    }
}

// One player under the hammer right now, then the rest queued.
$liveId = null;
foreach ($pool as $pid => $p) {
    if ($p['status'] === 'available') { $liveId = $pid; break; }
}
$pool[$liveId]['status'] = 'in_auction';
$liveLotOrder = ++$lotOrder;
$liveBase = $pool[$liveId]['base'];

$queued = [];
foreach ($pool as $pid => $p) {
    if ($p['status'] === 'available') { $queued[] = ['player' => $pid, 'order' => ++$lotOrder, 'base' => $p['base']]; }
}

// ---- emit ------------------------------------------------------------------
$o = [];
$o[] = "-- =====================================================================";
$o[] = "--  APL — demonstration dataset";
$o[] = "--";
$o[] = "--  A complete, coherent tournament for showing the application: six";
$o[] = "--  franchises, a {$total}-player pool, an auction part way through with a";
$o[] = "--  player under the hammer, and a match ready to be scored from the";
$o[] = "--  first ball.";
$o[] = "--";
$o[] = "--  Load database/reset.sql FIRST — this file assumes empty tables.";
$o[] = "--";
$o[] = "--  Every account here uses the password  ChangeMe@2026";
$o[] = "--  This is demonstration data. Run reset.sql again before real use.";
$o[] = "-- =====================================================================";
$o[] = "";
$o[] = "INSERT INTO `tournaments` (`id`,`name`,`season_year`,`purse_per_team`,`min_squad_size`,`max_squad_size`,`max_overseas`,`bid_increment`,`bid_timer_seconds`,`overs_per_innings`,`balls_per_over`,`status`) VALUES";
$o[] = "  (1, 'APL', 2026, 50000000.00, 11, 15, 4, 500000.00, 30, 20, 6, 'auction');";
$o[] = "";
$o[] = "INSERT INTO `teams` (`id`,`tournament_id`,`name`,`short_name`,`primary_color`,`home_venue`,`purse_total`,`purse_spent`,`players_bought`,`overseas_bought`) VALUES";
$rows = [];
foreach ($teams as [$tid, $name, $short, $colour, $venue]) {
    $rows[] = sprintf("  (%d, 1, %s, %s, %s, %s, 50000000.00, %0.2f, %d, 0)",
        $tid, $q($name), $q($short), $q($colour), $q($venue), $spent[$tid] ?? 0, count($squads[$tid] ?? []));
}
$o[] = implode(",\n", $rows) . ";";
$o[] = "";
$o[] = "INSERT INTO `users` (`name`,`email`,`password_hash`,`role`,`team_id`) VALUES";
$rows = [];
$rows[] = sprintf("  (%s, %s, %s, 'admin', NULL)",  $q('Tournament Director'), $q('admin@apl.local'),  $q($PW));
$rows[] = sprintf("  (%s, %s, %s, 'scorer', NULL)", $q('Match Scorer'),        $q('scorer@apl.local'), $q($PW));
$rows[] = sprintf("  (%s, %s, %s, 'viewer', NULL)", $q('Guest Viewer'),        $q('viewer@apl.local'), $q($PW));
foreach ($teams as [$tid, $name, $short]) {
    $rows[] = sprintf("  (%s, %s, %s, 'team_owner', %d)",
        $q($name . ' Owner'), $q(strtolower($short) . '@apl.local'), $q($PW), $tid);
}
$o[] = implode(",\n", $rows) . ";";
$o[] = "";
$o[] = "INSERT INTO `players` (`id`,`tournament_id`,`full_name`,`display_name`,`country`,`role`,`batting_style`,`bowling_style`,`is_overseas`,`is_capped`,`auction_set`,`base_price`,`career_matches`,`career_runs`,`career_wickets`,`strike_rate`,`economy`,`status`,`team_id`,`sold_price`) VALUES";
$rows = [];
foreach ($pool as $p) {
    $rows[] = sprintf("  (%d,1,%s,%s,'India',%s,%s,%s,0,%d,%s,%0.2f,%d,%d,%d,%0.2f,%0.2f,%s,%s,%s)",
        $p['id'], $q($p['full']), $q($p['disp']), $q($p['role']), $q($p['bat']), $q($p['bowl']),
        $p['base'] >= 1500000 ? 1 : 0, $q($p['set']), $p['base'], $p['matches'], $p['runs'], $p['wkts'],
        $p['sr'], $p['econ'], $q($p['status']),
        $p['team'] === null ? 'NULL' : (string) $p['team'],
        $p['price'] === null ? 'NULL' : sprintf('%0.2f', $p['price']));
}
$o[] = implode(",\n", $rows) . ";";
$o[] = "";
$o[] = "INSERT INTO `auction_lots` (`tournament_id`,`player_id`,`lot_order`,`status`,`base_price`,`current_bid`,`current_bidder_team_id`,`bid_count`,`started_at`,`ends_at`,`closed_at`,`sold_to_team_id`,`sold_price`) VALUES";
$rows = [];
foreach ($lots as $l) {
    $rows[] = sprintf("  (1,%d,%d,'sold',%0.2f,%0.2f,%d,%d,NOW() - INTERVAL %d MINUTE,NULL,NOW() - INTERVAL %d MINUTE,%d,%0.2f)",
        $l['player'], $l['order'], $l['base'], $l['price'], $l['team'], $l['steps'] + 1,
        120 - $l['order'], 118 - $l['order'], $l['team'], $l['price']);
}
$rows[] = sprintf("  (1,%d,%d,'live',%0.2f,%0.2f,%d,3,NOW() - INTERVAL 1 MINUTE,NOW() + INTERVAL 25 SECOND,NULL,NULL,NULL)",
    $liveId, $liveLotOrder, $liveBase, $liveBase + 1000000, 3);
foreach ($queued as $qd) {
    $rows[] = sprintf("  (1,%d,%d,'queued',%0.2f,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL)", $qd['player'], $qd['order'], $qd['base']);
}
$o[] = implode(",\n", $rows) . ";";
$o[] = "";
$o[] = "-- Bid ladder on the live lot: three teams, on the ₹5 L increment grid.";
$o[] = "SET @live_lot := (SELECT id FROM auction_lots WHERE player_id = {$liveId});";
$o[] = "INSERT INTO `auction_bids` (`lot_id`,`player_id`,`team_id`,`bid_amount`,`placed_at`) VALUES";
$o[] = sprintf("  (@live_lot,%d,3,%0.2f,NOW() - INTERVAL 46 SECOND),", $liveId, $liveBase);
$o[] = sprintf("  (@live_lot,%d,5,%0.2f,NOW() - INTERVAL 28 SECOND),", $liveId, $liveBase + 500000);
$o[] = sprintf("  (@live_lot,%d,3,%0.2f,NOW() - INTERVAL  6 SECOND);", $liveId, $liveBase + 1000000);
$o[] = "";
$o[] = "-- A fixture between the two completed squads, ready to score from ball 1.";
$o[] = "INSERT INTO `matches` (`id`,`tournament_id`,`match_number`,`stage`,`team_a_id`,`team_b_id`,`venue`,`scheduled_at`,`overs_per_innings`,`toss_winner_team_id`,`toss_decision`,`status`,`scorer_user_id`) VALUES";
$o[] = "  (1, 1, 1, 'league', 1, 2, 'Marine Drive Ground', NOW(), 20, 1, 'bat', 'live', (SELECT id FROM users WHERE email = 'scorer@apl.local'));";
$o[] = "";
$o[] = "INSERT INTO `match_squads` (`match_id`,`team_id`,`player_id`,`batting_order`,`is_playing_xi`,`is_captain`,`is_wicket_keeper`) VALUES";
$rows = [];
foreach ([1, 2] as $tid) {
    $order = 0;
    foreach ($squads[$tid] as $pid) {
        $order++;
        $rows[] = sprintf("  (1,%d,%d,%d,1,%d,%d)", $tid, $pid, $order, $order === 1 ? 1 : 0,
            $pool[$pid]['role'] === 'wicket_keeper' ? 1 : 0);
    }
}
$o[] = implode(",\n", $rows) . ";";
$o[] = "";
$o[] = "INSERT INTO `innings` (`id`,`match_id`,`innings_number`,`batting_team_id`,`bowling_team_id`,`started_at`) VALUES";
$o[] = "  (1, 1, 1, 1, 2, NOW());";
$o[] = "";

file_put_contents('database/demo_apl.sql', implode("\n", $o) . "\n");
printf("players: %d   sold: %d   live: 1   queued: %d   teams: %d\n",
    $total, count($lots), count($queued), count($teams));
printf("squad sizes: %s\n", json_encode(array_map('count', $squads)));
printf("purse spent: %s\n", json_encode($spent));
