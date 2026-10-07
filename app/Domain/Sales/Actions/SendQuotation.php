<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Outbox\Services\OutboxPublisher;
use App\Domain\Sales\Events\QuotationSent;
use App\Domain\Sales\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SendQuotation (02-67): the quotation is marked sent only in the same
 * transaction that writes its outbox delivery event — a "sent" status
 * always has a real outbox row and a resolved recipient behind it.
 */
class SendQuotation
{
    public const CHANNELS = ['email', 'sms', 'whatsapp', 'link'];

    public const BLOCKED = ['converted', 'accepted', 'declined', 'expired'];

    public function __construct(
        protected AuditRecorder $audit,
        protected OutboxPublisher $outbox,
    ) {}

    public function handle(Quotation $quotation, array $payload, Request $request): Quotation
    {
        return DB::transaction(function () use ($quotation, $payload, $request) {
            $fresh = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if (in_array($fresh->status, self::BLOCKED, true)) {
                throw new RuntimeException("Quotation {$fresh->quote_no} is {$fresh->status} and cannot be sent.");
            }

            $channel = (string) ($payload['channel'] ?? 'email');
            if (! in_array($channel, self::CHANNELS, true)) {
                throw new RuntimeException("Unsupported send channel [{$channel}].");
            }

            $customer = $fresh->customer;
            $to = trim((string) ($payload['to'] ?? ''));
            if ($to === '') {
                $to = trim((string) ($customer?->email ?? ''));
            }
            if ($to === '') {
                $to = trim((string) ($customer?->phone ?? ''));
            }
            if ($to === '') {
                throw new RuntimeException("Quotation {$fresh->quote_no} has no customer contact to send to.");
            }

            $before = [
                'status' => $fresh->status,
                'sent_at' => $fresh->sent_at?->toIso8601String(),
                'sent_channel' => $fresh->sent_channel,
                'sent_to' => $fresh->sent_to,
                'share_token' => $fresh->share_token,
            ];

            if ($fresh->status === 'draft') {
                $fresh->status = 'sent';
            }

            if ($fresh->share_token === null) {
                $fresh->share_token = Str::random(40);
            }

            $fresh->sent_at = now();
            $fresh->sent_channel = $channel;
            $fresh->sent_to = $to;
            $fresh->save();

            $shareUrl = url('/share/quotation/'.$fresh->share_token);

            $this->outbox->publish(
                new QuotationSent(
                    companyId: (int) $fresh->company_id,
                    branchId: $fresh->branch_id !== null ? (int) $fresh->branch_id : null,
                    quotationId: (int) $fresh->id,
                    quoteNo: (string) $fresh->quote_no,
                    revision: (int) $fresh->revision,
                    channel: $channel,
                    to: $to,
                    customerId: $fresh->customer_id !== null ? (int) $fresh->customer_id : null,
                    subject: "Quotation {$fresh->quote_no} rev {$fresh->revision}",
                    shareUrl: $shareUrl,
                ),
                options: [
                    'aggregate_type' => 'quotation',
                    'aggregate_id' => (int) $fresh->id,
                    'company_id' => (int) $fresh->company_id,
                ],
            );

            $this->audit->record([
                'action' => 'sales.quotation_sent',
                'entity_type' => 'quotation',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()?->id,
                'before' => $before,
                'after' => [
                    'status' => $fresh->status,
                    'sent_at' => $fresh->sent_at->toIso8601String(),
                    'sent_channel' => $channel,
                    'sent_to' => $to,
                ],
            ]);

            return $fresh;
        });
    }
}
