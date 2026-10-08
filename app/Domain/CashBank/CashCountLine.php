<?php

namespace App\Domain\CashBank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §08-05 — one denomination of a counted drawer.
 *
 * Kept as a line rather than folded into the total so the count can be added up
 * again by whoever reads it: "twenty 500s, four hundred 20s and a pile of coins"
 * is evidence, and a single figure is only a claim.
 */
class CashCountLine extends Model
{
    protected $fillable = [
        'company_id', 'cash_count_id', 'kind', 'face_value', 'quantity', 'amount', 'position',
    ];

    protected function casts(): array
    {
        return [
            'face_value' => 'decimal:4',
            'quantity' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    public function cashCount(): BelongsTo
    {
        return $this->belongsTo(CashCount::class);
    }

    /** "৳500 note" — how the line reads on the sheet. */
    public function label(): string
    {
        $kind = CashCount::KINDS[$this->kind] ?? ucfirst((string) $this->kind);

        return '৳'.rtrim(rtrim(number_format((float) $this->face_value, 2, '.', ''), '0'), '.').' '.strtolower($kind);
    }
}
