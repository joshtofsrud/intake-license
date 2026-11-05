@extends('layouts.tenant.app')
@section('title', 'Buying')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
<link rel="stylesheet" href="{{ asset('css/tenant/buying.css') }}?v={{ filemtime(public_path('css/tenant/buying.css')) }}">
@endpush

@section('content')
{{-- MARKER-BUYING --}}
<div class="ia-section ia-section--wide">

@if($lock)
  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">Buying</h1>
      <p class="ia-page-subtitle">What's selling, what's about to run out, and what to order.</p>
    </div>
  </div>

  @if($lock === 'addon')
    @php
      $vis = $addon->visibility ?? 'ask';
      $selfServe = $addon && $vis === 'self_serve' && $addon->is_self_serve;
    @endphp
    <div class="mk-lock">
      <div class="mk-lock-panel">
        <div class="mk-lock-eyebrow">Add-on</div>
        <h2>Buying</h2>
        <p>See which of your items are really selling, how long your stock will last counting what's on order, and what your distributors still have, then add it to a purchase order.</p>
        <ul>
          <li>Items that are moving, with days of stock left</li>
          <li>Searches that found nothing, as ordering signals</li>
          <li>Open to buy against your own inventory goal</li>
          <li>Add to a purchase order straight from what's running low</li>
        </ul>
        <div class="mk-lock-actions">
          @if(\Illuminate\Support\Facades\Route::has('tenant.feature_addons.index') && ($isOwner ?? false))
            <a class="ia-btn ia-btn--primary" href="{{ route('tenant.feature_addons.index') }}">{{ $selfServe ? 'Add Buying to your plan' : 'Ask about Buying' }}</a>
          @else
            <button type="button" class="ia-btn ia-btn--primary" id="by-ask-owner">Ask an owner to add it</button>
          @endif
        </div>
        <div class="rb-hint" style="margin-top:12px">Included with the Scale package.@if($addon && $addon->price_display_override) {{ $addon->price_display_override }}.@endif Searches that find nothing are already being counted, so they're waiting when you turn it on.</div>
      </div>
    </div>
  @else
    <div class="mk-lock">
      <div class="mk-lock-panel">
        <div class="mk-lock-eyebrow">Access</div>
        <h2>Your role doesn't include Buying</h2>
        <p>Your shop has Buying turned on, but the role you're signed in with can't open it. An owner can change that under Team &amp; access.</p>
        <div class="mk-lock-actions">
          <button type="button" class="ia-btn ia-btn--primary" id="by-ask-owner">Ask an owner for access</button>
        </div>
      </div>
    </div>
  @endif
  <script>
  (function () {
    var b = document.getElementById('by-ask-owner'); if (!b) return;
    b.addEventListener('click', function () {
      b.disabled = true;
      fetch(@json(route('tenant.buying-access')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (d) {
          b.textContent = d.sent ? 'Asked' : 'No owner to ask';
          if (window.IntakeToast) window.IntakeToast.success(d.sent ? 'The shop\'s owners have been asked.' : 'There\'s no active owner on this shop to ask.');
        }).catch(function () { b.disabled = false; if (window.IntakeToast) window.IntakeToast.error('That didn\'t send. Try again.'); });
    });
  })();
  </script>
@else

  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">Buying</h1>
      <p class="ia-page-subtitle">What's selling, what's about to run out, and what to order.</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <div class="ivr-window" id="by-win">
        @foreach(\App\Services\Tenant\BuyingService::WINDOWS as $wk => $wl)
          <a href="{{ route('tenant.buying', array_filter(['w' => $wk, 'loc' => $loc, 'tab' => request('tab')])) }}" class="{{ $w === $wk ? 'on' : '' }}">{{ ['30 days' => '30d', '90 days' => '90d', '6 months' => '6mo'][$wl] }}</a>
        @endforeach
      </div>
      <a class="ia-btn ia-btn--ghost ia-btn--sm" href="{{ route('tenant.buying.export', array_filter(['w' => $w, 'loc' => $loc])) }}">Export CSV</a>
      @if($locs->count() > 1)
        <select class="rb-sel" id="by-loc" aria-label="Location">
          <option value="">All locations</option>
          @foreach($locs as $l)<option value="{{ $l->id }}" @selected($loc === $l->id)>{{ $l->name }}</option>@endforeach
        </select>
      @endif
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="by-drafts-btn">Draft POs <span class="mk-count" id="by-dcount">{{ count($drafts) }}</span></button>
    </div>
  </div>

  @if(session('flash'))
    <div class="ia-flash ia-flash--{{ session('flash')['type'] }}" style="margin-bottom:14px">{{ session('flash')['message'] }}</div>
  @endif

  <div class="by-tabs" id="by-tabs">
    <a href="#" class="mk-tab {{ $tab === 'moving' ? 'on' : '' }}" data-tab="moving">Moving</a>
    <a href="#" class="mk-tab {{ $tab === 'searched' ? 'on' : '' }}" data-tab="searched">Searched, not stocked <span class="mk-tabn" id="by-scount">{{ count($searches) }}</span></a>
    <a href="#" class="mk-tab {{ $tab === 'otb' ? 'on' : '' }}" data-tab="otb">Open to buy</a>
  </div>

  {{-- ===================== MOVING ===================== --}}
  <section class="mk-pane {{ $tab === 'moving' ? 'on' : '' }}" id="pane-moving">
    <div class="mk-legend">
      <b>What this shows.</b> Items that sold in the last {{ \App\Services\Tenant\BuyingService::WINDOWS[$w] }}{{ $loc ? ' at ' . $locs->firstWhere('id', $loc)?->name : '' }}: register and online sales and parts used on work orders, refunds taken off.
      <b>Per week</b> is the average of the last {{ $set['pace_weeks'] }} weeks.
      <b>On order</b> is what's still owed on purchase orders you've placed, with each line's expected date. Units held for a customer's special order are shown but don't count as stock.
      @if($velocity['report_on'])Distributor figures cover only items in your inventory, and distributor pace is how fast <i>their</i> stock is dropping, not your sales.@endif
      @if($canPo)<b>Add to PO</b> puts a line on the draft for that vendor and location. Nothing is sent to the vendor until you place the PO on Purchase orders.@endif
      @if($canSettings)The thresholds behind each status are in <a class="mk-link" href="{{ route('tenant.settings.index') }}#ordering">Settings › Ordering</a>.@endif
    </div>

    @if(! $rows)
      <div class="mk-empty">
        <div class="t">Nothing sold {{ $loc ? 'here ' : '' }}in the last {{ \App\Services\Tenant\BuyingService::WINDOWS[$w] }}.</div>
        <div class="s">Buying reads sales and parts used on work orders from your stock history. Try a longer window{{ $loc ? ' or all locations' : '' }}.</div>
        @if($w !== 26)<a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.buying', ['w' => 26]) }}">Show 6 months{{ $loc ? ', all locations' : '' }}</a>@endif
      </div>
    @else
      <div class="mk-stack" id="by-stack" role="group" aria-label="Items by status"></div>
      <div class="mk-stack-keys" id="by-keys"></div>
      <div class="rb-strip" id="by-strip"></div>

      <div class="rb-bar">
        <div class="rb-seg" id="by-seg"></div>
        <select class="rb-sel" id="by-fv"><option value="">All vendors</option></select>
        <select class="rb-sel" id="by-fb"><option value="">All brands</option></select>
        <input class="ia-input mk-search" id="by-fq" placeholder="Search items" />
        <span class="sp"></span>
        <span class="rb-hint" id="by-count"></span>
      </div>

      <div class="rb-tbl mk-tbl">
        <table class="ia-table" id="by-tbl">
          <thead><tr>
            <th>Item</th><th>Sold</th><th class="ia-num">Per week</th><th class="ia-num">On hand</th>
            <th class="ia-num">Days left</th><th>On order</th>@if($velocity['report_on'])<th>Distributor</th>@endif<th>Status</th><th></th>
          </tr></thead>
          <tbody></tbody>
        </table>
        <div class="by-more" id="by-more" hidden><button type="button" class="ia-btn ia-btn--secondary ia-btn--sm">Show more</button></div>
        <div class="mk-empty" id="by-empty" hidden>
          <div class="t">Nothing matches those filters.</div>
          <div class="s">Clear the filters, or try a longer window.</div>
          <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="by-clear">Clear filters</button>
        </div>
      </div>
    @endif
  </section>

  {{-- ===================== SEARCHED ===================== --}}
  <section class="mk-pane {{ $tab === 'searched' ? 'on' : '' }}" id="pane-searched">
    <div class="mk-legend"><b>What this shows.</b> Searches at the register and on your online store in the last 90 days that found nothing in stock{{ $loc ? ' (this location\'s registers, plus the online store)' : '' }}. Each one is a customer who wanted something you didn't have. It's a signal, not an order. A search typed letter by letter counts once. Dismissing one hides it until someone searches for it again.</div>
    <div class="rb-h"><h2>Unanswered searches</h2><span class="rb-hint">Last 90 days</span></div>
    <div class="rb-tbl mk-tbl">
      <table class="ia-table" id="by-stbl"><thead><tr><th>Search</th><th class="ia-num">Times</th><th>Where</th><th>Last searched</th><th>Closest match in your inventory</th><th></th></tr></thead><tbody></tbody></table>
      <div class="mk-empty" id="by-sempty" hidden>
        <div class="t">No unanswered searches.</div>
        <div class="s">When a search at the register or on your online store finds nothing in stock, it shows up here.</div>
        <a class="ia-btn ia-btn--secondary ia-btn--sm" href="#" data-tab="moving">See what's moving</a>
      </div>
    </div>
  </section>

  {{-- ===================== OPEN TO BUY ===================== --}}
  <section class="mk-pane {{ $tab === 'otb' ? 'on' : '' }}" id="pane-otb">
    @if(! $canCost)
      <div class="mk-empty" style="border:0;text-align:left">
        <div class="t">Open to buy is in dollars at cost.</div>
        <div class="s">Your role doesn't include Costs &amp; margins, so it isn't shown. An owner can change that under Team &amp; access.</div>
        <a class="ia-btn ia-btn--secondary ia-btn--sm" href="#" data-tab="moving">Back to Moving</a>
      </div>
    @else
      <div class="mk-legend"><b>What this shows.</b> How much you can still spend to reach your inventory goal: goal, less stock on hand at cost, less what's still owed on placed POs. <b>In drafts</b> is shown so you can see what's planned, but it doesn't count until the PO is placed. Goals are per category and per location{{ $locs->count() > 1 && ! $loc ? '; pick a location to edit them' : '' }}.</div>
      <div class="rb-strip" id="by-ostrip"></div>
      <div class="rb-h"><h2>By category</h2><span class="rb-hint">{{ $loc || $locs->count() === 1 ? 'Edit a goal, then Save goals' : 'All locations: goals add up each location\'s' }}</span></div>
      <form method="POST" action="{{ route('tenant.buying.goals') }}" id="by-goals">
        @csrf
        <input type="hidden" name="location_id" value="{{ $loc ?? '' }}">
        <input type="hidden" name="w" value="{{ $w }}">
        <div class="rb-tbl mk-tbl">
          <table class="ia-table" id="by-otbl"><thead><tr><th>Category</th><th class="ia-num">On hand</th><th class="ia-num">On order</th><th class="ia-num">In drafts</th><th class="ia-num">Goal</th><th class="ia-num">Open to buy</th><th>Position</th></tr></thead><tbody></tbody></table>
        </div>
        @if($loc)
          <div class="mk-savebar"><button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save goals</button><span class="rb-hint">Goals for {{ $locs->firstWhere('id', $loc)?->name }}.</span></div>
        @else
          <div class="mk-savebar"><span class="rb-hint">Pick a location at the top to set its goals.</span></div>
        @endif
      </form>
    @endif
  </section>

  {{-- drawer --}}
  <div class="mk-scrim" id="by-scrim" hidden></div>
  <aside class="mk-drawer" id="by-drawer" hidden aria-label="Item detail">
    <div class="mk-dh"><div><div class="mk-dt" id="d-name"></div><div class="mk-ds" id="d-sub"></div></div><button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="d-close">Close</button></div>
    <div class="mk-db">
      <div id="d-pill"></div>
      <div class="mk-note" id="d-note"></div>
      <div class="rb-h" style="margin-top:20px"><h2>Weekly sales</h2><span class="rb-hint" id="d-chart-h"></span></div>
      <div id="d-chart"></div>
      <div class="mk-kv" id="d-kv"></div>
      <div class="rb-h" style="margin-top:22px"><h2>Order</h2></div>
      <div class="mk-onpo" id="d-onpo"></div>
      <div id="d-orderform">
        <div class="mk-form">
          <label>Cover for<select class="rb-sel" id="d-cover">@foreach([2, 4, 6, 8, 12] as $cw)<option value="{{ $cw }}" @selected($set['cover_weeks'] === $cw)>{{ $cw }} weeks</option>@endforeach @if(! in_array($set['cover_weeks'], [2, 4, 6, 8, 12], true))<option value="{{ $set['cover_weeks'] }}" selected>{{ $set['cover_weeks'] }} weeks</option>@endif</select></label>
          <label>From<select class="rb-sel" id="d-from"></select></label>
          <label>Ship to<select class="rb-sel" id="d-loc">@foreach($locs as $l)<option value="{{ $l->id }}" @selected(($loc ?? $locs->first()->id) === $l->id)>{{ $l->name }}</option>@endforeach</select></label>
          <label>Quantity<span class="mk-step"><button type="button" id="q-minus" aria-label="Fewer">&minus;</button><input class="ia-input" id="d-qty" inputmode="numeric" /><button type="button" id="q-plus" aria-label="More">+</button></span></label>
        </div>
        <div class="rb-hint" id="d-calc" style="margin-top:8px"></div>
        <div class="mk-target" id="d-target"></div>
      </div>
      <div class="by-ro" id="d-nopo" hidden>Your role can't add to purchase orders. Someone who can open Purchase orders can order it.</div>
    </div>
    <div class="mk-df"><button type="button" class="ia-btn ia-btn--primary" id="d-add">Add to PO</button><button type="button" class="ia-btn ia-btn--ghost" id="d-cancel">Cancel</button></div>
  </aside>

  {{-- draft POs --}}
  <div class="mk-modal" id="by-dmodal" hidden>
    <div class="mk-dialog" role="dialog" aria-label="Draft POs">
      <div class="mk-dh"><div><div class="mk-dt">Draft POs</div><div class="mk-ds">One draft per vendor and ship-to location. Nothing is sent until you place a PO.</div></div></div>
      <div class="mk-db" id="by-dbody"></div>
      <div class="mk-df">@if($canPo)<a class="ia-btn ia-btn--primary" href="{{ route('tenant.purchase-orders.index', ['tab' => 'draft']) }}">Open Purchase orders</a>@endif<span class="sp"></span><button type="button" class="ia-btn ia-btn--ghost" id="by-dclose">Close</button></div>
    </div>
  </div>

  <script type="application/json" id="by-data">{!! json_encode([
      'rows' => $rows, 'searches' => $searches, 'otb' => $otb, 'drafts' => $drafts,
      'locs' => $locs->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values(), 'loc' => $loc, 'w' => $w,
      'window' => \App\Services\Tenant\BuyingService::WINDOWS[$w], 'set' => $set,
      'canCost' => $canCost, 'canPo' => $canPo, 'vendorsAll' => $vendorsAll, 'velocity' => $velocity,
      'today' => now()->toDateString(),
      'urls' => [
        'page' => route('tenant.buying'), 'add' => route('tenant.buying.add-to-po'), 'dismiss' => route('tenant.buying.dismiss'),
        'po' => $canPo ? route('tenant.purchase-orders.show', ['id' => '__ID__']) : null,
        'poOpen' => $canPo ? route('tenant.purchase-orders.index', ['tab' => 'open']) : null,
        'find' => route('tenant.inventory.index'),
      ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
  <script src="{{ asset('js/tenant/buying.js') }}?v={{ filemtime(public_path('js/tenant/buying.js')) }}"></script>
@endif
</div>
@endsection
