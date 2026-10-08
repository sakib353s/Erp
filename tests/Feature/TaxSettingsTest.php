<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentRenderer;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
use App\Domain\Masters\TaxRate;
use App\Domain\Sales\Services\TotalsCalculator;
use App\Domain\Settings\Services\SettingService;
use App\Domain\Tax\Services\TaxPolicy;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §15-14 — the VAT settings, tested where they change money.
 *
 * A settings screen is only worth its rows if the engine obeys it, so this
 * suite drives `TotalsCalculator` — the one class that prices an order — and
 * reads the figures back. What is pinned, in the order a shop would notice:
 *
 *  · the shipped defaults compute exactly what this engine has always
 *    computed: exclusive tax (added on top), rounded once on the document,
 *    no tax at all on a sale that names no rate;
 *  · a default tax code fills the gap for an untagged sale but never
 *    overrides a line that names its own rate;
 *  · inclusive pricing takes the tax *out of* the price the customer sees
 *    rather than adding it: ৳115 at 15% is ৳100 taxable + ৳15 VAT, and the
 *    invoice's taxable value agrees with its own tax line;
 *  · the rounding policy is visible in the stored document — per-line or
 *    once — and the nearest-taka switch writes its difference into the
 *    invoice's rounding column instead of hiding it;
 *  · the branch's own way of pricing beats the company's, and the company's
 *    stays untouched for the other branches;
 *  · a default code that names no rate is refused at the write with the
 *    reason, audited as an invariant denial, and a rate belonging to another
 *    company never prices this one's invoice.
 */
