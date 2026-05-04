<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Register — {{ $tenant->name }}</title>
  @if($tenant->favicon_url)
    <link rel="icon" href="{{ $tenant->favicon_url }}">
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;font-size:14px}
    :root{
      --accent: {{ $tenant->accent_color ?? '#BEF264' }};
      --accent-text: {{ \App\Support\ColorHelper::accentTextColor($tenant->accent_color ?? '#BEF264') }};
      --bg:     #0f0f0f;
      --bg2:    #1a1a1a;
      --bg3:    #232323;
      --text:   #f0f0f0;
      --muted:  rgba(255,255,255,.45);
      --muted2: rgba(255,255,255,.65);
      --border: rgba(255,255,255,.1);
      --border2:rgba(255,255,255,.18);
      --danger: #F09595;
      --danger-bg: rgba(226,75,74,.15);
    }

    .topbar{
      display:flex;align-items:center;justify-content:space-between;
      padding:12px 24px;background:var(--bg2);border-bottom:0.5px solid var(--border);
      position:sticky;top:0;z-index:50
    }
    .topbar-left{display:flex;align-items:center;gap:14px}
    .topbar-brand{font-weight:600;font-size:15px}
    .loc-pill{
      display:inline-flex;align-items:center;gap:6px;
      padding:5px 10px;border-radius:99px;
      background:rgba(190,242,100,.08);border:0.5px solid var(--border2);
      font-size:12px;color:var(--muted2)
    }
    .loc-pill .dot{width:6px;height:6px;border-radius:99px;background:var(--accent)}
    .topbar-right{display:flex;align-items:center;gap:18px;font-size:13px}
    .topbar-right a{color:var(--muted2);transition:color .12s}
    .topbar-right a:hover{color:var(--text)}

    .main{
      display:grid;grid-template-columns:1fr 420px;gap:18px;
      max-width:1400px;margin:0 auto;padding:18px 24px;
      min-height:calc(100vh - 50px)
    }
    @media(max-width:900px){
      .main{grid-template-columns:1fr;padding:14px}
    }

    .panel{background:var(--bg2);border:0.5px solid var(--border);border-radius:14px;padding:18px}
    .search-input{
      width:100%;padding:12px 14px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:10px;color:var(--text);font-size:14px;font-family:inherit
    }
    .search-input:focus{outline:none;border-color:var(--accent)}
    .search-tabs{display:flex;gap:6px;margin:12px 0 14px}
    .search-tab{
      padding:6px 12px;background:transparent;border:0.5px solid var(--border);
      border-radius:99px;color:var(--muted);font-size:12px;font-family:inherit;cursor:pointer
    }
    .search-tab.active{background:var(--accent);color:var(--accent-text);border-color:var(--accent)}

    .results-section{margin-top:14px}
    .results-section h3{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px}
    .result-row{
      display:flex;align-items:center;justify-content:space-between;gap:12px;
      padding:10px 12px;border-radius:8px;cursor:pointer;transition:background .12s
    }
    .result-row:hover{background:var(--bg3)}
    .result-name{font-weight:500;font-size:14px}
    .result-meta{font-size:12px;color:var(--muted)}
    .result-price{font-size:14px;font-weight:600;color:var(--text);white-space:nowrap}

    .open-item-btn{
      width:100%;margin-top:10px;padding:10px 14px;
      background:transparent;border:0.5px dashed var(--border2);border-radius:10px;
      color:var(--muted2);font-size:13px;font-family:inherit;cursor:pointer;transition:all .12s
    }
    .open-item-btn:hover{border-color:var(--accent);color:var(--text)}

    .cart-customer{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding:10px 12px;background:var(--bg3);border-radius:10px;margin-bottom:14px;
      font-size:13px
    }
    .cart-customer .name{font-weight:500}
    .cart-customer .clear{color:var(--muted);cursor:pointer;font-size:11px}
    .cart-customer .clear:hover{color:var(--danger)}
    .attach-customer{
      width:100%;padding:10px;background:transparent;border:0.5px dashed var(--border2);
      border-radius:10px;color:var(--muted2);font-size:13px;font-family:inherit;cursor:pointer;transition:all .12s;margin-bottom:14px
    }
    .attach-customer:hover{border-color:var(--accent);color:var(--text)}

    .cart-lines{
      max-height:340px;overflow-y:auto;margin:0 -4px 14px;padding:0 4px;
      border-bottom:0.5px solid var(--border);padding-bottom:14px
    }
    .cart-line{
      display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;
      padding:10px 4px
    }
    .cart-line .name{font-size:13px;font-weight:500;line-height:1.3}
    .cart-line .meta{font-size:11px;color:var(--muted);margin-top:2px}
    .cart-line .qty{
      width:50px;padding:5px 8px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:6px;color:var(--text);font-size:13px;font-family:inherit;text-align:center
    }
    .cart-line .qty:focus{outline:none;border-color:var(--accent)}
    .cart-line .total{font-size:13px;font-weight:600;text-align:right;min-width:62px}
    .cart-line .remove{
      background:transparent;border:none;color:var(--muted);font-size:16px;cursor:pointer;padding:0 4px;line-height:1
    }
    .cart-line .remove:hover{color:var(--danger)}
    .empty-cart{padding:30px 0;text-align:center;color:var(--muted);font-size:13px}

    .totals{font-size:13px}
    .totals-row{display:flex;justify-content:space-between;padding:5px 0;color:var(--muted2)}
    .totals-row.grand{font-size:18px;font-weight:600;color:var(--text);padding-top:10px;margin-top:6px;border-top:0.5px solid var(--border)}

    .pay-btn{
      width:100%;margin-top:16px;padding:14px;background:var(--accent);color:var(--accent-text);
      border:none;border-radius:10px;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;
      transition:filter .12s
    }
    .pay-btn:hover:not(:disabled){filter:brightness(.93)}
    .pay-btn:disabled{opacity:.4;cursor:not-allowed}

    .err-banner{
      background:var(--danger-bg);color:var(--danger);border-radius:8px;
      padding:10px 12px;font-size:13px;margin-bottom:12px
    }

    .modal-bg{
      position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:100;padding:20px
    }
    .modal-bg.open{display:flex}
    .modal{
      background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;
      padding:24px;width:100%;max-width:420px
    }
    .modal h2{font-size:18px;font-weight:600;margin-bottom:8px}
    .modal .lede{color:var(--muted);font-size:13px;margin-bottom:18px}

    .tender-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
    .tender-btn{
      padding:14px 12px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:10px;color:var(--text);font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;
      transition:all .12s;text-align:left
    }
    .tender-btn:hover{border-color:var(--accent)}
    .tender-btn.selected{border-color:var(--accent);background:rgba(190,242,100,.05)}

    .tip-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:12px 0}
    .tip-btn{
      padding:12px 10px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:10px;color:var(--text);font-size:13px;font-family:inherit;cursor:pointer;
      transition:all .12s
    }
    .tip-btn:hover{border-color:var(--accent)}
    .tip-btn.selected{border-color:var(--accent);background:rgba(190,242,100,.05)}
    .tip-custom-row{display:flex;gap:8px;align-items:center;margin-top:6px}
    .tip-custom-row input{
      flex:1;padding:10px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:8px;color:var(--text);font-size:14px;font-family:inherit
    }
    .tip-custom-row input:focus{outline:none;border-color:var(--accent)}

    .modal-actions{display:flex;gap:8px;margin-top:18px}
    .btn-secondary{
      flex:1;padding:11px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:8px;color:var(--text);font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;transition:all .12s
    }
    .btn-secondary:hover{border-color:var(--border2)}
    .btn-primary{
      flex:1;padding:11px;background:var(--accent);color:var(--accent-text);
      border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:filter .12s
    }
    .btn-primary:hover:not(:disabled){filter:brightness(.93)}
    .btn-primary:disabled{opacity:.4;cursor:not-allowed}

    .modal input[type=text]{
      width:100%;padding:10px;background:var(--bg3);border:0.5px solid var(--border);
      border-radius:8px;color:var(--text);font-size:14px;font-family:inherit
    }
    .modal input[type=text]:focus{outline:none;border-color:var(--accent)}

    .receipt{text-align:center}
    .receipt h2{font-size:24px;margin-bottom:6px}
    .receipt .num{font-size:13px;color:var(--muted);margin-bottom:18px;font-family:'SF Mono','Menlo',monospace}
    .receipt .total{font-size:36px;font-weight:700;margin:14px 0}
    .receipt-actions{display:flex;flex-direction:column;gap:8px;margin-top:18px}

    .cust-results{
      position:absolute;top:100%;left:0;right:0;background:var(--bg2);
      border:0.5px solid var(--border);border-radius:10px;margin-top:4px;
      max-height:240px;overflow-y:auto;z-index:10
    }
    .cust-results .row{padding:10px 12px;cursor:pointer;border-bottom:0.5px solid var(--border)}
    .cust-results .row:hover{background:var(--bg3)}
    .cust-results .row:last-child{border-bottom:none}

  </style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <span class="topbar-brand">{{ $tenant->name }} — Register</span>
    <span class="loc-pill" id="locPill">
      <span class="dot"></span>
      <span id="locName">…</span>
    </span>
  </div>
  <div class="topbar-right">
    <a href="{{ route('tenant.register.refunds.index') }}">Refunds</a>
    <a href="{{ route('tenant.dashboard') }}">Dashboard</a>
    <a href="#" id="logoutLink">Sign out</a>
    <form id="logoutForm" method="POST" action="{{ route('tenant.logout') }}" style="display:none">@csrf</form>
  </div>
