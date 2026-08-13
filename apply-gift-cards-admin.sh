#!/bin/bash
# apply-gift-cards-admin.sh
#
# MARKER-GIFTCARDS-ADMIN — gift cards patch 2 of 3: the staff-side manager,
# per the approved mockup. Requires MARKER-GIFTCARDS (patch 1).
#
#   - Gift cards page: liability stats (outstanding balance, sold 30d,
#     redeemed 30d, fully used), search/status/type filters, masked codes
#   - Card detail: full ledger with running balances, Adjust balance
#     (reason required, staff recorded), Deactivate (reason required),
#     manual "+ Issue gift card" for cards sold outside a register sale
#   - Manage actions gated by NEW capability giftcards.manage (section
#     customers is wrong; new 'register' section renders generically in the
#     Roles editor). Viewing rides the retail route group like Orders.
#   - Nav entry after Orders, gate retail_enabled (in-store cards are core;
#     only the ONLINE purchase page is addon-gated, in patch 3).
set -e

MARKER="MARKER-GIFTCARDS-ADMIN"
CTRLDIR="app/Http/Controllers/Tenant"

if ! grep -q "MARKER-GIFTCARDS" app/Services/Tenant/GiftCardService.php 2>/dev/null; then
  echo "ERROR: requires apply-gift-cards-core.sh (MARKER-GIFTCARDS) first"
  exit 1
fi
if [ -f "$CTRLDIR/GiftCardController.php" ]; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Controller
# ---------------------------------------------------------------
cat > "$CTRLDIR/GiftCardController.php" <<'EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Services\Tenant\GiftCardService;
use Illuminate\Http\Request;

// MARKER-GIFTCARDS-ADMIN — staff-side gift card manager.
class GiftCardController extends Controller
{
    public function __construct(protected GiftCardService $cards)
    {
    }

