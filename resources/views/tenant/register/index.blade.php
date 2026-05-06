@extends('layouts.tenant.app')

@php $pageTitle = 'Register'; @endphp

@push('styles')
<style>
  .reg-page { --reg-danger: #F09595; --reg-danger-bg: rgba(226,75,74,.15); }

  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .reg-grid {
    display:grid;grid-template-columns:1fr 360px;gap:18px;
  }
  @media(max-width:1200px){ .reg-grid{grid-template-columns:1fr} }

  .reg-panel{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);padding:18px
  }

  .reg-search{
    width:100%;padding:12px 14px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);
    color:var(--ia-text);font-size:14px;font-family:inherit
  }
  .reg-search:focus{outline:none;border-color:var(--ia-accent)}

  .reg-tabs{display:flex;gap:6px;margin:12px 0 14px}
  .reg-tab{
    padding:6px 12px;background:transparent;border:0.5px solid var(--ia-border);
    border-radius:99px;color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer
  }
  .reg-tab.active{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}

  .reg-results-section{margin-top:14px}
  .reg-results-section h3{
    font-size:11px;font-weight:600;color:var(--ia-text-dim);
    text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px
  }
  .reg-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:10px 12px;border-radius:var(--ia-r-md);cursor:pointer;transition:background var(--ia-t)
  }
  .reg-row:hover{background:var(--ia-hover)}
  .reg-row.highlighted{background:var(--ia-hover)}
  .reg-results-section.mouse-active .reg-row.highlighted:not(:hover){background:transparent}
  .reg-row .name{font-weight:500;font-size:14px}
  .reg-row .meta{font-size:12px;color:var(--ia-text-dim)}
  .reg-row .price{font-size:14px;font-weight:600;color:var(--ia-text);white-space:nowrap}

  .reg-hint{
    display:flex;gap:14px;align-items:center;
    font-size:11px;color:var(--ia-text-dim);
    margin:8px 4px 6px;padding:0 4px
  }
  .reg-hint kbd{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:18px;height:18px;padding:0 5px;
    background:var(--ia-surface-2);
    border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-sm);
    font-family:var(--ia-font-mono);
    font-size:10px;color:var(--ia-text-muted);
    margin:0 2px
  }

  .reg-open-item{
    width:100%;margin-top:10px;padding:10px 14px;
    background:transparent;border:0.5px dashed var(--ia-border-strong);
    border-radius:var(--ia-r-md);color:var(--ia-text-muted);
    font-size:13px;font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-open-item:hover{border-color:var(--ia-accent);color:var(--ia-text)}

  .reg-cust{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:10px 12px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);
    margin-bottom:14px;font-size:13px
  }
  .reg-cust .name{font-weight:500}
  .reg-cust .clear{color:var(--ia-text-dim);cursor:pointer;font-size:11px}
  .reg-cust .clear:hover{color:var(--reg-danger)}

  .reg-attach{
    width:100%;padding:10px;background:transparent;border:0.5px dashed var(--ia-border-strong);
    border-radius:var(--ia-r-md);color:var(--ia-text-muted);font-size:13px;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t);margin-bottom:14px
  }
  .reg-attach:hover{border-color:var(--ia-accent);color:var(--ia-text)}

  .reg-lines{
    max-height:340px;overflow-y:auto;margin:0 -4px 14px;padding:0 4px;
    border-bottom:0.5px solid var(--ia-border);padding-bottom:14px
  }
  .reg-line{
    display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 4px
  }
  .reg-line .name{font-size:13px;font-weight:500;line-height:1.3}
  .reg-line .meta{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .reg-line .qty{
    width:50px;padding:5px 8px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-family:inherit;text-align:center
  }
  .reg-line .qty:focus{outline:none;border-color:var(--ia-accent)}
  .reg-line .total{font-size:13px;font-weight:600;text-align:right;min-width:62px}
  .reg-line .remove{background:transparent;border:none;color:var(--ia-text-dim);font-size:16px;cursor:pointer;padding:0 4px;line-height:1}
  .reg-line .remove:hover{color:var(--reg-danger)}
  .reg-empty{padding:30px 0;text-align:center;color:var(--ia-text-dim);font-size:13px}

  .reg-totals{font-size:13px}
  .reg-totals-row{display:flex;justify-content:space-between;padding:5px 0;color:var(--ia-text-muted)}
  .reg-totals-row.grand{font-size:18px;font-weight:600;color:var(--ia-text);padding-top:10px;margin-top:6px;border-top:0.5px solid var(--ia-border)}

  .reg-pay-row{display:grid;grid-template-columns:1fr 2fr;gap:8px;margin-top:16px}
  .reg-pay{
    padding:14px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-md);font-size:15px;font-weight:600;
    font-family:inherit;cursor:pointer;transition:filter var(--ia-t)
  }
  .reg-pay:hover:not(:disabled){filter:brightness(.93)}
  .reg-pay:disabled{opacity:.4;cursor:not-allowed}

  .reg-quote-btn{
    padding:14px;background:rgba(var(--ia-accent-rgb,255,255,255),.10);
    border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:14px;font-weight:500;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-quote-btn:hover:not(:disabled){border-color:var(--ia-accent);background:var(--ia-accent-soft)}
  .reg-quote-btn:disabled{opacity:.4;cursor:not-allowed}

  .reg-cust.warning{
    background:var(--reg-danger-bg);
    border:0.5px solid var(--reg-danger);
  }
  .reg-attach.warning{
    border:0.5px dashed var(--reg-danger);
    color:var(--reg-danger)
  }

  .reg-err{background:var(--reg-danger-bg);color:var(--reg-danger);border-radius:var(--ia-r-sm);padding:10px 12px;font-size:13px;margin-bottom:12px}

  .reg-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
  .reg-modal-bg.open{display:flex}
  .reg-modal{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-xl);padding:24px;width:100%;max-width:420px
  }
  .reg-modal h2{font-size:18px;font-weight:600;margin-bottom:8px;color:var(--ia-text)}
  .reg-modal .lede{color:var(--ia-text-dim);font-size:13px;margin-bottom:18px}

  .reg-tender-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
  .reg-tender-btn{
    padding:14px 12px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-weight:500;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t);text-align:left
  }
  .reg-tender-btn:hover{border-color:var(--ia-accent)}
  .reg-tender-btn.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}

  .reg-tip-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:12px 0}
  .reg-tip-btn{
    padding:12px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-tip-btn:hover{border-color:var(--ia-accent)}
  .reg-tip-btn.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}

  .reg-tip-custom{display:flex;gap:8px;align-items:center;margin-top:6px}
  .reg-tip-custom input{flex:1;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-tip-custom input:focus{outline:none;border-color:var(--ia-accent)}

  .reg-modal-actions{display:flex;gap:8px;margin-top:18px}
  .reg-btn-secondary{flex:1;padding:11px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;transition:all var(--ia-t)}
  .reg-btn-secondary:hover{border-color:var(--ia-border-strong)}
  .reg-btn-primary{flex:1;padding:11px;background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:var(--ia-r-sm);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:filter var(--ia-t)}
  .reg-btn-primary:hover:not(:disabled){filter:brightness(.93)}
  .reg-btn-primary:disabled{opacity:.4;cursor:not-allowed}

  .reg-modal input[type=text]{width:100%;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-modal input[type=text]:focus{outline:none;border-color:var(--ia-accent)}

  .reg-receipt{text-align:center}
  .reg-receipt h2{font-size:24px;margin-bottom:6px}
  .reg-receipt .num{font-size:13px;color:var(--ia-text-dim);margin-bottom:18px;font-family:var(--ia-font-mono)}
  .reg-receipt .total{font-size:36px;font-weight:700;margin:14px 0}

  .reg-cust-results{position:absolute;top:100%;left:0;right:0;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);margin-top:4px;max-height:240px;overflow-y:auto;z-index:10}

  .reg-refund-result{
    padding:12px;background:rgba(190,242,100,.04);
    border:0.5px solid var(--ia-accent);border-radius:var(--ia-r-md);
    margin-bottom:10px;cursor:pointer;transition:filter var(--ia-t)
  }
  .reg-refund-result:hover{filter:brightness(1.1)}
  .reg-refund-result .label{font-size:11px;color:var(--ia-accent);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:4px}
  .reg-refund-result .name{font-size:14px;font-weight:500}
  .reg-refund-result .meta{font-size:12px;color:var(--ia-text-dim);margin-top:2px}

  .reg-refund-list{max-height:380px;overflow-y:auto;margin:-4px 0 14px;padding:4px 0}
  .reg-refund-row{
    display:grid;grid-template-columns:auto 1fr auto auto;gap:12px;align-items:center;
    padding:10px 12px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);
    margin-bottom:6px
  }
  .reg-refund-row.disabled{opacity:.4}
  .reg-refund-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--ia-accent)}
  .reg-refund-row .name{font-size:13px;font-weight:500}
  .reg-refund-row .meta{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .reg-refund-row .qty-input{
    width:60px;padding:5px 8px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-family:inherit;text-align:center
  }
  .reg-refund-row .qty-input:focus{outline:none;border-color:var(--ia-accent)}
  .reg-refund-row .qty-input:disabled{opacity:.4;cursor:not-allowed}
  .reg-refund-row .total{font-size:13px;font-weight:600;text-align:right;min-width:70px}

  .reg-cart-section-label{
    font-size:10px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.08em;
    font-weight:600;padding:8px 4px 4px;border-top:0.5px solid var(--ia-border);
    margin-top:8px
  }
  .reg-cart-section-label:first-child{border-top:none;margin-top:0}
  .reg-cart-section-label.refund{color:#F09595}

  .reg-line.refund-line{background:rgba(226,75,74,.04)}
  .reg-line.refund-line .total{color:#F09595}
  .reg-line.refund-line .meta{color:#F09595;opacity:.7}
  .reg-cust-results .row{padding:10px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border)}
  .reg-cust-results .row:hover{background:var(--ia-hover)}
  .reg-cust-results .row:last-child{border-bottom:none}

  .reg-drafts-banner{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:11px 14px 11px 13px;
    background:var(--ia-accent-soft);
    border:0.5px solid var(--ia-border);
    border-left:3px solid var(--ia-accent);
    border-radius:var(--ia-r-md);margin-bottom:14px;font-size:13px;cursor:pointer;
    transition:filter var(--ia-t)
  }
  .reg-drafts-banner:hover{filter:brightness(1.08)}
  .reg-drafts-banner .label{color:var(--ia-text);font-weight:500}
  .reg-drafts-banner .cta{font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;font-weight:500}

  .reg-save-status{
    font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;
    margin-bottom:8px;height:14px;line-height:14px;
    transition:opacity var(--ia-t);opacity:0
  }
  .reg-save-status.visible{opacity:1}

  .reg-drafts-list{max-height:380px;overflow-y:auto;margin:-4px -4px 14px;padding:4px}
  .reg-draft-row{
    display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;
    padding:12px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);margin-bottom:8px
  }
  .reg-draft-row .meta-line{font-size:12px;color:var(--ia-text-dim);margin-top:2px}
  .reg-draft-row .total{font-size:14px;font-weight:600;text-align:right;min-width:62px}
  .reg-draft-row .actions{display:flex;gap:6px}
  .reg-draft-row .btn-resume{padding:6px 12px;background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:var(--ia-r-sm);font-size:12px;font-weight:500;font-family:inherit;cursor:pointer}
  .reg-draft-row .btn-resume:hover{filter:brightness(.93)}
  .reg-draft-row .btn-discard{padding:6px 10px;background:transparent;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer}
  .reg-draft-row .btn-discard:hover{color:var(--reg-danger);border-color:var(--reg-danger)}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Register</h1>
    <p class="ia-page-subtitle">Walk-in sales and retail checkouts.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link active">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
</div>

<div class="reg-page">

  <div class="reg-grid">

    <div class="reg-panel">
      <input type="text" class="reg-search" id="searchInput" placeholder="Search products and services…" autocomplete="off">

      <div class="reg-tabs">
        <button type="button" class="reg-tab active" data-type="all">All</button>
        <button type="button" class="reg-tab" data-type="product">Products</button>
        <button type="button" class="reg-tab" data-type="service">Services</button>
      </div>

      <div class="reg-hint" id="regHint" style="display:none">
        <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
        <span><kbd>↵</kbd> add</span>
        <span><kbd>esc</kbd> clear</span>
      </div>

      <div id="resultsArea">
        <div class="reg-empty">Type to search products and services.</div>
      </div>

      <button type="button" class="reg-open-item" id="addOpenItemBtn">+ Add custom item</button>
    </div>

    <div class="reg-panel">
      <div id="errBanner" class="reg-err" style="display:none"></div>

      <div id="saveStatus" class="reg-save-status"></div>

      <div id="draftsBanner" class="reg-drafts-banner" style="display:none">
        <span class="label" id="draftsBannerLabel"></span>
        <span class="cta">View →</span>
      </div>

      <div id="customerSlot">
        <button type="button" class="reg-attach" id="attachCustBtn">+ Attach customer</button>
      </div>

      <div class="reg-lines" id="cartLines">
        <div class="reg-empty">Cart is empty.</div>
      </div>

      <div class="reg-totals">
        <div class="reg-totals-row"><span>Subtotal</span><span id="subVal">$0.00</span></div>
        <div class="reg-totals-row" id="discountRow" style="display:none"><span>Discount</span><span id="discVal">-$0.00</span></div>
        <div class="reg-totals-row"><span>Tax</span><span id="taxVal">$0.00</span></div>
        <div class="reg-totals-row" id="surchargeRow" style="display:none"><span id="surchLabel">Surcharge</span><span id="surchVal">$0.00</span></div>
        <div class="reg-totals-row" id="tipRow" style="display:none"><span>Tip</span><span id="tipVal">$0.00</span></div>
        <div class="reg-totals-row grand"><span>Total</span><span id="totalVal">$0.00</span></div>
      </div>

      <div class="reg-pay-row">
        <button type="button" class="reg-quote-btn" id="quoteBtn" disabled>Save quote</button>
        <button type="button" class="reg-pay" id="payBtn" disabled>Collect payment</button>
      </div>
    </div>

  </div>

</div>

<div class="reg-modal-bg" id="refundTenderModal">
  <div class="reg-modal">
    <h2>Refund to customer</h2>
    <div class="lede" id="refundTenderLede">How is the refund being given?</div>
    <div class="reg-tender-grid">
      <button type="button" class="reg-tender-btn" data-refund-tender="card">Refund to card</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="cash">Cash from drawer</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="check">Check</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="store_credit">Store credit</button>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundTenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundTenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="tenderModal">
  <div class="reg-modal">
    <h2>Choose tender</h2>
    <div class="lede">How is the customer paying?</div>
    <div class="reg-tender-grid">
      <button type="button" class="reg-tender-btn" data-tender="cash">Cash</button>
      <button type="button" class="reg-tender-btn" data-tender="card">Card</button>
      <button type="button" class="reg-tender-btn" data-tender="check">Check</button>
      <button type="button" class="reg-tender-btn" data-tender="store_credit">Store credit</button>
      <button type="button" class="reg-tender-btn" data-tender="mark_paid">No tender (already paid)</button>
    </div>
    <div id="tenderRefRow" style="display:none;margin-bottom:14px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Reference (optional)</label>
      <input type="text" id="tenderRefInput" placeholder="Check #, last 4 of card, etc.">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="tenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="tenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="tipModal">
  <div class="reg-modal">
    <h2>Add tip?</h2>
    <div class="lede">Optional. Choose an amount or skip.</div>
    <div class="reg-tip-grid" id="tipGrid"></div>
    <div class="reg-tip-custom">
      <input type="text" id="tipCustomInput" placeholder="Custom amount">
      <button type="button" class="reg-btn-secondary" id="tipClearBtn" style="padding:10px 14px;flex:0">Clear</button>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="tipSkipBtn">Skip tip</button>
      <button type="button" class="reg-btn-primary" id="tipConfirmBtn">Add tip & continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="openItemModal">
  <div class="reg-modal">
    <h2>Custom item</h2>
    <div class="lede">For one-off items not in inventory.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Description</label>
      <input type="text" id="openItemName" placeholder="What is it?">
    </div>
    <div style="margin-bottom:6px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Price</label>
      <input type="text" id="openItemPrice" placeholder="0.00" inputmode="decimal">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="openItemModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="openItemAddBtn">Add to cart</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="customerModal">
  <div class="reg-modal">
    <h2>Attach customer</h2>
    <div style="margin-bottom:12px;position:relative">
      <input type="text" id="customerSearchInput" placeholder="Name, email, or phone" autocomplete="off">
      <div class="reg-cust-results" id="customerResults" style="display:none"></div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="customerModal">Cancel</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="confirmModal" style="z-index:1100">
  <div class="reg-modal" style="max-width:380px">
    <h2 id="confirmTitle">Are you sure?</h2>
    <div class="lede" id="confirmMessage"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="confirmCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="confirmOkBtn">Confirm</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="draftsModal">
  <div class="reg-modal" style="max-width:560px">
    <h2>Open drafts</h2>
    <div class="lede">Carts saved at this location.</div>
    <div class="reg-drafts-list" id="draftsList"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="draftsModal" style="flex:1">Close</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="quoteModal">
  <div class="reg-modal">
    <h2>Save as quote</h2>
    <div class="lede">The customer can come back later to pick up where they left off.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Notes (optional)</label>
      <input type="text" id="quoteNotesInput" placeholder="Anything the customer should know">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="quoteModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="quoteSaveBtn">Save quote</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="refundModal">
  <div class="reg-modal" style="max-width:600px">
    <h2>Add refund items</h2>
    <div class="lede" id="refundModalLede">Select items to refund.</div>
    <div class="reg-refund-list" id="refundList"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundAddBtn" disabled>Add to transaction</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="receiptModal">
  <div class="reg-modal reg-receipt">
    <h2>Sale complete</h2>
    <div class="num" id="receiptNum"></div>
    <div class="total" id="receiptTotal"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-primary" id="receiptNewSale">New sale</button>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
const ROUTES = {
  search:      @json(route('tenant.register.search')),
  storeSale:   @json(route('tenant.register.sales.store')),
  storeDraft:  @json(route('tenant.register.drafts.store')),
  listDrafts:  @json(route('tenant.register.drafts.index')),
  draftBase:   @json(url('/admin/register/drafts')),
  commitDraft: @json(url('/admin/register/drafts')),
  storeQuote:  @json(route('tenant.register.quotes.store')),
  quotesIndex: @json(route('tenant.register.quotes.index')),
  lookupSale:  @json(route('tenant.register.lookup-sale')),
  commitTxn:   @json(route('tenant.register.transactions.store')),
};
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const CFG = {
  taxRate:        {{ $taxRate ?? 0 }},
  taxLabel:       @json($taxLabel ?? ''),
  tipsEnabled:    {{ $tipsConfig['enabled'] ? 'true' : 'false' }},
  tipMethod:      @json($tipsConfig['method'] ?? null),
  tipOptions:     @json($tipsConfig['options'] ?? []),
  tipAllowCustom: {{ $tipsConfig['allow_custom'] ? 'true' : 'false' }},
  surchargeOn:    {{ $surchargeConfig['enabled'] ? 'true' : 'false' }},
  surchargePct:   {{ $surchargeConfig['percent'] ?? 0 }},
  surchargeLabel: @json($surchargeConfig['label'] ?? 'Surcharge'),
};

// Reusable confirm dialog. Returns a promise that resolves true/false.
// Usage: const ok = await confirmDialog('Replace cart?', 'Replace');
function confirmDialog(message, confirmLabel = 'Confirm', title = 'Are you sure?') {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    const okBtn = document.getElementById('confirmOkBtn');
    const cancelBtn = document.getElementById('confirmCancelBtn');
    okBtn.textContent = confirmLabel;
    const cleanup = (result) => {
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      closeModal('confirmModal');
      resolve(result);
    };
    const onOk = () => cleanup(true);
    const onCancel = () => cleanup(false);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    openModal('confirmModal');
  });
}

