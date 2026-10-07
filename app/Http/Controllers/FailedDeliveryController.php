<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\ResolveFailedDelivery;
use App\Domain\Delivery\FailedDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 02-96 Failed delivery management screen: lists open failure
 * attempts with their shipment/order/courier context and applies the
 * three recovery actions (retry/return/reship). No customer notice is
 * sent from here — recovery is an internal warehouse/courier
 * operation with audit.
 */
class FailedDeliveryController extends Controller
{
    public function __construct(
        protected ResolveFailedDelivery $resolver,
    ) {}

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $open = FailedDelivery::query()
            ->where('company_id', $companyId)
            ->where('status', FailedDelivery::STATUS_OPEN)
            ->with([
                'shipment:id,company_id,sales_order_id,courier_id,status,external_ref',
                'order:id,order_no,status,customer_id',
                'order.customer:id,code,name',
                'courier:id,code,name',
            ])
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->get();

        return view('sales.delivery.failed', [
            'open' => $open,
        ]);
    }

    public function retry(Request $request, FailedDelivery $failedDelivery): RedirectResponse
    {
        return $this->resolve($request, $failedDelivery, 'retry');
    }

    public function returnGoods(Request $request, FailedDelivery $failedDelivery): RedirectResponse
    {
        return $this->resolve($request, $failedDelivery, 'return');
    }

    public function reship(Request $request, FailedDelivery $failedDelivery): RedirectResponse
    {
        return $this->resolve($request, $failedDelivery, 'reship');
    }

    protected function resolve(Request $request, FailedDelivery $failedDelivery, string $action): RedirectResponse
    {
        $labels = [
            'retry' => 'Shipment back out for delivery.',
            'return' => 'Goods returned to the warehouse.',
            'reship' => 'Replacement shipment created.',
        ];

        try {
            $this->resolver->handle(
                $failedDelivery,
                $action,
                $request->only(['courier_id']),
                $request,
            );
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['failed_delivery' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.failed.index')
            ->with('status', $labels[$action]);
    }
}