</div>

<div class="main">

  <div class="panel">
    <input type="text" class="search-input" id="searchInput" placeholder="Search products, services, customers…" autocomplete="off">

    <div class="search-tabs">
      <button type="button" class="search-tab active" data-type="all">All</button>
      <button type="button" class="search-tab" data-type="product">Products</button>
      <button type="button" class="search-tab" data-type="service">Services</button>
    </div>

    <div id="resultsArea">
      <div class="empty-cart" id="emptyResults">Type to search products and services.</div>
    </div>

    <button type="button" class="open-item-btn" id="addOpenItemBtn">+ Add custom item</button>
  </div>

  <div class="panel" id="cartPanel">

    <div id="errBanner" class="err-banner" style="display:none"></div>

    <div id="customerSlot">
      <button type="button" class="attach-customer" id="attachCustBtn">+ Attach customer</button>
    </div>

    <div class="cart-lines" id="cartLines">
      <div class="empty-cart">Cart is empty.</div>
    </div>

    <div class="totals">
      <div class="totals-row"><span>Subtotal</span><span id="subVal">$0.00</span></div>
      <div class="totals-row" id="discountRow" style="display:none"><span>Discount</span><span id="discVal">-$0.00</span></div>
      <div class="totals-row"><span>Tax</span><span id="taxVal">$0.00</span></div>
      <div class="totals-row" id="surchargeRow" style="display:none"><span id="surchLabel">Surcharge</span><span id="surchVal">$0.00</span></div>
      <div class="totals-row" id="tipRow" style="display:none"><span>Tip</span><span id="tipVal">$0.00</span></div>
      <div class="totals-row grand"><span>Total</span><span id="totalVal">$0.00</span></div>
    </div>

    <button type="button" class="pay-btn" id="payBtn" disabled>Mark Paid</button>
  </div>

