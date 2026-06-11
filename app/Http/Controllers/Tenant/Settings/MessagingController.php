<?php
// MARKER-PATCH-224

namespace App\Http\Controllers\Tenant\Settings;

use App\Http\Controllers\Controller;
use App\Services\FeatureAccessService;
use App\Services\Sms\SmsService;
use App\Services\Sms\TwilioProvisioningService;
use Illuminate\Http\Request;

/**
 * Settings -> Messaging. Owns ALL tenant SMS configuration (moved out of
 * the general settings save in patch-224 so an unrelated save can never
 * wipe it). Two modes:
 *   Managed — Intake-provisioned number, platform credentials (primary).
 *   BYO     — tenant's own Twilio (advanced / custom tier).
 */
class MessagingController extends Controller
{
    public function __construct(
        protected TwilioProvisioningService $provisioning,
        protected FeatureAccessService $features,
    ) {}

    public function index()
    {
        $tenant = tenant();

        $mode = 'none';
        if ($tenant->sms_from_number && $tenant->twilio_account_sid) {
            $mode = 'byo';
        } elseif ($tenant->sms_from_number) {
            $mode = 'managed';
        }

        return view('tenant.settings.messaging', [
            'mode'            => $mode,
            'hasSmsAddon'     => $this->features->hasAddon($tenant, 'sms_notifications'),
            'platformReady'   => $this->provisioning->platformConfigured(),
            'inboundUrl'      => $this->provisioning->inboundUrl(),
            'hasTwilioToken'  => (bool) $tenant->twilio_auth_token,
        ]);
    }

    /** JSON number search for the claim flow. */
    public function search(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'type'      => ['required', 'in:tollfree,local'],
            'area_code' => ['required_if:type,local', 'nullable', 'digits:3'],
        ]);

        if (!$this->features->hasAddon($tenant, 'sms_notifications')) {
            return response()->json(['ok' => false, 'error' => 'The SMS notifications add-on is required.'], 403);
        }
        if (!$this->provisioning->platformConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Number provisioning is not available yet.'], 422);
        }

        try {
            $numbers = $request->input('type') === 'tollfree'
                ? $this->provisioning->searchTollFree()
                : $this->provisioning->searchLocal($request->input('area_code'));
        } catch (\Throwable $e) {
            \Log::error('messaging.search_failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not search numbers right now.'], 500);
        }

        return response()->json(['ok' => true, 'numbers' => $numbers]);
    }

    /** Claim = purchase on the platform account + webhook wired at birth. */
    public function claim(Request $request)
    {
        $tenant = tenant();

        $request->validate(['number' => ['required', 'string', 'max:20']]);

        if (!$this->features->hasAddon($tenant, 'sms_notifications')) {
            return back()->withErrors(['number' => 'The SMS notifications add-on is required to claim a number.']);
        }
        if ($tenant->sms_from_number) {
            return back()->withErrors(['number' => 'This business already has a number. Contact support to change it.']);
        }
        if (!$this->provisioning->platformConfigured()) {
            return back()->withErrors(['number' => 'Number provisioning is not available yet.']);
        }

        try {
            $this->provisioning->purchase($tenant, $request->input('number'));
        } catch (\Throwable $e) {
            \Log::error('messaging.claim_failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return back()->withErrors(['number' => 'Could not claim that number — it may have just been taken. Search again.']);
        }

        return redirect()->route('tenant.settings.messaging')
            ->with('flash', 'Your text number is live: ' . $tenant->fresh()->sms_from_number);
    }

    /** BYO credentials save (token keeps existing value when left blank). */
    public function saveByo(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'sms_enabled'        => ['nullable', 'boolean'],
            'sms_from_number'    => ['nullable', 'string', 'max:32'],
            'twilio_account_sid' => ['nullable', 'string', 'max:64'],
            'twilio_auth_token'  => ['nullable', 'string', 'max:128'],
        ]);

        if ($tenant->twilio_number_sid) {
            return back()->withErrors(['sms_from_number' => 'This business uses an Intake-managed number — BYO fields are locked.']);
        }

        $newToken = $request->input('twilio_auth_token');
        $tokenToSave = ($newToken !== null && $newToken !== '') ? $newToken : $tenant->twilio_auth_token;

        $fromNumber = $request->input('sms_from_number')
            ? SmsService::normalizePhone($request->input('sms_from_number'))
            : null;

        $tenant->update([
            'sms_enabled'        => (bool) $request->input('sms_enabled'),
            'sms_from_number'    => $fromNumber,
            'twilio_account_sid' => $request->input('twilio_account_sid') ?: null,
            'twilio_auth_token'  => $tokenToSave,
        ]);

        return redirect()->route('tenant.settings.messaging')->with('flash', 'Messaging settings saved.');
    }

    /** BYO: point their number's inbound webhook at our URL. */
    public function syncWebhook(Request $request)
    {
        $tenant = tenant();

        try {
            $this->provisioning->syncInboundWebhookByo($tenant);
        } catch (\Throwable $e) {
            return back()->withErrors(['sms_from_number' => $e->getMessage()]);
        }

        return redirect()->route('tenant.settings.messaging')
            ->with('flash', 'Inbound webhook configured — replies will land in your Inbox.');
    }
}
