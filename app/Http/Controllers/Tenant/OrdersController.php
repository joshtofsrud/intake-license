<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantOrder;
use App\Services\Sms\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Online Retail Wave 5a: the admin Orders surface.
 * Queue of paid online orders through fulfillment:
 *   paid -> fulfilling -> fulfilled -> completed  (cancel only pre-fulfillment)
 * Marking a pickup order fulfilled can text the customer it's ready.
 */
class OrdersController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled, 404);
    }

    public function index(Request $request)
    {
        $this->guard();
        $tenant = tenant();
        $tab = $request->query('tab', 'open');

        $base = TenantOrder::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('order_number')
            ->with('items');

        $counts = [
            'open' => (clone $base)->whereIn('status', TenantOrder::OPEN_STATUSES)->count(),
            'completed' => (clone $base)->where('status', TenantOrder::STATUS_COMPLETED)->count(),
            'all'  => (clone $base)->count(),
        ];

        $orders = (clone $base)
            ->when($tab === 'open', fn ($q) => $q->whereIn('status', TenantOrder::OPEN_STATUSES))
            ->when($tab === 'completed', fn ($q) => $q->where('status', TenantOrder::STATUS_COMPLETED))
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('tenant.orders.index', compact('orders', 'counts', 'tab'));
    }

    public function show(string $id)
    {
        $this->guard();
        $order = TenantOrder::query()
            ->where('tenant_id', tenant()->id)
            ->with(['items', 'customer', 'sale'])
            ->findOrFail($id);

        return view('tenant.orders.show', compact('order'));
    }

    /** POST /orders/{id} — status transitions + optional ready-text. */
    public function update(Request $request, string $id)
    {
        $this->guard();
        $tenant = tenant();
        $order = TenantOrder::query()
            ->where('tenant_id', $tenant->id)
            ->with('items')
            ->findOrFail($id);

        $data = $request->validate([
            'op'          => ['required', 'in:advance,cancel,mark_paid,out_for_delivery'],
            'notify_text' => ['nullable', 'boolean'],
        ]);

        // staff confirm a manual payment landed (Venmo etc.)
        if ($data['op'] === 'mark_paid') {
            abort_unless($order->status === TenantOrder::STATUS_PENDING_PAYMENT && $order->payment_method, 422);
            $svc = \App\Services\Tenant\OrderService::forTenant($tenant);
            $done = $svc->finalizeManual($order, \Illuminate\Support\Facades\Auth::guard('tenant')->id());
            $svc->reportPurchase($done); // MARKER-SHOP-GA4
            return back()->with('success', 'Payment confirmed — order is paid and in the queue.');
        }

        // MARKER-SHIPMENT-WORKFLOW: a local delivery heading out the door
        if ($data['op'] === 'out_for_delivery') {
            abort_unless($order->fulfillment_type === 'local_delivery'
                && in_array($order->status, [TenantOrder::STATUS_PAID, TenantOrder::STATUS_FULFILLING, TenantOrder::STATUS_FULFILLED], true), 422);
            if (filled($order->contact_email)) {
                $rows = $order->items->map(fn ($i) => "<tr><td style='padding:3px 14px 3px 0'>" . e($i->name_snapshot) . ' ×' . (int) $i->quantity . '</td></tr>')->implode('');
                try {
                    (new \App\Services\EmailService($tenant))->send('order_out_for_delivery', $order->contact_email, [
                        'first_name' => $order->contact_first_name ?: 'there', 'order_number' => $order->order_number,
                        'shop_name' => $tenant->name, 'items_rows' => "<table style='font-size:14px'>{$rows}</table>",
                        'tracking_html' => '', 'shipment_label' => 'your order', 'what_next' => 'We are bringing it to you today.',
                        'order_url' => 'https://' . request()->getHost() . '/order/' . $order->token,
                    ]);
                } catch (\Throwable $e) { Log::error('orders.out_for_delivery_email_failed', ['order' => $order->id, 'error' => $e->getMessage()]); }
            }
            return back()->with('success', 'Customer told it is out for delivery.');
        }

        if ($data['op'] === 'cancel') {
            abort_unless(in_array($order->status, [TenantOrder::STATUS_PAID, TenantOrder::STATUS_FULFILLING]), 422);
            // Money stays put — refunds go through the sale on the register,
            // same as any other sale. This only closes the fulfillment queue item.
            $order->forceFill(['status' => TenantOrder::STATUS_CANCELLED])->save();
            return back()->with('success', 'Order cancelled on the board. Refund it from the linked sale if money should move.');
        }

        $next = match ($order->status) {
            TenantOrder::STATUS_PAID       => TenantOrder::STATUS_FULFILLING,
            TenantOrder::STATUS_FULFILLING => TenantOrder::STATUS_FULFILLED,
            TenantOrder::STATUS_FULFILLED  => TenantOrder::STATUS_COMPLETED,
            default => null,
        };
        abort_unless($next, 422);

        $order->forceFill(['status' => $next])->save();

        // Ready-for-pickup text on the fulfilled transition
        if ($next === TenantOrder::STATUS_FULFILLED
            && $request->boolean('notify_text')
            && filled($order->contact_phone)) {
            try {
                // MARKER-ECOM-SETTINGS: the shop's own wording for pickup orders (E-commerce > Messages)
                $tpl = trim((string) ($tenant->settings['storefront']['ready_text'] ?? ''));
                if ($order->fulfillment_type === 'pickup' && $tpl !== '') {
                    $body = strtr($tpl, [
                        '{first_name}'   => $order->contact_first_name ?: 'there',
                        '{shop_name}'    => $tenant->name,
                        '{order_number}' => $order->order_number,
                    ]);
                    if (! preg_match('/\bSTOP\b/i', $body)) $body .= ' Reply STOP to opt out.';
                } else {
                    $body = "{$tenant->name}: Your order {$order->order_number} is ready"
                        . ($order->fulfillment_type === 'pickup' ? ' for pickup!' : '!')
                        . ' Reply STOP to opt out.';
                }
                SmsService::send($tenant, $order->contact_phone, $body);
            } catch (\Throwable $e) {
                Log::error('orders.ready_text_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
                return back()->with('success', 'Status updated — but the text failed to send (check SMS settings).');
            }
        }

        return back()->with('success', 'Order is now ' . str_replace('_', ' ', $next) . '.');
    }

    /** POST /orders/{id}/shipments/{shipment} — MARKER-SHIPMENT-WORKFLOW */
    public function shipment(Request $request, string $id, string $shipment)
    {
        $this->guard();
        $tenant = tenant();
        $order = TenantOrder::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        $sh = \App\Models\Tenant\TenantOrderShipment::query()->where('order_id', $order->id)->findOrFail($shipment);
        $d = $request->validate([
            'op'              => ['required', 'in:ship,out_for_delivery,delivered,special_order,cancel,resend'],
            'carrier'         => ['nullable', 'string', 'max:20'],
            'tracking_number' => ['nullable', 'string', 'max:80'],
            'note'            => ['nullable', 'string', 'max:300'],
            'notify'          => ['nullable', 'boolean'],
            'shipping_cost'   => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $svc = \App\Services\Tenant\ShipmentService::forTenant($tenant);
        $notify = $request->boolean('notify');
        switch ($d['op']) {
            case 'ship':
                $svc->markShipped($sh, (string) ($d['carrier'] ?? 'other'), (string) ($d['tracking_number'] ?? ''), $notify,
                    isset($d['shipping_cost']) && $d['shipping_cost'] !== '' ? (int) round(((float) $d['shipping_cost']) * 100) : null);
                $msg = 'Marked shipped' . ($notify && filled($order->contact_email) ? ' and the customer was emailed.' : '.'); break;
            case 'out_for_delivery':
                $svc->markOutForDelivery($sh, $notify); $msg = 'Marked out for delivery.'; break;
            case 'delivered':
                $svc->markDelivered($sh); $msg = 'Marked delivered.'; break;
            case 'special_order':
                $svc->specialOrder($sh, $d['note'] ?? null, $notify); $msg = 'Moved to a special order — it is on your special orders list.'; break;
            case 'cancel':
                $svc->cancel($sh, $d['note'] ?? null, $notify); $msg = 'Shipment cancelled. Refund it from the linked sale on the register.'; break;
            default:
                $svc->resend($sh); $msg = 'Email sent again.';
        }
        return back()->with('success', $msg);
    }
}
