<?php

namespace App\Domain\Foundation;

use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DB-driven document numbering rule (spec section L / correction G —
 * numbering is never hard-coded in controllers). Allocation happens
 * through NumberingService with a row-locked sequence row.
 */
class NumberingRule extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'document_type_id', 'branch_id', 'prefix', 'pattern',
        'padding', 'reset_period', 'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'padding' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(NumberingSequence::class);
    }
}
