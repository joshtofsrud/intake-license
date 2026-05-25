<?php
// MARKER-PATCH-143

namespace App\Services;

use App\Mail\TestSendMail;
use App\Mail\WelcomeEmail;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TestEmailService — single service every "Send test" button calls
 * through. Future patches that add test buttons to other template
 * editors should add methods here rather than calling Mail::send
 * directly.
 *
 * Test sends:
 *   - Always log to debug_logs (via the existing LogMailEvents listener)
 *   - Carry an X-Mail-Is-Test header so logs can be filtered later
 *   - NEVER write to TenantCampaignSend, even for campaign tests
 *   - NEVER count toward campaign metrics
 */
class TestEmailService
{
    /**
     * Send the generic "Test email" with the tenant's currently-configured
     * From name / address / reply-to. Used by the Email sender details card.
     */
    public function sendSettingsTest(
        Tenant $tenant,
        string $recipient,
        ?string $overrideFromName = null,
        ?string $overrideFromAddress = null,
        ?string $overrideReplyTo = null,
    ): array {
        $fromName    = $overrideFromName    ?: $tenant->emailFromName();
        $fromAddress = $overrideFromAddress ?: $tenant->emailFromAddress();
        $replyTo     = $overrideReplyTo     ?: $tenant->email_reply_to;

        return $this->sendWith(fn () => Mail::to($recipient)->send(
            new TestSendMail($fromAddress, $fromName, $replyTo, $tenant->name)
        ), $recipient, 'settings_test', [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Send a test welcome email — useful for verifying the full system
     * mail path (from Intake to a tenant owner) without going through
     * a real signup. Used by master admin smoke tests.
     */
    public function sendWelcomeTest(Tenant $tenant, TenantUser $user, string $recipient): array
    {
        return $this->sendWith(fn () => Mail::to($recipient)->send(
            new WelcomeEmail($tenant, $user, 'TEMP_TEST_PASSWORD', 'test')
        ), $recipient, 'welcome_test', [
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
        ]);
    }

    /**
     * Wrap a send closure with try/catch + log. Returns shape:
     *   ['ok' => bool, 'message' => string]
     */
    protected function sendWith(callable $sendFn, string $recipient, string $kind, array $context = []): array
    {
        try {
            $sendFn();
            Log::info('Test email sent', array_merge([
                'kind'      => $kind,
                'recipient' => $recipient,
            ], $context));
            return ['ok' => true, 'message' => 'Sent to ' . $recipient];
        } catch (Throwable $e) {
            Log::error('Test email failed', array_merge([
                'kind'      => $kind,
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
            ], $context));
            return ['ok' => false, 'message' => 'Send failed: ' . $e->getMessage()];
        }
    }
}
