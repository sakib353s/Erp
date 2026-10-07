<?php

namespace App\Domain\Delivery;

use App\Domain\Documents\Document;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 02-95 Proof of delivery: one row per delivered shipment. Signature
 * and photo are documents rows from the safe upload pipeline (never
 * raw bytes in this table); receiver name carries the human record.
 */
class ProofOfDelivery extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'branch_id', 'shipment_id', 'sales_order_id',
        'delivered_at', 'receiver_name', 'notes',
        'signature_document_id', 'photo_document_id', 'recorded_by',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function signatureDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signature_document_id');
    }

    public function photoDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'photo_document_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
