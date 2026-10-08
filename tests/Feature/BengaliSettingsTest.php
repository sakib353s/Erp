<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentRenderer;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\Translator;
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
use App\Domain\Sales\SalesOrder;
use App\Domain\Settings\Services\LocalizationService;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §15-07 — the Bengali/localization switches, made to mean something.
 *
 * Four switches and a language choice are only real if the documents obey
 * them, so this suite tests the documents: an issued invoice is rendered
 * through the real `DocumentRenderer` and its stored HTML is read back. The
 * country's rules are the interesting part — lakh-wise grouping rather than
 * thousands, বাংলা numerals that change the glyphs and not the figures, and an
 * amount in words taken from the number the totals were computed from.
 *
 * The last test is the reason the switches live in the settings table rather
 * than in config: a branch may print a Bangla slip while head office prints
 * western ones, because `effective()` resolves branch → company → default.
 */
class BengaliSettingsTest extends TestCase
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
            'code' => 'BN-1',
            'sku' => 'BN-SKU-1',
            'name' => 'Localization Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 80]],
            'idempotency_suffix' => 'bn-open-'.uniqid(),
        ], $this->httpRequest());
    }

    // ---------------------------------------------------------------- helpers

    protected function httpRequest(): Request
    {
        $request = Request::create('/__localization', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function settings(): SettingService
    {
        return app(SettingService::class);
    }

    /** A fresh read of the switches: values are memoised per instance. */
    protected function localization(): LocalizationService
    {
        return app(LocalizationService::class);
    }

    /** @return array{0: SalesOrder, 1: Invoice} */
    protected function issuedInvoice(int $qty = 3, int $unitPrice = 150): array
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => $unitPrice]],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());

        return [$order->fresh(), app(IssueInvoice::class)->handle($invoice, $this->httpRequest())];
    }

    /** The HTML of a real printed invoice, read back from where it was filed. */
    protected function printedInvoice(Invoice $invoice): string
    {
        $document = app(DocumentRenderer::class)->renderInvoice($invoice, $this->admin);

        return (string) Storage::disk('local')->get($document->path);
    }

    // ------------------------------------------------------ numbers & digits

    public function test_figures_are_grouped_lakh_wise_and_can_be_written_in_bangla_numerals(): void
    {
        // The default: lakh/crore grouping, western digits.
        $this->assertTrue($this->localization()->lakhCrore());
        $this->assertFalse($this->localization()->bengaliFigures());
        $this->assertSame('12,34,567.50', $this->localization()->number(1234567.5));

        $this->settings()->set('localization', 'bengali_numerals', true, null, $this->admin);

        $bengali = $this->localization();
        $this->assertTrue($bengali->bengaliFigures());
        $this->assertSame('১২,৩৪,৫৬৭.৫০', $bengali->number(1234567.5));

        // The figures do not change, only the glyphs — so the separators stay
        // in the lakh-wise places they were in before the switch was turned on.
        $this->assertStringContainsString(',৩৪,', $bengali->number(1234567.5));

        $this->settings()->set('localization', 'lakh_crore_format', false, null, $this->admin);

        // Western grouping is available for a company that trades that way.
        $this->assertSame('১,২৩৪,৫৬৭.৫০', $this->localization()->number(1234567.5));
    }

    public function test_quantities_follow_the_same_script_without_grouping(): void
    {
        $this->assertSame('3', $this->localization()->qty(3.0));
        $this->assertSame('12.5', $this->localization()->qty(12.5));

        $this->settings()->set('localization', 'bengali_numerals', true, null, $this->admin);

        $this->assertSame('৩', $this->localization()->qty(3.0));
        $this->assertSame('১২.৫', $this->localization()->qty(12.5));
    }

    public function test_amounts_are_spelled_out_in_the_language_the_company_chose(): void
    {
        $this->assertStringStartsWith('Taka', $this->localization()->words(450000));
        $this->assertStringContainsString('lakh', $this->localization()->words(450000));

        $this->assertStringStartsWith('টাকা', $this->localization()->words(450000, 'bn'));
        $this->assertStringContainsString('লাখ', $this->localization()->words(450000, 'bn'));
    }

    // ------------------------------------------------------------- documents

    public function test_a_printed_invoice_obeys_the_localization_switches(): void
    {
        [, $invoice] = $this->issuedInvoice(3, 150); // 450.00, no tax on this fixture

        $western = $this->printedInvoice($invoice);

        $this->assertStringContainsString('450.00', $western);
        $this->assertStringContainsString('In words: Taka four hundred and fifty only', $western);

        $this->settings()->set('localization', 'bengali_numerals', true, null, $this->admin);

        $bengali = $this->printedInvoice($invoice->fresh());

        $this->assertStringContainsString('৪৫০.০০', $bengali);
        $this->assertStringNotContainsString('450.00', $bengali);

        // Words can be turned off without touching the figures.
        $this->settings()->set('localization', 'amount_words_bn', false, null, $this->admin);

        $withoutWords = $this->printedInvoice($invoice->fresh());

        $this->assertStringNotContainsString('In words', $withoutWords);
        $this->assertStringContainsString('৪৫০.০০', $withoutWords);
    }

    public function test_a_branch_may_print_its_own_script(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'BN2',
            'name' => 'Narayanganj outlet',
            'is_default' => false,
            'is_active' => true,
        ]);

        [, $invoice] = $this->issuedInvoice(3, 150);

        // Company: western digits. This branch overrides it to Bangla.
        $this->settings()->set('localization', 'bengali_numerals', true, $other->id, $this->admin);

        $this->bindTenantContext($this->admin, $other);
        $branchPrint = $this->printedInvoice($invoice->fresh());

        $this->bindTenantContext($this->admin, $this->defaultBranch());
        $companyPrint = $this->printedInvoice($invoice->fresh());

        $this->assertStringContainsString('৪৫০.০০', $branchPrint);
        $this->assertStringContainsString('450.00', $companyPrint);

        // The company row was never touched by the branch's choice.
        $this->assertSame('450.00', $this->localization()->number(450));
    }

    // ---------------------------------------------------------------- language

    public function test_the_default_language_setting_decides_the_interface_locale(): void
    {
        $this->assertSame('en', app(Translator::class)->locale());

        $this->settings()->set('localization', 'default_locale', 'bn', null, $this->admin);

        // The translator memoises per request; a settings write clears it, which
        // is what makes the switch take effect on the next render.
        app(Translator::class)->flushLocale();
        $this->assertSame('bn', app(Translator::class)->locale());
    }

    public function test_the_readers_own_language_choice_still_wins_over_the_default(): void
    {
        $this->settings()->set('localization', 'default_locale', 'bn', null, $this->admin);
        app(Translator::class)->flushLocale();

        $this->assertSame('bn', app(Translator::class)->locale());

        session(['locale' => 'en']);

        $this->assertSame('en', app(Translator::class)->locale());
    }

    public function test_saving_the_localization_group_through_the_screen_takes_effect_immediately(): void
    {
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->actingAs($this->admin)
            ->post(route('settings.update', 'localization'), [
                'settings' => ['default_locale' => 'bn', 'bengali_numerals' => '1'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The write flushed the memoised values, so the very next read is Bangla.
        $this->assertSame('bn', app(Translator::class)->locale());
        $this->assertSame('৪৫০.০০', $this->localization()->number(450));
    }
}
