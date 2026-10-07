import pathlib

# ---------------------------------------------------------------- ProductController
p = pathlib.Path('app/Http/Controllers/ProductController.php')
s = p.read_text()

a = """use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\View\\View;"""
b = """use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\View\\View;
use Symfony\\Component\\HttpFoundation\\StreamedResponse;"""
assert s.count(a) == 1, 'imports'
s = s.replace(a, b, 1)

a = """    /** The copy form (§04-04) — prefilled with an identity that is actually free. */"""
b = """    /**
     * The catalogue as CSV (§04-12). The columns are the import template's own,
     * in its own order, so an export can be edited and imported straight back —
     * that round trip is the point of an export in an ERP, not a report.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));

        $query = Product::query()
            ->where('company_id', $user->company_id)
            ->with(['category:id,code', 'brand:id,code', 'unit:id,code'])
            ->orderBy('sku');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (($data['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($data['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $products = $query->get();

        return response()->streamDownload(function () use ($products): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ProductImportService::COLUMNS);

            foreach ($products as $product) {
                fputcsv($out, [
                    $product->code,
                    $product->sku,
                    $product->name,
                    $product->category?->code,
                    $product->brand?->code,
                    $product->unit?->code,
                    $product->barcode,
                    $product->description,
                    $product->cost_method,
                    number_format((float) $product->standard_cost, 4, '.', ''),
                    $product->is_stocked ? '1' : '0',
                    $product->track_batch ? '1' : '0',
                    $product->is_active ? '1' : '0',
                ]);
            }

            fclose($out);
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The copy form (§04-04) — prefilled with an identity that is actually free. */"""
assert s.count(a) == 1, 'export method slot'
s = s.replace(a, b, 1)

a = "use App\\Domain\\Inventory\\Services\\ProductService;"
b = "use App\\Domain\\Inventory\\Services\\ProductImportService;\nuse App\\Domain\\Inventory\\Services\\ProductService;"
assert s.count(a) == 1, 'service import'
s = s.replace(a, b, 1)
p.write_text(s)
print('ProductController::export added')

# ------------------------------------------------------------------------ routes
p = pathlib.Path('routes/web.php')
s = p.read_text()

a = """    // §04-04 — a copy of the catalogue row, never a copy of the stock."""
b = """    // §04-12 — the catalogue as CSV, in and out. The import screen and its
    // history sit behind one key; reading the catalogue out has its own, because
    // taking a copy of the catalogue is a different permission from changing it.
    Route::get('/app/inventory/products/import', [ProductImportController::class, 'create'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import');
    Route::post('/app/inventory/products/import', [ProductImportController::class, 'store'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.store');
    Route::get('/app/inventory/products/import/template', [ProductImportController::class, 'template'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.template');
    Route::get('/app/inventory/products/import/history', [ProductImportController::class, 'history'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.history');
    Route::get('/app/inventory/products/import/{import}', [ProductImportController::class, 'show'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.show');
    Route::get('/app/inventory/products/export', [ProductController::class, 'export'])
        ->middleware('permission:inventory.products.export')
        ->name('inventory.products.export');

    // §04-04 — a copy of the catalogue row, never a copy of the stock."""
assert s.count(a) == 1, 'route anchor'
s = s.replace(a, b, 1)

a = "use App\\Http\\Controllers\\ProductCostHistoryController;"
b = "use App\\Http\\Controllers\\ProductCostHistoryController;\nuse App\\Http\\Controllers\\ProductImportController;"
assert s.count(a) == 1, 'controller import'
s = s.replace(a, b, 1)
p.write_text(s)
print('routes: import screen/history/show/template + export')

# --------------------------------------------------------------------- permissions
p = pathlib.Path('database/seeders/FoundationPermissionSeeder.php')
s = p.read_text()
a = "            ['inventory', 'products', 'edit', 'inventory.products.edit', 'Edit products'],"
b = """            ['inventory', 'products', 'edit', 'inventory.products.edit', 'Edit products'],
            ['inventory', 'products', 'import', 'inventory.products.import', 'Import the product catalogue (CSV)'],
            ['inventory', 'products', 'export', 'inventory.products.export', 'Export the product catalogue (CSV)'],"""
assert s.count(a) == 1, 'product permissions'
s = s.replace(a, b, 1)
p.write_text(s)
print('FoundationPermissionSeeder: inventory.products.import / .export')

p = pathlib.Path('database/seeders/SystemRoleSeeder.php')
s = p.read_text()
a = """                'inventory.batch.view', 'inventory.batch.manage',"""
b = """                'inventory.batch.view', 'inventory.batch.manage',
                // §04-12: the catalogue is maintained in bulk by the desk that
                // owns masters, and taking a copy of it out is part of that job.
                'inventory.products.import', 'inventory.products.export',"""
assert s.count(a) == 1, 'manager bundle'
s = s.replace(a, b, 1)
p.write_text(s)
print('SystemRoleSeeder: manager holds the catalogue import/export keys')

# ------------------------------------------------------------------ menu mapping
p = pathlib.Path('app/Domain/Foundation/Services/CatalogImporter.php')
s = p.read_text()
a = "        'inventory > products > product import'"
if a in s:
    print('importer: already mapped')
else:
    anchor = "        'inventory > products > product cost history' => ['/app/inventory/cost-history', 'inventory.products.view'],"
    assert s.count(anchor) == 1, 'importer anchor'
    s = s.replace(anchor, """        'inventory > products > product import' => ['/app/inventory/products/import', 'inventory.products.import'],
        'inventory > products > product export' => ['/app/inventory/products/export', 'inventory.products.export'],
        'inventory > products > import history' => ['/app/inventory/products/import/history', 'inventory.products.import'],
""" + anchor, 1)
    p.write_text(s)
    print('CatalogImporter: import / export / history leaves mapped')
