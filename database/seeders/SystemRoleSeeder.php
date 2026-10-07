<?php

namespace Database\Seeders;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * System roles. Roles are company-scoped, so on a fresh instance this
 * seeder is a no-op until first-boot setup creates the company — at
 * that point CompanyService materialises the Administrator role with
 * the full permission set. On an already-provisioned instance this
 * seeder re-syncs the Administrator role with the current matrix
 * (deploy path), keeping the system role complete.
 */
class SystemRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            if ($this->command) {
                $this->command->info('No company yet (pre-setup) — Administrator role will be materialised during first-boot setup.');
            }

            return;
        }

        $role = Role::firstOrCreate(
            ['company_id' => $company->id, 'slug' => 'administrator'],
            ['name' => 'Administrator', 'description' => 'Full access to every function.', 'is_system' => true],
        );

        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        $this->seedBusinessRoles($company->id);
    }

    /**
     * Structural employee roles (never faked users): Manager / Employee /
     * Technician get fixed permission bundles so first tenants have real
     * role options without inventing people.
     */
    protected function seedBusinessRoles(int $companyId): void
    {
        $all = Permission::query()->pluck('id', 'key');

        $bundles = [
            ['slug' => 'manager', 'name' => 'Manager', 'description' => 'Department manager: approvals, HR oversight, masters view, reports.', 'keys' => [
                'dashboard.view', 'approvals.view', 'approvals.decide', 'approvals.comment',
                'settings.view', 'employees.view', 'audit.view', 'documents.view',
                'masters.view', 'branches.view', 'roles.view', 'users.view', 'search.view',
                // HRM (§10): a manager runs their team's attendance and approves leave
                'attendance.view', 'attendance.manage', 'attendance.report',
                'leave.view', 'leave.approve', 'leave.balance',
            ]],
            ['slug' => 'employee', 'name' => 'Employee', 'description' => 'Staff account: own portal access only — own leave, own payslips.', 'keys' => [
                'dashboard.view', 'employees.view', 'documents.view', 'search.view',
                // Own leave only: the leave screen self-scopes when the user
                // cannot approve, so leave.view here never exposes the company.
                'leave.view', 'leave.request',
            ]],
            ['slug' => 'technician', 'name' => 'Technician', 'description' => 'Service technician (portal-capable).', 'keys' => [
                'dashboard.view', 'documents.view', 'documents.upload', 'search.view',
                'portal.technician.access',
            ]],
        ];

        foreach ($bundles as $bundle) {
            $role = Role::firstOrCreate(
                ['company_id' => $companyId, 'slug' => $bundle['slug']],
                [
                    'name' => $bundle['name'],
                    'description' => $bundle['description'],
                    'is_system' => true,
                ],
            );

            $ids = array_values(array_filter(array_map(fn ($k) => $all[$k] ?? null, $bundle['keys'])));
            $role->permissions()->sync($ids);
        }
    }
}