class TaxSettingsTest extends TestCase
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
            'code' => 'TAX-1',
            'sku' => 'TAX-SKU-1',
            'name' => 'Tax Policy Product',
            'cost_method' => 'fifo',
            'standard_cost' => 80,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 60]],
            'idempotency_suffix' => 'tax-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__tax-settings', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** One issued, taxed invoice at the given price — priced by the real engine. */
    protected function taxedInvoice(float $unitPrice, string $code = 'VAT15'): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'tax_applicable' => true,
            'tax_code' => $code,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => $unitPrice],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [
            'tax_applicable' => true,
            'tax_code' => $code,
        ], $this->httpRequest());

        return app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
    }

    /* --------------------------------------------------------------- helpers */

    protected function settings(): SettingService
    {
        return app(SettingService::class);
    }

    protected function policy(): TaxPolicy
    {
        return app(TaxPolicy::class);
    }

    protected function rate(string $code, float $rate, ?int $companyId = null, bool $active = true): TaxRate
    {
        return TaxRate::create([
            'company_id' => $companyId ?? $this->admin->company_id,
            'code' => $code,
            'name' => $code.' '.$rate.'%',
            'tax_type' => 'vat',
            'rate' => $rate,
            'effective_from' => now()->subDay(),
            'is_active' => $active,
        ]);
    }

    /**
     * One order, priced by the real engine.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    protected function totals(array $lines, float $docDiscount = 0.0, float $shipping = 0.0, ?string $taxCode = null): array
    {
        return app(TotalsCalculator::class)->calculate(
            $lines,
            $docDiscount,
            $shipping,
            $taxCode,
            null,
            true,
        );
    }

    /* -------------------------------------------------------------- defaults */

    public function test_the_shipped_defaults_compute_what_this_engine_has_always_computed(): void
    {
        $this->rate('VAT15', 15);

        $totals = $this->totals([
            ['qty' => 10, 'unit_price' => 150, 'tax_code' => 'VAT15'],
        ]);

        $this->assertSame(1500.0, $totals['taxable_base']);
        $this->assertSame(225.0, $totals['tax']);
        $this->assertSame(1725.0, $totals['grand_total']);
        $this->assertSame(0.0, $totals['rounding']);
        $this->assertSame(225.0, $totals['lines'][0]['tax']);

        // Nothing about the switch positions was changed by this slice.
        $this->assertFalse($this->policy()->pricesIncludeTax());
        $this->assertSame(TaxPolicy::ROUNDING_DOCUMENT, $this->policy()->roundingMode());
        $this->assertFalse($this->policy()->roundsToNearestTaka());
        $this->assertNull($this->policy()->defaultCode());
    }

    public function test_a_sale_that_names_no_rate_carries_no_tax_until_a_default_is_chosen(): void
    {
        $this->rate('VAT15', 15);

        $untagged = $this->totals([['qty' => 10, 'unit_price' => 150]]);

        $this->assertSame(0.0, $untagged['tax']);
        $this->assertSame(1500.0, $untagged['grand_total']);

        $this->settings()->set('tax', 'default_code', 'VAT15', null, $this->admin);

        $tagged = $this->totals([['qty' => 10, 'unit_price' => 150]]);

        $this->assertSame(225.0, $tagged['tax']);
        $this->assertSame(1725.0, $tagged['grand_total']);
    }

    public function test_a_line_that_names_its_own_rate_still_wins_over_the_default(): void
    {
        $this->rate('VAT15', 15);
        $this->rate('VAT5', 5);

        $this->settings()->set('tax', 'default_code', 'VAT15', null, $this->admin);

        $totals = $this->totals([['qty' => 10, 'unit_price' => 150, 'tax_code' => 'VAT5']]);

        $this->assertSame(75.0, $totals['tax']);
    }

    /* -------------------------------------------------------------- inclusive */

    public function test_inclusive_pricing_takes_the_tax_out_of_the_price_instead_of_adding_it(): void
    {
        $this->rate('VAT15', 15);

        $this->settings()->set('tax', 'prices_include_tax', true, null, $this->admin);

        // The shelf says ৳115 and the customer pays ৳115 — the 15% is inside it.
        $totals = $this->totals([['qty' => 1, 'unit_price' => 115, 'tax_code' => 'VAT15']]);

        $this->assertEqualsWithDelta(100.0, $totals['lines'][0]['taxable'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $totals['lines'][0]['tax'], 0.0001);
        $this->assertEqualsWithDelta(115.0, $totals['lines'][0]['line_total'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $totals['taxable_base'], 0.0001);
        $this->assertEqualsWithDelta(115.0, $totals['grand_total'], 0.0001);

        // And the two figures always add up to what is being asked for.
        $this->assertEqualsWithDelta(
            $totals['grand_total'],
            $totals['taxable_base'] + $totals['tax'] + $totals['shipping'] - $totals['rounding'],
            0.0001,
        );
    }

    public function test_the_exclusive_default_is_unchanged_by_the_inclusive_switch_being_off(): void
    {
        $this->rate('VAT15', 15);

        $exclusive = $this->totals([['qty' => 1, 'unit_price' => 115, 'tax_code' => 'VAT15']]);

        $this->assertEqualsWithDelta(115.0, $exclusive['taxable_base'], 0.0001);
        $this->assertEqualsWithDelta(17.25, $exclusive['tax'], 0.0001);
        $this->assertEqualsWithDelta(132.25, $exclusive['grand_total'], 0.0001);
    }

    /* --------------------------------------------------------------- rounding */

    public function test_the_rounding_mode_decides_where_the_paisa_lands(): void
    {
        $this->rate('VAT15', 15);

        $lines = [
            ['qty' => 1, 'unit_price' => 1.05, 'tax_code' => 'VAT15'],
            ['qty' => 1, 'unit_price' => 1.05, 'tax_code' => 'VAT15'],
            ['qty' => 1, 'unit_price' => 1.05, 'tax_code' => 'VAT15'],
        ];

        $document = $this->totals($lines);

        // Three lines of 0.1575: summed as they are, the document's tax is 0.4725.
        $this->assertEqualsWithDelta(0.4725, $document['tax'], 0.000001);
        $this->assertEqualsWithDelta(0.1575, $document['lines'][0]['tax'], 0.000001);

        $this->settings()->set('tax', 'rounding_mode', TaxPolicy::ROUNDING_LINE, null, $this->admin);

        $perLine = $this->totals($lines);

        // Rounded on every line, the same three lines carry 0.16 each.
        $this->assertEqualsWithDelta(0.16, $perLine['lines'][0]['tax'], 0.000001);
        $this->assertEqualsWithDelta(0.48, $perLine['tax'], 0.000001);

        // The stored document adds up either way: lines + tax + shipping − rounding.
        foreach ([$document, $perLine] as $totals) {
            $sum = 0.0;
            foreach ($totals['lines'] as $line) {
                $sum = round($sum + $line['line_total'], 4);
            }

            $this->assertEqualsWithDelta($totals['grand_total'], $sum + $totals['shipping'] + $totals['rounding'], 0.0001);
        }
    }

    public function test_the_nearest_taka_is_written_down_rather_than_hidden(): void
    {
        // An untaxed line, so the rounding column has only the taka adjustment
        // in it and the test is about that one thing.
        $before = $this->totals([['qty' => 1, 'unit_price' => 1234.60]]);

        $this->assertSame(1234.60, $before['grand_total']);
        $this->assertSame(0.0, $before['rounding']);

        $this->settings()->set('tax', 'round_to_nearest_taka', true, null, $this->admin);

        $after = $this->totals([['qty' => 1, 'unit_price' => 1234.60]]);

        $this->assertSame(1235.0, $after['grand_total']);
        $this->assertEqualsWithDelta(0.40, $after['rounding'], 0.0001);

        // The adjustment is the difference between the rounded total and the
        // exact one, so the invoice still reconciles to its own parts.
        $this->assertEqualsWithDelta(
            $after['grand_total'],
            $after['taxable_base'] + $after['tax'] + $after['shipping'] + $after['rounding'],
            0.0001,
        );
    }

    /* ----------------------------------------------------------- branch scope */

    public function test_a_branch_may_price_the_way_it_sells_while_the_company_stays_exclusive(): void
    {
        $this->rate('VAT15', 15);

        $outlet = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'OUT1',
            'name' => 'Counter outlet',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->settings()->set('tax', 'prices_include_tax', true, $outlet->id, $this->admin);

        $this->bindTenantContext($this->admin, $outlet);
        $inclusive = $this->totals([['qty' => 1, 'unit_price' => 115, 'tax_code' => 'VAT15']]);

        $this->bindTenantContext($this->admin, $this->defaultBranch());
        $exclusive = $this->totals([['qty' => 1, 'unit_price' => 115, 'tax_code' => 'VAT15']]);

        $this->assertEqualsWithDelta(100.0, $inclusive['taxable_base'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $inclusive['tax'], 0.0001);

        // The company row was never touched by the outlet's choice.
        $this->assertEqualsWithDelta(115.0, $exclusive['taxable_base'], 0.0001);
        $this->assertEqualsWithDelta(17.25, $exclusive['tax'], 0.0001);
    }

    /* -------------------------------------------------------------- the write */

    public function test_a_default_code_that_names_no_rate_is_refused_with_its_reason(): void
    {
        $this->rate('VAT15', 15);

        try {
            $this->settings()->set('tax', 'default_code', 'VAT-99', null, $this->admin);

            $this->fail('an unknown default tax code must be refused');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString(
                'names no active rate',
                (string) $refused->validator->errors()->first('settings.default_code'),
            );
        }

        // Nothing was stored, and the attempt is visible in the chain.
        $this->assertSame('', (string) $this->settings()->get('tax', 'default_code'));
        $this->assertSame(
            1,
            DB::table('audit_events')
                ->where('action', 'config.invariant_denied')
                ->where('reason', 'like', '%default tax code%')
                ->count(),
        );

        // A real code is stored, with its history row.
        $this->assertTrue($this->settings()->set('tax', 'default_code', 'VAT15', null, $this->admin));
        $this->assertSame('VAT15', $this->settings()->get('tax', 'default_code'));
        $this->assertSame('VAT15', $this->policy()->defaultCode());
    }

    public function test_an_inactive_rate_is_not_a_default_a_sale_may_use(): void
    {
        $this->rate('OLD15', 15, null, false);

        $this->expectException(ValidationException::class);

        $this->settings()->set('tax', 'default_code', 'OLD15', null, $this->admin);
    }

    public function test_a_rate_belonging_to_another_company_never_prices_this_one(): void
    {
        $this->rate('VAT15', 15);

        // A second tenant's rate with the same code, at a rate nobody agreed to.
        $otherCompanyId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Other Company',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'locale' => 'en',
            'fiscal_year_start_month' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rate('VAT15', 99, $otherCompanyId);

        $totals = $this->totals([['qty' => 1, 'unit_price' => 100, 'tax_code' => 'VAT15']]);

        $this->assertSame(15.0, $totals['tax']);
        $this->assertSame(115.0, $totals['grand_total']);
    }

    /* -------------------------------------------------------------- the screen */

    public function test_the_tax_group_needs_its_own_key_and_a_write_needs_settings_update(): void
    {
        // A settings reader without tax.manage may not open the tax group.
        $reader = $this->makeUser();
        $reader->roles()->attach($this->roleWith(['settings.view'])->id);

        $this->actingAs($reader)->get(route('settings.show', 'tax'))->assertForbidden();

        $taxReader = $this->makeUser();
        $taxReader->roles()->attach($this->roleWith(['settings.view', 'tax.manage'])->id);

        $this->actingAs($taxReader)
            ->get(route('settings.show', 'tax'))
            ->assertOk()
            ->assertSee('My prices already include VAT')
            ->assertSee('Mushak 9.1 form revision');

        // Reading the group is not writing it: settings.update is the door.
        $this->actingAs($taxReader)
            ->post(route('settings.update', 'tax'), ['settings' => ['round_to_nearest_taka' => '1']])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('settings.update', 'tax'), ['settings' => ['round_to_nearest_taka' => '1']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->policy()->roundsToNearestTaka());
    }

    /* ------------------------------------------------------------ the document */

    public function test_an_inclusive_price_prints_a_document_whose_figures_add_up(): void
    {
        $this->rate('VAT15', 15);

        $this->settings()->set('tax', 'prices_include_tax', true, null, $this->admin);

        $invoice = $this->taxedInvoice(115.0);

        // The stored document: ৳100 taxable + ৳15 VAT = the ৳115 on the shelf.
        $this->assertEqualsWithDelta(100.0, (float) $invoice->taxable_base, 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $invoice->tax, 0.0001);
        $this->assertEqualsWithDelta(115.0, (float) $invoice->grand_total, 0.0001);
        $this->assertEqualsWithDelta(
            (float) $invoice->grand_total,
            (float) $invoice->taxable_base + (float) $invoice->tax + (float) $invoice->shipping + (float) $invoice->rounding,
            0.0001,
        );

        $document = app(DocumentRenderer::class)->renderInvoice($invoice, $this->admin);
        $html = (string) Storage::disk('local')->get($document->path);

        // And the paper says the same thing rather than showing a subtotal the
        // VAT is added to on top of the total the customer is asked for.
        $this->assertStringContainsString('Taxable value', $html);
        $this->assertStringContainsString('VAT (included in the prices)', $html);
        $this->assertStringNotContainsString('Subtotal', $html);
    }

    public function test_the_same_price_without_the_switch_prints_an_exclusive_invoice(): void
    {
        $this->rate('VAT15', 15);

        $invoice = $this->taxedInvoice(115.0);

        $this->assertEqualsWithDelta(115.0, (float) $invoice->taxable_base, 0.0001);
        $this->assertEqualsWithDelta(17.25, (float) $invoice->tax, 0.0001);
        $this->assertEqualsWithDelta(132.25, (float) $invoice->grand_total, 0.0001);

        $document = app(DocumentRenderer::class)->renderInvoice($invoice, $this->admin);
        $html = (string) Storage::disk('local')->get($document->path);

        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringNotContainsString('VAT (included in the prices)', $html);
    }

    public function test_the_desk_lists_the_tax_group_with_its_own_key_and_count(): void
    {
        $this->rate('VAT15', 15);
        $this->settings()->set('tax', 'rounding_mode', TaxPolicy::ROUNDING_LINE, null, $this->admin);

        $response = $this->actingAs($this->admin)->get(route('settings.index'))->assertOk();

        $response->assertSee('VAT & Tax Settings');
        $response->assertSee('tax.manage');

        $this->assertSame(1, DB::table('settings')->where('setting_group', 'tax')->count());
    }
}
