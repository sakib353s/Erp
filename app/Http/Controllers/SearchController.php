<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Global search endpoint (row 16-49 / D17): GET /search?q=.
 * Server-side branch + permission filtering happens inside
 * SearchService; this controller only shapes the response.
 */
class SearchController extends Controller
{
    public function __construct(
        protected SearchService $search,
        protected AuditRecorder $audit,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $q = trim((string) $request->query('q'));

        $hits = $this->search->search($actor, $q);

        if ($q !== '' && mb_strlen($q) >= 2) {
            $this->audit->record([
                'action' => 'search.query',
                'entity_type' => 'search',
                'entity_id' => null,
                'actor_id' => $actor->id,
                'after' => ['q' => $q, 'results' => $hits->count()],
                'ip' => (string) $request->ip(),
            ]);
        }

        return view('search.index', [
            'q' => $q,
            'hits' => $hits,
            'grouped' => $hits->groupBy('entity_type'),
        ]);
    }

    /**
     * §16-49 — what the ⌘K palette calls while somebody types.
     *
     * The same engine and the same filters as the page, answering in the shape a
     * keyboard list needs: flat, small, with the kind of record and where it
     * opens. It is throttled in the route file, because a search box is the one
     * place where the client decides how many queries the server answers, and it
     * is deliberately **not** audited per keystroke: the page search already
     * records that a search happened, and one audit row per letter would turn the
     * tamper-evident trail into a keyboard log.
     */
    public function quick(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        return response()->json([
            'term' => $term,
            'results' => $this->search->quick($request->user(), $term, 8),
        ]);
    }
}
