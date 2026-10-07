<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotency guard (Rule 12 / spec §9.3): re-running the same
 * scope+key+payload returns the stored response instead of duplicating
 * the business effect. Key + callback + snapshot commit atomically, so a
 * failed operation leaves no key behind (retry re-executes).
 */
class IdempotencyGuard
{
    public const CONFLICT = 'payload_mismatch';
    public const IN_PROGRESS = 'in_progress';

    /**
     * @param  callable  $callback  fn(): array — returns a JSON-serialisable result
     * @return array{result: mixed, replayed: bool}
     *
     * @throws IdempotencyException when the same key is in flight or the payload differs
     */
    public function run(string $scope, string $key, array $payload, callable $callback, ?int $userId = null): array
    {
        $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use ($scope, $key, $requestHash, $callback, $userId) {
                IdempotencyKey::query()->create([
                    'scope' => $scope,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'user_id' => $userId,
                    'status' => 'in_progress',
                    'expires_at' => now()->addHours((int) config('erp.idempotency.ttl_hours', 24)),
                ]);

                $result = $callback();
                $row = IdempotencyKey::query()
                    ->where('scope', $scope)->where('key', $key)
                    ->lockForUpdate()->firstOrFail();

                $row->update([
                    'status' => 'completed',
                    'response_snapshot' => is_array($result) ? $result : ['value' => $result],
                ]);

                return ['result' => $result, 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->replay($scope, $key, $requestHash);
        }
    }

    protected function replay(string $scope, string $key, string $requestHash): array
    {
        $row = IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->firstOrFail();

        if (! hash_equals($row->request_hash, $requestHash)) {
            throw new IdempotencyException(self::CONFLICT, 'Idempotency key reused with a different payload.');
        }

        if ($row->status === 'in_progress') {
            throw new IdempotencyException(self::IN_PROGRESS, 'The same operation is still in progress.');
        }

        return ['result' => $row->response_snapshot, 'replayed' => true];
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlstate = (string) ($e->errorInfo[0] ?? '');

        return $sqlstate === '23000' || $sqlstate === '23505'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
