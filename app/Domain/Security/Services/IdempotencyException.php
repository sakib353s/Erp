<?php

namespace App\Domain\Security\Services;

use RuntimeException;

class IdempotencyException extends RuntimeException
{
    public function __construct(
        public readonly string $kind, // payload_mismatch | in_progress
        string $message,
    ) {
        parent::__construct($message);
    }
}
