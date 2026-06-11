<?php
// MARKER-PATCH-221

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantMessage;
use App\Services\Sms\SmsService;
use App\Services\Tenant\InboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inbound SMS webhook (Twilio).
 *
 * Posture: ALWAYS answer 2xx (fail-open on third-party callbacks — the
 * project rule), but never process what we can't trust or resolve:
 *   - unknown To number       -> log, 200, no processing
 *   - bad Twilio signature    -> log, 200, no processing
 *   - duplicate MessageSid    -> 200, no processing (Twilio retries)
 *
 * Intent order:
 *   1. STOP family  -> opt-out + system event (Twilio auto-confirms STOP)
 *   2. START family -> opt-in restored + system event
 *   3. pending-offer YES/NO   -> stub hook for the extension patch
 *   4. everything else        -> thread (needs_reply)
 *
 * Unknown senders become a customer with phone + a synthetic
 * sms-{digits}@sms.invalid email (email is NOT NULL + tenant-unique;
 * .invalid is RFC-2606 non-routable and obviously synthetic).
 */
class TwilioInboundController extends Controller
{
    private const STOP_WORDS  = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'];
    private const START_WORDS = ['start', 'unstop'];

    public function handle(Request $request, InboxService $inbox)
    {
        $to   = SmsService::normalizePhone((string) $request->input('To', ''));
        $from = SmsService::normalizePhone((string) $request->input('From', ''));
        $body = trim((string) $request->input('Body', ''));
        $sid  = (string) $request->input('MessageSid', '');

        if (!$to || !$from) {
            Log::warning('twilio_inbound.missing_numbers', ['to' => $request->input('To'), 'from' => $request->input('From')]);
            return $this->twiml();
        }

        $tenant = Tenant::where('sms_from_number', $to)
            ->orWhere('sms_from_number', $request->input('To'))
            ->first();
        if (!$tenant) {
            Log::warning('twilio_inbound.unknown_to_number', ['to' => $to]);
            return $this->twiml();
        }

        if (!$this->signatureOk($request, $tenant)) {
            Log::warning('twilio_inbound.bad_signature', ['tenant_id' => $tenant->id]);
            return $this->twiml(); // 2xx, but DO NOT process
        }

        // Dedupe on Twilio's retry behavior.
        if ($sid !== '' && TenantMessage::where('external_id', $sid)->exists()) {
            return $this->twiml();
        }

        $customer = $this->resolveCustomer($tenant, $from);
        $thread   = $inbox->threadFor($tenant, $customer, 'sms');
        $lower    = strtolower($body);

        // 1. STOP family — regulatory. Twilio auto-replies to STOP itself.
        if (in_array($lower, self::STOP_WORDS, true)) {
            $customer->update(['sms_opt_out_at' => now()]);
            $inbox->postSystem($thread, 'Customer texted STOP — opted out of SMS.', ['sid' => $sid]);
            return $this->twiml();
        }

        // 2. START family — opt back in.
        if (in_array($lower, self::START_WORDS, true) && $customer->sms_opt_out_at !== null) {
            $customer->update(['sms_opt_out_at' => null, 'sms_consent_source' => 'sms_start']);
            $inbox->postSystem($thread, 'Customer texted START — opted back in to SMS.', ['sid' => $sid]);
            return $this->twiml();
        }

        // 3. MARKER-PATCH-221-OFFER-HOOK — the extension patch replaces this
        //    stub: YES/NO against a pending offer resolves the offer instead
        //    of landing as a plain thread message.
        if ($this->handleOfferIntent($tenant, $customer, $thread, $lower, $sid)) {
            return $this->twiml();
        }

        // 4. Plain message -> thread, needs reply.
        $inbox->postInbound($thread, $body !== '' ? $body : '(empty message)', $sid ?: null);

        return $this->twiml();
    }

    /** Stub until the extension patch. Returning false = not an offer reply. */
    protected function handleOfferIntent(Tenant $tenant, TenantCustomer $customer, $thread, string $lowerBody, string $sid): bool
    {
        return false;
    }

    private function resolveCustomer(Tenant $tenant, string $e164): TenantCustomer
    {
        $digits = preg_replace('/\D+/', '', $e164);
        $last10 = substr($digits, -10);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->whereRaw("REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') LIKE ?", ['%' . $last10])
            ->orderBy('created_at')
            ->first();

        if ($customer) {
            return $customer;
        }

        return TenantCustomer::create([
            'id'                 => (string) Str::uuid(),
            'tenant_id'          => $tenant->id,
            'first_name'         => 'Text',
            'last_name'          => 'Contact ' . substr($last10, -4),
            'email'              => 'sms-' . $digits . '@sms.invalid',
            'phone'              => $e164,
            'sms_consent_source' => 'inbound_sms',
        ]);
    }

    /**
     * Twilio request signature: base64(HMAC-SHA1(url + sorted POST params,
     * auth token)). Validated against the tenant's token (platform env
     * fallback). No token configured -> accept (dev/null-driver tenants).
     */
    private function signatureOk(Request $request, Tenant $tenant): bool
    {
        $token = $tenant->twilio_auth_token ?: config('services.twilio.token'); // MARKER-PATCH-224B
        if (!$token) {
            return true;
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '') {
            return false;
        }

        $url  = $request->fullUrl();
        $data = $request->post();
        ksort($data);
        $payload = $url;
        foreach ($data as $key => $value) {
            $payload .= $key . $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $payload, $token, true));

        return hash_equals($expected, $signature);
    }

    private function twiml()
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
            ->header('Content-Type', 'text/xml');
    }
}
