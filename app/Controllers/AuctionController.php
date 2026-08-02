<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AuctionException;
use App\Services\AuctionService;
use Throwable;

/**
 * HTTP surface for the auction.
 *
 * Responsibilities kept deliberately thin: authenticate, authorise, validate
 * the shape of the input, hand off to AuctionService, and serialise the
 * result. No business rules live here.
 *
 * Two things this layer must never do:
 *   1. Trust a team_id from the request. The bidding team is read from the
 *      session, so an owner cannot spend another franchise's purse by editing
 *      a form field.
 *   2. Skip the CSRF check on a state-changing call.
 */
final class AuctionController
{
    public function __construct(private readonly AuctionService $auction = new AuctionService())
    {
    }

    /** POST /api/auction.php  action=bid */
    public function bid(): void
    {
        $this->requirePost();
        Auth::require(Auth::ROLE_OWNER);
        $this->requireCsrf();

        $teamId = Auth::teamId();

        if ($teamId === null) {
            $this->fail('NO_TEAM', 'Your account is not linked to a team.', 403);
        }

        $lotId  = $this->intInput('lot_id');
        $amount = $this->amountInput('amount');

        $this->send($this->auction->placeBid(
            $lotId,
            $teamId,
            Auth::user()['id'] ?? null,
            $amount,
            $_SERVER['REMOTE_ADDR'] ?? null
        ));
    }

    /** POST /api/auction.php  action=sell */
    public function sell(): void
    {
        $this->requirePost();
        Auth::require(Auth::ROLE_ADMIN);
        $this->requireCsrf();

        $this->send($this->auction->sell($this->intInput('lot_id'), Auth::user()['id'] ?? null));
    }

    /** POST /api/auction.php  action=unsold */
    public function unsold(): void
    {
        $this->requirePost();
        Auth::require(Auth::ROLE_ADMIN);
        $this->requireCsrf();

        $this->send($this->auction->markUnsold($this->intInput('lot_id'), Auth::user()['id'] ?? null));
    }

    /** POST /api/auction.php  action=next */
    public function next(): void
    {
        $this->requirePost();
        Auth::require(Auth::ROLE_ADMIN);
        $this->requireCsrf();

        $this->send($this->auction->startNextLot($this->intInput('tournament_id'), Auth::user()['id'] ?? null));
    }

    /** GET /api/auction.php?action=state — read-only, safe for viewers to poll. */
    public function state(): void
    {
        Auth::require();

        $this->send($this->auction->liveState($this->intInput('tournament_id')));
    }

    /**
     * Route one request. Any AuctionException becomes a clean JSON rejection;
     * anything else is logged and reported as a generic 500, so a driver
     * message never reaches the client.
     */
    public function dispatch(string $action): void
    {
        try {
            match ($action) {
                'bid'    => $this->bid(),
                'sell'   => $this->sell(),
                'unsold' => $this->unsold(),
                'next'   => $this->next(),
                'state'  => $this->state(),
                default  => $this->fail('UNKNOWN_ACTION', 'Unknown action.', 404),
            };
        } catch (AuctionException $e) {
            $this->json($e->toArray(), $e->status());
        } catch (Throwable $e) {
            error_log('[auction] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'Could not process that request.'], 500);
        }
    }

    // -----------------------------------------------------------------

    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->fail('METHOD_NOT_ALLOWED', 'This action requires POST.', 405);
        }
    }

    private function requireCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!Security::verifyCsrf(is_string($token) ? $token : null)) {
            $this->fail('CSRF_FAILED', 'Your session expired — reload the page and try again.', 419);
        }
    }

    private function intInput(string $key): int
    {
        $raw = $_POST[$key] ?? $_GET[$key] ?? null;

        if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            $this->fail('BAD_REQUEST', "Missing or invalid '{$key}'.", 400);
        }

        return (int) $raw;
    }

    /** Money in, as a plain decimal string — rejects negatives and junk. */
    private function amountInput(string $key): string
    {
        $raw = $_POST[$key] ?? null;

        if (!is_scalar($raw) || !preg_match('/^\d{1,12}(\.\d{1,2})?$/', (string) $raw)) {
            $this->fail('BAD_REQUEST', "Missing or invalid '{$key}'.", 400);
        }

        return (string) $raw;
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload): void
    {
        $this->json($payload + ['ok' => true], 200);
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo Security::json($payload);
        exit;
    }

    private function fail(string $code, string $message, int $status): never
    {
        $this->json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
