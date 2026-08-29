<?php

namespace App\Http\Controllers\Webhooks;

// MARKER-SIGNING-SEND
use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Models\InvestorDocument;
use App\Models\InvestorEvent;
use App\Services\SigningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The only thing allowed to record that a SAFE was signed or executed.
 *
 * Dropbox Sign posts multipart form data with a "json" field, not a JSON body,
 * which is easy to miss and produces an empty payload if you read it the usual
 * way.
 *
 * The response body MUST be exactly "Hello API Event Received" — anything else,
 * including a perfectly good 200, is treated as a failed delivery and retried.
 */
class DropboxSignWebhookController extends Controller
{
    private const ACK = 'Hello API Event Received';

    public function handle(Request $request)
    {
        $raw     = $request->input('json');
        $payload = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($payload) || ! isset($payload['event'])) {
            Log::warning('MARKER-SIGNING-SEND callback with no event payload');

            return response(self::ACK, 200);
        }

        $event = $payload['event'];

        if (! SigningService::callbackIsValid($event)) {
            Log::warning('MARKER-SIGNING-SEND callback failed verification', [
                'type' => $event['event_type'] ?? null,
            ]);

            // Not acknowledged: an unverified caller should get nothing useful.
            return response('invalid', 403);
        }

        $type      = $event['event_type'] ?? '';
        $requestId = $payload['signature_request']['signature_request_id'] ?? null;

        // A callback we can't match to anyone is still acknowledged — retrying
        // it would never help.
        $investor = $requestId ? Investor::where('signature_request_id', $requestId)->first() : null;

        if (! $investor) {
            if ($type !== 'callback_test') {
                Log::info('MARKER-SIGNING-SEND callback for an unknown request', [
                    'type' => $type, 'request' => $requestId,
                ]);
            }

            return response(self::ACK, 200);
        }

        if ($type === 'signature_request_signed') {
            // One signer of two. Record it, but this is not execution.
            InvestorEvent::log($investor->id, 'safe_signed_by_party',
                'A party signed the SAFE (recorded from Dropbox Sign)');
        }

        if ($type === 'signature_request_all_signed') {
            $investor->forceFill(['signed_at' => $investor->signed_at ?: now()])->save();

            InvestorEvent::log($investor->id, 'safe_executed',
                'SAFE fully executed (recorded from Dropbox Sign)');

            $this->storeExecuted($investor, $requestId);
        }

        if ($type === 'signature_request_declined') {
            InvestorEvent::log($investor->id, 'safe_declined', 'The SAFE was declined in Dropbox Sign');
        }

        return response(self::ACK, 200);
    }

    /** Keep our own copy: their link expires, a file on disk does not. */
    private function storeExecuted(Investor $investor, string $requestId): void
    {
        $pdf = SigningService::downloadExecuted($requestId);

        if (! $pdf) {
            Log::warning('MARKER-SIGNING-SEND executed PDF could not be downloaded', [
                'investor' => $investor->id,
            ]);

            return;
        }

        $path = 'investors/' . $investor->id . '/safe-executed-' . $requestId . '.pdf';
        Storage::disk('local')->put($path, $pdf);

        InvestorDocument::updateOrCreate(
            ['investor_id' => $investor->id, 'path' => $path],
            [
                'label'               => 'SAFE, executed',
                'original_name'       => 'safe-executed.pdf',
                'mime'                => 'application/pdf',
                'size'                => strlen($pdf),
                'visible_to_investor' => true,
                'signed_at'           => now(),
            ]
        );
    }
}
