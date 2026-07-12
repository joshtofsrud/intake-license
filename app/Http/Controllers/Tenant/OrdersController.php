<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantOrder;
use App\Services\Sms\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-PATCH-567 — Online Retail Wave 5a: the admin Orders surface.
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
            'op'          => ['required', 'in:advance,cancel,mark_paid'],
            'notify_text' => ['nullable', 'boolean'],
        ]);

        // MARKER-PATCH-631 — staff confirm a manual payment landed (Venmo etc.)
        if ($data['op'] === 'mark_paid') {
            abort_unless($order->status === TenantOrder::STATUS_PENDING_PAYMENT && $order->payment_method, 422);
            \App\Services\Tenant\OrderService::forTenant($tenant)->finalizeManual($order, \Illuminate\Support\Facades\Auth::guard('tenant')->id());
            return back()->with('success', 'Payment confirmed — order is paid and in the queue.');
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
                SmsService::send($tenant, $order->contact_phone,
                    "{$tenant->name}: Your order {$order->order_number} is ready"
                    . ($order->fulfillment_type === 'pickup' ? ' for pickup!' : '!')
                    . ' Reply STOP to opt out.');
            } catch (\Throwable $e) {
                Log::error('orders.ready_text_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
                return back()->with('success', 'Status updated — but the text failed to send (check SMS settings).');
            }
        }

        return back()->with('success', 'Order is now ' . str_replace('_', ' ', $next) . '.');
    }
}