const cart = {
  draft_id: null,
  customer: null,
  items: [],            // new-sale lines
  refund_lines: [],     // refund lines, each: {key, original_sale_id, original_item_id, name, qty, price_cents, type}
  refund_meta: null,    // {original_sale_id, original_sale_number, refund_method} — set when first refund line added
  tipCents: 0, discountCents: 0,
  payment_method: null, payment_reference: null,
};
const fmt = (cents) => '$' + (cents / 100).toFixed(2);
const fmtNeg = (cents) => '-$' + (cents / 100).toFixed(2);
let lineKey = 0;

// --- Draft auto-save infrastructure ---
// Cart changes debounce a save to /register/drafts. First save creates the
// draft and stores its id on cart.draft_id. Subsequent saves include the id
// to update in place. Mark Paid awaits any pending save, then commits.
const DRAFT_DEBOUNCE_MS = 1500;
let draftSaveTimer = null;
let draftSaveInFlight = null; // Promise of currently-firing save, or null.

function buildDraftPayload() {
  return {
    id: cart.draft_id,
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    items: cart.items.map(i => {
      const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };
}

async function fireDraftSave() {
  // If a save is already in flight, wait for it and re-queue this one.
  // Last-write-wins: the next save will include the latest cart state.
  if (draftSaveInFlight) {
    await draftSaveInFlight;
  }
  const payload = buildDraftPayload();
  draftSaveInFlight = (async () => {
    try {
      const res = await fetch(ROUTES.storeDraft, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.ok && data.draft_id) {
        cart.draft_id = data.draft_id;
        setSaveStatus('saved');
      }
    } catch (e) {
      // Silent failure on auto-save. Cart still works locally; commit will
      // fall back to the storeSale path if draft_id is still null.
      console.warn('[draft] save failed', e);
      setSaveStatus('idle');
    } finally {
      draftSaveInFlight = null;
    }
  })();
  return draftSaveInFlight;
}

function queueDraftSave() {
  // Empty cart with no existing draft — nothing to save.
  if (!cart.items.length && !cart.draft_id) return;
  clearTimeout(draftSaveTimer);
  draftSaveTimer = setTimeout(fireDraftSave, DRAFT_DEBOUNCE_MS);
  setSaveStatus('pending');
}

let saveStatusTimer = null;
function setSaveStatus(state) {
  const el = document.getElementById('saveStatus');
  if (!el) return;
  clearTimeout(saveStatusTimer);
  if (state === 'pending' || state === 'saving') {
    el.textContent = 'Saving…';
    el.classList.add('visible');
  } else if (state === 'saved') {
    el.textContent = 'Saved';
    el.classList.add('visible');
    saveStatusTimer = setTimeout(() => el.classList.remove('visible'), 1500);
  } else {
    el.classList.remove('visible');
  }
}

async function flushDraftSave() {
  // Cancel any pending debounce, fire immediately, await any in-flight save.
  clearTimeout(draftSaveTimer);
  draftSaveTimer = null;
  if (cart.items.length || cart.draft_id) {
    await fireDraftSave();
  }
  if (draftSaveInFlight) await draftSaveInFlight;
}

const searchInput = document.getElementById('searchInput');
const resultsArea = document.getElementById('resultsArea');
let searchType = 'all';
let searchTimer = null;

document.querySelectorAll('.reg-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    searchType = tab.dataset.type;
    runSearch();
  });
});
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(runSearch, 250);
});

