<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateDeliveryChallan;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\ProductWarranty;
use App\Domain\Sales\Services\WarrantyService;
use App\Domain\Sales\Warranty;
use App\Domain\Sales\WarrantyClaim;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-16 / §16-17 / §16-18 — warranties, their register, and their claims.
 *
 * What this pins, in the order a complaint would arrive:
 *
 *  · cover exists only where a promise was made: a product with no policy is
 *    delivered with no warranty and no error, and the register says so rather
 *    than inventing twelve months on the company's behalf;
 *  · the promise attaches to the delivery event itself and to the line it came
 *    from, so marking a challan delivered twice cannot buy the customer a second
 *    year — that is structural, not hopeful, and this suite proves it by trying;
 *  · the dates are fixed when the cover starts: editing the product's policy
 *    afterwards moves neither `starts_on` nor `ends_on` of a cover already given;
 *  · month arithmetic is clamped: cover beginning 31 January for one month ends
 *    on the last day of February, not in March (Carbon's plain addMonth would
 *    roll over, and a longer promise than the company made is a real dispute);
 *  · a claim is an event on a cover, never an edit to it — raising one leaves the
 *    dates alone, deciding one closes it with a resolution and a decider, and a
 *    cover that has run out refuses a claim because a goodwill repair is an
 *    expense rather than a warranty;
 *  · the invoice is the delivery event where no challan carried the goods, and
 *    it will not double-cover a line the challan already covered;
 *  · everything the desk writes is in the audit trail, and the screens are behind
 *    their own keys — reading the register is not deciding a claim;
 *  · the §07 warranty leaves and the product's warranty config land on the real
 *    screens rather than on a plan page.
 */
class WarrantyDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Customer $customer;

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

        $district = District::query()->orderBy('id')->firstOrFail();

        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'WC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Warranty Customer',
            'phone' => '01766666666',
            'address_line1' => 'House 4, Road 7, Banani',
            'district_id' => $district->id,
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------- fixtures */

    protected function httpRequest(?User $user = null): Request
    {
        $request = Request::create('/__warranty', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $user ?? $this->admin);

        return $request;
    }

    protected function product(string $code = 'WRT-1'): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => 'Warranty Product '.$code,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        // Goods on the shelf: an order can only be confirmed against stock, and
        // this suite is about what happens after the goods leave.
        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 500, 'unit_cost' => 80]],
            'idempotency_suffix' => 'warranty-open-'.$product->id,
        ], $this->httpRequest());

        return $product;
    }

    protected function policy(Product $product, int $months, array $extra = []): ProductWarranty
    {
        return app(WarrantyService::class)->savePolicy($product, $extra + [
            'months' => $months,
            'kind' => 'manufacturer',
            'covers_parts' => true,
            'covers_labour' => true,
        ], $this->admin);
    }

    /** A confirmed order for one product. */
    protected function order(Product $product, int $qty = 2, int $price = 150): \App\Domain\Sales\SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $price]],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    /** A challan on a confirmed order, dispatched and delivered through the real desks. */
    protected function deliver(\App\Domain\Sales\SalesOrder $order, int $qty): DeliveryChallan
    {
        $challan = app(CreateDeliveryChallan::class)->handle($order, [
            'lines' => [['product_id' => $order->lines->first()->product_id, 'qty' => $qty]],
        ], $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('sales.delivery-challans.dispatch', $challan))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery-challans.deliver', $challan))
            ->assertSessionHasNoErrors();

        return $challan->fresh();
    }

    /* -------------------------------------------------------------- 16-16 */

    public function test_a_delivery_activates_the_cover_the_product_was_promised(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $challan = $this->deliver($this->order($product, 3), 3);

        $warranty = Warranty::query()->where('challan_id', $challan->id)->firstOrFail();

        $this->assertSame('WR-000001', $warranty->code);
        $this->assertSame($product->id, $warranty->product_id);
        $this->assertSame($this->customer->id, $warranty->customer_id);
        $this->assertSame(12, $warranty->months);
        $this->assertSame($challan->delivered_at->toDateString(), $warranty->starts_on->toDateString());
        $this->assertSame(
            $challan->delivered_at->copy()->addYearNoOverflow()->toDateString(),
            $warranty->ends_on->toDateString(),
        );
        $this->assertEqualsWithDelta(3.0, (float) $warranty->qty, 0.0001);
        $this->assertSame(Warranty::STATUS_ACTIVE, $warranty->stateNow());

        // The delivery audit names what it created, so the trail can be joined up.
        $event = AuditEvent::query()->where('action', 'sales.delivery_challan_delivered')->latest('id')->firstOrFail();
        $this->assertContains('WR-000001', $event->after['warranties_activated'] ?? []);

        $activated = AuditEvent::query()->where('action', 'sales.warranty_activated')->firstOrFail();
        $this->assertSame($product->code, $activated->after['product']);
        $this->assertSame($challan->delivered_at->toDateString(), $activated->after['starts_on']);
    }

    public function test_a_product_without_a_policy_is_delivered_with_no_cover_and_no_error(): void
    {
        $product = $this->product('WRT-NOPOL');

        $challan = $this->deliver($this->order($product), 2);

        $this->assertSame(0, Warranty::query()->count());
        $this->assertSame('delivered', $challan->status);

        $event = AuditEvent::query()->where('action', 'sales.delivery_challan_delivered')->latest('id')->firstOrFail();
        $this->assertSame([], $event->after['warranties_activated'] ?? null);
    }

    public function test_a_delivery_marked_twice_cannot_cover_the_same_line_twice(): void
    {
        $product = $this->product();
        $this->policy($product, 24);

        $order = $this->order($product);
        $challan = $this->deliver($order, 2);

        $this->assertSame(1, Warranty::query()->count());
        $first = Warranty::query()->firstOrFail();

        // Walk the row back to `dispatched` the way a mistaken re-run would and
        // deliver it again: the cover is keyed on the delivered line, so the
        // second event writes nothing at all.
        DeliveryChallan::query()->whereKey($challan->id)->update(['status' => 'dispatched']);
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->actingAs($this->admin)
            ->post(route('sales.delivery-challans.deliver', $challan->fresh()))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Warranty::query()->count());
        $this->assertSame($first->id, Warranty::query()->firstOrFail()->id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'sales.warranty_activated')->count());
    }

    public function test_cover_starts_when_the_goods_arrived_and_a_later_policy_edit_cannot_move_it(): void
    {
        $product = $this->product();
        $this->policy($product, 6);

        $challan = $this->deliver($this->order($product), 1);
        $warranty = Warranty::query()->firstOrFail();

        $originalStart = $warranty->starts_on->toDateString();
        $originalEnd = $warranty->ends_on->toDateString();

        // The company changes its mind about the promise — for the future.
        $this->policy($product, 36);

        $warranty->refresh();

        $this->assertSame($originalStart, $warranty->starts_on->toDateString());
        $this->assertSame($originalEnd, $warranty->ends_on->toDateString());
        $this->assertSame(6, $warranty->months, 'A cover already granted keeps the months it was granted with.');

        // The next delivery gets the new promise.
        $second = $this->deliver($this->order($product), 1);
        $this->assertSame(36, Warranty::query()->where('challan_id', $second->id)->firstOrFail()->months);
    }

    public function test_a_month_of_cover_beginning_on_the_31st_ends_on_the_last_day_of_its_month(): void
    {
        $service = app(WarrantyService::class);

        $start = \Carbon\Carbon::parse('2027-01-31');
        $this->assertSame('2027-02-28', $service->addMonths($start, 1)->toDateString());

        // A leap year gets the extra day.
        $this->assertSame('2028-02-29', $service->addMonths(\Carbon\Carbon::parse('2028-01-31'), 1)->toDateString());

        // The ordinary case is untouched.
        $this->assertSame('2027-03-15', $service->addMonths(\Carbon\Carbon::parse('2027-01-15'), 2)->toDateString());

        // And a year from 29 February lands on the last day of February, not March.
        $this->assertSame('2029-02-28', $service->addMonths(\Carbon\Carbon::parse('2028-02-29'), 12)->toDateString());
    }

    public function test_the_state_is_read_from_the_clock_not_only_from_the_stored_flag(): void
    {
        $product = $this->product();
        $this->policy($product, 1);

        $challan = $this->deliver($this->order($product), 1);
        $warranty = Warranty::query()->firstOrFail();

        $this->assertSame(Warranty::STATUS_ACTIVE, $warranty->stateNow());
        $this->assertTrue($warranty->isLive());

        // Move the clock past the end: the row still says `active` on disk, and
        // the row is wrong — the answer to "is this covered" is a comparison.
        $warranty->forceFill(['ends_on' => now()->subDays(3)->toDateString()])->save();

        $this->assertSame(Warranty::STATUS_EXPIRED, $warranty->fresh()->stateNow());
        $this->assertFalse($warranty->fresh()->isLive());
        $this->assertSame(-3, $warranty->fresh()->daysRemaining());
    }

    public function test_applying_a_policy_to_an_invoice_is_refused_when_the_customer_is_already_covered(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $order = $this->order($product, 2);
        $this->deliver($order, 2);

        $this->assertSame(1, Warranty::query()->count());

        // Now invoice the order: the goods already carried a cover from the
        // challan, so issuing the invoice must not promise a second one.
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        $this->assertSame(1, Warranty::query()->count());
        $this->assertNull(Warranty::query()->firstOrFail()->invoice_id);
    }

    public function test_an_invoice_without_a_challan_is_the_delivery_event(): void
    {
        $product = $this->product();
        $this->policy($product, 18);

        $order = $this->order($product, 2);
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        $warranty = Warranty::query()->firstOrFail();

        $this->assertSame($invoice->id, $warranty->invoice_id);
        $this->assertSame('invoice', $warranty->source);
        $this->assertSame($invoice->invoice_date->toDateString(), $warranty->starts_on->toDateString());
        $this->assertSame(18, $warranty->months);
    }

    public function test_registering_cover_by_hand_states_its_own_months_or_refuses(): void
    {
        $service = app(WarrantyService::class);

        $bare = $this->product('WRT-BARE');

        try {
            $service->activateManually($bare, ['starts_on' => now()->toDateString(), 'qty' => 1], $this->admin);
            $this->fail('A product with no policy must not be covered by hand without months.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }

        $this->assertSame(0, Warranty::query()->count());

        // Stating the months is allowed — the desk is writing the promise down.
        $warranty = $service->activateManually($bare, [
            'starts_on' => now()->subDays(10)->toDateString(),
            'months' => 3,
            'qty' => 2,
            'serial_no' => 'SN-9001',
            'notes' => 'Registered from the paper invoice',
        ], $this->admin);

        $this->assertSame('manual', $warranty->source);
        $this->assertSame('SN-9001', $warranty->serial_no);
        $this->assertSame(3, $warranty->months);
        $this->assertSame(now()->subDays(10)->addMonthsNoOverflow(3)->toDateString(), $warranty->ends_on->toDateString());
    }

    /* -------------------------------------------------------------- 16-18 */

    public function test_a_claim_never_edits_the_cover_and_deciding_one_closes_it(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $this->deliver($this->order($product), 1);
        $warranty = Warranty::query()->firstOrFail();

        $startsOn = $warranty->starts_on->toDateString();
        $endsOn = $warranty->ends_on->toDateString();

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.store', $warranty), ['fault' => 'Screen flickers after ten minutes.'])
            ->assertRedirect(route('sales.warranties.claims'))
            ->assertSessionHas('status');

        $claim = WarrantyClaim::query()->firstOrFail();
        $this->assertSame('WC-000001', $claim->code);
        $this->assertSame('open', $claim->status);

        $warranty->refresh();
        $this->assertSame($startsOn, $warranty->starts_on->toDateString());
        $this->assertSame($endsOn, $warranty->ends_on->toDateString());
        $this->assertSame(Warranty::STATUS_ACTIVE, $warranty->status, 'Raising a claim does not touch the cover.');

        // Closing it without saying what was done is refused.
        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.decide', $claim), ['status' => 'completed'])
            ->assertSessionHasErrors('resolution');

        $this->assertSame('open', $claim->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.decide', $claim), [
                'status' => 'completed',
                'resolution' => 'repair',
                'cost' => 450.50,
                'resolution_notes' => 'Replaced the ribbon cable.',
            ])
            ->assertSessionHas('status');

        $claim->refresh();
        $this->assertSame('completed', $claim->status);
        $this->assertSame('repair', $claim->resolution);
        $this->assertEqualsWithDelta(450.50, (float) $claim->cost, 0.0001);
        $this->assertSame($this->admin->id, $claim->resolved_by);
        $this->assertNotNull($claim->resolved_on);

        // Honouring the claim marks the cover as claimed, and the dates still do
        // not move.
        $warranty->refresh();
        $this->assertSame(Warranty::STATUS_CLAIMED, $warranty->stateNow());
        $this->assertSame($startsOn, $warranty->starts_on->toDateString());
        $this->assertSame($endsOn, $warranty->ends_on->toDateString());

        $closed = AuditEvent::query()->where('action', 'sales.warranty_claim_closed')->firstOrFail();
        $this->assertSame('WC-000001', $closed->after['code']);
        $this->assertEqualsWithDelta(450.50, (float) $closed->after['cost'], 0.0001);
    }

    public function test_a_refusal_leaves_the_cover_untouched_and_an_expired_cover_takes_no_claim(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $this->deliver($this->order($product), 1);
        $warranty = Warranty::query()->firstOrFail();

        // Refuse a claim: the cover stays exactly as it was.
        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.store', $warranty), ['fault' => 'Chipped paint on the lid.'])
            ->assertSessionHasNoErrors();

        $claim = WarrantyClaim::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.decide', $claim), [
                'status' => 'rejected',
                'resolution' => 'reject',
                'resolution_notes' => 'Damage is not covered by the manufacturer.',
            ])
            ->assertSessionHas('status');

        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertSame(Warranty::STATUS_ACTIVE, $warranty->fresh()->stateNow());

        // Now run the cover out and try to claim again.
        $warranty->forceFill(['ends_on' => now()->subDay()->toDateString()])->save();

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.store', $warranty->fresh()), ['fault' => 'Motor no longer turns.'])
            ->assertSessionHasErrors('fault');

        $this->assertSame(1, WarrantyClaim::query()->count());

        // A voided cover refuses one too, and says why it was voided.
        $open = $this->deliver($this->order($product), 1);
        $second = Warranty::query()->where('challan_id', $open->id)->firstOrFail();

        app(WarrantyService::class)->void($second, 'Goods returned for credit under invoice INV-77.', $this->admin);

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.store', $second), ['fault' => 'Battery swells when charging.'])
            ->assertSessionHasErrors('fault');

        $this->assertSame(1, WarrantyClaim::query()->count());
    }

    public function test_a_closed_claim_is_a_record_and_a_void_needs_a_reason(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $this->deliver($this->order($product), 1);
        $warranty = Warranty::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.store', $warranty), ['fault' => 'Fan rattles at high speed.'])
            ->assertSessionHasNoErrors();

        $claim = WarrantyClaim::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.decide', $claim), ['status' => 'completed', 'resolution' => 'replace'])
            ->assertSessionHas('status');

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.claims.decide', $claim), ['status' => 'processing'])
            ->assertSessionHasErrors('status');

        $this->assertSame('completed', $claim->fresh()->status);

        // Voiding needs a reason and keeps it.
        $this->actingAs($this->admin)
            ->post(route('sales.warranties.void', $warranty), ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->actingAs($this->admin)
            ->post(route('sales.warranties.void', $warranty), ['void_reason' => 'Serial recorded against the wrong unit.'])
            ->assertSessionHas('status');

        $warranty->refresh();
        $this->assertSame(Warranty::STATUS_VOIDED, $warranty->stateNow());
        $this->assertSame('Serial recorded against the wrong unit.', $warranty->void_reason);

        $voided = AuditEvent::query()->where('action', 'sales.warranty_voided')->firstOrFail();
        $this->assertSame('Serial recorded against the wrong unit.', $voided->reason);
    }

    /* --------------------------------------------------------------- desks */

    public function test_the_register_policies_and_claims_screens_render_their_real_rows(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $this->deliver($this->order($product), 2);

        $warranty = Warranty::query()->firstOrFail();
        $warranty->forceFill(['serial_no' => 'SN-4242'])->save();

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.index'))
            ->assertOk()
            ->assertSeeText('WR-000001')
            ->assertSeeText('Warranty Customer')
            ->assertSeeText('In cover');

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.policies'))
            ->assertOk()
            ->assertSeeText('Warranty Product WRT-1')
            ->assertSeeText('12 month(s)')
            ->assertSeeText('Manufacturer warranty');

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.show', $warranty))
            ->assertOk()
            ->assertSeeText('WR-000001')
            ->assertSeeText('SN-4242')
            ->assertSeeText('Parts and labour');

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.index', ['state' => 'expired']))
            ->assertOk()
            ->assertDontSeeText('WR-000001');

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.claims'))
            ->assertOk();

        // An unauthenticated person sees the login door, not the register.
        auth()->logout();
        $this->get(route('sales.warranties.index'))->assertRedirect();
    }

    public function test_reading_the_register_and_deciding_a_claim_are_different_keys(): void
    {
        $product = $this->product();
        $this->policy($product, 12);

        $this->deliver($this->order($product), 1);
        $warranty = Warranty::query()->firstOrFail();

        $reader = $this->makeUser(['branch_scope' => 'all']);
        $this->grant($reader, ['sales.warranties.view']);

        $this->actingAs($reader)->get(route('sales.warranties.index'))->assertOk();
        $this->actingAs($reader)->get(route('sales.warranties.show', $warranty))->assertOk();
        $this->actingAs($reader)->get(route('sales.warranties.policies'))->assertOk();

        // Reading is not deciding.
        $this->actingAs($reader)
            ->post(route('sales.warranties.claims.store', $warranty), ['fault' => 'Speakers crackle.'])
            ->assertForbidden();

        $this->actingAs($reader)
            ->post(route('sales.warranties.void', $warranty), ['void_reason' => 'No reason at all.'])
            ->assertForbidden();

        $this->actingAs($reader)
            ->post(route('sales.warranties.policies.store'), ['product_id' => $product->id, 'months' => 60, 'kind' => 'seller'])
            ->assertForbidden();

        $this->assertSame(0, WarrantyClaim::query()->count());
        $this->assertSame(12, $product->warrantyPolicy->fresh()->months);

        // A person with neither key is stopped at the door.
        $stranger = $this->makeUser();
        $this->actingAs($stranger)->get(route('sales.warranties.index'))->assertForbidden();
    }

    public function test_another_companys_cover_is_not_readable_by_id(): void
    {
        $product = $this->product();
        $this->policy($product, 12);
        $this->deliver($this->order($product), 1);

        $warranty = Warranty::query()->firstOrFail();

        // A second company, with a cover of its own: the row exists, and it still
        // does not exist for this one.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = Warranty::query()->create([
            'company_id' => $otherCompany,
            'code' => 'WR-999999',
            'product_id' => $product->id,
            'source' => 'manual',
            'source_line_id' => 0,
            'source_line_key' => 'manual:other-company',
            'months' => 12,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'status' => Warranty::STATUS_ACTIVE,
            'qty' => 1,
        ]);

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.show', $foreign))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('sales.warranties.index'))
            ->assertOk()
            ->assertDontSeeText('WR-999999');

        $this->actingAs($this->admin)->get(route('sales.warranties.show', $warranty))->assertOk();
    }

    /* ------------------------------------------------------------ catalogue */

    public function test_the_warranty_leaves_land_on_the_real_desk(): void
    {
        app(\App\Domain\Foundation\Services\CatalogImporter::class)->sync();

        $leaves = ['Warranty Claims', 'Warranty Returns', 'Warranty Processing', 'Warranty Completed', 'Product Warranty Config'];

        foreach ($leaves as $label) {
            $item = MenuItem::query()->where('label', $label)->first();

            $this->assertNotNull($item, "The catalogue is missing the {$label} leaf.");
            $this->assertTrue((bool) $item->is_active, "{$label} should be an active menu row.");
            $this->assertStringContainsString('/app/sales/warranties', (string) $item->route, "{$label} should open the warranty desk.");
        }

        $returns = MenuItem::query()->where('label', 'Warranty Returns')->firstOrFail();
        $this->assertStringContainsString('state=claimed', (string) $returns->route);

        $config = MenuItem::query()->where('label', 'Product Warranty Config')->firstOrFail();
        $this->assertStringContainsString('/policies', (string) $config->route);
    }
}
