<?php
// MARKER-INVITE-DURABLE
// MARKER-INVITE-SCOPE — consume is tenant-scoped; the legacy cache key is gone.

namespace App\Support;

use Illuminate\Support\Facades\DB;

class TeamInvites
{
    /** Mark a token used, within the tenant that issued it. */
    public static function consume(string $tenantId, string $token): void
    {
        DB::table('tenant_team_invites')
            ->where('tenant_id', $tenantId)
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now(), 'updated_at' => now()]);
    }
}
