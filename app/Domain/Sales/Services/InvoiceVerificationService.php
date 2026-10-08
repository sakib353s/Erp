<?php

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Warranty;
use App\Domain\Sales\PublicAccessLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

/**
 * §16-19 / §16-20 — the invoice verification link and the page behind it.
 *
 * Two decisions are worth reading before the code.
 *
 * **The token is derived, and only its hash is stored.** A printed invoice
 * carries a QR symbol, and a reprint has to produce the same URL — so the
 * capability cannot be a value that exists only in the response of the request
 * that created it. It is `HMAC-SHA256(APP_KEY, "verification:{company}|{invoice}|{rotation}")`,
 * base64url-encoded; what the database holds is `hash('sha256', $token)`, which
 * is what a lookup compares against. The bytes are 32 of them and the form is
 * opaque: no invoice id, no sequence, nothing that makes the next invoice
 * guessable. Rotation is a version bump — the old URL stops resolving the
 * instant the version moves, and a reprint carries the new one.
 *
 * **The page publishes the document, not the books.** The payload is assembled
 * here and it names only what appears on the paper: the company and branch that
 * issued it, the number and date, what was bought, what is paid and what is due,
 * and who raised it. Internal notes, the journal entry, the audit chain,
 * costs and other customers are not filtered out of a query — they are never
 * read. Warranty dates are deliberately absent too: `warranty_flag` says terms
 * were promised on the line, but warranties are not registered in this
 * application yet (§16-16), so the page says that in words instead of showing a
 * date nobody recorded.
 */
class InvoiceVerificationService
{
    /**
     * The states a document can be published in. A draft or a pending invoice is
     * not something the company has asked anybody to pay, so it is not something
     * a customer may be told is on the books.
     */
    public const VERIFIABLE = ['issued', 'partial', 'paid', 'void'];

    private const PREFIX = 'verification:';

    public function __construct(protected AuditRecorder $audit) {}

    /** Publish a link for this invoice, or return the one already published. */
    public function issue(Invoice $invoice, ?User $actor = null): string
    {
        $this->assertPublishable($invoice);

        if ($this->published($invoice)) {
            return (string) $this->token($invoice);
        }

        return $this->publish($invoice, (int) $invoice->qr_token_version + 1, 'issued', $actor);
    }

    /**
     * Replace the published link with a new one. The old URL stops working
     * immediately, which is the whole point of being able to rotate it.
     */
    public function rotate(Invoice $invoice, ?User $actor = null): string
    {
        $this->assertPublishable($invoice);

        return $this->publish($invoice, (int) $invoice->qr_token_version + 1, 'rotated', $actor);
    }

    /**
     * Withdraw the link. The row keeps the date it happened, so “this invoice
     * was verifiable until 4 March” is a fact the company can still answer.
     */
    public function revoke(Invoice $invoice, ?User $actor = null): void
    {
        if (! $this->published($invoice)) {
            return;
        }

        $before = $this->state($invoice);

        $invoice->forceFill([
            'qr_token_hash' => null,
            'qr_token_version' => 0,
            'qr_token_revoked_at' => now(),
        ])->save();

        $this->audit->record([
            'company_id' => $invoice->company_id,
            'action' => 'sales.invoice_verification_revoked',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'before' => $before,
            'after' => $this->state($invoice),
            'result' => 'success',
        ]);
    }

    /** Is there a live link for this invoice? */
    public function published(Invoice $invoice): bool
    {
        return (int) $invoice->qr_token_version > 0
            && $invoice->qr_token_hash !== null
            && $invoice->qr_token_revoked_at === null;
    }

    /** The token a reprint has to carry, recomputed from the rotation. */
    public function token(Invoice $invoice): ?string
    {
        $version = (int) $invoice->qr_token_version;

        if ($version < 1 || $invoice->qr_token_revoked_at !== null) {
            return null;
        }

        return $this->derive((int) $invoice->company_id, (int) $invoice->id, $version);
    }

    /** The URL that goes inside the QR symbol. */
    public function url(Invoice $invoice): ?string
    {
        $token = $this->token($invoice);

        return $token === null ? null : route('public.invoice.verify', $token);
    }

