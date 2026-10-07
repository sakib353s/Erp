<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Sales\Actions\AcceptQuotation;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\ConvertQuotationToOrder;
use App\Domain\Sales\Actions\CreateDeliveryChallan;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\DeclineQuotation;
use App\Domain\Sales\Actions\DispatchDeliveryChallan;
use App\Domain\Sales\Actions\MarkDeliveryChallanDelivered;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Actions\PosOfflineSync;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\Invoice;
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
 * Phase G: offline POS sync, quotation accept/decline,
 * delivery dispatch/deliver side-effects.
 */
class SalesOfflineQuotationDeliveryTest extends TestCase
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
            'code' => 'OFD-1',
            'sku' => 'OFD-SKU-1',
            'name' => 'Offline Delivery Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'ofd-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__offline-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides);
    }

    public function test_offline_sync_commits_sales_idempotently_by_client_uuid(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 100, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $sale = [
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 500,
            'client_uuid' => 'offline-uuid-1',
            'client_total' => 300,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ];

        $first = app(PosOfflineSync::class)->handle(['sales' => [$sale]], $this->httpRequest());
        $this->assertSame(1, $first['committed']);
        $this->assertSame(0, $first['already_committed']);
        $this->assertSame(0, $first['conflicts']);
        $this->assertSame('committed', $first['results'][0]['status']);
        $this->assertNotNull($first['results'][0]['invoice_no']);

        // Re-submit same UUID + same total → idempotent already_committed
        $second = app(PosOfflineSync::class)->handle(['sales' => [$sale]], $this->httpRequest());
        $this->assertSame(0, $second['committed']);
        $this->assertSame(1, $second['already_committed']);
        $this->assertSame('already_committed', $second['results'][0]['status']);
        $this->assertSame($first['results'][0]['transaction_id'], $second['results'][0]['transaction_id']);

        // Same UUID with different client_total → explicit conflict
        $conflictSale = $sale;
        $conflictSale['client_total'] = 999;
        $third = app(PosOfflineSync::class)->handle(['sales' => [$conflictSale]], $this->httpRequest());
        $this->assertSame(0, $third['committed']);
        $this->assertSame(1, $third['conflicts']);
        $this->assertSame('conflict', $third['results'][0]['status']);
        $this->assertStringContainsString('different total', $third['results'][0]['error']);

        // Server-authoritative: stock issued once only (2 units)
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(98.0, (float) $balance->on_hand);
        $this->assertSame(1, Invoice::query()->where('invoice_type', 'pos')->count());
    }

    public function test_offline_sync_requires_client_uuid_and_reports_failures(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        // Missing client_uuid is caught by controller validation; action-level empty batch throws
        $this->expectException(\RuntimeException::class);
        app(PosOfflineSync::class)->handle(['sales' => []], $this->httpRequest());
    }

    public function test_offline_sync_batch_partial_failure_does_not_commit_bad_rows(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $good = [
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 400,
            'client_uuid' => 'batch-good',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ];
        $bad = [
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 10,
            'client_uuid' => 'batch-bad-short-tender',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 150],
            ],
        ];

        $result = app(PosOfflineSync::class)->handle(['sales' => [$good, $bad]], $this->httpRequest());

        $this->assertSame(1, $result['committed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('committed', $result['results'][0]['status']);
        $this->assertSame('failed', $result['results'][1]['status']);
        $this->assertNotEmpty($result['results'][1]['error']);

        // Only the good sale issued stock (2 units); bad row rolled back
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(98.0, (float) $balance->on_hand);
    }

    public function test_http_offline_sync_requires_pos_offline_permission(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->postJson('/pos/sync', ['sales' => [['client_uuid' => 'x', 'lines' => []]]])
            ->assertForbidden();

        $full = $this->roleWith(['portal.erp.access', 'pos.offline', 'pos.sell']);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $this->actingAs($user)
            ->postJson('/pos/sync', [
                'sales' => [[
                    'client_uuid' => 'http-sync-1',
                    'pos_session_id' => $session->id,
                    'payment_method' => 'cash',
                    'tendered' => 200,
                    'lines' => [[
                        'product_id' => $this->product->id,
                        'qty' => 1,
                        'unit_price' => 150,
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('committed', 1);
    }

    public function test_quotation_accept_and_decline_transitions(): void
    {
        $quote = app(CreateQuotation::class)->handle($this->orderPayload(), $this->httpRequest());
        $this->assertSame('draft', $quote->status);

        $accepted = app(AcceptQuotation::class)->handle($quote, $this->httpRequest());
        $this->assertSame('accepted', $accepted->status);

        // Accept again → rejected
        try {
            app(AcceptQuotation::class)->handle($accepted, $this->httpRequest());
            $this->fail('Expected accept RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be accepted', $e->getMessage());
        }

        // Decline requires reason
        try {
            app(DeclineQuotation::class)->handle($accepted, '  ', $this->httpRequest());
            $this->fail('Expected decline reason RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }

        $declined = app(DeclineQuotation::class)->handle($accepted, 'Price too high', $this->httpRequest());
        $this->assertSame('declined', $declined->status);
        $this->assertStringContainsString('Price too high', (string) $declined->notes);

        // DOC only — no stock/GL from accept/decline
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(100.0, (float) $balance->on_hand);
        $this->assertSame(0, JournalEntry::query()->count());

        // Declined quotation cannot be converted
        try {
            app(ConvertQuotationToOrder::class)
                ->handle($declined, [], $this->httpRequest());
            $this->fail('Expected convert RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('declined', $e->getMessage());
        }
    }

    public function test_http_quotation_accept_decline_require_process_permission(): void
    {
        $quote = app(CreateQuotation::class)->handle($this->orderPayload(), $this->httpRequest());

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);
        $this->actingAs($user)
            ->post("/app/sales/quotations/{$quote->id}/accept")
            ->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.quotations.process',
            'sales.quotations.view',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)
            ->post("/app/sales/quotations/{$quote->id}/accept")
            ->assertRedirect();
        $this->assertSame('accepted', $quote->fresh()->status);

        $this->actingAs($user)
            ->post("/app/sales/quotations/{$quote->id}/decline", ['reason' => 'Changed mind'])
            ->assertRedirect();
        $this->assertSame('declined', $quote->fresh()->status);
    }

    public function test_delivery_dispatch_and_deliver_advance_challan_and_order(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $challan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [
            'courier_name' => 'SA Paribahan',
        ], $this->httpRequest());

        $this->assertSame('draft', $challan->status);
        $this->assertSame('ready_to_ship', $order->fresh()->status);

        $stockBefore = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $journalBefore = JournalEntry::query()->count();

        $dispatched = app(DispatchDeliveryChallan::class)->handle($challan, [
            'tracking_no' => 'TRK-99',
        ], $this->httpRequest());

        $this->assertSame('dispatched', $dispatched->status);
        $this->assertNotNull($dispatched->dispatched_at);
        $this->assertSame('TRK-99', $dispatched->tracking_no);

        $order->refresh();
        $this->assertSame('out_for_delivery', $order->status);

        // Dispatch again → rejected
        try {
            app(DispatchDeliveryChallan::class)->handle($dispatched, [], $this->httpRequest());
            $this->fail('Expected dispatch RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be dispatched', $e->getMessage());
        }

        // Stock/GL still at invoice stage only — dispatch is DOC lifecycle
        $stockAfter = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals((float) $stockBefore->on_hand, (float) $stockAfter->on_hand);
        $this->assertEquals((float) $stockBefore->reserved, (float) $stockAfter->reserved);
        $this->assertSame($journalBefore, JournalEntry::query()->count());

        $delivered = app(MarkDeliveryChallanDelivered::class)->handle($dispatched, $this->httpRequest());
        $this->assertSame('delivered', $delivered->status);
        $this->assertNotNull($delivered->delivered_at);

        $order->refresh();
        $this->assertSame('delivered', $order->status);

        // Delivered again → rejected
        try {
            app(MarkDeliveryChallanDelivered::class)->handle($delivered, $this->httpRequest());
            $this->fail('Expected deliver RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be marked delivered', $e->getMessage());
        }
    }

    public function test_delivery_routes_enforce_dispatch_permission(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $challan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [], $this->httpRequest());

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access', 'sales.delivery.view']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get("/app/sales/delivery-challans/{$challan->id}")
            ->assertOk();
        $this->actingAs($user)
            ->post("/app/sales/delivery-challans/{$challan->id}/dispatch")
            ->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.delivery.view',
            'sales.delivery.dispatch',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)
            ->post("/app/sales/delivery-challans/{$challan->id}/dispatch")
            ->assertRedirect();
        $this->assertSame('dispatched', $challan->fresh()->status);
    }

    public function test_structural_seeders_ship_no_fake_documents(): void
    {
        $this->assertSame(0, Quotation::query()->count());
        $this->assertSame(0, DeliveryChallan::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }
}
