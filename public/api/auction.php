<?php

declare(strict_types=1);

/**
 * Auction JSON endpoint.
 *
 *   POST /api/auction.php   action=bid     lot_id, amount, csrf_token   (team owner)
 *   POST /api/auction.php   action=sell    lot_id, csrf_token           (admin)
 *   POST /api/auction.php   action=unsold  lot_id, csrf_token           (admin)
 *   POST /api/auction.php   action=next    tournament_id, csrf_token    (admin)
 *   GET  /api/auction.php?action=state&tournament_id=1                  (any signed-in user)
 *
 * Phase 2 folds this into the router; keeping it as one file for now means
 * the dashboard has a working write path without a routing layer.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use App\Controllers\AuctionController;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

(new AuctionController())->dispatch(is_string($action) ? $action : '');
