<?php

namespace App\Http\Controllers;

use App\Domain\Sales\Services\InvoiceVerificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §16-20 — the public invoice verification page.
 *
 * No session identity is required and none is invented: the token *is* the
 * capability, so the page is registered beside the other public doors
 * (`/share/quotation/{token}`) with its own throttle, and every visit is written
 * to `public_access_logs` and the audit chain. A link that has been rotated or
 * withdrawn resolves to nothing — not to an empty page, not to an error that
 * says whether the invoice exists.
 */
class PublicVerificationController extends Controller
{
    public function __construct(protected InvoiceVerificationService $verification) {}

    public function verify(string $token, Request $request): View
    {
        $invoice = $this->verification->resolve($token);

        abort_if($invoice === null, 404);
        abort_unless(in_array((string) $invoice->status, InvoiceVerificationService::VERIFIABLE, true), 404);

        $this->verification->logAccess($invoice, $token, $request);

        return view('public.verify', [
            'document' => $this->verification->payload($invoice),
        ]);
    }
}
