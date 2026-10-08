<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PrintHistory;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\BarcodeService;
use App\Domain\Inventory\Services\LabelService;
use App\Domain\Inventory\Services\QrService;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockBatch;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use App\Domain\Settings\Services\SettingService;
use App\Http\Requests\GenerateLabelsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §04-13/04-52/04-53/04-54 — barcodes, QR codes and the labels they go on.
 *
 * Four screens, one job each:
 *
 *  · **the desk** (`index`) — choose what to label, how many copies, on which
 *    paper, then generate the sheet. A generated sheet is a real document: the
 *    HTML is written to disk with its checksum and the print is logged, exactly
 *    like an invoice, so "we printed labels for these" is answerable later.
 *  · **the generators** (`barcodes`, `qrCodes`) — make one symbol for one thing
 *    and show what it says: the payload, the subset, the computed check digit,
 *    how many modules wide it is and whether it fits the paper. This is the
 *    screen to look at *before* printing five hundred labels.
 *  · **the scanner** (`scanner`) — prove that the scanner on the counter reads
 *    our codes and resolves to the right row, using the same lookup the desk uses.
 *  · **the endpoints** (`productBarcode`, `productQr`) — the symbol as SVG, so a
 *    product screen can show it with an `<img>` and a browser can save it.
 *
 * Nothing here invents a payload: what a label encodes is the thing's own code
 * (§LabelService), and nothing here writes stock.
 */
class InventoryLabelController extends Controller
{
    public function __construct(
        protected LabelService $labels,
        protected BarcodeService $barcode,
        protected QrService $qr,
        protected DocumentRenderer $renderer,
        protected AuditRecorder $audit,
        protected SettingService $settings,
        protected TenantContext $context,
    ) {}

