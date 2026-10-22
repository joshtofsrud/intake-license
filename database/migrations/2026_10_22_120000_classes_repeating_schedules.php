<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeating class schedules. A schedule repeats one class type at one time
 * on the days chosen; its classes are created ahead on a rolling window and
 * linked back by schedule_id. Additive and safe to re-run. MARKER-CLASSES-P7
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_class_schedules')) {
            Schema::create('tenant_class_schedules', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->uuid('class_template_id');
                $t->json('days');                       // [0..6], 0 = Sunday
                $t->char('start_time', 5);              // studio wall clock, H:i
                $t->unsignedInteger('capacity')->nullable();
                $t->uuid('instructor_resource_id')->nullable();
                $t->string('notes', 1000)->nullable();
                $t->date('starts_on');
                $t->date('ends_on')->nullable();        // null = keeps going
                $t->json('skip_dates')->nullable();     // dates removed by hand, never recreated
                $t->timestamps();
                $t->index(['tenant_id', 'ends_on']);
            });
        }
        if (! Schema::hasColumn('tenant_class_sessions', 'schedule_id')) {
            Schema::table('tenant_class_sessions', function (Blueprint $t) {
                $t->uuid('schedule_id')->nullable()->after('class_template_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_class_sessions', 'schedule_id')) {
            Schema::table('tenant_class_sessions', fn (Blueprint $t) => $t->dropColumn('schedule_id'));
        }
        Schema::dropIfExists('tenant_class_schedules');
    }
};
