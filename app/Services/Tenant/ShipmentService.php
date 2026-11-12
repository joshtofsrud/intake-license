<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantOrderShipment;
use App\Models\Tenant\TenantSpecialOrder;
use App\Services\EmailService;
use Illuminate\Support\Facades\Log;

/** MARKER-SHIPMENT-WORKFLOW: staff moving a shipment along, and telling the customer. */
class ShipmentService
{
    public function __construct(protected Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self { return new self($tenant); }

    public function markShipped(TenantOrderShipment $sh, string $carrier, string $tracking, bool $notify): void
    {
        $this->allow($sh, 'ship');
        $carrier = array_key_exists($carrier, ShipmentFlow::carriers()) ? $carrier : 'other';
        $first   = $sh->status !== 'shipped';
        $sh->forceFill([
            'status' => 'shipped', 'carrier' => $carrier, 'tracking_number' => trim($tracking) ?: null,
            'shipped_at' => $sh->shipped_at ?: now(),
        ])->save();
        $this->sync($sh);
        if ($notify) $this->email($sh, 'order_shipped', [
            'what_next' => $first ? 'It is on its way.' : 'We updated the tracking details for your shipment.',
        ]);
    }

    public function markOutForDelivery(TenantOrderShipment $sh, bool $notify): void
    {
        $this->allow($sh, 'out_for_delivery');
        $sh->forceFill(['status' => 'out_for_delivery', 'out_for_delivery_at' => now()])->save();
        $this->sync($sh);
        if ($notify) $this->email($sh, 'order_out_for_delivery', ['what_next' => 'It should arrive today.']);
    }

    public function markDelivered(TenantOrderShipment $sh): void
    {
        $this->allow($sh, 'delivered');
        $sh->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
        $this->sync($sh);
    }

    /** Supplier couldn't send it right away: it becomes a special order the shop tracks. */
    public function specialOrder(TenantOrderShipment $sh, ?string $note, bool $notify): void
    {
        $this->allow($sh, 'special_order');
        $order = $sh->order;
        $sh->loadMissing('items.orderItem');
        $made = 0;
        if ($order->customer_id && $order->sale_id) {
            foreach ($sh->items as $it) {
                if (! $it->orderItem?->inventory_item_id) continue;
                try {
                    app(SpecialOrderService::class)->create([
                        'tenant_id' => $this->tenant->id, 'inventory_item_id' => $it->orderItem->inventory_item_id,
                        'item_name_snapshot' => $it->orderItem->name_snapshot, 'quantity' => (int) $it->quantity,
                        'customer_id' => $order->customer_id, 'sale_id' => $order->sale_id,
                        'status' => TenantSpecialOrder::STATUS_NEEDED, 'created_from' => 'online',
                        'notes' => 'Online order ' . $order->order_number . ' — supplier could not ship this right away.',
                    ]);
                    $made++;
                } catch (\Throwable $e) {
                    Log::error('shipment.special_order_failed', ['shipment' => $sh->id, 'error' => $e->getMessage()]);
                }
            }
        }
        $sh->forceFill(['status' => 'special_order', 'note' => $note ? mb_substr($note, 0, 300) : null])->save();
        if ($notify) $this->email($sh, 'order_special_order', [
            'what_next' => $note ? e($note) : 'We are getting these in for you and will email you the moment they ship.',
        ]);
    }

    /** Supplier can't supply it at all. Money moves from the register, so the email says so plainly. */
    public function cancel(TenantOrderShipment $sh, ?string $reason, bool $notify): void
    {
        $this->allow($sh, 'cancel');
        $sh->forceFill(['status' => 'cancelled', 'note' => $reason ? mb_substr($reason, 0, 300) : null])->save();
        $this->sync($sh);
        if ($notify) {
            $sh->loadMissing('items.orderItem');
            $cents = (int) $sh->shipping_cents;
            foreach ($sh->items as $it) $cents += (int) $it->quantity * (int) ($it->orderItem?->unit_price_cents ?? 0);
            $this->email($sh, 'order_shipment_cancelled', [
                'reason'    => $reason ? e($reason) : 'We are not able to get these to you.',
                'money_line' => 'We are refunding ' . self::money($cents) . ' for this part of your order.',
            ]);
        }
    }

    public function resend(TenantOrderShipment $sh): void
    {
        $key = match ($sh->status) {
            'shipped' => 'order_shipped', 'out_for_delivery' => 'order_out_for_delivery',
            'special_order' => 'order_special_order', 'cancelled' => 'order_shipment_cancelled', default => null,
        };
        abort_unless($key, 422);
        $extra = $key === 'order_special_order' ? ['what_next' => $sh->note ? e($sh->note) : 'We will email you the moment it ships.']
              : ($key === 'order_shipment_cancelled' ? ['reason' => e($sh->note ?: 'We are not able to get these to you.'), 'money_line' => 'Your refund for this part of the order is on its way.'] : ['what_next' => '']);
        $this->email($sh, $key, $extra);
    }

    /** Refund email, from the register refund of an online order's sale. */
    public function notifyRefund(string $originalSaleId, int $cents, string $method): void
    {
        $order = TenantOrder::where('tenant_id', $this->tenant->id)->where('sale_id', $originalSaleId)->first();
        // MARKER-SHOP-GA4: a refund lowers the shop's revenue in Analytics too (needs the API secret and the visit's ids)
        if ($order && ! empty(((array) $order->metadata)['ga'])) {
            \App\Support\ShopGa4::send($this->tenant, 'refund', [
                'transaction_id' => $order->order_number, 'value' => round($cents / 100, 2),
                'currency' => strtoupper((string) ($this->tenant->currency ?: 'USD')),
            ], (array) $order->metadata['ga']);
        }
        if (! $order || ! filled($order->contact_email)) return;
        try {
            (new EmailService($this->tenant))->send('order_refund_issued', $order->contact_email, [
                'first_name' => $order->contact_first_name ?: 'there', 'order_number' => $order->order_number,
                'amount' => self::money($cents), 'shop_name' => $this->tenant->name,
                'money_line' => match ($method) {
                    'card'         => 'It goes back to your card and can take 5–10 business days to show up.',
                    'store_credit' => 'It was added to your store credit.',
                    'cash'         => 'It was refunded in cash.',
                    default        => 'It was refunded to you.',
                },
                'order_url' => $this->orderUrl($order),
            ]);
        } catch (\Throwable $e) {
            Log::error('shipment.refund_email_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------

    protected function allow(TenantOrderShipment $sh, string $action): void
    {
        abort_unless(in_array($action, ShipmentFlow::actions($sh->status), true), 422, 'That is not possible for this shipment right now.');
    }

    protected function sync(TenantOrderShipment $sh): void
    {
        $order = TenantOrder::find($sh->order_id);
        if (! $order) return;
        $next = ShipmentFlow::orderStatus(TenantOrderShipment::where('order_id', $order->id)->pluck('status')->all(), $order->status);
        if ($next !== $order->status) $order->forceFill(['status' => $next])->save();
    }

    protected function orderUrl(TenantOrder $order): string
    {
        return 'https://' . request()->getHost() . '/order/' . $order->token;
    }

    public static function money(int $cents): string { return '$' . number_format($cents / 100, 2); }

    protected function email(TenantOrderShipment $sh, string $key, array $extra = []): void
    {
        $order = $sh->order()->first();
        if (! $order || ! filled($order->contact_email)) return;
        $sh->loadMissing('items.orderItem');
        $rows = $sh->items->map(fn ($i) => "<tr><td style='padding:3px 14px 3px 0'>" . e((string) $i->orderItem?->name_snapshot) . ' ×' . (int) $i->quantity . '</td></tr>')->implode('');
        $url  = ShipmentFlow::trackingUrl($sh->carrier, $sh->tracking_number);
        $carrier = ShipmentFlow::carriers()[$sh->carrier] ?? '';
        $track = $sh->tracking_number
            ? ($url ? "<a href='" . e($url) . "' style='color:#111'>" . e($sh->tracking_number) . '</a>' : e($sh->tracking_number))
            : '';
        $n = TenantOrderShipment::where('order_id', $order->id)->count();
        try {
            (new EmailService($this->tenant))->send($key, $order->contact_email, array_merge([
                'first_name' => $order->contact_first_name ?: 'there', 'order_number' => $order->order_number,
                'shop_name' => $this->tenant->name, 'items_rows' => "<table style='font-size:14px'>{$rows}</table>",
                'carrier' => $carrier, 'tracking_number' => (string) $sh->tracking_number, 'tracking_html' => $track,
                'shipment_label' => $n > 1 ? 'part of your order' : 'your order',
                'order_url' => $this->orderUrl($order), 'what_next' => '', 'reason' => '', 'money_line' => '',
            ], $extra));
        } catch (\Throwable $e) {
            Log::error('shipment.email_failed', ['shipment' => $sh->id, 'key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
