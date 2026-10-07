<?php

namespace App\Domain\Sales;

use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeatPlanStop extends Model
{
    protected $fillable = [
        'beat_plan_id', 'sequence_no', 'customer_id', 'territory_id',
        'label', 'status', 'notes',
    ];

    protected $casts = [
        'sequence_no' => 'integer',
    ];

    public function beatPlan(): BelongsTo
    {
        return $this->belongsTo(BeatPlan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
