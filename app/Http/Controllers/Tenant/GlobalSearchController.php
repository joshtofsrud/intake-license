<?php
// MARKER-PATCH-231

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Lease;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantSale;
use Illuminate\Http\Request;

/**
 * Global search — one endpoint fanning out across customers, appointments,
 * sales, products, rentals, and leases. Substring LIKE matching (enough for
 * shop-scale data; no engine). Each section is feature-gated so a shop never
 * sees groups for features it doesn't run. Returns grouped JSON the modal
 * renders; every row links to a real detail page.
 */
class GlobalSearchController extends Controller
{
    private const PER_GROUP = 6;

    public function search(Request $request)
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $like = '%' . $q . '%';
        $groups = [];

        // ---- tags themselves. MARKER-TAGS-VISIBLE — for a tag of any size
        // the useful answer is the tag, not twenty arbitrary people carrying it.
        $tags = \App\Models\Tenant\TenantCustomerTag::where('tenant_id', $tenant->id)
            ->where('name', 'like', $like)
            ->withCount('customers')
            ->orderByDesc('customers_count')->limit(4)->get();
        if ($tags->count()) {
            $groups[] = $this->group('Tags', $tags->map(fn ($t) => [
                'title'    => $t->name,
                'subtitle' => number_format($t->customers_count) . ' ' . \Illuminate\Support\Str::plural('customer', $t->customers_count),
                'url'      => route('tenant.customers.index', ['tag' => $t->id]),
            ]));
        }

        // ---- customers
        $customers = TenantCustomer::where('tenant_id', $tenant->id)
            ->where(fn ($w) => $w
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                // MARKER-TAGS-VISIBLE — carrying a searched-for tag is a match.
                ->orWhereHas('tags', fn ($t) => $t->where('tenant_customer_tags.name', 'like', $like)))
            ->limit(self::PER_GROUP)->get();
        if ($customers->count()) {
            $groups[] = $this->group('Customers', $customers->map(fn ($c) => [
                'title'    => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ($c->email ?: 'Customer'),
                'subtitle' => $c->email ?: $c->phone,
                'url'      => route('tenant.customers.show', $c->id),
            ]));
        }

        // ---- appointments
        $appts = TenantAppointment::where('tenant_id', $tenant->id)
            ->where(fn ($w) => $w
                ->where('ra_number', 'like', $like)
                ->orWhere('customer_first_name', 'like', $like)
                ->orWhere('customer_last_name', 'like', $like)
                ->orWhere('customer_email', 'like', $like))
            ->orderByDesc('created_at')->limit(self::PER_GROUP)->get();
        if ($appts->count()) {
            $groups[] = $this->group('Appointments', $appts->map(fn ($a) => [
                'title'    => $a->ra_number,
                'subtitle' => trim(($a->customer_first_name ?? '') . ' ' . ($a->customer_last_name ?? '')) ?: null,
                'url'      => route('tenant.appointments.show', $a->id),
            ]));
        }

        // ---- sales
        $sales = TenantSale::where('tenant_id', $tenant->id)
            ->where('sale_number', 'like', $like)
            ->orderByDesc('created_at')->limit(self::PER_GROUP)->get();
        if ($sales->count()) {
            $groups[] = $this->group('Sales', $sales->map(fn ($s) => [
                'title'    => $s->sale_number,
                'subtitle' => format_money((int) $s->total_cents),
                'url'      => route('tenant.register.sales.page', $s->id),
            ]));
        }

        // ---- products (inventory items) — gated on retail
        if ($tenant->retail_enabled) {
            $items = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->where(fn ($w) => $w
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like))
                ->limit(self::PER_GROUP)->get();
            if ($items->count()) {
                $groups[] = $this->group('Products', $items->map(fn ($i) => [
                    'title'    => $i->name,
                    'subtitle' => $i->sku,
                    'url'      => route('tenant.inventory.show', $i->id),
                ]));
            }
        }

        // ---- rentals — gated
        if ($tenant->rentals_enabled) {
            $rentals = TenantRental::where('tenant_id', $tenant->id)
                ->where('rental_number', 'like', $like)
                ->orderByDesc('created_at')->limit(self::PER_GROUP)->get();
            if ($rentals->count()) {
                $groups[] = $this->group('Rentals', $rentals->map(fn ($r) => [
                    'title'    => $r->rental_number,
                    'subtitle' => format_money((int) $r->total_cents),
                    'url'      => route('tenant.rentals.bookings.show', $r->id),
                ]));
            }
        }

        // ---- leases — gated
        if ($tenant->leases_enabled) {
            $leases = Lease::where('tenant_id', $tenant->id)
                ->where('lease_number', 'like', $like)
                ->orderByDesc('created_at')->limit(self::PER_GROUP)->get();
            if ($leases->count()) {
                $groups[] = $this->group('Leases', $leases->map(fn ($l) => [
                    'title'    => $l->lease_number,
                    'subtitle' => $l->package_name_snapshot,
                    'url'      => route('tenant.rentals.leases.show', $l->id),
                ]));
            }
        }

        return response()->json(['groups' => $groups]);
    }

    private function group(string $label, $rows): array
    {
        return ['label' => $label, 'rows' => $rows->values()];
    }
}
