<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Concerns\BranchScope;
use App\Domain\Foundation\User;
use App\Domain\Sales\PublicAccessLog;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * §16-21 / §16-22 — the public document link and the secure download behind it.
 *
 * The shape is deliberately the same one the invoice verification link uses, so
 * there is one story in this application about handing a document to somebody
 * without a login:
 *
 *  · **the token is derived and only its digest is stored** —
 *    `HMAC-SHA256(APP_KEY, "document:{company}|{document}|{rotation}")`,
 *    base64url, 43 characters. A read-only leak of the `documents` table hands
 *    nobody a working link, and the address can be shown again on the screen
 *    that owns the file without keeping the capability in a column;
 *  · **the link has a life cycle** — published, rotated (the old address dies
 *    immediately, which is the entire point of being able to rotate), and
 *    withdrawn with the date kept. An expiry is optional: a permanent link is
 *    one with no expiry rather than one that silently outlives its purpose;
 *  · **the download is the stored bytes and nothing else** — streamed from the
 *    private disk with the file's own MIME type and a safe filename, never a
 *    path from the request and never a directory listing;
 *  · **every visit and every download is written down** — one
 *    `public_access_logs` row with the token's *hash* in the column, plus an
 *    audit event, so “who opened this and when” is answerable afterwards.
 *
 * A document that is not stored on disk cannot be published: a link to a file
 * that does not exist is a promise the application cannot keep, and it is
 * refused at publication rather than discovered by the customer.
 */
class DocumentShareService
{
    private const PREFIX = 'document:';

    public function __construct(protected AuditRecorder $audit) {}

    /**
     * Publish a link for this document, or hand back the one already live.
     */
    public function publish(Document $document, ?User $actor = null, ?CarbonInterface $expiresAt = null): string
    {
        $this->assertStored($document);

        if ($this->published($document)) {
            return (string) $this->token($document);
        }

        return $this->write($document, (int) $document->public_token_version + 1, 'issued', $actor, $expiresAt);
    }

    /** A new address; the old one stops resolving at this instant. */
    public function rotate(Document $document, ?User $actor = null, ?CarbonInterface $expiresAt = null): string
    {
        $this->assertStored($document);

        return $this->write($document, (int) $document->public_token_version + 1, 'rotated', $actor, $expiresAt);
    }

    /** Withdraw the link, keeping the date it happened. */
    public function revoke(Document $document, ?User $actor = null): void
    {
        if (! $this->published($document)) {
            return;
        }

        $before = $this->state($document);

        $document->forceFill([
            'public_token_hash' => null,
            'public_token_version' => 0,
            'public_token_expires_at' => null,
            'public_token_revoked_at' => now(),
            // Withdrawing the link takes the file back to private, because the
            // column means “somebody outside needs to see this”, and after a
            // withdrawal nobody outside can.
            'visibility' => 'private',
        ])->save();

        $this->audit->record([
            'company_id' => $document->company_id,
            'action' => 'documents.public_link_revoked',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'branch_id' => $document->branch_id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'before' => $before,
            'after' => $this->state($document),
            'result' => 'success',
        ]);
    }

    /** Is there a live address for this document right now? */
    public function published(Document $document): bool
    {
        return (int) $document->public_token_version > 0
            && $document->public_token_hash !== null
            && $document->public_token_revoked_at === null
            && $document->visibility === 'public'
            && ! $this->expired($document);
    }

    /** The address a screen may show again, recomputed from the rotation. */
    public function token(Document $document): ?string
    {
        if (! $this->published($document)) {
            return null;
        }

        return $this->derive((int) $document->company_id, (int) $document->id, (int) $document->public_token_version);
    }

    public function url(Document $document): ?string
    {
        $token = $this->token($document);

        return $token === null ? null : route('public.document.show', $token);
    }

    public function downloadUrl(Document $document): ?string
    {
        $token = $this->token($document);

        return $token === null ? null : route('public.document.download', $token);
    }

    /**
     * Find the document behind a link. The lookup is on the stored digest; the
     * re-derivation afterwards means a row edited by hand outside this service
     * cannot publish a file the company did not publish.
     */
    public function resolve(string $token): ?Document
    {
        if (strlen($token) < 32 || strlen($token) > 64) {
            return null;
        }

        $hash = hash('sha256', $token);

        $document = Document::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('public_token_hash', $hash)
            ->first();

        if ($document === null
            || (int) $document->public_token_version < 1
            || ! $document->allowsPublicAccessByToken($hash)
            || $this->expired($document)) {
            return null;
        }

        // Belt and braces: the stored digest must be the digest of *this*
        // document's derived address, so a row edited by hand outside this
        // service cannot publish a file the company did not publish.
        return hash_equals((string) $document->public_token_hash, hash('sha256', (string) $this->token($document)))
            ? $document
            : null;
    }