    /** The label desk (§04-52/04-53): what to label, and every sheet printed so far. */
    public function index(Request $request): View
    {
        $companyId = $this->companyId();

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('unit:id,code,symbol')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'sku', 'barcode', 'name', 'is_stocked', 'unit_id']);

        $batches = StockBatch::query()
            ->where('company_id', $companyId)
            ->with(['product:id,code,name', 'warehouse:id,name'])
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'product_id', 'warehouse_id', 'batch_no', 'expires_on']);

        return view('inventory.labels.index', [
            'products' => $products,
            'batches' => $batches,
            'orders' => SalesOrder::query()
                ->where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'order_no', 'customer_id']),
            'invoices' => Invoice::query()
                ->where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'invoice_no', 'customer_id']),
            'sheets' => $this->recentSheets(),
            'template' => $this->labels->templateDefinition(),
            'templates' => LabelService::TEMPLATES,
            'defaults' => $this->defaults(),
            'history' => $this->printHistory(),
        ]);
    }

    /** Render, file and log a sheet (§04-52). */
    public function generate(GenerateLabelsRequest $request): RedirectResponse
    {
        $companyId = $this->companyId();
        $type = (string) $request->validated('subject_type');
        $ids = $request->subjectIds();

        $subjects = $this->subjects($type, $ids, $companyId);

        if ($subjects === []) {
            return back()->withInput()->withErrors([
                $request->inputKey() => 'None of the ticked items belong to this company, so there is nothing to label.',
            ]);
        }

        try {
            $sheet = $this->labels->sheet($subjects, $request->sheetOptions($this->companyName() ?? ''));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors([$request->inputKey() => $e->getMessage()]);
        }

        $sheet['company_id'] = $companyId;
        $sheet['generated_at'] = now()->toDateTimeString();
        $sheet['generated_by'] = (string) $request->user()->name;

        // No branch in context means a company-wide sheet, which is stored with a
        // NULL branch and stays visible from every branch — never branch 0, which
        // would be a row that exists but belongs nowhere.
        $document = $this->renderer->renderLabelSheet(
            $sheet,
            $this->context->branchId() !== null ? (int) $this->context->branchId() : null,
            $request->user(),
        );

        $this->renderer->recordPrint($document, 'label_sheet', $request, (string) $request->attributes->get('correlation_id'));

        $this->audit->record([
            'action' => 'inventory.label_sheet_generated',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'branch_id' => $document->branch_id,
            'actor_id' => $request->user()->id,
            'after' => [
                'subject_type' => $type,
                'subject_ids' => $ids,
                'labels' => $sheet['totals']['labels'],
                'pages' => $sheet['totals']['pages'],
                'template' => $sheet['template']['key'],
                'copies' => $sheet['totals']['copies'],
                'qr' => $sheet['totals']['qr'],
                'narrowest_module_mm' => $sheet['totals']['narrowest_module_mm'],
                'unprintable' => $sheet['totals']['unprintable'],
                'document_id' => $document->id,
            ],
            'reason' => null,
        ]);

        $status = sprintf(
            'Filed %d label(s) on %d page(s) — %.2f mm per bar at the narrowest.',
            $sheet['totals']['labels'],
            $sheet['totals']['pages'],
            $sheet['totals']['narrowest_module_mm'],
        );

        if ($sheet['totals']['unprintable'] > 0) {
            $status .= ' '.$sheet['totals']['unprintable'].' of them cannot be printed on this paper; the reasons are below.';
        }

        // Back to the desk, not straight to the sheet: the warnings are what the
        // operator has to read *before* the paper is committed, and they only
        // exist while the run is fresh.
        return redirect()
            ->route('inventory.labels.index')
            ->with('status', $status)
            ->with('label_sheet_id', $document->id)
            ->with('label_warnings', $sheet['warnings']);
    }

    /** The filed sheet, exactly as it was stored (that file is what prints). */
    public function sheet(Request $request, int $labelSheet): Response
    {
        $document = $this->document($labelSheet);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404, 'The stored sheet is missing from the disk.');

        $content = (string) $disk->get($document->path);

        $this->audit->record([
            'action' => 'inventory.label_sheet_viewed',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'branch_id' => $document->branch_id,
            'actor_id' => $request->user()->id,
            'after' => ['document_id' => $document->id, 'checksum' => $document->checksum],
            'reason' => null,
        ]);

        return response($content, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$document->original_name.'"',
            'X-Label-Sheet-Checksum' => (string) $document->checksum,
        ]);
    }

    /** Log another print of a sheet that already exists (Rule 16). */
    public function print(Request $request, int $labelSheet): RedirectResponse
    {
        $document = $this->document($labelSheet);
        $copies = max(1, min(LabelService::MAX_COPIES, (int) $request->input('copies', 1)));

        PrintHistory::query()->create([
            'company_id' => $document->company_id,
            'document_type_id' => $document->document_type_id,
            'printable_type' => Document::class,
            'printable_id' => $document->id,
            'format' => 'html',
            'user_id' => $request->user()->id,
            'ip' => (string) $request->ip(),
            'correlation_id' => (string) $request->attributes->get('correlation_id'),
            'copies' => $copies,
        ]);

        // Back to the desk, not to the sheet: the sheet answers with the bytes that
        // were filed, so a flash message sent there would never be seen.
        return redirect()
            ->route('inventory.labels.index')
            ->with('status', 'Printing recorded — '.$copies.' cop'.($copies === 1 ? 'y' : 'ies').' of '.$document->original_name.'.');
    }

    /** One barcode, with everything a printer would want to know (§04-13/04-52). */
    public function barcodes(Request $request): View
    {
        return view('inventory.labels.barcodes', $this->generator($request, 'barcode'));
    }

    /** One QR code, with its payload shown in full (§04-13/04-52). */
    public function qrCodes(Request $request): View
    {
        return view('inventory.labels.qr-codes', $this->generator($request, 'qr'));
    }

    /** The scanner test bench (§04-54) — does the counter's scanner read our labels? */
    public function scanner(Request $request): View
    {
        $scan = $request->query('code') !== null ? trim((string) $request->query('code')) : null;

        return view('inventory.labels.scanner', [
            'settings' => $this->defaults(),
            'scan' => $scan,
            'resolution' => $scan !== null && $scan !== '' ? $this->resolve($scan, $this->companyId()) : null,
            'templates' => LabelService::TEMPLATES,
            'recent' => $this->recentSheets(5),
        ]);
    }

    /** Resolve a scanned code to the row it belongs to (used by the desk and the test bench). */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code'));

        if ($code === '') {
            return response()->json(['found' => false, 'message' => 'Scan or type a code first.'], 200);
        }

        return response()->json($this->resolve($code, $this->companyId()), 200);
    }

    /** A product's barcode as SVG (§04-13). */
    public function productBarcode(Request $request, int $product): Response
    {
        $row = $this->product($product);
        $payload = $this->labels->payloadFor($row);

        $svg = $this->barcode->svg($payload, [
            'moduleWidth' => (float) $request->query('module', 2),
            'height' => (float) $request->query('height', 64),
            'showText' => $request->query('text', '1') !== '0',
            'title' => $row->name.' — '.$payload,
        ]);

        return $this->svgResponse($svg['svg'], 'barcode-'.$row->code.'.svg');
    }

    /** A product's QR code as SVG (§04-13). */
    public function productQr(Request $request, int $product): Response
    {
        $row = $this->product($product);
        $payload = $this->labels->payloadFor($row);

        $svg = $this->qr->svg($payload, [
            'level' => (string) $request->query('level', (string) $this->settings->get('labels', 'qr_level', 'M')),
            'moduleSize' => (float) $request->query('module', 4),
            'title' => $row->name.' — '.$payload,
        ]);

        return $this->svgResponse($svg['svg'], 'qr-'.$row->code.'.svg');
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * The chosen rows, scoped to the company *here* rather than by route binding:
     * products have no global company scope in this application, so a bound model
     * would happily hand over another company's row.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    protected function subjects(string $type, array $ids, int $companyId): array
    {
        if ($ids === []) {
            return [];
        }

        if ($type === 'product') {
            return Product::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => $this->labels->productSubject($product))
                ->all();
        }

        if ($type === 'order') {
            return SalesOrder::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->get()
                ->map(fn (SalesOrder $order) => $this->labels->documentSubject($order))
                ->all();
        }

        if ($type === 'invoice') {
            return Invoice::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->get()
                ->map(fn (Invoice $invoice) => $this->labels->documentSubject($invoice))
                ->all();
        }

        if ($type === 'batch') {
            return StockBatch::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->with('product')
                ->orderBy('batch_no')
                ->get()
                ->map(fn (StockBatch $batch) => $this->labels->batchSubject($batch))
                ->all();
        }

        return [];
    }

    /** @return array<string, mixed> */
    protected function generator(Request $request, string $kind): array
    {
        $companyId = $this->companyId();
        $subject = null;

        // Whatever was asked for — a product, a batch or a raw code — becomes one
        // subject, and the screen shows exactly what is about to be encoded.
        if ($request->query('product') !== null) {
            $subject = $this->labels->productSubject($this->product((int) $request->query('product')));
        } elseif ($request->query('batch') !== null) {
            $batch = StockBatch::query()
                ->where('company_id', $companyId)
                ->with('product')
                ->find((int) $request->query('batch'));

            if ($batch !== null) {
                $subject = $this->labels->batchSubject($batch);
            }
        } elseif ($request->query('order') !== null) {
            $order = SalesOrder::query()->where('company_id', $companyId)->find((int) $request->query('order'));

            if ($order !== null) {
                $subject = $this->labels->documentSubject($order);
            }
        } elseif ($request->query('invoice') !== null) {
            $invoice = Invoice::query()->where('company_id', $companyId)->find((int) $request->query('invoice'));

            if ($invoice !== null) {
                $subject = $this->labels->documentSubject($invoice);
            }
        } elseif (trim((string) $request->query('code')) !== '') {
            $code = trim((string) $request->query('code'));

            $subject = [
                'type' => 'manual',
                'id' => 0,
                'code' => $code,
                'payload' => $code,
                'name' => 'Typed code',
                'subtitle' => null,
                'price' => 0.0,
                'unit' => null,
                'batch_no' => null,
                'expires_on' => null,
                'meta' => ['source' => 'typed'],
            ];
        }

        $template = $this->labels->templateDefinition($request->query('template') !== null ? (string) $request->query('template') : null);
        $payload = $subject['payload'] ?? null;
        $encoded = null;
        $symbol = null;
        $error = null;

        if (is_string($payload) && $payload !== '') {
            try {
                if ($kind === 'qr') {
                    $level = strtoupper((string) $request->query('level', (string) $this->settings->get('labels', 'qr_level', 'M')));
                    $level = in_array($level, QrService::LEVELS, true) ? $level : 'M';

                    $result = $this->qr->svg($payload, ['level' => $level, 'moduleSize' => 4, 'title' => $payload]);
                    $encoded = $result['meta'];
                    $symbol = $result['svg'];
                } else {
                    $result = $this->barcode->svg($payload, ['moduleWidth' => 2, 'height' => 70, 'title' => $payload]);
                    $encoded = $result['meta'];
                    $symbol = $result['svg'];
                }
            } catch (RuntimeException|\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        $fit = null;

        if ($encoded !== null && $kind === 'barcode') {
            $available = max(10.0, $template['width_mm'] - 6.0);
            $moduleMm = $available / $encoded['modules'];

            $fit = [
                'label_width_mm' => $template['width_mm'],
                'available_mm' => round($available, 2),
                'module_mm' => round($moduleMm, 4),
                'prints' => $moduleMm >= BarcodeService::MIN_MODULE_MM,
                'minimum_mm' => BarcodeService::MIN_MODULE_MM,
                'needed_mm' => round($encoded['modules'] * BarcodeService::MIN_MODULE_MM, 1),
            ];
        }

        return [
            'kind' => $kind,
            'subject' => $subject,
            'payload' => $payload,
            'encoded' => $encoded,
            'symbol' => $symbol,
            'error' => $error,
            'fit' => $fit,
            'template' => $template,
            'templates' => LabelService::TEMPLATES,
            'levels' => QrService::LEVELS,
            'defaults' => $this->defaults(),
            'products' => Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'code', 'name']),
            'recent' => $this->recentSheets(5),
        ];
    }

    /** The sheets generated most recently, newest first. */
    protected function recentSheets(int $limit = 10): Collection
    {
        $typeId = DocumentType::query()->where('code', 'label_sheet')->value('id');

        if ($typeId === null) {
            return collect();
        }

        return Document::query()
            ->where('company_id', $this->companyId())
            ->where('document_type_id', $typeId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'original_name', 'size_bytes', 'checksum', 'created_at', 'uploaded_by', 'branch_id']);
    }

    /** Every recorded print of a label sheet, newest first (Rule 16). */
    protected function printHistory(int $limit = 15): Collection
    {
        $typeId = DocumentType::query()->where('code', 'label_sheet')->value('id');

        if ($typeId === null) {
            return collect();
        }

        return PrintHistory::query()
            ->where('company_id', $this->companyId())
            ->where('document_type_id', $typeId)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'printable_id', 'copies', 'user_id', 'ip', 'created_at']);
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'template' => $this->labels->template(),
            'copies' => $this->settings->getInt('labels', 'copies', 1),
            'show_price' => $this->settings->getBool('labels', 'show_price', true),
            'show_company' => $this->settings->getBool('labels', 'show_company', true),
            'show_code_text' => $this->settings->getBool('labels', 'show_code_text', true),
            'qr' => $this->settings->getBool('labels', 'qr_on_label', false),
            'qr_level' => (string) $this->settings->get('labels', 'qr_level', 'M'),
            'scan_mode' => (string) $this->settings->get('barcode', 'scan_mode', 'keyboard_wedge'),
            'scan_min_length' => $this->settings->getInt('barcode', 'scan_min_length', 4),
            'scan_clear_ms' => $this->settings->getInt('barcode', 'scan_clear_ms', 120),
            'scan_beep' => $this->settings->getBool('barcode', 'scan_beep', true),
            'scan_autofocus' => $this->settings->getBool('barcode', 'scan_autofocus', true),
        ];
    }

    /** The stored sheet, scoped to this company and to the label-sheet type. */
    protected function document(int $id): Document
    {
        $typeId = DocumentType::query()->where('code', 'label_sheet')->value('id');

        $document = Document::query()
            ->where('company_id', $this->companyId())
            ->where('id', $id)
            ->when($typeId !== null, fn ($q) => $q->where('document_type_id', $typeId))
            ->first();

        abort_if($document === null, 404, 'That label sheet does not exist in this company.');

        return $document;
    }

    /**
     * What a scanned code means, or the reason it means nothing.
     *
     * A barcode is looked up against the barcode column *and* the SKU and the
     * code, because the three are what a label can carry and what a counter can
     * be handed — a code that resolves is one a scanner can be trusted with.
     *
     * @return array<string, mixed>
     */
    protected function resolve(string $code, int $companyId): array
    {
        $product = Product::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('code', $code);
            })
            ->first(['id', 'code', 'sku', 'barcode', 'name', 'is_active', 'is_stocked']);

        if ($product !== null) {
            return [
                'found' => true,
                'kind' => 'product',
                'label' => $product->name,
                'code' => $product->code,
                'matched_on' => $product->barcode === $code ? 'barcode' : ($product->sku === $code ? 'SKU' : 'product code'),
                'detail' => $this->stockSummary($product, $companyId),
                'href' => route('inventory.labels.barcodes', ['product' => $product->id]),
                'product_id' => $product->id,
            ];
        }

        $batch = StockBatch::query()
            ->where('company_id', $companyId)
            ->where('batch_no', $code)
            ->with('product:id,code,name')
            ->first(['id', 'batch_no', 'product_id', 'expires_on']);

        if ($batch !== null) {
            return [
                'found' => true,
                'kind' => 'batch',
                'label' => (string) $batch->product?->name,
                'code' => (string) $batch->batch_no,
                'matched_on' => 'batch number',
                'detail' => $batch->expires_on !== null
                    ? 'Expires '.$batch->expires_on->toDateString()
                    : 'No expiry date recorded on this lot',
                'href' => route('inventory.labels.barcodes', ['batch' => $batch->id]),
                'product_id' => null,
            ];
        }

        return [
            'found' => false,
            'message' => sprintf('No product or batch in this company carries the code "%s".', $code),
            'code' => $code,
        ];
    }

    /** A product of ours, or a 404 — never another company's row. */
    protected function product(int $id): Product
    {
        $product = Product::query()
            ->where('company_id', $this->companyId())
            ->with('unit:id,code,symbol')
            ->find($id);

        abort_if($product === null, 404, 'That product does not exist in this company.');

        return $product;
    }

    protected function stockSummary(Product $product, int $companyId): string
    {
        $onHand = (float) StockBalance::query()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->sum('on_hand');

        $state = [];

        if ($product->is_active === false) {
            $state[] = 'inactive';
        }

        if ($product->is_stocked === false) {
            $state[] = 'not stock-managed';
        }

        return sprintf(
            'On hand %s%s',
            rtrim(rtrim(number_format($onHand, 4, '.', ''), '0'), '.') ?: '0',
            $state === [] ? '' : ' · '.implode(', ', $state),
        );
    }

    protected function svgResponse(string $svg, string $filename): Response
    {
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    protected function companyId(): int
    {
        $companyId = $this->context->companyId();

        abort_if($companyId === null, 500, 'No company context.');

        return (int) $companyId;
    }

    protected function companyName(): ?string
    {
        return $this->context->company()?->name;
    }
}
