<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CustomerTimelineService
 *
 * Merges every customer-facing event into a single chronological timeline:
 *   - Service appointments (tenant_appointments)
 *   - POS sales (tenant_sales, paid + draft + quote — non-paid surfaces too
 *     so admins can see in-progress state)
 *   - Class registrations (tenant_class_registrations) — the act of being
 *     enrolled, separate from the payment that may or may not have funded it
 *   - Pack & membership events (admin grants today; will also include
 *     register-driven purchases once Stripe Connect ships and pack/membership
 *     buys flow through the POS)
 *
 * Returns a Collection of arrays, each row a "TimelineEvent" with a uniform
 * shape so the view can render them with one template:
 *
 *   [
 *     'kind'       => 'sale' | 'appointment' | 'class_registration' | 'pack_grant' | 'membership_grant',
 *     'date'       => Carbon — used for sort + group-by-month
 *     'title'      => string — top line ("POS sale #1024")
 *     'subtitle'   => string — secondary line ("Drop-in: Power Yoga · ...")
 *     'status'     => string — "Paid", "Refunded", "Registered", "Checked in", etc.
 *     'status_tone'=> 'success' | 'warning' | 'danger' | 'neutral'
 *     'amount_cents' => int|null — null if non-monetary (registration without sale, grant)
 *     'is_refunded'=> bool — controls strikethrough styling
 *     'href'       => string|null — click-through destination URL
 *     'identifier' => string|null — small grey number ('#1024', 'RA-1043')
 *   ]
 *
 * Performance notes:
 *   - All four queries are tenant-scoped on indexed columns
 *   - Eager loads kept minimal — we only pull what the row renders
 *   - Sort happens once after merge; not per-source
 *   - At customer scale (single customer's history), even at 1000+ events the
 *     in-memory merge is fast. If a tenant accumulates 10k+ events for one
 *     customer, switch to paginated UNION query in DB.
 */
class CustomerTimelineService
{
    /**
     * Build the full timeline for a single customer.
     *
     * @return Collection<int, array> Sorted newest-first.
     */
    public function buildForCustomer(string $tenantId, string $customerId): Collection
    {
        $events = collect();

        $events = $events
            ->concat($this->loadAppointments($tenantId, $customerId))
            ->concat($this->loadSales($tenantId, $customerId))
            ->concat($this->loadClassRegistrations($tenantId, $customerId))
            ->concat($this->loadPackGrants($tenantId, $customerId))
            ->concat($this->loadMembershipGrants($tenantId, $customerId))
            ->concat($this->loadRentals($tenantId, $customerId)); // MARKER-PATCH-219

        // Single sort after merge — newest first.
        return $events->sortByDesc(fn ($e) => $e['date']->timestamp)->values();
    }

    /**
     * Group an already-built timeline by year-month key (e.g. "2026-05").
     * Each group includes a `total_cents` of paid revenue for that month so
     * the view can show "May 2026 · $179.00" headers. Refunds reduce the
     * group total. Non-monetary events contribute zero.
     *
     * Default-expanded months: current + previous calendar month. Older
     * months render collapsed; the view handles the collapse UI.
     *
     * @return Collection<string, array{label:string, total_cents:int, expanded:bool, events:Collection}>
     */
    public function groupByMonth(Collection $events): Collection
    {
        $now = now();
        // MARKER-PATCH-200 — expand the current month, last month, AND any
        // future month. Upcoming appointments are future-dated (e.g. a June
        // booking made in May); the old logic only expanded this/last month, so
        // scheduled-ahead appointments loaded but rendered collapsed (the month
        // header showed "2 events" with no visible rows). Compare by month key.
        $thisMonthKey = $now->format('Y-m');
        $lastMonthKey = $now->copy()->subMonth()->format('Y-m');

        return $events
            ->groupBy(fn ($e) => $e['date']->format('Y-m'))
            ->map(function (Collection $monthEvents, string $key) use ($thisMonthKey, $lastMonthKey) {
                $total = $monthEvents->sum(function ($e) {
                    if (empty($e['amount_cents'])) return 0;
                    return $e['is_refunded'] ? 0 : $e['amount_cents'];
                });

                // Expand current month, last month, or anything in the future.
                $expanded = ($key >= $thisMonthKey) || ($key === $lastMonthKey);

                return [
                    'label'       => Carbon::parse($key . '-01')->format('F Y'),
                    'total_cents' => (int) $total,
                    'expanded'    => $expanded,
                    'events'      => $monthEvents->values(),
                ];
            });
    }

    // ------------------------------------------------------------------
    // Loaders — one per source. Each returns a Collection of timeline rows.
    // ------------------------------------------------------------------

    /**
     * MARKER-PATCH-219 — Rail 3: rentals in the customer timeline.
     * amount_cents is the LEDGER sum (paid_cents mirror), not the rental
     * total — unpaid rentals show as activity without inflating revenue.
     */
    private function loadRentals(string $tenantId, string $customerId): Collection
    {
        return TenantRental::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with('lines:id,rental_id,name_snapshot,kind')
            ->get()
            ->map(function ($r) {
                $overdue = $r->isOverdue();
                $tone = match (true) {
                    $r->status === 'cancelled' => 'danger',
                    $overdue                   => 'danger',
                    $r->status === 'out'       => 'warning',
                    $r->status === 'returned'  => 'success',
                    default                    => 'neutral',
                };
                $label = $overdue ? 'Out · Overdue' : ucfirst($r->status);

                $unitNames = $r->lines->where('kind', 'unit')->pluck('name_snapshot')
                    ->take(3)->filter()->implode(', ');

                return [
                    'kind'         => 'rental',
                    'date'         => $r->starts_at,
                    'title'        => 'Rental',
                    'identifier'   => $r->rental_number,
                    'subtitle'     => $unitNames !== '' ? $unitNames : 'Rental booking',
                    'status'       => $label,
                    'status_tone'  => $tone,
                    'amount_cents' => $r->paid_cents > 0 ? $r->paid_cents : null,
                    'is_refunded'  => false,
                    'href'         => route('tenant.rentals.bookings.show', ['id' => $r->id]),
                ];
            });
    }

    private function loadAppointments(string $tenantId, string $customerId): Collection
    {
        return TenantAppointment::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with('items:id,appointment_id,item_name_snapshot')
            ->get()
            ->map(function ($a) {
                $isRefunded = $a->payment_status === 'refunded';
                $statusTone = match (true) {
                    $isRefunded                              => 'danger',
                    $a->payment_status === 'paid'            => 'success',
                    $a->status === 'completed'               => 'success',
                    $a->status === 'cancelled'               => 'danger',
                    $a->status === 'in_progress'             => 'warning',
                    default                                  => 'neutral',
                };
                $statusLabel = ucwords(str_replace('_', ' ', $a->status))
                    . ' · ' . ucfirst($a->payment_status);

                // Subtitle: line items if any, fallback to status
                $subtitle = $a->items->isNotEmpty()
                    ? $a->items->pluck('item_name_snapshot')->take(3)->filter()->implode(', ')
                    : 'No line items';

                return [
                    'kind'         => 'appointment',
                    'date'         => $a->appointment_date,
                    'title'        => 'Service appointment',
                    'identifier'   => $a->ra_number,
                    'subtitle'     => $subtitle,
                    'status'       => $statusLabel,
                    'status_tone'  => $statusTone,
                    // MARKER-PATCH-174B — sales-as-money model: the appointment
                    // is a service record, not a revenue row. Its money is
                    // carried by the linked deposit/balance sales (which sum to
                    // the appointment total). Counting the appointment total
                    // here AND the sales double-counts. null keeps it off the
                    // monthly revenue rollup while still showing in the feed.
                    'amount_cents' => null,
                    'is_refunded'  => $isRefunded,
                    'href'         => route('tenant.appointments.show', [
                        'subdomain' => tenant()->subdomain,
                        'id'        => $a->id,
                    ]),
                ];
            });
    }

    private function loadSales(string $tenantId, string $customerId): Collection
    {
        return TenantSale::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereNotIn('payment_status', ['draft']) // hide drafts; quotes show
            ->with('items:id,sale_id,name_snapshot')
            ->get()
            ->map(function ($s) {
                // Refund detection: a refund-of-sale has refund_of_sale_id set
                // and total_cents will be negative for the refund half.
                $isRefund = !empty($s->refund_of_sale_id);
                $statusTone = match ($s->payment_status) {
                    'paid'     => $isRefund ? 'danger' : 'success',
                    'refunded' => 'danger',
                    'quote'    => 'neutral',
                    'unpaid'   => 'warning',
                    default    => 'neutral',
                };
                $statusLabel = $isRefund
                    ? 'Refund issued'
                    : ucfirst($s->payment_status);

                $subtitle = $s->items->isNotEmpty()
                    ? $s->items->pluck('name_snapshot')->take(3)->filter()->implode(', ')
                    : 'No line items';

                return [
                    'kind'         => 'sale',
                    'sale_id'      => $s->id,
                    'date'         => $s->sale_date,
                    'title'        => $isRefund ? 'POS refund' : 'POS sale',
                    'identifier'   => $s->sale_number ? '#' . $s->sale_number : null,
                    'subtitle'     => $subtitle,
                    'status'       => $statusLabel,
                    'status_tone'  => $statusTone,
                    'amount_cents' => (int) $s->total_cents,
                    'is_refunded'  => $isRefund || $s->payment_status === 'refunded',
                    'href'         => null, // sales open in a modal via openSaleModal(sale_id)
                ];
            });
    }

    private function loadClassRegistrations(string $tenantId, string $customerId): Collection
    {
        return TenantClassRegistration::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with(['session.template:id,name'])
            ->get()
            ->map(function ($r) {
                $statusTone = match ($r->status) {
                    'checked_in' => 'success',
                    'registered' => 'success',
                    'waitlisted' => 'warning',
                    'cancelled', 'no_show' => 'danger',
                    default      => 'neutral',
                };

                $className  = $r->session?->template?->name ?? 'Unknown class';
                $payVia     = $r->payment_method
                    ? str_replace('_', ' ', $r->payment_method)
                    : 'unknown';
                $subtitle   = "{$className} · paid via {$payVia}";

                return [
                    'kind'         => 'class_registration',
                    'date'         => $r->registered_at ?? $r->created_at,
                    'title'        => 'Class registration',
                    'identifier'   => null,
                    'subtitle'     => $subtitle,
                    'status'       => ucwords(str_replace('_', ' ', $r->status)),
                    'status_tone'  => $statusTone,
                    'amount_cents' => null,
                    'is_refunded'  => $r->status === 'cancelled',
                    'href'         => $r->session
                        ? route('tenant.classes.sessions.show', [
                            'subdomain' => tenant()->subdomain,
                            'id'        => $r->session->id,
                          ])
                        : null,
                ];
            });
    }

    private function loadPackGrants(string $tenantId, string $customerId): Collection
    {
        return TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with('product:id,name,credit_count,price_cents')
            ->get()
            ->map(function ($p) {
                $statusTone = match ($p->status) {
                    'active'    => 'success',
                    'exhausted' => 'neutral',
                    'expired'   => 'warning',
                    'cancelled' => 'danger',
                    default     => 'neutral',
                };

                $productName = $p->product?->name ?? 'Pack';
                $remaining   = $p->credits_remaining;
                $total       = $p->product?->credit_count ?? 0;
                $expiry      = $p->expires_at?->format('M j, Y');
                $subtitle    = "{$productName}"
                    . ($expiry ? " · expires {$expiry}" : '')
                    . " · {$remaining} of {$total} remaining";

                return [
                    'kind'         => 'pack_grant',
                    'date'         => $p->created_at,
                    'title'        => 'Pack granted',
                    'identifier'   => null,
                    'subtitle'     => $subtitle,
                    'status'       => ucfirst($p->status),
                    'status_tone'  => $statusTone,
                    // Show the product price as the grant value — admins want
                    // to see what this would have cost the customer.
                    'amount_cents' => (int) ($p->product?->price_cents ?? 0),
                    'is_refunded'  => false,
                    'href'         => null,
                ];
            });
    }

    private function loadMembershipGrants(string $tenantId, string $customerId): Collection
    {
        return TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with('product:id,name,monthly_limit,price_cents,type')
            ->get()
            ->map(function ($m) {
                $statusTone = match ($m->status) {
                    'active'    => 'success',
                    'paused'    => 'warning',
                    'expired'   => 'warning',
                    'cancelled' => 'danger',
                    default     => 'neutral',
                };

                $productName = $m->product?->name ?? 'Membership';
                $usage       = $m->classes_used_this_period;
                $limit       = $m->product?->monthly_limit;
                $usageHint   = $m->product?->type === 'unlimited'
                    ? "{$usage} visits this period"
                    : "{$usage} of {$limit} used this period";
                $subtitle    = "{$productName} · {$usageHint}";

                return [
                    'kind'         => 'membership_grant',
                    'date'         => $m->created_at,
                    'title'        => 'Membership granted',
                    'identifier'   => null,
                    'subtitle'     => $subtitle,
                    'status'       => ucfirst($m->status),
                    'status_tone'  => $statusTone,
                    'amount_cents' => (int) ($m->product?->price_cents ?? 0),
                    'is_refunded'  => false,
                    'href'         => null,
                ];
            });
    }
}
