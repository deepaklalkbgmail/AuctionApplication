<?php

declare(strict_types=1);

/**
 * Ball-by-ball scoring endpoint.
 *
 *   POST /api/scoring.php  action=ball  innings_id, csrf_token
 *                          runs_off_bat | extra_type + extra_runs | is_wicket + dismissal_type
 *                          striker_id + non_striker_id + bowler_id   (first ball only)
 *                          new_batter_id                              (after a wicket)
 *                          bowler_id                                  (start of an over)
 *
 *   POST /api/scoring.php  action=undo       innings_id, csrf_token
 *   GET  /api/scoring.php?action=scorecard&innings_id=1
 *
 * Every response carries the complete scorecard, so the caller replaces its
 * state rather than patching it.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use App\Controllers\ScoringController;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

(new ScoringController())->dispatch(is_string($action) ? $action : '');
