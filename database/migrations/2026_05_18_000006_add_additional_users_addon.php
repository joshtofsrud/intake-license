<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the 'additional_users' addon row.
 *
 * Bundled with Branded + Scale, not separately sold. Gates two things:
 *   1. The "Add staff member" action on the staff admin screen
 *   2. The PIN tier auth flow (Layers 2 + 3 of the auth refactor)
 *
 * Starter tenants are hard-capped at 1 user. They never see Add User,
 * never see PIN flows. Branded+ tenants can add users; once they have
 * 2+, the PIN tier activates automatically.
 *
 * Pattern matches 'extended_reports' (and 'retail') — capability flag,
 * not a sellable product, hence is_self_serve=0 and price 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('addons')->where('code', 'additional_users')->exists();
        if ($exists) {
            return;
        }

        $now = now();

        DB::table('addons')->insert([
            'code' => 'additional_users',
            'name' => 'Additional Users',
            'category' => 'team',
            'description' => 'Add team members with their own sign-in. Each staff member gets a 4-digit PIN for quick sign-in on shared devices. Included free with Branded and Scale.',
            'tooltip' => 'Multiple staff sign-ins with PIN auth on shared devices. Idle lock and per-action confirmation for sensitive operations.',
            'price_cents' => 0,
            'billing_cadence' => 'monthly',
            'price_display_override' => 'Included',
            'included_in_plans' => json_encode(['branded', 'scale']),
            'sort_order' => 110,
            'status' => 'active',
            'is_self_serve' => 0,
            'is_new' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->where('code', 'additional_users')->delete();
    }
};
