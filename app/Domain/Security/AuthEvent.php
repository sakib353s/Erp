<?php

namespace App\Domain\Security;

use Illuminate\Database\Eloquent\Model;

/** Authentication event trail (login/logout/failure/lockout/suspicious). */
class AuthEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'event_type', 'email_attempt', 'ip',
        'user_agent', 'reason', 'context',
    ];

    protected $casts = ['context' => 'array'];
}
