<?php

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

class AuditArchive extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'period', 'seq_from', 'seq_to', 'event_count',
        'chain_start_hash', 'chain_end_hash', 'checksum', 'archived_by',
    ];
}
