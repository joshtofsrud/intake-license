#!/usr/bin/env python3
"""Last-minute extension offers — Phase 2.
1. SMS YES/NO intent: the waiting PATCH-221 stub becomes real — a reply
   against an open offer re-sends the pay link (YES) or declines (NO),
   posting system events to the customer's inbox thread either way.
2. Offer lifecycle lands in the unified inbox (sent / paid events).
3. Offers activity page: 30-day stats (sent, accepted+conversion,
   revenue, avg extension), filterable table, CSV export, rental-nav tab.
4. Desk chips: due-back rows show Extended / Offer sent / Eligible.
5. Master admin rollup: platform-wide 30-day extension line in the
   SaaS business section.
Run from repo root: python3 apply-rental-extension-phase2.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    os.makedirs(os.path.dirname(os.path.join(ROOT, p)), exist_ok=True)
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")
def newfile(p, content, label):
    if os.path.exists(os.path.join(ROOT, p)):
        print(f"SKIP (exists): {label}"); return
    write(p, content)
    print(f"OK: {label}")

# ============================================================
# 1) YES/NO intent — replace the PATCH-221 stub
# ============================================================
sub('app/Http/Controllers/Webhooks/TwilioInboundController.php',
    """    /** Stub until the extension patch. Returning false = not an offer reply. */
    protected function handleOfferIntent(Tenant $tenant, TenantCustomer $customer, $thread, string $lowerBody, string $sid): bool
    {
        return false;
    }""",
    """    // MARKER-RENTAL-EXT — YES/NO against the customer's open extension
    // offer. YES re-sends the pay link (payment still happens on the page —
    // a text can't take a card); NO declines. Both post a system event so
    // the thread tells the story. Anything else falls through to a plain
    // inbox message.
    private const OFFER_YES = ['yes', 'y', 'ok', 'okay', 'yep', 'yeah', 'yea', 'sure', "\\u{1F44D}", "\\u{1F44D}\\u{1F3FB}", "\\u{1F44D}\\u{1F3FC}", "\\u{1F44D}\\u{1F3FD}", "\\u{1F44D}\\u{1F3FE}", "\\u{1F44D}\\u{1F3FF}", "\\u{1F919}", "\\u{1F918}"];
    private const OFFER_NO  = ['no', 'n', 'nope', 'nah', 'no thanks', 'no thank you'];

    protected function handleOfferIntent(Tenant $tenant, TenantCustomer $customer, $thread, string $lowerBody, string $sid): bool
    {
        $isYes = in_array($lowerBody, self::OFFER_YES, true);
        $isNo  = in_array($lowerBody, self::OFFER_NO, true);
        if (!$isYes && !$isNo) return false;

        $offer = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('rental', fn ($q) => $q->where('customer_id', $customer->id))
            ->orderByDesc('sent_at')
            ->first();
        if (!$offer) return false; // YES/NO with no open offer -> plain message

        $inbox = app(\\App\\Services\\Tenant\\InboxService::class);
        $url = rtrim($tenant->publicUrl(), '/') . '/x/' . $offer->token;

        if ($isYes) {
            $inbox->postInbound($thread, 'YES', $sid ?: null);
            $inbox->postSystem($thread, 'Customer replied YES to the extension offer — pay link re-sent.', ['offer_id' => $offer->id]);
            try {
                SmsService::send($tenant, $customer->phone,
                    'Awesome — tap the link to confirm and pay, and the bike is yours until '
                    . tlocal_datetime($offer->extend_to, 'g:i A') . ': ' . $url);
            } catch (\\Throwable $e) {
                Log::warning('rental_ext.yes_resend_failed', ['offer' => $offer->id]);
            }
            return true;
        }

        $offer->update(['status' => 'declined', 'responded_at' => now()]);
        $inbox->postInbound($thread, $lowerBody, $sid ?: null);
        $inbox->postSystem($thread, 'Customer declined the extension offer.', ['offer_id' => $offer->id]);
        try {
            SmsService::send($tenant, $customer->phone,
                'No problem — see you at ' . tlocal_datetime($offer->offer_from, 'g:i A') . '!');
        } catch (\\Throwable $e) {
            Log::warning('rental_ext.no_ack_failed', ['offer' => $offer->id]);
        }
        return true;
    }""",
    "webhook: YES/NO intent")

# ============================================================
# 2) Offer lifecycle -> inbox system events
# ============================================================
sub('app/Services/RentalExtensionOfferService.php',
    """        if ($customer?->phone) {
            try {
                SmsService::send($tenant, $customer->phone, $body);
            } catch (\\Throwable $ex) {
                Log::warning('rental_ext.sms_failed', ['offer' => $offer->id, 'error' => $ex->getMessage()]);
            }
        }

        return $offer;""",
    """        if ($customer?->phone) {
            try {
                SmsService::send($tenant, $customer->phone, $body);
            } catch (\\Throwable $ex) {
                Log::warning('rental_ext.sms_failed', ['offer' => $offer->id, 'error' => $ex->getMessage()]);
            }
        }

        // MARKER-RENTAL-EXT-P2 — the offer is part of the conversation.
        if ($customer) {
            try {
                $inbox  = app(\\App\\Services\\Tenant\\InboxService::class);
                $thread = $inbox->threadFor($tenant, $customer, 'sms');
                $inbox->postSystem($thread,
                    'Last-minute extension offer sent — extends to '
                    . tlocal_datetime($e['extend_to'], 'g:i A') . ' for ' . format_money($e['total_cents'])
                    . ' (' . $e['discount_pct'] . '% off).',
                    ['offer_id' => $offer->id, 'channel' => $channel]);
            } catch (\\Throwable $ex) {
                Log::warning('rental_ext.inbox_event_failed', ['offer' => $offer->id]);
            }
        }

        return $offer;""",
    "service: sent event")

sub('app/Http/Controllers/Tenant/RentalExtensionOfferController.php',
    """            $customer = $rental->customer;
            if ($customer?->phone) {""",
    """            $customer = $rental->customer;
            // MARKER-RENTAL-EXT-P2 — paid event on the thread.
            if ($customer) {
                try {
                    $inbox  = app(\\App\\Services\\Tenant\\InboxService::class);
                    $thread = $inbox->threadFor($tenant, $customer, 'sms');
                    $inbox->postSystem($thread,
                        'Extension accepted — paid ' . format_money($offer->total_cents)
                        . '. New return time ' . tlocal_datetime($offer->extend_to, 'g:i A') . '.',
                        ['offer_id' => $offer->id]);
                } catch (\\Throwable $e) {
                    Log::warning('rental_ext.paid_event_failed', ['offer' => $offer->id]);
                }
            }
            if ($customer?->phone) {""",
    "controller: paid event")

# ============================================================
# 3) Activity page — controller, route, nav tab, blade
# ============================================================
newfile('app/Http/Controllers/Tenant/RentalExtensionActivityController.php', """<?php

namespace App\\Http\\Controllers\\Tenant;

use App\\Http\\Controllers\\Controller;
use App\\Models\\Tenant\\TenantRentalExtensionOffer;
use Illuminate\\Http\\Request;

/**
 * MARKER-RENTAL-EXT-P2 — Offers activity: did the robot make money?
 * 30-day stats + the offer table, filterable, CSV export.
 */
class RentalExtensionActivityController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rental_extensions_enabled, 404);

        $since     = now()->subDays(30);
        $prevSince = now()->subDays(60);

        $base = TenantRentalExtensionOffer::where('tenant_id', $tenant->id);

        $sent     = (clone $base)->where('sent_at', '>=', $since)->count();
        $sentPrev = (clone $base)->whereBetween('sent_at', [$prevSince, $since])->count();
        $accepted = (clone $base)->where('status', 'paid')->where('sent_at', '>=', $since)->count();
        $revenue  = (clone $base)->where('status', 'paid')->where('sent_at', '>=', $since)->sum('total_cents');
        $avgMins  = (int) (clone $base)->where('status', 'paid')->where('sent_at', '>=', $since)
            ->get(['offer_from', 'extend_to'])
            ->avg(fn ($o) => $o->offer_from && $o->extend_to ? $o->offer_from->diffInMinutes($o->extend_to) : null);

        $filter = $request->query('filter', 'all');
        $offers = (clone $base)
            ->with(['rental.customer', 'rental.lines' => fn ($q) => $q->where('kind', 'unit')])
            ->when($filter === 'accepted', fn ($q) => $q->where('status', 'paid'))
            ->when($filter === 'dead',     fn ($q) => $q->whereIn('status', ['declined', 'expired', 'cancelled']))
            ->orderByDesc('sent_at')
            ->limit(200)
            ->get();

        if ($request->query('export') === 'csv') {
            $rows = [['Sent', 'Customer', 'Unit', 'Channel', 'Discount %', 'Total', 'Status']];
            foreach ($offers as $o) {
                $rows[] = [
                    $o->sent_at?->toDateTimeString(),
                    $o->rental?->customer?->fullName() ?? '—',
                    $o->rental?->lines?->first()?->name_snapshot ?? '—',
                    $o->channel,
                    $o->discount_pct,
                    number_format($o->total_cents / 100, 2),
                    $o->status,
                ];
            }
            $csv = implode("\\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)), $rows));
            return response($csv, 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="extension-offers.csv"',
            ]);
        }

        return view('tenant.rentals.extension-activity', [
            'sent'     => $sent,
            'sentPrev' => $sentPrev,
            'accepted' => $accepted,
            'convPct'  => $sent > 0 ? round($accepted * 100 / $sent, 1) : 0,
            'revenue'  => (int) $revenue,
            'avgPer'   => $accepted > 0 ? (int) round($revenue / $accepted) : 0,
            'avgMins'  => $avgMins,
            'offers'   => $offers,
            'filter'   => $filter,
        ]);
    }
}
""", "activity controller")

sub('routes/web.php',
    """                Route::get('/rentals/settings',  [TenantControllers\\RentalSettingsController::class, 'index'])->name('rentals.settings');""",
    """                Route::get('/rentals/extension-offers', [TenantControllers\\RentalExtensionActivityController::class, 'index'])->name('rentals.extension.activity'); // MARKER-RENTAL-EXT-P2
                Route::get('/rentals/settings',  [TenantControllers\\RentalSettingsController::class, 'index'])->name('rentals.settings');""",
    "activity route")

sub('resources/views/layouts/tenant/_rental-nav.blade.php',
    """  if (tenant()->leases_enabled) {
    $rnTabs[] = ['key' => 'leases', 'label' => 'Leases', 'route' => 'tenant.rentals.leases.index']; // MARKER-PATCH-230
  }""",
    """  if (tenant()->leases_enabled) {
    $rnTabs[] = ['key' => 'leases', 'label' => 'Leases', 'route' => 'tenant.rentals.leases.index']; // MARKER-PATCH-230
  }
  if (tenant()->rental_extensions_enabled) {
    $rnTabs[] = ['key' => 'offers', 'label' => 'Offers', 'route' => 'tenant.rentals.extension.activity']; // MARKER-RENTAL-EXT-P2
  }""",
    "nav tab")

newfile('resources/views/tenant/rentals/extension-activity.blade.php', """@extends('layouts.tenant.app')
{{-- MARKER-RENTAL-EXT-P2 — offers activity. --}}
@section('title', 'Last-minute offers')
@section('content')
<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Last-minute offers</h1>
    <p class="ia-page-subtitle">Auto-extension activity · last 30 days.</p>
  </div>
  <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $filter, 'export' => 'csv']) }}" class="ia-btn">Export CSV</a>
