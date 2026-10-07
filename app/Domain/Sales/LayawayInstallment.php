<?php

namespace App\Domain\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 02-41 one dated row of a layaway balance schedule. */
class LayawayInstallment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $table = 'layaway_installments';

    protected $fillable = [
        'company_id', 'layaway_schedule_id', 'line_no',
        'due_on', 'amount', 'status', 'paid_at',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'due_on' => 'date',
        'amount' => 'decimal:4',
        'paid_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LayawaySchedule::class, 'layaway_schedule_id');
    }
}
