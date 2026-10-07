<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\Services\JournalPostingService;
use Illuminate\Http\Request;

/**
 * CreateManualJournal (09-07). Line validation, balance assert, period
 * lock and numbering all happen inside JournalPostingService — this
 * action is the thin request → service bridge for the HTTP layer.
 */
class CreateManualJournal
{
    public function __construct(protected JournalPostingService $posting) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload, Request $request): JournalEntry
    {
        return $this->posting->post($payload, $request->user());
    }
}
