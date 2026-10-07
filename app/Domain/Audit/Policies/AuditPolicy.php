<?php

namespace App\Domain\Audit\Policies;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;

class AuditPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'audit.view');
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return $this->catalog->allows($user, 'audit.view');
    }

    public function export(User $user): bool
    {
        return $this->catalog->allows($user, 'audit.export');
    }
}
