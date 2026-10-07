<?php

namespace App\Domain\Notification;

use App\Domain\Foundation\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reusable message body per channel (02-10…02-12). System templates
 * are structural (company_id null) and seeded — placeholders
 * {order_no} {customer} {company} are rendered at queue time.
 */
class MessageTemplate extends Model
{
    protected $fillable = [
        'company_id', 'code', 'channel', 'name', 'body', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
