<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\ReservationService;
use App\Domain\Sales\StockReservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Stock reservations (§04-34) — the read side of what the order desk holds.
 *
 * Reserving, releasing and consuming happen inside the sales lifecycle
 * (`ReservationService`); this screen exists so the storekeeper can see what is
 * being promised away, release a hold whose order stopped moving, and run the
 * expiry sweep that gives overdue holds back to availability. Every change here
 * is audited with its reason — a released hold changes what the next customer
 * can be sold.
 */
class InventoryReservationController extends Controller
{
    public function __construct(protected ReservationService $reservations) {}

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $filters = [
            'status' => $this->statusFilter($request),
            'warehouse' => $request->filled('warehouse') ? (int) $request->query('warehouse') : null,
            'source' => $request->filled('source') ? (string) $request->query('source') : null,
            'q' => trim((string) $request->query('q')),
        ];

        $holds = $this->reservations->holds($companyId, $filters);

        // One query for the documents behind every hold on screen, so the source
        // column names the order instead of printing an id.
        $documents = SalesOrder::query()
            ->whereIn('id', $holds->pluck('source_id')->unique()->all())
            ->pluck('order_no', 'id');

        return view('inventory.reservations', [
            'holds' => $holds,
            'filters' => $filters,
            'counts' => $this->reservations->counts($companyId),
            'documents' => $documents,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /** Release one hold by hand — the goods go back to available immediately. */
    public function release(Request $request, StockReservation $reservation): RedirectResponse
    {
        $this->assertOwned($request, $reservation);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->reservations->releaseOne($reservation, $request->user()->id, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reservation' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Hold released: %s %s of %s is available again.',
            rtrim(rtrim(number_format((float) $reservation->qty, 4, '.', ''), '0'), '.'),
            $reservation->product?->sku ?? 'the product',
            $reservation->warehouse?->name ?? 'that warehouse',
        ));
    }

    /** The expiry sweep: overdue holds give their stock back (§04-34). */
    public function expire(Request $request): RedirectResponse
    {
        $count = $this->reservations->expireDue((int) $request->user()->company_id, $request->user()->id);

        return back()->with('status', $count === 0
            ? 'No hold has passed its deadline — nothing to expire.'
            : sprintf('%d overdue hold(s) expired; the quantity is available again.', $count));
    }

    protected function statusFilter(Request $request): string
    {
        $status = (string) $request->query('status', 'open');

        return in_array($status, ['open', 'overdue', 'released', 'consumed', 'expired', 'all'], true) ? $status : 'open';
    }

    protected function assertOwned(Request $request, StockReservation $reservation): void
    {
        abort_unless((int) $reservation->company_id === (int) $request->user()->company_id, 404);
    }
}