</div>

<div class="modal-bg" id="tenderModal">
  <div class="modal">
    <h2>Choose tender</h2>
    <div class="lede">How is the customer paying?</div>
    <div class="tender-grid">
      <button type="button" class="tender-btn" data-tender="cash">Cash</button>
      <button type="button" class="tender-btn" data-tender="card">Card</button>
      <button type="button" class="tender-btn" data-tender="check">Check</button>
      <button type="button" class="tender-btn" data-tender="store_credit">Store credit</button>
      <button type="button" class="tender-btn" data-tender="mark_paid">Mark paid (no tender)</button>
    </div>
    <div id="tenderRefRow" style="display:none;margin-bottom:14px">
      <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Reference (optional)</label>
      <input type="text" id="tenderRefInput" placeholder="Check #, last 4 of card, etc.">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" data-close-modal="tenderModal">Cancel</button>
      <button type="button" class="btn-primary" id="tenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="tipModal">
  <div class="modal">
    <h2>Add tip?</h2>
    <div class="lede">Optional. Choose an amount or skip.</div>
    <div class="tip-grid" id="tipGrid"></div>
    <div class="tip-custom-row">
      <input type="text" id="tipCustomInput" placeholder="Custom amount">
      <button type="button" class="btn-secondary" id="tipClearBtn" style="padding:10px 14px;flex:0">Clear</button>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="tipSkipBtn">Skip tip</button>
      <button type="button" class="btn-primary" id="tipConfirmBtn">Add tip & continue</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="openItemModal">
  <div class="modal">
    <h2>Custom item</h2>
    <div class="lede">For one-off items not in inventory.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Description</label>
      <input type="text" id="openItemName" placeholder="What is it?">
    </div>
    <div style="margin-bottom:6px">
      <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Price</label>
      <input type="text" id="openItemPrice" placeholder="0.00" inputmode="decimal">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" data-close-modal="openItemModal">Cancel</button>
      <button type="button" class="btn-primary" id="openItemAddBtn">Add to cart</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="customerModal">
  <div class="modal">
    <h2>Attach customer</h2>
    <div style="margin-bottom:12px;position:relative">
      <input type="text" id="customerSearchInput" placeholder="Name, email, or phone" autocomplete="off">
      <div class="cust-results" id="customerResults" style="display:none"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" data-close-modal="customerModal">Cancel</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="receiptModal">
  <div class="modal receipt">
    <h2>Sale complete</h2>
    <div class="num" id="receiptNum"></div>
    <div class="total" id="receiptTotal"></div>
    <div class="receipt-actions">
      <button type="button" class="btn-primary" id="receiptNewSale">New sale</button>
    </div>
  </div>
