<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberingSequence extends Model
{
    protected $fillable = ['numbering_rule_id', 'period_key', 'last_value'];

    protected $casts = ['last_value' => 'integer'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NumberingRule::class, 'numbering_rule_id');
    }
}
