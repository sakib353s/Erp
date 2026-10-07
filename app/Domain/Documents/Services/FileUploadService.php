<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Safe upload pipeline (spec section K):
 *  - size limits from config (general vs image),
 *  - extension allow-list AND real MIME sniffing via finfo — the
 *    client-declared Content-Type is never trusted,
 *  - server-generated filename + Y/m directory pattern,
 *  - optional WebP derivative for raster images (GD present),
 *  - every upload recorded as document.upload audit event,
 *  - Document row stores sha256 of the bytes for integrity checks.
 *
 * Nothing here echoes user-controlled filenames back to disk paths.
 */
class FileUploadService
{
    public function __construct(protected AuditRecorder $audit) {}

    /**
     * @param array{
     *     document_type?: string, owner_type?: ?string, owner_id?: ?int,
     *     branch_id?: ?int, purpose?: ?string, visibility?: string,
     *     generate_webp?: bool
     * } $meta
     */
    public function store(UploadedFile $file, User $actor, array $meta = []): Document
    {
        $this->validate($file);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $directory = now()->format((string) config('erp.upload.path_pattern', 'Y/m'));
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $filename, config('filestorage.disk', 'local'));

        if ($path === false) {
            throw new RuntimeException('Upload failed: could not persist file to storage.');
        }

        $disk = Storage::disk(config('filestorage.disk', 'local'));
        $bytes = $disk->get($path);
        $sha256 = hash('sha256', $bytes);
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($path)) ?: 'application/octet-stream';

        $webpPath = null;
        if (($meta['generate_webp'] ?? config('erp.upload.generate_webp', true))
            && in_array($extension, config('erp.upload.image_extensions', []), true)
            && function_exists('imagecreatefromstring')
        ) {
            $webpPath = $this->makeWebp($disk->path($path), preg_replace('/\.\w+$/', '.webp', $disk->path($path)))
                ? preg_replace('/\.\w+$/', '.webp', $path)
                : null;
        }

        $document = Document::create([
            'company_id' => $actor->company_id,
            'branch_id' => $meta['branch_id'] ?? $actor->default_branch_id,
            'owner_type' => $meta['owner_type'] ?? null,
            'owner_id' => $meta['owner_id'] ?? null,
            'document_type_id' => $this->resolveTypeId($meta['document_type'] ?? null),
            'purpose' => $meta['purpose'] ?? 'attachment',
            'visibility' => ($meta['visibility'] ?? null) === 'public' ? 'public' : 'private',
            'disk' => config('filestorage.disk', 'local'),
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $sniffed,
            'extension' => $extension,
            'size_bytes' => (int) $file->getSize(),
            'checksum' => $sha256,
            'derivative_path' => $webpPath,
            'scan_status' => 'not_scanned', // truthful: no scanner configured yet
            'uploaded_by' => $actor->id,
        ]);

        $this->audit->record([
            'action' => 'document.upload',
            'entity_type' => $document->owner_type ?? 'document',
            'entity_id' => $document->owner_id ?? $document->id,
            'branch_id' => $document->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'document_id' => $document->id,
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'sha256' => $sha256,
            ],
        ]);

        return $document;
    }

    /** Throwing validator — used by controllers AND tests. */
    public function validate(UploadedFile $file): void
    {
        $maxBytes = (int) config('erp.upload.max_bytes');
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, config('erp.upload.allowed_extensions', []), true)) {
            throw new InvalidArgumentException("File type '.{$extension}' is not allowed.");
        }

        if (! $file->isValid()) {
            throw new InvalidArgumentException('The upload did not complete successfully.');
        }

        $imageExtensions = config('erp.upload.image_extensions', []);
        if (in_array($extension, $imageExtensions, true)) {
            $maxBytes = (int) config('erp.upload.image_max_bytes', $maxBytes);
        }

        if ($file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('File exceeds the maximum allowed size of '.number_format($maxBytes / 1048576, 1).' MB.');
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) file_get_contents($file->getRealPath()));

        $allowed = config("erp.upload.allowed_mimes.{$extension}", []);

        foreach ($allowed as $needle) {
            if ($sniffed !== false && str_contains((string) $sniffed, $needle)) {
                return;
            }
        }

        // Mismatch between declared extension and real content — reject
        // (this is the double-extension / renamed-executable defence).
        throw new InvalidArgumentException('The file content does not match its extension.');
    }

    protected function resolveTypeId(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return DocumentType::query()->where('code', $code)->value('id');
    }

    /** @return bool true when the derivative was written */
    protected function makeWebp(string $sourcePath, string $targetPath): bool
    {
        $data = @file_get_contents($sourcePath);
        $image = $data !== false ? @imagecreatefromstring($data) : false;

        if ($image === false) {
            return false; // derivative is optional; original is already stored
        }

        imagesavealpha($image, true);
        $ok = @imagewebp($image, $targetPath, (int) config('erp.upload.webp_quality', 85));
        imagedestroy($image);

        return $ok !== false;
    }
}
