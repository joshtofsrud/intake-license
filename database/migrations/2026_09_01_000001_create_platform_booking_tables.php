<?php
// MARKER-SCHED-FOUNDATION

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_booking_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();      // demo | investor | founding-check-in
            $table->string('name', 80);
            $table->string('kind', 12)->default('public'); // public | internal (no link, manual only)
            $table->unsignedSmallInteger('length_min')->default(20);
            // meet | phone | choice | in_person. Meet links come from the
            // Google patch; until then `meet_url` below is a fixed link per type.
            $table->string('location_mode', 12)->default('meet');
            $table->string('meet_url', 255)->nullable();
            $table->text('description')->nullable();   // shown on the booking page
            // [{key,label,type:text|select|textarea,required:bool,options:[]}]
            $table->json('questions')->nullable();
            $table->unsignedSmallInteger('reminder_minutes')->default(60); // 0 = none
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('platform_booking_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();      // public reschedule/cancel handle
            $table->foreignId('booking_type_id')->nullable()
                  ->constrained('platform_booking_types')->nullOnDelete();

            // Who
            $table->string('name', 120);
            $table->string('email', 191)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('company', 191)->nullable();
            $table->json('answers')->nullable();        // {question_key: answer}

            // When — UTC instants. `timezone` is the BOOKER's zone for display.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64)->nullable();

            // Where
            $table->string('location_mode', 12)->default('meet');
            $table->string('location_detail', 255)->nullable(); // meet url or the number to call

            // Lifecycle: confirmed | rescheduled | cancelled | completed | no_show
            $table->string('status', 16)->default('confirmed');
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 12)->nullable();  // admin | booker
            $table->text('cancel_message')->nullable();

            // Provenance: public (booked from a page) | manual (added in admin)
            $table->string('source_kind', 12)->default('public');
            $table->string('source_url', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('message_to_them')->nullable(); // goes in the confirmation
            $table->text('notes_internal')->nullable();  // never shown to them
            $table->timestamps();

            $table->index('starts_at');
            $table->index(['status', 'starts_at']);
            $table->index('email');
        });

        Schema::create('platform_booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('platform_bookings')->cascadeOnDelete();
            $table->string('kind', 40);          // created | confirmation_sent | reminder_sent | rescheduled | cancelled | completed | no_show | note
            $table->string('actor', 12)->default('system'); // system | admin | booker
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // Seed the three types from the approved mockup. Slugs are the
        // public URL: intake.works/book/{slug}.
        $now = now();
        DB::table('platform_booking_types')->insert([
            [
                'slug' => 'demo', 'name' => 'Demo call', 'kind' => 'public',
                'length_min' => 20, 'location_mode' => 'meet',
                'description' => 'A 20-minute walk-through of Intake on a screen share. Bring your questions — no slides.',
                'questions' => json_encode([
                    ['key' => 'company',   'label' => 'Business name',          'type' => 'text',     'required' => true],
                    ['key' => 'shop_type', 'label' => 'Type of shop',           'type' => 'select',   'required' => false,
                        'options' => ['Bike shop', 'Salon or barber', 'Fitness studio', 'Pet grooming', 'Auto or moto service', 'Something else']],
                    ['key' => 'using_now', 'label' => 'What do you use today?', 'type' => 'text',     'required' => false],
                    ['key' => 'notes',     'label' => 'Anything else?',         'type' => 'textarea', 'required' => false],
                ]),
                'reminder_minutes' => 60, 'is_active' => true, 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'investor', 'name' => 'Investor call', 'kind' => 'public',
                'length_min' => 30, 'location_mode' => 'choice',
                'description' => 'Thirty minutes, one on one. Ask anything about the business, the numbers, or the terms.',
                'questions' => json_encode([
                    ['key' => 'heard_from', 'label' => 'How did you hear about the round?', 'type' => 'text',     'required' => false],
                    ['key' => 'notes',      'label' => 'Anything you\'d like covered?',     'type' => 'textarea', 'required' => false],
                ]),
                'reminder_minutes' => 60, 'is_active' => true, 'sort_order' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'slug' => 'founding-check-in', 'name' => 'Founding shop check-in', 'kind' => 'internal',
                'length_min' => 45, 'location_mode' => 'meet',
                'description' => null, 'questions' => json_encode([]),
                'reminder_minutes' => 60, 'is_active' => true, 'sort_order' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        // Default rules — the Availability page edits these.
        $settings = [
            'timezone'         => 'America/Los_Angeles',
            'hours'            => json_encode([
                'mon' => [['09:00', '16:00']], 'tue' => [['09:00', '16:00']], 'wed' => [['09:00', '16:00']],
                'thu' => [['09:00', '13:00']], 'fri' => [['10:00', '14:00']], 'sat' => [], 'sun' => [],
            ]),
            'min_notice_hours' => '24',
            'buffer_minutes'   => '15',
            'max_per_day'      => '4',
            'window_weeks'     => '3',
            'blocked_dates'    => json_encode([]), // [{from:'2026-09-07', to:'2026-09-07', label:'Labor Day'}]
        ];
        foreach ($settings as $k => $v) {
            DB::table('platform_booking_settings')->insert([
                'key' => $k, 'value' => $v, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_booking_events');
        Schema::dropIfExists('platform_bookings');
        Schema::dropIfExists('platform_booking_settings');
        Schema::dropIfExists('platform_booking_types');
    }
};
