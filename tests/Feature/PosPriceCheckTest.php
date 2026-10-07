<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-42 POS price check mode: GET /pos/price-check resolves the server's
 * quoted unit price (price list → fallback) and writes nothing.
 */
class PosPriceCheckTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__price-check', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(string $code, string $name, bool $active = true): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => $active,
        ], $this->httpRequest());
    }

    protected function userWith(array $permissionKeys): User
    {
        $user = $this->makeUser(['name' => 'Price Checker']);
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_price_check_returns_the_quoted_unit_price_for_a_sku(): void
    {
        $product = $this->makeProduct('PCHK-1', 'Price Probe');
        $user = $this->userWith(['portal.erp.access', 'pos.price_check']);

        $response = $this->actingAs($user)
            ->getJson(route('pos.price-check', ['q' => $product->code]));

        $response->assertOk()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.code', $product->code);

        $this->assertEquals(100.0, (float) $response->json('data.unit_price'));
        $this->assertNull($response->json('data.price_list'));
    }

    public function test_price_check_prefers_an_effective_price_list_item(): void
    {
        $product = $this->makeProduct('PCHK-2', 'Listed Probe');

        $priceList = PriceList::create([
            'company_id' => $this->admin->company_id,
            'code' => 'POS-RETAIL',
            'name' => 'Retail price list',
            'is_default' => true,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'price' => 250,
        ]);

        $user = $this->userWith(['portal.erp.access', 'pos.price_check']);

        $response = $this->actingAs($user)
            ->getJson(route('pos.price-check', ['q' => $product->code]));

        $response->assertOk();
        $this->assertEquals(250.0, (float) $response->json('data.unit_price'));
        $this->assertSame('Retail price list', $response->json('data.price_list'));
    }

    public function test_price_check_writes_nothing(): void
    {
        $product = $this->makeProduct('PCHK-3', 'ReadOnly Probe');
        $user = $this->userWith(['portal.erp.access', 'pos.price_check']);

        $movements = StockMovement::query()->count();
        $items = PriceListItem::query()->count();
        $audits = AuditEvent::query()->count();

        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['q' => $product->code]))
            ->assertOk();
        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['product_id' => $product->id]))
            ->assertOk();

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame($items, PriceListItem::query()->count());
        $this->assertSame($audits, AuditEvent::query()->count());
        $this->assertEquals(100.0, (float) $product->fresh()->standard_cost);
    }

    public function test_price_check_is_scoped_to_active_products_of_this_company(): void
    {
        $inactive = $this->makeProduct('PCHK-4', 'Retired Probe', false);
        $active = $this->makeProduct('PCHK-5', 'Live Probe');
        $user = $this->userWith(['portal.erp.access', 'pos.price_check']);

        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['product_id' => 999999]))
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['q' => $inactive->code]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['product_id' => $active->id]))
            ->assertOk()
            ->assertJsonPath('data.product_id', $active->id);
    }

    public function test_price_check_requires_its_own_permission(): void
    {
        $product = $this->makeProduct('PCHK-6', 'Gate Probe');

        $sellerOnly = $this->userWith(['portal.erp.access', 'pos.sell']);
        $this->actingAs($sellerOnly)
            ->getJson(route('pos.price-check', ['q' => $product->code]))
            ->assertForbidden();

        $checker = $this->userWith(['portal.erp.access', 'pos.price_check']);
        $this->actingAs($checker)
            ->getJson(route('pos.price-check', ['q' => $product->code]))
            ->assertOk();
    }

    public function test_terminal_renders_the_price_check_panel_only_when_permitted(): void
    {
        $checker = $this->userWith(['portal.erp.access', 'pos.sell', 'pos.price_check']);
        $this->actingAs($checker)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertSee('Price check')
            ->assertSee('price-check-input')
            ->assertSee(route('pos.price-check'));

        $sellerOnly = $this->userWith(['portal.erp.access', 'pos.sell']);
        $this->actingAs($sellerOnly)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertDontSee('price-check-input');
    }

    public function test_unknown_or_ambiguous_searches_are_reported(): void
    {
        $this->makeProduct('PWID-1', 'Widget Alpha');
        $this->makeProduct('PWID-2', 'Widget Beta');
        $user = $this->userWith(['portal.erp.access', 'pos.price_check']);

        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['q' => 'Widget']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->actingAs($user)
            ->getJson(route('pos.price-check', ['q' => 'does-not-exist']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->actingAs($user)
            ->getJson(route('pos.price-check', []))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q', 'product_id']);
    }
}
