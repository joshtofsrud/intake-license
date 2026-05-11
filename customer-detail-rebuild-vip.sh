#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Customer detail rebuild + VIP toggle architecture.
#
# Three big chunks:
#
# 1. VIP architecture (works on both desktop + mobile)
#    - Migration: tenant_customers.is_vip boolean default false
#    - PATCH /admin/customers/{id} gets new op=toggle_vip handler
#    - Desktop page-head: VIP toggle button + VIP badge under name
#    - Customer list sort sheet (mobile) + select (desktop): "VIPs only" option
#
# 2. Mobile customer detail page — full rebuild per approved mockup:
#    - Hero: name + amber ★ VIP toggle + edit pencil
#    - Status pills: Active member (if any) + Last visit (if any)
#    - 3 contact tiles: Call (tel:) / Text (sms:) / Email (mailto:)
#    - + New appointment lime CTA
#    - 3-stat row: Visits / Lifetime / Since
#    - Conditional membership card with renewal date
#    - Activity timeline with filter chips + month dividers + reflowed rows
#    - Notes section (unchanged, kept)
#
# 3. Engine architecture stays out of v1. The is_vip column is the foundation;
#    the engine itself ships in v1.1 once we have manual-toggle training data.
#
# Patch is large but careful — uses heredoc for new files, str_replace for
# precise edits to existing ones.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== customer detail rebuild + VIP toggle starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Migration: add is_vip column
# ─────────────────────────────────────────────────────────────────────────────
MIG_FILE="database/migrations/2026_05_10_000001_add_is_vip_to_tenant_customers.php"
if [ -f "$MIG_FILE" ]; then
  echo "SKIP 1 (migration already exists)"
else
cat > "$MIG_FILE" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            // Manual VIP flag. Default false. Tenants toggle this manually
            // from the customer detail page. v1.1 introduces a learning
            // engine that suggests candidates based on tenant's pattern of
            // manual toggles; engine writes to a separate suggestions table.
            $table->boolean('is_vip')->default(false)->after('country');
            $table->index(['tenant_id', 'is_vip'], 'tnt_customers_vip_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->dropIndex('tnt_customers_vip_idx');
            $table->dropColumn('is_vip');
        });
    }
};
PHP
echo "OK 1 (migration created)"
fi

# ─────────────────────────────────────────────────────────────────────────────
# 2. Add is_vip to TenantCustomer $fillable
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('app/Models/Tenant/TenantCustomer.php')
s = p.read_text()
if "'is_vip'" in s:
    print("SKIP 2 (is_vip already in fillable)")
