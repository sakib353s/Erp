<?php

namespace Database\Seeders;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Services\CatalogImporter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Imports the verbatim §47 menu catalog, then adds the foundation's
 * cross-cutting utility/header entries (approval inbox, audit log,
 * workflow admin, notifications, profile) — these are part of the
 * foundation's navigational intent but sit outside the module tree.
 *
 * Status is computed from REAL registered routes: entries whose pages
 * exist become 'active', everything else stays 'planned' (C1).
 */
class NavigationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $importer = app(CatalogImporter::class);
        $stats = $importer->sync();

        $entries = [
            ['code' => 'settings.company_profile', 'label' => 'Company Profile', 'route' => '/app/settings/company', 'permission' => 'settings.company', 'location' => 'utility', 'icon' => 'bi-building', 'sort' => 5],
            ['code' => 'masters.all_masters', 'label' => 'Master Data', 'route' => '/app/masters/units', 'permission' => 'masters.manage', 'location' => 'utility', 'icon' => 'bi-list-check', 'sort' => 6],
            ['code' => 'employee.all_employees', 'label' => 'Employees', 'route' => '/app/employees', 'permission' => 'employees.view', 'location' => 'utility', 'icon' => 'bi-person-badge', 'sort' => 7],
            ['code' => 'utility.approvals', 'label' => 'Approval Inbox', 'route' => '/app/approvals', 'permission' => 'approvals.view', 'location' => 'utility', 'icon' => 'bi-inbox', 'sort' => 10],
            ['code' => 'utility.audit_log', 'label' => 'Audit Log', 'route' => '/app/audit', 'permission' => 'audit.view', 'location' => 'utility', 'icon' => 'bi-shield-lock', 'sort' => 20],
            ['code' => 'utility.workflows', 'label' => 'Workflows', 'route' => '/app/workflows', 'permission' => 'workflows.view', 'location' => 'utility', 'icon' => 'bi-diagram-3', 'sort' => 30],
            ['code' => 'utility.workflow_settings', 'label' => 'Workflow Settings', 'route' => '/app/settings/workflow', 'permission' => 'settings.view', 'location' => 'utility', 'icon' => 'bi-sliders', 'sort' => 40],
            ['code' => 'header.notifications', 'label' => 'Notifications', 'route' => '/app/notifications', 'permission' => 'notifications.view', 'location' => 'header', 'icon' => 'bi-bell', 'sort' => 10],
            ['code' => 'header.profile', 'label' => 'My Profile', 'route' => '/app/profile', 'permission' => null, 'location' => 'header', 'icon' => 'bi-person', 'sort' => 20],
        ];

        foreach ($entries as $i => $entry) {
            $permissionId = null;

            if ($entry['permission'] !== null) {
                $permissionId = Permission::updateOrCreate(
                    ['key' => $entry['permission']],
                    [
                        'module' => in_array($entry['permission'], ['settings.company', 'masters.manage', 'employees.view'], true)
                            ? (str_starts_with($entry['permission'], 'settings.') ? 'settings' : explode('.', $entry['permission'])[0])
                            : 'settings',
                        'resource' => explode('.', $entry['permission'])[0],
                        'action' => explode('.', $entry['permission'])[1] ?? 'view',
                        'label' => 'Allow: '.$entry['label'],
                        'is_system' => true,
                    ],
                )->id;
            }

            $active = $importer->hasRoute($entry['route']);

            MenuItem::updateOrCreate(
                ['code' => $entry['code']],
                [
                    'module_id' => null,
                    'parent_id' => null,
                    'label' => $entry['label'],
                    'label_key' => 'menu.'.$entry['code'],
                    'route' => $entry['route'],
                    'icon' => $entry['icon'],
                    'permission_id' => $permissionId,
                    'location' => $entry['location'],
                    'status' => $active ? 'active' : 'planned',
                    'action' => null,
                    'feature_key' => null, // foundation entries are never entitlement-gated
                    'sort' => $entry['sort'],
                ],
            );
        }

        if ($this->command) {
            $this->command->info(sprintf(
                'Navigation: %d catalog entries (%d active), %d utility/header entries.',
                $stats['items'],
                $stats['active'],
                count($entries),
            ));
        }
    }
}
