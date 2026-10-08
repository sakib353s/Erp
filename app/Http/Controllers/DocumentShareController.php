<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\Services\DocumentShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * §16-21 — publishing, rotating and withdrawing a document's public link.
 *
 * Three separate doors because they are three different decisions, each with its
 * own audit action. An expiry is optional and always explicit: a permanent link
 * is one the operator chose to leave open, not one that quietly outlived its
 * purpose.
 */
class DocumentShareController extends Controller
{
    public function __construct(protected DocumentShareService $share) {}

    public function publish(Request $request, Document $document): RedirectResponse
    {
        $this->guardCompany($request, $document);

        $expiresAt = $this->expiry($request);

        $this->share->publish($document, $request->user(), $expiresAt);

        return back()->with('status', $expiresAt === null
            ? 'Public link published for '.$document->original_name.' — it stays open until somebody withdraws it.'
            : 'Public link published for '.$document->original_name.' — it closes on '.$expiresAt->format('d M Y').'.');
    }

    public function rotate(Request $request, Document $document): RedirectResponse
    {
        $this->guardCompany($request, $document);

        $expiresAt = $this->expiry($request);

        $this->share->rotate($document, $request->user(), $expiresAt);

        return back()->with('status', 'The old address for '.$document->original_name.' stopped working; the new one is below.');
    }

    public function revoke(Request $request, Document $document): RedirectResponse
    {
        $this->guardCompany($request, $document);

        if (! $this->share->published($document)) {
            return back()->with('status', 'There was no live link for '.$document->original_name.'.');
        }

        $this->share->revoke($document, $request->user());

        return back()->with('status', 'Public link withdrawn for '.$document->original_name.'.');
    }

    /** Optional, explicit, and bounded: null means permanent. */
    private function expiry(Request $request): ?\Carbon\CarbonInterface
    {
        $days = $request->input('expires_in_days');

        if ($days === null || $days === '') {
            return null;
        }

        $validated = $request->validate([
            'expires_in_days' => ['integer', 'min:1', 'max:365'],
        ], [], ['expires_in_days' => 'expiry']);

        return now()->addDays((int) $validated['expires_in_days']);
    }

    /** Another company's document is not found, not refused (§ cross-company rule). */
    private function guardCompany(Request $request, Document $document): void
    {
        abort_unless($document->company_id === $request->user()->company_id, 404);
    }
}
