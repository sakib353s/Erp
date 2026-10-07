<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\BatchService;
use App\Domain\Inventory\StockBatch;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The batch register and the expiry desk (§04-38, §04-39).
 *
 * Two screens and one write. The register answers "what is this and how much of
 * it is left"; the expiry desk answers "what is about to go off"; and the only
 * write is a correction of a date, which is an event with a reason rather than an
 * edit — so a moved date can never look like a date that was always right.
 */
class InventoryBatchController extends Controller
{
    public function __construct(protected BatchService $batches) {}

    public function index(Request $request): View
    {
        $withinDays = $this->alertDays($request);
        $state = (string) $request->query('state');

        $filters = [
            'q' => trim((string) $request->query('q')),
            'product_id' => $request->query('product_id') ? (int) $request->query('product_id') : null,
            'warehouse_id' => $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null,
            'state' => in_array($state, array_keys(StockBatch::STATE_LABELS), true) ? $state : null,
            'only_stocked' => $request->has('stocked_only') ? $request->boolean('stocked_only') : true,
        ];

        return view('inventory.batches.index', [
            'batches' => $this->batches->batches($filters, $withinDays)
                ->with(['expiryChanges' => fn ($q) => $q->limit(5)])
                ->paginate(20)
                ->withQueryString(),
            'buckets' => $this->batches->buckets($withinDays),
            'withinDays' => $withinDays,
            'filters' => $filters,
            'states' => StockBatch::STATE_LABELS,
            // Discontinued products are offered too: a batch of one can still be
            // sitting on a shelf and it is exactly the one worth finding.
            'products' => Product::query()->stocked()->orderBy('sku')->get(['id', 'sku', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** §04-39: expired, expiring within the alert window, or undated at all. */
    public function expiry(Request $request): View
    {
        $withinDays = $this->alertDays($request);
        $type = (string) $request->query('type', StockBatch::STATE_EXPIRED);

        if (! in_array($type, [StockBatch::STATE_EXPIRED, StockBatch::STATE_EXPIRING, StockBatch::STATE_UNDATED], true)) {
            $type = StockBatch::STATE_EXPIRED;
        }

        $buckets = $this->batches->buckets($withinDays);

        // A work queue, not an archive: the desk shows the first 200 and says so
        // rather than pretending the rest are not there.
        $rows = $buckets[$type]['rows'];

        return view('inventory.expiry', [
            'type' => $type,
            'withinDays' => $withinDays,
            'buckets' => $buckets,
            'rows' => $rows->take(200),
            'hidden' => max(0, $rows->count() - 200),
            'types' => [
                StockBatch::STATE_EXPIRED => 'Expired',
                StockBatch::STATE_EXPIRING => "Expiring within {$withinDays} days",
                StockBatch::STATE_UNDATED => 'No expiry date on record',
            ],
        ]);
    }

    /** §04-38: correct a date, with the reason kept beside it. */
    public function updateExpiry(Request $request, StockBatch $batch): RedirectResponse
    {
        abort_unless(
            (int) $batch->company_id === (int) $request->user()->company_id,
            404,
            'That batch does not exist.',
        );

        $data = $request->validate([
            'expires_on' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['expires_on' => 'expiry date', 'reason' => 'reason for the correction']);

        try {
            $updated = $this->batches->updateExpiry(
                $batch,
                $data['expires_on'] ?? null,
                (string) $data['reason'],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['expires_on' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Batch %s now expires %s — %s.',
            $updated->batch_no,
            $updated->expires_on?->format('d M Y') ?? 'never (no date on record)',
            $updated->expires_on === null ? 'and the screen says so' : 'the old date and the reason are kept',
        ));
    }

    /**
     * The alert window: the company setting, unless the caller asked for another
     * window on this screen — `?days=90` is a question ("what is coming in three
     * months?"), and it is clamped rather than trusted.
     */
    protected function alertDays(Request $request): int
    {
        $days = $request->filled('days')
            ? (int) $request->query('days')
            : app(SettingService::class)->getInt('inventory', 'expiry_alert_days', 30);

        return max(1, min(3650, $days));
    }
}
