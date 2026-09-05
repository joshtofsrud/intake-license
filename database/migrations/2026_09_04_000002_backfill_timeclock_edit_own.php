<?php
// MARKER-TC-EDIT-SCOPE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * timeclock.edit_own is new, and a role whose capability set is already
 * materialised will not pick up a new registry key on its own. Every role that
 * can already edit ANYONE'S punches is granted it here — no behaviour change,
 * it is a strict subset of what they hold. Roles with capabilities = NULL mean
 * "all capabilities" and need nothing. Staff is deliberately left alone so the
 * grant is a deliberate act in Roles & access.
 */
return new class extends Migration
{
    public function up(): void
    {
        $n = 0;
        DB::table('tenant_roles')->whereNotNull('capabilities')->orderBy('id')
            ->each(function ($role) use (&$n) {
                $caps = json_decode($role->capabilities ?? '[]', true);
                if (! is_array($caps)) {
                    return;
                }
                if (! in_array('timeclock.edit', $caps, true)) {
                    return;
                }
                if (in_array('timeclock.edit_own', $caps, true)) {
                    return;
                }
                $caps[] = 'timeclock.edit_own';
                DB::table('tenant_roles')->where('id', $role->id)
                    ->update(['capabilities' => json_encode(array_values($caps))]);
                $n++;
            });

        Log::info("MARKER-TC-EDIT-SCOPE: granted timeclock.edit_own to {$n} role(s)");
    }

    public function down(): void
    {
        DB::table('tenant_roles')->whereNotNull('capabilities')->orderBy('id')
            ->each(function ($role) {
                $caps = json_decode($role->capabilities ?? '[]', true);
                if (! is_array($caps) || ! in_array('timeclock.edit_own', $caps, true)) {
                    return;
                }
                $caps = array_values(array_diff($caps, ['timeclock.edit_own']));
                DB::table('tenant_roles')->where('id', $role->id)
                    ->update(['capabilities' => json_encode($caps)]);
            });
    }
};
