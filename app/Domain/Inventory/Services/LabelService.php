<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBatch;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Settings\Services\SettingService;
use InvalidArgumentException;
use RuntimeException;

/**
 * What a label says and where it sits on the paper (§04-13, §04-52, §04-53).
 *
 * This service owns three things the rest of the desk only presents:
 *
 *  · **The payload.** What a scanner reads back must be the *product's own
 *    code*, never a row id and never a number minted at print time. A label is
 *    a promise that "what I scanned is what this is", so the payload is a
 *    function of the row: print the same box next year and the same bars come
 *    out. An internal id would also be a poor thing to print — it tells anyone
 *    who scans the label how many rows we have — while a code scans straight
 *    into the search box that already resolves it.
 *  · **The template.** Real sheet geometry (columns × rows, label size, page
 *    margins, gaps), because label paper is bought by part number and a sheet
 *    that is 2 mm off puts every row on a seam.
 *  · **The fit.** Code 128 may not be printed finer than 0.25 mm per module
 *    (ISO/IEC 15417). A long code on a narrow label can only be printed too
 *    small, so the desk is told *before* it prints five hundred labels, and the
 *    warning stays on the screen — a warning printed on a label would ruin the
 *    label it is warning about.
 */
class LabelService
{
    /** The sheet geometries this application can lay out, in millimetres. */
    public const TEMPLATES = [
        'a4_3x8' => [
            'label' => 'A4 sheet, 24 labels (3 × 8, 70 × 37 mm)',
            'short' => '3 × 8',
            'page' => 'a4',
            'columns' => 3,
            'rows' => 8,
            'width_mm' => 70.0,
            'height_mm' => 37.0,
            'margin_left_mm' => 0.0,
            'margin_top_mm' => 0.0,
            'gap_x_mm' => 0.0,
            'gap_y_mm' => 0.0,
            'note' => 'The common shop sheet: 3 × 70 = 210 mm and 8 × 37 = 296 mm fill an A4 page with nothing to spare.',
        ],
        'a4_3x7' => [
            'label' => 'A4 sheet, 21 labels (3 × 7, 63.5 × 38.1 mm)',
            'short' => '3 × 7',
            'page' => 'a4',
            'columns' => 3,
            'rows' => 7,
            'width_mm' => 63.5,
            'height_mm' => 38.1,
            'margin_left_mm' => 7.0,
            'margin_top_mm' => 15.0,
            'gap_x_mm' => 2.5,
            'gap_y_mm' => 0.0,
            'note' => 'Avery-style L7160 sheet: 21 labels with a 2.5 mm gutter.',
        ],
        'thermal_50x25' => [
            'label' => 'Thermal roll, 50 × 25 mm',
            'short' => '50 × 25',
            'page' => 'roll',
            'columns' => 1,
            'rows' => 0,
            'width_mm' => 50.0,
            'height_mm' => 25.0,
            'margin_left_mm' => 0.0,
            'margin_top_mm' => 0.0,
            'gap_x_mm' => 0.0,
            'gap_y_mm' => 2.0,
            'note' => 'A shelf label roll: continuous paper, one label per row.',
        ],
        'thermal_38x25' => [
            'label' => 'Thermal roll, 38 × 25 mm',
            'short' => '38 × 25',
            'page' => 'roll',
            'columns' => 1,
            'rows' => 0,
            'width_mm' => 38.0,
            'height_mm' => 25.0,
            'margin_left_mm' => 0.0,
            'margin_top_mm' => 0.0,
            'gap_x_mm' => 0.0,
            'gap_y_mm' => 2.0,
            'note' => 'The narrow roll that fits a small box or a drawer front.',
        ],
    ];

    /** The paper size the sheet is laid out on, in millimetres. */
    public const PAGES = [
        'a4' => ['width_mm' => 210.0, 'height_mm' => 297.0, 'label' => 'A4 (210 × 297 mm)'],
        'roll' => ['width_mm' => null, 'height_mm' => null, 'label' => 'Continuous roll'],
    ];

