<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\Services\JournalPostingService;
use Illuminate\Http\Request;

/**
 * PostReversal (09-10). Mirror entry in an open period; original stays
 * immutable. Reason is mandatory; audit records both sides.
 */
class PostReversal
{
    public function __construct(protected JournalPostingService $posting) {}

    public function handle(JournalEntry $entry, string $reason, Request $request): JournalEntry
    {
        return $this->posting->reverse($entry, $reason, $request->user());
    }
}
