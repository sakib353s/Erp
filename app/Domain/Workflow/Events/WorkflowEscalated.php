<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Outbox\Contracts\ArrayPayload;

/** An overdue step was escalated to the configured escalation role. */
class WorkflowEscalated implements ArrayPayload
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $requestId,
        public readonly int $stepId,
        public readonly int $level,
        public readonly int $escalationRoleId,
        public readonly string $subject,
        public readonly ?int $actorId = null,
        public readonly ?string $comment = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $payload): static
    {
        return new static(
            companyId: (int) $payload['companyId'],
            branchId: (int) $payload['branchId'],
            requestId: (int) $payload['requestId'],
            stepId: (int) $payload['stepId'],
            level: (int) $payload['level'],
            escalationRoleId: (int) $payload['escalationRoleId'],
            subject: (string) $payload['subject'],
            actorId: $payload['actorId'] !== null ? (int) $payload['actorId'] : null,
            comment: $payload['comment'],
        );
    }
}
