<?php

namespace App\Support\Launch;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-SHOP-POLICIES: the store's returns page and who hears about new orders.
 *
 * settings.storefront.returns_page_id  a published page linked at checkout and in the order email
 * settings.storefront.notify_user_ids  team members emailed for each new online order
 */
class ShopPolicies
{
    public static function returnsPage(Tenant $t): ?TenantPage
    {
        $id = (string) ((($t->settings['storefront'] ?? [])['returns_page_id'] ?? '') ?: '');
        if ($id === '') return null;
        return TenantPage::where('tenant_id', $t->id)->where('id', $id)->where('is_published', true)->first();
    }

    public static function returnsUrl(Tenant $t): ?string
    {
        $p = self::returnsPage($t);
        return $p ? '/' . ltrim((string) $p->slug, '/') : null;
    }

    /** @return TenantUser[] */
    public static function orderNotifyPeople(Tenant $t): array
    {
        $ids = array_values(array_filter(array_map('strval', (array) ((($t->settings['storefront'] ?? [])['notify_user_ids'] ?? []) ?: []))));
        if (! $ids) return [];
        return TenantUser::where('tenant_id', $t->id)->whereIn('id', $ids)->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('name')->get()->all();
    }

    /** Email the chosen people about a new online order. Never throws. */
    public static function notifyNewOrder(Tenant $t, $order, string $how): void
    {
        try {
            $people = self::orderNotifyPeople($t);
            if (! $people) return;
            $lines = [];
            foreach ($order->items ?? [] as $l) {
                $lines[] = '  ' . $l->name_snapshot . ' x' . (int) $l->quantity . '  $' . number_format($l->line_total_cents / 100, 2);
            }
            $who = trim(($order->contact_first_name ?? '') . ' ' . ($order->contact_last_name ?? '')) ?: 'A customer';
            $ful = match ($order->fulfillment_type) { 'ship' => 'Ship', 'local_delivery' => 'Local delivery', default => 'Pickup' };
            $body = $who . ' placed order ' . $order->order_number . ' (' . $how . ").\n\n"
                . implode("\n", $lines) . "\n\nTotal: $" . number_format($order->total_cents / 100, 2) . "\nHow they get it: " . $ful
                . "\n\nOpen the order: " . route('tenant.orders.show', $order->id);
            $subject = 'New online order ' . $order->order_number . ' · $' . number_format($order->total_cents / 100, 2);
            foreach ($people as $u) {
                Mail::raw($body, fn ($m) => $m->to($u->email)->subject($subject));
            }
        } catch (\Throwable $e) {
            Log::warning('shop_policies.notify_failed', ['order' => $order->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
