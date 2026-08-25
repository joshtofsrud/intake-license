<?php
// MARKER-TENANT-STANDING

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_settings', 'past_due_grace_days')) {
                $table->unsignedSmallInteger('past_due_grace_days')->default(14);
            }
            if (! Schema::hasColumn('billing_settings', 'past_due_action')) {
                $table->string('past_due_action', 16)->default('lock');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->dropColumn(['past_due_grace_days', 'past_due_action']);
        });
    }
};
