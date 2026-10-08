<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §16-18 — what the customer said afterwards, and what was done about it.
 *
 * A claim is a life cycle rather than an edit: it opens, a desk decides, and it
 * closes with a resolution and — where money moved — a cost. The warranty it
 * belongs to is never modified by a claim; the cover is a fact about dates.
 *
 * `resolution` is deliberately separate from `status` because they answer
 * different questions: *where is this* (open, processing, completed) and *what
 * was agreed* (repair, replace, refund, rejected). A completed claim with no
 * resolution would be a claim that ended without anybody deciding anything.
 */
class WarrantyClaim extends Model
{
    use Auditable;

    public const STATUSES = [
        'open' => 'Open — just reported',
        'processing' => 'Being looked at',
        'approved' => 'Approved',
        'rejected' => 'Refused',
        'completed' => 'Closed',
    ];

    public const RESOLUTIONS = [
        'repair' => 'Repaired',
        'replace' => 'Replaced',
        'refund' => 'Refunded',
        'reject' => 'Not covered',
    ];

    /** The states a claim can still be decided from. */
    public const OPEN_STATES = ['open', 'processing', 'approved'];

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'warranty_id', 'reported_on', 'fault',
        'status', 'resolution', 'resolved_on', 'resolved_by', 'resolution_notes',
        'cost', 'service_invoice_id', 'created_by',
    ];

    protected $casts = [
        'reported_on' => 'date',
        'resolved_on' => 'date',
        'cost' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function serviceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'service_invoice_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function resolutionLabel(): string
    {
        return $this->resolution === null
            ? '—'
            : (self::RESOLUTIONS[$this->resolution] ?? (string) $this->resolution);
    }

    /** Claims still needing somebody: the working queue. */
    public function scopeWorking(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'processing']);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
