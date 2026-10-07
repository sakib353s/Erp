<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\CodReconciliation;
use App\Domain\Delivery\RiderCodCollection;
use App\Domain\People\Employee;
use Illuminate\Support\Collection;

/**
 * 02-97 COD collection tracking report: real collections with their
 * reconciliation state (outstanding / pending / reconciled) and
 * per-rider cash totals — sums of actual rows only, never forecasts.
 */
class CodQuery
{
    /**
     * @return array{
     *   rows: Collection<int, array{
     *     collection: RiderCodCollection,
     *     shipment_id: int|null,
     *     order_no: string|null,
     *     state: string,
     *   }>,
     *   riders: Collection<int, array{
     *     rider: Employee|null,
     *     collected: float,
     *     reconciled: float,
     *     pending: float,
     *     outstanding: float,
     *     collections: int,
     *   }>,
     *   reconciliations: Collection<int, CodReconciliation>,
     *   totals: array{collected: float, reconciled: float, outstanding: float, remitted: float},
     * }
     */
    public function report(int $companyId): array
    {
        $collections = RiderCodCollection::query()
            ->where('company_id', $companyId)
            ->with(['assignment.shipment.order', 'rider', 'reconciliation'])
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->get();

        $reconciliations = CodReconciliation::query()
            ->where('company_id', $companyId)
            ->with('rider')
            ->orderByDesc('id')
            ->get();

        $rows = $collections->map(fn (RiderCodCollection $c) => [
            'collection' => $c,
            'shipment_id' => $c->assignment?->shipment_id,
            'order_no' => $c->assignment?->shipment?->order?->order_no,
            'state' => $this->state($c),
        ]);

        $riders = $collections->groupBy('rider_employee_id')
            ->map(function (Collection $group) {
                $reconciled = $group->filter(
                    fn (RiderCodCollection $c) => $c->reconciliation?->isReconciled() === true,
                );
                $collected = round((float) $group->sum('amount'), 2);
                $settled = round((float) $reconciled->sum('amount'), 2);

                return [
                    'rider' => $group->first()?->rider,
                    'collected' => $collected,
                    'reconciled' => $settled,
                    'pending' => round((float) $group->filter(
                        fn (RiderCodCollection $c) => $c->cod_reconciliation_id !== null
                            && $c->reconciliation?->isReconciled() !== true,
                    )->sum('amount'), 2),
                    'outstanding' => round($collected - $settled, 2),
                    'collections' => $group->count(),
                ];
            })
            ->values();

        $collectedTotal = round((float) $collections->sum('amount'), 2);
        $reconciledTotal = round((float) $collections->filter(
            fn (RiderCodCollection $c) => $c->reconciliation?->isReconciled() === true,
        )->sum('amount'), 2);

        return [
            'rows' => $rows,
            'riders' => $riders,
            'reconciliations' => $reconciliations,
            'totals' => [
                'collected' => $collectedTotal,
                'reconciled' => $reconciledTotal,
                'outstanding' => round($collectedTotal - $reconciledTotal, 2),
                'remitted' => round((float) $reconciliations
                    ->filter(fn (CodReconciliation $r) => $r->isReconciled())
                    ->sum('remitted_amount'), 2),
            ],
        ];
    }

    protected function state(RiderCodCollection $collection): string
    {
        if ($collection->cod_reconciliation_id === null) {
            return 'outstanding';
        }

        return $collection->reconciliation?->isReconciled() === true
            ? 'reconciled'
            : 'pending';
    }
}
