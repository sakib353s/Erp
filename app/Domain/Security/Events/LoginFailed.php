<?php

namespace App\Domain\Security\Events;

use App\Domain\Outbox\Contracts\ArrayPayload;

/** A login attempt failed (unknown user, bad password, locked account…). */
class LoginFailed implements ArrayPayload
{
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $userId,
        public readonly string $ip,
        public readonly string $emailAttempt,
        public readonly string $reason,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $payload): static
    {
        return new static(
            companyId: (int) $payload['companyId'],
            userId: $payload['userId'] !== null ? (int) $payload['userId'] : null,
            ip: (string) $payload['ip'],
            emailAttempt: (string) $payload['emailAttempt'],
            reason: (string) $payload['reason'],
        );
    }
}
