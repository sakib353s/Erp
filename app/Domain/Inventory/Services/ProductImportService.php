<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductImport;
use App\Domain\Masters\Brand;
use App\Domain\Masters\ProductCategory;
use App\Domain\Masters\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Catalogue import (§04-12).
 *
 * The rules that make a bulk write safe, in one place:
 *
 *  · **A row is a unit of work.** Each row runs in its own transaction, so row
 *    412 failing does not undo the 411 that were fine — and the run's error list
 *    says exactly which line did not land and why.
 *  · **A preview writes nothing.** The same code path is used for both modes;
 *    the preview just rolls its own transaction back. A dry run therefore
 *    reports what a real run *would* do, not a second opinion about it.
 *  · **Blank means "leave it alone".** Importing a file that only carries codes
 *    and prices must not blank out descriptions and categories somebody else
 *    filled in. Only the cells the file actually filled are applied, and a row
 *    that changes nothing is counted as unchanged rather than touched.
 *  · **A cost change is still a cost change.** Updates go through
 *    `ProductService::update()`, so an import that moves a standard cost lands in
 *    the product's cost history with the file name as its reason — bulk is not a
 *    way around the trail.
 *  · **Masters are never invented.** A category, brand or unit named in the file
 *    must already exist for the company. Silently creating masters from a
 *    spreadsheet is how a catalogue ends up with "Home Appliance", "home
 *    appliance" and "Home appliance " as three categories.
 */
class ProductImportService
{
    /** Rows the sheet must stay under, and the file size the form accepts. */
    public const MAX_ROWS = 5000;

    public const MAX_KILOBYTES = 2048;

    /** The template is the contract: these headings, in any order. */
    public const COLUMNS = [
        'code', 'sku', 'name', 'category', 'brand', 'unit',
        'barcode', 'description', 'cost_method', 'standard_cost',
        'is_stocked', 'track_batch', 'is_active',
    ];

    /**
     * Headings people actually type, mapped to the column they mean. Everything
     * else is reported as ignored rather than quietly dropped.
     */
    protected const ALIASES = [
        'product_code' => 'code',
        'code' => 'code',
        'product_name' => 'name',
        'name' => 'name',
        'item_name' => 'name',
        'sku' => 'sku',
        'barcode' => 'barcode',
        'ean' => 'barcode',
        'description' => 'description',
        'category' => 'category',
        'category_code' => 'category',
        'product_category' => 'category',
        'brand' => 'brand',
        'brand_name' => 'brand',
        'unit' => 'unit',
        'unit_code' => 'unit',
        'uom' => 'unit',
        'cost_method' => 'cost_method',
        'method' => 'cost_method',
        'cost' => 'standard_cost',
        'standard_cost' => 'standard_cost',
        'stocked' => 'is_stocked',
        'is_stocked' => 'is_stocked',
        'stock_managed' => 'is_stocked',
        'batch' => 'track_batch',
        'track_batch' => 'track_batch',
        'active' => 'is_active',
        'is_active' => 'is_active',
    ];

