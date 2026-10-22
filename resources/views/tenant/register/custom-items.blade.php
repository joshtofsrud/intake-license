@extends('layouts.tenant.app')
@php $pageTitle = 'Custom items'; @endphp
{{-- MARKER-CUSTOM-ITEMS: Register > Custom items --}}
@push('styles')
<style>

  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* ----------------------------------------------- */
  /* display:contents keeps the links as direct flex children of the bar on
     desktop, so nothing about the existing layout changes. */
  .reg-tabs-scroll{display:contents}

  @media (max-width: 760px){
    .ia-page-subtitle{display:none}

    .reg-tabs-bar{display:block;flex-wrap:nowrap}
    .reg-tabs-scroll{
      display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;
      -webkit-overflow-scrolling:touch
    }
    .reg-tabs-scroll::-webkit-scrollbar{display:none}
    .reg-tab-link{white-space:nowrap;flex:0 0 auto;padding:10px 14px}
  }

  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .h-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .h-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}

  .h-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-bottom:14px;flex-wrap:wrap
  }
  .h-search{
    flex:1;min-width:220px;max-width:380px;padding:9px 12px;
    background:var(--ia-input-bg);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;
    font-family:inherit
  }
  .h-search:focus{outline:none;border-color:var(--ia-accent)}
  .h-count{font-size:13px;color:var(--ia-text-dim)}



  .h-filters{
    display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px
  }
  .h-chip{
    padding:6px 12px;background:transparent;
    border:0.5px solid var(--ia-border);border-radius:99px;
    color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer;
    transition:all var(--ia-t)
  }
  .h-chip:hover{color:var(--ia-text);border-color:var(--ia-border-strong)}
  .h-chip.active{
    background:var(--ia-accent);color:var(--ia-accent-text);
    border-color:var(--ia-accent);font-weight:500
  }

  .h-table-wrap{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);overflow:hidden
  }
  .h-table{width:100%;border-collapse:collapse}
  .h-table thead th{
    text-align:left;padding:12px 14px;font-size:11px;font-weight:600;
    color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;
    background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border);
    user-select:none;white-space:nowrap
  }
  .h-table thead th.sortable{cursor:pointer}
  .h-table thead th.sortable:hover{color:var(--ia-text)}
  .h-table thead th .sort-arrow{
    display:inline-block;margin-left:4px;font-size:10px;
    color:var(--ia-text-muted);opacity:.5
  }
  .h-table thead th.sort-active .sort-arrow{
    color:var(--ia-accent);opacity:1
  }
  .h-table tbody td{
    padding:12px 14px;font-size:13px;color:var(--ia-text);
    border-bottom:0.5px solid var(--ia-border);vertical-align:middle
  }
  .h-table tbody tr:last-child td{border-bottom:none}
  .h-table tbody tr{cursor:pointer}
  .h-table tbody tr:hover{background:var(--ia-hover)}

  .h-sale-num{font-family:'SF Mono',Menlo,monospace;font-size:12px}
  .h-sale-num.muted{color:var(--ia-text-dim);font-style:italic}
  .h-status{
    display:inline-block;padding:2px 8px;border-radius:99px;
    font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:600
  }
  .h-status.draft{background:rgba(255,255,255,.06);color:var(--ia-text-dim)}
  .h-status.quote{background:rgba(190,242,100,.15);color:var(--ia-accent)}
  .h-status.unpaid{background:rgba(255,180,80,.15);color:#FFB450}
  .h-status.paid{background:rgba(120,200,120,.15);color:#78c878}
  .h-status.partial{background:rgba(255,180,80,.15);color:#FFB450}
  .h-status.refunded{background:rgba(240,149,149,.15);color:#F09595}

  .h-total{font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
  .h-total.refund{color:#F09595}
  .h-tx-group{font-family:'SF Mono',Menlo,monospace;font-size:11px;color:var(--ia-text-dim)}
  .h-tx-group.linked{color:var(--ia-accent)}
  .h-meta{color:var(--ia-text-dim);font-size:12px}

  .h-empty-search{
    padding:40px 20px;text-align:center;color:var(--ia-text-dim);font-size:13px
  }
  /* ---------------------------------------------- */
  /* this block MUST stay last in the stylesheet:
     media queries add no specificity, so it has to out-order the base
     .h-table tbody td rules above, and match their specificity. */
  .h-sortbar{display:none}
  .h-more{display:none;margin:14px 0 4px;width:100%;padding:12px;
    background:transparent;border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;
    font-family:inherit;cursor:pointer}
  .h-more:hover{background:rgba(127,127,127,.06)}
  .h-more.on{display:block}

  @media (max-width: 760px){
    /* count above a full-width search rather than squeezed beside it */
    .h-toolbar{flex-wrap:wrap;gap:8px}
    .h-count{flex:1 1 100%;order:-1}
    .h-search{flex:1 1 100%;max-width:none;min-width:0}

    /* sort lives in the header row on desktop; the cards have no header */
    .h-sortbar{display:flex;gap:8px;align-items:center;margin:0 0 12px}
    .h-sortbar select{flex:1;min-width:0;padding:9px 12px;font-size:13px;
      font-family:inherit;color:var(--ia-text);background:var(--ia-input-bg);
      border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md)}

    /* table -> ledger rows. No labels: a 3x2 grid over
       the same <td>s, with status carried by a stripe on the row. Desktop
       markup, data attributes and the sort JS are all untouched. */
    .h-table-wrap{overflow:visible}
    .h-table, .h-table tbody{display:block;width:auto}
    .h-table thead{display:none}

    .h-table tbody tr:last-child td{border-bottom:0}
    .h-table tbody tr{
      /* 3 columns so sale # and items share row 2
         instead of stacking into a third line. */
      display:grid;grid-template-columns:auto 1fr auto;
      column-gap:8px;row-gap:2px;align-items:baseline;
      padding:9px 2px 9px 11px;
      border-bottom:.5px solid var(--ia-border);
      border-left:3px solid rgba(255,255,255,.10)
    }
    /* status, as a stripe — same palette as the chips it replaces */
    .h-table tbody tr[data-status="paid"]{border-left-color:#78c878}
    .h-table tbody tr[data-status="partial"],
    .h-table tbody tr[data-status="unpaid"]{border-left-color:#FFB450}
    .h-table tbody tr[data-status="refunded"]{border-left-color:#F09595}
    .h-table tbody tr[data-status="quote"]{border-left-color:var(--ia-accent)}

    .h-table tbody td{display:block;padding:0;border:0;text-align:left;min-width:0}
    .h-table tbody td::before{content:none}          /* labels off */

    .h-table tbody td[data-label="Customer"]{
      grid-column:1 / 3;grid-row:1;font-size:13.5px;font-weight:600;
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap
    }
    .h-table tbody td[data-label="Total"]{
      grid-column:3;grid-row:1;text-align:right;
      font-size:14.5px;font-weight:650;letter-spacing:-.01em
    }
    .h-table tbody td[data-label="Sale #"]{
      grid-column:1;grid-row:2;font-size:11.5px;color:var(--ia-text-dim);
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap
    }
    .h-table tbody td[data-label="Date"]{
      grid-column:3;grid-row:2;text-align:right;font-size:11px;
      color:var(--ia-text-dim)
    }
    .h-table tbody td[data-label="Items"]{
      grid-column:2;grid-row:2;font-size:11.5px;color:var(--ia-text-dim);
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap
    }

    /* location sits inline after the item count rather than on its own line */
    .h-table tbody td[data-label="Items"] .h-meta{display:inline;font-size:11.5px}
    .h-table tbody td[data-label="Items"] .h-meta::before{content:" \00b7 "}
    /* items now sits beside the sale number, so it needs its own leading dot
       (the blanket td::before above turns labels off). */
    .h-table tbody td[data-label="Items"]::before{content:"\00b7 ";color:var(--ia-text-dim)}

    /* carried elsewhere on this row, or too long to scan */
    .h-table tbody td[data-label="Status"],
    .h-table tbody td[data-label="Tx group"],
    .h-table tbody td[data-label="Staff"],
    .h-table tbody td[data-label="Customer"] .h-meta{display:none}

    .h-table tbody td.h-empty-search{grid-column:1 / -1;text-align:center}
  }

.ci-legend{margin-top:22px;font-size:12.5px;line-height:1.65;color:var(--ia-text-dim);max-width:900px;border-top:.5px solid var(--ia-border);padding-top:12px}
.ci-legend b{color:var(--ia-text);font-weight:500}
.ci-tabn{display:inline-block;min-width:18px;padding:0 6px;margin-left:6px;border-radius:999px;background:rgba(224,168,78,.18);color:#E0A84E;font-size:11px;font-weight:600;line-height:18px;text-align:center}
.ci-bar{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
/* MARKER-CI-SEARCH */
.ci-search{display:flex;align-items:center;gap:6px;flex:1 1 220px;max-width:340px}
.ci-search input{flex:1;min-width:0;padding:7px 12px;background:var(--ia-input-bg);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px}
.ci-search input:focus{outline:none;border-color:var(--ia-accent)}
.ci-search a{font-size:12.5px;color:var(--ia-text-dim);text-decoration:none}
@media (max-width:640px){.ci-search{max-width:none;flex-basis:100%}}
.h-chip b{font-weight:600;margin-left:4px;opacity:.8}
.ci-hint{font-size:12.5px;color:var(--ia-text-dim);margin:0 0 12px}
.ci-table td{vertical-align:middle}
.ci-name b{font-weight:500;color:var(--ia-text)}
.ci-name small{display:block;color:var(--ia-text-dim);font-size:11.5px;margin-top:2px}
.ci-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:8px 0;font-size:12.5px;color:var(--ia-text-dim)}
.ci-pager nav{display:flex;align-items:center;gap:6px}
.ci-pager nav a,.ci-pager nav span{padding:4px 10px;border-radius:999px;border:.5px solid var(--ia-border);color:var(--ia-text);text-decoration:none}
.ci-pager nav span{opacity:.4}
.ci-times{display:inline-block;min-width:26px;padding:2px 8px;border-radius:999px;background:var(--ia-hover);font-weight:600;font-size:12px}
.ci-times.hot{background:rgba(224,168,78,.16);color:#E0A84E}
.ci-sales a{font-family:var(--ia-font-mono);font-size:11.5px;color:var(--ia-text-dim);margin-right:6px;text-decoration:none}
.ci-sales a:hover{color:var(--ia-text)}
.ci-acts{white-space:nowrap;text-align:right}
.ci-acts .ia-btn{margin-left:6px}
.ci-done{color:var(--ia-text-dim);font-size:12.5px}
.ci-shade{position:fixed;inset:0;background:rgba(0,0,0,.45);opacity:0;pointer-events:none;transition:.2s;z-index:40}
.ci-shade.on{opacity:1;pointer-events:auto}
.ci-drawer{position:fixed;top:0;right:0;bottom:0;width:420px;max-width:100%;background:var(--ia-surface);border-left:.5px solid var(--ia-border);transform:translateX(100%);transition:.22s;z-index:41;display:flex;flex-direction:column;padding:20px 22px 0;overflow:auto}
.ci-drawer.on{transform:none}
.ci-dh{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:14px}
.ci-dt{font-size:17px;font-weight:600}
.ci-ds{font-size:12.5px;color:var(--ia-text-dim);margin-top:3px}
.ci-x{background:none;border:0;color:var(--ia-text-dim);font-size:22px;cursor:pointer}
.ci-seg{display:flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:18px}
.ci-seg button{flex:1;background:none;border:0;padding:8px;font:inherit;font-size:13px;color:var(--ia-text-dim);cursor:pointer}
.ci-seg button.on{background:var(--ia-accent-soft);color:var(--ia-text);font-weight:500}
.ci-row{display:flex;gap:8px}
.ci-sku{font-size:12px;margin-top:6px;min-height:16px}
.ci-sku.ok{color:#7BC67E}.ci-sku.bad{color:#E24B4A}
.ci-note{font-size:12.5px;color:var(--ia-text-dim);line-height:1.55;background:var(--ia-hover);border-radius:var(--ia-r-md);padding:10px 12px}
.ci-results label{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:.5px solid var(--ia-border);cursor:pointer;font-size:13.5px}
.ci-results small{display:block;color:var(--ia-text-dim);font-size:12px;margin-top:2px}
.ci-chk{display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-top:14px;color:var(--ia-text)}
.ci-foot{position:sticky;bottom:0;margin-top:auto;padding:14px 0 18px;background:var(--ia-surface);display:flex;gap:8px;border-top:.5px solid var(--ia-border)}
@media (max-width:760px){.ci-acts .ia-btn--ghost{display:none}.ci-table th:nth-child(5),.ci-table td:nth-child(5){display:none}}

  .ci-err{color:#E24B4A;font-size:12.5px;margin-top:4px}
</style>
@endpush

@section('content')
@php
  $money = fn ($c) => '$' . number_format(((int) $c) / 100, 2);
  $reopenKey = old('custom_item_key');
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Custom items</h1>
    <p class="ia-page-subtitle">Things rung up by hand. Add them to inventory so next time they scan.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.custom-items') }}" class="reg-tab-link active">Custom items @if($counts['open'] > 0)<span class="ci-tabn">{{ $counts['open'] }}</span>@endif</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.layaways.index') }}" class="reg-tab-link">Layaways</a>
  <a href="{{ route('tenant.register.reconciliation') }}" class="reg-tab-link">Reconciliation</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a>
  </div>
</div>

<div class="ci-bar">
  <div class="h-filters" style="margin:0">
    @foreach(['open' => 'To sort', 'added' => 'Added', 'matched' => 'Matched', 'skipped' => 'Not inventory'] as $k => $label)
      <a href="{{ route('tenant.register.custom-items', array_filter(['show' => $k, 'range' => $range, 'q' => $q])) }}" class="h-chip {{ $filter === $k ? 'active' : '' }}" style="text-decoration:none">{{ $label }} @if($tabCounts[$k] > 0)<b>{{ $tabCounts[$k] }}</b>@endif</a>
    @endforeach
  </div>
  <form method="GET" action="{{ route('tenant.register.custom-items') }}" class="ci-search" role="search">
    <input type="hidden" name="show" value="{{ $filter }}"><input type="hidden" name="range" value="{{ $range }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="Search name or sale #" autocomplete="off" aria-label="Search custom items">
    @if($q !== '')<a href="{{ route('tenant.register.custom-items', ['show' => $filter, 'range' => $range]) }}">Clear</a>@endif
  </form>
  <div class="h-filters" style="margin:0">
    @foreach(['today' => 'Today', 'week' => 'This week', 'all' => 'All'] as $k => $label)
      <a href="{{ route('tenant.register.custom-items', array_filter(['show' => $filter, 'range' => $k, 'q' => $q])) }}" class="h-chip {{ $range === $k ? 'active' : '' }}" style="text-decoration:none">{{ $label }}</a>
    @endforeach
  </div>
</div>
<p class="ci-hint">Same name and price are grouped. Rung up shows how often it happens, a good hint at what to add first.</p>

@if($rows->isEmpty())
  <div class="h-empty">
    @if($q !== '')
      <h3>No custom items match "{{ $q }}"</h3>
      <p style="font-size:13px">Try part of the name or a sale number, or check the other tabs.</p>
    @else
    <h3>{{ $filter === 'open' ? 'Nothing to sort' : 'Nothing here yet' }}</h3>
    <p style="font-size:13px">{{ $filter === 'open' ? 'Everything rung up by hand has been sorted.' : 'Sorted custom items show up here.' }}</p>
    @endif
  </div>
@else
  @if($pager['pages'] > 1)
    @php $pq = fn ($p) => route('tenant.register.custom-items', array_filter(['show' => $filter, 'range' => $range, 'q' => $q, 'page' => $p])); @endphp
    <div class="ci-pager">
      <div>{{ $pager['from'] }}&ndash;{{ $pager['to'] }} of {{ $pager['total'] }}</div>
      <nav>
        @if($pager['page'] > 1)<a href="{{ $pq($pager['page'] - 1) }}">&lsaquo; Prev</a>@else<span>&lsaquo; Prev</span>@endif
        Page {{ $pager['page'] }} of {{ $pager['pages'] }}
        @if($pager['page'] < $pager['pages'])<a href="{{ $pq($pager['page'] + 1) }}">Next &rsaquo;</a>@else<span>Next &rsaquo;</span>@endif
      </nav>
    </div>
  @endif
  <div class="h-table-wrap">
    <table class="h-table ci-table">
      <thead><tr><th>Typed as</th><th style="text-align:right">Price</th><th style="text-align:center">Rung up</th><th>Last</th><th>Sales</th><th></th></tr></thead>
      <tbody>
        @foreach($rows as $g)
          @php
            $r = $g['resolution'];
            $lastAt = \Illuminate\Support\Carbon::parse($g['last_at'])->timezone($currentTenant->timezone());
            $when = $lastAt->isToday() ? $lastAt->format('g:i a') : ($lastAt->isYesterday() ? 'Yesterday' : $lastAt->format('M j'));
          @endphp
          <tr>
            <td data-label="Typed as" class="ci-name"><b>{{ $g['name'] }}</b>
              <small>
                @if($filter === 'open' && $r && in_array($r->status, ['added', 'matched'], true) && $r->item)
                  Rung up by hand again; it's in inventory as {{ $r->item->name }} ({{ $r->item->sku }})
                @elseif($filter !== 'open' && $r && $r->item)
                  {{ $r->status === 'added' ? 'Added as' : 'Matched to' }} {{ $r->item->name }} · {{ $r->item->sku }}
                @elseif($filter !== 'open' && $g['ignored'])
                  Never listed again
                @elseif($lastAt->isToday())
                  Today
                @endif
              </small></td>
            <td data-label="Price" style="text-align:right">{{ $money($g['price_cents']) }}</td>
            <td data-label="Rung up" style="text-align:center"><span class="ci-times {{ $g['times'] > 2 ? 'hot' : '' }}">{{ $filter === 'open' ? $g['open'] : $g['times'] }}</span></td>
            <td data-label="Last" style="color:var(--ia-text-dim);font-size:12.5px">{{ $when }}@if($g['last_staff']) · {{ $g['last_staff'] }}@endif</td>
            <td data-label="Sales" class="ci-sales">@foreach($g['sales'] as $s)<a href="{{ route('tenant.register.sales.receipt', $s['id']) }}" target="_blank" title="{{ $s['number'] }}{{ !empty($s['at']) ? ' · ' . \Illuminate\Support\Carbon::parse($s['at'])->timezone($currentTenant->timezone())->format('M j') : '' }}">{{ $s['number'] ? '#' . \Illuminate\Support\Str::afterLast($s['number'], '-') : 'sale' }}</a>@endforeach</td>
            <td class="ci-acts">
              @if($filter === 'open')
                <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" data-ci-open="add" data-key="{{ $g['key'] }}" data-name="{{ $g['name'] }}" data-price="{{ number_format($g['price_cents'] / 100, 2, '.', '') }}" data-times="{{ $g['open'] }}">Add to inventory</button>
                <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-ci-open="match" data-key="{{ $g['key'] }}" data-name="{{ $g['name'] }}" data-price="{{ number_format($g['price_cents'] / 100, 2, '.', '') }}" data-times="{{ $g['open'] }}">Match</button>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-ci-open="skip" data-key="{{ $g['key'] }}" data-name="{{ $g['name'] }}" data-price="{{ number_format($g['price_cents'] / 100, 2, '.', '') }}" data-times="{{ $g['open'] }}">Not inventory</button>
              @else
                <form method="POST" action="{{ route('tenant.register.custom-items.undo') }}" style="display:inline">
                  @csrf<input type="hidden" name="key" value="{{ $g['key'] }}">
                  <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Move back to To sort</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @if($pager['pages'] > 1)
    @php $pq = fn ($p) => route('tenant.register.custom-items', array_filter(['show' => $filter, 'range' => $range, 'q' => $q, 'page' => $p])); @endphp
    <div class="ci-pager">
      <div>{{ $pager['from'] }}&ndash;{{ $pager['to'] }} of {{ $pager['total'] }}</div>
      <nav>
        @if($pager['page'] > 1)<a href="{{ $pq($pager['page'] - 1) }}">&lsaquo; Prev</a>@else<span>&lsaquo; Prev</span>@endif
        Page {{ $pager['page'] }} of {{ $pager['pages'] }}
        @if($pager['page'] < $pager['pages'])<a href="{{ $pq($pager['page'] + 1) }}">Next &rsaquo;</a>@else<span>Next &rsaquo;</span>@endif
      </nav>
    </div>
  @endif
@endif

<div class="ci-legend"><b>What sorting does.</b> <b>Add to inventory</b> creates the item the same way Inventory › Add item does; past sales stay exactly as they were rung up and nothing comes off stock for them. <b>Match</b> links these lines to an item you already have, for this list only; stock and sales don't change. <b>Not inventory</b> is for labor, shop supplies and one-offs: they leave the list and stop counting on the dashboard. If the same thing is rung up by hand again later, it comes back here. Nothing on this page changes a receipt, a payment or a customer's record.</div>

{{-- drawer --}}
<div class="ci-shade" id="ciShade"></div>
<aside class="ci-drawer" id="ciDrawer" aria-hidden="true">
  <div class="ci-dh"><div><div class="ci-dt" id="ciDt">Add to inventory</div><div class="ci-ds" id="ciDs"></div></div><button class="ci-x" id="ciX" type="button" aria-label="Close">&times;</button></div>
  <div class="ci-seg" id="ciSeg"><button data-m="add" class="on" type="button">New item</button><button data-m="match" type="button">Match existing</button><button data-m="skip" type="button">Not inventory</button></div>

  <form data-pane="add" method="POST" action="{{ route('tenant.inventory.store') }}" id="ciAddForm"
        data-identify-url="{{ route('tenant.inventory.identify') }}" data-identify-mode="create">
    @csrf
    <input type="hidden" name="custom_item_key" id="fKey" value="{{ old('custom_item_key') }}">
    <input type="hidden" name="duplicate_ok" value="">
    <div class="ia-form-group"><label class="ia-form-label">Name <span class="ia-required">*</span></label><input class="ia-input" name="name" id="fName" required maxlength="255" value="{{ old('name') }}"></div>
    <div class="ia-form-group"><label class="ia-form-label">SKU <span class="ia-required">*</span></label>
      <div class="ci-row"><input class="ia-input" name="sku" id="fSku" required maxlength="64" value="{{ old('sku') }}" placeholder="Type one or generate"><button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-sku-generate="{{ route('tenant.inventory.sku.next') }}">Generate</button></div>
    </div>
    <div class="ia-form-group"><label class="ia-form-label">Barcode</label><input class="ia-input ia-scan-field" name="catalog_upc" value="{{ old('catalog_upc') }}" placeholder="Scan it now so it scans at the register" maxlength="32"></div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group"><label class="ia-form-label">Price</label><input class="ia-input" name="shop_sell_price_dollars" id="fPrice" inputmode="decimal" value="{{ old('shop_sell_price_dollars') }}"></div>
      <div class="ia-form-group"><label class="ia-form-label">Cost</label><input class="ia-input" name="shop_cost_dollars" inputmode="decimal" value="{{ old('shop_cost_dollars') }}" placeholder="$"></div>
    </div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group"><label class="ia-form-label">Category <span class="ia-required">*</span></label>
        <select class="ia-input" name="category_id" required>
          <option value="">Choose&hellip;</option>
          @foreach($categories as $id => $label)<option value="{{ $id }}" @selected(old('category_id') === $id)>{{ $label }}</option>@endforeach
        </select></div>
      <div class="ia-form-group"><label class="ia-form-label">Stock on hand now</label><input class="ia-input" name="initial_stock" type="number" min="0" step="1" value="{{ old('initial_stock') }}" placeholder="Count the shelf"></div>
    </div>
    @if(session('barcode_duplicate'))
      @php $bd = session('barcode_duplicate'); @endphp
      <p class="ci-err">Barcode {{ $bd['code'] }} is already on {{ $bd['name'] }}. Use Match instead.</p>
    @endif
    <p class="ci-note" id="fNote"></p>
  </form>

  <form data-pane="match" method="POST" action="{{ route('tenant.register.custom-items.resolve') }}" id="ciMatchForm" hidden>
    @csrf
    <input type="hidden" name="key" class="ciKey"><input type="hidden" name="display_name" class="ciName">
    <input type="hidden" name="status" value="matched"><input type="hidden" name="inventory_item_id" id="mItem">
    <div class="ia-form-group"><label class="ia-form-label">Find the item</label><input class="ia-input" id="mQ" placeholder="Search name, SKU or barcode" autocomplete="off" data-url="{{ route('tenant.register.custom-items.search') }}"></div>
    <div class="ci-results" id="mResults"></div>
    <p class="ci-note">Matching only tidies this list. Stock and past sales don't change.</p>
  </form>

  <form data-pane="skip" method="POST" action="{{ route('tenant.register.custom-items.resolve') }}" id="ciSkipForm" hidden>
    @csrf
    <input type="hidden" name="key" class="ciKey"><input type="hidden" name="display_name" class="ciName">
    <input type="hidden" name="status" value="skipped">
    <div class="ia-form-group"><label class="ia-form-label">What is it?</label>
      <select class="ia-input" name="reason"><option value="labor">Labor or service</option><option value="supplies">Shop supplies</option><option value="one_off">One-off</option><option value="other">Other</option></select></div>
    <label class="ci-chk"><input type="checkbox" name="ignore_always" value="1"> Don't list "<span id="skName"></span>" here again, at any price</label>
  </form>

  <div class="ci-foot"><button class="ia-btn ia-btn--primary" type="button" id="ciSave">Add to inventory</button><button class="ia-btn ia-btn--ghost" type="button" id="ciCancel">Cancel</button></div>
</aside>
@endsection

@push('scripts')
<script src="{{ asset('js/tenant/item-identify.js') }}?v=2"></script>
<script>
(function () {
  var drawer = document.getElementById('ciDrawer'), shade = document.getElementById('ciShade'), mode = 'add', cur = null;
  var titles = { add: 'Add to inventory', match: 'Match to an item', skip: 'Not inventory' };
  var saves  = { add: 'Add to inventory', match: 'Link to this item', skip: 'Remove from list' };
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function setMode(m) {
    mode = m;
    document.querySelectorAll('#ciSeg button').forEach(function (b) { b.classList.toggle('on', b.dataset.m === m); });
    document.querySelectorAll('#ciDrawer [data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== m; });
    document.getElementById('ciDt').textContent = titles[m];
    document.getElementById('ciSave').textContent = saves[m];
  }
  function open(d, m, keepValues) {
    cur = d;
    document.getElementById('ciDs').textContent = '"' + d.name + '" · $' + d.price + ' · rung up ' + d.times + (d.times == 1 ? ' time' : ' times');
    document.getElementById('fKey').value = d.key;
    document.querySelectorAll('.ciKey').forEach(function (i) { i.value = d.key; });
    document.querySelectorAll('.ciName').forEach(function (i) { i.value = d.name; });
    if (!keepValues) {
      document.getElementById('fName').value = d.name;
      document.getElementById('fPrice').value = d.price;
      document.getElementById('fSku').value = '';
    }
    document.getElementById('fNote').textContent = 'The ' + d.times + ' already sold stay on their receipts as they were. Enter what is on the shelf now; past sales won\'t come off it.';
    document.getElementById('skName').textContent = d.name;
    document.getElementById('mItem').value = ''; document.getElementById('mResults').innerHTML = ''; document.getElementById('mQ').value = d.name;
    setMode(m);
    drawer.classList.add('on'); shade.classList.add('on'); drawer.setAttribute('aria-hidden', 'false');
  }
  function close() { drawer.classList.remove('on'); shade.classList.remove('on'); drawer.setAttribute('aria-hidden', 'true'); }

  document.querySelectorAll('[data-ci-open]').forEach(function (b) {
    b.addEventListener('click', function () { open({ key: b.dataset.key, name: b.dataset.name, price: b.dataset.price, times: b.dataset.times }, b.dataset.ciOpen); });
  });
  document.getElementById('ciSeg').addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { setMode(b.dataset.m); if (b.dataset.m === 'match') { search(); } } });
  ['ciX', 'ciCancel', 'ciShade'].forEach(function (id) { document.getElementById(id).addEventListener('click', close); });
  document.getElementById('ciSave').addEventListener('click', function () {
    var f = document.querySelector('#ciDrawer [data-pane="' + mode + '"]');
    if (mode === 'match' && !document.getElementById('mItem').value) { document.getElementById('mQ').focus(); return; }
    if (f.reportValidity && !f.reportValidity()) { return; }
    f.submit();
  });

  // Match: item search
  var q = document.getElementById('mQ'), t = null;
  function search() {
    var v = q.value.trim(); if (v.length < 2) { return; }
    fetch(q.dataset.url + '?q=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { items: [] }; })
      .then(function (d) {
        var box = document.getElementById('mResults');
        box.innerHTML = (d.items || []).map(function (it) {
          return '<label><input type="radio" name="pick" value="' + esc(it.id) + '"> <span><b>' + esc(it.name) + '</b><small>SKU ' + esc(it.sku) + ' · ' + (it.price == null ? 'no price' : '$' + (it.price / 100).toFixed(2)) + ' · ' + esc(it.stock) + ' in stock</small></span></label>';
        }).join('') || '<p class="ci-note">No match. Try fewer words, or add it as a new item.</p>';
        box.querySelectorAll('input[name="pick"]').forEach(function (r) { r.addEventListener('change', function () { document.getElementById('mItem').value = r.value; }); });
      }).catch(function () {});
  }
  q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(search, 250); });

  // A failed save comes back here with what was typed: reopen it.
  @if($reopenKey)
    (function () {
      var b = document.querySelector('[data-ci-open][data-key="' + @json($reopenKey) + '"]');
      if (b) { open({ key: b.dataset.key, name: b.dataset.name, price: b.dataset.price, times: b.dataset.times }, 'add', true); }
    })();
  @endif
})();
</script>
@endpush
