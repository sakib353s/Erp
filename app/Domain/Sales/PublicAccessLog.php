<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per unauthenticated access to a shared document (02-68):
 * who opened which subject, with which token, from where.
 */
class PublicAccessLog extends Model
{
    protected $fillable = [
        'company_id', 'subject_type', 'subject_id', 'access_token',
        'ip', 'user_agent', 'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
