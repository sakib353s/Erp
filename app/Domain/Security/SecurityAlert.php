<?php

namespace App\Domain\Security;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAlert extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'user_id', 'alert_type', 'severity', 'title', 'detail',
        'ip', 'context', 'status', 'acknowledged_by', 'acknowledged_at', 'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
