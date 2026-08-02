<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\ScoringException;
use App\Services\ScoringService;
use Throwable;

/**
 * HTTP surface for ball-by-ball scoring.
 *
 * Writes are limited to the scorer and the admin. Reads are open to anyone
 * signed in, so a viewer's scorecard can poll the same endpoint.
 *
 * Every response is the complete scorecard, not a delta: the client replaces
 * its state wholesale and can never drift from the database. At roughly 120
 * balls an innings the payload stays small, and it makes a dropped or
 * out-of-order response harmless.
 */
final class ScoringController
{
    public function __construct(private readonly ScoringService $scoring = new ScoringService())
    {
    }

    /** POST action=ball */
    public function ball(): void
    {
        $this->requirePost();
        Auth::require(Auth::ROLE_SCORER, Auth::ROLE_ADMIN);
        $this->requireCsrf();

        $input = [
            'runs_off_bat'        => $this->optionalInt('runs_off_bat') ?? 0,
            'extra_runs'          => $this->optionalInt('extra_runs') ?? 0,
            'extra_type'          => (string) ($_POST['extra_type'] ?? 'none'),
            'is_wicket'           => ($_POST['is_wicket'] ?? '0') === '1',
            'dismissal_type'      => $_POST['dismissal_type'] ?? null,
            'dismissed_player_id' => $this->optionalInt('dismissed_player_id'),
            'fielder_id'          => $this->optionalInt('fielder_id'),
            'striker_id'          => $this->optionalInt('striker_id'),
            'non_striker_id'      => $this->optionalInt('non_striker_id'),
            'bowler_id'           => $this->optionalInt('bowler_id'),
            'new_batter_id'       => $this->optionalInt('new_batter_id'),
        ];

        $this->send($this->scoring->recordBall(
            $this->intInput('innings_id'),
            $input,
            Auth::user()['id'] ?? null
        ));
    }

    /** POST action=undo */
    public function undo(): void
    {
        $this->requirePost();
        Auth::require(Auth::ROLE_SCORER, Auth::ROLE_ADMIN);
        $this->requireCsrf();

        $this->send($this->scoring->undoLastBall($this->intInput('innings_id'), Auth::user()['id'] ?? null));
    }

    /** GET action=scorecard — read-only; safe for a viewer to poll. */
    public function scorecard(): void
    {
        Auth::require();

        $this->send($this->scoring->scorecard($this->intInput('innings_id')));
    }

    public function dispatch(string $action): void
    {
        try {
            match ($action) {
                'ball'      => $this->ball(),
                'undo'      => $this->undo(),
                'scorecard' => $this->scorecard(),
                default     => $this->fail('UNKNOWN_ACTION', 'Unknown action.', 404),
            };
        } catch (ScoringException $e) {
            $this->json($e->toArray(), $e->status());
        } catch (Throwable $e) {
            error_log('[scoring] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'Could not record that ball.'], 500);
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
            $this->fail('CSRF_FAILED', 'Your session expired — reload the page and sign in again.', 419);
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

    private function optionalInt(string $key): ?int
    {
        $raw = $_POST[$key] ?? $_GET[$key] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            $this->fail('BAD_REQUEST', "Invalid '{$key}'.", 400);
        }

        return (int) $raw;
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
