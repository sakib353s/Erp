<?php

namespace App\Domain\Documents;

use App\Domain\Delivery\ShippingLabel;
use App\Domain\Foundation\User;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
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
    public function renderInvoice(Invoice $invoice, User $user): Document
    {
        $invoice->loadMissing(['lines.product', 'customer', 'company']);

        $html = view('sales.invoices.print', ['invoice' => $invoice])->render();

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

    /** Rule 16: every print is attributable — user, IP, type, format, copies. */
    public function recordPrint(Model $printable, string $typeCode, Request $request, ?string $correlationId = null): PrintHistory
    {
        $type = DocumentType::query()->where('code', $typeCode)->first();

        return PrintHistory::query()->create([
            'company_id' => $printable->getAttribute('company_id'),
            'document_type_id' => $type?->id,
            'printable_type' => $printable::class,
            'printable_id' => $printable->getKey(),
            'format' => 'html',
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'copies' => 1,
        ]);
    }

    public function storeGenerated(
        string $content,
        int $companyId,
        string $directory,
        string $basename,
        Model $owner,
        ?string $typeCode,
        User $user,
        string $mime = 'text/html',
        string $extension = 'html',
    ): Document {
        $originalName = $basename.'-'.substr(sha1((string) $owner->getKey()), 0, 8).'.'.$extension;
        $path = sprintf('generated/%d/%s/%s', $companyId, $directory, $originalName);

        Storage::disk('local')->put($path, $content);

        $type = $typeCode !== null
            ? DocumentType::query()->where('code', $typeCode)->first()
            : null;

        return Document::query()->create([
            'company_id' => $companyId,
            'branch_id' => $owner->getAttribute('branch_id'),
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
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