</div>

@include('layouts.tenant._rental-nav', ['active' => 'offers'])

<div class="ia-stat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:22px">
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Offers sent</div>
    <div class="ia-stat-value">{{ $sent }}</div>
    <div class="ia-stat-delta {{ $sent >= $sentPrev ? '' : 'down' }}">{{ $sent >= $sentPrev ? '+' : '' }}{{ $sent - $sentPrev }} vs. prev. 30d</div>
  </div>
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Accepted</div>
    <div class="ia-stat-value">{{ $accepted }}</div>
    <div class="ia-stat-delta">{{ $convPct }}% conversion</div>
  </div>
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Revenue captured</div>
    <div class="ia-stat-value">{{ format_money($revenue) }}</div>
    <div class="ia-stat-delta">{{ $accepted > 0 ? format_money($avgPer) . ' / accepted offer' : '—' }}</div>
  </div>
  <div class="ia-card" style="padding:16px 18px">
    <div class="ia-label">Avg. extension</div>
    <div class="ia-stat-value">{{ $avgMins ? floor($avgMins / 60) . 'h ' . ($avgMins % 60) . 'm' : '—' }}</div>
    <div class="ia-stat-delta">per accepted offer</div>
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span class="ia-card-title">All offers</span>
    <div style="display:flex;gap:6px">
      @foreach(['all' => 'All', 'accepted' => 'Accepted', 'dead' => 'Declined / expired'] as $k => $label)
        <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $k]) }}" class="ia-btn ia-btn--sm {{ $filter === $k ? 'ia-btn--primary' : '' }}" style="text-decoration:none">{{ $label }}</a>
      @endforeach
    </div>
  </div>
  @if($offers->isEmpty())
    <div style="padding:22px 20px;font-size:12.5px;opacity:.55">No offers yet — they'll appear here as the scan finds eligible rentals.</div>
  @else
    <table class="ia-table">
      <thead><tr><th>Sent</th><th>Customer</th><th>Unit</th><th>Channel</th><th class="ia-num">Discount</th><th class="ia-num">Offer total</th><th>Status</th></tr></thead>
      <tbody>
        @foreach($offers as $o)
          <tr @if($o->rental) onclick="window.location='{{ route('tenant.rentals.bookings.show', $o->rental_id) }}'" style="cursor:pointer" @endif>
            <td class="ia-num">{{ $o->sent_at ? tlocal($o->sent_at) : '—' }}</td>
            <td>{{ $o->rental?->customer?->fullName() ?? '—' }}</td>
            <td>{{ $o->rental?->lines?->first()?->name_snapshot ?? '—' }}</td>
            <td>{{ $o->channel === 'manual' ? 'Manual' : 'Auto' }}</td>
            <td class="ia-num">{{ $o->discount_pct }}%</td>
            <td class="ia-num">{{ format_money($o->total_cents) }}</td>
            <td>
              @if($o->status === 'paid')<span class="ia-badge ia-badge--healthy">Accepted · paid</span>
              @elseif($o->status === 'sent')<span class="ia-badge ia-badge--out">Awaiting reply</span>
              @else<span class="ia-badge">{{ ucfirst($o->status) }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
""", "activity blade")

# ============================================================
# 4) Desk chips on due-back rows
# ============================================================
sub('resources/views/tenant/rentals/desk.blade.php',
    """            $lateLabel = '';
            if ($late) {
              $mins = $r->due_at->diffInMinutes(now());
              $lateLabel = $mins >= 60 ? floor($mins / 60) . 'h overdue' : $mins . 'm overdue';
            }
          @endphp""",
    """            $lateLabel = '';
            if ($late) {
              $mins = $r->due_at->diffInMinutes(now());
              $lateLabel = $mins >= 60 ? floor($mins / 60) . 'h overdue' : $mins . 'm overdue';
            }
            // MARKER-RENTAL-EXT-P2 — extension chip
            $extChip = null;
            if (tenant()->rental_extensions_enabled && !$late) {
              $extOffer = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('rental_id', $r->id)
                ->whereIn('status', ['sent', 'paid'])->orderByDesc('sent_at')->first();
              if ($extOffer?->status === 'paid')      $extChip = ['Extended', 'ia-badge--healthy'];
              elseif ($extOffer?->status === 'sent')  $extChip = ['Offer sent', 'ia-badge--out'];
              else {
                $extReason = null;
                $extChip = app(\\App\\Services\\RentalExtensionOfferService::class)->eligibility(tenant(), $r, $extReason)
                  ? ['Ext. eligible', ''] : null;
              }
            }
          @endphp""",
    "desk: compute chip")

sub('resources/views/tenant/rentals/desk.blade.php',
    """              @if($late)
                <span class="ia-badge ia-badge--overdue">{{ $lateLabel }}</span>
              @else
                <span class="ia-badge ia-badge--out">Out</span>
              @endif""",
    """              @if($late)
                <span class="ia-badge ia-badge--overdue">{{ $lateLabel }}</span>
              @else
                <span class="ia-badge ia-badge--out">Out</span>
              @endif
              @if($extChip)
                <span class="ia-badge {{ $extChip[1] }}" title="Last-minute extension">{{ $extChip[0] }}</span>
              @endif""",
    "desk: render chip")

# ============================================================
# 5) Master admin rollup — SaaS section line
# ============================================================
sub('app/Filament/Pages/PlatformDashboard.php',
    """        return [
            'totalTenants'      => $totalTenants,""",
    """        // MARKER-RENTAL-EXT-P2 — platform-wide last-minute extension line.
        $extSince    = now()->subDays(30);
        $extSent     = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('sent_at', '>=', $extSince)->count();
        $extAccepted = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('status', 'paid')->where('sent_at', '>=', $extSince)->count();
        $extRevenue  = (int) \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('status', 'paid')->where('sent_at', '>=', $extSince)->sum('total_cents');
        $extTenants  = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('sent_at', '>=', $extSince)->distinct('tenant_id')->count('tenant_id');

        return [
            'totalTenants'      => $totalTenants,""",
    "master: compute rollup")

sub('app/Filament/Pages/PlatformDashboard.php',
    """            'weekly'            => $weekly,
        ];
    }""",
    """            'weekly'            => $weekly,
            'extSent'           => $extSent,     // MARKER-RENTAL-EXT-P2
            'extAccepted'       => $extAccepted,
            'extRevenue'        => $extRevenue,
            'extTenants'        => $extTenants,
        ];
    }""",
    "master: expose rollup")

sub('resources/views/filament/pages/platform-dashboard.blade.php',
    """      <div class="pd-section-sub">{{ $saas['totalTenants'] }} tenants · <a href="/admin/tenants">tenants directory →</a></div>""",
    """      <div class="pd-section-sub">{{ $saas['totalTenants'] }} tenants · <a href="/admin/tenants">tenants directory →</a></div>
      {{-- MARKER-RENTAL-EXT-P2 — 30d last-minute extension rollup --}}
      @if(($saas['extSent'] ?? 0) > 0)
        <div class="pd-section-sub" style="margin-top:4px">
          Last-minute extensions (30d): {{ $saas['extSent'] }} sent · {{ $saas['extAccepted'] }} accepted ({{ $saas['extSent'] > 0 ? round($saas['extAccepted'] * 100 / $saas['extSent']) : 0 }}%) · ${{ number_format($saas['extRevenue'] / 100, 2) }} captured · {{ $saas['extTenants'] }} {{ $saas['extTenants'] === 1 ? 'shop' : 'shops' }}
        </div>
      @endif""",
    "master: blade line")

print("\\nDone. No migration needed. view:clear after deploy.")
