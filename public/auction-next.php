<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Put the next player under the hammer
 * =====================================================================
 *
 *  The auctioneer's "go" button. It closes nothing and sells nothing; it
 *  takes the next queued lot, makes it live, and starts its countdown.
 *
 *  A plain form post rather than an AJAX call, deliberately. This is the
 *  one control an administrator needs when there is *no* live lot — which
 *  is exactly when the board is showing an empty state and its JavaScript
 *  has nothing to drive. A form works there, works on the live board, and
 *  works with a strict Content-Security-Policy without an exception.
 *
 *  On success it redirects back to the board, which then renders the new
 *  lot server-side. One page load per lot is not a cost worth optimising
 *  away at an auction that runs one player a minute.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AuctionException;
use App\Services\AuctionService;

Auth::require(Auth::ROLE_ADMIN);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: auction.php');
    exit;
}

if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    $_SESSION['auction_notice'] = ['kind' => 'error', 'message' => 'Your session expired. Please try again.'];

    header('Location: auction.php');
    exit;
}

$tournamentId = (int) ($_POST['tournament_id'] ?? 0);

if ($tournamentId === 0) {
    $tournamentId = (int) Database::scalar(
        "SELECT id FROM tournaments
          WHERE status IN ('auction', 'draft', 'ongoing')
       ORDER BY FIELD(status, 'auction', 'ongoing', 'draft'), id
          LIMIT 1"
    );
}

try {
    $result = (new AuctionService())->startNextLot($tournamentId, Auth::id());

    $_SESSION['auction_notice'] = [
        'kind'    => 'success',
        'message' => ($result['lot']['player_name'] ?? 'The next player') . ' is under the hammer.',
    ];
} catch (AuctionException $e) {
    // NOTHING_QUEUED and LOT_ALREADY_OPEN are ordinary states, not faults —
    // say what they mean rather than showing an error page.
    $_SESSION['auction_notice'] = [
        'kind'    => $e->errorCode() === AuctionException::LOT_ALREADY_OPEN ? 'note' : 'error',
        'message' => $e->getMessage(),
    ];
}

header('Location: auction.php');
exit;
