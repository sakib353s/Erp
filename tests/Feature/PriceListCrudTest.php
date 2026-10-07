<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Sales\Services\PricingService;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-108 Price list management: GET|POST /app/pricing/price-lists with
 * per-product price rows. Guards: company-unique code, valid window,
 * one default list per company, non-negative prices for own-company
 * products only. The default list is what PricingService resolves.
 */
class PriceListCrudTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Product $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->product = $this->makeProduct('PLST-1', 'List Probe One');
        $this->other = $this->makeProduct('PLST-2', 'List Probe Two');
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__price-lists', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(string $code, string $name): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'RETAIL-A',
            'name' => 'Retail A',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => 1,
            'items' => [
                ['product_id' => $this->product->id, 'price' => 120.5],
                ['product_id' => $this->other->id, 'price' => 90],
            ],
        ], $overrides);
    }

    public function test_price_list_crud_writes_headers_and_price_rows(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.price-lists.store'), $this->payload())
            ->assertRedirect(route('pricing.price-lists.index'));

        $list = PriceList::query()->where('code', 'RETAIL-A')->firstOrFail();

        $this->assertSame($this->admin->company_id, $list->company_id);
        $this->assertCount(2, $list->items);
        $this->assertSame(
            120.5,
            (float) PriceListItem::query()
                ->where('price_list_id', $list->id)
                ->where('product_id', $this->product->id)
                ->firstOrFail()
                ->price,
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'record.create',
            'entity_type' => PriceList::class,
            'entity_id' => $list->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.price-lists.index'))
            ->assertOk()
            ->assertSee('RETAIL-A')
            ->assertSee('Retail A');

        $this->actingAs($this->admin)
            ->get(route('pricing.price-lists.edit', $list))
            ->assertOk()
            ->assertSee($this->product->sku);

        $this->actingAs($this->admin)
            ->delete(route('pricing.price-lists.destroy', $list))
            ->assertRedirect(route('pricing.price-lists.index'));

        $this->assertDatabaseMissing('price_lists', ['id' => $list->id]);
        $this->assertDatabaseMissing('price_list_items', ['price_list_id' => $list->id]);
    }

    public function test_edit_replaces_the_price_row_set(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.price-lists.store'), $this->payload())
            ->assertRedirect(route('pricing.price-lists.index'));

        $list = PriceList::query()->where('code', 'RETAIL-A')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('pricing.price-lists.update', $list), $this->payload([
                'name' => 'Retail A Revised',
                'items' => [
                    ['product_id' => $this->product->id, 'price' => 135],
                ],
            ]))
            ->assertRedirect(route('pricing.price-lists.index'));

        $list->refresh();
        $this->assertSame('Retail A Revised', $list->name);
        $this->assertCount(1, $list->items);
        $this->assertSame(
            135.0,
            (float) $list->items()->where('product_id', $this->product->id)->firstOrFail()->price,
        );
        $this->assertSame(
            0,
            $list->items()->where('product_id', $this->other->id)->count(),
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'record.update',
            'entity_type' => PriceList::class,
            'entity_id' => $list->id,
        ]);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.price-lists.store'), $this->payload())
            ->assertRedirect(route('pricing.price-lists.index'));

        $this->actingAs($this->admin)
            ->from(route('pricing.price-lists.create'))
            ->post(route('pricing.price-lists.store'), $this->payload([
                'name' => 'Same code different name',
            ]))
            ->assertRedirect(route('pricing.price-lists.create'))
            ->assertSessionHasErrors('code');

        $this->assertSame(
            1,
            PriceList::query()->where('code', 'RETAIL-A')->count(),
        );
    }

    public function test_invalid_window_and_negative_price_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('pricing.price-lists.create'))
            ->post(route('pricing.price-lists.store'), $this->payload([
                'valid_from' => now()->toDateString(),
                'valid_to' => now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('valid_to');

        $this->actingAs($this->admin)
            ->from(route('pricing.price-lists.create'))
            ->post(route('pricing.price-lists.store'), $this->payload([
                'items' => [
                    ['product_id' => $this->product->id, 'price' => -5],
                ],
            ]))
            ->assertSessionHasErrors('items.0.price');

        $this->actingAs($this->admin)
            ->from(route('pricing.price-lists.create'))
            ->post(route('pricing.price-lists.store'), $this->payload([
                'items' => [
                    ['product_id' => 999999, 'price' => 10],
                ],
            ]))
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, PriceList::query()->count());
    }

    public function test_single_default_list_drives_unit_price_resolution(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.price-lists.store'), $this->payload(['is_default' => 1]))
            ->assertRedirect(route('pricing.price-lists.index'));

        $pricing = app(PricingService::class);

        $this->assertEqualsWithDelta(120.5, $pricing->resolveUnitPrice($this->product), 0.0001);

        $this->actingAs($this->admin)
            ->post(route('pricing.price-lists.store'), $this->payload([
                'code' => 'RETAIL-B',
                'name' => 'Retail B',
                'is_default' => 1,
                'items' => [
                    ['product_id' => $this->product->id, 'price' => 130],
                ],
            ]))
            ->assertRedirect(route('pricing.price-lists.index'));

        $this->assertSame(
            1,
            PriceList::query()->where('is_default', true)->count(),
        );
        $this->assertTrue(
            (bool) PriceList::query()->where('code', 'RETAIL-B')->firstOrFail()->is_default,
        );
        $this->assertFalse(
            (bool) PriceList::query()->where('code', 'RETAIL-A')->firstOrFail()->is_default,
        );
        $this->assertEqualsWithDelta(130.0, $pricing->resolveUnitPrice($this->product), 0.0001);
    }

    public function test_inactive_or_out_of_window_list_falls_back_to_standard_cost(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.price-lists.store'), $this->payload(['is_default' => 1]))
            ->assertRedirect(route('pricing.price-lists.index'));

        $list = PriceList::query()->where('code', 'RETAIL-A')->firstOrFail();
        $pricing = app(PricingService::class);

        $this->actingAs($this->admin)
            ->put(route('pricing.price-lists.update', $list), $this->payload([
                'is_default' => 1,
                'is_active' => 0,
            ]))
            ->assertRedirect(route('pricing.price-lists.index'));

        $this->assertEqualsWithDelta(100.0, $pricing->resolveUnitPrice($this->product), 0.0001);

        $this->actingAs($this->admin)
            ->put(route('pricing.price-lists.update', $list), $this->payload([
                'is_default' => 1,
                'is_active' => 1,
                'valid_from' => now()->addDays(5)->toDateString(),
                'valid_to' => now()->addMonth()->toDateString(),
            ]))
            ->assertRedirect(route('pricing.price-lists.index'));

        $this->assertEqualsWithDelta(100.0, $pricing->resolveUnitPrice($this->product), 0.0001);
    }

    public function test_price_list_routes_require_the_pricing_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Pricing Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('pricing.price-lists.index'))
            ->assertForbidden();

        $this->actingAs($denied)
            ->post(route('pricing.price-lists.store'), $this->payload())
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Pricing Manager']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'pricing.manage',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('pricing.price-lists.index'))
            ->assertOk();

        $this->actingAs($allowed)
            ->get(route('pricing.price-lists.create'))
            ->assertOk();
    }
}
