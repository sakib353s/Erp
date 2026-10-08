<?php

namespace App\Domain\Notification\Services;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Illuminate\Support\Collection;

/**
 * Permission-based routing for system notifications (Rule J):
 * recipients are resolved from the DATABASE (roles/grants), never from
 * hard-coded user lists (correction G).
 */
class NotificationRouter
{
    /** @return Collection<int, User> active users whose roles/grants hold the key */
    public function usersWithPermission(string $key): Collection
    {
        $companyId = app(TenantContext::class)->companyId()
            ?? Company::current()?->id;

        if ($companyId === null) {
            return collect();
        }

        $viaRoles = User::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereNull('roles.company_id')->orWhere('roles.company_id', $companyId))
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', $key))
            ->with('roles.permissions')
            ->get();

        $viaDirect = User::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereHas('directPermissions', fn ($q) => $q->where('permissions.key', $key)->where('user_permission.effect', 'grant'))
            ->get();

        $recipients = $viaRoles->merge($viaDirect);

        /*
         * A super admin holds every key the catalogue defines, so they are on
         * every real digest list — including the ones nobody else holds. But a
         * key that does not exist is a typo, not a permission: answering "the
         * owner holds it" would quietly mail the owner and hide the mistake.
         * Nobody holds an undefined key, and the command says so out loud.
         */
        if (Permission::query()->where('key', $key)->exists()) {
            $recipients = $recipients->merge(
                User::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->where('is_super_admin', true)
                    ->get()
            );
        }

        return $recipients->unique('id')->values();
    }

    /** Security watchers: holders of the security-alerts permission + super admins. */
    public function watchers(): Collection
    {
        $key = (string) config('erp.notifications.security_recipients_permission', 'security.alerts.view');

        return $this->usersWithPermission($key);
    }
}
