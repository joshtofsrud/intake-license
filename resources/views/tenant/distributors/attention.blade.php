@extends('layouts.tenant.app')
@php $pageTitle = 'Catalog Attention'; @endphp

{{-- MARKER-PATCH-HLC7C --}}

@push('styles')
<style>
.at-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px;margin-bottom:18px}
.at-chips{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.at-chip{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:11px 16px;min-width:120px}
/* MARKER-ATTENTION-TILES — a tile is a control now, so it looks like one. */
a.at-chip{display:block;text-decoration:none;color:inherit;cursor:pointer;transition:border-color .12s,background .12s}
a.at-chip:hover{border-color:var(--ia-border-strong)}
a.at-chip.on{border-color:var(--ia-accent);background:rgba(224,166,75,.10)}
a.at-chip.on .k{color:var(--ia-accent)}
.at-chip .v{font-size:22px;font-weight:700;font-family:var(--ia-mono)}
.at-chip .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-top:2px}
.at-tbl input[type=checkbox],.at-bar input[type=checkbox]{accent-color:var(--ia-accent);width:15px;height:15px;cursor:pointer;vertical-align:middle}
.at-tbl{width:100%;border-collapse:collapse;font-size:13px}
.at-tbl th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--ia-border)}
.at-tbl td{padding:11px 10px;border-bottom:.5px solid var(--ia-border);vertical-align:middle}
.at-mono{font-family:var(--ia-mono)}
.at-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
/* MARKER-PATCH-558 */
.at-sync{display:flex;align-items:stretch;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:14px;margin-bottom:20px;overflow:hidden}
.at-sync-stat{padding:16px 20px;border-right:0.5px solid var(--ia-border);flex:1;min-width:0}
.at-sync-stat .k{font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600}
.at-sync-stat .v{font-size:15px;font-weight:700;margin-top:5px}
.at-sync-stat .d{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
.at-sync-act{padding:14px 18px;display:flex;flex-direction:column;justify-content:center;gap:8px;flex:none;min-width:230px}
.at-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;vertical-align:1px}
.at-dot.ok{background:#8FD14F}.at-dot.bad{background:#F26D6D}.at-dot.run{background:var(--ia-accent)}.at-dot.idle{background:var(--ia-text-muted)}
.at-chg{font-size:12.5px;line-height:1.6;max-width:430px}
.at-chg .old{color:var(--ia-text-muted);text-decoration:line-through;text-decoration-color:rgba(242,109,109,.55)}
.at-hl{background:rgba(205,233,138,.16);border-radius:3px;padding:0 2px;color:#cde98a}
/* MARKER-ATTENTION-FILTERROW — desktop keeps the toggle on its own line. */
.at-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.at-filter .at-seg{flex:0 0 100%;margin-top:2px}
.at-pager-top{display:none} /* MARKER-ATTENTION-TIGHT — mobile only */
/* MARKER-ATTENTION-ACTIONS — the same hook the Communication Center uses to get
   the tab bar out of the way while something is being edited. */
body.at-bar-open .ia-mobile-nav{display:none}
.at-bar .at-btn[hidden]{display:none !important}

/* MARKER-ATTENTION-MOBILE — below 720px the table becomes cards. The markup is
   unchanged: these rows live inside the bulk-action form and carry the
   checkboxes selection depends on, so this is done by changing how they are
   displayed rather than by rebuilding them. */
@media (max-width: 720px) {

  /* the sync card: four across becomes one per row */
  .at-sync{flex-direction:column}
  /* MARKER-ATTENTION-TIGHT — label and value share a line; this was three
     stacked blocks and a button, which filled a phone screen on its own. */
  .at-sync-stat{border-right:0;border-bottom:.5px solid var(--ia-border);padding:8px 13px;
    display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
  .at-sync-stat .k{font-size:9.5px;letter-spacing:.07em;flex:0 0 88px}
  .at-sync-stat .v{font-size:13.5px;margin-top:0}
  .at-sync-stat .d{font-size:10.5px;margin-top:0;flex:1 1 100%;padding-left:96px}
  .at-sync-act{min-width:0;padding:10px 13px}
  .at-sync-act .at-btn{width:100%}

  /* the stat tiles: a compact 2x2, not four tall boxes */
  .at-chips{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:12px}
  .at-chip{min-width:0;padding:7px 11px}
  .at-chip .v{font-size:17px}
  .at-chip .k{font-size:9.5px}

  /* pager above the list; the bottom one stays for the end of the page */
  .at-pager-top{display:block;margin-bottom:10px}

  /* the filter form: two dropdowns a row, then Filter + Clear + the stock
     toggle sharing the last line instead of taking two of their own */
  .at-filter{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .at-filter select{flex:1 1 46%;min-width:0}
  .at-filter > .at-btn{flex:0 0 auto;padding:8px 14px}
  .at-filter .at-seg{flex:1 1 auto;margin-top:0;display:flex;min-width:0}
  .at-filter .at-segbtn{flex:1 1 0;min-width:0;padding:8px 6px;font-size:11.5px;
    text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

  /* the table becomes cards — as a FLEX row, so the checkbox is a normal
     child. Absolute positioning inside a display:block table row does not
     reliably stay inside it, which is how the checkbox ended up outside the
     card's left edge. */
  .at-tbl, .at-tbl tbody, .at-tbl tr, .at-tbl td{display:block;width:100%}
  .at-tbl thead{position:absolute;left:-9999px}
  .at-tbl tr{
    display:flex;
    flex-wrap:wrap;
    align-items:flex-start;
    gap:0 10px;
    border:.5px solid var(--ia-border);
    border-radius:var(--ia-r-md, 10px);
    padding:11px 12px;
    margin-bottom:8px;
  }
  .at-tbl td{border:0;padding:0}

  /* checkbox: a column of its own at the start of the card */
  .at-c-pick{flex:0 0 22px;width:22px;padding-top:1px}
  .at-tbl .at-cb{width:19px;height:19px;margin:0}

  /* name takes the rest of the first line; the reason rides along with it */
  .at-c-item{flex:1 1 0;min-width:0;font-size:13.5px;line-height:1.35}
  .at-c-why{flex:0 0 auto;margin-left:auto;align-self:flex-start}
  .at-c-why .at-badge{font-size:10px;padding:1px 7px}

  .at-c-item .at-dim{font-size:10.5px}

  /* the change: full width beneath, labelled, wrapping */
  .at-c-chg{
    flex:1 1 100%;
    margin-top:9px;
    padding-top:8px !important;
    border-top:.5px solid var(--ia-border) !important;
    max-width:none;
    white-space:normal;
    word-break:break-word;
    font-size:12px;
    line-height:1.5;
  }
  .at-c-chg::before{
    content:attr(data-l);
    display:block;
    font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;
    color:var(--ia-text-dim);margin-bottom:4px;
  }
  .at-c-chg .when{font-size:10.5px}

  /* per-row actions across the bottom of the card */
  .at-tbl td:last-child{flex:1 1 100%;margin-top:9px;text-align:left !important;
    display:flex;gap:6px;flex-wrap:wrap}
  .at-tbl td:last-child .at-btn{flex:1 1 46%;padding:8px 9px;font-size:12px}

  /* the bulk bar follows you down the page */
  .at-bar{
    position:sticky;bottom:0;z-index:5;
    background:var(--ia-surface);
    border:.5px solid var(--ia-border-strong,rgba(255,255,255,.22));
    border-radius:var(--ia-r-lg,14px);
    padding:11px 12px;margin-top:12px;
    display:flex;flex-wrap:wrap;gap:7px;
  }
  .at-bar .at-btn{flex:1 1 46%}
  .at-bar > span:first-child{flex:1 1 100%;margin-bottom:2px}

  /* the page title and the history link stop fighting for one line */
  .ia-page-head{flex-wrap:wrap}
}
.at-chg .nb{color:#F26D6D;font-weight:700}
.at-chg .when{font-size:11px;color:var(--ia-text-muted)}
.at-rowact{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.at-rowact .at-btn{padding:5px 11px;font-size:12px}
.at-b-map{background:rgba(226,75,74,.16);color:#f0a3a3}
.at-b-msrp{background:rgba(239,159,39,.16);color:#f0c78a}
.at-b-van{background:rgba(120,140,170,.16);color:#aebbcf}
.at-b-title{background:rgba(190,242,100,.15);color:#cde98a}
.at-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.at-sel{padding:7px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.at-seg{display:inline-flex;border:1px solid var(--ia-border-strong);border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:14px}
.at-segbtn{padding:8px 16px;font-size:13px;font-weight:600;color:var(--ia-text-dim);text-decoration:none;border-right:1px solid var(--ia-border-strong)}
.at-segbtn:last-child{border-right:0}
.at-segbtn.active{background:var(--ia-accent);color:var(--ia-accent-text)}
/* MARKER-ATTENTION-SLIM — hidden until something is selected. A sticky bar
   that is always there covers the row you are reading, and on a phone it
   covered the diff you are being asked to judge. */
.at-bar[hidden]{display:none !important}
.at-bar{position:sticky;bottom:10px;background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:12px 16px;margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;box-shadow:0 10px 28px rgba(0,0,0,.4)}
.at-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.at-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.at-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.at-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.at-empty{text-align:center;padding:48px 20px;color:var(--ia-text-dim)}
.at-empty .big{font-size:34px;margin-bottom:8px}
.at-dim{color:var(--ia-text-dim)}
.at-toggle{font-size:12px;color:var(--ia-text-dim)}
.at-toggle a{color:var(--ia-accent);text-decoration:none}
</style>
@endpush

@section('content')
@php
  $fmt = fn($c) => $c !== null ? '$' . number_format($c/100, 2) : '—';
  // MARKER-PATCH-558 — plain speech, not jargon
  $badge = function($r){
    return match($r){
      'below_map'     => ['at-b-map','Below MAP'],
      'off_msrp'      => ['at-b-msrp','Off MSRP'],
      'title_changed' => ['at-b-title','Renamed by distributor'],
      'details_changed' => ['at-b-title','Details updated'], // MARKER-DETAILS-WATCH
      'cost_vanished' => ['at-b-van','Cost removed'],
      'map_vanished'  => ['at-b-van','MAP removed'],
      'msrp_vanished' => ['at-b-van','MSRP removed'],
      default         => ['at-b-van', str_replace('_',' ', $r)],
    };
  };
  // MARKER-PATCH-558 — highlight words in $new that aren't in $old
  $wordDiff = function(?string $old, ?string $new): string {
    if (blank($new)) return '—';
    $oldWords = array_map('mb_strtolower', preg_split('/\s+/', (string) $old) ?: []);
    return collect(preg_split('/\s+/', $new))
        ->map(fn ($w) => in_array(mb_strtolower($w), $oldWords, true)
            ? e($w) : '<span class="at-hl">' . e($w) . '</span>')
        ->implode(' ');
  };
@endphp
<div style="max-width:980px">
  {{-- MARKER-ATTENTION-PER-DIST — this queue spans every connected
       distributor, so it can't be titled after one of them. --}}
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Distributor catalogs</h1>
  {{-- MARKER-CATALOG-UNDO --}}
  <a href="{{ route('tenant.distributors.attention.history') }}" class="ia-back-link" style="margin-left:auto">
    Change history
  </a>
  @include('layouts.tenant._inventory-tabs')

  {{-- MARKER-PATCH-558 — sync status card (supersedes the 555 button row):
     plain language for shop owners, not developers. --}}
  @php
    $srStats = $lastSyncRun ? (json_decode($lastSyncRun->stats ?? '[]', true) ?: []) : [];
    $srRunning = $lastSyncRun && ! $lastSyncRun->finished_at;
  @endphp
  <div class="at-sync">
    <div class="at-sync-stat">
      <div class="k">Catalog sync</div>
      <div class="v">
        @if(!$lastSyncRun) <span class="at-dot idle"></span>Never run
        @elseif($srRunning) <span class="at-dot run"></span>Checking now…
        @elseif($lastSyncRun->error) <span class="at-dot bad"></span>Last check failed
        @else <span class="at-dot ok"></span>Healthy @endif
      </div>
      <div class="d">Checks your connected distributors nightly at 5:00 am</div>
    </div>
    <div class="at-sync-stat">
      <div class="k">Last checked</div>
      <div class="v">{{ $lastSyncRun ? tlocal($lastSyncRun->started_at, 'M j, g:i a') : '—' }}</div>
      <div class="d">
        @if($lastSyncRun && $lastSyncRun->error)
          <span style="color:#f0a3a3">{{ \Illuminate\Support\Str::limit($lastSyncRun->error, 60) }}</span>
        @elseif(!empty($srStats))
          {{ number_format($srStats['linked'] ?? 0) }} items ·
          {{ number_format($srStats['cost_updated'] ?? 0) }} costs updated{{ !empty($lastSyncRun->dry_run) ? ' · preview only' : '' }}
        @else
          run a check to see where things stand
        @endif
      </div>
    </div>
    <div class="at-sync-stat">
      <div class="k">Needs review</div>
      <div class="v" style="color:{{ ($counts['total'] ?? 0) > 0 ? 'var(--ia-accent)' : 'inherit' }}">
        {{ ($counts['total'] ?? 0) === 1 ? '1 item' : ($counts['total'] ?? 0) . ' items' }}
      </div>
      <div class="d">{{ ($counts['total'] ?? 0) > 0 ? 'changes waiting for your call below' : 'nothing waiting on you' }}</div>
    </div>
    <div class="at-sync-act">
      <form method="POST" action="{{ route('tenant.distributors.attention.sync') }}">@csrf
        <button class="ia-btn ia-btn--primary" style="width:100%">Check for changes now</button>
      </form>
      <form method="POST" action="{{ route('tenant.distributors.attention.sync') }}">@csrf
        <input type="hidden" name="mode" value="dry">
        <button class="ia-btn ia-btn--ghost" style="width:100%">Preview only (saves nothing)</button>
      </form>
    </div>
  </div>

  {{-- MARKER-ATTENTION-TILES — the counts are the filter. Reading a tile then
       hunting for the same value in a dropdown was four steps to act on a
       number already on the screen. Brand, category and stock are carried
       through: a tile narrows by reason, it does not quietly drop the rest. --}}
  @php
    $tileHref = function (?string $reason) use ($filters, $stock) {
        // MARKER-ATTENTION-TILES-FIX — the names the PAGE reads. f_brand and
        // friends are the hidden inputs on the bulk-action POST form, and
        // nothing reads them from the query string, so tiles built with those
        // reloaded the page unchanged.
        return route('tenant.distributors.attention', array_filter([
            'brand'    => $filters['brand'] ?? null,
            'category' => $filters['category'] ?? null,
            'reason'   => $reason,
            'stock'    => $stock !== 'all' ? $stock : null,
        ]));
    };
    $tileOn = $filters['reason'] ?? null;
  @endphp

  <div class="at-chips">
    <a class="at-chip {{ $tileOn ? '' : 'on' }}" href="{{ $tileHref(null) }}">
      <div class="v">{{ $counts['total'] }}</div><div class="k">Open</div></a>

    <a class="at-chip {{ $tileOn === 'title_changed' ? 'on' : '' }}" href="{{ $tileHref('title_changed') }}">
      <div class="v" style="color:#cde98a">{{ $counts['title'] ?? 0 }}</div><div class="k">Titles</div></a>

    <a class="at-chip {{ $tileOn === 'details_changed' ? 'on' : '' }}" href="{{ $tileHref('details_changed') }}">
      <div class="v" style="color:#9fd0e8">{{ $counts['details'] ?? 0 }}</div><div class="k">Details</div></a>

    <a class="at-chip {{ $tileOn === 'below_map' ? 'on' : '' }}" href="{{ $tileHref('below_map') }}">
      <div class="v" style="color:#f0a3a3">{{ $counts['below_map'] }}</div><div class="k">Below MAP</div></a>

    <a class="at-chip {{ $tileOn === 'off_msrp' ? 'on' : '' }}" href="{{ $tileHref('off_msrp') }}">
      <div class="v" style="color:#f0c78a">{{ $counts['off_msrp'] }}</div><div class="k">Off MSRP</div></a>

    {{-- the dropdown calls this one "Cost removed"; same flag, one name. --}}
    <a class="at-chip {{ $tileOn === 'cost_vanished' ? 'on' : '' }}" href="{{ $tileHref('cost_vanished') }}">
      <div class="v" style="color:#aebbcf">{{ $counts['vanished'] }}</div><div class="k">Cost removed</div></a>
  </div>

  <form method="GET" action="{{ route('tenant.distributors.attention') }}" class="at-filter">
    @if($stock !== 'all')<input type="hidden" name="stock" value="{{ $stock }}">@endif
    <select name="brand" class="at-sel">
      <option value="">All brands</option>
      @foreach(($brandOptions ?? []) as $b)<option value="{{ $b }}" @selected(($filters['brand'] ?? null)===$b)>{{ $b }}</option>@endforeach
    </select>
    <select name="category" class="at-sel">
      <option value="">All categories</option>
      @foreach(($categoryOptions ?? []) as $c)<option value="{{ $c }}" @selected(($filters['category'] ?? null)===$c)>{{ $c }}</option>@endforeach
    </select>
    <select name="reason" class="at-sel">
      <option value="">All reasons</option>
      <option value="title_changed" @selected(($filters['reason'] ?? null)==='title_changed')>Title changed</option>
      <option value="details_changed" @selected(($filters['reason'] ?? null)==='details_changed')>Details updated</option>
      <option value="below_map" @selected(($filters['reason'] ?? null)==='below_map')>Below MAP</option>
      <option value="off_msrp" @selected(($filters['reason'] ?? null)==='off_msrp')>Off MSRP</option>
      <option value="cost_vanished" @selected(($filters['reason'] ?? null)==='cost_vanished')>Cost removed</option>
      <option value="map_vanished" @selected(($filters['reason'] ?? null)==='map_vanished')>MAP removed</option>
      <option value="msrp_vanished" @selected(($filters['reason'] ?? null)==='msrp_vanished')>MSRP removed</option>
    </select>
    <select name="per" class="at-sel" title="Rows per page"> {{-- MARKER-ATTENTION-SCALE --}}
      @foreach([50,100,250] as $pp)
        <option value="{{ $pp }}" @selected(($perPage ?? 100) === $pp)>{{ $pp }} / page</option>
      @endforeach
    </select>
    <button class="at-btn primary" type="submit">Filter</button>
    @if(($filters['brand'] ?? null) || ($filters['category'] ?? null) || ($filters['reason'] ?? null))
      <a class="at-btn" href="{{ route('tenant.distributors.attention', $stock !== 'all' ? ['stock' => $stock] : []) }}">Clear</a>
    @endif

    {{-- MARKER-ATTENTION-FILTERROW — the stock toggle lives inside the filter
         form now, so it can share the button's line on a phone. It is links,
         not inputs: nothing about what the form submits changes. --}}
    @php
    $segLink = fn ($s) => route('tenant.distributors.attention', array_filter([
        'stock'    => $s === 'all' ? null : $s,
        'brand'    => $filters['brand'] ?? null,
        'category' => $filters['category'] ?? null,
        'reason'   => $filters['reason'] ?? null,
    ]));
    @endphp
    <div class="at-seg">
    <a class="at-segbtn {{ $stock === 'all' ? 'active' : '' }}" href="{{ $segLink('all') }}">All ({{ $counts['total'] }})</a>
    <a class="at-segbtn {{ $stock === 'in' ? 'active' : '' }}" href="{{ $segLink('in') }}">In stock ({{ $counts['in'] ?? 0 }})</a>
    <a class="at-segbtn {{ $stock === 'out' ? 'active' : '' }}" href="{{ $segLink('out') }}">Out of stock ({{ $counts['out'] ?? 0 }})</a>
  </div>
  </form>



  {{-- MARKER-TITLE-RATIO -- legend: name edits below the threshold never
       reach this page; the stored baseline adopts them silently. --}}
  <div class="at-dim" style="font-size:11.5px;margin:6px 2px 10px">
    Name changes under {{ $titleThresholdPct ?? 15 }}% are treated as cosmetic feed edits and adopted silently &mdash; they never appear here and never touch your item names. Counts above cover all open flags; the table shows {{ $flags->count() }} of {{ $flags->total() }} matching the filter.
  </div>

  @if($flags->isEmpty())
    <div class="at-card"><div class="at-empty"><div class="big">✓</div>All clear — no pricing attention needed right now.</div></div>
  @else
    <form method="POST" action="{{ route('tenant.distributors.attention.resolve') }}">
      @csrf
      <input type="hidden" name="action" id="at-action" value="">
      <input type="hidden" name="f_brand" value="{{ $filters['brand'] ?? '' }}">
      <input type="hidden" name="f_category" value="{{ $filters['category'] ?? '' }}">
      <input type="hidden" name="f_reason" value="{{ $filters['reason'] ?? '' }}">
      <input type="hidden" name="f_stock" value="{{ $stock }}">
      <script>function setAct(a){document.getElementById('at-action').value=a;}</script>
      {{-- MARKER-ATTENTION-TIGHT — with 100 cards a page, the pager at the
           bottom is a very long scroll away on a phone. --}}
      <div class="at-pager-top">{{ $flags->links() }}</div>
      <table class="at-tbl">
          <thead><tr>
            {{-- MARKER-ATTENTION-SELECT — this takes the rows on THIS PAGE.
                 Everything matching the filter is a separate, stated choice. --}}
            <th style="width:28px"><input type="checkbox" id="at-all-page" onclick="atSelectPage(this.checked)"></th>
            <th style="width:24%">Item</th><th style="width:13%">What happened</th><th>The change</th><th style="text-align:right">Act on it</th>
          </tr></thead>
          <tbody>
          {{-- MARKER-PATCH-558 — every row states the change itself --}}
          @foreach($flags as $f)
            @php
              [$bc, $bl] = $badge($f->reason);
              $item = $f->item; $d = $f->detail ?? []; $cat = $item?->distributorCatalog;
              // MARKER-ATTENTION-PER-DIST — which distributor raised this one.
              $flagDist = $cat?->distributor_code;
              $sell = $item->shop_sell_price_cents ?? null;
            @endphp
            <tr>
              {{-- MARKER-ATTENTION-ACTIONS — the reason travels with the row so
                   the bar can offer only what applies. --}}
              <td class="at-c-pick"><input class="at-cb" type="checkbox" name="flag_ids[]"
                  value="{{ $f->id }}" data-reason="{{ $f->reason }}"></td>
              <td class="at-c-item">
                <div style="display:flex;align-items:center;gap:7px">
                  <div style="font-weight:600">{{ $item->name ?? '—' }}</div>
                  {{-- MARKER-ATTENTION-ITEM-INFO — deciding on a rename needs to
                       see the item. Button, not a link: this row sits inside the
                       bulk-action form and a stray submit would act on other
                       flags. --}}
                  @if($item)
                    <button type="button" title="Item details"
                            onclick="event.stopPropagation(); window.IntakeItemModal.open('{{ $item->id }}')"
                            style="flex:none;width:18px;height:18px;border-radius:50%;border:1px solid var(--ia-border);background:none;color:var(--ia-text-dim);font-size:11px;font-weight:700;line-height:1;cursor:pointer;padding:0">i</button>
                  @endif
                </div>
                {{-- MARKER-BLADE-WORD-BOUNDARY — the directive must not touch the
                     word before it. Blade's statement regex starts with \B@, so
                     a directive glued to the preceding word is left as literal
                     text while the matching
                     @endif compiled, giving an endif with no if and a fatal
                     compile error for the whole view. --}}
                <div class="at-dim at-mono" style="font-size:11px">
                  {{ $item->sku ?? '' }} · {{ $item->computed_stock_count ?? 0 }} in stock
                  @if($flagDist)
                    · <span style="font-weight:700">{{ $flagDist }}</span>
                  @endif
                </div>
              </td>
              <td class="at-c-why" data-l="What happened"><span class="at-badge {{ $bc }}">{{ $bl }}</span></td>
              <td class="at-chg at-c-chg" data-l="The change">
                @if($f->reason === 'title_changed')
                  <span class="old">{{ $d['old'] ?? $item->name }}</span> →<br>
                  {!! $wordDiff($d['old'] ?? $item->name, $d['new'] ?? $cat?->display_name) !!}
                  <div class="when">your item still uses the name on the left @if(isset($d['change_ratio']))&middot; {{ $d['change_ratio'] }}% changed @endif</div>
                @elseif($f->reason === 'details_changed')
                  @foreach(($d['changed'] ?? []) as $fld => $chg)
                    <div><b style="text-transform:capitalize">{{ $fld }}</b>:
                      <span class="old">{{ blank($chg['old'] ?? null) ? '—' : \Illuminate\Support\Str::limit($chg['old'], 60) }}</span> →
                      {{ blank($chg['new'] ?? null) ? '—' : \Illuminate\Support\Str::limit($chg['new'], 60) }}</div>
                  @endforeach
                  <div class="when">your item still has the values on the left</div>
                @elseif($f->reason === 'below_map')
                  Your price <span class="nb">{{ $fmt($d['sell_cents'] ?? $sell) }}</span> is
                  <span class="nb">{{ $fmt($d['delta_cents'] ?? null) }} under</span> the
                  {{ $fmt($d['map_cents'] ?? $item->catalog_map_cents) }} minimum advertised price.
                  @if(($d['prev_map_cents'] ?? null) && ($d['prev_map_cents'] !== ($d['map_cents'] ?? null)))
                    <div class="when">MAP moved from {{ $fmt($d['prev_map_cents']) }} in a recent catalog</div>
                  @endif
                @elseif($f->reason === 'off_msrp')
                  @if(($d['prev_msrp_cents'] ?? null) && ($d['prev_msrp_cents'] !== ($d['msrp_cents'] ?? null)))
                    MSRP moved <span class="old">{{ $fmt($d['prev_msrp_cents']) }}</span> →
                    <b>{{ $fmt($d['msrp_cents'] ?? $item->catalog_msrp_cents) }}</b>; your price is still <b>{{ $fmt($d['sell_cents'] ?? $sell) }}</b>
                  @else
                    Your <b>{{ $fmt($d['sell_cents'] ?? $sell) }}</b> is
                    <b>{{ $d['pct_under'] ?? '?' }}% under</b> the {{ $fmt($d['msrp_cents'] ?? $item->catalog_msrp_cents) }} MSRP
                  @endif
                  <div class="when">you may be leaving margin on the table</div>
                @elseif(str_ends_with($f->reason, '_vanished'))
                  @php $what = ['cost_vanished' => 'a dealer cost', 'map_vanished' => 'a MAP', 'msrp_vanished' => 'an MSRP'][$f->reason] ?? 'this data'; @endphp
                  {{ $cat?->distributor_code ?: 'The distributor' }} no longer publishes {{ $what }} for this item
                  @if($d['prev_cost_cents'] ?? $d['prev_map_cents'] ?? $d['prev_msrp_cents'] ?? null)
                    — it was <b>{{ $fmt($d['prev_cost_cents'] ?? $d['prev_map_cents'] ?? $d['prev_msrp_cents']) }}</b>
                  @endif.
                  <div class="when">often means discontinued or a supplier change</div>
                @else
                  <span class="at-dim">flag opened {{ tlocal($f->created_at, 'M j') }}</span>
                @endif
              </td>
              <td>
                <div class="at-rowact">
                  @if($f->reason === 'title_changed')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('adopt_title')">Use new name</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('keep_title')">Keep mine</button>
                  @elseif($f->reason === 'details_changed')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('adopt_details')">Use new details</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('keep_details')">Keep mine</button>
                  @elseif($f->reason === 'below_map')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('raise_map')">Raise to {{ $fmt($d['map_cents'] ?? $item->catalog_map_cents) }}</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('acknowledge')">Dismiss</button>
                  @elseif($f->reason === 'off_msrp')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('match_msrp')">Match {{ $fmt($d['msrp_cents'] ?? $item->catalog_msrp_cents) }}</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('acknowledge')">Dismiss</button>
                  @else
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('acknowledge')">Dismiss</button>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
      </table>

      {{-- MARKER-ATTENTION-SELECT — the escalation, stated rather than ticked. --}}
      <div id="at-scope" class="at-scope" hidden>
        <span id="at-scope-text"></span>
        <a href="#" id="at-scope-link" onclick="return atScope(true)"></a>
      </div>

      <div class="at-bar" id="at-bar">
        <span class="at-dim" style="font-size:12px" id="at-bar-label">With selected:</span>
        <button class="at-btn primary" type="submit" data-applies="title_changed" onclick="setAct('adopt_title')">Adopt new title</button>
        <button class="at-btn" type="submit" data-applies="title_changed" onclick="setAct('keep_title')">Keep mine</button>
        <button class="at-btn primary" type="submit" data-applies="details_changed" onclick="setAct('adopt_details')">Adopt new details</button>
        <button class="at-btn" type="submit" data-applies="details_changed" onclick="setAct('keep_details')">Keep my details</button>
        <span class="at-dim" style="opacity:.4">|</span>
        <button class="at-btn primary" type="submit" data-applies="price" onclick="setAct('raise_map')">Raise to MAP</button>
        <button class="at-btn" type="submit" data-applies="price" onclick="setAct('match_msrp')">Match MSRP</button>
        <button class="at-btn" type="submit" data-applies="any" onclick="setAct('acknowledge')">Dismiss</button>
        {{-- MARKER-ATTENTION-SELECT — no longer a tick beside the buttons: the
             scope is chosen above and shown in the label to the left. --}}
        <input type="checkbox" name="select_all" value="1" id="at-select-all" hidden>
      </div>
    </form>
    <div style="margin-top:14px" class="at-pager-bottom">{{ $flags->links() }}</div> {{-- MARKER-ATTENTION-SCALE --}}
  @endif
</div>
{{-- MARKER-ATTENTION-ITEM-INFO — the same modal the register uses. --}}
@include('tenant._item-detail-modal')

@push('styles')
<style>
  /* MARKER-ATTENTION-SELECT */
  .at-scope{background:rgba(224,166,75,.10);border:.5px solid rgba(224,166,75,.4);
    border-radius:var(--ia-r-md);padding:10px 13px;margin-bottom:10px;font-size:12.5px;line-height:1.6}
  .at-scope.armed{background:rgba(240,138,138,.10);border-color:rgba(240,138,138,.45)}
  .at-scope a{color:var(--ia-accent);font-weight:600;text-decoration:underline;margin-left:6px}
  @media(max-width:720px){
    .at-bar{flex-wrap:wrap}
    .at-bar .at-btn{flex:1 1 46%}
  }
</style>
@endpush

@push('scripts')
<script>
  // MARKER-ATTENTION-SELECT — two scopes, and the page always says which is in
  // force. "This page" is what a checkbox can honestly mean when the rest are
  // not loaded; everything matching is a deliberate second step.
  (function () {
    var TOTAL = {{ (int) ($flags->total() ?? 0) }};
    var PER   = {{ (int) ($flags->count() ?? 0) }};

    window.atSelectPage = function (checked) {
      document.querySelectorAll('.at-cb').forEach(function (c) { c.checked = checked; });
      if (!checked) { atScope(false); }
      atRender();
    };

    window.atScope = function (all) {
      var box = document.getElementById('at-select-all');
      if (box) { box.checked = !!all; }
      if (all) {
        document.querySelectorAll('.at-cb').forEach(function (c) { c.checked = true; });
        var head = document.getElementById('at-all-page');
        if (head) { head.checked = true; }
      }
      atRender();
      return false;
    };

    function atRender() {
      var all     = (document.getElementById('at-select-all') || {}).checked;
      var picked  = document.querySelectorAll('.at-cb:checked').length;
      var scope   = document.getElementById('at-scope');
      var text    = document.getElementById('at-scope-text');
      var link    = document.getElementById('at-scope-link');
      var label   = document.getElementById('at-bar-label');
      if (!scope) { return; }

      if (all) {
        scope.hidden = false;
        scope.classList.add('armed');
        text.textContent = 'All ' + TOTAL.toLocaleString() + ' items matching this filter are selected — not just this page.';
        link.textContent = 'Select only this page instead';
        link.onclick = function () { return atScope(false); };
        if (label) { label.textContent = 'With all ' + TOTAL.toLocaleString() + ':'; }
        return;
      }

      scope.classList.remove('armed');
      if (picked >= PER && TOTAL > PER) {
        scope.hidden = false;
        text.textContent = 'All ' + picked.toLocaleString() + ' on this page are selected.';
        link.textContent = 'Select all ' + TOTAL.toLocaleString() + ' matching this filter';
        link.onclick = function () { return atScope(true); };
      } else {
        scope.hidden = true;
      }
      if (label) {
        label.textContent = picked ? 'With ' + picked.toLocaleString() + ' selected:' : 'With selected:';
      }

      // MARKER-ATTENTION-SLIM — the bar only exists when it has something to
      // act on, so it never sits on top of the row being read.
      var bar = document.getElementById('at-bar');
      if (bar) {
        var show = !!(all || picked);
        bar.hidden = !show;

        // MARKER-ATTENTION-ACTIONS — two fixed bars at the bottom of a phone
        // leaves nothing to read. Same hook the Communication Center uses.
        document.body.classList.toggle('at-bar-open', show);

        atActions(all, picked);
      }
    }

    document.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('at-cb')) {
        var box = document.getElementById('at-select-all');
        // Unticking any row means this is no longer "everything".
        if (box && box.checked && !e.target.checked) { box.checked = false; }
        atRender();
      }
    });

    // MARKER-ATTENTION-ACTIONS — show only the buttons the selection can use,
    // with the number each will affect. Offering "Raise to MAP" for a rename
    // makes the bar a liar: the controller skips it and says nothing.
    function atActions(all, picked) {
      var counts = { title_changed: 0, details_changed: 0, price: 0, any: 0 };

      if (all) {
        // Everything matching the filter: we cannot count what is not loaded,
        // so every action stays available and the count is left off.
        counts = null;
      } else {
        document.querySelectorAll('.at-cb:checked').forEach(function (c) {
          var r = c.getAttribute('data-reason') || '';
          if (r === 'title_changed' || r === 'details_changed') {
            counts[r]++;
          } else {
            counts.price++;   // below_map, off_msrp, vanished — price-side flags
          }
          counts.any++;
        });
      }

      document.querySelectorAll('.at-bar .at-btn').forEach(function (btn) {
        var applies = btn.getAttribute('data-applies');
        if (!applies) { return; }

        if (counts === null) {
          btn.hidden = false;
          btn.textContent = btn.getAttribute('data-label') || btn.textContent;
          return;
        }

        var n = counts[applies] || 0;
        btn.hidden = n === 0;

        if (!btn.getAttribute('data-label')) {
          btn.setAttribute('data-label', btn.textContent.trim());
        }
        btn.textContent = btn.getAttribute('data-label') + ' (' + n.toLocaleString() + ')';
      });
    }

    document.addEventListener('DOMContentLoaded', atRender);
  })();

  // The bulk buttons confirm with the real number and scope.
  window.atConfirm = function (action, label) {
    var all    = (document.getElementById('at-select-all') || {}).checked;
    var picked = document.querySelectorAll('.at-cb:checked').length;
    var total  = {{ (int) ($flags->total() ?? 0) }};
    var n      = all ? total : picked;

    if (!n) {
      IntakeConfirm.alert({ title: 'Nothing selected', message: 'Tick some rows first.' });
      return false;
    }

    return IntakeConfirm.show({
      title: label + ' for ' + n.toLocaleString() + ' item' + (n === 1 ? '' : 's') + '?',
      message: all
        ? 'This applies to every item matching the current filter, not just this page. You can put it back from Change history.'
        : 'You can put it back from Change history.',
      confirmText: label,
      danger: n > 100
    });
  };
</script>
@endpush

@endsection
