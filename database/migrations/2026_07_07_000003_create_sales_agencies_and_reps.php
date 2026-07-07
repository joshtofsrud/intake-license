<?php
// MARKER-AGENCIES-CORE — Rep agencies and their reps.
// Commission rates live PER AGENCY (not a global constant) so different groups
// can carry different terms. The ledger build (chunk 2) reads these.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_agencies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('status', 20)->default('onboarding');   // active | onboarding | paused
            $t->decimal('commission_year1', 5, 4)->default(0.2500);   // of collected revenue, account age < 12mo
            $t->decimal('commission_residual', 5, 4)->default(0.1000); // account age >= 12mo
            $t->boolean('deal_registration')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('sales_reps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('agency_id');
            $t->string('name', 120);
            $t->string('role', 20)->default('rep');            // principal | rep
            $t->string('email', 191)->nullable();
            $t->string('phone', 64)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();     // /rep panel login, wired in chunk 2
            $t->string('status', 20)->default('active');       // active | inactive
            $t->timestamps();

            $t->foreign('agency_id', 'sales_reps_agency_fk')
              ->references('id')->on('sales_agencies')->cascadeOnDelete();
            $t->index(['agency_id', 'status']);
        });

        Schema::table('sales_reps', function (Blueprint $t) {
            try {
                $t->foreign('user_id', 'sales_reps_user_fk')
                  ->references('id')->on('users')->nullOnDelete();
            } catch (\Throwable $e) { /* users table shape differs — link stays soft */ }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_reps');
        Schema::dropIfExists('sales_agencies');
    }
};
