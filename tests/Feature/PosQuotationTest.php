<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Quotation;
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
 * 02-40 Sales › POS › POS Quotation: the terminal cart becomes a draft
 * quotation through CreateQuotation — DOC only (no stock, no GL), totals
 * and pricing recomputed server-side. The endpoint rides
 * sales.quotations.create (not pos.sell); the terminal's Save as
 * quotation button and the POS Quotation menu leaf are gated by the same
 * key.
 */
class PosQuotationTest extends TestCase
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
            'code' => 'POSQT-1',
            'sku' => 'POSQT-SKU-1',
            'name' => 'POS Quotation Product',
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
            'idempotency_suffix' => 'posqt-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-quotation-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function quotePayload(array $overrides = []): array
    {
        return array_merge([
            'notes' => 'Counter quote',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides);
    }

    protected function userWith(array $permissionKeys): User
    {
        $user = $this->makeUser();
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_pos_quotation_saves_the_cart_as_a_draft_quotation(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), $this->quotePayload())
            ->assertRedirect(route('pos.terminal'))
            ->assertSessionHas('status');

        $quote = Quotation::query()->sole();
        $this->assertSame('draft', $quote->status);
        $this->assertStringStartsWith('QT/', $quote->quote_no);
        $this->assertSame('none', $quote->workflow_state);
        $this->assertSame('draft', $quote->posting_state);
        $this->assertEquals(750.0, (float) $quote->grand_total);
        $this->assertSame('Counter quote', $quote->notes);

        $this->assertCount(1, $quote->lines);
        $line = $quote->lines->first();
        $this->assertEquals(5.0, (float) $line->qty);
        $this->assertEquals(150.0, (float) $line->unit_price);
        $this->assertEquals(750.0, (float) $line->line_total);

        $this->assertStringContainsString(
            $quote->quote_no,
            (string) session('status'),
        );
    }

    public function test_pos_quotation_is_doc_only_no_stock_no_gl(): void
    {
        $stockBefore = (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand;
        $movementsBefore = StockMovement::query()->count();

        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), $this->quotePayload())
            ->assertRedirect(route('pos.terminal'));

        $this->assertEquals($stockBefore, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);
        $this->assertSame(
            $movementsBefore,
            StockMovement::query()->count(),
        );
        $this->assertSame(0, JournalEntry::query()->count());

        $audit = AuditEvent::query()
            ->where('action', 'sales.quotation_created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('quotation', $audit->entity_type);
        $this->assertSame(
            Quotation::query()->sole()->quote_no,
            $audit->after['quote_no'],
        );
    }

    public function test_pos_quotation_validates_lines_and_qty(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), ['notes' => 'x'])
            ->assertSessionHasErrors(['lines']);
        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), $this->quotePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 0]],
            ]))
            ->assertSessionHasErrors(['lines.0.qty']);
        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), $this->quotePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => -2]],
            ]))
            ->assertSessionHasErrors(['lines.0.qty']);
        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), $this->quotePayload([
                'lines' => [['product_id' => 999999, 'qty' => 1]],
            ]))
            ->assertSessionHasErrors(['lines.0.product_id']);

        $this->assertSame(0, Quotation::query()->count());
    }

    public function test_pos_quotation_route_requires_sales_quotations_create(): void
    {
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->post(route('pos.quotation.store'), $this->quotePayload())
            ->assertForbidden();
        $this->assertSame(0, Quotation::query()->count());

        // The endpoint rides sales.quotations.create alone — no pos.sell needed.
        $granted = $this->userWith(['portal.erp.access', 'sales.quotations.create']);
        $this->actingAs($granted)
            ->post(route('pos.quotation.store'), $this->quotePayload())
            ->assertRedirect(route('pos.terminal'));

        $this->assertSame(1, Quotation::query()->count());
        $this->assertSame('draft', Quotation::query()->sole()->status);
    }

    public function test_pos_quotation_reaches_the_quotations_list(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pos.quotation.store'), $this->quotePayload())
            ->assertRedirect(route('pos.terminal'));

        $quote = Quotation::query()->sole();
        $this->actingAs($this->admin)
            ->get(route('sales.quotations.index'))
            ->assertOk()
            ->assertSee($quote->quote_no)
            ->assertSee('draft');
    }

    public function test_terminal_shows_quotation_button_only_with_permission(): void
    {
        app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $withKey = $this->userWith(['portal.erp.access', 'pos.sell', 'sales.quotations.create']);
        $this->actingAs($withKey)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertSee('Save as quotation');

        $withoutKey = $this->userWith(['portal.erp.access', 'pos.sell']);
        $this->actingAs($withoutKey)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertDontSee('Save as quotation');
    }

    public function test_pos_quotation_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'POS Quotation')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos', $leaf->route);
        $this->assertSame('sales.quotations.create', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('POS Quotation');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('POS Quotation');
    }
}
