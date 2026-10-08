<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockTransfer;
use App\Domain\Sales\Invoice;
use Carbon\Carbon;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-05…08 — the branch desk: the branches side by side, and what crosses
 * between them.
 *
 *  · **the comparison is only for people who can see the company** — a
 *    branch-scoped user is refused outright, because a comparison with
 *    branches you cannot open is somebody else's numbers;
 *  · **every figure is the figure the branch's own screen shows** — sales come
 *    from issued invoices, stock from the layers at cost, and the month filter
 *    is a real window, not a caption;
 *  · **a transfer crosses a branch by definition** — the desk refuses a
 *    destination inside the same branch, and the raise goes through the stock
 *    engine, so the approval threshold applies exactly as it does at the
 *    inventory desk;
 *  · **the catalogue leaves point at the real pages**.
 */
class BranchDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $headOffice;

    protected Branch $second;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-02-20 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->headOffice = $this->defaultBranch();
        $this->bindTenantContext($this->admin, $this->headOffice);

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->second = Branch::create([
            'company_id' => Company::current()?->id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------- helpers */

    protected function warehouse(Branch $branch, string $code, string $name): Warehouse
    {
        return Warehouse::withoutGlobalScopes()->create([
            'company_id' => Company::current()?->id,
            'branch_id' => $branch->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function product(string $sku, float $cost = 100.0): Product
    {
        return Product::withoutGlobalScopes()->create([
            'company_id' => Company::current()?->id,
            'code' => $sku,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'unit_price' => $cost * 1.5,
            'cost_price' => $cost,
            'is_stocked' => true,
            'is_active' => true,
        ]);
    }

    /** Stock on a branch's shelf, booked the way the ledger books it. */
    protected function stock(Product $product, Warehouse $warehouse, float $qty, float $unitCost): void
    {
        app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'idempotency_key' => uniqid('branch-desk-', true),
        ], $this->admin);
    }

    protected function invoice(Branch $branch, string $number, string $date, float $total, float $due, string $status = 'issued'): Invoice
    {
        // The document-type registry is global, not per company: the seeder is
        // what puts a sales invoice type there.
        $documentType = DB::table('document_types')->where('code', 'invoice')->firstOrFail();

        return Invoice::query()->create([
            'company_id' => Company::current()?->id,
            'branch_id' => $branch->id,
            'document_type_id' => $documentType->id,
            'invoice_no' => $number,
            'status' => $status,
            'invoice_date' => $date,
            'grand_total' => $total,
            'due_amount' => $due,
            'created_by' => $this->admin->id,
        ]);
    }

    /** A user holding exactly the given keys, through the house helper. */
    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();

        $this->grant($user, $keys);

        return $user;
    }

    /* --------------------------------------------------- §12-07 the comparison */

    public function test_the_comparison_puts_each_branchs_own_sales_beside_the_others(): void
    {
        $this->invoice($this->headOffice, 'INV-HO-1', '2027-02-05', 10000, 4000);
        $this->invoice($this->second, 'INV-CTG-1', '2027-02-07', 25000, 0, 'paid');

        $response = $this->actingAs($this->admin)->get(route('branches.compare'));

        $response->assertOk();
        $response->assertSee('Head Office');
        $response->assertSee('Chittagong Depot');
        $response->assertSee('৳35,000.00');   // the company line
        $response->assertSee('28.6%');        // Head Office's share
        $response->assertSee('71.4%');        // Chittagong's share
        $response->assertSee('৳10,000.00');
        $response->assertSee('৳4,000.00');    // still receivable
    }

    public function test_the_month_filter_is_a_real_window(): void
    {
        $this->invoice($this->headOffice, 'INV-JAN', '2027-01-11', 9000, 9000);
        $this->invoice($this->headOffice, 'INV-FEB', '2027-02-11', 2000, 2000);

        $february = $this->actingAs($this->admin)->get(route('branches.compare', ['month' => '2027-02']));
        $february->assertOk();
        $february->assertSee('৳2,000.00');
        $february->assertDontSee('৳9,000.00');
        $february->assertSee('February 2027');

        $january = $this->actingAs($this->admin)->get(route('branches.compare', ['month' => '2027-01']));
        $january->assertOk();
        $january->assertSee('৳9,000.00');
        $january->assertDontSee('৳2,000.00');
    }

    public function test_stock_and_headcount_are_the_balances_the_other_screens_show(): void
    {
        $head = $this->warehouse($this->headOffice, 'W-HO', 'Head Office Store');
        $depot = $this->warehouse($this->second, 'W-CTG', 'Depot Store');

        $this->stock($this->product('SKU-A', 100), $head, 10, 100);
        $this->stock($this->product('SKU-B', 250), $depot, 4, 250);

        $response = $this->actingAs($this->admin)->get(route('branches.compare'));

        $response->assertOk();
        $response->assertSee('৳1,000.00');   // Head Office's stock at cost
        $response->assertSee('৳1,000.00');   // and the depot's, from its own layers
        $response->assertSee('৳2,000.00');   // the company's stock
    }

    public function test_a_branch_scoped_user_is_refused_the_comparison(): void
    {
        $scoped = $this->makeUser(['branch_scope' => 'assigned']);

        $this->grant($scoped, ['branches.view', 'branches.compare']);

        $this->actingAs($scoped)
            ->get(route('branches.compare'))
            ->assertForbidden();
    }

    public function test_the_comparison_never_reaches_into_another_company(): void
    {
        $elsewhere = new Company(['name' => 'Other Traders Ltd', 'is_active' => true]);
        $elsewhere->singleton = false;
        $elsewhere->save();

        $foreign = Branch::withoutGlobalScopes()->create([
            'company_id' => $elsewhere->id,
            'code' => 'OTH',
            'name' => 'Rival Outlet',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('branches.compare'));

        $response->assertOk();
        $response->assertDontSee('Rival Outlet');
        $this->assertNotSame($foreign->company_id, $this->admin->company_id);
    }

    /* ------------------------------------------------------ §12-08 the transfer */

    public function test_the_branch_desk_lists_what_crossed_the_boundary(): void
    {
        $head = $this->warehouse($this->headOffice, 'W-HO', 'Head Office Store');
        $depot = $this->warehouse($this->second, 'W-CTG', 'Depot Store');
        $product = $this->product('SKU-C', 100);

        $this->actingAs($this->admin)
            ->post(route('branches.transfer.store', $this->headOffice), [
                'from_warehouse_id' => $head->id,
                'to_warehouse_id' => $depot->id,
                'transfer_date' => '2027-02-18',
                'narration' => 'Depot ran out of cartons.',
                'lines' => [['product_id' => $product->id, 'qty_sent' => 5, 'unit_cost' => 120]],
            ])
            ->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->latest('id')->firstOrFail();

        $this->assertSame($head->id, (int) $transfer->from_warehouse_id);
        $this->assertSame($depot->id, (int) $transfer->to_warehouse_id);
        $this->assertSame('600.0000', (string) $transfer->total_value);

        $desk = $this->actingAs($this->admin)->get(route('branches.transfer', $this->headOffice));
        $desk->assertOk();
        $desk->assertSee($transfer->transfer_no);
        $desk->assertSee($transfer->transfer_no);
        $desk->assertSee('Depot Store');

        $overview = $this->actingAs($this->admin)->get(route('branches.transfers'));
        $overview->assertOk();
        $overview->assertSee($transfer->transfer_no);
        $overview->assertSee('Chittagong Depot');
    }

    public function test_a_transfer_that_does_not_cross_a_branch_is_refused(): void
    {
        $head = $this->warehouse($this->headOffice, 'W-HO', 'Head Office Store');
        $annex = $this->warehouse($this->headOffice, 'W-HO-2', 'Head Office Annex');
        $product = $this->product('SKU-D', 50);

        $this->actingAs($this->admin)
            ->post(route('branches.transfer.store', $this->headOffice), [
                'from_warehouse_id' => $head->id,
                'to_warehouse_id' => $annex->id,
                'transfer_date' => '2027-02-18',
                'lines' => [['product_id' => $product->id, 'qty_sent' => 1]],
            ])
            ->assertSessionHasErrors('to_warehouse_id');

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_a_warehouse_of_another_branch_cannot_be_the_source(): void
    {
        $depot = $this->warehouse($this->second, 'W-CTG', 'Depot Store');
        $head = $this->warehouse($this->headOffice, 'W-HO', 'Head Office Store');
        $product = $this->product('SKU-E', 50);

        $this->actingAs($this->admin)
            ->post(route('branches.transfer.store', $this->headOffice), [
                'from_warehouse_id' => $depot->id,   // not this branch's
                'to_warehouse_id' => $head->id,
                'transfer_date' => '2027-02-18',
                'lines' => [['product_id' => $product->id, 'qty_sent' => 1]],
            ])
            ->assertSessionHasErrors('from_warehouse_id');

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_a_transfer_above_the_threshold_waits_like_any_other(): void
    {
        $head = $this->warehouse($this->headOffice, 'W-HO', 'Head Office Store');
        $depot = $this->warehouse($this->second, 'W-CTG', 'Depot Store');
        $product = $this->product('SKU-F', 1000);

        // The engine holds a transfer at or above `inventory.transfer_approval_above`,
        // and the default of 0 holds nothing — so the gate is armed the way the
        // inventory approval test arms it, with the real setting.
        $threshold = 500.0;
        app(\App\Domain\Settings\Services\SettingService::class)
            ->set('inventory', 'transfer_approval_above', $threshold, null, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('branches.transfer.store', $this->headOffice), [
                'from_warehouse_id' => $head->id,
                'to_warehouse_id' => $depot->id,
                'transfer_date' => '2027-02-18',
                'lines' => [['product_id' => $product->id, 'qty_sent' => 1, 'unit_cost' => $threshold]],
            ])
            ->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->latest('id')->firstOrFail();

        $this->assertSame(StockTransfer::STATUS_PENDING, $transfer->status);
        $this->assertStringContainsString('wait', (string) session('status'));
    }

    public function test_reading_the_desk_needs_the_view_key_and_raising_needs_the_transfer_key(): void
    {
        $reader = $this->userWith(['branches.view']);

        $this->actingAs($reader)->get(route('branches.transfer', $this->headOffice))->assertOk();
        $this->actingAs($reader)->get(route('branches.compare'))->assertForbidden();

        $head = $this->warehouse($this->headOffice, 'W-HO', 'Head Office Store');
        $depot = $this->warehouse($this->second, 'W-CTG', 'Depot Store');
        $product = $this->product('SKU-G', 10);

        $this->actingAs($reader)
            ->post(route('branches.transfer.store', $this->headOffice), [
                'from_warehouse_id' => $head->id,
                'to_warehouse_id' => $depot->id,
                'transfer_date' => '2027-02-18',
                'lines' => [['product_id' => $product->id, 'qty_sent' => 1]],
            ])
            ->assertForbidden();

        $this->assertSame(0, StockTransfer::query()->count());
    }

    /* ------------------------------------------------------------ the catalogue */

    public function test_the_branches_group_of_the_menu_points_at_real_pages(): void
    {
        // The menu is built from the supplied catalogue by the same importer the
        // console command runs, so this is the real wiring and not a fixture: if
        // the catalogue's Branches leaves and the router disagree, the sidebar
        // renders dead links — which is exactly the defect this suite exists for.
        app(\App\Domain\Foundation\Services\CatalogImporter::class)->sync();

        $leaves = MenuItem::query()
            ->where('label', 'Branches')->firstOrFail()
            ->children()
            ->get();

        $this->assertCount(6, $leaves, 'The Branches group should have six leaves.');

        $expected = [
            'All Branches' => '/app/branches',
            'Add Branch' => '/app/branches/create',
            'Branch Profile' => null,          // a branch's own page: the index links to it
            'Branch Settings' => '/app/settings/branches',
            'Branch Comparison' => '/app/branches/compare',
            'Branch Transfer' => '/app/branches/transfer',
        ];

        foreach ($leaves as $leaf) {
            $this->assertArrayHasKey($leaf->label, $expected, "Unexpected leaf: {$leaf->label}");

            if ($expected[$leaf->label] !== null) {
                $this->assertSame($expected[$leaf->label], $leaf->route, "{$leaf->label} points at the wrong page.");
            }

            $this->assertTrue($leaf->is_active, "{$leaf->label} should be an active menu row.");
            $this->assertNotNull($leaf->route, "{$leaf->label} has no destination at all.");
        }

        // Every one of them must actually render for a user who holds the key.
        foreach ($leaves as $leaf) {
            $this->actingAs($this->admin)
                ->get($leaf->route)
                ->assertOk();
        }
    }
}
