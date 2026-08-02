<?php

declare(strict_types=1);

/**
 * Demo match state for the scorer pad.
 *
 * Mirrors what public/score.php will read from `matches`, `match_squads` and
 * `innings` once ScoringService lands, so the pad can be used and reviewed
 * before the ingestion endpoint exists. Same pattern as demo_state.php.
 */

return [
    'match' => [
        'id'                => 1,
        'match_number'      => 1,
        'venue'             => 'Chinnaswamy',
        'overs_per_innings' => 20,
        'balls_per_over'    => 6,
        'status'            => 'live',
        'toss_text'         => 'Titan Strikers won the toss and chose to bat',
    ],

    'innings' => [
        'id'             => 1,
        'innings_number' => 1,
        'batting_team'   => ['id' => 1, 'name' => 'Titan Strikers', 'short_name' => 'TS', 'primary_color' => '#22c55e'],
        'bowling_team'   => ['id' => 2, 'name' => 'Royal Chargers', 'short_name' => 'RC', 'primary_color' => '#f59e0b'],
        'target'         => null,      // set for the 2nd innings
    ],

    // batting_order drives the "next batter in" list.
    'batting_xi' => [
        ['id' => 101, 'name' => 'V Rao',      'order' => 1,  'is_captain' => 1, 'is_keeper' => 0],
        ['id' => 102, 'name' => 'L Carter',   'order' => 2,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 103, 'name' => 'K Anand',    'order' => 3,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 104, 'name' => 'A Menon',    'order' => 4,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 105, 'name' => 'N Bose',     'order' => 5,  'is_captain' => 0, 'is_keeper' => 1],
        ['id' => 106, 'name' => 'R Iyer',     'order' => 6,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 107, 'name' => 'S Grewal',   'order' => 7,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 108, 'name' => 'M Fernandes','order' => 8,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 109, 'name' => 'D Sharma',   'order' => 9,  'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 110, 'name' => 'T Bhat',     'order' => 10, 'is_captain' => 0, 'is_keeper' => 0],
        ['id' => 111, 'name' => 'P Naidu',    'order' => 11, 'is_captain' => 0, 'is_keeper' => 0],
    ],

    'bowling_xi' => [
        ['id' => 201, 'name' => 'S Khan',     'style' => 'Left-arm fast'],
        ['id' => 202, 'name' => 'J Hartley',  'style' => 'Right-arm fast'],
        ['id' => 203, 'name' => 'G Pillai',   'style' => 'Right-arm medium'],
        ['id' => 204, 'name' => 'H Malhotra', 'style' => 'Off break'],
        ['id' => 205, 'name' => 'Y Tandon',   'style' => 'Leg break'],
        ['id' => 206, 'name' => 'B Oduya',    'style' => 'Left-arm orthodox'],
        ['id' => 207, 'name' => 'C Whitfield','style' => 'Right-arm medium'],
        ['id' => 208, 'name' => 'A Sequeira', 'style' => 'Right-arm fast'],
        ['id' => 209, 'name' => 'F Ali',      'style' => 'Off break'],
        ['id' => 210, 'name' => 'K Joshi',    'style' => 'Leg break'],
        ['id' => 211, 'name' => 'E Mwangi',   'style' => 'Left-arm fast'],
    ],

    // Who is out in the middle when the pad opens.
    'opening' => [
        'striker_id'     => 101,
        'non_striker_id' => 102,
        'bowler_id'      => 201,
    ],
];
