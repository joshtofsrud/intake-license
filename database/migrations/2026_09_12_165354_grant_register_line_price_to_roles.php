<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-LINE-PRICE — grant register.line_price to every existing role.
 *
 * TenantRole::allowsCapability() treats a NULL capability list as full access,
 * but an explicit list as exhaustive. Every role edited on the Roles page has
 * an explicit list, so a newly registered key is denied by default — which
 * would take discounting away from staff who already do it, without anyone
 * asking for that.
 *
 * This preserves today's access exactly. Turning it off is then a deliberate
 * act on the Roles page rather than a side effect of a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenant_roles')->whereNotNull('capabilities')->get(['id', 'capabilities']) as $role) {
            $caps = json_decode((string) $role->capabilities, true);

            if (! is_array($caps) || in_array('register.line_price', $caps, true)) {
                continue;
            }

            $caps[] = 'register.line_price';

            DB::table('tenant_roles')->where('id', $role->id)
                ->update(['capabilities' => json_encode(array_values($caps))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('tenant_roles')->whereNotNull('capabilities')->get(['id', 'capabilities']) as $role) {
            $caps = json_decode((string) $role->capabilities, true);

            if (! is_array($caps)) {
                continue;
            }

            DB::table('tenant_roles')->where('id', $role->id)->update([
                'capabilities' => json_encode(array_values(array_diff($caps, ['register.line_price']))),
            ]);
        }
    }
};
