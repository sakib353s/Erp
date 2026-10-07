<?php

namespace App\Domain\Settings\Policies;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Settings\Setting;

class SettingPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'settings.view');
    }

    public function view(User $user, Setting $setting): bool
    {
        return $this->catalog->allows($user, 'settings.view');
    }

    public function update(User $user, Setting $setting): bool
    {
        return $this->catalog->allows($user, 'settings.update');
    }
}
