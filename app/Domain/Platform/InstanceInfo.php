<?php

namespace App\Domain\Platform;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row registration of THIS isolated ERP instance (control plane
 * architecture, D1/D2). Tenant-side identity only — no billing authority.
 */
class InstanceInfo extends Model
{
    protected $table = 'instance_info';

    protected $fillable = [
        'slug', 'name', 'platform_ref', 'status', 'schema_version', 'provisioned_at',
    ];

    protected $casts = [
        'singleton' => 'boolean',
        'provisioned_at' => 'datetime',
    ];

    /** The one and only row. */
    public static function current(): ?static
    {
        return static::query()->first();
    }
}
