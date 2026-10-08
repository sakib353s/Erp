<?php

namespace App\Domain\Documents;

use App\Domain\Delivery\ShippingLabel;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Services\QrService;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\InvoiceVerificationService;
use App\Domain\Settings\Services\LocalizationService;
use App\Domain\Tax\Services\TaxPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders printable documents and files them (02-07/02-08): the HTML is
 * stored as a private `generated` document row (checksum + size are
 * computed from the real bytes — no placeholders) and every print is
 * logged to print_history with the acting user, IP and document type.
 * Format is html because no PDF engine is installed — never claim pdf.
 */
class DocumentRenderer
{
    public function __construct(
        protected LocalizationService $localization,
        protected TaxPolicy $taxPolicy,
        protected InvoiceVerificationService $verification,
        protected QrService $qr,
    ) {}

    public function renderInvoice(Invoice $invoice, User $user): Document
    {
        $invoice->loadMissing(['lines.product', 'customer', 'company']);

        // §15-07: the print obeys the company's (or the branch's) localization
        // switches — grouping, numerals and whether the amount is spelled out.
        // §16-19: the verification address for the QR symbol, and the symbol
        // itself. Null when nothing has been published — the paper then carries
        // no code claiming a check that would fail.
        $verificationUrl = $this->verification->url($invoice);

        $html = view('sales.invoices.print', [
            'invoice' => $invoice,
            'localization' => $this->localization,
            // §15-14: what the document may claim about its own totals.
            'taxInclusive' => $this->taxPolicy->pricesIncludeTax(),
            'verificationUrl' => $verificationUrl,
            'verificationQr' => $verificationUrl === null ? null : $this->qr->svg($verificationUrl, [
                'moduleSize' => 3,
                'title' => 'Verify '.(string) $invoice->invoice_no,
            ])['svg'],
        ])->render();

        return $this->storeGenerated(
            $html,
            companyId: (int) $invoice->company_id,
            directory: 'invoices',
            basename: str_replace(['/', '\\'], '-', (string) $invoice->invoice_no),
            owner: $invoice,
            typeCode: 'invoice',
            user: $user,
        );
    }

    public function renderPackingSlip(SalesOrder $order, User $user): Document
    {
        $order->loadMissing(['lines.product', 'customer', 'company', 'warehouse']);

        $html = view('sales.orders.packing-slip', ['order' => $order])->render();

        return $this->storeGenerated(
            $html,
            companyId: (int) $order->company_id,
            directory: 'packing-slips',
            basename: str_replace(['/', '\\'], '-', (string) $order->order_no),
            owner: $order,
            typeCode: 'packing_slip',
            user: $user,
        );
    }

    public function renderShippingLabel(ShippingLabel $label, SalesOrder $order, User $user): Document
    {
        $order->loadMissing(['company']);

        $html = view('sales.orders.shipping-label', [
            'label' => $label->loadMissing(['courier', 'shipment']),
            'order' => $order,
        ])->render();

        return $this->storeGenerated(
            $html,
            companyId: (int) $label->company_id,
            directory: 'shipping-labels',
            basename: str_replace(['/', '\\'], '-', (string) $label->label_no),
            owner: $label,
            typeCode: 'shipping_label',
            user: $user,
        );
    }

    /**
     * §04-53 — a printed label sheet, filed like any other generated document.
     *
     * The sheet is rendered from the real sheet data (the same array the desk
     * previewed), so what is stored and checksummed is exactly what the printer
     * receives. It is stored under the company directory with no number of its
     * own: label sheets are not registered documents, and the codes printed on
     * them belong to the products and batches being labelled. The branch is
     * nullable — a sheet generated with no branch in context is company-wide and
     * says so rather than pointing at a branch it was not printed in.
     *
     * @param  array<string, mixed>  $sheet
     */
    public function renderLabelSheet(array $sheet, ?int $branchId, User $user, ?string $basename = null): Document
    {
        $html = view('inventory.labels.sheet', ['sheet' => $sheet])->render();

        return $this->storeGenerated(
            $html,
            companyId: (int) $sheet['company_id'],
            directory: 'labels',
            basename: $basename ?? 'labels-'.now()->format('Ymd-His'),
            owner: null,
            typeCode: 'label_sheet',
            user: $user,
            branchId: $branchId,
        );
    }

    /**
     * Rule 16: every print is attributable — user, IP, type, format, copies.
     *
     * §16-25 adds what the sheet itself carried: the filed copy it was rendered
     * from, the title that appeared on it, the paper it was printed on, the copy
     * locale, any watermark and the checksum of the bytes. A history row that
     * cannot be matched against the paper in somebody's hand is a log of clicks,
     * not a record of documents.
     *
     * @param  ?string  $printableType  set when the subject is not an entity (a report)
     */
    public function recordPrint(
        Model $printable,
        string $typeCode,
        Request $request,
        ?string $correlationId = null,
        ?Document $document = null,
        ?string $title = null,
        string $pageFormat = 'a4',
        string $locale = 'en',
        ?string $watermark = null,
        ?string $checksum = null,
        int $copies = 1,
        ?string $printableType = null,
        ?int $printableId = null,
    ): PrintHistory {
        $type = DocumentType::query()->where('code', $typeCode)->first();

        return PrintHistory::query()->create([
            'company_id' => $printable->getAttribute('company_id'),
            'document_type_id' => $type?->id,
            'document_id' => $document?->id,
            'printable_type' => $printableType ?? $printable::class,
            'printable_id' => $printableId ?? $printable->getKey(),
            'format' => 'html',
            'printed_title' => $title ?? $type?->printed_title,
            'page_format' => $pageFormat,
            'locale' => $locale,
            'watermark' => $watermark,
            'checksum' => $checksum ?? $document?->checksum,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'copies' => $copies,
        ]);
    }

    /**
     * The owner may be null: a label sheet is generated *for* many rows at once
     * (twelve products, three batches) and none of them owns it, so saying so is
     * more honest than picking the first one and naming it the owner. In that
     * case the caller states the branch the sheet was printed in.
     */
    public function storeGenerated(
        string $content,
        int $companyId,
        string $directory,
        string $basename,
        ?Model $owner,
        ?string $typeCode,
        User $user,
        string $mime = 'text/html',
        string $extension = 'html',
        ?int $branchId = null,
    ): Document {
        $originalName = $basename.'-'.substr(sha1((string) ($owner?->getKey() ?? $basename)), 0, 8).'.'.$extension;
        $path = sprintf('generated/%d/%s/%s', $companyId, $directory, $originalName);

        Storage::disk('local')->put($path, $content);

        $type = $typeCode !== null
            ? DocumentType::query()->where('code', $typeCode)->first()
            : null;

        return Document::query()->create([
            'company_id' => $companyId,
            'branch_id' => $owner?->getAttribute('branch_id') ?? $branchId,
            'owner_type' => $owner !== null ? $owner::class : null,
            'owner_id' => $owner?->getKey(),
            'document_type_id' => $type?->id,
            'purpose' => 'generated',
            'visibility' => 'private',
            'disk' => 'local',
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
            'uploaded_by' => $user->id,
        ]);
    }
}