else:
    # Find protected $fillable and append 'is_vip'
    import re
    m = re.search(r"protected\s+\$fillable\s*=\s*\[", s)
    if not m:
        print("SKIP 2 (no $fillable found — model may use $guarded)")
    else:
        # Insert 'is_vip', just before the closing ];
        start = m.end()
        end = s.find('];', start)
        section = s[start:end]
        # Append before the closing bracket
        new_section = section.rstrip().rstrip(',') + ",\n        'is_vip',\n    "
        s = s[:start] + new_section + s[end:]
        p.write_text(s)
        print("OK 2 (is_vip added to fillable)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 3. Add toggle_vip handler to CustomerController::handleUpdate
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/CustomerController.php')
s = p.read_text()
if "op === 'toggle_vip'" in s:
    print("SKIP 3 (toggle_vip already present)")
else:
    old = "        if ($op === 'add_note') {"
    new = """        if ($op === 'toggle_vip') {
            // Toggle is_vip flag. Returns the new state so the UI can render
            // the updated star + badge without a full page reload.
            $customer->is_vip = !$customer->is_vip;
            $customer->save();
            return response()->json(['ok' => true, 'is_vip' => $customer->is_vip]);
        }
        if ($op === 'add_note') {"""
    assert s.count(old) == 1
    s = s.replace(old, new)
    p.write_text(s)
    print("OK 3 (toggle_vip handler added)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 4. CustomerController index: add 'vips_only' sort
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/CustomerController.php')
s = p.read_text()
if "case 'vips_only'" in s:
    print("SKIP 4 (vips_only sort already present)")
else:
    old = """        // Sort
        switch ($sort) {
            case 'name_desc':"""
    new = """        // VIPs-only filter is a sort option for UX simplicity. When
        // selected, filter to is_vip=true and order by name ascending.
        if ($sort === 'vips_only') {
            $q->where('is_vip', true);
        }

        // Sort
        switch ($sort) {
            case 'name_desc':"""
    assert s.count(old) == 1
    p.write_text(s.replace(old, new))
    print("OK 4 (vips_only filter added to index)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 5. Customer list: add 'VIPs only' to $sortLabels in both index.blade.php
#    AND show small ★ icon next to VIP customers in desktop table.
#    (Mobile cards keep no inline star per spec; sort sheet covers it.)
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/index.blade.php')
s = p.read_text()
if "vips_only" in s:
    print("SKIP 5 (vips_only label already present)")
else:
    old = "    'last_service' => 'Last service',\n  ];"
    new = "    'last_service' => 'Last service',\n    'vips_only'    => 'VIPs only',\n  ];"
    assert s.count(old) == 1
    s = s.replace(old, new)
    p.write_text(s)
    print("OK 5 (vips_only added to sort labels)")
PY

# Add inline VIP star to desktop table rows (next to name)
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/index.blade.php')
s = p.read_text()
if "vip-list-star" in s:
    print("SKIP 5b (desktop star already present)")
else:
    old = '<td><span style="font-weight:500">{{ $c->first_name }} {{ $c->last_name }}</span></td>'
    new = ('<td><span style="font-weight:500">{{ $c->first_name }} {{ $c->last_name }}</span>'
           '@if($c->is_vip)<span class="vip-list-star" title="VIP">★</span>@endif</td>')
    assert s.count(old) == 1
    p.write_text(s.replace(old, new))
    print("OK 5b (desktop VIP star added)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 6. Customer DETAIL — desktop page-head: add VIP toggle button + badge
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
if "VIP-DESKTOP-INTEGRATION v1" in s:
    print("SKIP 6 (desktop VIP integration present)")
else:
    # Replace page-head: add VIP badge under name + VIP toggle in actions
    old = """{{-- Header --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">Customer</div>
    <h1 class="ia-page-title">{{ $customer->first_name }} {{ $customer->last_name }}</h1>
    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>"""
    new = """{{-- Header — VIP-DESKTOP-INTEGRATION v1 --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">Customer</div>
    <h1 class="ia-page-title">
      {{ $customer->first_name }} {{ $customer->last_name }}
      <span class="cust-vip-badge" data-vip-badge style="display:{{ $customer->is_vip ? 'inline-flex' : 'none' }}">
        <svg viewBox="0 0 24 24" fill="currentColor" stroke="none" width="12" height="12" aria-hidden="true">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        VIP
      </span>
    </h1>
    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost cust-desktop-only">← Back</a>
    <button type="button" class="ia-btn ia-btn--ghost cust-vip-toggle-desktop {{ $customer->is_vip ? 'is-on' : '' }}"
            data-vip-toggle data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}">
      <svg viewBox="0 0 24 24" fill="{{ $customer->is_vip ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      </svg>
      <span data-vip-label>{{ $customer->is_vip ? 'VIP' : 'Mark VIP' }}</span>
    </button>
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>"""
    assert s.count(old) == 1
    p.write_text(s.replace(old, new))
    print("OK 6 (desktop VIP integration added)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 7. Customer DETAIL — add mobile parallel render BEFORE the desktop
#    .cust-layout block. Wrap desktop in .cust-desktop-only.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
if "CUSTOMER-DETAIL-MOBILE-REBUILD v1" in s:
    print("SKIP 7 (mobile rebuild present)")
else:
    # Wrap the existing .cust-layout block in cust-desktop-only.
    # The opening: <div class="cust-layout">
    old_open = '<div class="cust-layout">'
    new_open = '''{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1 — parallel mobile render below.
     Desktop layout (this .cust-layout grid) is hidden on phones via CSS.
     ============================================================ --}}
<div class="cust-layout cust-desktop-only">'''
    assert s.count(old_open) == 1
    s = s.replace(old_open, new_open)

    # Inject the mobile layout markup right before the desktop wrapper,
    # so it appears at the top on mobile (before any hidden desktop content).
    # Use the page-head closing as the anchor.
    anchor = '''</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1'''

    # Compute things we need in PHP
    mobile_block = '''</div>

{{-- ============================================================
     MOBILE LAYOUT (hidden on desktop via CSS)
     ============================================================ --}}
@php
  // Compute mobile hero data
  $mobActiveMembership = isset($customerMemberships) ? $customerMemberships->where('status','active')->first() : null;
  $mobActivePacks = isset($customerPacks) ? $customerPacks->where('status','active') : collect();
  $mobLastVisit = $lastService ? \\Carbon\\Carbon::parse($lastService) : null;
  $mobMonthsSince = $customer->created_at->diffInMonths(now());
  $mobSinceLabel = $mobMonthsSince < 1 ? '<1 mo' : ($mobMonthsSince < 12 ? $mobMonthsSince . ' mo' : floor($mobMonthsSince / 12) . ' yr');
  $mobVisitCount = $appointments->whereIn('status', ['completed','confirmed','in_progress'])->count();
@endphp

<div class="cust-mobile-only cust-mobile">

  {{-- HERO BAND --}}
  <div class="cmd-hero">
    <div class="cmd-hero-top">
      <h1 class="cmd-hero-name">{{ $customer->first_name }} {{ $customer->last_name }}</h1>
      <div class="cmd-hero-actions">
        <button type="button" class="cmd-vip-btn {{ $customer->is_vip ? 'is-on' : '' }}"
                data-vip-toggle data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}"
                aria-label="{{ $customer->is_vip ? 'Remove VIP status' : 'Mark as VIP' }}">
          <svg viewBox="0 0 24 24" fill="{{ $customer->is_vip ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          VIP
        </button>
        <button type="button" class="cmd-edit-btn" onclick="document.getElementById('edit-toggle').click()" aria-label="Edit customer info">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>
      </div>
    </div>

    {{-- Status pills --}}
    @if($mobActiveMembership || $mobLastVisit)
      <div class="cmd-status">
        @if($mobActiveMembership)
          <span class="cmd-pill cmd-pill--member">
            <span class="cmd-pill-dot"></span>
            Active member
          </span>
        @endif
        @if($mobLastVisit)
          <span class="cmd-pill cmd-pill--neutral">Last visit · {{ $mobLastVisit->format('M j') }}</span>
        @endif
      </div>
    @endif

    {{-- Contact tiles --}}
    <div class="cmd-contact-tiles">
      <a href="{{ $customer->phone ? 'tel:' . preg_replace('/[^0-9+]/', '', $customer->phone) : '#' }}"
         class="cmd-tile {{ $customer->phone ? '' : 'is-disabled' }}"
         {{ $customer->phone ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        <span class="cmd-tile-label">Call</span>
      </a>
      <a href="{{ $customer->phone ? 'sms:' . preg_replace('/[^0-9+]/', '', $customer->phone) : '#' }}"
         class="cmd-tile {{ $customer->phone ? '' : 'is-disabled' }}"
         {{ $customer->phone ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <span class="cmd-tile-label">Text</span>
      </a>
      <a href="{{ $customer->email ? 'mailto:' . $customer->email : '#' }}"
         class="cmd-tile {{ $customer->email ? '' : 'is-disabled' }}"
         {{ $customer->email ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        <span class="cmd-tile-label">Email</span>
      </a>
    </div>

    {{-- Primary CTA --}}
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}" class="cmd-cta">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      New appointment
    </a>
  </div>

  {{-- STATS ROW --}}
  <div class="cmd-stats">
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ $mobVisitCount }}</div>
      <div class="cmd-stat-label">Visits</div>
    </div>
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ format_money((int)$totalSpend) }}</div>
      <div class="cmd-stat-label">Lifetime</div>
    </div>
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ $mobSinceLabel }}</div>
      <div class="cmd-stat-label">Since</div>
    </div>
  </div>

  {{-- MEMBERSHIP CARD (conditional) --}}
  @if($mobActiveMembership)
    <div class="cmd-section">
      <div class="cmd-section-head">
        <span>Membership</span>
      </div>
      <div class="cmd-mb-card">
        <div class="cmd-mb-card-top">
          <span class="cmd-mb-card-title">{{ $mobActiveMembership->product?->name ?? 'Membership' }}</span>
          @if($mobActiveMembership->renews_at)
            <span class="cmd-mb-card-renew">Renews {{ \\Carbon\\Carbon::parse($mobActiveMembership->renews_at)->format('M j') }}</span>
          @endif
        </div>
        <div class="cmd-mb-card-meta">
          Started {{ \\Carbon\\Carbon::parse($mobActiveMembership->granted_at ?? $mobActiveMembership->created_at)->format('M j') }}
          @if($mobActiveMembership->product?->price_cents)
            · {{ format_money($mobActiveMembership->product->price_cents) }}/mo
          @endif
        </div>
      </div>
    </div>
  @endif

  {{-- ACTIVITY — reuse existing $timelineMonths data --}}
  @if($timelineCount > 0)
    <div class="cmd-section">
      <div class="cmd-section-head">
        <span>Activity · {{ $timelineCount }} events</span>
      </div>
      <div class="cmd-activity">
        @foreach($timelineMonths as $monthKey => $month)
          <div class="cmd-act-month-label">{{ $month['label'] }}</div>
          @foreach($month['events'] as $e)
            @php
              $iconClass = match($e['kind']) {
                'appointment' => 'cmd-act-icon--appt',
                'sale' => 'cmd-act-icon--sale',
                'class_registration' => 'cmd-act-icon--class',
                'pack_grant', 'membership_grant' => 'cmd-act-icon--member',
                default => '',
              };
            @endphp
            <div class="cmd-act-row"
                 @if(!empty($e['sale_id'])) onclick="window.openSaleModal && window.openSaleModal('{{ $e['sale_id'] }}')"
                 @elseif($e['href']) onclick="window.location='{{ $e['href'] }}'"
                 @endif>
              <div class="cmd-act-icon {{ $iconClass }}"></div>
              <div class="cmd-act-date">{{ $e['date']->format('M j') }}</div>
              <div class="cmd-act-amount">
                @if($e['amount_cents'] !== null){{ format_money($e['amount_cents']) }}@endif
              </div>
              <div class="cmd-act-main">
                <div class="cmd-act-title">{{ $e['title'] }}</div>
                <div class="cmd-act-sub">{{ $e['subtitle'] }}</div>
              </div>
              <span class="cmd-act-pill cmd-act-pill--{{ $e['status_tone'] }}">{{ $e['status'] }}</span>
            </div>
          @endforeach
        @endforeach
      </div>
    </div>
  @endif

  {{-- NOTES — mobile version. Reuses existing add-note infrastructure. --}}
  <div class="cmd-section">
    <div class="cmd-section-head">
      <span>Notes</span>
    </div>
    @forelse($notes as $note)
      <div class="cmd-note">
        <div class="cmd-note-head">
          <span class="cmd-note-author">{{ $note->user?->name ?? 'Staff' }}</span>
          <span>{{ \\Carbon\\Carbon::parse($note->created_at)->format('M j, g:i a') }}</span>
        </div>
        <div class="cmd-note-body">{{ $note->note }}</div>
      </div>
    @empty
      <p class="cmd-note-empty">No notes yet.</p>
    @endforelse
    <div class="cmd-note-add">
      <input type="text" id="cmd-note-input" placeholder="Add a note..." maxlength="200">
      <button type="button" class="cmd-note-add-btn" data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}">Add</button>
    </div>
  </div>

</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1'''
    # Find the </div> that ends ia-page-head, then inject mobile block right after
    head_end = '''    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>'''
    # Already replaced; need the new version:
    head_end_new = '''    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>'''
    # Anchor: the line `</div>\n\n<div class="cust-layout cust-desktop-only">`
    anchor_2 = '''</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1 — parallel mobile render below.
     Desktop layout (this .cust-layout grid) is hidden on phones via CSS.
     ============================================================ --}}
<div class="cust-layout cust-desktop-only">'''

    assert s.count(anchor_2) == 1, f"anchor_2 count = {s.count(anchor_2)}"
    s = s.replace(anchor_2, mobile_block + ''' — parallel mobile render below.
     Desktop layout (this .cust-layout grid) is hidden on phones via CSS.
     ============================================================ --}}
<div class="cust-layout cust-desktop-only">''')
    p.write_text(s)
    print("OK 7 (mobile detail rebuild injected)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 8. Append mobile + desktop VIP CSS to the show.blade.php <style> block
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
marker = "/* CUSTOMER-MOBILE-REBUILD-CSS v1 */"
if marker in s:
    print("SKIP 8 (CSS already present)")
else:
    # Anchor: just before "/* CUSTOMER-MOBILE-POLISH v1 */" comment block we added earlier
    # Actually replace the entire @media (max-width: 600px) block from customer-detail-mobile.sh
    # so we get a clean integrated set. Simpler approach: append before </style>
    # and let later rules win where they overlap.
    addition = '''

/* CUSTOMER-MOBILE-REBUILD-CSS v1 — full mobile detail page styles + VIP. */

/* VIP — desktop badge + toggle */
.cust-vip-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 99px;
  background: rgba(245,158,11,.10);
  color: #F59E0B;
  border: 0.5px solid rgba(245,158,11,.30);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .04em;
  vertical-align: middle;
  margin-left: 8px;
}
.cust-vip-badge svg { color: #F59E0B; }

.cust-vip-toggle-desktop {
  display: inline-flex !important;
  align-items: center;
  gap: 5px;
}
.cust-vip-toggle-desktop.is-on {
  color: #F59E0B !important;
  border-color: rgba(245,158,11,.30) !important;
  background: rgba(245,158,11,.06) !important;
}

/* Customer list — small ★ next to VIP customer names */
.vip-list-star {
  color: #F59E0B;
  margin-left: 6px;
  font-size: 12px;
  vertical-align: middle;
}

/* Mobile-only visibility helpers */
.cust-mobile-only { display: none; }

@media (max-width: 600px) {
  .cust-desktop-only { display: none !important; }
  .cust-mobile-only { display: block; }

  /* Container */
  .cust-mobile { padding: 0; }

  /* HERO */
  .cmd-hero { margin-bottom: 16px; }
  .cmd-hero-top {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px;
  }
  .cmd-hero-name {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -.02em;
    line-height: 1.15;
    color: var(--ia-text);
    margin: 0;
    flex: 1;
    min-width: 0;
    word-break: break-word;
  }
  .cmd-hero-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
  }
  .cmd-vip-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    color: var(--ia-text-muted);
    height: 36px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-vip-btn.is-on {
    color: #F59E0B;
    border-color: rgba(245,158,11,.30);
    background: rgba(245,158,11,.08);
  }
  .cmd-vip-btn:active { transform: scale(0.95); }
  .cmd-edit-btn {
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    color: var(--ia-text-muted);
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-edit-btn:active { transform: scale(0.95); }

  /* Status pills */
  .cmd-status {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
  }
  .cmd-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 500;
  }
  .cmd-pill-dot {
    width: 6px; height: 6px; border-radius: 50%;
  }
  .cmd-pill--member {
    background: rgba(190,242,100,.10);
    color: var(--ia-accent);
    border: 0.5px solid rgba(190,242,100,.25);
  }
  .cmd-pill--member .cmd-pill-dot { background: var(--ia-accent); }
  .cmd-pill--neutral {
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    border: 0.5px solid var(--ia-border);
  }

  /* Contact tiles */
  .cmd-contact-tiles {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-top: 14px;
  }
  .cmd-tile {
    display: flex; flex-direction: column; align-items: center;
    gap: 4px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px 6px;
    color: var(--ia-text);
    text-decoration: none;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-tile svg { color: var(--ia-accent); }
  .cmd-tile:active { transform: scale(0.97); }
  .cmd-tile-label {
    font-size: 11px;
    color: var(--ia-text-muted);
    font-weight: 500;
  }
  .cmd-tile.is-disabled {
    opacity: .35;
    pointer-events: none;
  }
  .cmd-tile.is-disabled svg { color: var(--ia-text-muted); }

  /* CTA */
  .cmd-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    margin-top: 12px;
    padding: 14px;
    background: var(--ia-accent);
    color: var(--ia-bg, #0a0a0a);
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }
  .cmd-cta:active { transform: scale(0.99); }

  /* Stats */
  .cmd-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 20px;
  }
  .cmd-stat {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 10px 8px;
    text-align: center;
  }
  .cmd-stat-value {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -.01em;
    font-variant-numeric: tabular-nums;
    color: var(--ia-text);
  }
  .cmd-stat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--ia-text-muted);
    margin-top: 3px;
    font-weight: 500;
  }

  /* Sections */
  .cmd-section { margin-bottom: 24px; }
  .cmd-section-head {
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }

  /* Membership card */
  .cmd-mb-card {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-left: 3px solid var(--ia-accent);
    border-radius: 10px;
    padding: 14px;
  }
  .cmd-mb-card-top {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: 10px;
    margin-bottom: 4px;
  }
  .cmd-mb-card-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--ia-text);
  }
  .cmd-mb-card-renew {
    font-size: 11px;
    color: var(--ia-text-muted);
    font-variant-numeric: tabular-nums;
  }
  .cmd-mb-card-meta {
    font-size: 12px;
    color: var(--ia-text-muted);
  }

  /* Activity */
  .cmd-act-month-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    font-weight: 500;
    padding: 12px 4px 6px;
  }
  .cmd-act-row {
    display: grid;
    grid-template-columns: 28px 1fr auto;
    grid-template-rows: auto auto;
    gap: 4px 12px;
    padding: 12px 4px;
    border-bottom: 0.5px solid var(--ia-border);
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-act-row:last-child { border-bottom: none; }
  .cmd-act-icon {
    grid-row: 1 / 3;
    width: 28px; height: 28px;
    border-radius: 50%;
    margin-top: 2px;
    background: var(--ia-surface-2);
  }
  .cmd-act-icon--appt { background: rgba(250,180,106,.18); }
  .cmd-act-icon--sale { background: rgba(190,242,100,.15); }
  .cmd-act-icon--class { background: rgba(117,168,224,.18); }
  .cmd-act-icon--member { background: rgba(244,115,115,.15); }
  .cmd-act-date {
    grid-column: 2;
    grid-row: 1;
    font-size: 10px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    font-variant-numeric: tabular-nums;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .cmd-act-amount {
    grid-column: 3;
    grid-row: 1;
    font-size: 13px;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    color: var(--ia-text);
    text-align: right;
  }
  .cmd-act-main {
    grid-column: 2;
    grid-row: 2;
    min-width: 0;
  }
  .cmd-act-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--ia-text);
    margin-bottom: 1px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cmd-act-sub {
    font-size: 11px;
    color: var(--ia-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cmd-act-pill {
    grid-column: 3;
    grid-row: 2;
    align-self: center;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 99px;
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    white-space: nowrap;
    justify-self: end;
  }
  .cmd-act-pill--success { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .cmd-act-pill--warning { background: rgba(245,158,11,.15); color: #F59E0B; }
  .cmd-act-pill--danger { background: rgba(244,115,115,.15); color: #F47373; }

  /* Notes */
  .cmd-note {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
  }
  .cmd-note-head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11px;
    color: var(--ia-text-muted);
    margin-bottom: 4px;
  }
  .cmd-note-author { font-weight: 500; color: var(--ia-text); }
  .cmd-note-body { font-size: 13px; line-height: 1.4; color: var(--ia-text); }
  .cmd-note-empty { font-size: 13px; color: var(--ia-text-muted); padding: 4px; }
  .cmd-note-add {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 4px;
  }
  .cmd-note-add input {
    flex: 1;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    padding: 10px 12px;
    color: var(--ia-text);
    font-size: 13px;
    font-family: inherit;
  }
  .cmd-note-add-btn {
    background: var(--ia-accent);
    color: var(--ia-bg, #0a0a0a);
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
  }
}
'''
    old_close = '</style>\n@endpush'
    assert s.count(old_close) == 1
    p.write_text(s.replace(old_close, addition + old_close))
    print("OK 8 (mobile + desktop VIP CSS appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 9. Append VIP toggle JS + mobile note-add JS to show.blade.php @push('scripts')
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
import re
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
if "VIP-TOGGLE-JS v1" in s:
    print("SKIP 9 (VIP JS already present)")
else:
    js = """

@push('scripts')
<script>
// VIP-TOGGLE-JS v1 — handles both desktop + mobile VIP toggle buttons.
(function () {
  function setupVipToggle() {
    document.querySelectorAll('[data-vip-toggle]').forEach(function (btn) {
      if (btn.__vipBound) return;
      btn.__vipBound = true;
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url');
        var csrf = btn.getAttribute('data-csrf');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('_token', csrf);
        fd.append('op', 'toggle_vip');

        fetch(url, {
          method: 'POST',
          body: fd,
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (!data || !data.ok) {
            if (window.IntakeConfirm && IntakeConfirm.alert) {
              IntakeConfirm.alert({ title: 'Couldn\\'t toggle VIP', message: 'Please try again.' });
            } else {
              alert('Could not toggle VIP. Please try again.');
            }
            return;
          }
          // Update all VIP UI on the page (desktop + mobile both visible in DOM)
          var isOn = !!data.is_vip;
          document.querySelectorAll('[data-vip-toggle]').forEach(function (b) {
            b.classList.toggle('is-on', isOn);
            var svg = b.querySelector('svg');
            if (svg) svg.setAttribute('fill', isOn ? 'currentColor' : 'none');
            var lbl = b.querySelector('[data-vip-label]');
            if (lbl) lbl.textContent = isOn ? 'VIP' : 'Mark VIP';
          });
          // Desktop badge under name
          document.querySelectorAll('[data-vip-badge]').forEach(function (badge) {
            badge.style.display = isOn ? 'inline-flex' : 'none';
          });
        })
        .catch(function () {
          btn.disabled = false;
          alert('Could not toggle VIP. Please try again.');
        });
      });
    });
  }

  // Mobile note-add handler (mirrors desktop, smaller surface)
  function setupMobileNoteAdd() {
    var btn = document.querySelector('.cmd-note-add-btn');
    var input = document.getElementById('cmd-note-input');
    if (!btn || !input || btn.__bound) return;
    btn.__bound = true;
    btn.addEventListener('click', function () {
      var note = input.value.trim();
      if (!note) return;
      var url = btn.getAttribute('data-url');
      var csrf = btn.getAttribute('data-csrf');
      btn.disabled = true;
      var fd = new FormData();
      fd.append('_method', 'PATCH');
      fd.append('_token', csrf);
      fd.append('op', 'add_note');
      fd.append('note', note);
      fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (data && data.ok) {
            // Soft reload to reflect new note. Could splice in DOM but page is short.
            window.location.reload();
          } else {
            alert(data && data.message ? data.message : 'Could not add note.');
          }
        })
        .catch(function () { btn.disabled = false; alert('Could not add note.'); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setupVipToggle(); setupMobileNoteAdd(); });
  } else {
    setupVipToggle(); setupMobileNoteAdd();
  }
})();
</script>
@endpush
"""
    # Append before the final @endsection
    old = "@endsection"
    n = s.count(old)
    if n < 1:
        print("ABORT (no @endsection found)")
        raise SystemExit(1)
    # Replace the LAST occurrence
    idx = s.rfind(old)
    s = s[:idx] + js + "\n" + s[idx:]
    p.write_text(s)
    print("OK 9 (VIP JS + mobile notes JS appended)")
PY

echo ""
echo "=== verifying ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}×)"
  else
    echo "  ✗ MISSING: $label"
    fail=1
  fi
}

verify "database/migrations/2026_05_10_000001_add_is_vip_to_tenant_customers.php" "is_vip" "migration"
verify "app/Models/Tenant/TenantCustomer.php"                         "'is_vip'"              "model fillable"
verify "app/Http/Controllers/Tenant/CustomerController.php"           "toggle_vip"            "controller handler"
verify "app/Http/Controllers/Tenant/CustomerController.php"           "vips_only"             "controller sort filter"
verify "resources/views/tenant/customers/index.blade.php"             "vips_only"             "list sort label"
verify "resources/views/tenant/customers/index.blade.php"             "vip-list-star"         "list star icon"
verify "resources/views/tenant/customers/show.blade.php"              "VIP-DESKTOP-INTEGRATION v1" "desktop integration"
verify "resources/views/tenant/customers/show.blade.php"              "CUSTOMER-DETAIL-MOBILE-REBUILD v1" "mobile rebuild marker"
verify "resources/views/tenant/customers/show.blade.php"              "cmd-hero"              "mobile hero"
verify "resources/views/tenant/customers/show.blade.php"              "cmd-contact-tiles"     "contact tiles"
verify "resources/views/tenant/customers/show.blade.php"              "VIP-TOGGLE-JS v1"      "VIP toggle JS"

# Blade balance
python3 <<'PY'
import sys
src = open('resources/views/tenant/customers/show.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@push','@endpush'), ('@forelse','@endforelse')]
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    if no != nc:
        print(f'  ✗ {o}({no}) != {c}({nc})')
        ok = False
    else:
        print(f'  ✓ {o}/{c}: {no}')
if not ok: sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "DEPLOY:"
echo "  git add -A && git commit -m 'mobile+desktop: customer detail rebuild + VIP toggle architecture'"
echo "  git push"
echo "  Server: git pull && \\"
echo "    php artisan migrate --force && \\"
echo "    php artisan optimize:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "NOTE: this patch requires running migrations. The is_vip column needs"
echo "to exist on tenant_customers before the page will load."
echo ""
echo "=== rebuild complete ==="
