<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Document;
use App\Domain\Documents\Services\DocumentShareService;
use App\Domain\Documents\Services\FileUploadService;
use App\Http\Requests\UploadDocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * Document foundation (section K): upload via the safe MIME-sniffing
 * pipeline, download streams the stored bytes and records a
 * document.download audit row with the correlation id, delete removes
 * both derivatives and the row. Cross-company access is impossible
 * (404), branch-scoped users only see their branches.
 */
class DocumentController extends Controller
{
    public function __construct(
        protected FileUploadService $uploads,
        protected AuditRecorder $audit,
        protected DocumentShareService $share,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $ids = $actor->accessibleBranchIds();

        $query = Document::query()->with(['uploader', 'documentType'])->orderByDesc('id');

        if ($ids !== null) {
            $query->whereIn('branch_id', array_merge($ids, [null]));
        }

        if ($purpose = (string) $request->query('purpose')) {
            $query->where('purpose', $purpose);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where('original_name', 'like', "%{$search}%");
        }

        $documents = $query->paginate(15)->withQueryString();

        // §16-21: which of these files have a live public link, read back from
        // the same service that publishes them so the list cannot disagree with
        // the file's own screen.
        $shared = $documents->getCollection()
            ->mapWithKeys(fn (Document $document) => [$document->id => $this->share->published($document)]);

        return view('documents.index', [
            'documents' => $documents,
            'q' => $search,
            'purpose' => $purpose,
            'shared' => $shared,
        ]);
    }

    public function store(UploadDocumentRequest $request): RedirectResponse
    {
        try {
            $document = $this->uploads->store(
                $request->file('file'),
                $request->user(),
                [
                    'purpose' => $request->validated('purpose') ?? 'attachment',
                    'branch_id' => $request->user()->default_branch_id,
                ],
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return back()->with('status', "Uploaded {$document->original_name}.");
    }

    /** §16-21: one file, its metadata, its public link and every visit to it. */
    public function show(Request $request, Document $document): View
    {
        abort_unless($document->company_id === $request->user()->company_id, 404);

        return view('documents.show', [
            'document' => $document->load(['documentType', 'uploader', 'branch']),
            'verification' => $this->share->summary($document),
            'stored' => $this->share->stored($document),
        ]);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->company_id === $request->user()->company_id, 404);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404, 'Stored file is missing.');

        $this->audit->record([
            'action' => 'document.download',
            'entity_type' => $document->owner_type ?? 'document',
            'entity_id' => $document->owner_id ?? $document->id,
            'branch_id' => $document->branch_id,
            'actor_id' => $request->user()->id,
            'after' => ['document_id' => $document->id, 'original_name' => $document->original_name],
            'correlation_id' => (string) $request->attributes->get('correlation_id'),
            'ip' => (string) $request->ip(),
        ]);

        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
        ]);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->company_id === $request->user()->company_id, 404);

        $disk = Storage::disk($document->disk);
        $disk->delete($document->path);

        if ($document->derivative_path !== null) {
            $disk->delete($document->derivative_path);
        }

        $snapshot = ['original_name' => $document->original_name, 'checksum' => $document->checksum];
        $document->delete();

        $this->audit->record([
            'action' => 'record.delete',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'actor_id' => $request->user()->id,
            'before' => $snapshot,
            'ip' => (string) $request->ip(),
        ]);

        return back()->with('status', 'Document deleted.');
    }
}
