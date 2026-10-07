<?php

namespace App\Domain\Outbox\Contracts;

/**
 * Domain events published through the outbox must round-trip through
 * JSON payload storage: toArray() on the way in, fromArray() on dispatch.
 */
interface ArrayPayload
{
    public function toArray(): array;

    public static function fromArray(array $payload): static;
}
