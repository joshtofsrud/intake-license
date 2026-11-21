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

    /**
     * MARKER-ORDER-PRINT: GET /orders/{id}/print/{doc}. The pick list and bag tag print on the
     * receipt printer (the same paper and logo settings as receipts); the packing slip is a
     * full page for the box, one per shipment when ?shipment= is given.
     */
    public function printDoc(Request $request, string $id, string $doc)
    {
        $this->guard();
        $tenant = tenant();
        $order = TenantOrder::query()->where('tenant_id', $tenant->id)->with(['items', 'sale'])->findOrFail($id);
        $print = \App\Services\PrintIdentityService::forTenant($tenant);

        if ($doc === 'packing-slip') {
            $ships = $order->fulfillment_type === 'ship' ? $order->shipments()->with('items.orderItem')->get() : collect();
            $only  = $request->query('shipment');
            return view('tenant.orders.print.packing-slip', [
                'tenant' => $tenant, 'order' => $order, 'print' => $print, 'ships' => $ships,
                'one'    => $only ? $ships->firstWhere('id', $only) : null,
                'returns' => \App\Support\Launch\ShopPolicies::returnsUrl($tenant),
            ]);
        }

        return view('tenant.orders.print.' . ($doc === 'tag' ? 'tag' : 'pick-lists'), [
            'tenant' => $tenant, 'print' => $print, 'orders' => collect([$order]), 'bins' => $this->bins($tenant, collect([$order])),
        ]);
    }

    /** MARKER-ORDER-PRINT: GET /orders/print/pick-lists?ids[]=… — several pick lists at once, one per page. */
    public function printPickLists(Request $request)
    {
        $this->guard();
        $tenant = tenant();
        $ids = array_slice(array_values(array_filter((array) $request->query('ids', []), 'is_string')), 0, 100);
        $orders = TenantOrder::query()->where('tenant_id', $tenant->id)->whereIn('id', $ids)->with('items')->orderBy('paid_at')->get();
        abort_if($orders->isEmpty(), 404);

        return view('tenant.orders.print.pick-lists', [
            'tenant' => $tenant, 'print' => \App\Services\PrintIdentityService::forTenant($tenant), 'orders' => $orders, 'bins' => $this->bins($tenant, $orders),
        ]);
    }

    /** Where each item sits: the bin at the order's location, else the item's own bin. [item id => bin] */
    private function bins($tenant, $orders): array
    {
        $itemIds = $orders->flatMap(fn ($o) => $o->items->pluck('inventory_item_id'))->filter()->unique()->values();
        if ($itemIds->isEmpty()) return [];
        $loc = $tenant->defaultLocation?->id;
        $out = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)->whereIn('id', $itemIds)->pluck('shop_bin_location', 'id')->filter()->all();
        if ($loc) {
            foreach (\App\Models\Tenant\TenantInventoryItemLocation::whereIn('inventory_item_id', $itemIds)->where('location_id', $loc)->whereNotNull('shop_bin_location')->pluck('shop_bin_location', 'inventory_item_id') as $iid => $bin) {
                if (trim((string) $bin) !== '') $out[(string) $iid] = $bin;
            }
        }
        return $out;
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
            'op'          => ['required', 'in:advance,cancel,mark_paid,refund'],
            'notify_text' => ['nullable', 'boolean'],
            'notify'      => ['nullable', 'boolean'],
            // MARKER-ORDER-STEPS: cancel and refund in one step
            'lines'       => ['nullable', 'array'],
            'lines.*'     => ['nullable', 'numeric', 'min:0'],
            'restock'     => ['nullable', 'boolean'],
            'reason'      => ['nullable', 'string', 'max:300'],
        ]);
        $me = \Illuminate\Support\Facades\Auth::guard('tenant')->user();

        if ($data['op'] === 'refund') {
            return $this->refund($request, $order, $data, $me);
        }

        // staff confirm a manual payment landed (Venmo etc.)
        if ($data['op'] === 'mark_paid') {
            abort_unless($order->status === TenantOrder::STATUS_PENDING_PAYMENT && $order->payment_method, 422);
            $svc = \App\Services\Tenant\OrderService::forTenant($tenant);
            $done = $svc->finalizeManual($order, \Illuminate\Support\Facades\Auth::guard('tenant')->id());
            $svc->reportPurchase($done); // MARKER-SHOP-GA4
            \App\Support\OrderSteps::record($done->fresh(), 'Paid', $me?->name, tender_label($done->payment_method) . ', marked by staff');
            return back()->with('success', 'Payment confirmed — order is paid and in the queue.');
        }

        // only an unpaid pay-later order is cancelled on its own; a paid one goes through
        // cancel-and-refund (op=refund) so the money is settled in the same step
        if ($data['op'] === 'cancel') {
            abort_unless($order->status === TenantOrder::STATUS_PENDING_PAYMENT && $order->payment_method && ! $order->sale_id, 422);
            $order->forceFill(['status' => TenantOrder::STATUS_CANCELLED])->save();
            \App\Support\OrderSteps::record($order, 'Cancelled', $me?->name, 'no payment was taken');
            return back()->with('success', 'Order cancelled. No payment was taken.');
        }

        // MARKER-ORDER-STEPS: the next step for how this order leaves
        $step = \App\Support\OrderSteps::next($order);
        abort_unless($step, 422, 'This order moves on its own, or is already done.');
        [$next, $label] = $step;
        $notify = $request->boolean('notify') || $request->boolean('notify_text');

        $meta = (array) ($order->metadata ?? []);
        if ($next === TenantOrder::STATUS_FULFILLED && $order->fulfillment_type === 'pickup') {
            $meta['ready_at'] = now()->toIso8601String();
            unset($meta['reminded_at']);
        }
        $order->forceFill(['status' => $next, 'metadata' => $meta])->save();

        $told = null;
        if ($notify && $next === TenantOrder::STATUS_FULFILLED) {
            $told = $order->fulfillment_type === 'local_delivery'
                ? $this->tellOutForDelivery($tenant, $order)
                : $this->tellReady($tenant, $order);
        }
        \App\Support\OrderSteps::record($order, \App\Support\OrderSteps::label($order, $next), $me?->name, $told);

        return back()->with('success', \App\Support\OrderSteps::label($order, $next) . '.' . ($told ? ' ' . ucfirst($told) . '.' : ''));
    }

    /**
     * MARKER-ORDER-STEPS: "ready for pickup" by text and email. Returns what was sent,
     * e.g. "customer texted and emailed", or "couldn't tell the customer: …".
     */
    private function tellReady($tenant, TenantOrder $order): string
    {
        $sent = [];
        if (filled($order->contact_phone)) {
            try {
                // MARKER-ECOM-SETTINGS: the shop's own wording for pickup orders (E-commerce > Messages)
                $tpl = trim((string) ($tenant->settings['storefront']['ready_text'] ?? ''));
                $body = $tpl !== ''
                    ? strtr($tpl, ['{first_name}' => $order->contact_first_name ?: 'there', '{shop_name}' => $tenant->name, '{order_number}' => $order->order_number])
                    : "{$tenant->name}: Your order {$order->order_number} is ready for pickup!";
                if (! preg_match('/\bSTOP\b/i', $body)) $body .= ' Reply STOP to opt out.';
                SmsService::send($tenant, $order->contact_phone, $body);
                $sent[] = 'texted';
            } catch (\Throwable $e) {
                Log::error('orders.ready_text_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }
        if (filled($order->contact_email)) {
            try {
                $cfg = \App\Services\Tenant\OrderService::forTenant($tenant)->config();
                (new \App\Services\EmailService($tenant))->send('order_ready_pickup', $order->contact_email, [
                    'first_name' => $order->contact_first_name ?: 'there', 'order_number' => $order->order_number, 'shop_name' => $tenant->name,
                    'items_rows' => $this->itemRows($order),
                    'pickup_line' => e($cfg['pickup_instructions'] !== '' ? $cfg['pickup_instructions'] : 'Bring your name or order number to the counter.'),
                    'order_url' => 'https://' . request()->getHost() . '/order/' . $order->token,
                ]);
                $sent[] = 'emailed';
            } catch (\Throwable $e) {
                Log::error('orders.ready_email_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }
        return $sent ? 'customer ' . implode(' and ', $sent) : 'couldn\'t reach the customer (no phone or email worked)';
    }

    private function tellOutForDelivery($tenant, TenantOrder $order): string
    {
        if (! filled($order->contact_email)) {
            return 'no email on this order, so the customer wasn\'t told';
        }
        try {
            (new \App\Services\EmailService($tenant))->send('order_out_for_delivery', $order->contact_email, [
                'first_name' => $order->contact_first_name ?: 'there', 'order_number' => $order->order_number,
                'shop_name' => $tenant->name, 'items_rows' => $this->itemRows($order),
                'tracking_html' => '', 'shipment_label' => 'your order', 'what_next' => 'We are bringing it to you today.',
                'order_url' => 'https://' . request()->getHost() . '/order/' . $order->token,
            ]);
            return 'customer emailed';
        } catch (\Throwable $e) {
            Log::error('orders.out_for_delivery_email_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return 'the email to the customer failed';
        }
    }

    private function itemRows(TenantOrder $order): string
    {
        $rows = $order->items->map(fn ($i) => "<tr><td style='padding:3px 14px 3px 0'>" . e($i->name_snapshot) . ' ×' . (int) $i->quantity . '</td></tr>')->implode('');
        return "<table style='font-size:14px'>{$rows}</table>";
    }

    /**
     * MARKER-ORDER-STEPS: cancel and refund in one step. Goes through the same refund as the
     * register (SaleService::createRefund), so the money goes back the way it came in and lands
     * in the ledger the same way: card to the card, cash as a cash refund, and so on. The
     * refund then reaches the Connected POS through its usual hook.
     */
    private function refund(Request $request, TenantOrder $order, array $data, $me)
    {
        $tenant = tenant();
        abort_unless($order->sale_id && in_array($order->status, [TenantOrder::STATUS_PAID, TenantOrder::STATUS_FULFILLING, TenantOrder::STATUS_FULFILLED, TenantOrder::STATUS_COMPLETED], true), 422);
        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)->with('items')->findOrFail($order->sale_id);
        $plan = \App\Support\OrderRefund::plan($order, $sale);

        $restock = $request->boolean('restock');
        $rows = [];
        foreach ((array) ($data['lines'] ?? []) as $saleItemId => $qty) {
            $line = collect($plan['lines'])->firstWhere('sale_item_id', (string) $saleItemId);
            $qty = (float) $qty;
            if (! $line || $qty <= 0) continue;
            $qty = min($qty, $line['left']);
            $rows[] = [
                'sale_item_id' => (string) $saleItemId,
                'quantity'     => $qty,
                'disposition'  => ($line['kind'] === 'product' && $restock && ! $line['dropship']) ? 'restock' : 'customer_keeps',
            ];
        }
        if (! $rows) {
            return back()->with('error', 'Choose at least one thing to refund.');
        }

        try {
            $refundSale = app(\App\Services\Tenant\SaleService::class)->createRefund([
                'tenant_id'          => $tenant->id,
                'original_sale_id'   => $sale->id,
                'rang_up_by_user_id' => $me?->id,
                'refund_method'      => $plan['method'],
                'reason'             => trim('Online order ' . $order->order_number . ' cancelled' . (filled($data['reason'] ?? null) ? ': ' . $data['reason'] : '')),
                'items'              => $rows,
            ]);
        } catch (\App\Services\Tenant\SaleValidationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('orders.refund_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'The refund didn\'t go through: ' . $e->getMessage());
        }

        // the card refund itself, exactly as the register does it
        $cardError = $plan['method'] === 'card' ? \App\Support\OrderRefund::stripe($tenant, $refundSale) : null;

        if ($request->boolean('notify')) {
            try {
                \App\Services\Tenant\ShipmentService::forTenant($tenant)->notifyRefund($sale->id, (int) abs($refundSale->total_cents), $plan['method']);
            } catch (\Throwable $e) { Log::warning('order refund email skipped: ' . $e->getMessage()); }
        }

        // everything refunded: the order is cancelled, and shipments not yet sent are too
        $after = \App\Support\OrderRefund::plan($order, $sale->fresh('items'));
        $all = collect($after['lines'])->where('kind', 'product')->every(fn ($l) => $l['left'] <= 0);
        if ($all) {
            $order->forceFill(['status' => TenantOrder::STATUS_CANCELLED])->save();
            \App\Models\Tenant\TenantOrderShipment::where('order_id', $order->id)->whereIn('status', ['pending', 'to_ship', null])->update(['status' => 'cancelled']);
        }
        $amount = '$' . number_format(abs($refundSale->total_cents) / 100, 2);
        \App\Support\OrderRefund::recordHistory($order, $all, $amount, $plan['method_label'], $me?->name);

        $msg = ($all ? 'Order cancelled and ' : '') . $amount . ' refunded ' . $plan['method_label'] . '.';
        if ($cardError) {
            return back()->with('error', $msg . ' But the card refund failed: ' . $cardError . ' Retry it from the sale, or in Stripe.');
        }
        return back()->with('success', $msg);
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