// Detect sale-number pattern: S-YYYYMMDD-NNN (case-insensitive, optional spaces around dashes)
function looksLikeSaleNumber(q) {
  return /^s[\s-]*\d{8}[\s-]*\d{1,4}$/i.test(q.trim());
}
function normalizeSaleNumber(q) {
  return q.trim().toUpperCase().replace(/\s+/g, '').replace(/^S(\d)/, 'S-$1').replace(/(\d{8})(\d)/, '$1-$2');
}

async function runSearch() {
  const q = searchInput.value.trim();
  if (q.length < 2) {
    resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
    return;
  }

  // Sale-number lookup runs in parallel with regular search.
  let refundResult = null;
  if (looksLikeSaleNumber(q)) {
    try {
      const lookupUrl = new URL(ROUTES.lookupSale, window.location.origin);
      lookupUrl.searchParams.set('sale_number', normalizeSaleNumber(q));
      const r = await fetch(lookupUrl, {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (d.ok) refundResult = d.sale;
    } catch (e) { /* silent — fall through to regular search */ }
  }

  try {
    const url = new URL(ROUTES.search, window.location.origin);
    url.searchParams.set('q', q);
    url.searchParams.set('type', searchType);
    const res = await fetch(url, {headers: {'Accept': 'application/json'}});
    const data = await res.json();
    renderResults(data, refundResult);
  } catch (e) {
    resultsArea.innerHTML = '<div class="reg-empty">Search failed.</div>';
  }
}

// Keyboard nav state
let highlighted = 0;
let visibleResults = [];

function renderResults(data, refundResult) {
  let html = '';
  visibleResults = [];

  // If a refund-eligible sale was matched, render it first as a distinctive card.
  if (refundResult) {
    html += '<div class="reg-refund-result" data-refund-sale="' + refundResult.id + '">';
    html +=   '<div class="label">Refund from sale</div>';
    html +=   '<div class="name">#' + escapeHtml(refundResult.sale_number) + '</div>';
    html +=   '<div class="meta">' + (refundResult.customer ? escapeHtml(refundResult.customer) + ' · ' : '');
    html +=     fmt(refundResult.total_cents) + ' · ' + (refundResult.items.length) + ' items</div>';
    html += '</div>';
  }

  if (data.products && data.products.length) {
    html += '<div class="reg-results-section"><h3>Products</h3>';
    data.products.forEach(p => {
      visibleResults.push({type:'product',source_id:p.id,name:p.name,price_cents:p.price_cents,is_taxable:p.is_taxable});
      const idx = visibleResults.length - 1;
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(p.name)}</div><div class="meta">${escapeHtml(p.sku || '')}</div></div>
        <div class="price">${fmt(p.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (data.services && data.services.length) {
    html += '<div class="reg-results-section mouse-defer"><h3>Services</h3>';
    data.services.forEach(s => {
      visibleResults.push({type:'service',source_id:s.id,name:s.name,price_cents:s.price_cents,is_taxable:true});
      const idx = visibleResults.length - 1;
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(s.name)}</div><div class="meta">${s.duration_minutes || 0} min</div></div>
        <div class="price">${fmt(s.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (!html) html = '<div class="reg-empty">No matches.</div>';
  resultsArea.innerHTML = html;

  // Show/hide keyboard hint based on whether results exist
  const hint = document.getElementById('regHint');
  hint.style.display = visibleResults.length ? '' : 'none';

  // Reset highlight to first row
  if (highlighted >= visibleResults.length) highlighted = 0;
  applyHighlight();

  // Click handler — add the row's item, then clear search and refocus (same as Enter)
  resultsArea.querySelectorAll('[data-i]').forEach(row => {
    row.addEventListener('click', () => {
      const i = parseInt(row.dataset.i, 10);
      addToCart(visibleResults[i]);
      searchInput.value = '';
      visibleResults = [];
      highlighted = 0;
      resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
      document.getElementById('regHint').style.display = 'none';
      searchInput.focus();
    });
  });

  // Wire refund-result click → open picker modal.
  const refundEl = resultsArea.querySelector('[data-refund-sale]');
  if (refundEl) {
    // Stash the refund result on the element via dataset for the click handler.
    refundEl.addEventListener('click', () => {
      // Re-fetch the sale to get fresh refundable quantities (in case anything changed).
      const saleId = refundEl.dataset.refundSale;
      openRefundPicker(saleId);
    });
  }

  // Wire mouse-active class to the search panel's results sections
  resultsArea.querySelectorAll('.reg-results-section').forEach(section => {
    section.addEventListener('mouseenter', () => section.classList.add('mouse-active'));
    section.addEventListener('mouseleave', () => section.classList.remove('mouse-active'));
  });
}

function applyHighlight() {
  resultsArea.querySelectorAll('.reg-row').forEach((row, i) => {
    if (parseInt(row.dataset.i, 10) === highlighted) {
      row.classList.add('highlighted');
    } else {
      row.classList.remove('highlighted');
    }
  });
}

// Keyboard navigation on the search input
searchInput.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (highlighted < visibleResults.length - 1) { highlighted++; applyHighlight(); }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (highlighted > 0) { highlighted--; applyHighlight(); }
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (visibleResults[highlighted]) {
      addToCart(visibleResults[highlighted]);
      // Clear search and refocus for next item
      searchInput.value = '';
      visibleResults = [];
      highlighted = 0;
      resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
      document.getElementById('regHint').style.display = 'none';
      searchInput.focus();
    }
  } else if (e.key === 'Escape') {
    searchInput.value = '';
    visibleResults = [];
    highlighted = 0;
    resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
    document.getElementById('regHint').style.display = 'none';
  }
});

function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

function addToCart(item) {
  cart.items.push({
    key: ++lineKey, type: item.type, source_id: item.source_id,
    name: item.name, price_cents: item.price_cents, qty: 1,
    is_taxable: item.is_taxable !== false,
  });
  renderCart();
  queueDraftSave();
}
function removeLine(key) {
  cart.items = cart.items.filter(i => i.key !== key);
  renderCart();
  queueDraftSave();
}
function updateQty(key, qty) {
  const n = parseFloat(qty);
  if (isNaN(n) || n <= 0) { removeLine(key); return; }
  const line = cart.items.find(i => i.key === key);
  if (line) line.qty = n;
  renderCart();
  queueDraftSave();
}

function renderCart() {
  const lines = document.getElementById('cartLines');
  const totalCount = cart.items.length + cart.refund_lines.length;
  if (totalCount === 0) {
    lines.innerHTML = '<div class="reg-empty">Cart is empty.</div>';
    document.getElementById('payBtn').disabled = true;
    document.getElementById('quoteBtn').disabled = true;
  } else {
    let html = '';

    // Refund section — render first (visually on top) when present.
    if (cart.refund_lines.length > 0) {
      html += '<div class="reg-cart-section-label refund">Returning to customer · sale #' +
        escapeHtml(cart.refund_meta?.original_sale_number ?? '') + '</div>';
      html += cart.refund_lines.map(r => `
        <div class="reg-line refund-line">
          <div>
            <div class="name">${escapeHtml(r.name)}</div>
            <div class="meta">refund · ${r.qty} × ${fmt(r.price_cents)}</div>
          </div>
          <div></div>
          <div style="display:flex;align-items:center;gap:6px">
            <span class="total">-${fmt(Math.round(r.price_cents * r.qty))}</span>
            <button type="button" class="remove" data-remove-refund="${r.key}">×</button>
          </div>
        </div>
      `).join('');
    }

    // New-sale section
    if (cart.items.length > 0) {
      if (cart.refund_lines.length > 0) {
        html += '<div class="reg-cart-section-label">Adding to cart</div>';
      }
      html += cart.items.map(i => `
        <div class="reg-line">
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
    }

    lines.innerHTML = html;
    document.getElementById('payBtn').disabled = false;
    document.getElementById('quoteBtn').disabled = false;
  }
  lines.querySelectorAll('[data-key]').forEach(input => {
    input.addEventListener('change', () => updateQty(parseInt(input.dataset.key, 10), input.value));
  });
  lines.querySelectorAll('[data-remove]').forEach(btn => {
    btn.addEventListener('click', () => removeLine(parseInt(btn.dataset.remove, 10)));
  });
  lines.querySelectorAll('[data-remove-refund]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = parseInt(btn.dataset.removeRefund, 10);
      cart.refund_lines = cart.refund_lines.filter(r => r.key !== key);
      if (cart.refund_lines.length === 0) cart.refund_meta = null;
      renderCart();
    });
  });

  const slot = document.getElementById('customerSlot');
  if (cart.customer) {
    slot.innerHTML = `
      <div class="reg-cust">
        <div><span class="name">${escapeHtml(cart.customer.name)}</span></div>
        <span class="clear" id="clearCust">Remove</span>
      </div>`;
    document.getElementById('clearCust').addEventListener('click', () => {
      cart.customer = null;
      renderCart();
      queueDraftSave();
    });
    // Customer is now attached — clear any prior warning.
    if (customerWarningActive) applyCustomerWarning(false);
  } else {
    slot.innerHTML = `<button type="button" class="reg-attach" id="attachCustBtn">+ Attach customer</button>`;
    document.getElementById('attachCustBtn').addEventListener('click', openCustomerModal);
    // Re-apply warning class if a prior quote attempt set it.
    if (customerWarningActive) applyCustomerWarning(true);
  }
  renderTotals();
}

function calcSubtotal() { return cart.items.reduce((sum, i) => sum + Math.round(i.price_cents * i.qty), 0); }
function calcRefundSubtotal() {
  return cart.refund_lines.reduce((sum, r) => sum + Math.round(r.price_cents * r.qty), 0);
}
function calcTax() {
  if (!CFG.taxRate) return 0;
  let taxable = 0;
  cart.items.forEach(i => { if (i.is_taxable) taxable += Math.round(i.price_cents * i.qty); });
  return Math.round(taxable * (CFG.taxRate / 100));
}
function calcSurcharge() {
  if (!CFG.surchargeOn) return 0;
  if (cart.payment_method !== 'card') return 0;
  return Math.round(calcSubtotal() * (CFG.surchargePct / 100));
}

function renderTotals() {
  const sub = calcSubtotal();
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;
  // Net total = new sale total - refund subtotal. May be negative.
  const total = (sub - disc + tax + surch + tip) - refundSub;

  document.getElementById('subVal').textContent = fmt(sub);
  document.getElementById('taxVal').textContent = fmt(tax);
  document.getElementById('totalVal').textContent = fmt(total);

  if (disc > 0) { document.getElementById('discountRow').style.display = ''; document.getElementById('discVal').textContent = fmtNeg(disc); }
  else { document.getElementById('discountRow').style.display = 'none'; }
  if (surch > 0) { document.getElementById('surchargeRow').style.display = ''; document.getElementById('surchLabel').textContent = CFG.surchargeLabel; document.getElementById('surchVal').textContent = fmt(surch); }
  else { document.getElementById('surchargeRow').style.display = 'none'; }
  if (tip > 0) { document.getElementById('tipRow').style.display = ''; document.getElementById('tipVal').textContent = fmt(tip); }
  else { document.getElementById('tipRow').style.display = 'none'; }
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
      box.innerHTML = '<div class="row" style="color:var(--ia-text-dim)">No matches.</div>';
      box.style.display = '';
      return;
    }
    box.innerHTML = data.customers.map(c => `
      <div class="row" data-cust='${JSON.stringify(c)}'>
        <div style="font-weight:500">${escapeHtml(c.name || '(no name)')}</div>
        <div style="font-size:11px;color:var(--ia-text-dim)">${escapeHtml(c.email || c.phone || '')}</div>
      </div>
    `).join('');
    box.querySelectorAll('[data-cust]').forEach(row => {
      row.addEventListener('click', () => {
        cart.customer = JSON.parse(row.dataset.cust);
        closeModal('customerModal');
        renderCart();
        queueDraftSave();
      });
    });
    box.style.display = '';
  } catch (e) {
    box.innerHTML = '<div class="row" style="color:#F09595">Search failed.</div>';
    box.style.display = '';
  }
}

// --- Save as Quote flow ---
let customerWarningActive = false;

function applyCustomerWarning(on) {
  customerWarningActive = on;
  const slot = document.getElementById('customerSlot');
  const cust = slot.querySelector('.reg-cust');
  const attach = slot.querySelector('.reg-attach');
  if (on) {
    if (cust) cust.classList.add('warning');
    if (attach) attach.classList.add('warning');
  } else {
    if (cust) cust.classList.remove('warning');
    if (attach) attach.classList.remove('warning');
  }
}

document.getElementById('quoteBtn').addEventListener('click', async () => {
  if (cart.refund_lines.length > 0) {
    showError('Quotes can\'t include refund items. Remove the refund lines or commit the transaction.');
    return;
  }
  if (!cart.customer) {
    applyCustomerWarning(true);
    const ok = await confirmDialog(
      'Quotes need a customer attached so you can find and follow up later.',
      'Attach customer',
      'Customer required'
    );
    if (ok) openCustomerModal();
    return;
  }
  // Customer is attached — clear any prior warning state and open quote modal.
  applyCustomerWarning(false);
  document.getElementById('quoteNotesInput').value = '';
  openModal('quoteModal');
  setTimeout(() => document.getElementById('quoteNotesInput').focus(), 50);
});

document.getElementById('quoteSaveBtn').addEventListener('click', async () => {
  const btn = document.getElementById('quoteSaveBtn');
  btn.disabled = true;

  // Make sure any pending draft save lands first — same flush pattern as commit.
  await flushDraftSave();

  const payload = {
    id: cart.draft_id,
    customer_id: cart.customer.id,
    notes: document.getElementById('quoteNotesInput').value.trim() || null,
    tip_cents: cart.tipCents,
    items: cart.items.map(i => {
      const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };

  try {
    const res = await fetch(ROUTES.storeQuote, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      showError(data.error || 'Could not save the quote.');
      closeModal('quoteModal');
      return;
    }
    // Success — clear the cart so register is ready for the next sale.
    closeModal('quoteModal');
    cart.draft_id = null;
    cart.customer = null;
    cart.items = [];
    cart.tipCents = 0;
    cart.discountCents = 0;
    cart.payment_method = null;
    cart.payment_reference = null;
    renderCart();
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error saving quote.');
    closeModal('quoteModal');
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('payBtn').addEventListener('click', () => {
  // Net total decides which path we take.
  const sub = calcSubtotal();
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;
  const net = (sub - disc + tax + surch + tip) - refundSub;

  if (net === 0 && cart.refund_lines.length > 0) {
    // Even exchange — skip tender, commit immediately.
    // No money changes hands, but the payload still requires a payment method
    // for the validator. 'even_exchange' is a sentinel that the controller treats
    // the same as 'mark_paid' (no actual tender).
    cart.payment_method = 'even_exchange';
    commitTransaction({ even_exchange: true });
    return;
  }

  if (net < 0) {
    // Refund-direction transaction.
    cart.payment_method = null;
    document.getElementById('refundTenderConfirmBtn').disabled = true;
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('refundTenderLede').textContent =
      'Customer is owed ' + fmt(Math.abs(net)) + '. How is the refund being given?';
    openModal('refundTenderModal');
    return;
  }

  // Standard sale-direction tender flow (net > 0).
  cart.payment_method = null;
  cart.payment_reference = null;
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderRefInput').value = '';
  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  openModal('tenderModal');
});

// Refund-tender modal handlers
document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.refundTender;
    document.getElementById('refundTenderConfirmBtn').disabled = false;
  });
});

document.getElementById('refundTenderConfirmBtn').addEventListener('click', () => {
  closeModal('refundTenderModal');
  // Refund-direction commits skip the tip step entirely.
  commitTransaction({});
});

document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
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
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
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
    btn.className = 'reg-tip-btn';
    btn.textContent = label;
    btn.addEventListener('click', () => {
      document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
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
    document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
  }
});
document.getElementById('tipClearBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
});
document.getElementById('tipSkipBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  closeModal('tipModal');
  commitTransaction({});
});
document.getElementById('tipConfirmBtn').addEventListener('click', () => {
  closeModal('tipModal');
  commitTransaction({});
});

async function commitTransaction(opts = {}) {
  document.getElementById('payBtn').disabled = true;
  document.getElementById('errBanner').style.display = 'none';

  // Make sure any pending or in-flight draft save lands before commit.
  await flushDraftSave();

  const hasRefund = cart.refund_lines.length > 0;
  const hasNewSale = cart.items.length > 0;

  try {
    let url, payload;

    if (hasRefund) {
      // Mixed or pure-refund transaction — use the new endpoint that handles both.
      url = ROUTES.commitTxn;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        items: hasNewSale ? cart.items.map(serializeLine) : [],
        refund: {
          original_sale_id: cart.refund_meta.original_sale_id,
          item_ids: cart.refund_lines.map(r => r.original_item_id),
          refund_method: cart.payment_method,
        },
      };
    } else if (cart.draft_id) {
      // Draft-backed pure sale — promote draft to paid (existing path).
      url = ROUTES.commitDraft + '/' + cart.draft_id + '/commit';
      payload = {
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        tip_cents: cart.tipCents,
        customer_id: cart.customer ? cart.customer.id : null,
      };
    } else {
      // Fallback path — pure sale, no draft, send full cart.
      url = ROUTES.storeSale;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        discount_cents: cart.discountCents,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        items: cart.items.map(serializeLine),
      };
    }

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not complete the transaction.'); return; }
    showReceipt(data);
  } catch (e) {
    showError('Network error. Please try again.');
  } finally {
    document.getElementById('payBtn').disabled = (cart.items.length === 0 && cart.refund_lines.length === 0);
  }
}

function serializeLine(i) {
  const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
  if (i.type === 'product') out.inventory_item_id = i.source_id;
  if (i.type === 'service') out.service_id = i.source_id;
  if (i.type === 'open_item') {
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
  }
  return out;
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

document.getElementById('receiptNewSale').addEventListener('click', async () => {
  cart.draft_id = null;
  cart.customer = null;
  cart.items = [];
  cart.refund_lines = [];
  cart.refund_meta = null;
  cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payment_reference = null;
  closeModal('receiptModal');
  renderCart();
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  refreshDraftsBanner(await loadDrafts());
});

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('[data-close-modal]').forEach(btn => {
  btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
});

// --- Drafts banner / resume / discard ---
async function loadDrafts() {
  try {
    const res = await fetch(ROUTES.listDrafts, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    return data.drafts || [];
  } catch (e) {
    return [];
  }
}

function refreshDraftsBanner(drafts) {
  const banner = document.getElementById('draftsBanner');
  // Filter out the current cart's own draft from the count.
  const others = drafts.filter(d => d.id !== cart.draft_id);
  if (!others.length) { banner.style.display = 'none'; return; }
  const word = others.length === 1 ? 'draft' : 'drafts';
  document.getElementById('draftsBannerLabel').textContent =
    others.length + ' open ' + word + ' at this location';
  banner.style.display = '';
}

function fmtAge(iso) {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  const now = Date.now();
  const mins = Math.floor((now - then) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return mins + 'm ago';
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return hrs + 'h ago';
  return Math.floor(hrs / 24) + 'd ago';
}

function renderDraftsList(drafts) {
  const list = document.getElementById('draftsList');
  const others = drafts.filter(d => d.id !== cart.draft_id);
  if (!others.length) {
    list.innerHTML = '<div class="reg-empty">No other open drafts.</div>';
    return;
  }
  list.innerHTML = others.map(d => {
    const itemWord = d.item_count === 1 ? 'item' : 'items';
    const meta = [
      d.item_count + ' ' + itemWord,
      d.customer || 'no customer',
      d.started_by ? 'by ' + d.started_by : null,
      fmtAge(d.updated_at),
    ].filter(Boolean).join(' · ');
    return '<div class="reg-draft-row" data-id="' + d.id + '">' +
      '<div>' +
        '<div style="font-weight:500">' + escapeHtml(d.customer || 'Walk-in') + '</div>' +
        '<div class="meta-line">' + escapeHtml(meta) + '</div>' +
      '</div>' +
      '<div class="total">' + fmt(d.total_cents) + '</div>' +
      '<div class="actions">' +
        '<button type="button" class="btn-resume" data-resume="' + d.id + '">Resume</button>' +
        '<button type="button" class="btn-discard" data-discard="' + d.id + '">Discard</button>' +
      '</div>' +
    '</div>';
  }).join('');
  list.querySelectorAll('[data-resume]').forEach(btn => {
    btn.addEventListener('click', () => resumeDraft(btn.dataset.resume));
  });
  list.querySelectorAll('[data-discard]').forEach(btn => {
    btn.addEventListener('click', () => discardDraftFromList(btn.dataset.discard));
  });
}

document.getElementById('draftsBanner').addEventListener('click', async () => {
  const drafts = await loadDrafts();
  renderDraftsList(drafts);
  openModal('draftsModal');
});

async function resumeDraft(id) {
  if (cart.items.length > 0) {
    const ok = await confirmDialog(
      'Your current cart will be replaced with this draft.',
      'Replace cart',
      'Replace current cart?'
    );
    if (!ok) return;
  }
  try {
    const res = await fetch(ROUTES.draftBase + '/' + id, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not load draft.'); closeModal('draftsModal'); return; }
    // Cancel any pending save for the OLD cart before we overwrite state.
    clearTimeout(draftSaveTimer);
    draftSaveTimer = null;
    cart.draft_id = data.draft.id;
    cart.customer = data.draft.customer;
    cart.tipCents = data.draft.tip_cents || 0;
    cart.items = (data.draft.items || []).map(i => ({
      key: ++lineKey,
      type: i.type,
      source_id: i.source_id,
      name: i.name,
      price_cents: i.price_cents,
      qty: i.qty,
      is_taxable: i.is_taxable,
    }));
    closeModal('draftsModal');
    renderCart();
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error loading draft.');
    closeModal('draftsModal');
  }
}

async function discardDraftFromList(id) {
  const ok = await confirmDialog(
    'This draft will be permanently deleted.',
    'Discard draft',
    'Discard this draft?'
  );
  if (!ok) return;
  try {
    const res = await fetch(ROUTES.draftBase + '/' + id, {
      method: 'DELETE',
      headers: {'Accept':'application/json', 'X-CSRF-TOKEN': CSRF},
    });
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not discard draft.'); return; }
    // If we just discarded the cart's own draft, clear it too.
    if (cart.draft_id === id) {
      cart.draft_id = null;
      cart.items = [];
      cart.customer = null;
      cart.tipCents = 0;
      renderCart();
    }
    const drafts = await loadDrafts();
    renderDraftsList(drafts);
    refreshDraftsBanner(drafts);
  } catch (e) {
    showError('Network error discarding draft.');
  }
}

renderCart();

// --- Refund picker ---
let refundPickerSale = null;  // the full sale object from lookupSale, kept while modal is open

async function openRefundPicker(saleId) {
  // We don't have a per-id endpoint yet; the sale_number-based lookup is what we have.
  // Re-trigger the search to get fresh data (cheap — last query is still in input).
  const q = searchInput.value.trim();
  if (!q || !looksLikeSaleNumber(q)) {
    showError('Could not load sale. Try searching the sale number again.');
    return;
  }
  try {
    const url = new URL(ROUTES.lookupSale, window.location.origin);
    url.searchParams.set('sale_number', normalizeSaleNumber(q));
    const r = await fetch(url, {headers: {'Accept': 'application/json'}});
    const d = await r.json();
    if (!d.ok) { showError(d.error || 'Sale not found.'); return; }
    refundPickerSale = d.sale;
    renderRefundPicker();
    openModal('refundModal');
  } catch (e) {
    showError('Network error loading sale.');
  }
}

function renderRefundPicker() {
  const sale = refundPickerSale;
  if (!sale) return;
  document.getElementById('refundModalLede').textContent =
    'Sale #' + sale.sale_number + (sale.customer ? ' · ' + sale.customer : '') + ' · ' + fmt(sale.total_cents);

  const list = document.getElementById('refundList');
  if (!sale.items.length) {
    list.innerHTML = '<div class="reg-empty">No items on this sale.</div>';
    return;
  }
  list.innerHTML = sale.items.map((it, idx) => {
    const disabled = it.remaining <= 0;
    const meta = disabled
      ? 'fully refunded'
      : (it.already_refunded > 0 ? it.already_refunded + ' of ' + it.quantity + ' already refunded · ' + it.remaining + ' available' : it.quantity + ' available');
    return '<div class="reg-refund-row ' + (disabled ? 'disabled' : '') + '" data-idx="' + idx + '">' +
      '<input type="checkbox" data-pick="' + idx + '" ' + (disabled ? 'disabled' : '') + '>' +
      '<div>' +
        '<div class="name">' + escapeHtml(it.name) + '</div>' +
        '<div class="meta">' + escapeHtml(meta) + '</div>' +
      '</div>' +
      '<input type="number" class="qty-input" data-qty="' + idx + '" min="0" max="' + it.remaining + '" step="1" value="' + it.remaining + '" ' + (disabled ? 'disabled' : '') + '>' +
      '<div class="total">' + fmt(it.unit_price_cents) + '</div>' +
    '</div>';
  }).join('');

  // Wire checkbox + qty change to update the Add button state.
  list.querySelectorAll('[data-pick]').forEach(cb => cb.addEventListener('change', updateRefundAddBtn));
  list.querySelectorAll('[data-qty]').forEach(inp => inp.addEventListener('input', updateRefundAddBtn));
  updateRefundAddBtn();
}

function updateRefundAddBtn() {
  const list = document.getElementById('refundList');
  let anyChecked = false;
  list.querySelectorAll('[data-pick]:checked').forEach(cb => {
    const idx = cb.dataset.pick;
    const qty = parseFloat(list.querySelector('[data-qty="' + idx + '"]').value);
    if (qty > 0) anyChecked = true;
  });
  document.getElementById('refundAddBtn').disabled = !anyChecked;
}

document.getElementById('refundAddBtn').addEventListener('click', () => {
  const sale = refundPickerSale;
  if (!sale) return;
  const list = document.getElementById('refundList');

  // If cart already has refund lines from a different sale, block.
  if (cart.refund_meta && cart.refund_meta.original_sale_id !== sale.id) {
    showError('Cart already has refund lines from a different sale. Discard or commit those first.');
    return;
  }

  list.querySelectorAll('[data-pick]:checked').forEach(cb => {
    const idx = parseInt(cb.dataset.pick, 10);
    const item = sale.items[idx];
    const qty = parseFloat(list.querySelector('[data-qty="' + idx + '"]').value);
    if (!qty || qty <= 0) return;
    cart.refund_lines.push({
      key: ++lineKey,
      original_sale_id:  sale.id,
      original_item_id:  item.id,
      type:              item.type,
      name:              item.name,
      qty:               qty,
      price_cents:       item.unit_price_cents,
    });
  });

  if (cart.refund_lines.length > 0 && !cart.refund_meta) {
    cart.refund_meta = {
      original_sale_id:    sale.id,
      original_sale_number: sale.sale_number,
      refund_method:       null,  // resolved at tender time
    };
  }

  closeModal('refundModal');
  refundPickerSale = null;
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  renderCart();
  searchInput.focus();
});

// On page load, populate the banner.
loadDrafts().then(refreshDraftsBanner);

// If we were redirected here from the Quotes page with ?resume=<id>,
// load that quote into the cart automatically.
(function () {
  const params = new URLSearchParams(window.location.search);
  const resumeId = params.get('resume');
  if (!resumeId) return;
  // Strip the param from the URL so a refresh doesn't re-trigger.
  const cleanUrl = window.location.pathname;
  window.history.replaceState({}, '', cleanUrl);
  // Reuse the existing resumeDraft path — it handles drafts and quotes both.
  resumeDraft(resumeId);
})();
</script>
@endpush
