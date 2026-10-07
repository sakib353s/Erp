<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scored suspicion flag on a sales order (02-28). Score + rules are the
 * scorer's explainability snapshot; decision/review_* are the human
 * review outcome. A reviewed flag is never re-scored or deleted — the
 * order itself is never touched by any review action.
 */
class SuspiciousOrderFlag extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const DECISION_LEGITIMATE = 'confirmed_legitimate';

    public const DECISION_SUSPICIOUS = 'confirmed_suspicious';

    protected $fillable = [
        'company_id', 'sales_order_id', 'score', 'level', 'rules',
        'status', 'decision', 'review_notes', 'reviewed_by', 'reviewed_at',
        'scored_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'rules' => 'array',
        'reviewed_at' => 'datetime',
        'scored_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
