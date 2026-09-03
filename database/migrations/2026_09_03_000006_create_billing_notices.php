<?php
// MARKER-BILLING-NOTICES

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each notice says. Editable in master admin; the code only
        // decides when to send one.
        Schema::create('billing_notice_templates', function (Blueprint $table) {
            $table->string('event', 40)->primary();
            $table->string('label', 80);
            $table->boolean('send_alert')->default(true);
            $table->boolean('send_email')->default(true);
            $table->unsignedSmallInteger('repeat_after_hours')->default(72);
            $table->string('subject', 191);
            $table->text('body');
            $table->timestamps();
        });

        // Every notice actually sent, and what the shop did afterwards.
        Schema::create('billing_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('event', 40)->index();
            $table->uuid('charge_run_id')->nullable()->index();
            $table->boolean('alerted')->default(false);
            $table->boolean('emailed')->default(false);
            $table->string('email_to', 191)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('resolved_by_action', 40)->nullable();  // card_added | charged | written_off
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event', 'created_at']);
        });

        // Starting wording — every word of it editable afterwards.
        $now = now();
        DB::table('billing_notice_templates')->insert([
            [
                'event' => 'no_card', 'label' => 'No card on file',
                'send_alert' => true, 'send_email' => true, 'repeat_after_hours' => 168,
                'subject' => 'Add a card to keep sending campaigns',
                'body' => "Your usage balance is {balance}, and there's no card on file to settle it.\n\n"
                        . "Campaigns are paused until a card is added. Receipts, booking confirmations and "
                        . "reminders are unaffected and keep sending as normal.\n\n"
                        . "You can add one here: {link}",
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'event' => 'charge_failed', 'label' => 'Card declined',
                'send_alert' => true, 'send_email' => true, 'repeat_after_hours' => 72,
                'subject' => 'Your card was declined',
                'body' => "We tried to charge {amount} for usage and the bank refused it.\n\n"
                        . "We'll try again automatically. Campaigns are paused until it clears — receipts, "
                        . "confirmations and reminders keep sending.\n\n"
                        . "Update your card here: {link}",
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'event' => 'card_expiring', 'label' => 'Card expiring soon',
                'send_alert' => true, 'send_email' => false, 'repeat_after_hours' => 336,
                'subject' => 'Your card expires soon',
                'body' => "The card on file ({card}) expires {expires}.\n\n"
                        . "Replacing it before then avoids a failed charge and a pause on campaigns.\n\n{link}",
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'event' => 'charged', 'label' => 'Charge succeeded (receipt)',
                'send_alert' => false, 'send_email' => true, 'repeat_after_hours' => 0,
                'subject' => 'Receipt — {amount} for Intake usage',
                'body' => "We charged {amount} to the card ending {card_last4} for {messages} messages.\n\n"
                        . "The full breakdown is on your charges page: {link}",
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notices');
        Schema::dropIfExists('billing_notice_templates');
    }
};