</div>

<script>
const ROUTES = {
  search:        @json(route('tenant.register.search')),
  storeSale:     @json(route('tenant.register.sales.store')),
};
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const CFG = {
  taxRate:       {{ $taxRate ?? 0 }},
  taxLabel:      @json($taxLabel ?? ''),
  tipsEnabled:   {{ $tipsConfig['enabled'] ? 'true' : 'false' }},
  tipMethod:     @json($tipsConfig['method'] ?? null),
  tipOptions:    @json($tipsConfig['options'] ?? []),
  tipAllowCustom:{{ $tipsConfig['allow_custom'] ? 'true' : 'false' }},
  surchargeOn:   {{ $surchargeConfig['enabled'] ? 'true' : 'false' }},
  surchargePct:  {{ $surchargeConfig['percent'] ?? 0 }},
  surchargeLabel:@json($surchargeConfig['label'] ?? 'Surcharge'),
};

const cart = {
  customer: null,
  items: [],
  tipCents: 0,
  discountCents: 0,
  payment_method: null,
  payment_reference: null,
};

const fmt = (cents) => '$' + (cents / 100).toFixed(2);
const fmtNeg = (cents) => '-$' + (cents / 100).toFixed(2);
let lineKey = 0;

async function loadLocation() {
  document.getElementById('locName').textContent = 'Current location';
}
loadLocation();

const searchInput = document.getElementById('searchInput');
const resultsArea = document.getElementById('resultsArea');
let searchType = 'all';
let searchTimer = null;

document.querySelectorAll('.search-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.search-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    searchType = tab.dataset.type;
    runSearch();
  });
});

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(runSearch, 250);
});

async function runSearch() {
  const q = searchInput.value.trim();
  if (q.length < 2) {
    resultsArea.innerHTML = '<div class="empty-cart" id="emptyResults">Type to search products and services.</div>';
    return;
  }
  try {
    const url = new URL(ROUTES.search, window.location.origin);
    url.searchParams.set('q', q);
    url.searchParams.set('type', searchType);
    const res = await fetch(url, {headers: {'Accept': 'application/json'}});
    const data = await res.json();
    renderResults(data);
  } catch (e) {
    resultsArea.innerHTML = '<div class="empty-cart">Search failed.</div>';
  }
}

