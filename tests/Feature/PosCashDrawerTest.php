<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\PosSession;
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
 * 02-44 Sales › POS › Cash Drawer: the drawer screen renders live state
 * from the open session (counters + expected cash using the close
 * formula), the session's cash events (the balanced journal entries each
 * movement posted) and its audit trail below. Honest empty state when no
 * drawer is open. The route and the menu leaf sit behind pos.cash_drawer.
 */
class PosCashDrawerTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

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

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'POSDRW-1',
            'sku' => 'POSDRW-SKU-1',
            'name' => 'POS Drawer Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'posdrw-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-drawer-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** Open a session and commit a 5 × 150 cash sale (cash_sales 750). */
    protected function openSessionWithSale(float $float = 1000.0): PosSession
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => $float, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 1000,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        return $session->fresh();
    }

    protected function userWith(array $permissionKeys): User
    {
        $user = $this->makeUser();
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_drawer_renders_live_state_with_cash_events_and_audit_trail(): void
    {
        $session = $this->openSessionWithSale(1000.0);

        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), ['direction' => 'in', 'amount' => 500, 'reason' => 'Float from safe'])
            ->assertRedirect(route('pos.cash-io.index'));
        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), ['direction' => 'out', 'amount' => 200, 'reason' => 'Safe drop'])
            ->assertRedirect(route('pos.cash-io.index'));

        // expected = 1000 opening + 750 cash sales + 500 in − 200 out = 2050
        $this->actingAs($this->admin)
            ->get(route('pos.drawer'))
            ->assertOk()
            ->assertSee($session->session_no)
            ->assertSee('Opening float')
            ->assertSee('Expected cash')
            ->assertSee('2,050.00')
            ->assertSee('Float from safe')
            ->assertSee('Safe drop')
            ->assertSee('pos.cash_moved');

        $journal = JournalEntry::query()
            ->where('source_id', $session->id)
            ->where('source_type', 'pos_session')
            ->latest('id')
            ->firstOrFail();
        $this->actingAs($this->admin)
            ->get(route('pos.drawer'))
            ->assertSee($journal->entry_no);
    }

    public function test_drawer_honest_empty_state_without_open_session(): void
    {
        $this->actingAs($this->admin)
            ->get(route('pos.drawer'))
            ->assertOk()
            ->assertSee('No open cash drawer')
            ->assertDontSee('Opening float')
            ->assertDontSee('Cash events');
    }

    public function test_drawer_is_honest_after_the_session_closes(): void
    {
        $session = $this->openSessionWithSale(1000.0);
        app(ClosePosSession::class)->handle($session, 1750.0, $this->httpRequest());

        $this->actingAs($this->admin)
            ->get(route('pos.drawer'))
            ->assertOk()
            ->assertSee('No open cash drawer')
            ->assertDontSee($session->session_no)
            ->assertDontSee('Cash events');
    }

    public function test_drawer_route_requires_pos_cash_drawer_permission(): void
    {
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->get(route('pos.drawer'))
            ->assertForbidden();

        // The cash in/out key alone never opens the drawer view.
        $this->actingAs($this->userWith(['portal.erp.access', 'pos.cash_io']))
            ->get(route('pos.drawer'))
            ->assertForbidden();

        $granted = $this->userWith(['portal.erp.access', 'pos.cash_drawer']);
        $this->actingAs($granted)
            ->get(route('pos.drawer'))
            ->assertOk()
            ->assertSee('Cash Drawer');
    }

    public function test_cash_drawer_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Cash Drawer')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos/drawer', $leaf->route);
        $this->assertSame('pos.cash_drawer', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Cash Drawer');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Cash Drawer');
    }
}
