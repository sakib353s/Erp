<?php

namespace Tests\Feature;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\Support\DocumentTypeRegistry;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\BarcodeService;
use App\Domain\Inventory\Services\LabelService;
use App\Domain\Inventory\Services\QrService;
use App\Domain\Inventory\StockBatch;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Services\PricingService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-13/04-52/04-53/04-54 — barcodes, QR codes, the label desk and the scanner.
 *
 * What this pins, in the order the complaints would arrive:
 *  · a label carries the thing's *own* code, so the same box prints the same bars
 *    next year and a scanner reads back something the search box resolves;
 *  · a filed sheet is the bytes that were printed — served from the file, not
 *    re-rendered, because a checksum over a re-render is a checksum of nothing;
 *  · ticking a row that is not this company's is a refusal, not a label;
 *  · the paper geometry is real: a sheet of 24 labels fills an A4 page exactly,
 *    and a code too long for the label says so *before* five hundred are printed;
 *  · the scanner bench resolves barcode, SKU and batch number, and says plainly
 *    when a code means nothing.
 */
class LabelDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Product $box;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->box = app(CreateProduct::class)->handle([
            'code' => 'PKD-BOX',
            'sku' => 'PKD-BOX-12',
            'name' => 'Corrugated box, 12 inch',
            'barcode' => '8801234567890',
            'cost_method' => 'fifo',
            'standard_cost' => 12,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());
    }

    protected function request(): Request
    {
        $request = Request::create('/__labels', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function warehouseId(): int
    {
        return (int) DB::table('warehouses')->where('company_id', $this->admin->company_id)->value('id');
    }

    /** Receive stock in a lot, the way a purchase receipt would. */
    protected function receive(Product $product, float $qty, ?string $batchNo, ?string $expiresOn = null): StockMovement
    {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouseId(),
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => $qty,
            'unit_cost' => 12,
            'batch_no' => $batchNo,
            'expires_on' => $expiresOn,
            'idempotency_key' => uniqid('label-recv-', true),
        ], $this->admin);
    }

    /** The label-sheet document filed last, with its file read back from disk. */
    protected function lastSheet(): array
    {
        $typeId = DocumentType::query()->where('code', 'label_sheet')->value('id');

        $document = Document::query()
            ->where('document_type_id', $typeId)
            ->orderByDesc('id')
            ->firstOrFail();

        return [$document, (string) Storage::disk($document->disk)->get($document->path)];
    }

    /* -------------------------------------------------------------- the desk --- */

    public function test_the_desk_lists_what_can_be_labelled_and_needs_the_label_permission(): void
    {
        $this->actingAs($this->admin)
            ->get(route('inventory.labels.index'))
            ->assertOk()
            ->assertSee('Corrugated box, 12 inch')
            // What the label would carry, and where it comes from — the barcode.
            ->assertSee('8801234567890')
            ->assertSee('Sheet');

        // A role without the key cannot reach the desk at all.
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['inventory.products.view'])->id);

        $this->actingAs($user)
            ->get(route('inventory.labels.index'))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------ the sheet --- */

    public function test_a_sheet_is_filed_as_the_exact_bytes_that_will_be_printed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), [
                'subject_type' => 'product',
                'product_ids' => [$this->box->id],
                'template' => 'a4_3x8',
                'copies' => 3,
                'show_price' => '1',
                'show_company' => '1',
                'show_code_text' => '1',
                'qr' => '0',
            ])
            ->assertRedirect(route('inventory.labels.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status')
            ->assertSessionHas('label_sheet_id');

        [$document, $content] = $this->lastSheet();

        $this->assertSame('generated', $document->purpose);
        $this->assertSame('local', $document->disk);
        $this->assertSame(strlen($content), (int) $document->size_bytes);
        $this->assertSame(hash('sha256', $content), $document->checksum, 'the checksum is of the stored bytes');

        // Three copies of one product: three labels on one page.
        $this->assertStringContainsString('3 label(s)', $content);
        $this->assertStringContainsString('8801234567890', $content);
        $this->assertStringContainsString('Corrugated box, 12 inch', $content);

        // The bars are drawn with the standard's own quiet zone, and the sheet
        // gives them a box in millimetres — that is what the fit check measured.
        $this->assertStringContainsString('preserveAspectRatio="none"', $content);

        // The desk is told; the paper is not printed with the warning on it.
        $this->assertStringNotContainsString('no room for a readable barcode', $content);

        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.label_sheet_generated']);
        $this->assertDatabaseHas('print_history', ['printable_id' => $document->id, 'format' => 'html']);
    }

    public function test_the_filed_sheet_is_served_from_the_file_not_re_rendered(): void
    {
        $this->actingAs($this->admin)->post(route('inventory.labels.generate'), [
            'subject_type' => 'product',
            'product_ids' => [$this->box->id],
        ])->assertSessionHasNoErrors();

        [$document, $content] = $this->lastSheet();

        $response = $this->actingAs($this->admin)
            ->get(route('inventory.labels.sheet', ['labelSheet' => $document->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->assertSame($content, $response->getContent(), 'what is sent is the file that was stored');
        $this->assertSame($document->checksum, $response->headers->get('X-Label-Sheet-Checksum'));

        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.label_sheet_viewed']);

        // A second print is logged with its own copy count (Rule 16).
        $this->actingAs($this->admin)
            ->post(route('inventory.labels.print', ['labelSheet' => $document->id]), ['copies' => 2])
            ->assertRedirect(route('inventory.labels.index'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2 copies'));

        $this->assertDatabaseHas('print_history', [
            'printable_id' => $document->id,
            'copies' => 2,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_a_label_sheet_carries_no_number_of_its_own(): void
    {
        $type = DocumentTypeRegistry::map()['label_sheet'];

        $this->assertFalse($type['requires_numbering'], 'nothing downstream refers to "label sheet 12"');
        $this->assertSame('inventory', $type['type_group']);
        $this->assertDatabaseMissing('numbering_rules', ['document_type_id' => DocumentType::query()->where('code', 'label_sheet')->value('id')]);
    }

    public function test_a_row_from_another_company_cannot_be_labelled(): void
    {
        $otherCompanyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Other Traders',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = Product::query()->create([
            'company_id' => $otherCompanyId,
            'code' => 'THEIR-BOX',
            'sku' => 'THEIR-BOX',
            'name' => 'Their box',
            'cost_method' => 'wac',
            'standard_cost' => 1,
            'is_stocked' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), [
                'subject_type' => 'product',
                'product_ids' => [$foreign->id],
            ])
            ->assertSessionHasErrors('product_ids');

        $this->assertSame(0, Document::query()->count(), 'nothing was filed for a stranger\'s row');

        // Their sheet is not ours to read, either.
        $this->actingAs($this->admin)
            ->get(route('inventory.labels.sheet', ['labelSheet' => 999999]))
            ->assertNotFound();
    }

    public function test_ticking_nothing_or_too_much_is_refused_with_a_sentence_about_the_right_table(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), ['subject_type' => 'product'])
            ->assertSessionHasErrors('product_ids');

        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), ['subject_type' => 'batch'])
            ->assertSessionHasErrors('batch_ids');

        // Three rows × 200 copies is 600 labels, over the run's ceiling of 500 —
        // refused in numbers ("3 row(s) × 200 copies is 600 labels") rather than
        // as "invalid selection".
        $extra = [];

        foreach (['PKD-BAG', 'PKD-TAPE'] as $code) {
            $extra[] = app(CreateProduct::class)->handle([
                'code' => $code,
                'sku' => $code,
                'name' => $code.' thing',
                'cost_method' => 'wac',
                'standard_cost' => 1,
                'is_stocked' => true,
                'is_active' => true,
            ], $this->request())->id;
        }

        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), [
                'subject_type' => 'product',
                'product_ids' => array_merge([$this->box->id], $extra),
                'copies' => 200,
            ])
            ->assertSessionHasErrors('product_ids');

        $this->assertSame(0, Document::query()->count());

        // And one row over the per-label ceiling is a plain field error.
        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), [
                'subject_type' => 'product',
                'product_ids' => [$this->box->id],
                'copies' => LabelService::MAX_COPIES + 1,
            ])
            ->assertSessionHasErrors('copies');
    }

    public function test_a_batch_label_carries_the_lot_and_its_expiry(): void
    {
        $this->box->forceFill(['track_batch' => true])->save();
        $this->receive($this->box->fresh(), 10, 'LOT-A', now()->addMonths(6)->toDateString());

        $batch = StockBatch::query()->where('batch_no', 'LOT-A')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('inventory.labels.generate'), [
                'subject_type' => 'batch',
                'batch_ids' => [$batch->id],
                'template' => 'a4_3x8',
            ])
            ->assertSessionHasNoErrors();

        [$document, $content] = $this->lastSheet();

        $this->assertStringContainsString('LOT-A', $content);
        $this->assertStringContainsString('Batch LOT-A', $content);
        $this->assertStringContainsString('exp', $content);
        $this->assertStringContainsString('Corrugated box, 12 inch', $content);
        $this->assertStringStartsWith('labels-', (string) $document->original_name, 'a sheet is named for what it is, not for a row');
    }

    /* ----------------------------------------------------------- the numbers --- */

    public function test_the_sheet_geometry_fills_the_page_and_says_when_a_code_will_not_fit(): void
    {
        $service = app(LabelService::class);

        $subject = $service->productSubject($this->box->fresh());

        // 3 × 70 mm and 8 × 37 mm: an A4 page with nothing to spare.
        $template = $service->templateDefinition('a4_3x8');
        $this->assertSame(210.0, $template['columns'] * $template['width_mm']);
        $this->assertSame(296.0, $template['rows'] * $template['height_mm']);
        $this->assertSame(24, $service->perPage('a4_3x8'));

        $sheet = $service->sheet([$subject], ['template' => 'a4_3x8', 'copies' => 25]);
        $this->assertSame(25, $sheet['totals']['labels']);
        $this->assertSame(2, $sheet['totals']['pages'], '25 labels need a second sheet');
        $this->assertSame(0, $sheet['totals']['unprintable']);
        $this->assertSame([], $sheet['warnings']);

        // A long code on a 38 mm roll: the desk is warned before the paper is spent.
        $long = $subject;
        $long['payload'] = 'PACKAGING-CARTON-TWELVE-INCH-DHAKA-2026';
        $long['name'] = 'A very long code';

        $narrow = $service->sheet([$long], ['template' => 'thermal_38x25']);
        $this->assertSame(1, $narrow['totals']['unprintable']);
        $this->assertStringContainsString('smallest printable bar', $narrow['warnings'][0]);
        $this->assertLessThan(BarcodeService::MIN_MODULE_MM, $narrow['totals']['narrowest_module_mm']);

        // And a QR never squeezes the bars below that floor: it is the QR that
        // gives way, and it says so.
        $both = $service->sheet([$long], ['template' => 'thermal_38x25', 'qr' => true]);
        $this->assertNull($both['labels'][0]['qr_svg']);
        $this->assertTrue(collect($both['warnings'])->contains(fn (string $w) => str_contains($w, 'the QR was left off')));
    }

    public function test_the_payload_is_the_product_own_code_and_never_an_internal_id(): void
    {
        $service = app(LabelService::class);

        $this->assertSame('8801234567890', $service->payloadFor($this->box));

        // Without a barcode the SKU stands in; without that, the product code.
        $this->box->forceFill(['barcode' => null])->save();
        $this->assertSame('PKD-BOX-12', $service->payloadFor($this->box->fresh()));

        $this->box->forceFill(['sku' => ''])->save();
        $this->assertSame('PKD-BOX', $service->payloadFor($this->box->fresh()));
    }

    /* -------------------------------------------------------- the generators --- */

    public function test_the_generator_screens_and_the_per_product_svg_endpoints(): void
    {
        $this->actingAs($this->admin)
            ->get(route('inventory.labels.barcodes', ['product' => $this->box->id]))
            ->assertOk()
            ->assertSee('Code 128')
            ->assertSee('8801234567890')
            ->assertSee('Check digit');

        $this->actingAs($this->admin)
            ->get(route('inventory.labels.qr-codes', ['product' => $this->box->id]))
            ->assertOk()
            ->assertSee('Mask')
            ->assertSee('version');

        $barcode = $this->actingAs($this->admin)
            ->get(route('inventory.products.barcode', ['product' => $this->box->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');

        $this->assertStringContainsString('<svg', $barcode->getContent());
        $this->assertStringContainsString('8801234567890', $barcode->getContent());

        $qr = $this->actingAs($this->admin)
            ->get(route('inventory.products.qr', ['product' => $this->box->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');

        $this->assertStringContainsString('<svg', $qr->getContent());

        // A product we do not own is not ours to encode: 404, not an empty symbol.
        $this->actingAs($this->admin)
            ->get(route('inventory.products.barcode', ['product' => 999999]))
            ->assertNotFound();
    }

    /* ----------------------------------------------------------- the scanner --- */

    public function test_the_scanner_bench_resolves_codes_and_says_when_nothing_matches(): void
    {
        $this->box->forceFill(['track_batch' => true])->save();
        $this->receive($this->box->fresh(), 5, 'LOT-SCAN');

        // Barcode, SKU and code all resolve — a label can carry any of the three.
        foreach ([
            ['8801234567890', 'barcode'],
            ['PKD-BOX-12', 'SKU'],
            ['PKD-BOX', 'product code'],
        ] as [$code, $matched]) {
            $this->actingAs($this->admin)
                ->getJson(route('inventory.labels.lookup', ['code' => $code]))
                ->assertOk()
                ->assertJson([
                    'found' => true,
                    'kind' => 'product',
                    'label' => 'Corrugated box, 12 inch',
                    'matched_on' => $matched,
                ]);
        }

        $this->actingAs($this->admin)
            ->getJson(route('inventory.labels.lookup', ['code' => 'LOT-SCAN']))
            ->assertOk()
            ->assertJson(['found' => true, 'kind' => 'batch', 'matched_on' => 'batch number']);

        $this->actingAs($this->admin)
            ->getJson(route('inventory.labels.lookup', ['code' => 'NOTHING-AT-ALL']))
            ->assertOk()
            ->assertJson(['found' => false]);

        // The bench shows the same resolution, and flags a code too short to trust.
        $this->actingAs($this->admin)
            ->get(route('inventory.labels.scanner', ['code' => '8801234567890']))
            ->assertOk()
            ->assertSee('Corrugated box, 12 inch')
            ->assertSee('Matched the barcode');

        $this->actingAs($this->admin)
            ->get(route('inventory.labels.scanner', ['code' => 'X']))
            ->assertOk()
            ->assertSee('squarer at the bars');
    }

    /* ---------------------------------------------------------------- service --- */

    public function test_the_service_refuses_an_empty_run_and_a_run_over_its_ceiling(): void
    {
        $service = app(LabelService::class);
        $subject = $service->productSubject($this->box->fresh());

        $this->expectException(\InvalidArgumentException::class);
        $service->sheet([], []);
    }

    public function test_the_barcode_and_qr_encoders_are_the_ones_the_sheet_uses(): void
    {
        // A short guarantee that the desk is not carrying its own second encoder:
        // what the service reports is what the encoder computed, check digit and
        // all, and the QR's version is the smallest the data fits in.
        $service = app(LabelService::class);
        $barcode = app(BarcodeService::class);
        $qr = app(QrService::class);

        $sheet = $service->sheet([$service->productSubject($this->box->fresh())], ['template' => 'a4_3x8']);
        $label = $sheet['labels'][0];
        $encoded = $barcode->encode('8801234567890');

        $this->assertSame($encoded['checksum'], $label['checksum']);
        $this->assertSame($encoded['subset'], $label['subset']);
        $this->assertSame($encoded['symbology'], 'Code 128');
        $this->assertSame($qr->fitVersion(13, 'M'), $qr->encode('8801234567890', 'M')['version']);

        // The price on a label is the price the till resolves, not a column read.
        $this->assertSame(
            round(app(PricingService::class)->resolveUnitPrice($this->box->fresh()), 4),
            round((float) $label['price'], 4),
        );
    }
}
