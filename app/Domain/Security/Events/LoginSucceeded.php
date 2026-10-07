<?php

namespace App\Domain\Security\Events;

use App\Domain\Outbox\Contracts\ArrayPayload;

/** A user signed in; may carry a suspicious-login finding. */
class LoginSucceeded implements ArrayPayload
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $userId,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly bool $suspicious,
        public readonly ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $payload): static
    {
        return new static(
            companyId: (int) $payload['companyId'],
            userId: (int) $payload['userId'],
            ip: (string) $payload['ip'],
            userAgent: (string) $payload['userAgent'],
            suspicious: (bool) $payload['suspicious'],
            reason: $payload['reason'],
        );
    }
}
