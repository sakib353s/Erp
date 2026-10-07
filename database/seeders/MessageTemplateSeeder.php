<?php

namespace Database\Seeders;

use App\Domain\Notification\MessageTemplate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Structural system message templates (02-10…02-12) — company_id null
 * = system rows every instance shares. Bodies carry the placeholders
 * {order_no} {customer} {company} rendered at queue time. NO messages
 * are created here; only the reusable bodies.
 */
class MessageTemplateSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $templates = [
            ['channel' => 'sms', 'name' => 'Order update (SMS)', 'body' => 'Dear {customer}, update for your order {order_no} from {company}.'],
            ['channel' => 'whatsapp', 'name' => 'Order update (WhatsApp)', 'body' => 'Dear {customer}, update for your order {order_no} from {company}.'],
            ['channel' => 'email', 'name' => 'Order update (Email)', 'body' => 'Dear {customer},\n\nHere is the update for your order {order_no} from {company}.'],
        ];

        foreach ($templates as $template) {
            MessageTemplate::updateOrCreate(
                [
                    'company_id' => null,
                    'code' => 'order_update',
                    'channel' => $template['channel'],
                ],
                [
                    'name' => $template['name'],
                    'body' => $template['body'],
                    'subject' => 'Order update — {company}',
                    'is_active' => true,
                ],
            );
        }
    }
}
