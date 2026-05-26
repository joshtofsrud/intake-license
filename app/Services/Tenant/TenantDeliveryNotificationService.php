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
    public function sendScheduled(TenantDelivery $delivery): void
    {
        $delivery->loadMissing('customer');
        $customer = $delivery->customer;
        if (!$customer) {
            Log::warning('TenantDeliveryNotificationService: delivery has no customer', [
                'delivery_id' => $delivery->id,
            ]);
            return;
        }

        $vars = $this->buildVars($delivery);
        $channels = [];

        // EMAIL
        if (
            $this->tenant->notificationEnabled('delivery_scheduled_email')
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
            $this->tenant->notificationEnabled('delivery_scheduled_sms')
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
            return "{$shop}: We'll {$verb} your bike on {$when} ({$vars['window']}). Reply STOP to opt out.";
        }
        return "{$shop}: We'll {$verb} your bike on {$when} ({$vars['window']}) at {$vars['address']}. Reply STOP to opt out.";
    }
}
