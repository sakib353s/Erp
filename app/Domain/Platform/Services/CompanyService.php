<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\Support\DocumentTypeRegistry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\FeatureEntitlement;
use App\Domain\Foundation\NumberingRule;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SystemRoleSeeder;
use Database\Seeders\CashBankCoreSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\PurchaseCoreSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SINGLETON company administration (Rules 1 & 2).
 *
 * Every path that creates or mutates THE company goes through this
 * service: the DB carries a `singleton` unique guard, and this service
 * refuses any second insert with an exception rather than an upsert.
 * Branch creation is legal; company creation is not (past first boot).
 */
class CompanyService
{
    public function __construct(
        protected AuditRecorder $audit,
        protected SettingService $settings,
        protected TenantContext $context,
    ) {}

    public function exists(): bool
    {
        return Company::query()->exists();
    }

    /**
     * First-boot creation (spec §49). Returns the administrator User.
     * Throws if a company already exists — there is no code path that
     * creates a second company (decision D1 / Rules 1 & 2).
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(Company, Branch): User  $createActor  builds the admin
     *                                                        account once THE company and its head-office branch exist —
     *                                                        users.company_id is a NOT NULL foreign key, so the company
     *                                                        row must be inserted before the user can belong to it.
     */
    public function createFirst(array $attributes, callable $createActor): User
    {
        if ($this->exists()) {
            throw new InvalidArgumentException('A company already exists on this instance. One company per instance is a hard rule.');
        }

        return DB::transaction(function () use ($attributes, $createActor) {
            $company = Company::create([
                'name' => $attributes['name'],
                'legal_name' => $attributes['legal_name'] ?? $attributes['name'],
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'address_line1' => $attributes['address_line1'] ?? null,
                'address_line2' => $attributes['address_line2'] ?? null,
                'area' => $attributes['area'] ?? null,
                'district' => $attributes['district'] ?? null,
                'postal_code' => $attributes['postal_code'] ?? null,
                'country' => 'Bangladesh',
                'currency' => $attributes['currency'] ?? 'BDT',
                'timezone' => 'Asia/Dhaka',
                'locale' => $attributes['locale'] ?? 'en',
                'alt_locale' => 'bn',
                'fiscal_year_start_month' => $attributes['fiscal_year_start_month'] ?? 7,
                'trade_license_no' => $attributes['trade_license_no'] ?? null,
                'tin' => $attributes['tin'] ?? null,
                'bin' => $attributes['bin'] ?? null,
                'is_active' => true,
            ]);

            // First boot runs BEFORE any tenant middleware exists, yet the
            // settings store and the audit chain resolve THE company from
            // the trusted context — bind it here, once, from DB state.
            $this->context->setCompany($company);

            // Structural defaults: one Head Office branch (the instance is
            // usable with branch context from second zero — no fake data,
            // the operator names it).
            $branch = Branch::create([
                'company_id' => $company->id,
                'code' => 'HO',
                'name' => $attributes['branch_name'] ?? 'Head Office',
                'is_default' => true,
                'is_active' => true,
            ]);

            // Now — and only now — may the administrator exist: the NOT NULL
            // users.company_id foreign key resolves against the rows above.
            $actor = $createActor($company, $branch);

            $actor->forceFill([
                'company_id' => $company->id,
                'branch_scope' => 'all',
                'default_branch_id' => $branch->id,
            ])->save();

            $this->materializeDefaults($company, $actor);

            $this->audit->record([
                'action' => 'config.update',
                'entity_type' => 'company',
                'entity_id' => $company->id,
                'branch_id' => $branch->id,
                'actor_id' => $actor->id,
                'after' => ['name' => $company->name, 'branch' => $branch->name],
            ]);

            return $actor;
        });
    }

    /** Update THE company (never create a second one). */
    public function update(array $attributes, User $actor): Company
    {
        $company = Company::current();

        if ($company === null) {
            throw new InvalidArgumentException('Instance is not initialised; run first-boot setup.');
        }

        $company->fill($attributes);
        $company->save();

        $this->audit->record([
            'action' => 'config.update',
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'actor_id' => $actor->id,
            'before' => ['name' => $company->getOriginal('name')],
            'after' => ['name' => $company->name],
        ]);

        return $company;
    }

    /**
     * Materialise structural defaults after first boot: security/general/
     * localization settings rows (so the onboarding checklist reflects
     * reality), default numbering rules and the Administrator role.
     * NO business data — only structural scaffolding.
     */
    protected function materializeDefaults(Company $company, User $actor): void
    {
        foreach (['general', 'localization', 'security', 'numbering'] as $group) {
            $fields = config("erp.settings.groups.{$group}.fields", []);

            foreach ($fields as $key => $meta) {
                $this->settings->set($group, $key, $meta['default'] ?? null, null, $actor);
            }
        }

        // Structural document types (idempotent — normally already seeded).
        $registry = DocumentTypeRegistry::map();

        foreach ($registry as $row) {
            DocumentType::firstOrCreate(['code' => $row['code']], $row);
        }

        foreach (DocumentTypeRegistry::NUMBERING_PREFIXES as $code => $prefix) {
            $type = DocumentType::query()->where('code', $code)->first();

            if ($type === null) {
                continue;
            }

            NumberingRule::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'branch_id' => 0, // 0 = company-wide sentinel (decision D-scope)
                    'document_type_id' => $type->id,
                ],
                [
                    'prefix' => $prefix,
                    'pattern' => config('erp.numbering.default_pattern'),
                    'padding' => (int) config('erp.numbering.default_padding'),
                    'reset_period' => config('erp.numbering.default_reset_period'),
                    'is_active' => true,
                ],
            );
        }

        // Administrator role receives every permission in the matrix
        // (seeders created the full vocabulary before first boot runs).
        $role = Role::firstOrCreate(
            ['company_id' => $company->id, 'slug' => 'administrator'],
            ['name' => 'Administrator', 'description' => 'Full access to every function.', 'is_system' => true],
        );

        if (! $actor->roles()->whereKey($role->id)->exists()) {
            $role->users()->attach($actor->id);
        }

        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        // Local feature-entitlement rows (mirrored by the platform per C3);
        // without rows the runtime falls back to config default, with rows
        // the platform controls each feature explicitly.
        foreach ((array) config('erp.features.keys') as $featureKey) {
            FeatureEntitlement::firstOrCreate(
                ['company_id' => $company->id, 'feature_key' => $featureKey],
                ['is_enabled' => (bool) config('erp.features.default_enabled'), 'source' => 'platform'],
            );
        }

        // Company-scoped structural seeders return early when no company
        // exists (DatabaseSeeder often runs before first boot). Re-run them
        // now that THE company and its default branch exist: the Bangladesh
        // reference lists and the current fiscal year, the COA, the default
        // warehouse, the posting rules — still zero fake business data.
        //
        // ReferenceDataSeeder must be first: it is the seeder that creates the
        // current fiscal year, and AccountingCoreSeeder can only open that
        // year's posting periods once the year exists. Without it a fresh
        // instance has a chart of accounts and nowhere to post a single entry.
        foreach ([
            ReferenceDataSeeder::class,
            // The system roles are built by looking their keys up in the
            // permission catalogue, so the catalogue has to exist first —
            // whether or not `db:seed` was ever run before first boot.
            FoundationPermissionSeeder::class,
            SystemRoleSeeder::class,
            AccountingCoreSeeder::class,
            InventoryCoreSeeder::class,
            SalesCoreSeeder::class,
            PurchaseCoreSeeder::class,
            CashBankCoreSeeder::class,
        ] as $seeder) {
            app($seeder)->run();
        }
    }
}
