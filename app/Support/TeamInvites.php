<?php
// MARKER-INVITE-DURABLE

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TeamInvites
{
    /** Mark a token used (and clear any legacy cache copy). */
    public static function consume(string $token): void
    {
        DB::table('tenant_team_invites')
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now(), 'updated_at' => now()]);
        Cache::forget('team_invite_' . $token);
    }
}
