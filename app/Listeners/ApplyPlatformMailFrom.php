<?php

namespace App\Listeners;

use App\Models\PlatformSettings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * MARKER-PLATFORM-MAIL — stamps the platform sender onto outgoing mail that
 * has not set its own From.
 *
 * Why an event listener rather than boot-time config: this only runs when a
 * message is actually being sent, so there is no per-request database hit,
 * and tenant mail that sets its own sender is untouched by construction.
 *
 * Never throws: a settings problem must not stop mail from going out.
 */
class ApplyPlatformMailFrom
{
    public function handle(MessageSending $event): void
    {
        try {
            $email   = $event->message;
            $current = ($email->getFrom() ?: [])[0] ?? null;

            // Only fill in when nothing was set, or when the framework
            // placeholder would otherwise go out.
            $needsSender = $current === null
                || strcasecmp($current->getAddress(), PlatformSettings::PLACEHOLDER_ADDRESS) === 0;

            if (! $needsSender) {
                return;
            }

            $address = PlatformSettings::fromAddress();
            if (! $address) {
                return; // nothing configured anywhere — leave as-is
            }

            $email->from(new Address($address, PlatformSettings::fromName() ?? ''));
        } catch (\Throwable $e) {
            Log::warning('platform_mail_from.skipped', ['error' => $e->getMessage()]);
        }
    }
}
