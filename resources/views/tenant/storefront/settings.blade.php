@extends('layouts.tenant.app')
@php $pageTitle = 'E-commerce'; $tab = session('ecom_tab', request('tab', 'avail')); @endphp

@section('content')
{{-- MARKER-ECOM-SETTINGS: the e-commerce settings center (replaces the old Storefront page). --}}
<style>
.ec-wrap{max-width:1000px}
.ec-tabs{display:flex;gap:2px;overflow-x:auto;border-bottom:.5px solid var(--ia-border);margin-bottom:22px;scrollbar-width:none}
.ec-tabs::-webkit-scrollbar{display:none}
.ec-tab{background:none;border:0;padding:10px 14px;font-size:13px;color:var(--ia-text-muted);white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-.5px;display:flex;gap:6px;align-items:center}
.ec-tab:hover{color:var(--ia-text)}
.ec-tab.on{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.ec-tab .pip{font-size:10px;font-weight:600;padding:1px 6px;border-radius:99px;background:var(--ia-surface-2);color:var(--ia-text-muted)}
.ec-tab .pip.warn{background:#FAEEDA;color:#854F0B}
.ec-panel{display:none}.ec-panel.on{display:block}

.ec-sec{padding:4px 0 22px;margin-bottom:22px;border-bottom:.5px solid var(--ia-border);max-width:760px}
.ec-sec:last-of-type{border-bottom:0}
.ec-sec h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:6px;font-weight:600;display:flex;gap:8px;align-items:center}
.ec-new{font-size:9.5px;letter-spacing:.05em;background:var(--ia-accent);color:var(--ia-accent-text);padding:1px 6px;border-radius:99px;font-weight:700}
.ec-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:11px 0;border-bottom:.5px solid var(--ia-border)}
.ec-row:last-child{border-bottom:0}
.ec-row .t{font-size:13.5px;font-weight:500}
.ec-row .d{font-size:12px;color:var(--ia-text-muted);margin-top:2px;max-width:50ch;line-height:1.5}
.ec-row .ctl{display:flex;gap:8px;align-items:center;flex:none}
.ec-row .ia-input,.ec-row .ia-select{width:auto}
.ec-pre{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--ia-text-muted)}
.ec-pre .ia-input{width:92px}
.ec-sub{margin:2px 0 6px 0;padding:10px 0 4px 14px;border-left:2px solid var(--ia-border)}
.ec-actions{display:flex;gap:8px;align-items:center;padding-top:4px}
.ec-actions .saved{font-size:12px;color:#3B6D11}
.ec-note{font-size:12px;color:var(--ia-text-muted);line-height:1.55;max-width:70ch}
.ec-note b{color:var(--ia-text);font-weight:500}

/* toggle — compact, readable off AND on in the light admin theme */
.tg{position:relative;width:34px;height:20px;flex:none;display:inline-block}
.tg input{opacity:0;width:100%;height:100%;position:absolute;inset:0;margin:0;cursor:pointer;z-index:2}
.tg i{position:absolute;inset:0;border-radius:99px;background:var(--ia-border-strong);transition:.14s}
.tg i:before{content:'';position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;top:3px;left:3px;transition:.14s;box-shadow:0 1px 2px rgba(0,0,0,.25)}
.tg input:checked + i{background:#7aa82a}
.tg input:checked + i:before{transform:translateX(14px)}
.tg input:indeterminate + i{background:#c9d9a6}
.tg input:indeterminate + i:before{transform:translateX(7px)}
.tg input:focus-visible + i{box-shadow:0 0 0 3px var(--ia-accent-soft)}

/* availability */
.av-sum{display:flex;gap:22px;flex-wrap:wrap;margin-bottom:14px}
.av-sum div{font-size:12px;color:var(--ia-text-muted)}
.av-sum b{display:block;font-size:20px;font-weight:500;color:var(--ia-text);letter-spacing:-.01em;line-height:1.2}
.av-sum .w b{color:#854F0B}
.av-view{display:inline-flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);overflow:hidden;margin-right:6px}
.av-view button{background:transparent;border:0;padding:0 14px;height:38px;font-size:13px;color:var(--ia-text-muted)}
.av-view button.on{background:var(--ia-surface-2);color:var(--ia-text);font-weight:500}
.av-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin:0 0 14px;padding:9px 0;border-top:.5px solid var(--ia-border);border-bottom:.5px solid var(--ia-border)}
.av-legend summary{cursor:pointer;font-size:12px;color:var(--ia-text);font-weight:500;list-style:none}
.av-legend summary::-webkit-details-marker{display:none}
.av-legend summary:after{content:" ▾";color:var(--ia-text-muted)}
.av-legend[open] summary:after{content:" ▴"}
.av-legend .lg-body{display:flex;gap:18px;flex-wrap:wrap;margin-top:8px}
.av-legend b{color:var(--ia-text);font-weight:500}
.av-legend .k{flex:1 1 220px}
.av-bulk{display:none;align-items:center;gap:8px;flex-wrap:wrap;padding:9px 12px;margin-bottom:10px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);font-size:13px}
.av-bulk.on{display:flex}
.av-bulk .sp{flex:1}
.ia-table td.c{width:1%}
.ia-table tbody tr{cursor:default}
.nm{font-weight:500;line-height:1.3}
.nm small{display:block;font-weight:400;color:var(--ia-text-muted);font-size:11.5px;margin-top:1px}
.ia-badge{white-space:nowrap}
.ia-badge--forsale{background:#EAF3DE;color:#3B6D11}
.ia-badge--special{background:#FAEEDA;color:#854F0B}
.ia-badge--out{background:#FCEBEB;color:#A32D2D}
.ia-badge--offline{background:#F1EFE8;color:#5F5E5A}
.stk{font-variant-numeric:tabular-nums}
.stk.z{color:#A32D2D}
.ia-table th.ctr,.ia-table td.ctr{text-align:center}
.av-list{display:none}
.av-item{display:grid;grid-template-columns:22px 1fr auto;gap:10px;align-items:center;padding:11px 0;border-bottom:.5px solid var(--ia-border)}
.av-item .nm{font-size:13.5px}
.av-item .tgs{display:flex;gap:12px}
.av-item .tgs label{display:flex;flex-direction:column;align-items:center;gap:3px;font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--ia-text-muted)}
.av-item .meta{display:flex;gap:8px;align-items:center;margin-top:3px;font-size:11.5px;color:var(--ia-text-muted)}
.av-foot{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--ia-text-muted);padding:12px 2px}
.ac-flash{position:fixed;right:20px;top:52px;z-index:60;background:#EAF3DE;color:#3B6D11;font-size:12px;font-weight:500;padding:5px 11px;border-radius:99px;opacity:0;transition:opacity .2s;pointer-events:none}
.ac-flash.on{opacity:1}
.cat-bar{display:flex;gap:3px;height:6px;width:90px;border-radius:99px;overflow:hidden;background:var(--ia-surface-2)}
.cat-bar i{display:block;background:#7aa82a}
.rules-menu{position:relative;display:inline-block}
.rules-pop{display:none;position:absolute;top:42px;left:0;z-index:30;min-width:290px;background:var(--ia-surface);border:.5px solid var(--ia-border-strong);border-radius:var(--ia-r-lg);padding:6px;box-shadow:0 10px 30px rgba(0,0,0,.14)}
.rules-pop.on{display:block}
.rules-pop button{display:block;width:100%;text-align:left;background:none;border:0;padding:9px 11px;border-radius:var(--ia-r-md);font-size:13px;color:var(--ia-text)}
.rules-pop button:hover{background:var(--ia-hover)}
.rules-pop button small{display:block;color:var(--ia-text-muted);font-size:11.5px;margin-top:1px}
.rules-pop hr{border:0;border-top:.5px solid var(--ia-border);margin:5px 0}


/* MARKER-DISTRIBUTION-WAREHOUSES */
.dw-chips{display:flex;gap:5px;flex-wrap:wrap}
.dw-chip{font-size:11.5px;padding:2px 8px;border-radius:99px;background:var(--ia-surface-2);color:var(--ia-text);white-space:nowrap}
.dw-chip b{font-weight:600;margin-left:3px}
.dw-chip.shop{background:#EAF3DE;color:#3B6D11}
.dw-chip.off{opacity:.45;text-decoration:line-through}
.dw-chip.z{color:var(--ia-text-muted)}
.src-badge{font-size:11.5px;padding:2px 8px;border-radius:99px;white-space:nowrap;display:inline-block}
.src-shop{background:#EAF3DE;color:#3B6D11}.src-supplier{background:#E6F1FB;color:#185FA5}.src-special{background:#FAEEDA;color:#854F0B}.src-out{background:#FCEBEB;color:#A32D2D}
.wcol{display:none}
.av-where .wcol{display:table-cell}
.av-where .wsel{display:inline-block}
.wsel{display:none}
.wh-vendor{font-size:12px;font-weight:600;margin:14px 0 4px;display:flex;gap:10px;align-items:center}
.wh-vendor small{font-weight:400;color:var(--ia-text-muted)}
.wh-tbl{width:100%;border-collapse:collapse;font-size:13px}
.wh-tbl th{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600;text-align:left;padding:6px 8px;border-bottom:.5px solid var(--ia-border)}
.wh-tbl td{padding:8px;border-bottom:.5px solid var(--ia-border);vertical-align:middle}
.wh-tbl .n{text-align:right;font-variant-numeric:tabular-nums}
.wh-ord{display:flex;flex-direction:column;gap:2px}
.wh-ord button{background:none;border:0;font-size:10px;line-height:1;color:var(--ia-text-muted);padding:2px 5px;cursor:pointer}
.wh-ord button:hover{color:var(--ia-text)}
.hidden-note{padding:14px 16px;border:.5px dashed var(--ia-border-strong);border-radius:var(--ia-r-lg);color:var(--ia-text-muted);font-size:13px}
.av-item .chips{margin-top:6px}
.hide{display:none!important}
.wide-in{width:400px;max-width:100%}
.stat-ok{display:inline-flex;gap:6px;align-items:center;font-size:12px;color:#3B6D11}
.stat-ok:before{content:'';width:7px;height:7px;border-radius:50%;background:#3B6D11}
.msg-row .ch{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;padding:2px 7px;border-radius:99px;background:var(--ia-surface-2);color:var(--ia-text-muted);margin-left:8px;font-weight:500}
.toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#1a1a1a;color:#fff;border-radius:10px;padding:10px 14px;font-size:13px;display:none;gap:14px;align-items:center;z-index:9999;max-width:calc(100vw - 24px)}
.toast.on{display:flex}
.toast button{background:none;border:0;color:#BEF264;font-weight:600;font-size:13px}

.ec-sub.hide{display:none}
.ec-err{background:#FCEBEB;color:#A32D2D;border-radius:var(--ia-r-md);padding:10px 14px;font-size:13px;margin-bottom:14px;max-width:760px}
.ec-ok{background:#EAF3DE;color:#3B6D11;border-radius:var(--ia-r-md);padding:10px 14px;font-size:13px;margin-bottom:14px;max-width:760px}
.ec-link{font-size:12.5px;color:var(--ia-text);text-decoration:underline}
.ec-ta{width:100%;min-height:64px;margin-top:8px}
.nm a{color:inherit;text-decoration:none}
.av-empty{padding:30px 10px;text-align:center;color:var(--ia-text-muted);font-size:13px}
@media (max-width:900px){
  .ia-table-wrap.av-desk{display:none}
  .av-list{display:block}
  .ia-toolbar .ia-input{max-width:none;min-width:0}
  .ia-toolbar .ia-select{flex:1;min-width:0}
  .ec-row{align-items:flex-start}
  .ec-row:has(.wide-in){flex-direction:column;align-items:stretch;gap:8px}
  .ec-row:has(.wide-in) .ctl{justify-content:flex-start}
  .ec-row .ctl{flex-wrap:wrap;justify-content:flex-end}
  .ec-actions{position:sticky;bottom:0;background:var(--ia-bg);padding:10px 0;border-top:.5px solid var(--ia-border);z-index:5}
  .av-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
  .av-sum b{font-size:17px}
  .av-sum div{font-size:10.5px;line-height:1.3}
  .ia-toolbar .ia-select{flex:1 1 calc(50% - 8px)}
  .ia-toolbar .rules-menu{flex:1 1 calc(50% - 8px)}
  .ia-toolbar #rulesBtn{width:100%}
  .av-view{width:100%;display:flex}.av-view button{flex:1}
}

</style>

<div class="ec-wrap">
  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">E-commerce</h1>
      <div class="ia-page-subtitle">What you sell online, how it gets to the customer, and what they're told.</div>
    </div>
    <div class="ia-page-actions"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="/shop" target="_blank">View your store ↗</a></div>
  </div>

  @if(session('success'))<div class="ec-ok">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="ec-err">{{ session('error') }}</div>@endif
  @if($errors->any())<div class="ec-err">{{ $errors->first() }}</div>@endif

  <div class="ec-tabs" id="tabs">
    <button type="button" class="ec-tab {{ $tab === 'avail' ? 'on' : '' }}" data-p="avail">Availability <span class="pip {{ $counts['out'] > 0 ? 'warn' : '' }}" id="pipOut">{{ $counts['out'] }}</span></button>
    <button type="button" class="ec-tab {{ $tab === 'ful' || $tab === 'fulfillment' ? 'on' : '' }}" data-p="ful">Fulfillment</button>
    <button type="button" class="ec-tab {{ $tab === 'tax' ? 'on' : '' }}" data-p="tax">Tax</button>
    <button type="button" class="ec-tab {{ $tab === 'pay' || $tab === 'payments' ? 'on' : '' }}" data-p="pay">Payments</button>
    <button type="button" class="ec-tab {{ $tab === 'msg' ? 'on' : '' }}" data-p="msg">Messages</button>
    <button type="button" class="ec-tab {{ $tab === 'gen' || $tab === 'general' ? 'on' : '' }}" data-p="gen">General</button>
  </div>

  {{-- ============ AVAILABILITY ============ --}}
  <section class="ec-panel {{ $tab === 'avail' ? 'on' : '' }}" id="p-avail">
    <div class="av-sum">
      <div><b id="sOnline">{{ $counts['online'] }}</b>for sale online</div>
      <div><b id="sOver">0</b>can be oversold</div>
      <div class="w"><b id="sOut">{{ $counts['out'] }}</b>out of stock, can't be ordered</div>
      <div><b id="sOff">{{ $counts['active'] - $counts['online'] }}</b>offline</div>
    </div>

    <details class="av-legend" id="legend" open>
      <summary>What these switches do</summary>
      <div class="lg-body">
        <div class="k"><b>Online</b> — the item appears in your store. Offline items are never shown.</div>
        <div class="k"><b>Oversell</b> — when stock hits 0 the item stays orderable, and each order becomes a special order. Off means it shows <b>Out of stock</b> and can't be added to a cart.</div>
        <div class="k wsel" id="whereLegend" style="flex:1 1 220px"><b>Where it's in stock</b> — <b>Shop</b> is your own stock; the other chips are supplier counts. A struck-through chip is a warehouse you haven't turned on, and "?" means that count is too old to trust. <b>Ships from now</b> is where one would come from right now. Online orders only: the register never uses supplier stock.</div>
        <div class="k">Switches here save instantly. They change the online store only — the register and in-store sales are never affected.</div>
      </div>
    </details>

    <div class="ia-toolbar">
      <span class="av-view"><button type="button" class="on" data-v="items">Items</button><button type="button" data-v="cats">Categories</button></span>
      <input class="ia-input" id="q" placeholder="Search name, SKU or brand" autocomplete="off">
      <select class="ia-select" id="fCat"><option value="">All categories</option></select>
      <select class="ia-select" id="fStock"><option value="">Any stock</option><option value="in">In stock</option><option value="zero">Out of stock</option></select>
      <select class="ia-select" id="fState"><option value="">Any status</option><option value="forsale">For sale</option><option value="special">Special order</option><option value="out">Out of stock (blocked)</option><option value="offline">Offline</option></select>
      <select class="ia-select wsel" id="fSrc"><option value="">Any source</option><option value="shop">Ships from shop</option><option value="supplier">Ships from supplier</option><option value="special">Special order</option><option value="out">Can't be ordered</option></select>
      <span class="rules-menu">
        <button type="button" class="ia-btn ia-btn--secondary" id="rulesBtn" style="height:38px">Quick rules ▾</button>
        <div class="rules-pop" id="rulesPop">
          <button type="button" data-r="instock-online">Put every in-stock item online<small>Skips items already online</small></button>
          <button type="button" data-r="catalog-online">Put every catalog-linked item online<small>Distributor-matched items with images and specs</small></button>
          <button type="button" data-r="catalog-over">Allow oversell on catalog-linked items<small>They stay orderable at 0 stock as special orders</small></button>
          <hr>
          <button type="button" data-r="over-off">Stop overselling everywhere<small>Items at 0 stock become Out of stock</small></button>
          <button type="button" data-r="all-off">Take everything offline<small>The store shows no items until you publish again</small></button>
        </div>
      </span>
      <span class="ac-flash" id="flash">Saved</span>
    </div>

    <div class="av-bulk" id="bulk">
      <b id="bulkN">0</b> selected
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-b="on">Put online</button>
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-b="off">Take offline</button>
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-b="over-on">Allow oversell</button>
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-b="over-off">Stop oversell</button>
      <span class="sp"></span>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-b="clear">Clear</button>
    </div>

    <div id="viewItems" class="">
      <div class="ia-table-wrap av-desk">
        <table class="ia-table">
          <thead><tr>
            <th class="c"><input type="checkbox" id="selAll" title="Select all on this page"></th>
            <th>Item</th><th>Category</th><th class="ia-num">Stock</th><th class="wcol">Where it's in stock</th><th class="wcol">Ships from now</th><th class="ia-num">Price</th>
            <th class="ctr">Online</th><th class="ctr">Oversell</th><th>Store shows</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>
      <div class="av-list" id="list"></div>
      <div class="av-foot"><span id="countLine"></span><span><button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="more">Show more</button></span></div>
    </div>

    <div id="viewCats" class="hide">
      <div class="ec-note" style="margin-bottom:10px">Switch a whole category at once. A half-way switch means some items are on and some off — click to turn them all on, click again for all off. You'll see how many items change before anything is applied.</div>
      <div class="ia-table-wrap av-desk">
        <table class="ia-table">
          <thead><tr><th>Category</th><th class="ia-num">Items</th><th>Online now</th><th class="ctr">Online</th><th class="ctr">Oversell</th></tr></thead>
          <tbody id="catBody"></tbody>
        </table>
      </div>
      <div class="av-list" id="catList"></div>
    </div>
  </section>

  {{-- ============ FULFILLMENT ============ --}}
  <section class="ec-panel {{ in_array($tab, ['ful','fulfillment']) ? 'on' : '' }}" id="p-ful">
    <form method="POST" action="{{ route('tenant.storefront.settings.update') }}">
      @csrf <input type="hidden" name="section" value="fulfillment">
      <div class="ec-sec">
        <h3>Pickup</h3>
        <div class="ec-row"><div><div class="t">Offer pickup</div><div class="d">Customers collect in store. Free.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="pickup_enabled" value="1" @checked($cfg['pickup_enabled'])><i></i></label></div></div>
        <div class="ec-row"><div style="flex:1"><div class="t">Pickup instructions</div><div class="d">Optional. Hours, where to park, what to bring. Shown at checkout and in the confirmation email.</div><textarea class="ia-textarea ec-ta" name="pickup_instructions" maxlength="300" placeholder="Come to the front counter and give us your name or order number.">{{ old('pickup_instructions', $cfg['pickup_instructions']) }}</textarea></div></div>
      </div>

      <div class="ec-sec">
        <h3>Local delivery</h3>
        <div class="ec-row"><div><div class="t">Offer local delivery</div><div class="d">Customers choose delivery at checkout and enter an address.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="local_delivery" id="ldOn" value="1" @checked($cfg['local_delivery'])><i></i></label></div></div>
        <div class="ec-sub" id="ldBox">
          <div class="ec-row"><div><div class="t">Delivery fee</div><div class="d">Zero means free delivery.</div></div><div class="ctl"><span class="ec-pre">$ <input class="ia-input" name="delivery_fee" inputmode="decimal" value="{{ old('delivery_fee', $cfg['delivery_fee']) }}" placeholder="0.00"></span></div></div>
          <div class="ec-row"><div><div class="t">Free delivery over</div><div class="d">Leave blank for none. Judged after any discount.</div></div><div class="ctl"><span class="ec-pre">$ <input class="ia-input" name="free_over" inputmode="decimal" value="{{ old('free_over', $cfg['free_over']) }}" placeholder="none"></span></div></div>
          <div class="ec-row"><div style="flex:1"><div class="t">Delivery area</div><div class="d">ZIP codes you deliver to, separated by commas. Blank means anywhere — you'll confirm the window with the customer. A customer outside the list is told so before paying.</div><input class="ia-input" style="margin-top:8px;width:100%" name="delivery_zips" value="{{ old('delivery_zips', $cfg['delivery_zips']) }}" placeholder="99201, 99202, 99203"></div></div>
        </div>
      </div>

      {{-- MARKER-ORDER-SHIPMENTS --}}
      <div class="ec-sec">
        <h3>Shipping</h3>
        <div class="ec-row"><div><div class="t">Ship orders to an address</div><div class="d">Customers enter a street address at checkout. An order that comes from more than one place ships as separate shipments, and each is priced on its own.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="ship_enabled" id="shipOn" value="1" @checked(old('ship_enabled', $cfg['ship_enabled']))><i></i></label></div></div>
        <div class="ec-sub" id="shipBox">
          <div class="ec-row"><div><div class="t">Shipping price per shipment</div><div class="d">A flat price. Zero means free shipping.</div></div><div class="ctl"><span class="ec-pre">$ <input class="ia-input" name="ship_fee" inputmode="decimal" value="{{ old('ship_fee', $cfg['ship_fee']) }}" placeholder="0.00"></span></div></div>
          @if (($dist['access'] ?? 'hidden') === 'on')
          <div class="ec-row"><div><div class="t">Price for a shipment from a supplier</div><div class="d">Leave blank to charge the same price as above.</div></div><div class="ctl"><span class="ec-pre">$ <input class="ia-input" name="ship_fee_supplier" inputmode="decimal" value="{{ old('ship_fee_supplier', $cfg['ship_fee_supplier']) }}" placeholder="same"></span></div></div>
          @endif
          <div class="ec-row"><div><div class="t">Free shipping over</div><div class="d">Leave blank for none. Judged after any discount, and applies to every shipment on the order.</div></div><div class="ctl"><span class="ec-pre">$ <input class="ia-input" name="ship_free_over" inputmode="decimal" value="{{ old('ship_free_over', $cfg['ship_free_over']) }}" placeholder="none"></span></div></div>
          <div class="ec-note">Weight-based and carrier rates are planned. Today each shipment has one flat price.</div>
        </div>
      </div>

      <div class="ec-sec">
        <h3>Installation or assembly</h3>
        <div class="ec-row"><div><div class="t">Offer it at checkout</div><div class="d">Adds an "I'd like this installed" tick box. Requests show on the order for you to schedule.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="install_offer" value="1" @checked($cfg['install_offer'])><i></i></label></div></div>
        <div class="ec-row"><div><div class="t">Checkbox wording</div><div class="d">Blank uses the standard wording.</div></div><div class="ctl"><input class="ia-input wide-in" name="install_label" maxlength="160" value="{{ old('install_label', $cfg['install_label']) }}" placeholder="{{ $cfg['install_default'] }}"></div></div>
      </div>

      {{-- MARKER-DISTRIBUTION-WAREHOUSES --}}
      @if (($dist['access'] ?? 'hidden') !== 'hidden')
      <div class="ec-sec" id="dropSec">
        <h3>Where orders ship from <span class="ec-new">NEW</span></h3>
        <div class="ec-row"><div><div class="t">Your shop</div><div class="d">Always an option. An order ships from your own stock whenever you have enough.</div></div><div class="ctl"><span class="stat-ok">Always on</span></div></div>
        @if ($dist['access'] === 'on')
        <div class="ec-row"><div><div class="t">Ship from supplier warehouses when you're out</div><div class="d">The supplier packs and ships straight to your customer. You're still the seller, and the customer pays you.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="dropship_on" id="dsOn" value="1" @checked(old('dropship_on', $dist['cfg']['dropship_on']))><i></i></label></div></div>
        <div class="ec-sub" id="dsBox">
          <div class="ec-note" style="margin-bottom:6px">Pick the warehouses you want to work with. Items are only offered from a warehouse you've turned on, and only where it shows stock.</div>
          @php $byDist = collect($dist['warehouses'])->groupBy('dist'); @endphp
          @forelse ($byDist as $dcode => $list)
            @php $usable = $list->where('dropship', true); @endphp
            <div class="wh-vendor">{{ $dcode }} <small>{{ $list->count() }} {{ $list->count() === 1 ? 'warehouse' : 'warehouses' }}@if ($usable->isEmpty()) · dropshipping isn't turned on for this supplier yet @endif</small></div>
            @if ($usable->isEmpty())
              <div class="ec-note">Not available yet.</div>
            @else
              <table class="wh-tbl"><thead><tr><th style="width:36px">Try</th><th>Warehouse</th><th>Ships from</th><th class="n">Your items in stock</th><th class="n">Use it</th></tr></thead>
              <tbody class="wh-body">
              @foreach ($usable as $w)
                <tr data-id="{{ $w['id'] }}">
                  <td><div class="wh-ord"><button type="button" class="up" aria-label="Try earlier">▲</button><button type="button" class="dn" aria-label="Try later">▼</button></div><input type="hidden" name="wh_order[]" value="{{ $w['id'] }}"></td>
                  <td>{{ $w['name'] }}</td><td>{{ $w['ships_from'] ?: '—' }}</td><td class="n">{{ number_format($w['in_stock']) }}</td>
                  <td class="n"><label class="tg"><input type="checkbox" name="wh_on[{{ $w['id'] }}]" value="1" @checked($w['enabled'])><i></i></label></td>
                </tr>
              @endforeach
              </tbody></table>
            @endif
          @empty
            <div class="ec-note">No supplier warehouses are available yet.</div>
          @endforelse

          <div class="ec-row"><div><div class="t">Use my stock first</div><div class="d">On: your shelf ships what it has and the supplier fills the rest, so 2 in stock and 3 ordered ships 2 from you and 1 from the supplier. Off: the supplier ships the whole line when it can, and your stock stays for the register.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="dropship_own_first" value="1" @checked(old('dropship_own_first', $dist['cfg']['own_first']))><i></i></label></div></div>
          <div class="ec-row"><div><div class="t">Which warehouse is tried first</div><div class="d">The top of the list above, or the one showing the most stock. If it's out, the next is tried.</div></div><div class="ctl"><select class="ia-select" name="dropship_order_mode"><option value="list" @selected($dist['cfg']['order_mode'] === 'list')>In the order above</option><option value="stock" @selected($dist['cfg']['order_mode'] === 'stock')>Most stock first</option></select></div></div>
          <div class="ec-row"><div><div class="t">Items you allow to oversell</div><div class="d">Used when you're out of stock.</div></div><div class="ctl"><select class="ia-select" name="dropship_oversell_mode"><option value="supplier" @selected($dist['cfg']['oversell_mode'] === 'supplier')>Ask a supplier first, else special order</option><option value="special" @selected($dist['cfg']['oversell_mode'] === 'special')>Always a special order</option></select></div></div>
          <div class="ec-row"><div><div class="t">Extra handling time for supplier orders</div><div class="d">Added to the warehouse's own handling days and shown to the customer.</div></div><div class="ctl"><input class="ia-input" type="number" min="0" max="60" name="dropship_handling_days" value="{{ old('dropship_handling_days', $dist['cfg']['handling_days']) }}" style="width:70px"> days</div></div>
          <div class="ec-row"><div><div class="t">Wording the customer sees</div></div><div class="ctl"><input class="ia-input" style="width:280px;max-width:100%" name="dropship_label" maxlength="80" value="{{ old('dropship_label', $dist['cfg']['label']) }}"></div></div>
        </div>
        @else
        <div class="hidden-note">Supplier dropshipping isn't turned on for your shop yet. Contact support to have it enabled.</div>
        @endif
        <details class="av-legend" open style="margin-top:10px"><summary>What this changes</summary><div class="lg-body">
          <div class="k"><b>Online orders only.</b> The register and in-store sales never use supplier stock.</div>
          <div class="k"><b>Supplier stock is a snapshot.</b> It refreshes on a schedule, so a very recent sale at the supplier can still run out. If that happens you're told, and the line becomes a special order.</div>
          <div class="k"><b>Costs.</b> Supplier orders use your own dealer cost, so margins stay accurate.</div>
        </div></details>
      </div>
      @endif
      <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save fulfillment</button></div>
    </form>
  </section>

  {{-- ============ TAX ============ --}}
  <section class="ec-panel {{ $tab === 'tax' ? 'on' : '' }}" id="p-tax">
    <form method="POST" action="{{ route('tenant.storefront.settings.update') }}">
      @csrf <input type="hidden" name="section" value="tax">
      <div class="ec-sec">
        <h3>Sales tax</h3>
        <div class="ec-row"><div><div class="t">Shop rate</div><div class="d">Online orders use the same rate as your register, from Business settings.</div></div><div class="ctl"><b>{{ rtrim(rtrim(number_format($info['tax_rate'], 3), '0'), '.') }}%</b><a class="ia-btn ia-btn--ghost ia-btn--sm" href="{{ route('tenant.settings.index') }}">Change in Business</a></div></div>
      </div>
      <div class="ec-sec">
        <h3>What gets taxed</h3>
        <div class="ec-row"><div><div class="t">Tax the delivery and shipping fees</div><div class="d">Off means delivery and shipping are untaxed. Some states tax them — check yours.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="tax_delivery_fee" value="1" @checked($cfg['tax_delivery_fee'])><i></i></label></div></div>
        <div class="ec-row"><div><div class="t">Tax-exempt items</div><div class="d">Items marked exempt in Inventory are never taxed, online or in store.</div></div><div class="ctl"><span class="ec-note">{{ $info['exempt'] }} {{ $info['exempt'] === 1 ? 'item' : 'items' }}</span></div></div>
        <div class="ec-row"><div><div class="t">Prices shown to customers</div><div class="d">Before tax. Tax is added at checkout.</div></div><div class="ctl"></div></div>
      </div>
      <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save tax</button></div>
    </form>
  </section>

  {{-- ============ PAYMENTS ============ --}}
  <section class="ec-panel {{ in_array($tab, ['pay','payments']) ? 'on' : '' }}" id="p-pay">
    <form method="POST" action="{{ route('tenant.storefront.settings.update') }}">
      @csrf <input type="hidden" name="section" value="payments">
      <div class="ec-sec">
        <h3>Card payments</h3>
        <div class="ec-row"><div><div class="t">Pay by card</div><div class="d">Paid out to your bank by Stripe. Orders are confirmed the moment the card clears.</div></div><div class="ctl">@if($info['card_ready'])<span class="stat-ok">Connected</span>@else<span class="ec-note">Not set up</span>@endif</div></div>
      </div>
      <div class="ec-sec">
        <h3>Other ways to pay</h3>
        <div class="ec-note" style="margin-bottom:4px">Venmo, Cash App and pay-in-store orders wait as <b>Awaiting payment</b> until you mark them paid on the order. Stock isn't taken until then.</div>
        <div class="ec-row"><div><div class="t">Cancel unpaid orders after</div><div class="d">Awaiting-payment orders are released after this long.</div></div><div class="ctl"><input class="ia-input" type="number" min="1" max="720" name="hold_hours" value="{{ old('hold_hours', $cfg['hold_hours']) }}" style="width:80px"> hours</div></div>
        <div class="ec-row"><div><div class="t">Payment methods</div><div class="d">Turn Venmo, Cash App and others on or off for online orders.</div></div><div class="ctl"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.settings.index') }}">Edit payment methods</a></div></div>
      </div>
      <div class="ec-sec">
        <h3>If a payment goes through but the order can't be completed</h3>
        <div class="ec-row"><div><div class="t">Refund the customer automatically</div><div class="d">For example two shoppers buy the last unit at the same moment. The card is refunded in full, the shopper is emailed, and the failure appears under Issues. If this is off, the order is flagged for you to refund by hand. Recommended on.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="auto_refund" value="1" @checked($cfg['auto_refund'])><i></i></label></div></div>
      </div>
      <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save payments</button></div>
    </form>
  </section>

  {{-- ============ MESSAGES ============ --}}
  <section class="ec-panel {{ $tab === 'msg' ? 'on' : '' }}" id="p-msg">
    <div class="ec-sec">
      <h3>Order messages</h3>
      <div class="ec-row msg-row"><div><div class="t">Order confirmation<span class="ch">Email</span></div><div class="d">Sent when payment clears.</div></div><div class="ctl"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.communication.index') }}">Messages</a></div></div>
      <div class="ec-row msg-row"><div><div class="t">Online order refunded<span class="ch">Email</span></div><div class="d">Sent if a paid order can't be completed and the money goes back. Edit it under Messages.</div></div><div class="ctl"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.communication.index') }}">Edit</a></div></div>
    </div>
    <form method="POST" action="{{ route('tenant.storefront.settings.update') }}">
      @csrf <input type="hidden" name="section" value="wording">
      <div class="ec-sec">
        <h3>Ready for pickup<span class="ch" style="font-size:10.5px;margin-left:8px;font-weight:500;text-transform:uppercase">Text</span></h3>
        <div class="ec-row"><div style="flex:1"><div class="t">Text wording</div><div class="d">Sent when you mark a pickup order ready (you can untick it per order). You can use {first_name}, {shop_name} and {order_number}. "Reply STOP" is added if you leave it out. Blank uses the standard text.</div><textarea class="ia-textarea ec-ta" name="ready_text" maxlength="300" placeholder="{{ $cfg['ready_default'] }}">{{ old('ready_text', $cfg['ready_text']) }}</textarea></div></div>
      </div>
      <div class="ec-sec">
        <h3>Wording in your store</h3>
        <div class="ec-note" style="margin-bottom:6px">What shoppers read on product pages, in the cart and at checkout. Blank uses the standard wording.</div>
        <div class="ec-row"><div><div class="t">In stock</div></div><div class="ctl"><input class="ia-input wide-in" name="stock_label" maxlength="80" value="{{ old('stock_label', $cfg['words']['stock_label']) }}" placeholder="{{ $cfg['words_default']['stock_label'] }}"></div></div>
        <div class="ec-row"><div><div class="t">Special order</div><div class="d">Shown for items you've allowed to oversell when stock is 0.</div></div><div class="ctl"><input class="ia-input wide-in" name="special_label" maxlength="80" value="{{ old('special_label', $cfg['words']['special_label']) }}" placeholder="{{ $cfg['words_default']['special_label'] }}"></div></div>
        <div class="ec-row"><div><div class="t">Out of stock</div><div class="d">Shown for items that can't be oversold at 0 stock.</div></div><div class="ctl"><input class="ia-input wide-in" name="out_label" maxlength="80" value="{{ old('out_label', $cfg['words']['out_label']) }}" placeholder="{{ $cfg['words_default']['out_label'] }}"></div></div>
        <div class="ec-row"><div><div class="t">Cart and checkout notice</div><div class="d">Appears on any special-order line, so nobody is surprised.</div></div><div class="ctl"><input class="ia-input wide-in" name="special_notice" maxlength="200" value="{{ old('special_notice', $cfg['words']['special_notice']) }}" placeholder="{{ $cfg['words_default']['special_notice'] }}"></div></div>
        <div class="ec-row"><div><div class="t">Store page intro</div><div class="d">The line under your shop's title.</div></div><div class="ctl"><input class="ia-input wide-in" name="intro" maxlength="200" value="{{ old('intro', $cfg['words']['intro']) }}" placeholder="{{ $cfg['words_default']['intro'] }}"></div></div>
      </div>
      <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save wording</button></div>
    </form>
  </section>

  {{-- ============ GENERAL ============ --}}
  <section class="ec-panel {{ in_array($tab, ['gen','general']) ? 'on' : '' }}" id="p-gen">
    <form method="POST" action="{{ route('tenant.storefront.settings.update') }}">
      @csrf <input type="hidden" name="section" value="general">
      <div class="ec-sec">
        <h3>Store</h3>
        <div class="ec-row"><div><div class="t">Store is open</div><div class="d">Turn off to hide /shop entirely, carts and checkout included. Nothing is deleted.</div></div><div class="ctl"><label class="tg"><input type="checkbox" name="enabled" value="1" @checked($cfg['enabled'])><i></i></label></div></div>
        <div class="ec-row"><div><div class="t">Category browsing</div><div class="d">How categories appear on your shop page.</div></div><div class="ctl"><select class="ia-select" name="browse_layout"><option value="chips" @selected($cfg['browse_layout'] === 'chips')>Chips across the top</option><option value="sidebar" @selected($cfg['browse_layout'] === 'sidebar')>Sidebar with counts</option></select></div></div>
        <div class="ec-row"><div><div class="t">Out-of-stock items that can't be oversold</div><div class="d">Show them marked Out of stock, or hide them until they're back.</div></div><div class="ctl"><select class="ia-select" name="out_display"><option value="show" @selected($cfg['out_display'] === 'show')>Show, marked Out of stock</option><option value="hide" @selected($cfg['out_display'] === 'hide')>Hide from the store</option></select></div></div>
      </div>
      <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save general</button></div>
    </form>
  </section>
</div>

<script>
/* MARKER-DISTRIBUTION-WAREHOUSES: reorder warehouse rows; show/hide the dropship options */
(function(){
  document.querySelectorAll('.wh-body').forEach(function(tb){
    tb.addEventListener('click',function(e){
      var b=e.target.closest('button.up,button.dn'); if(!b) return;
      var tr=b.closest('tr'); if(!tr) return;
      if(b.classList.contains('up') && tr.previousElementSibling) tb.insertBefore(tr,tr.previousElementSibling);
      if(b.classList.contains('dn') && tr.nextElementSibling) tb.insertBefore(tr.nextElementSibling,tr);
    });
  });
  var so=document.getElementById('shipOn'),sb=document.getElementById('shipBox');
  if(so&&sb){var g=function(){sb.classList.toggle('hide',!so.checked)};so.addEventListener('change',g);g();}
  var on=document.getElementById('dsOn'),box=document.getElementById('dsBox');
  if(on&&box){var f=function(){box.classList.toggle('hide',!on.checked)};on.addEventListener('change',f);f();}
})();
</script>
<div class="toast" id="toast"><span id="toastT"></span><button type="button" id="toastU">Undo</button></div>

<div id="ecomCfg" hidden
     data-items="{{ route('tenant.storefront.availability.items') }}"
     data-cats="{{ route('tenant.storefront.availability.categories') }}"
     data-item="{{ url('/storefront/availability/item') }}"
     data-bulk="{{ route('tenant.storefront.availability.bulk') }}"
     data-undo="{{ route('tenant.storefront.availability.undo') }}"
     data-csrf="{{ csrf_token() }}"></div>
<script src="{{ asset('js/tenant/ecommerce-availability.js') }}?v={{ filemtime(public_path('js/tenant/ecommerce-availability.js')) }}" defer></script>
@endsection
