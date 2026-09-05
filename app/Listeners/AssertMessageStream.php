<?php
// MARKER-STREAM-ASSERT

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Every message declares its Postmark stream. Anything that reaches the
 * transport without an X-PM-Message-Stream header is transactional and is
 * stamped 'outbound' here; campaign sends set the broadcast stream in their
 * own builder and are left alone. Being explicit per send means a future
 * Postmark default change, or a new send path, can never silently move
 * transactional mail onto the broadcast stream or vice versa.
 */
class AssertMessageStream
{
    public function handle(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();
        if (! $headers->has('X-PM-Message-Stream')) {
            $headers->addTextHeader('X-PM-Message-Stream', 'outbound');
        }
    }
}
