<?php

namespace App\Http\Controllers\Webhooks;

// MARKER-CONTRIBUTIONS
use App\Http\Controllers\Controller;
use App\Models\BillingSettings;
use App\Models\Contribution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only thing in the system allowed to mark a contribution paid.
 *
 * Same shape as the subscription webhook: verify the signature, dedupe on
 * event id, handle, always return 2xx unless the signature failed so Stripe
 * does not retry a message we understood and rejected.
 *
 * Route: POST /webhooks/stripe/contributions — covered by the existing
 * webhooks/stripe/* CSRF exemption.
 */
class ContributionWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = BillingSettings::current()->activeContribWebhookSecret();

        if (! $secret) {
            Log::warning('MARKER-CONTRIBUTIONS webhook hit with no signing secret configured');

            return response('not configured', 500);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret
            );
        } catch (\Throwable $e) {
            Log::warning('MARKER-CONTRIBUTIONS webhook verification failed', ['error' => $e->getMessage()]);

            return response('invalid signature', 400);
        }

        // Dedupe on the shared events table — a replayed event must not
        // double-count money. The table has received_at/processed_at, NOT
        // created_at/updated_at: writing the wrong columns would throw, get
        // swallowed as "already handled", and every contribution would look
        // processed while nothing was ever marked paid.
        try {
            DB::table('stripe_webhook_events')->insert([
                'event_id'    => $event->id,
                'type'        => $event->type,
                'received_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Only a duplicate key should land here. Anything else is a real
            // fault and must be visible rather than silently 200'd.
            if (! str_contains($e->getMessage(), 'Duplicate') && $e->getCode() !== '23000') {
                Log::error('MARKER-CONTRIBUTIONS dedupe insert failed', ['error' => $e->getMessage()]);

                return response('dedupe failed', 500);
            }

            return response('already handled', 200);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            if (($session->metadata->kind ?? null) !== 'contribution') {
                return response('not a contribution', 200);
            }

            $contribution = Contribution::where('stripe_session_id', $session->id)->first()
                ?: Contribution::find($session->client_reference_id ?? 0);

            if (! $contribution) {
                Log::warning('MARKER-CONTRIBUTIONS paid session with no row', ['session' => $session->id]);

                return response('no matching row', 200);
            }

            // Trust the amount Stripe reports, not the one we asked for.
            $contribution->update([
                'status'                => 'paid',
                'amount_cents'          => (int) ($session->amount_total ?? $contribution->amount_cents),
                'stripe_payment_intent' => $session->payment_intent ?? null,
                'paid_at'               => now(),
            ]);

            Log::info('MARKER-CONTRIBUTIONS paid', [
                'contribution' => $contribution->id,
                'amount'       => $contribution->amount_cents,
            ]);
        }

        if ($event->type === 'checkout.session.expired') {
            Contribution::where('stripe_session_id', $event->data->object->id)
                ->where('status', 'pending')
                ->update(['status' => 'expired']);
        }

        DB::table('stripe_webhook_events')
            ->where('event_id', $event->id)
            ->update(['processed_at' => now()]);

        return response('ok', 200);
    }
}
