<?php

namespace App\Domain\Settings;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    /** Company-scope rows use this sentinel instead of NULL (see migration). */
    public const COMPANY_SCOPE = 0;

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'setting_group', 'setting_key', 'value',
        'value_type', 'is_encrypted', 'is_protected', 'effective_from', 'updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'is_encrypted' => 'boolean',
        'is_protected' => 'boolean',
        'effective_from' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
