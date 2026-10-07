<?php

namespace Tests\Concerns;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\CustomerGroup;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\District;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Masters\PricingRule;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowDefinition;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixture for the 02-111+ pricing tests: company instance,
 * two products on a default price list (100 / 200), a base customer and
 * helpers for groups, zones and rule rows. 02-113 adds an analyst
 * (pricing.rules, no override authority) and the pricing_rule/override
 * workflow seed.
 */
trait BuildsPricingRules
{
    use CreatesERPInstance;

    protected User $admin;

    protected Product $product;

    protected Product $other;

    protected PriceList $list;

    protected Customer $customer;

    protected function setUpPricing(): void
    {
        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->product = $this->pricingProduct('PRC-1', 'Pricing Probe One');
        $this->other = $this->pricingProduct('PRC-2', 'Pricing Probe Two');

        $this->list = PriceList::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PRC-LIST',
            'name' => 'Pricing default list',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_default' => true,
        ]);

        PriceListItem::query()->create([
            'price_list_id' => $this->list->id,
            'product_id' => $this->product->id,
            'price' => 100,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $this->list->id,
            'product_id' => $this->other->id,
            'price' => 200,
        ]);

        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PRC-CUST',
            'name' => 'Pricing Customer',
            'is_active' => true,
        ]);
    }

    protected function pricingRequest(): Request
    {
        $request = Request::create('/__pricing-rules', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function pricingProduct(string $code, string $name, float $standardCost = 100): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => $standardCost,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->pricingRequest());
    }

    /** @return array<string, mixed> */
    protected function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Probe rule',
            'priority' => 100,
            'price' => 90,
        ], $overrides);
    }

    protected function makeRule(array $overrides = []): PricingRule
    {
        return PricingRule::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
        ], $this->rulePayload($overrides)));
    }

    protected function makeGroup(string $code = 'PRC-GRP'): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => "Group {$code}",
            'is_active' => true,
        ]);
    }

    /** Maps the customer's district into a fresh company delivery zone. */
    protected function makeZoneFor(Customer $customer): DeliveryZone
    {
        $district = District::query()->orderBy('id')->firstOrFail();

        $customer->district_id = $district->id;
        $customer->save();

        $zone = DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'ZONE-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Probe zone',
        ]);

        DB::table('delivery_zone_district')->insert([
            'delivery_zone_id' => $zone->id,
            'district_id' => $district->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $zone;
    }

    /**
     * Analyst with pricing.rules but no pricing.override authority (02-113):
     * superadmins always hold every permission, so gated paths need one.
     */
    protected function makePricingAnalyst(string $name = 'Pricing Analyst'): User
    {
        $analyst = $this->makeUser(['name' => $name]);
        $analyst->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.rules'])->id);
        app(PermissionCatalog::class)->invalidate($analyst);

        return $analyst;
    }

    /** Seeds the pricing_rule/override workflow; returns its approver (02-113). */
    protected function makeOverrideWorkflow(): User
    {
        $role = $this->roleWith([]);
        $approver = $this->makeUser(['name' => 'Pricing Override Approver']);
        $approver->roles()->sync([$role->id]);

        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => PricingRule::ENTITY_TYPE,
            'action' => PricingRule::APPROVAL_ACTION,
            'name' => 'Pricing rule override approval',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);

        return $approver;
    }
}