    public function index(Request $request)
    {
        $tenant = tenant();
        $q      = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $type   = $request->query('type');

        $rows = TenantGiftCard::query()
            ->where('tenant_id', $tenant->id)
            ->when($q !== '', function ($w) use ($q) {
                $norm = TenantGiftCard::normalizeCode($q);
                $w->where(function ($x) use ($q, $norm) {
                    if ($norm !== '') {
                        $x->orWhere('code', 'like', '%' . $norm . '%');
                    }
                    $x->orWhere('purchaser_name', 'like', '%' . $q . '%')
                      ->orWhere('recipient_name', 'like', '%' . $q . '%')
                      ->orWhere('recipient_email', 'like', '%' . $q . '%')
                      ->orWhereHas('purchaser', fn ($c) => $c->where('first_name', 'like', '%' . $q . '%')
                          ->orWhere('last_name', 'like', '%' . $q . '%')
                          ->orWhere('email', 'like', '%' . $q . '%'));
                });
            })
            ->when(in_array($status, ['active', 'used', 'deactivated', 'pending'], true), fn ($w) => $w->where('status', $status))
            ->when(in_array($type, ['physical', 'egift'], true), fn ($w) => $w->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $since = now()->subDays(30);
        $stats = [
            'outstanding_cents' => (int) TenantGiftCard::where('tenant_id', $tenant->id)->where('status', 'active')->sum('balance_cents'),
            'active_count'      => (int) TenantGiftCard::where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'sold_cents'        => (int) TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'issue')->where('created_at', '>=', $since)->sum('amount_cents'),
            'sold_count'        => (int) TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'issue')->where('created_at', '>=', $since)->count(),
            'redeemed_cents'    => (int) abs(TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'redeem')->where('created_at', '>=', $since)->sum('amount_cents')),
            'redeemed_count'    => (int) TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'redeem')->where('created_at', '>=', $since)->count(),
            'used_count'        => (int) TenantGiftCard::where('tenant_id', $tenant->id)->where('status', 'used')->count(),
        ];

        return view('tenant.gift-cards.index', [
            'tenant'    => $tenant,
            'rows'      => $rows,
            'stats'     => $stats,
            'q'         => $q,
            'status'    => $status,
            'type'      => $type,
            'canManage' => (bool) auth('tenant')->user()?->can('giftcards.manage'),
        ]);
    }

    public function show(Request $request, string $cardId)
    {
        $tenant = tenant();
        $card = TenantGiftCard::where('tenant_id', $tenant->id)->findOrFail($cardId);
        $card->load('transactions');

        return view('tenant.gift-cards.show', [
            'tenant'    => $tenant,
            'card'      => $card,
            'canManage' => (bool) auth('tenant')->user()?->can('giftcards.manage'),
        ]);
    }

    /** Manual issue — a card sold or granted outside a register sale. */
    public function store(Request $request)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $tenant = tenant();

        $data = $request->validate([
            'type'            => 'required|in:physical,egift',
            'amount'          => 'required|numeric|min:1|max:10000',
            'code'            => 'nullable|string|max:40|required_if:type,physical',
            'recipient_email' => 'nullable|email|max:160|required_if:type,egift',
            'recipient_name'  => 'nullable|string|max:120',
            'note'            => 'nullable|string|max:200',
        ]);

        $amount = (int) round(((float) $data['amount']) * 100);
        $code = $data['type'] === 'physical'
            ? TenantGiftCard::normalizeCode((string) $data['code'])
            : $this->cards->generateCode($tenant->id);

        if ($data['type'] === 'physical') {
            if ($code === '') {
                return back()->with('error', 'Card code is required for a physical card.');
            }
            if (TenantGiftCard::where('tenant_id', $tenant->id)->where('code', $code)->exists()) {
                return back()->with('error', "Code {$code} is already in use.");
            }
        }

        $card = \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $data, $amount, $code) {
            $card = TenantGiftCard::create([
                'tenant_id'         => $tenant->id,
                'code'              => $code,
                'type'              => $data['type'],
                'status'            => 'active',
                'original_cents'    => $amount,
                'balance_cents'     => $amount,
                'recipient_name'    => $data['recipient_name'] ?? null,
                'recipient_email'   => $data['recipient_email'] ?? null,
                'issued_by_user_id' => auth('tenant')->id(),
            ]);
            TenantGiftCardTransaction::create([
                'tenant_id'           => $tenant->id,
                'gift_card_id'        => $card->id,
                'kind'                => 'issue',
                'amount_cents'        => $amount,
                'balance_after_cents' => $amount,
                'note'                => 'Issued manually' . (filled($data['note'] ?? null) ? ' — ' . $data['note'] : ''),
                'user_id'             => auth('tenant')->id(),
            ]);
            return $card;
        });

        if ($card->type === 'egift') {
            \App\Jobs\DeliverGiftCardJob::dispatch($card->id)->afterCommit();
        }

        return redirect()->route('tenant.gift-cards.show', ['cardId' => $card->id])
            ->with('success', 'Gift card issued — ' . $card->code);
    }

    public function adjust(Request $request, string $cardId)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $card = TenantGiftCard::where('tenant_id', tenant()->id)->findOrFail($cardId);

        $data = $request->validate([
            'direction' => 'required|in:credit,debit',
            'amount'    => 'required|numeric|min:0.01|max:10000',
            'reason'    => 'required|string|max:200',
        ]);

        $delta = (int) round(((float) $data['amount']) * 100);
        if ($data['direction'] === 'debit') {
            $delta = -$delta;
        }

        try {
            $this->cards->adjust($card, $delta, $data['reason'], auth('tenant')->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $verb = $delta > 0 ? '+' : '−';
        return back()->with('success', sprintf('Balance adjusted %s$%s — %s.', $verb, number_format(abs($delta) / 100, 2), $data['reason']));
    }

    public function deactivate(Request $request, string $cardId)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $card = TenantGiftCard::where('tenant_id', tenant()->id)->findOrFail($cardId);

        $data = $request->validate(['reason' => 'required|string|max:200']);
        $this->cards->deactivate($card, $data['reason'], auth('tenant')->id());

        return back()->with('success', 'Card deactivated — ' . $data['reason']);
    }
}
EOF
echo "ok: GiftCardController created"

