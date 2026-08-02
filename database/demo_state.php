<?php

declare(strict_types=1);

/**
 * Demo dataset for the Phase 1 UI.
 *
 * Mirrors, column for column, what public/index.php reads out of MySQL
 * (`v_live_auction`, `teams`, `auction_bids`, `auction_lots`) so the dashboard
 * renders identically with or without a database. Delete this file once the
 * auction controller is live — nothing else depends on it.
 *
 * Matches database/seed.sql.
 */

return [
    'tournament' => [
        'id'                => 1,
        'name'              => 'Corporate Premier League',
        'season_year'       => 2026,
        'bid_increment'     => 500000.00,
        'bid_timer_seconds' => 30,
        'max_squad_size'    => 15,
    ],

    // One row of v_live_auction — the player under the hammer.
    'lot' => [
        'lot_id'             => 5,
        'lot_order'          => 5,
        'lot_status'         => 'live',
        'base_price'         => 2000000.00,
        'current_bid'        => 3250000.00,
        'bid_count'          => 6,
        'ends_at'            => date('Y-m-d H:i:s', time() + 22),
        'player_id'          => 1,
        'full_name'          => 'Kabir Anand',
        'display_name'       => 'K Anand',
        'photo_url'          => null,
        'role'               => 'all_rounder',
        'country'            => 'India',
        'is_overseas'        => 0,
        'auction_set'        => 'Marquee',
        'career_matches'     => 92,
        'career_runs'        => 2148,
        'career_wickets'     => 74,
        'strike_rate'        => 148.60,
        'economy'            => 7.85,
        'bidder_team_id'     => 4,
        'bidder_team_name'   => 'Desert Falcons',
        'bidder_team_short'  => 'DF',
        'bidder_team_color'  => '#a855f7',
    ],

    'teams' => [
        ['id' => 3, 'name' => 'Coastal Kings',  'short_name' => 'CK', 'primary_color' => '#38bdf8',
         'purse_total' => 10000000.00, 'purse_spent' => 2900000.00, 'purse_remaining' => 7100000.00,
         'players_bought' => 5, 'overseas_bought' => 1],
        ['id' => 1, 'name' => 'Titan Strikers', 'short_name' => 'TS', 'primary_color' => '#22c55e',
         'purse_total' => 10000000.00, 'purse_spent' => 4250000.00, 'purse_remaining' => 5750000.00,
         'players_bought' => 6, 'overseas_bought' => 1],
        ['id' => 2, 'name' => 'Royal Chargers', 'short_name' => 'RC', 'primary_color' => '#f59e0b',
         'purse_total' => 10000000.00, 'purse_spent' => 6100000.00, 'purse_remaining' => 3900000.00,
         'players_bought' => 7, 'overseas_bought' => 2],
        ['id' => 4, 'name' => 'Desert Falcons', 'short_name' => 'DF', 'primary_color' => '#a855f7',
         'purse_total' => 10000000.00, 'purse_spent' => 7350000.00, 'purse_remaining' => 2650000.00,
         'players_bought' => 8, 'overseas_bought' => 3],
    ],

    'bids' => [
        ['bid_amount' => 3250000.00, 'placed_at' => date('Y-m-d H:i:s', time() -   9), 'short_name' => 'DF', 'team_name' => 'Desert Falcons', 'primary_color' => '#a855f7'],
        ['bid_amount' => 3000000.00, 'placed_at' => date('Y-m-d H:i:s', time() -  33), 'short_name' => 'RC', 'team_name' => 'Royal Chargers', 'primary_color' => '#f59e0b'],
        ['bid_amount' => 2750000.00, 'placed_at' => date('Y-m-d H:i:s', time() -  58), 'short_name' => 'TS', 'team_name' => 'Titan Strikers', 'primary_color' => '#22c55e'],
        ['bid_amount' => 2500000.00, 'placed_at' => date('Y-m-d H:i:s', time() -  74), 'short_name' => 'DF', 'team_name' => 'Desert Falcons', 'primary_color' => '#a855f7'],
        ['bid_amount' => 2250000.00, 'placed_at' => date('Y-m-d H:i:s', time() -  95), 'short_name' => 'RC', 'team_name' => 'Royal Chargers', 'primary_color' => '#f59e0b'],
        ['bid_amount' => 2000000.00, 'placed_at' => date('Y-m-d H:i:s', time() - 110), 'short_name' => 'TS', 'team_name' => 'Titan Strikers', 'primary_color' => '#22c55e'],
    ],

    'queue' => [
        ['lot_order' => 6,  'base_price' => 2000000.00, 'display_name' => 'R Fletcher', 'role' => 'batsman',       'country' => 'Australia',    'is_overseas' => 1],
        ['lot_order' => 7,  'base_price' => 1500000.00, 'display_name' => 'T Nkosi',    'role' => 'wicket_keeper', 'country' => 'South Africa', 'is_overseas' => 1],
        ['lot_order' => 8,  'base_price' => 1000000.00, 'display_name' => 'D Sharma',   'role' => 'bowler',        'country' => 'India',        'is_overseas' => 0],
        ['lot_order' => 9,  'base_price' => 1200000.00, 'display_name' => 'J Hartley',  'role' => 'bowler',        'country' => 'England',      'is_overseas' => 1],
        ['lot_order' => 10, 'base_price' =>  500000.00, 'display_name' => 'A Menon',    'role' => 'all_rounder',   'country' => 'India',        'is_overseas' => 0],
    ],

    'sold' => [
        ['sold_price' => 900000.00,  'display_name' => 'N Bose',  'role' => 'wicket_keeper', 'short_name' => 'CK', 'primary_color' => '#38bdf8'],
        ['sold_price' => 1800000.00, 'display_name' => 'L Carter','role' => 'all_rounder',   'short_name' => 'TS', 'primary_color' => '#22c55e'],
        ['sold_price' => 2600000.00, 'display_name' => 'S Khan',  'role' => 'bowler',        'short_name' => 'DF', 'primary_color' => '#a855f7'],
        ['sold_price' => 3400000.00, 'display_name' => 'V Rao',   'role' => 'batsman',       'short_name' => 'RC', 'primary_color' => '#f59e0b'],
    ],
];
