<?php

namespace App\Domain\Reporting;

use App\Domain\Documents\Document;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued order export (02-13). Scope is fixed at creation time
 * (company + the requester's branch visibility); the produced CSV is
 * filed as a `generated` document so the download is auditable.
 */
class OrderExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'user_id', 'status', 'filters', 'row_count',
        'document_id', 'error', 'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'row_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