# ---------------------------------------------------------------
# 2. Purchaser relation on the model (used by list search)
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Models/Tenant/TenantGiftCard.php'
src = io.open(p, encoding='utf-8').read()
a = "    public function transactions(): HasMany\n"
assert src.count(a) == 1
src = src.replace(a, """    public function purchaser()
    {
        return $this->belongsTo(TenantCustomer::class, 'purchaser_customer_id');
    }

""" + a, 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: purchaser relation added')
PY

# ---------------------------------------------------------------
# 3. Capability
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Support/CapabilityRegistry.php'
src = io.open(p, encoding='utf-8').read()
assert 'giftcards.manage' not in src
a = "            // ---- Scheduling ----\n"
assert src.count(a) == 1
src = src.replace(a, """            // ---- Register ---- MARKER-GIFTCARDS-ADMIN
            'giftcards.manage' => [
                'label'   => 'Manage gift cards',
                'section' => 'register',
                'desc'    => 'Issue cards manually, adjust balances, and deactivate lost or stolen cards.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

""" + a, 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: giftcards.manage capability registered')
PY

# ---------------------------------------------------------------
# 4. Routes (retail group) + nav entry
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'routes/web.php'
src = io.open(p, encoding='utf-8').read()
a = "Route::get('/register/gift-cards/lookup', [TenantControllers\\RegisterController::class, 'giftCardLookup'])->name('register.gift-cards.lookup'); // MARKER-GIFTCARDS"
assert src.count(a) == 1
src = src.replace(a, a + """
                // MARKER-GIFTCARDS-ADMIN -- staff gift card manager
                Route::get('/gift-cards',                     [TenantControllers\\GiftCardController::class, 'index'])->name('gift-cards.index');
                Route::post('/gift-cards',                    [TenantControllers\\GiftCardController::class, 'store'])->name('gift-cards.store');
                Route::get('/gift-cards/{cardId}',            [TenantControllers\\GiftCardController::class, 'show'])->name('gift-cards.show');
                Route::post('/gift-cards/{cardId}/adjust',    [TenantControllers\\GiftCardController::class, 'adjust'])->name('gift-cards.adjust');
                Route::post('/gift-cards/{cardId}/deactivate',[TenantControllers\\GiftCardController::class, 'deactivate'])->name('gift-cards.deactivate');""", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: admin routes added')

p2 = 'resources/views/layouts/tenant/_nav-items.blade.php'
s2 = io.open(p2, encoding='utf-8').read()
a2 = """      'group'  => null,
      'gate'   => 'online_store_enabled',
    ],
"""
assert s2.count(a2) == 1
s2 = s2.replace(a2, a2 + """    // MARKER-GIFTCARDS-ADMIN
    [
      'route'  => 'tenant.gift-cards.index',
      'label'  => 'Gift Cards',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="4" width="11" height="7.5" rx="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 6.8h11M7 4v7.5M7 4c-.8-1.6-3.2-2.1-3.2-.6C3.8 4.6 5.8 4 7 4zm0 0c.8-1.6 3.2-2.1 3.2-.6C10.2 4.6 8.2 4 7 4z" stroke="currentColor" stroke-width="1.1"/></svg>',
      'group'  => null,
      'gate'   => 'retail_enabled',
    ],
""", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: nav entry added after Orders')
PY

# ---------------------------------------------------------------
# 5. Views
# ---------------------------------------------------------------
mkdir -p resources/views/tenant/gift-cards

cat > resources/views/tenant/gift-cards/index.blade.php <<'EOF'
@extends('layouts.tenant.app')

{{-- MARKER-GIFTCARDS-ADMIN — gift card manager list, per the approved mockup --}}

@php
  $pageTitle = 'Gift cards';
  $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
@endphp

@push('styles')
<style>
  .gc-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-bottom:24px }
  .gc-stat { padding:16px; border-radius:var(--ia-r-md); background:var(--ia-surface); border:0.5px solid var(--ia-border) }
  .gc-stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.07em; font-weight:500; margin-bottom:8px; color:var(--ia-text-dim) }
  .gc-stat-value { font-size:24px; font-weight:500; line-height:1 }
  .gc-stat-sub { font-size:12px; margin-top:5px; color:var(--ia-text-dim) }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div>
    <div class="ia-page-title">Gift cards</div>
    <div class="ia-page-subtitle">Issue, look up, and adjust gift card balances</div>
  </div>
  <div class="ia-page-actions">
    @if($canManage)
      <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('gcIssuePanel').style.display=''">+ Issue gift card</button>
    @endif
  </div>
</div>

@if($canManage)
<div id="gcIssuePanel" class="ia-card" style="display:none;margin-bottom:20px;max-width:640px">
  <div class="ia-card-head"><div class="ia-card-title">Issue a gift card</div></div>
  <form method="POST" action="{{ route('tenant.gift-cards.store') }}">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Type</label>
        <select name="type" class="ia-select" id="gcIssueType">
          <option value="physical">Physical card</option>
          <option value="egift">E-gift card</option>
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Amount ($)</label>
        <input class="ia-input" name="amount" inputmode="decimal" required>
      </div>
    </div>
    <div class="ia-form-group" id="gcIssueCodeWrap">
      <label class="ia-form-label">Card code (from the physical card)</label>
      <input class="ia-input ia-mono" name="code" placeholder="GC-0000-0000-0000">
    </div>
    <div class="ia-input-grid-2" id="gcIssueEmailWrap" style="display:none">
      <div class="ia-form-group">
        <label class="ia-form-label">Recipient name</label>
        <input class="ia-input" name="recipient_name">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Recipient email</label>
        <input class="ia-input" name="recipient_email" type="email">
      </div>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Note (why it's being issued)</label>
      <input class="ia-input" name="note" maxlength="200">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--primary">Issue card</button>
      <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('gcIssuePanel').style.display='none'">Cancel</button>
    </div>
  </form>
</div>
@endif

<div class="gc-stats">
  <div class="gc-stat">
    <div class="gc-stat-label">Outstanding balance</div>
    <div class="gc-stat-value">{{ $money($stats['outstanding_cents']) }}</div>
    <div class="gc-stat-sub">across {{ $stats['active_count'] }} active {{ \Illuminate\Support\Str::plural('card', $stats['active_count']) }}</div>
  </div>
  <div class="gc-stat">
    <div class="gc-stat-label">Sold — 30 days</div>
    <div class="gc-stat-value">{{ $money($stats['sold_cents']) }}</div>
    <div class="gc-stat-sub">{{ $stats['sold_count'] }} {{ \Illuminate\Support\Str::plural('card', $stats['sold_count']) }}</div>
  </div>
  <div class="gc-stat">
    <div class="gc-stat-label">Redeemed — 30 days</div>
    <div class="gc-stat-value">{{ $money($stats['redeemed_cents']) }}</div>
    <div class="gc-stat-sub">{{ $stats['redeemed_count'] }} {{ \Illuminate\Support\Str::plural('redemption', $stats['redeemed_count']) }}</div>
  </div>
  <div class="gc-stat">
    <div class="gc-stat-label">Fully used</div>
    <div class="gc-stat-value">{{ $stats['used_count'] }}</div>
    <div class="gc-stat-sub">all time</div>
  </div>
</div>

<form method="GET" class="ia-toolbar">
  <input class="ia-input" name="q" value="{{ $q }}" placeholder="Search code, customer, or email…">
  <select class="ia-select" name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <option value="active" @selected($status==='active')>Active</option>
    <option value="used" @selected($status==='used')>Used</option>
    <option value="deactivated" @selected($status==='deactivated')>Deactivated</option>
    <option value="pending" @selected($status==='pending')>Pending payment</option>
  </select>
  <select class="ia-select" name="type" onchange="this.form.submit()">
    <option value="">All types</option>
    <option value="physical" @selected($type==='physical')>Physical</option>
    <option value="egift" @selected($type==='egift')>E-gift</option>
  </select>
</form>

@if($rows->isEmpty())
  <div class="ia-empty" style="background:var(--ia-surface);border:0.5px dashed var(--ia-border)">
    <div class="ia-empty-title">No gift cards yet</div>
    <div class="ia-empty-desc">Sell one at the register with "+ Sell gift card", or issue one from here.</div>
  </div>
@else
  <div class="ia-table-wrap">
    <table class="ia-table">
      <thead><tr>
        <th>Code</th><th>Type</th><th>Purchaser</th><th>Recipient</th>
        <th class="ia-num">Original</th><th class="ia-num">Balance</th><th>Status</th><th>Last activity</th>
      </tr></thead>
      <tbody>
        @foreach($rows as $r)
          <tr onclick="window.location='{{ route('tenant.gift-cards.show', ['cardId' => $r->id]) }}'">
            <td class="ia-mono">{{ $r->maskedCode() }}</td>
            <td>{{ $r->type === 'egift' ? 'E-gift' : 'Physical' }}</td>
            <td>{{ $r->purchaser?->fullName() ?? $r->purchaser_name ?? 'Walk-in' }}</td>
            <td>{{ $r->recipient_email ?: '—' }}</td>
            <td class="ia-num">{{ $money($r->original_cents) }}</td>
            <td class="ia-num" style="font-weight:600">{{ $money($r->balance_cents) }}</td>
            <td>
              @php
                $badge = match($r->status) {
                  'active' => 'completed', 'used' => 'pending',
                  'deactivated' => 'cancelled', default => 'partial',
                };
              @endphp
              <span class="ia-badge ia-badge--{{ $badge }}">{{ ucfirst($r->status) }}</span>
            </td>
            <td style="opacity:.55">{{ tlocal($r->updated_at)->format('M j, Y') }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px">{{ $rows->links() }}</div>
@endif

@endsection

@push('scripts')
<script>
  // MARKER-GIFTCARDS-ADMIN — issue panel field toggle
  (function () {
    var sel = document.getElementById('gcIssueType');
    if (!sel) return;
    sel.addEventListener('change', function () {
      var egift = sel.value === 'egift';
      document.getElementById('gcIssueCodeWrap').style.display = egift ? 'none' : '';
      document.getElementById('gcIssueEmailWrap').style.display = egift ? '' : 'none';
    });
  })();
</script>
@endpush
EOF

cat > resources/views/tenant/gift-cards/show.blade.php <<'EOF'
@extends('layouts.tenant.app')

{{-- MARKER-GIFTCARDS-ADMIN — card detail + ledger, per the approved mockup --}}

@php
  $pageTitle = 'Gift card';
  $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $badge = match($card->status) {
    'active' => 'completed', 'used' => 'pending',
    'deactivated' => 'cancelled', default => 'partial',
  };
  $kindBadge = fn ($k) => match($k) {
    'issue' => ['completed', 'Issued'],
    'redeem' => ['partial', 'Redeemed'],
    'adjust' => ['shipped', 'Adjustment'],
    'deactivate' => ['cancelled', 'Deactivated'],
    default => ['pending', ucfirst($k)],
  };
@endphp

@push('styles')
<style>
  .gcd-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:24px }
  .gcd-stat { padding:16px; border-radius:var(--ia-r-md); background:var(--ia-surface); border:0.5px solid var(--ia-border) }
  .gcd-row { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:0.5px solid rgba(255,255,255,.06); font-size:13px }
  .gcd-row:last-child { border-bottom:none }
  .gcd-kind { min-width:96px }
  .gcd-desc { flex:1; color:var(--ia-text-muted) }
  .gcd-when { font-size:12px; color:var(--ia-text-dim) }
  .gcd-amt { font-variant-numeric:tabular-nums; font-weight:600; min-width:76px; text-align:right }
  .gcd-amt.credit { color:#9ccf5f }
  .gcd-amt.debit { color:#F09595 }
  .gcd-bal { font-variant-numeric:tabular-nums; min-width:76px; text-align:right; color:var(--ia-text-dim) }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div>
    <div class="ia-page-title ia-mono" style="letter-spacing:.06em">{{ $card->code }}</div>
    <div class="ia-page-subtitle">
      {{ $card->type === 'egift' ? 'E-gift' : 'Physical' }}
      @if($card->purchaser) · Purchased by {{ $card->purchaser->fullName() }}
      @elseif($card->purchaser_name) · Purchased by {{ $card->purchaser_name }} @endif
      · Issued {{ tlocal($card->created_at)->format('M j, Y') }}
      @if($card->recipient_email) · To {{ $card->recipient_email }} @endif
    </div>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.gift-cards.index') }}" class="ia-btn ia-btn--ghost ia-btn--sm">← All gift cards</a>
    @if($canManage && $card->status !== 'deactivated')
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" onclick="document.getElementById('gcAdjustPanel').style.display=''">Adjust balance</button>
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm" onclick="document.getElementById('gcDeactivatePanel').style.display=''">Deactivate</button>
    @endif
  </div>
</div>

@if($canManage && $card->status !== 'deactivated')
<div id="gcAdjustPanel" class="ia-card" style="display:none;margin-bottom:16px;max-width:560px">
  <div class="ia-card-head"><div class="ia-card-title">Adjust balance</div></div>
  <form method="POST" action="{{ route('tenant.gift-cards.adjust', ['cardId' => $card->id]) }}">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Direction</label>
        <select name="direction" class="ia-select">
          <option value="credit">Add (credit)</option>
          <option value="debit">Remove (debit)</option>
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Amount ($)</label>
        <input class="ia-input" name="amount" inputmode="decimal" required>
      </div>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Reason <span class="ia-required">*</span></label>
      <input class="ia-input" name="reason" maxlength="200" required placeholder="e.g. Goodwill — broken spoke on first ride">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--primary">Record adjustment</button>
      <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('gcAdjustPanel').style.display='none'">Cancel</button>
    </div>
  </form>
</div>

<div id="gcDeactivatePanel" class="ia-card" style="display:none;margin-bottom:16px;max-width:560px">
  <div class="ia-card-head"><div class="ia-card-title">Deactivate card</div></div>
  <form method="POST" action="{{ route('tenant.gift-cards.deactivate', ['cardId' => $card->id]) }}">
    @csrf
    <div class="ia-form-group">
      <label class="ia-form-label">Reason <span class="ia-required">*</span></label>
      <input class="ia-input" name="reason" maxlength="200" required placeholder="e.g. Reported lost">
    </div>
    <div style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:14px">The remaining balance stays on record but the card can no longer be redeemed.</div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--danger">Deactivate</button>
      <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('gcDeactivatePanel').style.display='none'">Cancel</button>
    </div>
  </form>
</div>
@endif

<div class="gcd-stats">
  <div class="gcd-stat">
    <div class="ia-label" style="color:var(--ia-text-dim);margin-bottom:8px">Current balance</div>
    <div style="font-size:24px;font-weight:500;color:var(--ia-accent)">{{ $money($card->balance_cents) }}</div>
  </div>
  <div class="gcd-stat">
    <div class="ia-label" style="color:var(--ia-text-dim);margin-bottom:8px">Original value</div>
    <div style="font-size:24px;font-weight:500">{{ $money($card->original_cents) }}</div>
  </div>
  <div class="gcd-stat">
    <div class="ia-label" style="color:var(--ia-text-dim);margin-bottom:8px">Status</div>
    <div style="padding-top:5px"><span class="ia-badge ia-badge--{{ $badge }}">{{ ucfirst($card->status) }}</span></div>
    @if($card->status === 'deactivated' && $card->deactivated_reason)
      <div style="font-size:12px;color:var(--ia-text-dim);margin-top:6px">{{ $card->deactivated_reason }}</div>
    @endif
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head"><div class="ia-card-title">Ledger</div></div>
  @forelse($card->transactions as $t)
    @php [$tb, $tl] = $kindBadge($t->kind); @endphp
    <div class="gcd-row">
      <span class="gcd-kind"><span class="ia-badge ia-badge--{{ $tb }}">{{ $tl }}</span></span>
      <span class="gcd-desc">
        {{ $t->note }}
        @if($t->sale_id)
          · <a href="{{ route('tenant.register.sales.receipt', ['id' => $t->sale_id]) }}" style="color:var(--ia-text);border-bottom:1px dotted var(--ia-border-strong)" onclick="event.stopPropagation()">View sale</a>
        @endif
      </span>
      <span class="gcd-when">{{ tlocal($t->created_at)->format('M j, g:i A') }}</span>
      <span class="gcd-amt {{ $t->amount_cents >= 0 ? 'credit' : 'debit' }}">{{ $t->amount_cents >= 0 ? '+' : '−' }}{{ $money(abs($t->amount_cents)) }}</span>
      <span class="gcd-bal">{{ $money($t->balance_after_cents) }}</span>
    </div>
  @empty
    <div style="padding:20px 0;color:var(--ia-text-dim);font-size:13px">No ledger entries.</div>
  @endforelse
</div>

@endsection
EOF
echo "ok: 2 views created"

echo ""
echo "== gift-cards admin applied =="
echo "Post-deploy: php artisan optimize:clear"