    /**
     * What the public page may say about the file: what it is, how big it is,
     * who published it and until when. The storage path, the internal purpose
     * notes and every other document are not read.
     *
     * @return array<string, mixed>
     */
    public function payload(Document $document, string $token): array
    {
        $document->loadMissing(['company', 'documentType']);

        return [
            'verified' => true,
            'checked_at' => now(),
            'name' => $this->safeName($document),
            'mime_type' => (string) ($document->mime_type ?? 'application/octet-stream'),
            'size_bytes' => (int) $document->size_bytes,
            'extension' => $document->extension,
            'purpose' => (string) ($document->purpose ?? 'attachment'),
            'type' => $document->documentType?->name,
            'checksum' => $document->checksum,
            'uploaded_at' => $document->created_at,
            'company' => $document->company?->name,
            'expires_at' => $document->public_token_expires_at,
            'download_url' => route('public.document.download', $token),
            'privacy_note' => 'This page serves one file: the document above, exactly as it was stored. '
                .'Nothing else in the workspace is reachable through this address, and the link can be withdrawn or replaced by the company at any time.',
        ];
    }

    /** One row per visit, in the same ledger the other public doors write to. */
    public function logAccess(Document $document, string $token, Request $request, string $what): void
    {
        PublicAccessLog::create([
            'company_id' => $document->company_id,
            'subject_type' => 'document',
            'subject_id' => $document->id,
            'access_token' => hash('sha256', $token),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'accessed_at' => now(),
        ]);

        $this->audit->record([
            'company_id' => $document->company_id,
            'action' => $what === 'download' ? 'documents.public_file_downloaded' : 'documents.public_link_viewed',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'branch_id' => $document->branch_id,
            'actor_type' => 'public',
            'actor_id' => null,
            'actor_label' => 'Anonymous public-link visitor',
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 191),
            'after' => [
                'subject_type' => 'document',
                'original_name' => $document->original_name,
                'rotation' => (int) $document->public_token_version,
            ],
            'result' => 'success',
        ]);
    }

    /**
     * What the document screen draws: the address, its life cycle and how the
     * link has actually been used.
     *
     * @return array<string, mixed>
     */
    public function summary(Document $document): array
    {
        $rows = PublicAccessLog::query()
            ->where('subject_type', 'document')
            ->where('subject_id', $document->id)
            ->orderByDesc('accessed_at');

        return [
            'published' => $this->published($document),
            'url' => $this->url($document),
            'rotation' => (int) $document->public_token_version,
            'issued_at' => $document->public_token_issued_at,
            'expires_at' => $document->public_token_expires_at,
            'revoked_at' => $document->public_token_revoked_at,
            'expired' => $this->expired($document),
            'visits' => $rows->count(),
            'last_seen_at' => (clone $rows)->value('accessed_at'),
            'last_ip' => (clone $rows)->value('ip'),
            'downloads' => AuditEvent::query()
                ->where('action', 'documents.public_file_downloaded')
                ->where('entity_id', $document->id)
                ->count(),
        ];
    }

    /** The name a browser may save the file under: nothing that can climb a path. */
    public function safeName(Document $document): string
    {
        $name = (string) $document->original_name;
        $name = str_replace(['/', '\\', "\0"], '-', $name);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? $name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document-'.$document->id;
        }

        return mb_substr($name, 0, 180);
    }

    /** Whether the stored bytes are really there. */
    public function stored(Document $document): bool
    {
        return $document->disk !== null
            && $document->path !== null
            && Storage::disk($document->disk)->exists($document->path);
    }

    private function assertStored(Document $document): void
    {
        if ($this->stored($document)) {
            return;
        }

        throw ValidationException::withMessages([
            'document' => 'The stored file for “'.$document->original_name.'” is missing, so there is nothing to publish.',
        ]);
    }

    private function expired(Document $document): bool
    {
        return $document->public_token_expires_at !== null
            && $document->public_token_expires_at->isPast();
    }

    private function write(Document $document, int $version, string $what, ?User $actor, ?CarbonInterface $expiresAt): string
    {
        $token = $this->derive((int) $document->company_id, (int) $document->id, $version);
        $before = $this->state($document);

        $document->forceFill([
            'public_token_hash' => hash('sha256', $token),
            'public_token_version' => $version,
            'public_token_issued_at' => now(),
            'public_token_expires_at' => $expiresAt,
            'public_token_revoked_at' => null,
            // The document foundation's own rule (`allowsPublicAccessByToken`)
            // asks for both a live token *and* `visibility = public`; publishing
            // is what makes that true, so the two can never disagree.
            'visibility' => 'public',
        ])->save();

        $this->audit->record([
            'company_id' => $document->company_id,
            'action' => $what === 'rotated' ? 'documents.public_link_rotated' : 'documents.public_link_issued',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'branch_id' => $document->branch_id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'before' => $before,
            'after' => $this->state($document),
            'result' => 'success',
        ]);

        return $token;
    }

    private function derive(int $companyId, int $documentId, int $version): string
    {
        $material = self::PREFIX.$companyId.'|'.$documentId.'|'.$version;
        $raw = hash_hmac('sha256', $material, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function state(Document $document): array
    {
        return [
            'rotation' => (int) $document->public_token_version,
            'published' => $this->published($document),
            'hash' => $document->public_token_hash,
            'issued_at' => $document->public_token_issued_at?->toIso8601String(),
            'expires_at' => $document->public_token_expires_at?->toIso8601String(),
            'revoked_at' => $document->public_token_revoked_at?->toIso8601String(),
        ];
    }
}
