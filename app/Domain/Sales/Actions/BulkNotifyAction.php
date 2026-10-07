<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\SmsProvider;
use App\Domain\Notification\MessageTemplate;
use App\Domain\Notification\OutboxMessage;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * BulkNotifyAction (02-10…02-12): queues customer messages per order
 * with the same per-order isolation as the print slices. Recipient and
 * body are resolved server-side (template placeholders rendered from
 * the real order/customer/company rows). Transport states are truthful:
 * an SMS message is only `queued` when the company has an ACTIVE
 * provider with real credentials; email only when a real mail driver
 * (smtp/ses/…) is set — the dev log/array drivers are held; WhatsApp
 * has no transport registry at all, so it is always held (an SMS API
 * key never counts as a WhatsApp sender). Emails attach the invoice
 * document when one has actually been printed (HTML — no PDF engine
 * exists). Nothing is ever marked sent from this action.
 */
class BulkNotifyAction
{
    public const OUTCOME_QUEUED = 'queued';

    public const OUTCOME_HELD = 'not_configured';

    public const OUTCOME_FAILED = 'failed';

    /** @var array<int, string> */
    public const CHANNELS = ['sms', 'whatsapp', 'email'];

    public const MAX_PER_RUN = 100;

    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array<int, int|string>  $orderIds
     * @param  array<string, mixed>  $payload
     * @return array{
     *   channel: string,
     *   requested: int,
     *   counts: array<string, int>,
     *   results: array<int, array{order_id: int, order_no: ?string, outcome: string, message: string}>
     * }
     */
    public function handle(string $channel, array $orderIds, array $payload, Request $request): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException("Unsupported bulk notify channel [{$channel}].");
        }

        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            throw new RuntimeException('Select at least one order.');
        }

        if (count($ids) > self::MAX_PER_RUN) {
            throw new RuntimeException('A bulk action accepts at most '.self::MAX_PER_RUN.' orders per run.');
        }

        $template = $this->resolveTemplate($payload, $channel, $companyId);
        $transport = $this->resolveTransport($channel, $companyId);

        $results = [];
        $counts = [
            self::OUTCOME_QUEUED => 0,
            self::OUTCOME_HELD => 0,
            self::OUTCOME_FAILED => 0,
        ];

        foreach ($ids as $id) {
            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->find($id);

            if ($order === null) {
                $counts[self::OUTCOME_FAILED]++;
                $results[] = [
                    'order_id' => $id,
                    'order_no' => null,
                    'outcome' => self::OUTCOME_FAILED,
                    'message' => 'Order not found.',
                ];

                continue;
            }

            try {
                [$outcome, $message] = $this->queueMessage($order, $channel, $template, $transport, $request);
            } catch (\Throwable $e) {
                $outcome = self::OUTCOME_FAILED;
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unexpected failure.';
            }

            $counts[$outcome]++;
            $results[] = [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'outcome' => $outcome,
                'message' => $message,
            ];
        }

        $this->audit->record([
            'action' => "sales.order_bulk_{$channel}",
            'entity_type' => 'sales_order',
            'entity_id' => $results[0]['order_id'],
            'actor_id' => $request->user()->id,
            'after' => [
                'action' => 'notify_'.$channel,
                'requested' => count($ids),
                'queued' => $counts[self::OUTCOME_QUEUED],
                'held' => $counts[self::OUTCOME_HELD],
                'failed' => $counts[self::OUTCOME_FAILED],
                'template_id' => $template->id,
                'order_ids' => $ids,
            ],
            'reason' => null,
        ]);

        return [
            'channel' => $channel,
            'requested' => count($ids),
            'counts' => $counts,
            'results' => $results,
        ];
    }

    protected function resolveTemplate(array $payload, string $channel, int $companyId): MessageTemplate
    {
        $templateId = (int) ($payload['template_id'] ?? 0);

        $template = MessageTemplate::query()
            ->where('channel', $channel)
            ->where('is_active', true)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->find($templateId);

        if ($template === null) {
            throw new RuntimeException('The selected message template is not available for this channel.');
        }

        return $template;
    }

    /**
     * Transport readiness per channel — truthful only:
     *  - sms: an ACTIVE sms_providers row with real credentials (code).
     *  - email: a real mail driver (smtp/ses/…); the dev `log`/`array`
     *    drivers never count as a transport to the customer (driver name).
     *  - whatsapp: no transport registry exists at all → always null.
     */
    protected function resolveTransport(string $channel, int $companyId): ?string
    {
        if ($channel === 'sms') {
            return SmsProvider::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('config_status', 'configured')
                ->orderBy('id')
                ->value('code');
        }

        if ($channel === 'email') {
            $driver = (string) config('mail.default', 'log');

            return in_array($driver, ['log', 'array', ''], true) ? null : $driver;
        }

        return null;
    }

    /** @return array{0: string, 1: string} */
    protected function queueMessage(SalesOrder $order, string $channel, MessageTemplate $template, ?string $transport, Request $request): array
    {
        $customer = $order->customer()->first();

        if ($customer === null) {
            return [self::OUTCOME_FAILED, 'Order has no customer for the '.$channel.' message.'];
        }

        if ($channel === 'email') {
            if (! filled($customer->email)) {
                return [self::OUTCOME_FAILED, 'Customer has no email address for the message.'];
            }
            $recipient = $customer->email;
        } else {
            if (! filled($customer->phone)) {
                return [self::OUTCOME_FAILED, 'Customer has no phone number for the '.$channel.' message.'];
            }
            $recipient = $customer->phone;
        }

        $company = Company::query()->find($order->company_id);

        $body = strtr($template->body, [
            '{order_no}' => (string) $order->order_no,
            '{customer}' => (string) $customer->name,
            '{company}' => (string) ($company?->name ?? ''),
            '{status}' => (string) $order->status,
        ]);

        $configured = $transport !== null;
        $message = OutboxMessage::query()->create([
            'company_id' => $order->company_id,
            'channel' => $channel,
            'message_template_id' => $template->id,
            'sales_order_id' => $order->id,
            'recipient' => $recipient,
            'subject' => $template->subject !== null
                ? strtr($template->subject, [
                    '{order_no}' => (string) $order->order_no,
                    '{company}' => (string) ($company?->name ?? ''),
                ])
                : null,
            'body' => $body,
            'attachments' => $this->emailAttachments($order, $channel),
            'status' => $configured ? OutboxMessage::STATUS_QUEUED : OutboxMessage::STATUS_NOT_CONFIGURED,
            'provider_code' => $configured ? $transport : null,
            'queued_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        if ($configured) {
            return [
                self::OUTCOME_QUEUED,
                "Queued {$channel} to {$recipient} (message #{$message->id}, transport {$transport}).",
            ];
        }

        return [
            self::OUTCOME_HELD,
            sprintf(
                'Held %s to %s: no %s provider is configured.',
                $channel,
                $recipient,
                ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'mail'][$channel] ?? $channel,
            ),
        ];
    }

    /**
     * Email attachment list (02-12): the invoice document only when it
     * actually exists — i.e. the invoice has already been printed
     * through the print slices. The stored file is HTML (no PDF engine
     * exists), so the attachment carries the real document ids and
     * format, never a phantom PDF.
     *
     * @return array<string, mixed>|null
     */
    protected function emailAttachments(SalesOrder $order, string $channel): ?array
    {
        if ($channel !== 'email') {
            return null;
        }

        $invoice = $order->invoices()
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->orderByDesc('id')
            ->first();

        if ($invoice === null) {
            return null;
        }

        $documentIds = Document::query()
            ->where('owner_type', Invoice::class)
            ->where('owner_id', $invoice->id)
            ->pluck('id')
            ->all();

        if ($documentIds === []) {
            return null;
        }

        return [
            'invoice_id' => $invoice->id,
            'document_ids' => $documentIds,
            'format' => 'html',
        ];
    }
}
