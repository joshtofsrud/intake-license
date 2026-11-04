<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantEmailTemplate;
use App\Models\Tenant\TenantSpecialOrder;
use App\Services\EmailService;
use App\Services\Sms\SmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-SO-HANDOVER: "your part is here".
 *
 * A prompt, not an automatic send: after a shipment fills special orders (or
 * from the Arrived bench) the person sees who would be told and how, can
 * change the wording, and sends or skips. Channels follow the customer's
 * details and the shop's notification settings (Communication Center →
 * Special order arrived). A work-order part can also alert the team.
 */
class SpecialOrderNotifier
{
    public const TEMPLATE = 'special_order_arrived';

    public const DEFAULT_SUBJECT = '{{shop_name}}: your {{item}} is here';

    public const DEFAULT_BODY = "Hi {{first_name}}, your {{item}} is here and waiting for you at {{location}}. — {{shop_name}}";

    public function __construct(private readonly Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    private function smsReady(): bool
    {
        return (bool) ($this->tenant->sms_enabled && $this->tenant->twilio_account_sid && $this->tenant->twilio_auth_token);
    }

    /** The wording shown in the dialog: the shop's own template if it has one, else the default. */
    public function defaultMessage(): string
    {
        $tpl = TenantEmailTemplate::where('tenant_id', $this->tenant->id)->where('template_type', self::TEMPLATE)->first();
        if ($tpl && $tpl->is_enabled && trim((string) $tpl->body_html) !== '') {
            $text = trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $tpl->body_html)))));
            if ($text !== '') {
                return $text;
            }
        }

        return self::DEFAULT_BODY;
    }

    public function subject(): string
    {
        $tpl = TenantEmailTemplate::where('tenant_id', $this->tenant->id)->where('template_type', self::TEMPLATE)->first();

        return ($tpl && $tpl->is_enabled && trim((string) $tpl->subject) !== '') ? (string) $tpl->subject : self::DEFAULT_SUBJECT;
    }

    /**
     * One row per order for the dialog.
     *
     * @param  Collection<int, TenantSpecialOrder>  $orders
     */
    public function rows(Collection $orders): array
    {
        $emailOn = $this->tenant->notificationEnabled(self::TEMPLATE . '_email');
        $smsOn   = $this->tenant->notificationEnabled(self::TEMPLATE . '_sms');
        $smsReady = $this->smsReady();

        return $orders->map(function (TenantSpecialOrder $so) use ($emailOn, $smsOn, $smsReady) {
            $so->loadMissing('customer', 'appointment', 'location');
            $c = $so->customer;
            $email = $c?->email;
            $phone = $c?->phone;
            $notes = [];
            if ($so->appointment) {
                $notes[] = $so->appointment->ra_number;
            }
            $hold = SpecialOrderService::holdState($so);
            if ($so->location && $hold['at'] && $hold['at'] !== $so->location->name) {
                $notes[] = 'picks up at ' . $so->location->name;
            }

            $channels = [];
            $channels[] = [
                'key' => 'email', 'label' => 'Email',
                'available' => (bool) $email,
                'on' => (bool) $email && $emailOn,
                'why' => $email ? null : 'No email on file',
            ];
            $smsWhy = ! $phone ? 'No mobile number on file' : (! $smsReady ? 'Texting is not set up' : null);
            $channels[] = [
                'key' => 'sms', 'label' => 'Text',
                'available' => $smsWhy === null,
                'on' => $smsWhy === null && $smsOn,
                'why' => $smsWhy,
            ];
            if ($so->appointment_id) {
                $channels[] = ['key' => 'staff', 'label' => 'Alert staff', 'available' => true, 'on' => true, 'why' => null];
            }

            return [
                'id'        => $so->id,
                'so_number' => $so->so_number,
                'item'      => $so->item_name_snapshot,
                'who'       => $c ? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) : ($so->appointment ? 'Work order ' . $so->appointment->ra_number : 'Shop stock'),
                'note'      => implode(' · ', $notes),
                'channels'  => $channels,
                'told'      => $so->notified_at ? ($so->notified_via . ' · ' . tlocal_date($so->notified_at)) : null,
            ];
        })->values()->all();
    }

    /**
     * Send. $picks: [orderId => ['email' => bool, 'sms' => bool, 'staff' => bool]].
     * Returns ['email' => n, 'sms' => n, 'staff' => n, 'none' => n].
     */
    public function send(Collection $orders, array $picks, ?string $message): array
    {
        $message = trim((string) $message) !== '' ? mb_substr(trim((string) $message), 0, 1000) : $this->defaultMessage();
        $subjectTpl = $this->subject();
        $count = ['email' => 0, 'sms' => 0, 'staff' => 0, 'none' => 0];
        $svc = app(SpecialOrderService::class);

        foreach ($orders as $so) {
            $so->loadMissing('customer', 'appointment', 'location');
            $want = (array) ($picks[$so->id] ?? []);
            $c = $so->customer;
            $hold = SpecialOrderService::holdState($so);
            $vars = [
                'first_name' => $c?->first_name ?: 'there',
                'item'       => $so->item_name_snapshot,
                'location'   => $so->location?->name ?: ($hold['at'] ?: $this->tenant->name),
                'shop_name'  => $this->tenant->name,
                'so_number'  => $so->so_number,
            ];
            $text = self::fill($message, $vars);
            $via = [];

            if (! empty($want['email']) && $c?->email && $this->tenant->notificationEnabled(self::TEMPLATE . '_email')) {
                try {
                    $mail = EmailService::forTenant($this->tenant);
                    $html = $mail->renderHtml(nl2br(e($text)));
                    if ($mail->sendRendered(self::TEMPLATE, $c->email, self::fill($subjectTpl, $vars), $html)) {
                        $via[] = 'Email';
                        $count['email']++;
                    }
                } catch (\Throwable $e) {
                    Log::error('special_order.notify_email_failed', ['so' => $so->id, 'error' => $e->getMessage()]);
                }
            }
            if (! empty($want['sms']) && $c?->phone && $this->smsReady() && $this->tenant->notificationEnabled(self::TEMPLATE . '_sms')) {
                try {
                    SmsService::send($this->tenant, $c->phone, $text . ' Reply STOP to opt out.', 'other');
                    $via[] = 'Text';
                    $count['sms']++;
                } catch (\Throwable $e) {
                    Log::error('special_order.notify_sms_failed', ['so' => $so->id, 'error' => $e->getMessage()]);
                }
            }
            if (! empty($want['staff']) && $so->appointment_id) {
                app(StaffAlertService::class)->emit($this->tenant, 'special_order.arrived', [
                    'title' => "Part arrived for {$so->appointment?->ra_number}: {$so->item_name_snapshot}",
                    'body'  => "{$so->so_number} is on the bench" . ($hold['at'] ? " at {$hold['at']}" : '') . '.',
                    'link'  => route('tenant.special-orders.show', ['id' => $so->id]),
                ]);
                $via[] = 'Staff alerted';
                $count['staff']++;
            }

            if ($via) {
                $so->forceFill(['notified_at' => now(), 'notified_via' => implode(', ', $via)])->save();
                $svc->addNote($so->id, null, 'Told: ' . implode(', ', $via) . '.', true);
            } else {
                $count['none']++;
            }
        }

        return $count;
    }

    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? $m[0]), $text);
    }
}
