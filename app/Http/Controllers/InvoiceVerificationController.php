<?php

namespace App\Http\Controllers;

use App\Domain\Sales\Invoice;
use App\Domain\Sales\Services\InvoiceVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * §16-19 — publishing, rotating and withdrawing an invoice's verification link.
 *
 * The three actions are deliberately separate: publishing a capability, moving
 * it to a new address, and taking it away are different decisions, and the audit
 * chain records which one happened. Nothing here deletes anything — a withdrawn
 * link leaves the invoice with the date it was withdrawn.
 */
class InvoiceVerificationController extends Controller
{
    public function __construct(protected InvoiceVerificationService $verification) {}

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->verification->issue($invoice, $request->user());

        return back()->with('status', 'Verification link published for '.$invoice->invoice_no.'.');
    }

    public function rotate(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->verification->rotate($invoice, $request->user());

        return back()->with('status', 'The old link for '.$invoice->invoice_no.' stopped working; the new one is below.');
    }

    public function revoke(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! $this->verification->published($invoice)) {
            return back()->with('status', 'There was no live link for '.$invoice->invoice_no.'.');
        }

        $this->verification->revoke($invoice, $request->user());

        return back()->with('status', 'Verification link withdrawn for '.$invoice->invoice_no.'.');
    }
}
