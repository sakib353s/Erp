<?php

namespace App\Domain\Business;

use App\Domain\Documents\Document;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-03/04/09/10 — a paper filed against a record.
 *
 * The bytes are never copied: this row points at a `documents` row, which means
 * the scan of the trade licence keeps the library's MIME sniffing, checksum,
 * audit trail and virus-scan status, and the register keeps a label that says
 * what the file is doing there ("2026 renewal", "signed page 4").
 */
class BusinessRecordFile extends Model
{
    protected $fillable = [
        'company_id', 'business_record_id', 'document_id', 'label', 'attached_by',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(BusinessRecord::class, 'business_record_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function attacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by');
    }

    /** What the attachment line says when nobody gave it a label. */
    public function label(): string
    {
        return $this->label ?: ($this->document?->original_name ?? 'Attachment');
    }
}
