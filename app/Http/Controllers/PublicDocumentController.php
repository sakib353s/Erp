<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\DocumentShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §16-21 / §16-22 — the public document link and its secure download.
 *
 * No session identity: the token is the capability. The metadata page and the
 * download are two addresses so a browser can preview the first and save the
 * second, and both are recorded — a view and a download are different facts and
 * the log keeps them apart. A link that was rotated, withdrawn or has expired
 * resolves to a plain 404, never to a page that hints the file exists.
 */
class PublicDocumentController extends Controller
{
    public function __construct(protected DocumentShareService $share) {}

    public function show(string $token, Request $request): View
    {
        $document = $this->share->resolve($token);

        abort_if($document === null, 404);

        $this->share->logAccess($document, $token, $request, 'view');

        return view('public.document', [
            'document' => $this->share->payload($document, $token),
        ]);
    }

    public function download(string $token, Request $request): StreamedResponse
    {
        $document = $this->share->resolve($token);

        abort_if($document === null, 404);
        abort_unless($this->share->stored($document), 404, 'The stored file is missing.');

        $this->share->logAccess($document, $token, $request, 'download');

        $disk = Storage::disk((string) $document->disk);

        return $disk->download((string) $document->path, $this->share->safeName($document), [
            'Content-Type' => (string) ($document->mime_type ?? 'application/octet-stream'),
        ]);
    }
}