function renderResults(data) {
  let html = '';
  if (data.products && data.products.length) {
    html += '<div class="results-section"><h3>Products</h3>';
    data.products.forEach(p => {
      html += `<div class="result-row" data-add='${JSON.stringify({type:'product',source_id:p.id,name:p.name,price_cents:p.price_cents,is_taxable:p.is_taxable})}'>
        <div><div class="result-name">${escapeHtml(p.name)}</div><div class="result-meta">${escapeHtml(p.sku || '')}</div></div>
        <div class="result-price">${fmt(p.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (data.services && data.services.length) {
    html += '<div class="results-section"><h3>Services</h3>';
    data.services.forEach(s => {
      html += `<div class="result-row" data-add='${JSON.stringify({type:'service',source_id:s.id,name:s.name,price_cents:s.price_cents,is_taxable:true})}'>
        <div><div class="result-name">${escapeHtml(s.name)}</div><div class="result-meta">${s.duration_minutes || 0} min</div></div>
        <div class="result-price">${fmt(s.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (!html) html = '<div class="empty-cart">No matches.</div>';
  resultsArea.innerHTML = html;

  resultsArea.querySelectorAll('[data-add]').forEach(row => {
    row.addEventListener('click', () => {
      const data = JSON.parse(row.dataset.add);
      addToCart(data);
    });
  });
}

function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

function addToCart(item) {
  cart.items.push({
    key: ++lineKey,
    type: item.type,
    source_id: item.source_id,
    name: item.name,
    price_cents: item.price_cents,
    qty: 1,
    is_taxable: item.is_taxable !== false,
  });
  renderCart();
}

function removeLine(key) {
  cart.items = cart.items.filter(i => i.key !== key);
  renderCart();
}

function updateQty(key, qty) {
  const n = parseFloat(qty);
  if (isNaN(n) || n <= 0) {
    removeLine(key);
    return;
  }
  const line = cart.items.find(i => i.key === key);
  if (line) line.qty = n;
  renderCart();
}

function renderCart() {
  const lines = document.getElementById('cartLines');
  if (!cart.items.length) {
    lines.innerHTML = '<div class="empty-cart">Cart is empty.</div>';
    document.getElementById('payBtn').disabled = true;
  } else {
    lines.innerHTML = cart.items.map(i => `
      <div class="cart-line">
        <div>
          <div class="name">${escapeHtml(i.name)}</div>
          <div class="meta">${fmt(i.price_cents)} · ${i.type}</div>
        </div>
        <input type="text" class="qty" value="${i.qty}" data-key="${i.key}" inputmode="decimal">
        <div style="display:flex;align-items:center;gap:6px">
          <span class="total">${fmt(Math.round(i.price_cents * i.qty))}</span>
          <button type="button" class="remove" data-remove="${i.key}">×</button>
        </div>
      </div>
    `).join('');
    document.getElementById('payBtn').disabled = false;
  }

  lines.querySelectorAll('[data-key]').forEach(input => {
    input.addEventListener('change', () => updateQty(parseInt(input.dataset.key, 10), input.value));
  });
  lines.querySelectorAll('[data-remove]').forEach(btn => {
    btn.addEventListener('click', () => removeLine(parseInt(btn.dataset.remove, 10)));
  });

  const slot = document.getElementById('customerSlot');
  if (cart.customer) {
    slot.innerHTML = `
      <div class="cart-customer">
        <div><span class="name">${escapeHtml(cart.customer.name)}</span></div>
        <span class="clear" id="clearCust">Remove</span>
      </div>`;
    document.getElementById('clearCust').addEventListener('click', () => {
      cart.customer = null;
      renderCart();
    });
  } else {
    slot.innerHTML = `<button type="button" class="attach-customer" id="attachCustBtn">+ Attach customer</button>`;
    document.getElementById('attachCustBtn').addEventListener('click', openCustomerModal);
  }

  renderTotals();
}

function calcSubtotal() {
  return cart.items.reduce((sum, i) => sum + Math.round(i.price_cents * i.qty), 0);
}
function calcTax() {
  if (!CFG.taxRate) return 0;
  let taxable = 0;
  cart.items.forEach(i => {
    if (i.is_taxable) taxable += Math.round(i.price_cents * i.qty);
  });
  return Math.round(taxable * (CFG.taxRate / 100));
}
function calcSurcharge() {
  if (!CFG.surchargeOn) return 0;
  if (cart.payment_method !== 'card') return 0;
  return Math.round(calcSubtotal() * (CFG.surchargePct / 100));
}

function renderTotals() {
  const sub = calcSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;
  const total = sub - disc + tax + surch + tip;

  document.getElementById('subVal').textContent = fmt(sub);
  document.getElementById('taxVal').textContent = fmt(tax);
  document.getElementById('totalVal').textContent = fmt(total);

  if (disc > 0) {
    document.getElementById('discountRow').style.display = '';
    document.getElementById('discVal').textContent = fmtNeg(disc);
  } else {
    document.getElementById('discountRow').style.display = 'none';
  }

  if (surch > 0) {
    document.getElementById('surchargeRow').style.display = '';
    document.getElementById('surchLabel').textContent = CFG.surchargeLabel;
    document.getElementById('surchVal').textContent = fmt(surch);
  } else {
    document.getElementById('surchargeRow').style.display = 'none';
  }

  if (tip > 0) {
    document.getElementById('tipRow').style.display = '';
    document.getElementById('tipVal').textContent = fmt(tip);
  } else {
    document.getElementById('tipRow').style.display = 'none';
  }
}

document.getElementById('addOpenItemBtn').addEventListener('click', () => {
  document.getElementById('openItemName').value = '';
  document.getElementById('openItemPrice').value = '';
  openModal('openItemModal');
});
document.getElementById('openItemAddBtn').addEventListener('click', () => {
  const name = document.getElementById('openItemName').value.trim();
  const priceStr = document.getElementById('openItemPrice').value.trim();
  const priceFloat = parseFloat(priceStr);
  if (!name || isNaN(priceFloat) || priceFloat < 0) return;
  const cents = Math.round(priceFloat * 100);
  addToCart({type:'open_item', source_id:null, name, price_cents:cents, is_taxable:true});
  closeModal('openItemModal');
});

function openCustomerModal() {
  document.getElementById('customerSearchInput').value = '';
  document.getElementById('customerResults').style.display = 'none';
  openModal('customerModal');
  setTimeout(() => document.getElementById('customerSearchInput').focus(), 50);
}
let custTimer = null;
document.getElementById('customerSearchInput').addEventListener('input', () => {
  clearTimeout(custTimer);
  custTimer = setTimeout(searchCustomers, 250);
});
async function searchCustomers() {
  const q = document.getElementById('customerSearchInput').value.trim();
  const box = document.getElementById('customerResults');
  if (q.length < 2) { box.style.display = 'none'; return; }
  const url = new URL(ROUTES.search, window.location.origin);
  url.searchParams.set('q', q);
  url.searchParams.set('type', 'customer');
  try {
    const res = await fetch(url, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.customers || !data.customers.length) {
      box.innerHTML = '<div class="row" style="color:var(--muted)">No matches.</div>';
      box.style.display = '';
      return;
    }
    box.innerHTML = data.customers.map(c => `
      <div class="row" data-cust='${JSON.stringify(c)}'>
        <div style="font-weight:500">${escapeHtml(c.name || '(no name)')}</div>
        <div style="font-size:11px;color:var(--muted)">${escapeHtml(c.email || c.phone || '')}</div>
      </div>
    `).join('');
    box.querySelectorAll('[data-cust]').forEach(row => {
      row.addEventListener('click', () => {
        cart.customer = JSON.parse(row.dataset.cust);
        closeModal('customerModal');
        renderCart();
      });
    });
    box.style.display = '';
  } catch (e) {
    box.innerHTML = '<div class="row" style="color:var(--danger)">Search failed.</div>';
    box.style.display = '';
  }
}

document.getElementById('payBtn').addEventListener('click', () => {
  cart.payment_method = null;
  cart.payment_reference = null;
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderRefInput').value = '';
  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('.tender-btn').forEach(b => b.classList.remove('selected'));
  openModal('tenderModal');
});

document.querySelectorAll('.tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.tender;
    document.getElementById('tenderConfirmBtn').disabled = false;
    const showRef = ['card', 'check'].includes(cart.payment_method);
    document.getElementById('tenderRefRow').style.display = showRef ? '' : 'none';
    renderTotals();
  });
});

document.getElementById('tenderConfirmBtn').addEventListener('click', () => {
  cart.payment_reference = document.getElementById('tenderRefInput').value.trim() || null;
  closeModal('tenderModal');
  if (CFG.tipsEnabled) {
    openTipModal();
  } else {
    commitSale();
  }
});

function openTipModal() {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  const grid = document.getElementById('tipGrid');
  grid.innerHTML = '';
  const sub = calcSubtotal();
  (CFG.tipOptions || []).forEach(opt => {
    let cents, label;
    if (CFG.tipMethod === 'percent') {
      cents = Math.round(sub * (parseFloat(opt) / 100));
      label = `${opt}% (${fmt(cents)})`;
    } else {
      cents = Math.round(parseFloat(opt) * 100);
      label = fmt(cents);
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tip-btn';
    btn.textContent = label;
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tip-btn').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      cart.tipCents = cents;
      document.getElementById('tipCustomInput').value = '';
    });
    grid.appendChild(btn);
  });
  openModal('tipModal');
}

document.getElementById('tipCustomInput').addEventListener('input', () => {
  const v = parseFloat(document.getElementById('tipCustomInput').value);
  if (!isNaN(v) && v >= 0) {
    cart.tipCents = Math.round(v * 100);
    document.querySelectorAll('.tip-btn').forEach(b => b.classList.remove('selected'));
  }
});
document.getElementById('tipClearBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  document.querySelectorAll('.tip-btn').forEach(b => b.classList.remove('selected'));
});
document.getElementById('tipSkipBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  closeModal('tipModal');
  commitSale();
});
document.getElementById('tipConfirmBtn').addEventListener('click', () => {
  closeModal('tipModal');
  commitSale();
});

