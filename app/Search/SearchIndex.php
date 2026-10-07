<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized, permission- and branch-aware search hit (D17).
 * Rebuilt from source tables by SearchIndexRebuilder; never seeded
 * with fake business data.
 */
class SearchIndex extends Model
{
    protected $table = 'search_index';

    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'branch_id',
        'permission_key', 'title', 'subtitle', 'excerpt', 'url', 'normalized',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'branch_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }
}
