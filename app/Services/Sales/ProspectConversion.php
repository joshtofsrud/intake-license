<?php
// MARKER-SALES-INVITE — the prospect → tenant handoff, in one place.

namespace App\Services\Sales;

use App\Mail\SalesTrialInvite;
use App\Models\SalesProspect;
use App\Models\Tenant;
use App\Services\EmailLedger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProspectConversion
{
    public const SESSION_KEY = 'sales_invite';

    /** Email the owner a signup link that carries this prospect's token. */
    public static function invite(SalesProspect $p, string $email, string $plan, ?string $ownerName, ?string $message, ?string $sentBy, ?string $replyTo): void
    {
        $p->update([
            'invite_token'  => $p->invite_token ?: Str::random(40),
            'invite_email'  => $email,
            'invite_plan'   => $plan,
            'invited_at'    => now(),
            'email'         => $p->email ?: $email,
            'owner_contact' => $p->owner_contact ?: ($ownerName ?: null),
        ]);
        $p->refresh();

        $log = EmailLedger::platform($email, 'sales_trial_invite');
        Mail::to($email)->send(new SalesTrialInvite($p, self::signupUrl($p), $ownerName, $message, $sentBy, $replyTo));
        if ($log) EmailLedger::markSent($log);

        $p->activities()->create(['type' => 'email', 'body' => "Trial invite sent to $email · " . ucfirst($plan)]);
        $changes = ['last_contacted_at' => now()];
        if (! $p->next_action_on) {
            $changes['next_action_on'] = now()->addDays(3)->toDateString();
            $changes['next_action']    = 'Follow up on trial invite';
        }
        if (in_array($p->stage, ['prospect', 'verifying'], true)) $changes['stage'] = 'contacted';
        $p->update($changes);
    }

    public static function signupUrl(SalesProspect $p): string
    {
        return route('platform.signup', ['invite' => $p->invite_token]);
    }

    public static function fromToken(?string $token): ?SalesProspect
    {
        if (! $token || strlen($token) > 64) return null;
        return SalesProspect::query()->where('invite_token', $token)->whereNull('tenant_id')->first();
    }

    /** Called right after a self-serve signup creates its tenant. Links it when the session carries an invite. */
    public static function linkFromSession(Tenant $tenant): void
    {
        try {
            $id = session(self::SESSION_KEY);
            if (! $id) return;
            $p = SalesProspect::query()->whereKey($id)->whereNull('tenant_id')->first();
            if ($p) self::link($p, $tenant, 'Signed up from the trial invite');
            session()->forget(self::SESSION_KEY);
        } catch (\Throwable $e) {
            Log::warning('[SalesInvite] link from session failed (non-fatal)', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }

    /** Link a tenant to a prospect (invite flow or by hand) and put the card in the right stage. */
    public static function link(SalesProspect $p, Tenant $tenant, ?string $note = null): void
    {
        $p->update(['tenant_id' => $tenant->id, 'converted_at' => $p->converted_at ?: now()]);
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $tenant->update(['settings' => array_merge($settings, ['sales_prospect_id' => $p->id])]);
        $p->activities()->create(['type' => 'system', 'body' => ($note ?: 'Linked to tenant') . ' · ' . $tenant->name . ' (' . $tenant->subdomain . ')']);

        $paid = in_array($tenant->subscription_status, ['active', 'past_due'], true) && $tenant->stripe_subscription_id;
        if ($paid) {
            $p->advanceTo('won', 'Subscription active');
        } elseif (! in_array($p->stage, ['won', 'lost'], true)) {
            $p->advanceTo('trial', 'Tenant created');
        }
    }

    /** Called from the Stripe webhook when an invoice is paid. */
    public static function onTenantActive(Tenant $tenant): void
    {
        $p = SalesProspect::query()->where('tenant_id', $tenant->id)->first();
        if (! $p || in_array($p->stage, ['won', 'lost'], true)) return;
        $p->update(['converted_at' => $p->converted_at ?: now()]);
        $p->advanceTo('won', 'First invoice paid');
    }
}
