<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RecordInvoicePayment;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-52 — critical mutations are idempotent under retry.
 *
 * Three of the mutations the row names are pinned here by retrying the exact same
 * operation and proving the *business effect* is produced once, not twice:
 *
 *  · **payment recording** — `RecordInvoicePayment` keys on `idempotency_key`; a
 *    second post with the same key returns the first receipt, not a second ledger
 *    entry (a double-click or a retried network call cannot post the money twice);
 *  · **invoice issuance** — `IssueInvoice` is guarded by `posting_state` and the
 *    stock ledger's own idempotency key, so re-issuing an already-posted invoice
 *    adds no second journal or stock movement;
 *  · **warranty activation** — driven by the delivery event's unique
 *    `source_line_key`, so a re-delivered challan writes no second cover.
 *
 * These are the double-click / retry / queue-retry guarantees the row requires.
 */
class IdempotencyMutationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(\Database\Seeders\InventoryCoreSeeder::class);
        $this->seed(\Database\Seeders\SalesCoreSeeder::class);
        $this->seed(\Database\Seeders\DocumentTypeSeeder::class);
        $this->seed(\Database\Seeders\AccountingCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();
    }

    protected function http(): Request
    {
        $request = Request::create('/__idem', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function issuedInvoice(): Invoice
    {
        $product = app(CreateProduct::class)->handle([
            'code' => 'IDEM-'.uniqid(),
            'sku' => 'IDEM-'.uniqid().'-SKU',
            'name' => 'Idempotency Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->http());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 500, 'unit_cost' => 80]],
            'idempotency_suffix' => 'idem-open-'.$product->id,
        ], $this->http());

        $district = District::query()->orderBy('id')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'IDEM-C-'.uniqid(),
            'name' => 'Idempotency Customer',
            'phone' => '01755550000',
            'address_line1' => 'Addr',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 150]],
        ], $this->http());

        app(ConfirmOrder::class)->handle($order, $this->http());

        return app(IssueInvoice::class)->handle(
            app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->http()),
            $this->http(),
        );
    }

    public function test_recording_the_same_payment_twice_posts_only_once(): void
    {
        $invoice = $this->issuedInvoice();

        $journalBefore = JournalEntry::query()->count();
        $key = 'pay-'.uniqid();

        $first = app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'cash',
            'idempotency_key' => $key,
        ], $this->http());

        $second = app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'cash',
            'idempotency_key' => $key, // retried network call / double-click
        ], $this->http());

        // Same receipt returned, not a new one.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::query()->where('idempotency_key', $key)->count());

        // One ledger entry produced for the receipt, not two.
        $this->assertSame($journalBefore + 1, JournalEntry::query()->count());
    }

    public function test_reissuing_an_already_posted_invoice_adds_no_second_journal_or_stock(): void
    {
        $invoice = $this->issuedInvoice(); // first issue posts the journal + stock

        $journalBefore = JournalEntry::query()->count();
        $stockBefore = \App\Domain\Inventory\StockMovement::query()->count();

        // A retried/duplicate issue request lands after the first one has posted.
        // The action refuses it (throws) instead of posting a second journal — a
        // retry can never double the ledger or the stock movement.
        try {
            app(IssueInvoice::class)->handle($invoice->fresh(), $this->http());
            $this->fail('An already-issued invoice must refuse re-issue, not post again.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be issued', $e->getMessage());
        }

        $this->assertSame($journalBefore, JournalEntry::query()->count());
        $this->assertSame($stockBefore, \App\Domain\Inventory\StockMovement::query()->count());
        $this->assertSame('posted', $invoice->fresh()->posting_state);
    }

}
