<?php
// MARKER-UNIVERSAL-SIGNATURE: one signature for Intake's emails, on
// platform_settings. Carries over the signature saved per admin before this.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'email_signature')) $t->text('email_signature')->nullable();
        });

        try {
            $current = DB::table('platform_settings')->where('id', 1)->value('email_signature');
            if ($current) return;
            $pick = null;
            foreach (DB::table('users')->whereNotNull('admin_prefs')->orderBy('id')->pluck('admin_prefs') as $raw) {
                $sig = (json_decode((string) $raw, true) ?: [])['signature'] ?? null;
                if (! is_array($sig)) continue;
                if (! $pick || (! empty($sig['enabled']) && empty($pick['enabled']))) $pick = $sig;
            }
            if ($pick) {
                if (DB::table('platform_settings')->where('id', 1)->exists()) {
                    DB::table('platform_settings')->where('id', 1)->update(['email_signature' => json_encode($pick)]);
                } else {
                    DB::table('platform_settings')->insert(['id' => 1, 'email_signature' => json_encode($pick), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
