<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\ReorderSuggestion;
use App\Domain\Inventory\Services\ReorderSuggestionService;
use App\Domain\Masters\Supplier;
use App\Http\Requests\AcceptReorderSuggestionRequest;
use App\Http\Requests\DismissReorderSuggestionRequest;
use App\Http\Requests\StoreReorderSuggestionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * §04-55/04-56/04-58 — the reorder desk.
 *
 * One screen, three jobs: **look** (what is short, and why the system thinks
 * so — demand, cover, what is already on order), **record** the look so the
 * proposal exists before the purchase order does, and **answer** it (draft an
 * order, or dismiss it with a reason). The history is the same register read
 * for its decisions.
 *
 * Nothing here writes stock or money. Accepting hands the quantity to the
 * purchase module's own service, which numbers the order and audits it exactly
 * as a hand-raised one.
 */
class ReorderSuggestionController extends Controller
{
    public function __construct(
        protected ReorderSuggestionService $suggestions,
        protected TenantContext $context,
    ) {}

    /** The desk: the live look at the shelves, above the proposals waiting for a decision. */
    public function index(Request $request): View
    {
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));
        $filters = ['warehouse_id' => $warehouseId, 'q' => $search, 'status' => null];

        $look = $this->suggestions->candidates($warehouseId, $search === '' ? null : $search);

        return view('inventory.reorder.index', [
            'rows' => $look['rows'],
            'counts' => $look['counts'],
            'filters' => ['warehouse' => $warehouseId, 'q' => $search],
            'warehouses' => $this->warehouses(),
            'suggestions' => $this->suggestions->suggestions($filters)->open()->paginate(15)->withQueryString(),
            'stats' => $this->suggestions->stats(),
            'windowDays' => $this->suggestions->windowDays(),
            'suppliers' => $this->suppliers(),
        ]);
    }

    /** §04-58 — the same register, read for what was decided. */
    public function history(Request $request): View
    {
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));
        $status = trim((string) $request->query('status'));

        $filters = ['warehouse_id' => $warehouseId, 'q' => $search, 'status' => $status];

        return view('inventory.reorder.history', [
            'suggestions' => $this->suggestions->history($filters)->paginate(25)->withQueryString(),
            'filters' => ['warehouse' => $warehouseId, 'q' => $search, 'status' => $status],
            'statuses' => ReorderSuggestion::STATUSES,
            'warehouses' => $this->warehouses(),
            'stats' => $this->suggestions->stats(),
        ]);
    }

    /** Write the current look down: one proposal per shelf that is short. */
    public function store(StoreReorderSuggestionsRequest $request): RedirectResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        $search = trim((string) $request->input('q'));

        $look = $this->suggestions->candidates($warehouseId, $search === '' ? null : $search);
        $chosen = $request->input('scope') === 'selected'
            ? array_map('strval', (array) $request->input('rows', []))
            : null;

        $rows = [];

        foreach ($look['rows'] as $row) {
            // The tick names a shelf (`product:warehouse`), and the figures are
            // re-read here — a shelf that has since been restocked simply is not
            // in this look any more, so it cannot be proposed from a stale form.
            $key = $row['product']->id.':'.($row['warehouse']?->id ?? 0);

            if ($chosen === null || in_array($key, $chosen, true)) {
                $rows[] = $row;
            }
        }

        if ($chosen !== null && $rows === []) {
            return back()->withInput()->withErrors([
                'rows' => 'Tick at least one shelf that is below its trigger — the figures are re-read when the form is sent, so a shelf that has since been restocked will not appear.',
            ]);
        }

        try {
            $result = $this->suggestions->record($rows, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['reorder' => $e->getMessage()]);
        }

        $message = $result['created'].' proposal(s) written down.';

        if ($result['superseded'] > 0) {
            $message .= ' '.$result['superseded'].' earlier proposal(s) for the same shelves were superseded.';
        }

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' shelf(s) were already covered by an open purchase order, so nothing was proposed for them.';
        }

        return redirect()->route('inventory.reorder.suggestions.index')->with('status', $message);
    }

    /** §04-56 — draft the purchase order. The quantity is a person's to change; the number is the module's. */
    public function accept(AcceptReorderSuggestionRequest $request, ReorderSuggestion $suggestion): RedirectResponse
    {
        $this->assertSameCompany($suggestion);

        $supplier = Supplier::query()
            ->where('company_id', $suggestion->company_id)
            ->findOrFail((int) $request->validated('supplier_id'));

        try {
            $order = $this->suggestions->accept(
                $suggestion,
                $supplier,
                $request->filled('quantity') ? (float) $request->validated('quantity') : null,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['accept' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchase.orders.show', $order)
            ->with('status', "Draft purchase order {$order->code} raised from suggestion {$suggestion->code} — it still needs approving before anything is sent.");
    }

    /** §04-58 — the answer is "no", and it is written down with its reason. */
    public function dismiss(DismissReorderSuggestionRequest $request, ReorderSuggestion $suggestion): RedirectResponse
    {
        $this->assertSameCompany($suggestion);

        try {
            $this->suggestions->dismiss($suggestion, (string) $request->validated('note'), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['dismiss' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.reorder.suggestions.index')
            ->with('status', "Suggestion {$suggestion->code} dismissed. The reason is kept in the history.");
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Warehouse> */
    protected function warehouses()
    {
        return Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Supplier> */
    protected function suppliers()
    {
        return Supplier::query()
            ->where('company_id', $this->context->companyId())
            ->where('is_active', true)
            ->where('is_blacklisted', false)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    protected function assertSameCompany(ReorderSuggestion $suggestion): void
    {
        abort_unless(
            $suggestion->company_id === $this->context->companyId(),
            404,
        );
    }
}