    /**
     * Find the invoice behind a link, or nothing. The lookup is on the stored
     * hash, so the comparison is over a value that cannot be replayed as a
     * capability, and a withdrawn link resolves to nothing at all.
     */
    public function resolve(string $token): ?Invoice
    {
        if (strlen($token) < 32 || strlen($token) > 64) {
            return null;
        }

        $invoice = Invoice::query()
            ->where('qr_token_hash', hash('sha256', $token))
            ->whereNull('qr_token_revoked_at')
            ->where('qr_token_version', '>', 0)
            ->first();

        if ($invoice === null) {
            return null;
        }

        // Belt and braces: the stored hash must be the hash of *this* invoice's
        // derived token, so a row that was edited by hand outside the service
        // cannot publish some other document.
        return hash_equals((string) $invoice->qr_token_hash, hash('sha256', (string) $this->token($invoice)))
            ? $invoice
            : null;
    }

    /**
     * What the public page may say. Everything here appears on the invoice
     * itself; anything that does not is not read, and a reader can tell the
     * difference between “not published” and “not known”.
     *
     * @return array<string, mixed>
     */
    public function payload(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'lines.product', 'branch']);

        $company = $invoice->company;
        $customer = $invoice->customer;

        return [
            'verified' => true,
            'checked_at' => now(),
            'invoice_no' => (string) $invoice->invoice_no,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'invoice_type' => (string) $invoice->invoice_type,
            'title' => (string) ($invoice->printed_title ?? 'INVOICE'),
            'status' => (string) $invoice->status,
            'issue_state' => (string) $invoice->posting_state,
            'currency' => (string) $invoice->currency,

            'company' => $company === null ? null : [
                'name' => (string) $company->name,
                'address' => $this->address($company),
                'phone' => $company->phone,
                'email' => $company->email,
                'website' => $company->website,
                'tin' => $company->tin,
                'bin' => $company->bin,
            ],

            'branch' => $invoice->branch?->name,

            'customer' => $customer === null ? null : [
                'name' => (string) $customer->name,
                // A link can be forwarded or photographed off a counter, so the
                // contact details on the page are masked: enough for the holder
                // to recognise their own invoice, not enough to harvest from it.
                'phone' => $customer->phone === null ? null : Str::mask((string) $customer->phone, '•', 4),
                'email' => $customer->email === null ? null : Str::mask((string) $customer->email, '•', 3, 4),
            ],

            'lines' => $invoice->lines
                ->sortBy('line_no')
                ->values()
                ->map(fn ($line) => [
                    'line_no' => (int) $line->line_no,
                    'name' => (string) ($line->product?->name ?? $line->description ?? 'Item'),
                    'qty' => (float) $line->qty,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => (float) $line->line_total,
                    'warranty_noted' => (bool) $line->warranty_flag,
                ])->all(),

            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'tax' => (float) $invoice->tax,
                'tax_applicable' => (bool) $invoice->tax_applicable,
                'tax_code' => $invoice->tax_code,
                'grand_total' => (float) $invoice->grand_total,
                'paid' => (float) $invoice->paid_amount,
                'due' => (float) $invoice->due_amount,
            ],

            'payment_state' => $this->paymentState($invoice),

            'raised_by' => $this->raisedBy($invoice),

            // §16-16 changed what this page can honestly say. Cover is now a row
            // with dates on it, so where goods were covered the page names the
            // date the cover runs to — it is the customer's own document and the
            // date is the customer's own promise. Where a line says warranty and
            // no cover was registered against it, the page says exactly that
            // instead of inventing a period or denying the note.
            'warranty_note' => $this->warrantyNote($invoice),

            'privacy_note' => 'This page shows the document itself: its identity, its lines and its totals. Internal notes, the accounting entry, the audit trail and every other document in the company are not published here.',
        ];
    }

    /**
     * §16-16 — what the verified page may say about cover.
     *
     * Three honest answers, in order of how much is actually known:
     *  · cover exists and is still running → the date it runs to;
     *  · cover exists but has ended → the date it ended;
     *  · a line was flagged warranty and no cover row exists → say so.
     * A page that claimed a period nobody promised would be worse than silence.
     */
    protected function warrantyNote(Invoice $invoice): ?string
    {
        $covers = Warranty::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('ends_on')
            ->get(['code', 'ends_on']);

        if ($covers->isNotEmpty()) {
            $latest = $covers->first();

            $stillRunning = $covers->contains(fn (Warranty $cover) => $cover->daysRemaining() >= 0);

            return ($stillRunning ? 'Warranty cover on these goods runs to ' : 'Warranty cover on these goods ended on ')
                .$latest->ends_on?->format('d M Y')
                .' — recorded in this system, so the date is the one the company is held to.';
        }

        return $invoice->lines->contains(fn ($line) => (bool) $line->warranty_flag)
            ? 'Warranty was noted on a line of this invoice, but no cover period is registered against it in this system.'
            : null;
    }

    /**
     * One row per visit, in the same ledger the quotation share links write to.
     * The column is called `access_token`; what goes in it is the SHA-256 of the
     * token, so the log can say *which* link was used without publishing a
     * capability to anybody who can read a log table.
     */
    public function logAccess(Invoice $invoice, string $token, Request $request): void
    {
        PublicAccessLog::create([
            'company_id' => $invoice->company_id,
            'subject_type' => 'invoice',
            'subject_id' => $invoice->id,
            'access_token' => hash('sha256', $token),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'accessed_at' => now(),
        ]);

        $this->audit->record([
            'company_id' => $invoice->company_id,
            'action' => 'sales.invoice_verified',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'actor_type' => 'public',
            'actor_id' => null,
            'actor_label' => 'Anonymous verification visitor',
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 191),
            'after' => [
                'subject_type' => 'invoice',
                'token_hash' => $invoice->qr_token_hash,
                'rotation' => (int) $invoice->qr_token_version,
            ],
            'result' => 'success',
        ]);
    }

    /**
     * What the invoice screen needs to draw its panel, and nothing that the
     * screen has to work out for itself.
     *
     * @return array<string, mixed>
     */
    public function summary(Invoice $invoice): array
    {
        $usage = $this->usage($invoice);

        return [
            'published' => $this->published($invoice),
            'url' => $this->url($invoice),
            'rotation' => (int) $invoice->qr_token_version,
            'issued_at' => $invoice->qr_token_issued_at,
            'revoked_at' => $invoice->qr_token_revoked_at,
            'visits' => $usage['visits'],
            'last_seen_at' => $usage['last_seen_at'],
            'last_ip' => $usage['last_ip'],
        ];
    }

    /** How many times the link has been used, and when it was last used. */
    public function usage(Invoice $invoice): array
    {
        $rows = PublicAccessLog::query()
            ->where('subject_type', 'invoice')
            ->where('subject_id', $invoice->id)
            ->orderByDesc('accessed_at');

        return [
            'visits' => $rows->count(),
            'last_seen_at' => (clone $rows)->value('accessed_at'),
            'last_ip' => (clone $rows)->value('ip'),
        ];
    }

    private function assertPublishable(Invoice $invoice): void
    {
        if (in_array((string) $invoice->status, self::VERIFIABLE, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'verification' => 'A '.$invoice->status.' document cannot be published — issue the invoice first.',
        ]);
    }

    private function publish(Invoice $invoice, int $version, string $what, ?User $actor): string
    {
        $token = $this->derive((int) $invoice->company_id, (int) $invoice->id, $version);
        $before = $this->state($invoice);

        $invoice->forceFill([
            'qr_token_hash' => hash('sha256', $token),
            'qr_token_version' => $version,
            'qr_token_issued_at' => now(),
            'qr_token_revoked_at' => null,
        ])->save();

        $this->audit->record([
            'company_id' => $invoice->company_id,
            'action' => $what === 'rotated' ? 'sales.invoice_verification_rotated' : 'sales.invoice_verification_issued',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'before' => $before,
            'after' => $this->state($invoice),
            'result' => 'success',
        ]);

        return $token;
    }

    /**
     * 32 bytes out of HMAC-SHA256, base64url, no padding: 43 characters, no
     * invoice number in sight.
     */
    private function derive(int $companyId, int $invoiceId, int $version): string
    {
        $material = self::PREFIX.$companyId.'|'.$invoiceId.'|'.$version;
        $raw = hash_hmac('sha256', $material, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function state(Invoice $invoice): array
    {
        return [
            'rotation' => (int) $invoice->qr_token_version,
            'published' => $this->published($invoice),
            'hash' => $invoice->qr_token_hash,
            'issued_at' => $invoice->qr_token_issued_at?->toIso8601String(),
            'revoked_at' => $invoice->qr_token_revoked_at?->toIso8601String(),
        ];
    }

    private function paymentState(Invoice $invoice): string
    {
        $due = (float) $invoice->due_amount;
        $paid = (float) $invoice->paid_amount;

        if ($due <= 0 && $paid > 0) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    private function raisedBy(Invoice $invoice): ?string
    {
        if ($invoice->created_by === null) {
            return null;
        }

        return (string) DB::table('users')->where('id', $invoice->created_by)->value('name');
    }

    private function address(object $company): ?string
    {
        $parts = array_filter([
            $company->address_line1 ?? null,
            $company->address_line2 ?? null,
            $company->area ?? null,
            $company->district ?? null,
            $company->postal_code ?? null,
        ], fn ($part) => $part !== null && $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
