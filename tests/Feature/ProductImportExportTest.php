<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductCostHistory;
use App\Domain\Inventory\ProductImport;
use App\Domain\Inventory\Services\ProductService;
use App\Domain\Masters\Brand;
use App\Domain\Masters\ProductCategory;
use App\Domain\Masters\Unit;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-12 catalogue import and export.
 *
 * What this pins:
 *  · the export's columns are the import template's own, so an export can be fed
 *    straight back in — and a file that describes what is already there changes
 *    nothing at all;
 *  · a preview writes nothing: same walk, every row rolled back, so its report is
 *    what a real run would do rather than a second opinion about it;
 *  · a blank cell means "leave it alone" — importing prices cannot erase the
 *    descriptions somebody else typed;
 *  · each row is its own unit of work: one bad line is reported with its line
 *    number and the rest of the file still lands;
 *  · a cost moved by an import still lands in the cost history, with the file
 *    named as the reason — bulk is not a way around the trail;
 *  · masters are never invented from a spreadsheet, and importing/exporting are
 *    two separate permissions.
 */
class ProductImportExportTest extends TestCase
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
    }

    protected function product(string $code, array $overrides = []): Product
    {
        return app(ProductService::class)->create(array_merge([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => 'Product '.$code,
            'cost_method' => 'fifo',
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
        ], $overrides));
    }

    protected function csv(array $rows, array $headings = ['code', 'sku', 'name']): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headings);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    protected function upload(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $csv);
    }

    public function test_the_template_downloads_with_the_columns_the_importer_reads(): void
    {
        $response = $this->actingAs($this->admin)->get(route('inventory.products.import.template'));

        $response->assertOk();

        $body = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($body))));

        $this->assertSame(
            implode(',', \App\Domain\Inventory\Services\ProductImportService::COLUMNS),
            trim($lines[0]),
        );
        $this->assertGreaterThanOrEqual(3, count($lines), 'The template carries the headings and at least one example row.');
    }

    public function test_an_export_can_be_imported_back_unchanged(): void
    {
        $category = ProductCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'HARDWARE',
            'name' => 'Hardware',
            'is_active' => true,
        ]);

        $this->product('ROUND-1', [
            'product_category_id' => $category->id,
            'description' => 'A description, with a comma and "quotes".',
            'standard_cost' => 125.5,
            'track_batch' => true,
        ]);
        $this->product('ROUND-2', ['cost_method' => 'wac', 'is_active' => false]);

        $export = $this->actingAs($this->admin)->get(route('inventory.products.export'));
        $export->assertOk();

        $csv = $export->streamedContent();
        $this->assertStringContainsString('ROUND-1', $csv);

        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($csv),
                'mode' => 'preview',
            ])
            ->assertRedirect();

        $run = ProductImport::query()->latest('id')->sole();

        $this->assertSame(2, $run->rows_total);
        $this->assertSame(0, $run->rows_created, 'A file describing what is already there must create nothing.');
        $this->assertSame(0, $run->rows_updated, '…and change nothing.');
        $this->assertSame(0, $run->rows_failed);
        $this->assertSame(2, $run->rows_skipped);
        $this->assertTrue($run->dry_run);
        $this->assertSame(ProductImport::STATUS_PREVIEW, $run->status);
    }

    public function test_a_preview_writes_nothing_and_says_what_would_happen(): void
    {
        $csv = $this->csv([
            ['NEW-1', 'NEW-1-SKU', 'First new product'],
            ['NEW-2', 'NEW-2-SKU', 'Second new product'],
        ]);

        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($csv),
                'mode' => 'preview',
            ])
            ->assertRedirect();

        $this->assertSame(0, Product::query()->whereIn('code', ['NEW-1', 'NEW-2'])->count());

        $run = ProductImport::query()->latest('id')->sole();
        $this->assertSame(2, $run->rows_created, 'The preview reports what a real run would create.');
        $this->assertSame(0, $run->rows_failed);
        $this->assertFalse($run->wroteAnything());
    }

    public function test_an_import_creates_products_and_resolves_masters_by_code_or_name(): void
    {
        ProductCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'HARDWARE',
            'name' => 'Hardware',
            'is_active' => true,
        ]);
        Brand::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'ACME',
            'name' => 'Acme Tools',
            'is_active' => true,
        ]);
        Unit::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PCS',
            'name' => 'Pieces',
            'is_active' => true,
        ]);

        $csv = $this->csv(
            [['IMP-1', 'IMP-1-SKU', 'Imported widget', 'HARDWARE', 'Acme Tools', 'PCS', '', 'From the file', 'wac', '99.25', '1', '1', '1']],
            ['code', 'sku', 'name', 'category', 'brand', 'unit', 'barcode', 'description', 'cost_method', 'standard_cost', 'is_stocked', 'track_batch', 'is_active'],
        );

        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($csv),
                'mode' => 'import',
            ])
            ->assertRedirect();

        $product = Product::query()->where('code', 'IMP-1')->sole();

        $this->assertSame('Imported widget', $product->name);
        $this->assertSame('HARDWARE', $product->category?->code);
        $this->assertSame('Acme Tools', $product->brand?->name);
        $this->assertSame('PCS', $product->unit?->code);
        $this->assertSame('wac', $product->cost_method);
        $this->assertSame('99.2500', (string) $product->standard_cost);
        $this->assertTrue((bool) $product->track_batch);
        $this->assertSame('From the file', $product->description);

        $run = ProductImport::query()->latest('id')->sole();
        $this->assertSame(ProductImport::STATUS_IMPORTED, $run->status);
        $this->assertTrue($run->wroteAnything());

        $this->assertTrue(
            AuditEvent::query()->where('action', 'inventory.products_imported')->where('entity_id', $run->id)->exists(),
            'A bulk write needs a receipt in the audit trail.',
        );
    }

    public function test_an_import_updates_only_the_cells_the_file_filled(): void
    {
        $category = ProductCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'KEEP',
            'name' => 'Keep me',
            'is_active' => true,
        ]);

        $product = $this->product('UPD-1', [
            'product_category_id' => $category->id,
            'description' => 'A description nobody wants to lose.',
            'standard_cost' => 10,
        ]);

        $csv = $this->csv(
            [['UPD-1', 'UPD-1-SKU', 'Product UPD-1', '', '', '', '', '', '', '18.50', '', '', '']],
            ['code', 'sku', 'name', 'category', 'brand', 'unit', 'barcode', 'description', 'cost_method', 'standard_cost', 'is_stocked', 'track_batch', 'is_active'],
        );

        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($csv),
                'mode' => 'import',
            ])
            ->assertRedirect();

        $product->refresh();

        $this->assertSame('18.5000', (string) $product->standard_cost);
        $this->assertSame('A description nobody wants to lose.', $product->description, 'A blank cell must not erase a value.');
        $this->assertSame($category->id, (int) $product->product_category_id);

        $history = ProductCostHistory::query()->where('product_id', $product->id)->sole();

        $this->assertSame('10.0000', (string) $history->old_standard_cost);
        $this->assertSame('18.5000', (string) $history->new_standard_cost);
        $this->assertStringContainsString('products.csv', (string) $history->reason);
        $this->assertSame($this->admin->id, $history->changed_by);

        $run = ProductImport::query()->latest('id')->sole();
        $this->assertSame(1, $run->rows_updated);
        $this->assertSame(0, $run->rows_created);
    }

    public function test_a_row_naming_a_category_that_does_not_exist_fails_alone(): void
    {
        $csv = $this->csv(
            [
                ['BAD-1', 'BAD-1-SKU', 'Nothing costs this', 'NOT-A-CATEGORY', '', '', '', '', 'wac', '5', '', '', ''],
                ['GOOD-1', 'GOOD-1-SKU', 'This one is fine', '', '', '', '', '', 'wac', '6', '', '', ''],
                ['GOOD-2', 'GOOD-2-SKU', 'So is this one', '', '', '', '', '', 'fifo', '7', '', '', ''],
            ],
            ['code', 'sku', 'name', 'category', 'brand', 'unit', 'barcode', 'description', 'cost_method', 'standard_cost', 'is_stocked', 'track_batch', 'is_active'],
        );

        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($csv),
                'mode' => 'import',
            ])
            ->assertRedirect();

        $run = ProductImport::query()->latest('id')->sole();

        $this->assertSame(ProductImport::STATUS_IMPORTED_WITH_ERRORS, $run->status);
        $this->assertSame(2, $run->rows_created);
        $this->assertSame(1, $run->rows_failed);

        $problem = collect($run->errors)->firstWhere('line', 2);
        $this->assertNotNull($problem, 'The refused row is reported by its line number.');
        $this->assertStringContainsString('NOT-A-CATEGORY', $problem['message']);
        $this->assertStringContainsString('Masters', $problem['message']);

        $this->assertSame(2, Product::query()->whereIn('code', ['GOOD-1', 'GOOD-2'])->count());
        $this->assertSame(0, Product::query()->where('code', 'BAD-1')->count());
    }

    public function test_a_row_with_a_bad_cost_method_is_refused_with_the_allowed_list(): void
    {
        $csv = $this->csv(
            [['CM-1', 'CM-1-SKU', 'Wrong method', '', '', '', '', '', 'averageish', '5', '', '', '']],
            ['code', 'sku', 'name', 'category', 'brand', 'unit', 'barcode', 'description', 'cost_method', 'standard_cost', 'is_stocked', 'track_batch', 'is_active'],
        );

        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($csv),
                'mode' => 'import',
            ])
            ->assertRedirect();

        $message = (string) collect(ProductImport::query()->latest('id')->sole()->errors)[0]['message'];

        $this->assertStringContainsString('averageish', $message);
        $this->assertStringContainsString('fifo', $message);
        $this->assertStringContainsString('standard', $message);
        $this->assertSame(0, Product::query()->where('code', 'CM-1')->count());
    }

    public function test_a_file_with_no_rows_is_refused_without_recording_a_run(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($this->csv([], ['code', 'sku', 'name'])),
                'mode' => 'import',
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(
            0,
            ProductImport::query()->count(),
            'A file that was never read leaves no run behind — there is nothing to record.',
        );
    }

    public function test_a_file_without_a_name_or_code_column_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.products.import.store'), [
                'file' => $this->upload($this->csv([['HARDWARE', 'Hardware']], ['category', 'label'])),
                'mode' => 'import',
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, ProductImport::query()->count());
    }

    public function test_import_and_export_are_two_separate_permissions(): void
    {
        $importer = $this->makeUser(['name' => 'Catalogue Importer']);
        $importer->roles()->sync($this->roleWith(['portal.erp.access', 'inventory.products.view', 'inventory.products.import'])->id);
        app(PermissionCatalog::class)->invalidate($importer);

        $this->actingAs($importer)->get(route('inventory.products.import'))->assertOk();
        $this->actingAs($importer)->get(route('inventory.products.import.history'))->assertOk();
        $this->actingAs($importer)->get(route('inventory.products.export'))->assertForbidden();

        $exporter = $this->makeUser(['name' => 'Catalogue Exporter']);
        $exporter->roles()->sync($this->roleWith(['portal.erp.access', 'inventory.products.view', 'inventory.products.export'])->id);
        app(PermissionCatalog::class)->invalidate($exporter);

        $this->actingAs($exporter)->get(route('inventory.products.export'))->assertOk();
        $this->actingAs($exporter)->get(route('inventory.products.import'))->assertForbidden();
    }

    public function test_another_companys_run_is_a_404(): void
    {
        $otherCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $strangerId = (int) DB::table('product_imports')->insertGetId([
            'company_id' => $otherCompanyId,
            'original_name' => 'somebody-elses.csv',
            'status' => 'imported',
            'dry_run' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.products.import.show', $strangerId))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('inventory.products.import.history'))
            ->assertOk()
            ->assertDontSee('somebody-elses.csv');
    }
}
