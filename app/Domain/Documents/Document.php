<?php

namespace App\Domain\Documents;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Safe file metadata (spec section K): generated path, sniffed MIME,
 * checksum, private/public disks, hashed public tokens. The original
 * client filename is never used for storage.
 */
class Document extends Model
{
    use \App\Domain\Foundation\Concerns\ScopedByBranch;

    protected $fillable = [
        'company_id', 'branch_id', 'owner_type', 'owner_id', 'document_type_id', 'purpose',
        'visibility', 'public_token_hash', 'public_token_expires_at', 'public_token_revoked_at',
        'disk', 'path', 'original_name', 'mime_type', 'extension', 'size_bytes',
        'checksum', 'width', 'height', 'derivative_path', 'version', 'revision_of_id',
        'scan_status', 'scanned_at', 'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'version' => 'integer',
        'public_token_expires_at' => 'datetime',
        'public_token_revoked_at' => 'datetime',
        'scanned_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    public function isImage(): bool
    {
        return in_array($this->extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    /** Public access requires visibility=public AND a live (unexpired, unrevoked) token. */
    public function allowsPublicAccessByToken(string $tokenHash): bool
    {
        return $this->visibility === 'public'
            && $this->public_token_hash !== null
            && hash_equals($this->public_token_hash, $tokenHash)
            && $this->public_token_revoked_at === null
            && ($this->public_token_expires_at === null || $this->public_token_expires_at->isFuture());
    }
}