async function commitSale() {
  const payload = {
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    discount_cents: cart.discountCents,
    payment_method: cart.payment_method,
    payment_reference: cart.payment_reference,
    items: cart.items.map(i => {
      const out = {
        type: i.type,
        quantity: i.qty,
        is_taxable: i.is_taxable,
      };
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };

  document.getElementById('payBtn').disabled = true;
  document.getElementById('errBanner').style.display = 'none';

  try {
    const res = await fetch(ROUTES.storeSale, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF,
      },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      showError(data.error || 'Could not complete the sale.');
      return;
    }
    showReceipt(data);
  } catch (e) {
    showError('Network error. Please try again.');
  } finally {
    document.getElementById('payBtn').disabled = cart.items.length === 0;
  }
}

function showError(msg) {
  const el = document.getElementById('errBanner');
  el.textContent = msg;
  el.style.display = '';
}

function showReceipt(data) {
  document.getElementById('receiptNum').textContent = data.sale_number;
  document.getElementById('receiptTotal').textContent = fmt(data.total_cents);
  openModal('receiptModal');
}

document.getElementById('receiptNewSale').addEventListener('click', () => {
  cart.customer = null;
  cart.items = [];
  cart.tipCents = 0;
  cart.discountCents = 0;
  cart.payment_method = null;
  cart.payment_reference = null;
  closeModal('receiptModal');
  renderCart();
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="empty-cart">Type to search products and services.</div>';
});

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('[data-close-modal]').forEach(btn => {
  btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
});

document.getElementById('logoutLink').addEventListener('click', (e) => {
  e.preventDefault();
  document.getElementById('logoutForm').submit();
});

renderCart();
</script>

</body>
</html>
