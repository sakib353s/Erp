<?php

namespace App\Domain\Documents;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Print/download access history for documents (Rule 16). */
class PrintHistory extends Model
{
    public const UPDATED_AT = null;

    /** Migration creates singular `print_history`; Laravel would guess `print_histories`. */
    protected $table = 'print_history';

    protected $fillable = [
        'company_id', 'document_type_id', 'printable_type', 'printable_id',
        'format', 'user_id', 'ip', 'correlation_id', 'copies',
    ];

    protected $casts = ['copies' => 'integer'];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
