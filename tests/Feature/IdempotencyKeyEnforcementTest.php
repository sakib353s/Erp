<?php

namespace Tests\Feature;

use App\Domain\Security\IdempotencyKey;
use App\Domain\Security\Services\IdempotencyException;
use App\Domain\Security\Services\IdempotencyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §16-52 — the idempotency guard, the mechanism behind "double-click / retry /
 * queue-retry safe".
 *
 * The guard records a scope+key+payload hash, runs the callback, and stores the
 * result. A second call with the same key and an identical payload returns the
 * stored result without running the work again — so a retried HTTP request, a
 * queued job that is delivered twice, or a double-clicked button cannot duplicate
 * the business effect. A different payload under the same key is refused (it is not
 * a retry, it is a conflicting instruction), and an in-flight key is not silently
 * collapsed into a later one.
 */
class IdempotencyKeyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeated_call_with_the_same_payload_replays_and_runs_once(): void
    {
        $guard = app(IdempotencyGuard::class);
        $runs = 0;

        $first = $guard->run('test.scope', 'key-1', ['amount' => 10], function () use (&$runs) {
            $runs++;

            return ['id' => 100];
        });
        $second = $guard->run('test.scope', 'key-1', ['amount' => 10], function () use (&$runs) {
            $runs++;

            return ['id' => 999]; // would be a duplicate effect if it ran
        });

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(['id' => 100], $second['result']);
        $this->assertSame(1, $runs, 'The work ran exactly once.');
    }

    public function test_a_different_payload_under_the_same_key_is_refused(): void
    {
        $guard = app(IdempotencyGuard::class);

        $guard->run('test.scope', 'key-2', ['amount' => 10], fn () => ['id' => 1]);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageMatches('/different payload/');

        $guard->run('test.scope', 'key-2', ['amount' => 999], fn () => ['id' => 2]);
    }

    public function test_an_in_flight_key_is_not_collapsed_into_a_later_call(): void
    {
        // Simulate a key left in_progress (a crash mid-operation) — a later call
        // must surface the conflict, never silently return or duplicate.
        IdempotencyKey::query()->create([
            'scope' => 'test.scope',
            'key' => 'key-3',
            'request_hash' => hash('sha256', json_encode(['amount' => 5])),
            'status' => 'in_progress',
            'expires_at' => now()->addHour(),
        ]);

        $guard = app(IdempotencyGuard::class);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageMatches('/still in progress/');

        $guard->run('test.scope', 'key-3', ['amount' => 5], fn () => ['id' => 1]);
    }

    public function test_a_completed_key_records_its_result(): void
    {
        app(IdempotencyGuard::class)->run('test.scope', 'key-4', ['x' => 1], fn () => ['ok' => true]);

        $row = IdempotencyKey::query()->where('scope', 'test.scope')->where('key', 'key-4')->firstOrFail();

        $this->assertSame('completed', $row->status);
        $this->assertSame(['ok' => true], $row->response_snapshot);
    }
}
