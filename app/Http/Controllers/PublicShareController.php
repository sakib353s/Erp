<?php

namespace App\Http\Controllers;

use App\Domain\Sales\Actions\RecordQuotationView;
use App\Domain\Sales\Quotation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unauthenticated quotation share links (02-68): a read-only document
 * behind a token, every visit written to public_access_logs + audit.
 */
class PublicShareController extends Controller
{
    public function __construct(protected RecordQuotationView $recordView) {}

    public function quotation(string $token, Request $request): View
    {
        $quotation = Quotation::query()
            ->where('share_token', $token)
            ->with(['lines.product', 'customer'])
            ->first();

        abort_if($quotation === null, 404);

        $this->recordView->handle($quotation, $token, $request);

        return view('public.quotation', [
            'quotation' => $quotation,
        ]);
    }
}