    public const MAX_COPIES = 200;

    public const MAX_LABELS = 500;

    public function __construct(
        protected BarcodeService $barcode,
        protected QrService $qr,
        protected SettingService $settings,
        protected PricingService $pricing,
    ) {}

    /** The template key the operator chose, or the default when nothing is stored yet. */
    public function template(): string
    {
        $key = (string) $this->settings->get('labels', 'template', 'a4_3x8');

        return isset(self::TEMPLATES[$key]) ? $key : 'a4_3x8';
    }

    /** @return array<string, mixed> */
    public function templateDefinition(?string $key = null): array
    {
        $key ??= $this->template();

        if (! isset(self::TEMPLATES[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown label sheet [%s].', $key));
        }

        return self::TEMPLATES[$key] + ['key' => $key, 'page_definition' => self::PAGES[self::TEMPLATES[$key]['page']]];
    }

    /** How many labels one page holds (a roll holds as many as it is asked for). */
    public function perPage(?string $key = null): int
    {
        $definition = $this->templateDefinition($key);

        return $definition['page'] === 'roll'
            ? PHP_INT_MAX
            : $definition['columns'] * $definition['rows'];
    }

    /**
     * What one product's label says. The payload is the product's own code, in
     * the order a scanner and the app will both resolve: the barcode the shop
     * already uses, else the SKU, else the product code.
     *
     * @return array{type: string, id: int, code: string, payload: string, name: string, subtitle: ?string, price: float, unit: ?string, batch_no: ?string, expires_on: ?string, meta: array<string, mixed>}
     */
    public function productSubject(Product $product): array
    {
        $payload = $this->payloadFor($product);

        return [
            'type' => 'product',
            'id' => (int) $product->id,
            'code' => (string) $product->code,
            'payload' => $payload,
            'name' => (string) $product->name,
            'subtitle' => $product->sku !== null && $product->sku !== $payload ? (string) $product->sku : null,
            // The price a label shows is the price the till will charge: it is
            // resolved through the same pricing chain rather than read from a
            // column, so a promotion cannot make the shelf and the counter
            // disagree about the same box.
            'price' => $this->pricing->resolveUnitPrice($product),
            'unit' => $product->unit?->symbol ?: ($product->unit?->code ?? null),
            'batch_no' => null,
            'expires_on' => null,
            'meta' => [
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'source' => $product->barcode ? 'barcode' : ($product->sku ? 'sku' : 'code'),
            ],
        ];
    }

    /**
     * A batch label carries the batch, and shows the product and the expiry as
     * text beside it — a batch number alone is unreadable to the person holding
     * the box.
     *
     * @return array<string, mixed>
     */
    public function batchSubject(StockBatch $batch): array
    {
        $batch->loadMissing('product');
        $product = $batch->product;

        if ($product === null) {
            throw new RuntimeException(sprintf('Batch %s has no product, so there is nothing to label.', $batch->batch_no));
        }

        return [
            'type' => 'batch',
            'id' => (int) $batch->id,
            'code' => (string) $batch->batch_no,
            'payload' => (string) $batch->batch_no,
            'name' => (string) $product->name,
            'subtitle' => (string) $product->code,
            'price' => null,
            'unit' => null,
            'batch_no' => (string) $batch->batch_no,
            'expires_on' => $batch->expires_on?->toDateString(),
            'meta' => [
                'product_id' => (int) $product->id,
                'product_code' => (string) $product->code,
                'warehouse_id' => $batch->warehouse_id !== null ? (int) $batch->warehouse_id : null,
            ],
        ];
    }

    /**
     * A document label carries the document number — what is printed on the
     * paper and what a person can search for. Not the internal id: a scanner
     * cannot type a headcount into a search box, and the number is already the
     * document's public name.
     *
     * @return array<string, mixed>
     */
    public function documentSubject(SalesOrder|Invoice $document): array
    {
        $isInvoice = $document instanceof Invoice;

        return [
            'type' => $isInvoice ? 'invoice' : 'sales_order',
            'id' => (int) $document->id,
            'code' => (string) ($isInvoice ? $document->invoice_no : $document->order_no),
            'payload' => (string) ($isInvoice ? $document->invoice_no : $document->order_no),
            'name' => $isInvoice ? 'Sales invoice' : 'Sales order',
            'subtitle' => null,
            'price' => $isInvoice && $document->total !== null ? (float) $document->total : null,
            'unit' => null,
            'batch_no' => null,
            'expires_on' => null,
            'meta' => [
                'customer_id' => $document->customer_id !== null ? (int) $document->customer_id : null,
                'warehouse_id' => $document->warehouse_id !== null ? (int) $document->warehouse_id : null,
            ],
        ];
    }

    /**
     * Turn subjects into printable labels: the symbol, its size, and whether it
     * can be printed on this paper at all.
     *
     * Plain arrays in and out — no collections, no view state — so the geometry
     * can be exercised on its own and a sheet can be inspected as data.
     *
     * @param  array<int, array<string, mixed>>  $subjects
     * @param  array{copies?: int, template?: string, show_price?: ?bool, show_company?: ?bool, show_code_text?: ?bool, qr?: ?bool, qr_level?: ?string, company?: ?string}  $options
     * @return array{template: array<string, mixed>, labels: array<int, array<string, mixed>>, warnings: array<int, string>, totals: array<string, mixed>}
     */
    public function sheet(array $subjects, array $options = []): array
    {
        if ($subjects === []) {
            throw new InvalidArgumentException('A label sheet needs at least one item — printing empty paper helps nobody.');
        }

        $template = $this->templateDefinition($options['template'] ?? null);
        $copies = max(1, min(self::MAX_COPIES, (int) ($options['copies'] ?? $this->settings->getInt('labels', 'copies', 1))));

        if (count($subjects) * $copies > self::MAX_LABELS) {
            throw new InvalidArgumentException(sprintf(
                'That is %d labels; one sheet run accepts at most %d. Print it in two runs.',
                count($subjects) * $copies,
                self::MAX_LABELS,
            ));
        }

        $showPrice = $options['show_price'] ?? $this->settings->getBool('labels', 'show_price', true);
        $showCompany = $options['show_company'] ?? $this->settings->getBool('labels', 'show_company', true);
        $showCodeText = $options['show_code_text'] ?? $this->settings->getBool('labels', 'show_code_text', true);
        $withQr = $options['qr'] ?? $this->settings->getBool('labels', 'qr_on_label', false);
        $qrLevel = (string) ($options['qr_level'] ?? $this->settings->get('labels', 'qr_level', 'M'));
        $company = $options['company'] ?? null;

        // The symbol may use all but 3 mm of the label's width for its bars, and
        // the height left over once the name, the price and the code line have
        // taken their share.
        $availableMm = max(10.0, $template['width_mm'] - 6.0);
        $barsHeightMm = max(8.0, $template['height_mm'] * 0.42);

        // A QR beside the bars is paid for out of the bars' width — but the bars
        // are the part the warehouse scanner reads and they have a legal minimum
        // width, so it is the QR that gives way: it shrinks, and below the point
        // where a QR stops being readable at arm's length it is dropped with a
        // note, rather than quietly squeezing the barcode into something no
        // scanner will read.
        $qrMinimumMm = 7.0;
        $qrDropped = [];

        $labels = [];
        $warnings = [];

        foreach ($subjects as $subject) {
            $encoded = $this->barcode->encode($subject['payload']);

            $modulesWithQuiet = $encoded['modules'] + 2 * BarcodeService::QUIET_ZONE_MODULES;
            $minimumBarsMm = $modulesWithQuiet * BarcodeService::MIN_MODULE_MM;

            $barsWidthMm = $availableMm;
            $qrSizeMm = 0.0;

            if ($withQr) {
                $wanted = $availableMm - $barsHeightMm - 2.0;

                $barsWidthMm = max($minimumBarsMm, min($availableMm, $wanted));
                $qrSizeMm = $availableMm - $barsWidthMm - 2.0;

                if ($qrSizeMm < $qrMinimumMm) {
                    // No room for both: keep the bars, drop the QR, and say so.
                    $barsWidthMm = $availableMm;
                    $qrSizeMm = 0.0;
                    $qrDropped[$subject['name']] = true;
                }
            }

            $moduleMm = $barsWidthMm / $modulesWithQuiet;
            $tooNarrow = $moduleMm < BarcodeService::MIN_MODULE_MM;

            $svg = $this->barcode->svg($subject['payload'], [
                'moduleWidth' => 1,
                'height' => 1,
                'showText' => false,
                'stretch' => true,
                'class' => 'erp-label-barcode',
            ])['svg'];

            if ($tooNarrow) {
                $warnings[] = sprintf(
                    '%s: a %d-module code needs %.1f mm at the smallest printable bar, but this label leaves %.1f mm. Use a wider label, or encode a shorter code for this product.',
                    $subject['name'],
                    $modulesWithQuiet,
                    $minimumBarsMm,
                    $barsWidthMm,
                );
            }

            for ($copy = 1; $copy <= $copies; $copy++) {
                $labels[] = $subject + [
                    'copy' => $copy,
                    'barcode_svg' => $svg,
                    'qr_svg' => $qrSizeMm > 0 ? $this->qr->svg($subject['payload'], ['level' => $qrLevel, 'moduleSize' => 1, 'stretch' => true])['svg'] : null,
                    'qr_level' => $qrSizeMm > 0 ? $qrLevel : null,
                    'symbology' => $encoded['symbology'],
                    'subset' => $encoded['subset'],
                    'checksum' => $encoded['checksum'],
                    'modules' => $encoded['modules'],
                    'module_mm' => round($moduleMm, 4),
                    'bars_width_mm' => round($barsWidthMm, 2),
                    'bars_height_mm' => round($barsHeightMm, 2),
                    'qr_size_mm' => round($qrSizeMm, 2),
                    'too_narrow' => $tooNarrow,
                    'show_price' => (bool) $showPrice,
                    'show_company' => (bool) $showCompany,
                    'show_code_text' => (bool) $showCodeText,
                    'company' => $company,
                ];
            }
        }

        foreach (array_keys($qrDropped) as $name) {
            $warnings[] = sprintf(
                '%s: there is no room for a readable barcode and a QR on a %.0f mm label, so the QR was left off — the bars are what the warehouse scanner reads.',
                $name,
                $template['width_mm'],
            );
        }

        $modules = array_column($labels, 'module_mm');
        $narrow = count(array_filter($labels, fn (array $label) => $label['too_narrow'] === true));
        $perPage = $this->perPage($template['key']);

        return [
            'template' => $template,
            'labels' => $labels,
            'warnings' => array_values(array_unique($warnings)),
            'totals' => [
                'labels' => count($labels),
                'unique' => count($subjects),
                'copies' => $copies,
                'per_page' => $perPage === PHP_INT_MAX ? count($labels) : $perPage,
                'pages' => $perPage === PHP_INT_MAX ? 1 : (int) ceil(count($labels) / max(1, $perPage)),
                'qr' => $withQr,
                'narrowest_module_mm' => $modules === [] ? 0.0 : round(min($modules), 3),
                'unprintable' => $narrow,
            ],
        ];
    }

    /**
     * Split a sheet's labels into pages for the view.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function pages(array $sheet): array
    {
        return array_chunk($sheet['labels'], $sheet['totals']['per_page']);
    }

    /** The code a scanner should read back for this product. */
    public function payloadFor(Product $product): string
    {
        foreach ([$product->barcode, $product->sku, $product->code] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('Product #%d has no barcode, SKU or code, so a label could not say anything about it.', $product->id));
    }
}
