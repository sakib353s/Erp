<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\BulkCreateShipment;
use App\Domain\Delivery\Actions\CreateShipment;
use App\Domain\Delivery\Actions\DispatchShipment;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\ShipmentLine;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Shipments screen (02-89): list + single create (order line remainders)
 * + bulk create with per-order outcomes + dispatch (stock TRANSIT_OUT at
 * the dispatch stage). GET is gated by sales.delivery.shipments; every
 * mutation by sales.delivery.shipments.create.
 */
class ShipmentController extends Controller
{
    public function __construct(
        protected CreateShipment $createShipment,
        protected BulkCreateShipment $bulkCreateShipment,
        protected DispatchShipment $dispatchShipment,
    ) {}

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $shipments = Shipment::query()
            ->where('company_id', $companyId)
            ->with(['order', 'courier', 'lines'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $selectedOrder = null;
        $remaining = [];
        if ($request->query('order_id')) {
            $selectedOrder = SalesOrder::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $request->query('order_id'))
                ->with('lines.product')
                ->first();

            if ($selectedOrder !== null) {
                $shipped = ShipmentLine::query()
                    ->where('company_id', $companyId)
                    ->whereIn(
                        'shipment_id',
                        $selectedOrder->shipments()->where('status', '!=', 'cancelled')->select('id'),
                    )
                    ->get()
                    ->groupBy(fn ($line) => (int) $line->product_id)
                    ->map(fn ($group) => $group->sum(fn ($line) => (float) $line->qty));

                foreach ($selectedOrder->lines as $line) {
                    $left = (float) $line->qty - (float) $shipped->get((int) $line->product_id, 0);
                    if ($left > 1e-9) {
                        $remaining[(int) $line->product_id] = round($left, 4);
                    }
                }
            }
        }

        $openOrders = SalesOrder::query()
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled', 'refunded', 'returned'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('sales.shipments.index', [
            'shipments' => $shipments,
            'openOrders' => $openOrders,
            'selectedOrder' => $selectedOrder,
            'remaining' => $remaining,
            'couriers' => Courier::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'order_id' => [
                'required', 'integer',
                Rule::exists('sales_orders', 'id')->where('company_id', $companyId),
            ],
            'courier_id' => [
                'required', 'integer',
                Rule::exists('couriers', 'id')->where('company_id', $companyId),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $order = SalesOrder::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['order_id']);

        try {
            $shipment = $this->createShipment->handle($order, $data, $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.shipments.index')
            ->with('status', "Shipment for {$order->order_no} created ({$shipment->lines->count()} line(s)).");
    }

    public function bulkStore(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => [
                'integer',
                Rule::exists('sales_orders', 'id')->where('company_id', $companyId),
            ],
            'courier_id' => [
                'required', 'integer',
                Rule::exists('couriers', 'id')->where('company_id', $companyId),
            ],
        ]);

        try {
            $summary = $this->bulkCreateShipment->handle(
                array_map('intval', $data['order_ids']),
                ['courier_id' => $data['courier_id']],
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }

        $status = sprintf(
            'Bulk shipment: %d requested, %d created, %d failed.',
            $summary['requested'],
            $summary['counts'][BulkCreateShipment::OUTCOME_CREATED],
            $summary['counts'][BulkCreateShipment::OUTCOME_FAILED],
        );

        $failures = array_values(array_filter(
            $summary['results'],
            fn (array $result) => $result['outcome'] === BulkCreateShipment::OUTCOME_FAILED,
        ));

        if ($failures !== []) {
            return back()
                ->with('status', $status)
                ->withErrors(['shipments' => array_column($failures, 'message')]);
        }

        return back()->with('status', $status);
    }

    public function dispatch(Request $request, Shipment $shipment): RedirectResponse
    {
        abort_unless(
            (int) $shipment->company_id === (int) $request->user()->company_id,
            404,
        );

        try {
            $fresh = $this->dispatchShipment->handle($shipment, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['shipment' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            "Shipment #{$fresh->id} dispatched for {$fresh->order?->order_no}.",
        );
    }
}
