<?php

namespace App\Domain\Workflow\Policies;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Workflow\WorkflowDefinition;

class WorkflowPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'workflows.view');
    }

    public function view(User $user, WorkflowDefinition $definition): bool
    {
        return $this->catalog->allows($user, 'workflows.view');
    }

    public function update(User $user, WorkflowDefinition $definition): bool
    {
        return $this->catalog->allows($user, 'workflows.manage');
    }
}
