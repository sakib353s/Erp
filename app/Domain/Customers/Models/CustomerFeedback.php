<?php

namespace App\Domain\Customers\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer feedback / NPS sample (05-19). Score 0-10; promoters are >= 9,
 * detractors <= 6 — computed, never stored as a label.
 */
class CustomerFeedback extends Model
{
    use Auditable;

    public const CHANNELS = ['phone', 'sms', 'email', 'in_person', 'portal'];
    public const CATEGORIES = ['product', 'delivery', 'service', 'pricing', 'other'];

    protected $fillable = [
        'company_id', 'customer_id', 'score', 'channel', 'comment', 'category', 'recorded_by',
    ];

    protected $casts = ['score' => 'integer'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sentiment(): string
    {
        return match (true) {
            $this->score >= 9 => 'promoter',
            $this->score >= 7 => 'passive',
            default => 'detractor',
        };
    }
}
