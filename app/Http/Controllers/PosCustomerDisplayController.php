<?php

namespace App\Http\Controllers;

use App\Domain\Sales\PosSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 02-43 POS Customer Display — the customer-facing second screen.
 *
 * Paired channel: the till mints a short display_code per open session
 * (shown on the terminal), the display screen enters that code and then
 * polls a whitelisted state snapshot that the terminal pushes. The state
 * carries items and totals only — never drawer money, session counters,
 * customer identity, or staff fields.
 */
class PosCustomerDisplayController extends Controller
{
    /**
     * Display screen: code entry form, or the paired live view for a code.
     */
    public function index(Request $request): View
    {
        $code = strtoupper(trim((string) $request->query('code', '')));
        if (strlen($code) > 16) {
            $code = substr($code, 0, 16);
        }
        $session = $code !== '' ? $this->pairedSession($request, $code) : null;

        // The view only ever receives the code, a boolean, and the whitelisted
        // state — never the session record itself (no drawer/money fields).
        return view('pos.customer-display', [
            'code' => $code,
            'paired' => $session !== null,
            'state' => $session !== null ? $this->stateFor($session) : null,
        ]);
    }

    /**
     * Poll endpoint used by the paired display screen.
     */
    public function state(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $session = $this->pairedSession($request, strtoupper($request->input('code')));
        if ($session === null) {
            return response()->json([
                'paired' => false,
                'reason' => 'No open POS session is paired to that code.',
            ]);
        }

        return response()->json(array_merge(['paired' => true], $this->stateFor($session)));
    }

    /**
     * The till pushes its cart snapshot here (debounced from renderCart).
     */
    public function push(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.name' => ['required', 'string', 'max:120'],
            'lines.*.qty' => ['required', 'numeric', 'min:0', 'max:999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $session = $this->pairedSession($request, strtoupper($request->input('code')));
        if ($session === null) {
            return response()->json([
                'ok' => false,
                'reason' => 'No open POS session is paired to that code.',
            ], 404);
        }

        // Server recomputes every money figure — the client only sends items.
        $lines = [];
        $total = 0.0;
        $itemCount = 0.0;
        foreach (($request->input('lines') ?? []) as $line) {
            $qty = round((float) $line['qty'], 4);
            $unitPrice = round((float) $line['unit_price'], 4);
            $lineTotal = round($qty * $unitPrice, 4);
            $lines[] = [
                'name' => mb_substr((string) $line['name'], 0, 120),
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
            $total = round($total + $lineTotal, 4);
            $itemCount = round($itemCount + $qty, 4);
        }

        $session->display_state = [
            'lines' => $lines,
            'item_count' => $itemCount,
            'total' => $total,
        ];
        $session->display_updated_at = now();
        $session->save();

        return response()->json([
            'ok' => true,
            'updated_at' => $session->display_updated_at->toIso8601String(),
        ]);
    }

    /**
     * Open session in the caller's company carrying this pairing code.
     */
    protected function pairedSession(Request $request, string $code): ?PosSession
    {
        if ($code === '' || preg_match('/^[A-Z0-9]{1,16}$/', $code) !== 1) {
            return null;
        }

        return PosSession::query()
            ->where('company_id', $request->user()->company_id)
            ->where('status', 'open')
            ->where('display_code', $code)
            ->first();
    }

    /**
     * The ONLY shape the display ever sees — a strict whitelist.
     */
    protected function stateFor(PosSession $session): array
    {
        $state = $session->display_state ?? [];

        return [
            'lines' => array_values(array_map(static fn (array $line): array => [
                'name' => (string) ($line['name'] ?? ''),
                'qty' => (float) ($line['qty'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'line_total' => (float) ($line['line_total'] ?? 0),
            ], is_array($state['lines'] ?? null) ? $state['lines'] : [])),
            'item_count' => (float) ($state['item_count'] ?? 0),
            'total' => round((float) ($state['total'] ?? 0), 4),
            'updated_at' => $session->display_updated_at?->toIso8601String(),
        ];
    }
}
