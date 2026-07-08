<?php
// MARKER-PATCH-152C

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantDelivery;
use App\Services\EmailService;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\Log;

/**
 * TenantDeliveryNotificationService — sends customer notifications
 * when a delivery is scheduled.
 *
 * Channels used:
 *   - Email via existing EmailService (template key: delivery_pickup_scheduled
 *     or delivery_dropoff_scheduled). Suppression list is honored automatically
 *     inside EmailService.
 *   - SMS via existing SmsService static helper.
 *
 * Per-event channels respect $tenant->notificationEnabled('delivery_scheduled_email')
 * and ...('delivery_scheduled_sms'). Default ON for both unless the tenant
 * explicitly toggled them off in settings.
 *
 * Records what was sent on the delivery row via notified_at +
 * notification_channels (e.g. "email,sms" or "email" or null).
 */
class TenantDeliveryNotificationService
{
    public function __construct(private readonly Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Send the "scheduled" notification for a delivery via the
     * channels enabled for this tenant. Idempotent on the record:
     * sets notified_at + notification_channels.
     *
     * Safe to call from a controller — catches all internal exceptions
     * so a notification failure never breaks the user-facing save flow.
     */
    public function sendScheduled(TenantDelivery $delivery, ?array $only = null): array // MARKER-PATCH-608 — returns channels actually sent (was void)
    {
        $allow = fn (string $ch) => $only === null || in_array($ch, $only, true);
        $delivery->loadMissing('customer');
        $customer = $delivery->customer;
        if (!$customer) {
            Log::warning('TenantDeliveryNotificationService: delivery has no customer', [
                'delivery_id' => $delivery->id,
            ]);
            return []; // MARKER-PATCH-608
        }

        $vars = $this->buildVars($delivery);
        $channels = [];

        // EMAIL
        if (
            $allow('email') // MARKER-PATCH-534
            && $this->tenant->notificationEnabled('delivery_scheduled_email')
            && !empty($customer->email)
        ) {
            try {
                $templateKey = $delivery->isPickup()
                    ? 'delivery_pickup_scheduled'
                    : 'delivery_dropoff_scheduled';
                EmailService::forTenant($this->tenant)->send(
                    $templateKey,
                    $customer->email,
                    $vars
                );
                $channels[] = 'email';
            } catch (\Throwable $e) {
                Log::error('Delivery email send failed', [
                    'delivery_id' => $delivery->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // SMS
        if (
            $allow('sms') // MARKER-PATCH-534
            && $this->tenant->notificationEnabled('delivery_scheduled_sms')
            && !empty($customer->phone)
        ) {
            try {
                $body = $this->renderSmsBody($delivery, $vars);
                SmsService::send($this->tenant, $customer->phone, $body);
                $channels[] = 'sms';
            } catch (\Throwable $e) {
                Log::error('Delivery SMS send failed', [
                    'delivery_id' => $delivery->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if (!empty($channels)) {
            $delivery->update([
                'notified_at'           => now(),
                'notification_channels' => implode(',', $channels),
            ]);
        }

        return $channels; // MARKER-PATCH-608
    }

    /**
     * Send the 24-hour reminder for a delivery via the channels
     * enabled for this tenant. Mirrors sendScheduled() but uses
     * the *_reminder template keys and reminder-specific toggles.
     *
     * Caller (deliveries:remind command) is responsible for stamping
     * reminded_at — we just send. Different from sendScheduled which
     * stamps notified_at internally, since the cron needs the stamp
     * to land even on full-failure to prevent retries.
     *
     * MARKER-PATCH-155
     */
    public function sendReminder(TenantDelivery $delivery): array
    {
        $delivery->loadMissing('customer');
        $customer = $delivery->customer;
        if (!$customer) {
            Log::warning('Delivery reminder: no customer', [
                'delivery_id' => $delivery->id,
            ]);
            return [];
        }

        $vars = $this->buildVars($delivery);
        // Re-frame the date phrase for "tomorrow" context
        $vars['when_human'] = 'tomorrow, ' . $vars['date_human'] . ' at ' . $vars['time_start'];
        $vars['when_sms']   = 'tomorrow (' . $vars['date_short'] . ' at ' . $vars['time_start'] . ')';

        $channels = [];

        if (
            $this->tenant->notificationEnabled('delivery_reminder_email')
            && !empty($customer->email)
        ) {
            try {
                $templateKey = $delivery->isPickup()
                    ? 'delivery_pickup_reminder'
                    : 'delivery_dropoff_reminder';
                EmailService::forTenant($this->tenant)->send(
                    $templateKey,
                    $customer->email,
                    $vars
                );
                $channels[] = 'email';
            } catch (\Throwable $e) {
                Log::error('Delivery reminder email failed', [
                    'delivery_id' => $delivery->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if (
            $this->tenant->notificationEnabled('delivery_reminder_sms')
            && !empty($customer->phone)
        ) {
            try {
                $body = $this->renderReminderSmsBody($delivery, $vars);
                SmsService::send($this->tenant, $customer->phone, $body);
                $channels[] = 'sms';
            } catch (\Throwable $e) {
                Log::error('Delivery reminder SMS failed', [
                    'delivery_id' => $delivery->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $channels;
    }

    /**
     * Build the variable map used by both email templates and the SMS body.
     */
    private function buildVars(TenantDelivery $delivery): array
    {
        $tz       = $this->tenant->timezone ?? config('app.timezone', 'UTC');
        $start    = $delivery->scheduled_at->copy()->setTimezone($tz);
        $end      = $start->copy()->addMinutes($delivery->window_minutes ?: 30);
        $customer = $delivery->customer;

        return [
            'first_name'       => $customer->first_name ?? '',
            'last_name'        => $customer->last_name ?? '',
            'shop_name'        => $this->tenant->name,
            'delivery_type'    => $delivery->type,
            'type_verb'        => $delivery->isPickup() ? 'pick up' : 'drop off',
            'type_noun'        => $delivery->isPickup() ? 'pickup'  : 'dropoff',
            'asset_noun'       => $this->tenant->asset_label_singular ?: 'order', // MARKER-PATCH-535
            'date_human'       => $start->format('l, F j'),
            'date_short'       => $start->format('M j'),
            'time_start'       => $start->format('g:i A'),
            'time_end'         => $end->format('g:i A'),
            'window'           => $start->format('g:i') . ' – ' . $end->format('g:i A'),
            'address'          => $delivery->address ?: 'on file',
            'notes'            => $delivery->notes ?: '',
            'accent'           => $this->tenant->accent_color ?? '#BEF264',
            'accent_text'      => \App\Support\ColorHelper::accentTextColor(
                                      $this->tenant->accent_color ?? '#BEF264'
                                  ),
        ];
    }

    /**
     * SMS body. Kept short — 160-char SMS is the friendly target,
     * but we don't hard-truncate (Twilio handles multi-part).
     */
    private function renderSmsBody(TenantDelivery $delivery, array $vars): string
    {
        $shop = $vars['shop_name'];
        $verb = $vars['type_verb'];
        $when = $vars['date_short'] . ' at ' . $vars['time_start'];
        if ($delivery->isPickup()) {
            return "{$shop}: We'll {$verb} your {$vars['asset_noun']} on {$when} ({$vars['window']}). Reply STOP to opt out."; // MARKER-PATCH-535
        }
        return "{$shop}: We'll {$verb} your {$vars['asset_noun']} on {$when} ({$vars['window']}) at {$vars['address']}. Reply STOP to opt out."; // MARKER-PATCH-535
    }

    /**
     * SMS body for the 24-hour reminder. Tomorrow-framed.
     * MARKER-PATCH-155
     */
    private function renderReminderSmsBody(TenantDelivery $delivery, array $vars): string
    {
        $shop = $vars['shop_name'];
        $verb = $vars['type_verb'];
        if ($delivery->isPickup()) {
            return "{$shop}: Reminder \u{2014} we'll {$verb} your {$vars['asset_noun']} {$vars['when_sms']}. Reply STOP to opt out."; // MARKER-PATCH-535
        }
        return "{$shop}: Reminder \u{2014} we'll {$verb} your {$vars['asset_noun']} {$vars['when_sms']} at {$vars['address']}. Reply STOP to opt out."; // MARKER-PATCH-535
    }
}

