<?php

namespace App\Domain\Business;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-09 — the history under a record: it was renewed, filed, attached, retired.
 *
 * The audit trail already records that something changed; this is the register's
 * own reading of it, in the register's words, kept next to the record so a
 * licence's page can show "renewed on 12 March 2026 for BDT 6,000, previous
 * expiry 30 June 2026" without anybody parsing an audit payload. Renewals are
 * the reason it exists: an expiry date that only ever gets overwritten loses the
 * fact that it was ever later, and that fact is the whole point of keeping the
 * register.
 */
class BusinessRecordEvent extends Model
{
    public const ACTIONS = [
        'created' => 'Created',
        'updated' => 'Updated',
        'renewed' => 'Renewed',
        'completed' => 'Completed',
        'attached' => 'File attached',
        'detached' => 'File detached',
        'retired' => 'Retired',
    ];

    protected $fillable = [
        'company_id', 'business_record_id', 'action', 'happened_on', 'note', 'meta', 'actor_id',
    ];

    protected $casts = [
        'happened_on' => 'date',
        'meta' => 'array',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(BusinessRecord::class, 'business_record_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst((string) $this->action);
    }
}