    public function __construct(
        protected ProductService $products,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /** @return array<int, string> */
    public function templateHeadings(): array
    {
        return self::COLUMNS;
    }

    /**
     * Two example rows: one complete, one that shows how little a row may carry
     * when it only means to move a price.
     *
     * @return array<int, array<int, string>>
     */
    public function templateRows(): array
    {
        return [
            array_map('strval', self::COLUMNS),
            ['WIDGET-1', 'WIDGET-1-SKU', 'Widget, standard', 'HARDWARE', 'ACME', 'PCS', '8801234567890', 'Steel widget', 'fifo', '125.50', '1', '0', '1'],
            ['WIDGET-2', 'WIDGET-2-SKU', 'Widget, heavy duty', 'HARDWARE', '', 'PCS', '', '', 'wac', '210', '1', '1', '1'],
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, warnings: array<int, string>, delimiter: string}
     */
    public function parse(UploadedFile $file): array
    {
        $size = (int) ($file->getSize() ?? 0);

        if ($size > self::MAX_KILOBYTES * 1024) {
            throw new RuntimeException('That file is larger than '.self::MAX_KILOBYTES.' KB — split the catalogue and import it in parts.');
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $delimiter = $this->sniffDelimiter($path);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $headerRow = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if ($headerRow === false || $headerRow === [null]) {
            fclose($handle);

            throw new RuntimeException('The file is empty — the first row has to be the column headings.');
        }

        $map = [];
        $warnings = [];
        $ignored = [];

        foreach ($headerRow as $index => $raw) {
            $canonical = $this->canonicalHeader((string) $raw);

            if ($canonical === null) {
                if (trim((string) $raw) !== '') {
                    $ignored[trim((string) $raw)] = true;
                }

                continue;
            }

            $map[$canonical] = $index;
        }

        foreach (array_keys($ignored) as $label) {
            $warnings[] = "Column \"{$label}\" is not part of the template and was ignored.";
        }

        if (! isset($map['name'])) {
            fclose($handle);

            throw new RuntimeException('The file needs a "name" column — a product without a name cannot be imported.');
        }

        if (! isset($map['code']) && ! isset($map['sku'])) {
            fclose($handle);

            throw new RuntimeException('The file needs a "code" or a "sku" column: that is how a row is matched to a product.');
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;

            if ($this->isBlankLine($cells)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw new RuntimeException('That file carries more than '.number_format(self::MAX_ROWS).' rows — split it and import in parts.');
            }

            $row = [];

            foreach ($map as $column => $index) {
                $row[$column] = trim((string) ($cells[$index] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($rows === []) {
            throw new RuntimeException('The file has headings but no rows.');
        }

        return [
            'headers' => array_keys($map),
            'rows' => $rows,
            'warnings' => $warnings,
            'delimiter' => $delimiter,
        ];
    }

    /** Preview and import are the same walk; only the rollback differs. */
    public function run(User $user, UploadedFile $file, bool $dryRun): ProductImport
    {
        $parsed = $this->parse($file);

        $run = ProductImport::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'status' => $dryRun ? ProductImport::STATUS_PREVIEW : ProductImport::STATUS_IMPORTED,
            'dry_run' => $dryRun,
            'headers' => $parsed['headers'],
            'errors' => [],
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $problems = [];

        foreach ($parsed['warnings'] as $warning) {
            $problems[] = ['line' => 0, 'message' => $warning];
        }

        foreach ($parsed['rows'] as $index => $row) {
            $line = $index + 2;   // line 1 is the heading row

            if ($this->isBlankRow($row)) {
                $skipped++;

                continue;
            }

            try {
                $outcome = DB::transaction(function () use ($row, $run, $dryRun, $user) {
                    $outcome = $this->applyRow($row, $run, $user);

                    if ($dryRun) {
                        // A preview is a statement about the file, not a write:
                        // the row is rolled back so nothing half-lands.
                        DB::rollBack();
                    }

                    return $outcome;
                });
            } catch (Throwable $e) {
                $failed++;
                $problems[] = [
                    'line' => $line,
                    'code' => $row['code'] ?? ($row['sku'] ?? ''),
                    'name' => $row['name'] ?? '',
                    'message' => $this->explain($e),
                ];

                continue;
            }

            match ($outcome) {
                'created' => $created++,
                'updated' => $updated++,
                default => $skipped++,
            };
        }

        $run->forceFill([
            'status' => $dryRun
                ? ProductImport::STATUS_PREVIEW
                : ($failed > 0 ? ProductImport::STATUS_IMPORTED_WITH_ERRORS : ProductImport::STATUS_IMPORTED),
            'rows_total' => count($parsed['rows']),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_skipped' => $skipped,
            'rows_failed' => $failed,
            'errors' => $problems,
        ])->save();

        if (! $dryRun) {
            $this->audit->record([
                'action' => 'inventory.products_imported',
                'entity_type' => 'product_import',
                'entity_id' => $run->id,
                'actor_id' => $user->id,
                'after' => [
                    'file' => $run->original_name,
                    'rows_total' => $run->rows_total,
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ],
            ]);
        }

        return $run->refresh();
    }

    /** @param array<string, string> $row */
    protected function applyRow(array $row, ProductImport $run, User $user): string
    {
        $code = $row['code'] ?? '';
        $sku = $row['sku'] ?? '';
        $name = $row['name'] ?? '';

        $bySku = $sku !== '' ? $this->findProduct('sku', $sku) : null;
        $byCode = $code !== '' ? $this->findProduct('code', $code) : null;

        if ($bySku !== null && $byCode !== null && $bySku->id !== $byCode->id) {
            throw new RuntimeException('That SKU and that product code belong to two different products — one row cannot mean both.');
        }

        $existing = $bySku ?? $byCode;

        $data = [
            'code' => $code !== '' ? $code : ($existing?->code ?? $sku),
            'sku' => $sku !== '' ? $sku : ($existing?->sku ?? $code),
            'name' => $name !== '' ? $name : ($existing?->name ?? ''),
            'barcode' => $this->nullable($row['barcode'] ?? null),
            'description' => $this->nullable($row['description'] ?? null),
            'cost_method' => ($row['cost_method'] ?? '') !== '' ? strtolower($row['cost_method']) : null,
            'standard_cost' => ($row['standard_cost'] ?? '') !== '' ? $this->decimal($row['standard_cost'], 'standard cost') : null,
            'is_stocked' => $this->boolean($row['is_stocked'] ?? null),
            'track_batch' => $this->boolean($row['track_batch'] ?? null),
            'is_active' => $this->boolean($row['is_active'] ?? null),
            'product_category_id' => $this->master(ProductCategory::class, $row['category'] ?? null, 'Category'),
            'brand_id' => $this->master(Brand::class, $row['brand'] ?? null, 'Brand'),
            'unit_id' => $this->master(Unit::class, $row['unit'] ?? null, 'Unit'),
        ];

        if ($existing === null) {
            if ($data['name'] === '') {
                throw new RuntimeException('A new product needs a name.');
            }

            if (! in_array($data['cost_method'] ?? 'wac', Product::COST_METHODS, true)) {
                throw new RuntimeException('Unsupported cost method "'.$data['cost_method'].'" — use '.implode(', ', Product::COST_METHODS).'.');
            }

            $this->products->create($this->withoutBlanks($data, [
                'cost_method' => 'wac',
                'standard_cost' => 0.0,
                'is_stocked' => true,
                'track_batch' => false,
                'is_active' => true,
            ]));

            return 'created';
        }

        if (($data['cost_method'] ?? $existing->cost_method) !== $existing->cost_method
            && ! in_array($data['cost_method'], Product::COST_METHODS, true)) {
            throw new RuntimeException('Unsupported cost method "'.$data['cost_method'].'" — use '.implode(', ', Product::COST_METHODS).'.');
        }

        // Only the cells the file actually filled are compared and applied: a
        // blank cell means "leave it as it is", never "erase it".
        $changes = [];

        foreach ($data as $field => $value) {
            if ($value === null) {
                continue;
            }

            $current = $existing->{$field};

            if (is_float($value) || is_int($value)) {
                if (abs((float) $current - (float) $value) > 0.00005) {
                    $changes[$field] = $value;
                }

                continue;
            }

            if (is_bool($value)) {
                if ((bool) $current !== $value) {
                    $changes[$field] = $value;
                }

                continue;
            }

            if ((string) $current !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return 'unchanged';
        }

        // A cost that moves inside an import is still a cost that moved: the
        // reason names the file, so the history explains itself later.
        if (array_key_exists('standard_cost', $changes) || array_key_exists('cost_method', $changes)) {
            $changes['cost_change_reason'] = 'Catalogue import “'.$run->original_name.'” (run #'.$run->id.').';
        }

        $this->products->update($existing, $changes, $user);

        return 'updated';
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    protected function master(string $model, ?string $value, string $label): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $needle = mb_strtolower($value);

        $match = $model::query()
            ->where('company_id', $this->context->companyId())
            ->where(fn ($q) => $q->whereRaw('lower(code) = ?', [$needle])->orWhereRaw('lower(name) = ?', [$needle]))
            ->first();

        if ($match === null) {
            throw new RuntimeException("{$label} \"{$value}\" does not exist for this company — add it in Masters first, then import.");
        }

        return (int) $match->getKey();
    }

    protected function findProduct(string $column, string $value): ?Product
    {
        return Product::query()
            ->where('company_id', $this->context->companyId())
            ->where($column, $value)
            ->first();
    }

    protected function boolean(?string $value): ?bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return match (mb_strtolower($value)) {
            '1', 'true', 'yes', 'y', 'on', 'active' => true,
            '0', 'false', 'no', 'n', 'off', 'inactive' => false,
            default => throw new RuntimeException("Expected a yes/no value, got \"{$value}\"."),
        };
    }

    protected function decimal(string $value, string $label): float
    {
        $cleaned = str_replace([',', ' '], '', trim($value));

        if (! is_numeric($cleaned)) {
            throw new RuntimeException("The {$label} \"{$value}\" is not a number.");
        }

        $number = (float) $cleaned;

        if ($number < 0) {
            throw new RuntimeException("The {$label} \"{$value}\" cannot be negative.");
        }

        return $number;
    }

    protected function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected function withoutBlanks(array $data, array $defaults): array
    {
        foreach ($data as $field => $value) {
            if ($value === null) {
                $data[$field] = $defaults[$field] ?? null;
            }
        }

        foreach (['code', 'sku', 'name'] as $required) {
            if (($data[$required] ?? '') === '') {
                throw new RuntimeException("A new product needs a {$required}.");
            }
        }

        return $data;
    }

    protected function explain(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $messages = [];

            foreach ($e->errors() as $field => $list) {
                $messages[] = implode(' ', $list);
            }

            return implode(' ', $messages);
        }

        return $e->getMessage();
    }

    /** Comma, semicolon or tab: whichever the heading row uses most. */
    protected function sniffDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $line = $handle === false ? '' : (string) fgets($handle, 8192);

        if ($handle !== false) {
            fclose($handle);
        }

        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    protected function canonicalHeader(string $raw): ?string
    {
        $raw = str_replace("\xEF\xBB\xBF", '', $raw);
        $key = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($raw))) ?? '';
        $key = trim($key, '_');

        return self::ALIASES[$key] ?? null;
    }

    /** @param array<int, string|null> $cells */
    protected function isBlankLine(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $row */
    protected function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
