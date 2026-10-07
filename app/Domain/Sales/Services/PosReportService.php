<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;

/**
 * PosReportService (02-34 X / 02-35 Z). Totals are aggregates of the
 * session's own transactions and payments — never recomputed client-side.
 * X = mid-session snapshot (session may be open); Z = closing report
 * (expected for closed session, still readable open).
 */
class PosReportService
{
    public function x(PosSession $session): array
    {
        return $this->build($session, 'X');
    }

    public function z(PosSession $session): array
    {
        if ($session->status !== 'closed') {
            // Z is still allowed as a preview while open, but flag it.
        }

        return $this->build($session, 'Z');
    }

    protected function build(PosSession $session, string $kind): array
    {
        $txns = PosTransaction::query()
            ->where('pos_session_id', $session->id)
            ->where('status', 'completed')
            ->get();

        $byMethod = [];
        foreach ($txns as $txn) {
            $method = $txn->payment_method ?: 'cash';
            $byMethod[$method] = round(($byMethod[$method] ?? 0) + (float) $txn->total, 4);
        }

        $paymentIds = $txns->pluck('invoice_id')->filter()->values();

        $receiptTotal = (float) Payment::query()
            ->where('company_id', $session->company_id)
            ->whereHas('allocations', function ($q) use ($paymentIds) {
                $q->where('allocatable_type', Invoice::class)
                    ->whereIn('allocatable_id', $paymentIds);
            })
            ->sum('amount');

        $cashSales = round($byMethod['cash'] ?? 0, 4);
        $nonCashSales = round(array_sum($byMethod) - $cashSales, 4);
        $expectedCash = round(
            (float) $session->opening_float
            + $cashSales
            + (float) $session->cash_in
            - (float) $session->cash_out,
            4,
        );

        return [
            'kind' => $kind,
            'session' => [
                'id' => $session->id,
                'session_no' => $session->session_no,
                'status' => $session->status,
                'opened_at' => optional($session->opened_at)->toDateTimeString(),
                'closed_at' => optional($session->closed_at)->toDateTimeString(),
                'opened_by' => $session->opened_by,
                'closed_by' => $session->closed_by,
            ],
            'count' => $txns->count(),
            'gross_total' => round($txns->sum(fn ($t) => (float) $t->total), 4),
            'by_method' => $byMethod,
            'cash_sales' => $cashSales,
            'non_cash_sales' => $nonCashSales,
            'cash_in' => (float) $session->cash_in,
            'cash_out' => (float) $session->cash_out,
            'opening_float' => (float) $session->opening_float,
            'expected_cash' => $expectedCash,
            'closing_counted' => $session->closing_counted !== null ? (float) $session->closing_counted : null,
            'variance' => $session->status === 'closed' ? (float) $session->variance : null,
            'receipt_total' => round($receiptTotal, 4),
        ];
    }
}
