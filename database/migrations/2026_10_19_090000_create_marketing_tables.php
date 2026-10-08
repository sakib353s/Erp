<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §11 — marketing: campaigns, the audience they went to, and the people who
 * asked not to be written to again.
 *
 * Three tables, because three different questions are being asked.
 *
 * `marketing_campaigns` is the *intention*: a channel, a message, an audience
 * rule and — when it is scheduled — a time. It is a draft until somebody
 * launches it, and launching is a decision, which is why `status` is
 * `draft|scheduled|launched|cancelled` and why nothing here counts as sent.
 *
 * `marketing_campaign_recipients` is the *audience as it actually was*: one row
 * per customer the campaign was aimed at, with the contact used, whether they
 * were skipped (and why), and the outbox message it was handed to. The row's
 * delivery state is not stored here — it is read from the outbox message this
 * row points at, so the campaign report and the outbox can never disagree about
 * what happened to a message. A skipped recipient has no outbox row at all and
 * says why in `skip_reason`; that is the difference between “we did not send
 * this” and “we sent it and nothing came back” that a marketing report lives or
 * dies on.
 *
 * `marketing_optouts` is the *promise*: do not write to this contact on this
 * channel again. It is checked before anything is queued, so an opt-out is
 * honoured by the dispatcher rather than remembered by a person, and it is keyed
 * by the contact itself — the same human writing back “STOP” twice is one row,
 * not two.
 *
 * `outbox_messages` gains one nullable column: which campaign a message came
 * from. Delivery stays in the shared outbox — there is one place where a message
 * can be marked sent, and this is not it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('code', 32);                     // MC-000001, per company
            $table->string('channel', 16);                  // sms|email|whatsapp|push
            $table->string('name', 160);
            $table->string('objective', 191)->nullable();   // what this is trying to do

            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->string('subject', 255)->nullable();     // email only
            $table->text('body');                           // rendered per recipient at launch

            // The audience is a rule, not a list: it is re-expanded at launch,
            // which is the only way “everybody who bought in the last month”
            // means what it says.
            $table->string('audience', 24)->default('all');  // all|recent_buyers|dormant|manual
            $table->unsignedSmallInteger('audience_days')->nullable();   // window for the two dated rules

            $table->string('status', 16)->default('draft'); // draft|scheduled|launched|cancelled
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('launched_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            // What the message costs and how long an order still counts as
            // having come from it. Both are the desk's own figures — nothing
            // here is inferred from a click nobody recorded.
            $table->decimal('cost_per_message', 12, 4)->default(0);
            $table->unsignedSmallInteger('attribution_days')->default(7);

            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'channel', 'status']);
            $table->index(['company_id', 'scheduled_at']);
        });

        Schema::create('marketing_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name', 191);                    // as it was on the day
            $table->string('contact', 191)->nullable();     // the phone/email actually used
            $table->string('skip_reason', 191)->nullable(); // only on the skipped rows
            $table->foreignId('outbox_message_id')->nullable()->constrained('outbox_messages')->nullOnDelete();

            // What this one message cost, frozen at launch: a campaign re-priced
            // next month must not rewrite what last month's send cost.
            $table->decimal('cost', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'customer_id']);
            $table->index(['company_id', 'campaign_id']);
        });

        Schema::create('marketing_optouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();

            $table->string('channel', 16);                  // sms|email|whatsapp|push|all
            $table->string('contact', 191);                 // the phone or address that said no
            $table->string('reason', 500)->nullable();
            $table->string('source', 24)->default('manual');// manual|reply|campaign|import
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'contact']);
            $table->index(['company_id', 'contact']);
        });

        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->foreignId('marketing_campaign_id')->nullable()->after('sales_order_id')
                ->constrained('marketing_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_campaign_id');
        });

        Schema::dropIfExists('marketing_optouts');
        Schema::dropIfExists('marketing_campaign_recipients');
        Schema::dropIfExists('marketing_campaigns');
    }
};
