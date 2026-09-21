@extends('layouts.tenant.app')

@php $gcCfg = \App\Services\Tenant\GiftCardService::config(tenant()); @endphp {{-- MARKER-GC-SETTINGS --}}

@php $pageTitle = 'Register'; @endphp

@push('styles')
<style>
  .reg-page { --reg-danger: #F09595; --reg-danger-bg: rgba(226,75,74,.15); }

  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* MARKER-REG-MOBILE ------------------------------------------------- */
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

  /* MARKER-REG-MOBILE — these three were inline on the <select>, which meant
     no media query could override them and the stage-3b mobile rule below
     silently did nothing. */
  .reg-tabs-bar .reg-picker-wrap,
  .reg-tabs-bar #registerPicker{margin-left:auto;max-width:220px;font-size:13px}

  @media (max-width: 760px){
    /* now reachable: its own full-width row under the tabs */
    .reg-tabs-bar .reg-picker-wrap,
  .reg-tabs-bar #registerPicker{
      display:block;width:100%;max-width:none;margin:8px 0 2px
    }
    /* the checkout banner stacks rather than wrapping around its button */
    #appointment-tray-banner{flex-wrap:wrap}
    #appointment-tray-banner > button{flex:1 1 100%}
  }

  /* MARKER-REGPICKER-ALIGN — the picker is an .ia-input in a flex row, so it
     stretched to the bar's full height and sat below the tab underline.
     Centre it and size it to the tab links instead. Scoped to the picker:
     .reg-tab-link needs the bar to stay stretch-aligned so its -0.5px bottom
     margin keeps the active underline on the border. */
  .reg-tabs-bar .reg-picker-wrap,
  .reg-tabs-bar #registerPicker{
    align-self:center;height:30px;padding:0 10px;line-height:1
  }

  /* MARKER-OFFLINE-SYNC stage 3b — mobile: picker on its own full-width row
     instead of floating beside wrapped tabs */
  @media (max-width: 760px) {
    .reg-tabs-bar .reg-picker-wrap,
  .reg-tabs-bar #registerPicker{
      order:99;flex:1 1 100%;max-width:none;margin:8px 0 2px;width:100%;
    }
    .reg-tabs-bar{row-gap:2px}
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  /* patch-96 layout — 50/50 split between item search and cart */
  .reg-grid {
    display:grid;grid-template-columns:1fr 1fr;gap:18px;
  }
  @media(max-width:1200px){ .reg-grid{grid-template-columns:1fr} }

  /* patch-100a oversell-actions — action row below oversold cart lines */
  .reg-oversell-actions {
    display: flex; gap: 8px; align-items: center;
    margin-top: 6px; flex-wrap: wrap;
  }
  .reg-oversell-btn {
    font-size: 11px; padding: 3px 10px;
    background: transparent;
    color: var(--ia-text);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-xs);
    cursor: pointer; transition: background 120ms ease;
  }
  .reg-oversell-btn:hover { background: var(--ia-hover); }
  .reg-oversell-pill {
    display: inline-block;
    font-size: 11px; padding: 3px 10px;
    background: rgba(99,153,34,0.12);
    color: #639922;
    border: 0.5px solid rgba(99,153,34,0.35);
    border-radius: var(--ia-r-xs);
    font-weight: 500;
  }
  /* MARKER-RESERVE-VISIBLE — held stock reads differently from missing stock. */
  .reg-stock-chip.is-held{
    color:#f5c451;
    border-color:rgba(245,196,81,.4);
  }

  /* MARKER-TENDER-LAYOUT ------------------------------------------------- */
  .reg-tender-modal{max-width:460px;padding:0;overflow:hidden}
  .tend-head{padding:18px 22px 16px;border-bottom:0.5px solid var(--ia-border)}
  .tend-eyebrow{display:flex;align-items:center;justify-content:space-between;
    font-size:11.5px;color:var(--ia-text-dim);letter-spacing:.02em}
  .tend-x{background:none;border:0;color:var(--ia-text-dim);font-size:20px;line-height:1;
    cursor:pointer;padding:0 2px}
  .tend-x:hover{color:var(--ia-text)}
  .tend-amount{font-size:34px;font-weight:700;letter-spacing:-.02em;margin-top:6px;
    font-variant-numeric:tabular-nums}
  .tend-sub{font-size:12.5px;color:var(--ia-text-dim);margin-top:2px}
  .tend-body{padding:16px 22px 4px;max-height:52vh;overflow-y:auto}
  .tend-label{font-size:11.5px;color:var(--ia-text-dim);margin:0 0 8px}
  .tend-primary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
  .tend-big{display:flex;align-items:center;justify-content:space-between;
    padding:15px 16px;font-size:14px;font-weight:600}
  .tend-dot{width:14px;height:14px;border-radius:50%;border:1.5px solid var(--ia-border-strong);flex:0 0 auto}
  .reg-tender-btn.tend-big.selected .tend-dot{border-color:var(--ia-accent);
    background:radial-gradient(circle,var(--ia-accent) 0 42%,transparent 44%)}
  .tend-more{width:100%;display:flex;align-items:center;justify-content:space-between;
    background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r);
    color:var(--ia-text);padding:12px 15px;font:inherit;font-size:13.5px;cursor:pointer;margin-bottom:12px}
  .tend-more:hover{border-color:var(--ia-border-strong)}
  .tend-chev{transition:transform .15s;color:var(--ia-text-dim)}
  .tend-more[aria-expanded="true"] .tend-chev{transform:rotate(180deg)}
  .tend-other{margin-bottom:12px}
  .tend-cash-input{position:relative;margin-bottom:8px}
  .tend-cash-input b{position:absolute;left:13px;top:50%;transform:translateY(-50%);
    color:var(--ia-text-dim);font-size:15px}
  .tend-cash-input input{width:100%;padding:13px 13px 13px 27px;font-size:17px;font-weight:700;
    font-variant-numeric:tabular-nums;background:var(--ia-input-bg);color:var(--ia-text);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r)}
  .tend-quick{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
  /* MARKER-CASH-SIMPLE — .reg-modal input[type=text] (padding:10px) outranked
     .tend-cash-input input, so the "$" sat on top of the typed amount. */
  .reg-modal .tend-cash-input input[type=text]{padding:13px 13px 13px 30px;font-size:18px}
  .tend-quick button.on{border-color:var(--ia-accent);color:var(--ia-accent)}
  .tend-change span{font-size:14px}
  .tend-change b{font-size:24px !important}
  .tend-change.owed b{color:#f5b54a !important}
  .tend-quick button{background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    color:var(--ia-text);border-radius:var(--ia-r);padding:9px 0;font:inherit;font-size:13px;cursor:pointer}
  .tend-quick button:hover{border-color:var(--ia-border-strong)}
  .tend-change{display:flex;align-items:center;justify-content:space-between;
    padding:10px 0 14px;font-size:13px;color:var(--ia-text-muted)}
  .tend-change b{font-size:16px;font-variant-numeric:tabular-nums;color:#7ee081}
  .tend-foot{padding:14px 22px 18px;border-top:0.5px solid var(--ia-border)}
  .tend-go{width:100%;padding:14px;font-size:14.5px;font-weight:700}
  .tend-links{display:flex;gap:18px;justify-content:center;margin-top:12px}
  .tend-link{background:none;border:0;color:var(--ia-text-dim);font:inherit;font-size:12.5px;
    cursor:pointer;padding:2px}
  .tend-link:hover{color:var(--ia-text);text-decoration:underline}

  /* MARKER-QUICK-ADD */
  .reg-quick-btn{display:flex;align-items:center;justify-content:space-between;gap:10px;
    background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r);
    padding:12px 14px;color:var(--ia-text);cursor:pointer;font-family:inherit;text-align:left}
  .reg-quick-btn:hover{border-color:var(--ia-border-strong)}
  .reg-quick-btn .qs-n{font-size:13.5px}
  .reg-quick-btn .qs-p{font-size:12px;color:var(--ia-text-dim);font-variant-numeric:tabular-nums}

  /* patch-96 oversell-badge — small amber inline marker on cart lines */
  .reg-oversell-badge {
    display:inline-block; margin-left:8px;
    padding:2px 7px;
    background:rgba(245,158,11,0.12);
    color:#F59E0B;
    border:0.5px solid rgba(245,158,11,0.35);
    border-radius:var(--ia-r-xs);
    font-size:10.5px; font-weight:600;
    letter-spacing:0.02em;
    vertical-align:middle;
    white-space:nowrap;
  }
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

  /* MARKER-RESULTS-SCROLL — cap the list and give it its own scroller, so a
     broad search doesn't run off the bottom of the screen. overscroll-behavior
     keeps a trackpad flick inside the list instead of scrolling the page. */
  #resultsArea{max-height:min(52vh,560px);overflow-y:auto;overscroll-behavior:contain}
  #resultsArea::-webkit-scrollbar{width:8px}
  #resultsArea::-webkit-scrollbar-thumb{background:var(--ia-border);border-radius:4px}
  #resultsArea::-webkit-scrollbar-thumb:hover{background:var(--ia-border-strong,var(--ia-border))}
  #resultsArea::-webkit-scrollbar-track{background:transparent}
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
  /* MARKER-REG-STOCK */
  .reg-stock-chip{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;
    font-size:11px;font-weight:600;border:0.5px solid transparent;white-space:nowrap}
  .reg-stock-chip.is-in{color:#7ee081;border-color:rgba(126,224,129,.35)}
  .reg-stock-chip.is-elsewhere{color:#6fb3f2;border-color:rgba(111,179,242,.35)}
  .reg-stock-chip.is-order{color:#f5c451;border-color:rgba(245,196,81,.35)}
  .reg-stock-chip.is-over{color:#f5c451;border-color:rgba(245,196,81,.35)}
  .reg-stock-chip.is-out{color:#f2777a;border-color:rgba(242,119,122,.35)}
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
    display:flex;flex-direction:column;gap:6px;
    padding:12px 14px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);
    margin-bottom:14px;font-size:13px
  }
  .reg-cust .head{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .reg-cust .name{font-weight:500;font-size:14px}
  .reg-cust .meta{display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--ia-text-dim)}
  .reg-cust .meta a{color:var(--ia-text-dim);text-decoration:none}
  .reg-cust .meta a:hover{color:var(--ia-text);text-decoration:underline}
  .reg-cust .actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:4px;padding-top:8px;border-top:0.5px solid var(--ia-border)}
  .reg-cust .profile-link{font-size:12px;color:var(--ia-accent);text-decoration:none}
  .reg-cust .profile-link:hover{text-decoration:underline}
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

  /* MARKER-HOLD-ROW — three buttons now, not two. Hold and Save quote share
     the top row; Collect payment spans the full width beneath, which is both
     the fix and the right hierarchy: the primary action should not be fighting
     two occasional ones for horizontal space. */
  .reg-pay-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px}
  .reg-pay-row .reg-pay{grid-column:1 / -1}
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
  /* MARKER-PATCH-161 — receipt indicator */
  .reg-cust-receipt{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:8px 12px;
    margin-top:8px;
    background:rgba(190,242,100,.06);
    border:0.5px solid rgba(190,242,100,.2);
    border-radius:var(--ia-r-sm);
    font-size:12px;
    flex-wrap:wrap;
  }
  .reg-cust-receipt--none{
    background:var(--ia-surface);
    border-color:var(--ia-border);
    color:var(--ia-text-dim);
  }
  .reg-cust-receipt-status{display:flex;align-items:center;gap:8px;min-width:0;flex:1}
  .reg-cust-receipt-dot{
    width:8px;height:8px;border-radius:50%;background:var(--ia-accent);
    box-shadow:0 0 0 3px rgba(190,242,100,.15);flex-shrink:0;
  }
  .reg-cust-receipt-skip{
    display:flex;align-items:center;gap:6px;cursor:pointer;
    font-size:11.5px;color:var(--ia-text-dim);user-select:none;flex-shrink:0;
  }
  .reg-cust-receipt-skip input{width:14px;height:14px;accent-color:var(--ia-accent)}

  .reg-attach.warning{
    border:0.5px dashed var(--reg-danger);
    color:var(--reg-danger)
  }

  .reg-err{background:var(--reg-danger-bg);color:var(--reg-danger);border-radius:var(--ia-r-sm);padding:10px 12px;font-size:13px;margin-bottom:12px;border:0.5px solid rgba(248,113,113,.30)}
  /* MARKER-PATCH-170C — shake animation for errors. Triggered by toggling .reg-err--shake. */
  @keyframes reg-shake {
    0%,100% { transform: translateX(0); }
    15%     { transform: translateX(-6px); }
    30%     { transform: translateX(5px); }
    45%     { transform: translateX(-4px); }
    60%     { transform: translateX(3px); }
    75%     { transform: translateX(-2px); }
    90%     { transform: translateX(1px); }
  }
  .reg-err--shake { animation: reg-shake 0.55s ease-out; }

  /* Pre-flight blocker modal — uses the same surfaces as other reg-modals
     but with a danger-tinged accent on the title. */
  .reg-preflight-icon {
    width: 44px; height: 44px;
    background: rgba(248,113,113,.10);
    border: 0.5px solid rgba(248,113,113,.25);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #f87171;
    margin: 0 auto 14px;
  }
  .reg-preflight h2 { text-align: center; }
  .reg-preflight .lede { text-align: center; }

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
  /* MARKER-SPLIT-TENDER */
  .reg-split-remaining{display:flex;justify-content:space-between;font-size:13.5px;padding:8px 2px;border-bottom:1px solid var(--ia-border);margin-bottom:10px}
  .reg-split-remaining b{font-variant-numeric:tabular-nums;color:#F5C56B}
  .reg-split-remaining.zero b{color:var(--ia-accent)}
  .reg-split-row{display:flex;align-items:center;gap:10px;border:1px solid var(--ia-border);border-radius:10px;padding:9px 12px;margin-bottom:7px;font-size:13px;flex-wrap:wrap}
  .reg-split-row .amt{margin-left:auto;font-weight:700;font-variant-numeric:tabular-nums}
  .reg-split-row .x{color:var(--ia-text-dim);cursor:pointer;padding:2px 6px;border-radius:6px}
  .reg-split-row .x:hover{color:#F09595}
  .reg-split-row .chg{flex-basis:100%;font-size:11px;color:var(--ia-accent)}
  /* MARKER-TENDERUX — no pointer-events:none: it kills the title tooltip and
     swallows the tap, leaving a touchscreen user no way to learn why. */
  .reg-tender-btn.split-disabled{opacity:.35;cursor:not-allowed}
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

  /* MARKER-GC-FIELDSTYLE -- email + textarea joined by the gift card modal */
  .reg-modal input[type=text],.reg-modal input[type=email],.reg-modal textarea{width:100%;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-modal textarea{resize:vertical;min-height:64px}
  .reg-modal input[type=text]:focus,.reg-modal input[type=email]:focus,.reg-modal textarea:focus{outline:none;border-color:var(--ia-accent)}

  .reg-receipt{text-align:center}
  .reg-receipt h2{font-size:24px;margin-bottom:6px}
  .reg-receipt .num{font-size:13px;color:var(--ia-text-dim);margin-bottom:18px;font-family:var(--ia-font-mono)}
  .reg-receipt .total{font-size:36px;font-weight:700;margin:14px 0}
  /* MARKER-PATCH-187 — auto-reset countdown line */
  .reg-receipt-auto{margin-top:14px;font-size:12px;color:var(--ia-text-dim)}
  .reg-receipt-auto span{font-variant-numeric:tabular-nums;color:var(--ia-text)}

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
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link active">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  {{-- MARKER-LAYAWAY-TAB --}}
  <a href="{{ route('tenant.register.layaways.index') }}" class="reg-tab-link">Layaways</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a> {{-- MARKER-REGISTER-RECON-DISPLAY --}}
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
  </div>{{-- /reg-tabs-scroll MARKER-REG-MOBILE — the picker sits OUTSIDE the
        scroller so it can take its own row on a phone. --}}
  {{-- MARKER-REGISTER-RECON-DISPLAY — register picker (only when registers exist) --}}
  @if (($registers ?? collect())->isNotEmpty())
    {{-- MARKER-REG-MOBILE — margin/max-width/font-size moved to CSS so the
         mobile rule can override them. --}}
    {{-- MARKER-SSEL-REGPICKER — our picker, not the OS-drawn native popup.
         The hidden input keeps the id "registerPicker" so the pairing script
         below reads it exactly as before, and the component fires `change`
         on it, which is the event that script already listens for. --}}
    @php
      // MARKER-SSEL-REGBASE — "No register / display" is the base option with
      // value 0, and it is what a session with nothing paired selects. There
      // is no separate placeholder row: any="" keeps the list to real options.
      $sselRegs = ['0' => 'No register / display'];
      foreach ($registers as $r) {
          $sselRegs[(string) $r->id] = '#' . $r->number . ' — ' . $r->name;
      }
      $sselRegCur = (string) ($currentRegisterId ?? 0);
      if (! array_key_exists($sselRegCur, $sselRegs)) { $sselRegCur = '0'; }
    @endphp
    <div class="reg-picker-wrap" style="margin-left:auto;width:220px;flex:0 0 220px;margin-bottom:8px">{{-- MARKER-SSEL-REGCLEAR — the tab bar has a bottom border; without this the button outline sat exactly on it --}}{{-- MARKER-SSEL-REGWIDTH — the old CSS sized #registerPicker itself; that id is now the hidden input, so the wrapper needs a real width or it collapses --}}
      <x-tenant.searchable-select name="register_picker" id="registerPicker" :searchable="false" :assoc="true"
        :options="$sselRegs" :selected="$sselRegCur"
        any="" noun="registers" />{{-- MARKER-SSEL-DEFAULT — "0" is already the first option --}}
    </div>
  @endif
</div>

@if(($appointmentTrayCount ?? 0) > 0)
  {{-- Appointment-sourced sales waiting for payment. Auto-created when staff
       marked an appointment Completed. We surface them prominently so staff
       can't miss a parked sale. --}}
  <div id="appointment-tray-banner" style="background:rgba(21,112,205,.07);border:0.5px solid rgba(21,112,205,.30);border-radius:var(--ia-r-md);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div style="display:flex;align-items:center;gap:12px;flex:1">
      <span style="font-size:20px">💳</span>
      <div>
        <div style="font-weight:500;font-size:14px;color:var(--ia-text)">{{ $appointmentTrayCount }} {{ $appointmentTrayCount === 1 ? 'appointment is' : 'appointments are' }} ready for checkout</div>
        <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">From recently completed appointments. Click to take payment.</div>
      </div>
    </div>
    <button type="button" id="appointment-tray-toggle" class="ia-btn ia-btn--primary ia-btn--sm">View list</button>
  </div>
  <div id="appointment-tray-list" style="display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:8px;margin-bottom:18px"></div>
@endif

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

      {{-- MARKER-QUICK-ADD — the services a counter rings hourly, chosen per
           service in Services, ordered by the list's own sort order. --}}
      <div id="quickAddWrap" style="display:none;margin:14px 0 10px">
        <div style="font-size:10.5px;letter-spacing:.09em;color:var(--ia-text-dim);margin-bottom:8px">QUICK ADD</div>
        <div id="quickAddGrid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px"></div>
      </div>

      <button type="button" class="reg-open-item" id="addOpenItemBtn">+ Add custom item</button>
      @if(tenant()->gift_cards_enabled)<button type="button" class="reg-open-item" id="sellGiftCardBtn">+ Sell gift card</button>@endif {{-- MARKER-GIFTCARDS-GATE --}}
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
        {{-- MARKER-BIZ-REGISTER — never leave a $0.00 tax line unexplained --}}
        <div class="reg-totals-row" id="taxExemptRow" style="display:none;font-size:11.5px;opacity:.75">
          <span id="taxExemptLabel">Tax exempt</span><span></span>
        </div>
        <div class="reg-totals-row" id="surchargeRow" style="display:none"><span id="surchLabel">Surcharge</span><span id="surchVal">$0.00</span></div>
        <div class="reg-totals-row" id="tipRow" style="display:none"><span>Tip</span><span id="tipVal">$0.00</span></div>
        <div class="reg-totals-row grand"><span>Total</span><span id="totalVal">$0.00</span></div>
        {{-- MARKER-PAID-VISIBLE — shown only when the sale has money on it, so
             an ordinary cart looks exactly as it did. --}}
        <div class="reg-totals-row" id="cartPaidRow" style="display:none">
          <span>Paid</span><span id="cartPaidAmt" style="color:#7ee081">$0.00</span>
        </div>
        <div class="reg-totals-row grand" id="cartRemainRow" style="display:none">
          <span>Still owed</span><span id="cartRemainAmt">$0.00</span>
        </div>
      </div>

      {{-- MARKER-REGISTER-DISCOUNT --}}
      <button type="button" class="reg-btn-secondary" id="discountBtn"
              style="width:100%;margin-top:10px;padding:9px;font-size:13px">Discount or code</button>

      <div class="reg-pay-row">
        {{-- MARKER-HOLD — parking a cart deliberately, as opposed to the autosave
             that happens anyway. The name is what tells them apart later. --}}
        <button type="button" class="reg-quote-btn" id="holdSaleBtn">Hold sale</button>
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
      {{-- MARKER-GC-FUNCTIONS --}}
      @if(tenant()->gift_cards_enabled && $gcCfg['refund_to_card'])
      <button type="button" class="reg-tender-btn" data-refund-tender="gift_card">Gift card</button>
      @endif
    </div>
    {{-- MARKER-GC-FUNCTIONS --}}
    <div id="refundGiftRow" style="display:none;margin-top:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin-bottom:6px;font-weight:500">Existing card code <span style="font-weight:400;color:var(--ia-text-dim)">(optional)</span></label>
      <input type="text" id="refundGiftCode" placeholder="Scan the customer's card, or leave blank" style="font-family:var(--ia-font-mono)">
      <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px">Leave blank to issue a new card for the refund amount — the code appears on the receipt screen.</div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundTenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundTenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

{{-- MARKER-TENDER-LAYOUT — rearranged, not rewired. Every id below is the
     one the existing JS already looks for; only the order, grouping and
     wording have changed. --}}
<div class="reg-modal-bg" id="tenderModal">
  <div class="reg-modal reg-tender-modal">

    {{-- Amount due leads. Before this, the only number on screen was an input
         box that doubled as the split field. --}}
    <div class="tend-head">
      <div class="tend-eyebrow">
        <span>Collect payment · Amount due</span>
        <button type="button" class="tend-x" data-close-modal="tenderModal" aria-label="Back to the sale">&times;</button>
      </div>
      <div class="tend-amount" id="tenderAmountDue">$0.00</div>
      <div class="tend-sub" id="tenderAmountSub"></div>
    </div>

    <div class="tend-body">
      <div class="tend-label">Payment method</div>

      {{-- The two used almost every time, given the weight they earn. --}}
      <div class="tend-primary">
        <button type="button" class="reg-tender-btn tend-big" data-tender="card">
          <span class="tend-big-name">Card</span><span class="tend-dot"></span>
        </button>
        <button type="button" class="reg-tender-btn tend-big" data-tender="cash">
          <span class="tend-big-name">Cash</span><span class="tend-dot"></span>
        </button>
      </div>

      {{-- Everything else, folded away until wanted. --}}
      <button type="button" class="tend-more" id="tenderMoreBtn" aria-expanded="false">
        <span>Other payment methods</span><span class="tend-chev">&#9662;</span>
      </button>

      <div class="reg-tender-grid tend-other" id="tenderOtherGrid" style="display:none">
        {{-- MARKER-PATCH-172 — payment-link tender (hidden when direct payments off) --}}
        <button type="button" class="reg-tender-btn" data-tender="payment_link" id="tenderPaymentLinkBtn" style="display:none">
          Send payment link
          <div style="font-size:11px;opacity:.55;font-weight:400;margin-top:2px">Customer pays from their phone</div>
        </button>
        <button type="button" class="reg-tender-btn" data-tender="check">Check</button>
        <button type="button" class="reg-tender-btn" data-tender="store_credit">Store credit</button>
        @if(tenant()->gift_cards_visible)<button type="button" class="reg-tender-btn" data-tender="gift_card">Gift card</button>@endif {{-- MARKER-GIFTCARDS-GATE --}}
        {{-- MARKER-PATCH-630 — manual tenders from tenant_payment_methods (Venmo, Cash App, custom) --}}
      @foreach(($manualTenders ?? []) as $mt)
        <button type="button" class="reg-tender-btn" data-tender="{{ $mt['key'] }}"
                data-manual="1" data-name="{{ $mt['name'] }}"
                @if($mt['linktpl']) data-linktpl="{{ $mt['linktpl'] }}" @endif
                @if($mt['instructions']) data-instructions="{{ $mt['instructions'] }}" @endif>
          {{ $mt['name'] }}
          @if($mt['hint'])<div style="font-size:11px;opacity:.55;font-weight:400;margin-top:2px">{{ $mt['hint'] }}</div>@endif
        </button>
      @endforeach
        {{-- MARKER-CASH-SIMPLE — "Already paid" lives with the other methods. --}}
        <button type="button" class="reg-tender-btn" data-tender="mark_paid">Already paid</button>
      </div>

      {{-- MARKER-TENDER-LAYOUT — cash received and change. The calculation
           already existed but only surfaced if you went through Add payment;
           here it is part of the cash tender, where a counter needs it. --}}
      <div id="tenderCashRow" style="display:none">
        <div class="tend-label">Cash received</div>
        <div class="tend-cash-input">
          <b>$</b><input type="text" id="tenderCashInput" inputmode="decimal" placeholder="0.00">
        </div>
        <div class="tend-quick" id="tenderQuickKeys"></div>
        <div class="tend-change">
          <span id="tenderChangeLabel">Change due</span><b id="tenderChangeAmt">$0.00</b>
        </div>
      </div>

      <div id="gcTenderRow" style="display:none;margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Gift card code</label>
      <div style="display:flex;gap:8px">
        <input type="text" id="gcTenderCode" placeholder="GC-0000-0000-0000" style="flex:1;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:var(--ia-font-mono)">
        <button type="button" class="reg-btn-secondary" id="gcTenderCheckBtn" style="flex:0 0 auto;padding:10px 16px">Check</button>
      </div>
      <div id="gcTenderBalance" style="display:none;justify-content:space-between;align-items:center;border:1px solid var(--ia-border);border-radius:10px;padding:10px 14px;margin-top:10px;font-size:13px">
        <span>Balance on this card</span>
        <b id="gcTenderBalanceAmt" style="font-variant-numeric:tabular-nums;color:var(--ia-accent);font-size:15px"></b>
      </div>
      <div id="gcTenderErr" style="display:none;font-size:12.5px;color:#f87171;margin-top:8px"></div>
    </div>
      <div id="tenderRefRow" style="display:none;margin-bottom:14px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Reference (optional)</label>
      <input type="text" id="tenderRefInput" placeholder="Check #, last 4 of card, etc.">
    </div>
      <div id="tenderManualRow" style="display:none;margin-bottom:14px">
      <div id="tenderManualInstr" style="font-size:12px;color:var(--ia-text-muted);margin-bottom:8px"></div>
      <div id="tenderManualLinkWrap" style="display:none">
        <div id="tenderManualLink" style="font-size:12px;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);border-radius:8px;padding:9px 11px;color:var(--ia-accent);word-break:break-all;margin-bottom:8px"></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="reg-btn-secondary" id="tenderManualCopy" style="font-size:12px;padding:7px 13px">Copy link</button>
          <a class="reg-btn-secondary" id="tenderManualSms" style="font-size:12px;padding:7px 13px;text-decoration:none" href="#">Text to customer</a>
        </div>
      </div>
      <div style="font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.4));margin-top:8px">Confirm the payment arrived in your app, then continue — the sale records as paid by this method.</div>
    </div>

      {{-- MARKER-SPLIT-TENDER — unchanged behaviour: a partial amount here
           starts a split. Now labelled, and sitting under the tender it
           applies to, instead of being an unlabelled box meaning two things. --}}
      <div id="splitAmountRow" style="display:none;gap:8px;margin-bottom:12px">
        <div style="flex:1;position:relative">
          <b style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ia-text-dim);font-size:15px">$</b>
          <input type="text" id="splitAmountInput" inputmode="decimal"
                 style="width:100%;padding:12px 12px 12px 26px;font-size:16px;font-weight:700;font-variant-numeric:tabular-nums">
        </div>
        <button type="button" class="reg-btn" id="splitAddBtn" style="padding:0 18px;font-weight:800">Add payment</button>
      </div>
      <div id="splitHint" style="display:none;font-size:11.5px;color:var(--ia-text-dim);margin:-6px 0 12px">
        Type a partial amount to split tenders — cash above the remainder computes change.
      </div>

      {{-- MARKER-SPLIT-TENDER — running remaining + recorded split payments.
         MARKER-TENDERUX — moved BELOW the tender grid: stacking legs above it
         pushed the grid and the action buttons down as payments were added,
         moving the target under the cashier's finger mid-transaction. --}}
    <div id="splitPayList"></div>
    <div class="reg-split-remaining" id="splitRemainRow" style="display:none">
      <span>Remaining</span><b id="splitRemain"></b>
    </div>

      <div id="layawayResult" style="display:none"></div>
      <div id="tenderModalErr" style="display:none;font-size:12.5px;color:#f87171;margin-bottom:10px"></div>
    </div>

    {{-- The action says what it will do. The two secondary routes are links,
         not buttons competing with it. --}}
    <div class="tend-foot">
      <button type="button" class="reg-btn-primary tend-go" id="tenderConfirmBtn" disabled>Continue</button>
      <div class="tend-links">
        <button type="button" class="tend-link" id="layawayBtn" style="display:none">Move to layaway</button>
        {{-- MARKER-CASH-SIMPLE — splitting is asked for, not always on screen.
             A cash amount under the total is already a partial payment. --}}
        <button type="button" class="tend-link" id="tenderSplitLink">Split payment</button>
      </div>
    </div>

  </div>
</div>

{{-- MARKER-PATCH-170C — Pre-flight blocker modal. Shown when the Charge
     button is pressed but the cart isn't commit-able. Replaces hidden inline
     errors that were easy to miss. --}}
<div class="reg-modal-bg" id="preflightModal">
  <div class="reg-modal reg-preflight">
    <div class="reg-preflight-icon">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <h2 id="preflightTitle">Add a customer</h2>
    <div class="lede" id="preflightLede">A customer is required when the sale includes a service.</div>
    <div class="reg-modal-actions" style="justify-content:center;gap:10px">
      <button type="button" class="reg-btn-secondary" data-close-modal="preflightModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="preflightActionBtn">Add customer →</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-170 — Direct Payments card-entry modal --}}
<div class="reg-modal-bg" id="cardPaymentModal">
  <div class="reg-modal">
    <h2>Card payment</h2>
    <div class="lede">Enter card details. Powered by Stripe.</div>

    <div id="cardPaymentSummary" style="background:var(--ia-surface-2);border-radius:var(--ia-r-md);padding:14px;margin-bottom:14px;font-size:13px">
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:15px"><span>Charge</span><span id="cardPaymentAmount">$0.00</span></div>
    </div>

    <div id="card-payment-element" style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:16px;margin-bottom:14px;min-height:60px"></div>

    <div id="cardPaymentError" style="display:none;padding:12px 14px;background:rgba(248,113,113,.10);border:0.5px solid rgba(248,113,113,.25);border-radius:var(--ia-r-md);font-size:12.5px;color:#f87171;margin-bottom:14px"></div>

    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="cardPaymentCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="cardPaymentChargeBtn" disabled>
        <span id="cardPaymentChargeLabel">Charge</span>
        <span id="cardPaymentSpinner" style="display:none;margin-left:8px">…</span>
      </button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-172 — Send-payment-link modal --}}
<div class="reg-modal-bg" id="paymentLinkModal">
  <div class="reg-modal" style="max-width:520px">
    <h2>Send payment link</h2>
    <div class="lede">Share this link with your customer. They'll pay from their device.</div>

    <div id="paymentLinkAmount" style="background:var(--ia-surface-2);border-radius:var(--ia-r-md);padding:14px;margin-bottom:14px;font-size:13px">
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:15px"><span>Charge</span><span id="paymentLinkAmountValue">$0.00</span></div>
    </div>

    <div id="paymentLinkQRContainer" style="background:white;border-radius:var(--ia-r-md);padding:18px;margin-bottom:14px;display:flex;justify-content:center;align-items:center;min-height:240px">
      <div id="paymentLinkQR"></div>
    </div>

    <div style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:10px 12px;margin-bottom:12px;display:flex;align-items:center;gap:10px">
      <code id="paymentLinkUrl" style="flex:1;font-size:11px;color:var(--ia-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></code>
      <button type="button" class="reg-btn-secondary" id="paymentLinkCopyBtn" style="padding:6px 10px;font-size:11.5px">Copy</button>
    </div>

    <div id="paymentLinkStatus" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-md);font-size:12.5px;color:var(--ia-text);margin-bottom:14px">
      <span class="stripe-spinner" style="width:14px;height:14px;border:2px solid rgba(190,242,100,.2);border-top-color:var(--ia-accent);border-radius:50%;animation:spin 0.8s linear infinite"></span>
      <span id="paymentLinkStatusText">Waiting for customer to pay…</span>
    </div>

    {{-- MARKER-PATCH-192 — two distinct actions: "Done" keeps the link live
         (sale stays pending, trackable from the appointment); "Cancel link" is
         the explicit destructive action that expires the Stripe session. --}}
    <div class="reg-modal-actions" style="display:flex;gap:10px;justify-content:space-between">
      <button type="button" class="reg-btn-secondary" id="paymentLinkCancelBtn" style="color:var(--ia-red,#F87171)">Cancel link</button>
      <button type="button" class="reg-btn-primary" id="paymentLinkDoneBtn">Done — keep link live</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-195 — Payment-link status view. Opened from the appointment
     banner (?status=<sale_id>) to show a live picture of an outstanding link. --}}
<div class="reg-modal-bg" id="linkStatusModal">
  <div class="reg-modal" style="max-width:560px">
    <h2 style="display:flex;align-items:center;gap:10px">Payment link status <span id="lsStatusPill" class="ls-pill"></span></h2>
    <div class="lede" id="lsHeader">Loading…</div>

    <div id="lsBody" style="margin-top:14px">
      <div class="ls-timeline" id="lsTimeline"></div>
    </div>

    <div class="ls-actions" id="lsActions" style="display:none;flex-direction:column;gap:8px;margin-top:16px">
      <div style="display:flex;gap:8px">
        <input type="text" id="lsUrl" readonly style="flex:1;font-size:11px;font-family:var(--ia-font-mono);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);padding:8px 10px;color:var(--ia-text-muted)">
        <button type="button" class="reg-btn-secondary" id="lsCopyBtn" style="padding:6px 12px;font-size:12px">Copy</button>
      </div>
    </div>

    <div class="reg-modal-actions" style="margin-top:18px;display:flex;justify-content:space-between;gap:10px">
      <button type="button" class="reg-btn-secondary" id="lsCancelLinkBtn" style="color:var(--ia-red,#F87171);display:none">Cancel link</button>
      <button type="button" class="reg-btn-primary" id="lsCloseBtn" style="margin-left:auto">Close</button>
    </div>
  </div>
</div>

<style>
  .ls-pill{font-size:11px;font-weight:600;padding:3px 9px;border-radius:100px}
  .ls-pill.pending{background:rgba(96,165,250,.12);color:#60A5FA}
  .ls-pill.paid{background:rgba(132,204,22,.12);color:#84CC16}
  .ls-pill.expired{background:rgba(251,191,36,.12);color:#FBBF24}
  .ls-timeline{position:relative;padding-left:22px}
  .ls-timeline:before{content:'';position:absolute;left:5px;top:6px;bottom:6px;width:1.5px;background:var(--ia-border)}
  .ls-te{position:relative;padding:7px 0}
  .ls-te:before{content:'';position:absolute;left:-21px;top:11px;width:9px;height:9px;border-radius:50%;background:var(--ia-surface);border:2px solid var(--ia-text-dim)}
  .ls-te.done:before{background:#84CC16;border-color:#84CC16}
  .ls-te.now:before{background:#60A5FA;border-color:#60A5FA}
  .ls-te .tt{font-size:13px;font-weight:500}
  .ls-te .td{font-size:11.5px;color:var(--ia-text-dim);font-family:var(--ia-font-mono);margin-top:1px}
</style>

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

{{-- MARKER-REGISTER-DISCOUNT --}}
<div class="reg-modal-bg" id="discountModal">
  <div class="reg-modal">
    <h2>Discount this sale</h2>
    <div class="lede">Applies to the whole sale. Line-item discounts are set on each line.</div>

    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Discount code</label>
      <div style="display:flex;gap:8px">
        <input type="text" id="discCodeInput" placeholder="e.g. SPRING20" autocapitalize="characters" style="flex:1">
        <button type="button" class="reg-btn-secondary" id="discCodeApply" style="padding:10px 14px;flex:0">Apply</button>
      </div>
      <div id="discCodeMsg" style="font-size:12px;margin-top:6px;min-height:16px"></div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;margin:14px 0;color:var(--ia-text-dim);font-size:11.5px">
      <div style="flex:1;height:1px;background:var(--ia-border)"></div>OR<div style="flex:1;height:1px;background:var(--ia-border)"></div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:12px">
      <div style="flex:1">
        <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Amount off</label>
        <input type="text" id="discAmtInput" placeholder="0.00" inputmode="decimal">
      </div>
      <div style="flex:1">
        <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Or percent</label>
        <input type="text" id="discPctInput" placeholder="10" inputmode="decimal">
      </div>
    </div>

    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="discClearBtn">Remove discount</button>
      <button type="button" class="reg-btn-primary" id="discApplyBtn">Apply discount</button>
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

{{-- MARKER-GIFTCARDS -- sell a gift card. Card is issued & activated when the
     sale COMPLETES, not when the line is added. --}}
<div class="reg-modal-bg" id="gcSellModal">
  <div class="reg-modal">
    <h2>Sell a gift card</h2>
    <div class="lede">Card is issued and activated when this sale is completed.</div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
      <button type="button" class="reg-tender-btn selected" id="gcKindPhysical">Physical card<br><span style="font-size:11.5px;color:var(--ia-text-dim);font-weight:400">Scan or type the card code</span></button>
      <button type="button" class="reg-tender-btn" id="gcKindEgift">E-gift card<br><span style="font-size:11.5px;color:var(--ia-text-dim);font-weight:400">Emailed to the recipient</span></button>
    </div>

    <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Amount</label>
    {{-- MARKER-GC-SETTINGS -- presets come from register settings --}}
    @if(count($gcCfg['presets']))
    <div style="display:grid;grid-template-columns:repeat({{ count($gcCfg['presets']) }},1fr);gap:8px;margin-bottom:10px" id="gcAmountGrid">
      @foreach($gcCfg['presets'] as $gcAmt)
      <button type="button" class="reg-tender-btn" data-cents="{{ $gcAmt }}" style="text-align:center;font-weight:600">${{ rtrim(rtrim(number_format($gcAmt / 100, 2), '0'), '.') }}</button>
      @endforeach
    </div>
    @else
    <div id="gcAmountGrid" style="display:none"></div>
    @endif
    <input type="text" id="gcCustomAmount" placeholder="Custom amount" inputmode="decimal">

    <div id="gcPhysicalFields">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Card code</label>
      <input type="text" id="gcSellCode" placeholder="Scan or type the printed code" style="font-family:var(--ia-font-mono)">
      <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px">Scan the barcode on the physical card, or type its printed code. Codes must be unused.</div>
    </div>

    <div id="gcEgiftFields" style="display:none">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Recipient email</label>
      <input type="email" id="gcSellEmail" placeholder="who@example.com">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 6px;font-weight:500">Gift message <span style="font-weight:400;color:var(--ia-text-dim)">(optional)</span></label>
      <textarea id="gcSellMessage" maxlength="500"></textarea>
      <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px">Code is generated automatically and emailed when the sale completes.</div>
    </div>

    <div id="gcSellErr" style="display:none;font-size:12.5px;color:#f87171;margin-top:10px"></div>

    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="gcSellModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="gcSellAddBtn">Add to sale</button>
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
    {{-- MARKER-REG-CUSTPICK — no match: the same inline create the appointment
         modal has. Name already split from what was typed. --}}
    <div id="custNewFields" style="display:none;margin-bottom:12px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <input type="text"  id="custNewFirst" placeholder="First name *" autocomplete="off">
        <input type="text"  id="custNewLast"  placeholder="Last name *"  autocomplete="off">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
        <input type="email" id="custNewEmail" placeholder="Email *" autocomplete="off">
        <input type="text"  id="custNewPhone" placeholder="Phone" autocomplete="off" inputmode="tel">
      </div>
      {{-- MARKER-CUST-ADDR — optional, but asked for up front so the record
           doesn't start life failing data health. --}}
      <div style="margin-top:8px">
        <input type="text" id="custNewAddr" placeholder="Street address" autocomplete="off">
      </div>
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;margin-top:8px">
        <input type="text" id="custNewCity"  placeholder="City" autocomplete="off">
        <input type="text" id="custNewState" placeholder="State" autocomplete="off">
        <input type="text" id="custNewPost"  placeholder="ZIP" autocomplete="off" inputmode="numeric">
      </div>
      <div style="font-size:11px;color:var(--ia-text-dim);margin-top:6px">No match — a new customer will be created. Address is optional.</div>
      <div id="custNewErr" style="display:none;font-size:12.5px;color:#f87171;margin-top:8px"></div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="customerModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="custNewAttachBtn" style="display:none">Add &amp; attach</button>
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
    {{-- MARKER-REGISTER-LINE-FIX — the change stays on screen after the tender closes. --}}
    <div id="receiptChange" style="display:none;text-align:center;margin:2px 0 8px;font-size:15px">
      Change due <b id="receiptChangeAmt" style="color:#7ee081;font-variant-numeric:tabular-nums"></b>
    </div>
    {{-- MARKER-PATCH-322 — print + email the receipt for this sale --}}
    <div class="reg-receipt-actions" style="display:flex;gap:8px;justify-content:center;margin:6px 0 2px">
      <button type="button" class="reg-btn-secondary" id="receiptPrintBtn">Print receipt</button>
      <button type="button" class="reg-btn-secondary" id="receiptEmailBtn">Email receipt</button>
    </div>
    <div id="receiptEmailPrompt" style="display:none;gap:6px;justify-content:center;align-items:center;margin:8px 0 2px">
      <input type="email" id="receiptEmailInput" placeholder="customer@email.com"
        style="background:var(--ia-input-bg,#0a0a0a);border:0.5px solid var(--ia-border,rgba(255,255,255,.13));border-radius:8px;color:var(--ia-text,#f0f0f0);font-size:13px;padding:8px 11px;font-family:inherit;width:210px">
      <button type="button" class="reg-btn-primary" id="receiptEmailSend">Send</button>
    </div>
    <div id="receiptEmailMsg" style="display:none;text-align:center;font-size:12px;margin-top:6px;color:var(--ia-text-dim)"></div>
    <div class="reg-modal-actions">
      {{-- MARKER-PATCH-232B — shown only when the register was opened with a return_to. --}}
      <a id="receiptBackTo" class="reg-btn-primary" style="display:none;text-decoration:none" href="#">Back</a>
      <button type="button" class="reg-btn-primary" id="receiptNewSale">New sale</button>
    </div>
    {{-- MARKER-PATCH-187 — auto-reset countdown --}}
    <div class="reg-receipt-auto" id="receiptAutoReset">Returning to a fresh register in <span id="receiptCountdown">45</span>s</div>
  </div>
</div>


@if(!empty($preAttachCustomer))
<script>
  // Patch 46: pre-attach customer from walk-in flow query param.
  // Runs after the register page's cart JS has initialized.
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof cart !== 'undefined' && cart) {
      cart.customer = @json($preAttachCustomer);
      if (typeof renderCart === 'function') renderCart();
      if (typeof queueDraftSave === 'function') queueDraftSave();
    }
  });
</script>
@endif

{{-- MARKER-PATCH-553 — item detail modal v2 (supersedes the 552 modal):
     gallery, brand header, permissioned cost/margin, badges, specs grid,
     stock table, action footer. --}}
{{-- MARKER-ITEM-MODAL-SHARED — the item modal moved to a shared partial so
     the appointment part picker can use the same one. --}}
@include('tenant._item-detail-modal')
<script>
// MARKER-LINE-PRICE-COND — declared HERE because this block is unconditional.
// Its previous home was inside the pre-attach-customer conditional, which only
// renders when the register is opened from a walk-in with a customer already
// attached — so on a normal load the flag was never defined and the control
// was correctly hidden from everyone, including Owners.
//
// NOTE: no Blade directive names in this comment. Blade compiles directives
// wherever it finds them, including inside a JS comment, so writing the
// condition out literally here would inject a real unclosed directive.
window.CAN_LINE_PRICE = @json($canLinePrice ?? false);
window.CAN_LAYAWAY = @json($canLayaway ?? false); // MARKER-LAYAWAY-REGISTER
window.QUICK_SERVICES = @json($quickServices ?? []); // MARKER-QUICK-ADD
window.CAN_OVERRIDE_RESERVE = @json($canOverrideReserve ?? false); // MARKER-RESERVE-OVERRIDE

// MARKER-ITEM-MODAL-SHARED — thin shim. The register's info button already
// calls openItemInfo(); keeping the name means that call site is untouched.
function openItemInfo( id ) {
  window.IntakeItemModal.open( id, {
    actionLabel: 'Add to sale',
    onAdd: function ( item ) { addToCart( item ); },
  } );
}
</script>

{{-- MARKER-LINE-PRICE-PLACE — inside the content section on purpose. This
     block used to sit after the final @endpush, and a view that extends a
     layout renders nothing outside a section or a push: the modal was in the
     source and absent from every page. --}}
{{-- MARKER-LINE-PRICE — in-app, because native dialogs get suppressed and then
     fail closed without telling anyone. --}}
@if(($canLinePrice ?? false))
<div id="reg-lineprice" style="display:none;position:fixed;inset:0;z-index:80;
     background:rgba(0,0,0,.6);align-items:center;justify-content:center"
     onclick="if (event.target === this) regLinePriceClose()">
  <div style="background:var(--ia-surface);border:0.5px solid var(--ia-border-strong);
       border-radius:12px;width:340px;max-width:92vw;padding:18px 20px">
    <div style="font-size:15px;font-weight:650;margin-bottom:2px">Change price</div>
    <div id="reg-lineprice-name" style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:14px"></div>

    <label style="font-size:11.5px;color:var(--ia-text-dim)">New price each</label>
    <input type="text" id="reg-lineprice-input" class="ia-input" inputmode="decimal"
           style="width:100%;margin-top:5px;font-size:16px"
           onkeydown="if (event.key === 'Enter') { event.preventDefault(); regLinePriceSave(); }
                      if (event.key === 'Escape') { regLinePriceClose(); }">

    <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:8px;line-height:1.5">
      Normally <span id="reg-lineprice-orig"></span>.
      Lower records a discount on the sale; higher just sets the price.
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
      <button type="button" class="ia-btn ia-btn--sm" onclick="regLinePriceReset()">Reset</button>
      <button type="button" class="ia-btn ia-btn--sm" onclick="regLinePriceClose()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" onclick="regLinePriceSave()">Apply</button>
    </div>
  </div>
</div>

<style>
  .reg-line-edit{background:none;border:0;color:var(--ia-accent);font-size:11px;
    cursor:pointer;padding:0 2px;font-family:inherit;opacity:.8}
  .reg-line-edit:hover{opacity:1;text-decoration:underline}
  .reg-line-disc{color:#7ee081}
  .reg-line-up{color:#f5c451}
</style>
@endif

@endsection

@push('scripts')
<script>
const ROUTES = {
  giftCardLookup: '{{ route('tenant.register.gift-cards.lookup') }}', // MARKER-GIFTCARDS
  search:      @json(route('tenant.register.search')),
  discountValidate: @json(route('tenant.register.discount.validate')), // MARKER-REGISTER-DISCOUNT
  storeSale:   @json(route('tenant.register.sales.store')),
  // MARKER-LAYAWAY-REGISTER
  customerOpen:    @json(route('tenant.register.customer.open', ['customer' => '__ID__'])),
  layawayOpen:     @json(route('tenant.register.layaway.open')),
  layawayPay:      @json(route('tenant.register.layaway.pay', ['plan' => '__ID__'])),
  layawayComplete: @json(route('tenant.register.layaway.complete', ['plan' => '__ID__'])),
  offlineCatalog: @json(route('tenant.register.offline_catalog')), // MARKER-OFFLINE-SYNC
  offlineSyncEnabled: {{ ($offlineSyncEnabled ?? false) ? 'true' : 'false' }}, // MARKER-OFFLINE-SYNC
  storeDraft:  @json(route('tenant.register.drafts.store')),
  listDrafts:  @json(route('tenant.register.drafts.index')),
  // MARKER-HOLD
  holdDraft:    @json(route('tenant.register.drafts.hold', ['id' => '__ID__'])),
  recordPayment: @json(route('tenant.register.payments.record')), // MARKER-PAY-PERSIST
  voidPayment:   @json(route('tenant.register.payments.void')),   // MARKER-VOID-PERSISTED
  voidSale:      @json(route('tenant.register.sale.void')),       // MARKER-NO-ORPHAN-MONEY
  draftCleanup: @json(route('tenant.register.drafts.cleanup')),
  draftBase:   @json(url('/admin/register/drafts')),
  commitDraft: @json(url('/admin/register/drafts')),
  storeQuote:  @json(route('tenant.register.quotes.store')),
  quotesIndex: @json(route('tenant.register.quotes.index')),
  lookupSale:  @json(route('tenant.register.lookup-sale')),
  commitTxn:   @json(route('tenant.register.transactions.store')),
  // MARKER-PATCH-161
  customerBase: @json(url('/admin/customers')),
  // MARKER-PATCH-162
  multiLocationActive: {{ $multiLocationActive ? 'true' : 'false' }},
  // MARKER-PATCH-170 — Direct Payments
  directPaymentsEnabled: {{ (($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true)) ? 'true' : 'false' }}, {{-- MARKER-PATCH-618 --}}
  directPaymentsPk: @json((($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true)) ? (($tenant->settings['register_payments_mode'] ?? 'test') === 'live' ? ($tenant->settings['register_payments_live_pk'] ?? '') : ($tenant->settings['register_payments_test_pk'] ?? '')) : ''),
  paymentIntentCreate: @json(url('/admin/register/payment-intent')),
  paymentIntentConfirm: @json(url('/admin/register/payment-intent/confirm')),
  // MARKER-PATCH-170B
  paymentIntentAutoRefund: @json(url('/admin/register/payment-intent/auto-refund')),
  // MARKER-PATCH-172
  checkoutSessionCreate: @json(url('/admin/register/checkout-session')),
  checkoutSessionCheck:  @json(url('/admin/register/checkout-session/check')),
  saleShow:              @json(route('tenant.register.sales.show', ['id' => '__ID__'])), {{-- MARKER-PATCH-195 --}}
  saleReceipt:           @json(route('tenant.register.sales.receipt', ['id' => '__ID__'])), {{-- MARKER-PATCH-322 --}}
  resendReceipt:         @json(route('tenant.sales.resend_receipt', ['id' => '__ID__'])), {{-- MARKER-PATCH-322 --}}
  checkoutSessionCancel: @json(url('/admin/register/checkout-session/cancel')),
};
const CSRF = document.querySelector('meta[name=csrf-token]').content;

// MARKER-REGISTER-RECON-DISPLAY — customer display mirroring.
// Debounced snapshots of the cart are pushed to the currently selected
// register; a paired iPad polls that register's snapshot and renders it.
const DisplayMirror = {
  enabled: {{ ($currentRegisterId ?? 0) > 0 ? 'true' : 'false' }},
  payUrl: null,
  timer: null,
  stateUrl: @json(route('tenant.register.display_state')),
  selectUrl: @json(route('tenant.register.select')),
};
function displaySnapshot() {
  const items = [];
  for (const i of cart.items) items.push({ name: i.name, qty: i.qty, line_cents: Math.round(i.price_cents * i.qty) });
  for (const r of cart.refund_lines) items.push({ name: r.name, qty: r.qty, line_cents: Math.round(r.price_cents * r.qty), refund: true });
  const sub = calcSubtotal() - calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const total = (calcSubtotal() - cart.discountCents + tax + surch + cart.tipCents) - (calcRefundSubtotal());
  return {
    state: DisplayMirror.payUrl ? 'pay' : (items.length ? 'cart' : 'idle'),
    items,
    subtotal_cents: sub,
    discount_cents: cart.discountCents,
    tax_cents: tax,
    tax_label: CFG.taxLabel || null,
    surcharge_cents: surch,
    tip_cents: cart.tipCents,
    total_cents: Math.max(0, Math.round(total)),
    pay_url: DisplayMirror.payUrl,
  };
}
function queueDisplayMirror(immediate = false) {
  if (!DisplayMirror.enabled) return;
  clearTimeout(DisplayMirror.timer);
  DisplayMirror.timer = setTimeout(() => {
    fetch(DisplayMirror.stateUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(displaySnapshot()),
    }).catch(() => {});
  }, immediate ? 0 : 400);
}

// MARKER-OFFLINE-SYNC stage 3 — register-specific offline behavior.
// Core (outbox, snapshot, replay, SW registration, status pill) lives in the
// global /js/offline-sync.js module loaded by the layout on every admin page;
// this block only handles what's unique to the register: queueing a commit,
// snapshot search, and disabling network-only tenders.
function osToggleTenders(online) {
  document.querySelectorAll('.reg-tender-btn').forEach(b => {
    const t = b.dataset.tender || b.dataset.refundTender;
    if (t === 'card' || t === 'payment_link') {
      const block = !online && window.IntakeOffline && IntakeOffline.enabled && !IntakeOffline.paused;
      b.disabled = block;
      b.style.opacity = block ? '.35' : '';
      b.title = block ? 'Unavailable offline' : '';
    }
  });
}
document.addEventListener('intake-offline-status', e => osToggleTenders(e.detail.online));
if (window.IntakeOffline) osToggleTenders(navigator.onLine);

function osBuildSalePayload(){
  return {
    client_uuid: IntakeOffline.uuid(),
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    discount_cents: cart.discountCents,
    payment_method: cart.payment_method,
    payment_reference: cart.payment_reference,
    items: cart.items.map(serializeLine),
    skip_receipt: cart.skipReceipt ? 1 : 0,
  };
}
async function osTryQueueCommit(){
  const io = window.IntakeOffline;
  if (!io || !io.enabled || io.paused || !io.db) return false;
  if (cart.refund_lines.length > 0) return false;
  if (cart.stripe_payment_intent_id) return false;
  if (cart.payment_method === 'card' || cart.payment_method === 'payment_link') return false;
  if (!cart.items.length) return false;
  await io.queueSale(osBuildSalePayload());
  cart.items = []; cart.refund_lines = []; cart.refund_meta = null;
  cart.customer = null; cart.tipCents = 0; cart.discountCents = 0; cart.discountCode = null; // MARKER-REGISTER-DISCOUNT
  cart.override_reserved = false; // MARKER-RESERVE-OVERRIDE — never carries into the next sale
  cart.po_number = null; // MARKER-BIZ-REGISTER
  (function(){ var r = document.getElementById('taxExemptRow'); if (r) r.style.display = 'none'; })();
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  if (typeof resetGiftTender === 'function') resetGiftTender(); // MARKER-TENDERFIX
  cart.draft_id = null; cart.skipReceipt = false;
  renderCart();
  showError('Saved offline — this sale will sync automatically when the connection returns.');
  return true;
}
function osSearchSnapshot(q){
  return (window.IntakeOffline && IntakeOffline.enabled && !IntakeOffline.paused)
    ? IntakeOffline.snapshotSearch(q) : null;
}

const registerPickerEl = document.getElementById('registerPicker');
if (registerPickerEl) {
  registerPickerEl.addEventListener('change', async () => {
    const id = parseInt(registerPickerEl.value, 10) || 0;
    try {
      await fetch(DisplayMirror.selectUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ register_id: id }),
      });
      DisplayMirror.enabled = id > 0;
      queueDisplayMirror(true);
    } catch (e) {}
  });
}
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
  discountCode: null, // MARKER-REGISTER-DISCOUNT
  payment_method: null, payment_reference: null,
  payments: [], // MARKER-SPLIT-TENDER
  po_number: null, // MARKER-BIZ-REGISTER
  tax_locked: false,    // when true, calcTax sums per-line tax_cents instead of computing from rate
  skipReceipt: false,   // MARKER-PATCH-161 — cashier opted out of receipt for this sale
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
      // Round-trip per-line tax for tax_locked sales so recalc preserves it.
      if (cart.tax_locked) {
        out.tax_cents = i.tax_cents || 0;
        if (i.tax_rate_snapshot != null) out.tax_rate_snapshot = i.tax_rate_snapshot;
      }
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return linePriceFields(i, out); // MARKER-REGISTER-LINE-FIX
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

// MARKER-INTENT-DRAFTS — a cart is not a record until someone means it to be.
// Without this, every price lookup left a draft behind: scan, walk away, and
// the list fills up with carts nobody started on purpose.
//
// Once a draft EXISTS — because the cart was held, or a special order or a
// layaway needed a sale to attach to — autosave resumes on it normally, so
// nothing is lost part-way through a real transaction.
function queueDraftSave() {
  // Empty cart with no existing draft — nothing to save.
  if (!cart.items.length && !cart.draft_id) return;

  // No draft yet and nobody asked for one: keep it in the browser.
  if (!cart.draft_id) { setSaveStatus('local'); return; }

  clearTimeout(draftSaveTimer);
  draftSaveTimer = setTimeout(fireDraftSave, DRAFT_DEBOUNCE_MS);
  setSaveStatus('pending');
}

let saveStatusTimer = null;
function setSaveStatus(state) {
  const el = document.getElementById('saveStatus');
  if (!el) return;
  clearTimeout(saveStatusTimer);
  // MARKER-INTENT-DRAFTS — say what is actually true. A cart that is not saved
  // anywhere must not imply it is.
  if (state === 'local') {
    el.textContent = 'Not saved — hold this sale to keep it';
    el.classList.add('visible');
    return;
  }
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

// MARKER-INTENT-DRAFTS — force=true is the deliberate act: Hold, Add to
// order, Put on layaway. Those three need a sale row to exist and say so.
// Everything else only flushes a draft that is already there.
async function flushDraftSave(force) {
  // Cancel any pending debounce, fire immediately, await any in-flight save.
  clearTimeout(draftSaveTimer);
  draftSaveTimer = null;
  if ((force && cart.items.length) || cart.draft_id) {
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
    // MARKER-OFFLINE-SYNC — offline: search the cached catalog snapshot.
    const snap = osSearchSnapshot(q);
    if (snap && (snap.products.length || snap.services.length)) {
      renderResults(snap, null);
      resultsArea.insertAdjacentHTML('afterbegin',
        '<div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#F5C56B;margin-bottom:8px">Offline — cached catalog snapshot</div>');
    } else {
      resultsArea.innerHTML = '<div class="reg-empty">' + (!navigator.onLine ? 'Offline — no cached matches.' : 'Search failed.') + '</div>';
    }
  }
}

// Keyboard nav state
let highlighted = 0;
let visibleResults = [];

// MARKER-REG-STOCK — always ends on something the person at the counter can
// DO: sell it, fetch it from the other shop, or order it. Kept short, because
// anything longer gets skipped with a customer waiting.
function stockChip(p) {
  if (typeof p.current_location_stock !== 'number') { return ''; }

  const n         = p.current_location_stock;
  const elsewhere = Array.isArray(p.stock_elsewhere) ? p.stock_elsewhere : [];
  const atHere    = (p.stock_scope === 'location' && p.current_location_name)
    ? ' at ' + escapeHtml(p.current_location_name)
    : '';

  // MARKER-RESERVE-VISIBLE — name the reason. A unit held on a layaway is on
  // the shelf and cannot be sold; saying only "none here" sends someone to
  // look for stock that is sitting right in front of them.
  const heldHere = (typeof p.reserved_here === 'number') ? p.reserved_here : 0;

  if (n > 0) {
    const more = heldHere > 0
      ? ` <span class="reg-stock-chip is-held">${heldHere} more held on layaway</span>`
      : '';
    return ` <span class="reg-stock-chip is-in">${n} available${atHere}</span>${more}`;
  }

  if (heldHere > 0) {
    const onHand = (typeof p.on_hand_here === 'number') ? p.on_hand_here : heldHere;
    return ` <span class="reg-stock-chip is-held">${onHand} on hand${atHere} · held on layaway, not for sale</span>`;
  }

  // None here, but on a shelf somewhere — name the biggest pile first.
  if (elsewhere.length) {
    const parts = elsewhere.map(e => `${e.n} at ${escapeHtml(e.name)}`);
    return ` <span class="reg-stock-chip is-elsewhere">None here · ${parts.join(', ')}</span>`;
  }

  // Nowhere at all. A vendor turns a dead end into a special order.
  if (p.vendor_name) {
    return ` <span class="reg-stock-chip is-order">None in stock · order from ${escapeHtml(p.vendor_name)}</span>`;
  }

  if (p.allow_oversell) {
    return ` <span class="reg-stock-chip is-over">None in stock · can still sell</span>`;
  }

  return ` <span class="reg-stock-chip is-out">None in stock</span>`;
}

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
      visibleResults.push({type:'product',source_id:p.id,name:p.name,price_cents:p.price_cents,is_taxable:p.is_taxable,current_location_stock:p.current_location_stock,current_location_name:p.current_location_name,allow_oversell:p.allow_oversell,stock_scope:p.stock_scope,stock_elsewhere:p.stock_elsewhere,vendor_name:p.vendor_name,reserved_here:p.reserved_here,on_hand_here:p.on_hand_here}); // MARKER-RESERVE-VISIBLE
      const idx = visibleResults.length - 1;
      // MARKER-REG-STOCK — answered in the row, rather than surfacing later as
      // an oversell warning once the item is already in the cart.
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(p.name)}</div><div class="meta">${escapeHtml(p.subtitle || p.sku || '')}${stockChip(p)}</div></div>
        <button type="button" class="reg-info-btn" data-item-id="${p.id}" title="Item details" aria-label="Item details">i</button>
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

  // MARKER-PATCH-552 — info buttons open the item modal; stop the row's add-to-cart
  resultsArea.querySelectorAll('.reg-info-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openItemInfo(btn.dataset.itemId);
    });
  });

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
      // MARKER-RESULTS-SCROLL — the list is scrollable now, so keyboard
      // navigation has to bring its own row into view. block:'nearest'
      // means this is a no-op while the row is already visible.
      if (typeof row.scrollIntoView === 'function') {
        row.scrollIntoView({ block: 'nearest' });
      }
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
  // patch-96 cart-meta + patch-100a oversell-actions — store stock data
  // and any action-state (transfer / SO) on the cart line so it persists
  // through re-renders and draft saves.
  cart.items.push({
    key: ++lineKey, type: item.type, source_id: item.source_id,
    name: item.name, price_cents: item.price_cents, qty: 1,
    is_taxable: item.is_taxable !== false,
    current_location_stock: (typeof item.current_location_stock === 'number')
      ? item.current_location_stock : null,
    reserved_here: (typeof item.reserved_here === 'number') ? item.reserved_here : 0, // MARKER-RESERVE-VISIBLE
    on_hand_here:  (typeof item.on_hand_here  === 'number') ? item.on_hand_here  : null,
    current_location_name: item.current_location_name || null,
    transfer_request_id: null,
    transfer_request_from: null,
    special_order_id: null,
    so_number: null,
  });
  renderCart();
  queueDraftSave();
}

// patch-100a oversell-actions — handlers for the two action buttons.
// Both find the cart line, POST to the endpoint, then mutate the line's
// state fields so the next renderCart() swaps button for pill.

function requestTransferForLine(key) {
  const line = cart.items.find(i => i.key === key);
  if (!line || line.transfer_request_id) return;
  fetch('{{ route('tenant.register.oversell.transfer-request') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      inventory_item_id: line.source_id,
      quantity: Math.max(1, Math.ceil(line.qty)),
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      line.transfer_request_id = data.transfer_request_id;
      line.transfer_request_from = data.from_location_name || null;
      renderCart();
      queueDraftSave();
    } else {
      IntakeConfirm.alert({ title: 'Couldn\'t request transfer', message: (data.error || 'Unknown error') }); // MARKER-SO-CUSTOMER
    }
  })
  .catch(err => IntakeConfirm.alert({ title: 'Couldn\'t request transfer', message: err.message })); // MARKER-SO-CUSTOMER
}

function addToOrderForLine(key, retried) {
  const line = cart.items.find(i => i.key === key);
  if (!line || line.special_order_id) return;

  // MARKER-SO-CUSTOMER — a special order is a promise to a person. With no
  // customer on the sale, open the picker and finish this click once one is
  // chosen. The picker's row handler looks for afterCustomerPick.
  if (!cart.customer) {
    window.afterCustomerPick = function () { addToOrderForLine(key, retried); };
    window.__custPickArmed = true;
    openCustomerModal();
    return;
  }

  // MARKER-SO-DRAFT-RACE — draft saving is debounced, so a fast click could
  // create the order before cart.draft_id existed, leaving it with no sale
  // link — exactly the orphan class this feature exists to prevent. Flush
  // the draft first and wait for its id.
  // MARKER-INTENT-DRAFTS — a special order must link to a sale, so this path
  // creates one deliberately.
  if (!cart.draft_id && !retried && typeof fireDraftSave === 'function') {
    Promise.resolve(fireDraftSave())
      .then(function () { addToOrderForLine(key, true); })
      .catch(function () { addToOrderForLine(key, true); }); // proceed unlinked rather than blocking the sale
    return;
  }
  fetch('{{ route('tenant.register.oversell.special-order') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      inventory_item_id: line.source_id,
      quantity: Math.max(1, Math.ceil(line.qty)),
      // MARKER-SO-CUSTOMER — cart.customer_id never existed; this was always null.
      customer_id: cart.customer ? cart.customer.id : null,
      sale_id: cart.draft_id || null, // MARKER-SO-SALE-LINK — lets the server clean up later
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      line.special_order_id = data.special_order_id;
      line.so_number = data.so_number;
      renderCart();
      queueDraftSave();
    } else {
      IntakeConfirm.alert({ title: 'Couldn\'t add to order', message: (data.error || 'Unknown error') }); // MARKER-SO-CUSTOMER
    }
  })
  .catch(err => IntakeConfirm.alert({ title: 'Couldn\'t add to order', message: err.message })); // MARKER-SO-CUSTOMER
}

// MARKER-NO-ORPHAN-MONEY — refund everything on this sale and void it.
async function refundAndVoidSale() {
  const paid = (cart.payments || []).reduce((n, p) => n + (p.amount_cents || 0), 0);

  const ok = await iaConfirm('Refund ' + fmt(paid) + ' and void this sale? '
    + 'The customer gets their money back and nothing is kept.');
  if (!ok) { return false; }

  try {
    const r = await fetch(ROUTES.voidSale, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
      body: JSON.stringify({ draft_id: cart.draft_id }),
    });
    const d = await r.json();
    if (!d.ok) { showError(d.error || 'Could not void this sale.'); return false; }

    if (window.IntakeToast) { IntakeToast.success(fmt(paid) + ' refunded. Sale voided.'); }

    cart.items = []; cart.payments = []; cart.payment_method = null;
    cart.customer = null; cart.draft_id = null; cart.tipCents = 0;
    cart.discountCents = 0; cart.discountCode = null;
    renderCart();
    if (typeof renderSplit === 'function') { renderSplit(); }
    if (typeof loadDrafts === 'function') { loadDrafts(); }
    return true;
  } catch (e) {
    showError('Could not void this sale. Nothing was changed.');
    return false;
  }
}

function removeLine(key) {
  // MARKER-NO-ORPHAN-MONEY — taking the last line out of a part-paid cart
  // leaves the shop holding a customer's money against nothing: Total $0.00,
  // Paid $129.00, and no way to give it back. Removing the goods does not
  // remove the obligation, so this asks instead of quietly doing it.
  const paidNow = (cart.payments || []).reduce((n, p) => n + (p.amount_cents || 0), 0);
  if (paidNow > 0 && cart.items.length === 1 && cart.items[0].key === key) {
    refundAndVoidSale();
    return;
  }

  // MARKER-SO-SALE-LINK — a line that requested a special order takes that
  // request with it. Only retracts orders still in "needed"; anything already
  // placed with a vendor is left alone and reported, since goods may be
  // inbound. Same rule as removing a part from an appointment.
  const line = cart.items.find(i => i.key === key);
  if (line && line.special_order_id) {
    const soUrl = @json(route('tenant.special-orders.cancel', ['id' => '__ID__']));
    fetch(soUrl.replace('__ID__', line.special_order_id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ reason: 'Line removed from register sale.' }),
    })
      .then(r => r.json())
      .then(d => {
        if (d && d.ok) {
          if (window.IntakeToast) IntakeToast.success('Special order ' + (line.so_number || '') + ' cancelled');
        } else if (window.IntakeToast) {
          IntakeToast.error('Line removed, but ' + (line.so_number || 'the special order') + ' is already placed — check Special orders');
        }
      })
      .catch(() => {
        if (window.IntakeToast) IntakeToast.error('Line removed, but ' + (line.so_number || 'the special order') + ' may still be open');
      });
  }

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

// MARKER-LINE-PRICE — one place decides what a typed price MEANS.
  //
  //   below the original → discount: the line keeps its real price and the
  //     difference is recorded, so the concession survives into reporting
  //   above the original → override: a new price, and no discount, because
  //     none was given
  //
  // Stored per unit; quantity is applied when the line is serialised, so
  // changing the quantity afterwards does the right thing without re-asking.
  function applyLinePrice(line, newCents) {
    if (newCents === null || isNaN(newCents) || newCents < 0) { return; }

    line.effective_price_cents = newCents;

    if (newCents < line.price_cents) {
      line.line_discount_cents = line.price_cents - newCents;
      line.line_override_cents = null;
    } else if (newCents > line.price_cents) {
      line.line_discount_cents = 0;
      line.line_override_cents = newCents;
    } else {
      line.line_discount_cents = 0;
      line.line_override_cents = null;
    }
  }

  function editLinePrice(key) {
    const line = cart.items.find(i => i.key === key);
    if (!line) { return; }

    const current = (typeof line.effective_price_cents === 'number')
      ? line.effective_price_cents : line.price_cents;

    // In-app prompt, not window.prompt: native dialogs get suppressed and
    // fail closed and silently, which has bitten this app before.
    openLinePriceModal(line, current);
  }

  function openLinePriceModal(line, currentCents) {
    const wrap = document.getElementById('reg-lineprice');
    if (!wrap) { return; }

    document.getElementById('reg-lineprice-name').textContent = line.name;
    document.getElementById('reg-lineprice-orig').textContent = fmt(line.price_cents);
    const input = document.getElementById('reg-lineprice-input');
    input.value = (currentCents / 100).toFixed(2);
    wrap.dataset.key = line.key;
    wrap.style.display = 'flex';
    input.focus();
    input.select();
  }

  window.regLinePriceClose = function () {
    const wrap = document.getElementById('reg-lineprice');
    if (wrap) { wrap.style.display = 'none'; }
  };

  window.regLinePriceSave = function () {
    const wrap = document.getElementById('reg-lineprice');
    const key  = parseInt(wrap.dataset.key, 10);
    const line = cart.items.find(i => i.key === key);
    const val  = parseFloat(document.getElementById('reg-lineprice-input').value);

    if (line && !isNaN(val)) {
      applyLinePrice(line, Math.round(val * 100));
    }

    regLinePriceClose();
    renderCart();
  };

  window.regLinePriceReset = function () {
    const wrap = document.getElementById('reg-lineprice');
    const key  = parseInt(wrap.dataset.key, 10);
    const line = cart.items.find(i => i.key === key);

    if (line) {
      line.effective_price_cents = line.price_cents;
      line.line_discount_cents = 0;
      line.line_override_cents = null;
    }

    regLinePriceClose();
    renderCart();
  };

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
            ${r.type === 'product' ? `
            <div class="meta" style="margin-top:6px;display:flex;align-items:center;gap:6px">
              <span style="opacity:.65">Goes to</span>
              <select class="reg-dispo" data-dispo="${r.key}"
                      style="background:transparent;border:1px solid var(--ia-border);border-radius:7px;color:inherit;font-family:inherit;font-size:11.5px;padding:3px 6px">
                <option value="restock"${(r.disposition||'restock')==='restock'?' selected':''}>Restock — sellable</option>
                <option value="open_box"${r.disposition==='open_box'?' selected':''}>Open box — sellable</option>
                <option value="damaged"${r.disposition==='damaged'?' selected':''}>Damaged</option>
                <option value="defective"${r.disposition==='defective'?' selected':''}>Defective</option>
                <option value="warranty_hold"${r.disposition==='warranty_hold'?' selected':''}>Warranty hold</option>
                <option value="return_vendor"${r.disposition==='return_vendor'?' selected':''}>Return to vendor</option>
                <option value="scrap"${r.disposition==='scrap'?' selected':''}>Scrap</option>
                <option value="customer_keeps"${r.disposition==='customer_keeps'?' selected':''}>Customer keeps item</option>
              </select>
              ${['restock','open_box'].includes(r.disposition||'restock')
                ? '<span style="color:var(--ia-accent)">back to stock</span>'
                : (r.disposition === 'customer_keeps' ? '<span style="opacity:.6">no stock change</span>' : '<span style="color:#F5C56B">off the shelf</span>')}
            </div>` : ''}
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
      html += cart.items.map(i => {
        // patch-96 oversell-badge + patch-100a oversell-actions — show badge
        // and an action row below the line when the qty exceeds local stock.
        let badge = '';
        let actionRow = '';
        const isOversold = typeof i.current_location_stock === 'number'
                           && i.qty > i.current_location_stock;
        if (isOversold) {
          const overBy = i.qty - i.current_location_stock;
          const locLabel = i.current_location_name ? ' at ' + escapeHtml(i.current_location_name) : '';

          // MARKER-RESERVE-VISIBLE — if the shortfall is explained by units
          // held on a layaway, say that instead of "short". The stock is
          // there; it belongs to someone. Telling staff it is short sends
          // them to recount a shelf that is correct.
          const heldHere = (typeof i.reserved_here === 'number') ? i.reserved_here : 0;

          if (heldHere > 0 && heldHere >= overBy) {
            // MARKER-RESERVE-OVERRIDE — the door, for whoever holds the key.
            const sellAnyway = (window.CAN_OVERRIDE_RESERVE === true && !cart.override_reserved)
              ? ` <button type="button" class="reg-oversell-btn" data-action="override-reserve" data-key="${i.key}">Sell anyway…</button>`
              : (cart.override_reserved ? ' <span class="reg-oversell-pill">✓ Selling anyway — their plan loses this item</span>' : '');
            badge = `<span class="reg-oversell-badge" title="On the shelf, promised to a layaway.">⚠ ${heldHere} held on layaway${locLabel} — not short</span>${sellAnyway}`;
          } else {
            badge = `<span class="reg-oversell-badge" title="Stock will go to ${i.current_location_stock - i.qty}${locLabel}">⚠ short ${overBy}${locLabel}</span>`;
          }

          // Action row: each button is either active (button) or already-fired (pill).
          // MARKER-PATCH-162 — transfer button only renders when the tenant
          // has 2+ active locations to move stock between. Single-location
          // tenants still see the pill if a transfer was previously created
          // (orphan rows pre-patch), but can't create new ones.
          let transferBtn = '';
          if (i.transfer_request_id) {
            const fromLabel = i.transfer_request_from ? ' from ' + escapeHtml(i.transfer_request_from) : '';
            transferBtn = `<span class="reg-oversell-pill">✓ Transfer requested${fromLabel}</span>`;
          } else if (ROUTES.multiLocationActive && i.type === 'product' && i.source_id) {
            transferBtn = `<button type="button" class="reg-oversell-btn" data-action="transfer" data-key="${i.key}">Request transfer</button>`;
          }

          let soBtn = '';
          if (i.special_order_id) {
            soBtn = `<span class="reg-oversell-pill">✓ ${escapeHtml(i.so_number || 'SO created')}</span>`;
          } else if (i.type === 'product' && i.source_id) {
            soBtn = `<button type="button" class="reg-oversell-btn" data-action="so" data-key="${i.key}">Add to order</button>`;
          }

          if (transferBtn || soBtn) {
            actionRow = `<div class="reg-oversell-actions">${transferBtn}${soBtn}</div>`;
          }
        }

        // MARKER-LINE-PRICE — effective price is what the line actually
        // charges. Below the original it is a discount and the original stays
        // visible struck through; above it is simply the new price.
        const orig = i.price_cents;
        const eff  = (typeof i.effective_price_cents === 'number') ? i.effective_price_cents : orig;
        const per  = orig - eff;

        let priceMeta;
        if (per > 0) {
          priceMeta = `<s style="opacity:.55">${fmt(orig)}</s> ${fmt(eff)} `
            + `<span class="reg-line-disc">−${fmt(per)} each</span> · ${i.type}`;
        } else if (per < 0) {
          priceMeta = `<s style="opacity:.55">${fmt(orig)}</s> ${fmt(eff)} `
            + `<span class="reg-line-up">price changed</span> · ${i.type}`;
        } else {
          priceMeta = `${fmt(orig)} · ${i.type}`;
        }

        // Falls back to false: a missing flag should hide the control, never
        // break the cart.
        const priceBtn = (window.CAN_LINE_PRICE === true)
          ? `<button type="button" class="reg-line-edit" data-price="${i.key}" title="Change this line's price">edit price</button>`
          : '';

        return `
        <div class="reg-line">
          <div>
            <div class="name">${escapeHtml(i.name)} ${badge}</div>
            <div class="meta">${priceMeta} ${priceBtn}</div>
            ${actionRow}
          </div>
          <input type="text" class="qty" value="${i.qty}" data-key="${i.key}" inputmode="decimal">
          <div style="display:flex;align-items:center;gap:6px">
            <span class="total">${fmt(Math.round(eff * i.qty))}</span>
            <button type="button" class="remove" data-remove="${i.key}">×</button>
          </div>
        </div>
      `;
      }).join('');
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
  // MARKER-LINE-PRICE
  // MARKER-RESERVE-OVERRIDE — the consequence is stated before the click, and
  // it is another customer's, which is why this asks rather than toggles.
  lines.querySelectorAll('[data-action="override-reserve"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const ok = window.iaConfirm
        ? await iaConfirm('This item is held for another customer\'s layaway.\n\n'
            + 'Selling it releases their hold: their plan stays open and still owes what it owed, '
            + 'but their item is gone and will need ordering.\n\nSell it to the customer in front of you?')
        : true;
      if (!ok) { return; }
      cart.override_reserved = true;
      renderCart();
    });
  });

  lines.querySelectorAll('[data-price]').forEach(btn => {
    btn.addEventListener('click', () => editLinePrice(parseInt(btn.dataset.price, 10)));
  });
  // patch-100a oversell-actions — wire the action buttons
  lines.querySelectorAll('[data-action="transfer"]').forEach(btn => {
    btn.addEventListener('click', () => requestTransferForLine(parseInt(btn.dataset.key, 10)));
  });
  lines.querySelectorAll('[data-action="so"]').forEach(btn => {
    btn.addEventListener('click', () => addToOrderForLine(parseInt(btn.dataset.key, 10)));
  });
  lines.querySelectorAll('[data-remove-refund]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = parseInt(btn.dataset.removeRefund, 10);
      cart.refund_lines = cart.refund_lines.filter(r => r.key !== key);
      if (cart.refund_lines.length === 0) cart.refund_meta = null;
      renderCart();
    });
  });
  // MARKER-REFUND-QTY — where the returned goods go, per line.
  lines.querySelectorAll('[data-dispo]').forEach(sel => {
    sel.addEventListener('change', () => {
      const key = parseInt(sel.dataset.dispo, 10);
      const line = cart.refund_lines.find(r => r.key === key);
      if (line) { line.disposition = sel.value; renderCart(); }
    });
  });

  const slot = document.getElementById('customerSlot');
  if (cart.customer) {
    const c = cart.customer;
    // MARKER-BIZ-REGISTER — a zero tax line and a missing PO are both things
    // staff should see at the counter, not discover at invoicing time.
    (function () {
      const row = document.getElementById('taxExemptRow');
      if (!row) return;
      if (c.tax_exempt) {
        row.style.display = '';
        document.getElementById('taxExemptLabel').textContent =
          'Tax exempt' + (c.tax_exempt_certificate ? ' — cert ' + c.tax_exempt_certificate : '');
      } else {
        row.style.display = 'none';
      }
    })();
    const profileUrl = ROUTES.customerBase + '/' + encodeURIComponent(c.id);
    const emailRow = c.email
      ? `<a href="mailto:${escapeHtml(c.email)}">${escapeHtml(c.email)}</a>`
      : '';
    const phoneRow = c.phone
      ? `<a href="tel:${escapeHtml(c.phone)}">${escapeHtml(c.phone)}</a>`
      : '';
    const metaInner = (emailRow || phoneRow)
      ? `<div class="meta">${emailRow}${phoneRow}</div>`
      : '';
    // MARKER-PATCH-161 — receipt indicator
    const hasEmail = !!c.email;
    const skipChecked = cart.skipReceipt ? 'checked' : '';
    const receiptRow = hasEmail
      ? `<div class="reg-cust-receipt">
           <span class="reg-cust-receipt-status">
             <span class="reg-cust-receipt-dot"></span>
             Receipt will email to <b>${escapeHtml(c.email)}</b>
           </span>
           <label class="reg-cust-receipt-skip">
             <input type="checkbox" id="skipReceiptChk" ${skipChecked}>
             Skip receipt
           </label>
         </div>`
      : `<div class="reg-cust-receipt reg-cust-receipt--none">
           <span class="reg-cust-receipt-status">No email on file — no receipt will send</span>
         </div>`;

    slot.innerHTML = `
      <div class="reg-cust">
        <div class="head">
          <span class="name">${escapeHtml(c.name || '(no name)')}</span>
        </div>
        ${metaInner}
        ${receiptRow}
        <div class="actions">
          <a class="profile-link" href="${profileUrl}" target="_blank" rel="noopener">View profile →</a>
          <span class="clear" id="clearCust">Remove</span>
        </div>
        <div id="custOpen"></div>
      </div>`;
    loadCustomerOpen(c.id); // MARKER-LAYAWAY-REGISTER
    var skipChk = document.getElementById('skipReceiptChk');
    if (skipChk) {
      skipChk.addEventListener('change', function(){
        cart.skipReceipt = !!skipChk.checked;
      });
    }
    document.getElementById('clearCust').addEventListener('click', () => {
      cart.customer = null;
      cart.skipReceipt = false; // MARKER-PATCH-161
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

// MARKER-LAYAWAY-CARD — set when a card charge is for a plan rather than the
// cart. Cleared on every outcome, so a later cart sale can never be mistaken
// for a plan payment.
const LayawayCard = { planId: null, amountCents: 0 };

async function recordLayawayCardPayment(conf) {
  const planId = LayawayCard.planId;
  const amount = LayawayCard.amountCents;
  LayawayCard.planId = null;
  LayawayCard.amountCents = 0;

  const ref = (conf.card_brand && conf.card_last4) ? (conf.card_brand + ' ····' + conf.card_last4) : 'Card';

  try {
    const r = await fetch(ROUTES.layawayPay.replace('__ID__', planId), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
      body: JSON.stringify({
        amount_cents: amount,
        payment_method: 'card',
        payment_reference: ref,
        stripe_payment_intent_id: conf.payment_intent,
      })
    });
    const d = await r.json();

    if (!d.ok) {
      // The charge went through. Say so, loudly, with the reference — this is
      // money that exists in Stripe and not yet on the plan.
      if (window.IntakeToast) IntakeToast.error(d.error || 'Charged, but not recorded. Reference ' + conf.payment_intent);
      return;
    }

    if (window.IntakeToast) {
      IntakeToast.success(fmt(amount) + ' taken on card · '
        + (d.balance_cents > 0 ? fmt(d.balance_cents) + ' still owed' : 'paid in full'));
    }

    if (d.can_hand_over) {
      const ok = window.iaConfirm
        ? await iaConfirm('Paid in full and everything is here. Hand it over now?')
        : true;
      if (ok) { handOverLayaway(planId); return; }
    }

    if (cart.customer) { loadCustomerOpen(cart.customer.id); }
  } catch (e) {
    if (window.IntakeToast) {
      IntakeToast.error('Charged, but recording failed. Reference ' + conf.payment_intent + ' — record it manually.');
    }
  }
}

// MARKER-LAYAWAY-REGISTER ---------------------------------------------------
async function loadCustomerOpen(customerId) {
  const box = document.getElementById('custOpen');
  if (!box || !customerId) return;
  try {
    const r = await fetch(ROUTES.customerOpen.replace('__ID__', customerId), { headers: { Accept: 'application/json' } });
    const d = await r.json();
    if (!d.ok || !d.open.length) { box.innerHTML = ''; return; }
    box.innerHTML = '<div style="font-size:10.5px;letter-spacing:.09em;color:var(--ia-text-dim);margin:10px 0 6px">OPEN WITH THIS CUSTOMER</div>'
      + d.open.map(o => {
          const meta = o.kind === 'layaway'
            ? (o.status === 'ready' ? (o.awaiting ? 'Paid · waiting on a special order' : 'Paid · ready to hand over')
               : fmt(o.balance_cents) + ' balance' + (o.scheduled_cents ? ' · ' + fmt(o.scheduled_cents) + ' due ' + (o.next_due_on || '') : '')
                 + (o.overdue ? ' · <span style="color:#f2777a">overdue</span>' : ''))
            : fmt(o.balance_cents) + ' balance';
          const btn = o.kind === 'layaway'
            ? (o.status === 'ready' && !o.awaiting
                ? `<button type="button" class="reg-btn-primary" style="padding:4px 10px;font-size:12px" data-handover="${o.id}">Hand over</button>`
                : (o.balance_cents > 0 ? `<button type="button" class="reg-btn-primary" style="padding:4px 10px;font-size:12px" data-payon='${JSON.stringify({id:o.id,balance_cents:o.balance_cents,scheduled_cents:o.scheduled_cents,label:o.label})}'>Pay on this</button>` : ''))
            : `<a class="reg-btn-secondary" style="padding:4px 10px;font-size:12px;text-decoration:none" href="${o.url}">Open</a>`;
          return `<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:0.5px solid var(--ia-border);border-radius:8px;margin-bottom:6px;font-size:12.5px">
            <div style="flex:1"><div>${escapeHtml(o.label)}${o.first_item ? ' <span style="color:var(--ia-text-dim)">· ' + escapeHtml(o.first_item) + (o.items > 1 ? ' +' + (o.items - 1) : '') + '</span>' : ''}</div>
            <div style="font-size:11.5px;color:var(--ia-text-dim)">${meta}</div></div>${btn}</div>`;
        }).join('');

    box.querySelectorAll('[data-payon]').forEach(b => b.addEventListener('click', () => {
      cart.layaway_target = JSON.parse(b.dataset.payon);
      openTenderForPlan();
    }));
    box.querySelectorAll('[data-handover]').forEach(b => b.addEventListener('click', () => handOverLayaway(b.dataset.handover)));
  } catch (e) { box.innerHTML = ''; }
}

function openTenderForPlan() {
  const t = cart.layaway_target;
  document.getElementById('tenderRefInput').value = '';
  { const amt = document.getElementById('splitAmountInput'); if (amt) amt.value = ((t.scheduled_cents || t.balance_cents) / 100).toFixed(2); }
  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  resetCashTender(); // MARKER-REGISTER-LINE-FIX
  cart.splitOpen = false; // MARKER-CASH-SIMPLE
  if (typeof tenderPaint === 'function') { tenderPaint(); } // MARKER-TENDER-LAYOUT
  resetGiftTender();
  tenderModalError('');
  const lb = document.getElementById('layawayBtn'); if (lb) lb.style.display = 'none';
  const lr = document.getElementById('layawayResult');
  if (lr) {
    lr.style.display = '';
    lr.innerHTML = `<div style="padding:9px 12px;border:0.5px solid rgba(190,242,100,.35);border-radius:8px;margin-bottom:10px;font-size:12.5px">
      Paying on <strong>${escapeHtml(t.label)}</strong> · ${fmt(t.balance_cents)} balance.
      <span style="color:var(--ia-text-dim)">Enter any amount up to the balance, then pick how they are paying.</span></div>`;
  }
  openModal('tenderModal');
  if (typeof tenderPaint === 'function') { tenderPaint(); } // MARKER-TENDER-LAYOUT
}

async function payOnLayaway() {
  const t = cart.layaway_target;
  const amtEl = document.getElementById('splitAmountInput');
  const typed = amtEl && amtEl.value ? Math.round(parseFloat(String(amtEl.value).replace(/[^0-9.]/g, '')) * 100) : (t.scheduled_cents || t.balance_cents);
  if (!typed || isNaN(typed) || typed <= 0) { tenderModalError('Enter an amount.'); return; }
  if (typed > t.balance_cents) { tenderModalError('That is more than the ' + fmt(t.balance_cents) + ' owed.'); return; }
  // MARKER-LAYAWAY-CARD — a card goes through the terminal first and comes
  // back to payOnLayaway via the card modal's success path.
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    LayawayCard.planId = t.id;
    LayawayCard.amountCents = typed;
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }
  if (!['cash', 'check', 'store_credit', 'mark_paid'].includes(cart.payment_method)) {
    tenderModalError('Use cash, check, store credit or mark paid — or card if the terminal is set up.'); return;
  }
  const btn = document.getElementById('tenderConfirmBtn'); btn.disabled = true;
  try {
    const r = await fetch(ROUTES.layawayPay.replace('__ID__', t.id), {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
      body: JSON.stringify({ amount_cents: typed, payment_method: cart.payment_method, payment_reference: cart.payment_reference })
    });
    const d = await r.json();
    if (!d.ok) { tenderModalError(d.error || 'Payment failed.'); btn.disabled = false; return; }
    const lr = document.getElementById('layawayResult');
    lr.innerHTML = `<div style="padding:12px;border:0.5px solid rgba(126,224,129,.4);border-radius:8px;font-size:13px">
      <div style="font-weight:650">${fmt(typed)} recorded on ${escapeHtml(t.label)}</div>
      <div style="color:var(--ia-text-dim);margin-top:3px">${d.balance_cents > 0
        ? fmt(d.balance_cents) + ' still owed' + (d.scheduled_cents ? ' · next ' + fmt(d.scheduled_cents) + ' due ' + (d.next_due_on || '') : '')
        : (d.can_hand_over ? 'Paid in full — everything is here. Hand it over now?' : 'Paid in full — waiting on a special order to arrive.')}</div>
      ${d.can_hand_over ? '<button type="button" class="reg-btn-primary" style="margin-top:10px" id="handOverNow">Hand over now</button>' : ''}
    </div>`;
    const ho = document.getElementById('handOverNow');
    if (ho) ho.addEventListener('click', () => handOverLayaway(t.id));
    cart.layaway_target = null;
    setTimeout(() => { if (!ho) { closeModal('tenderModal'); if (cart.customer) loadCustomerOpen(cart.customer.id); } }, 1800);
  } catch (e) { tenderModalError('Payment failed.'); btn.disabled = false; }
}

async function handOverLayaway(planId) {
  const ok = window.iaConfirm ? await iaConfirm('Hand over the goods now? This completes the sale and moves the stock.') : true;
  if (!ok) return;
  try {
    const r = await fetch(ROUTES.layawayComplete.replace('__ID__', planId), {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' }, body: '{}'
    });
    const d = await r.json();
    if (!d.ok) { if (window.IntakeToast) IntakeToast.error(d.error || 'Could not complete.'); return; }
    closeModal('tenderModal');
    showReceipt({ sale_number: d.sale_number, total_cents: d.total_cents, sale_id: d.sale_id });
    cart.items = []; cart.customer = null; cart.layaway_target = null; renderCart();
  } catch (e) { if (window.IntakeToast) IntakeToast.error('Could not complete.'); }
}

document.getElementById('layawayBtn')?.addEventListener('click', async () => {
  if (!cart.customer) { tenderModalError('Attach a customer first — a layaway holds goods for someone.'); return; }

  // MARKER-LAYAWAY-NO-GIFTCARD — refuse, do not filter. This used to strip
  // gift-card lines out of the payload silently, so the plan quietly covered
  // less than the cart on screen. Name the line and let the person decide.
  const gcLines = cart.items.filter(i => i.type === 'gift_card');
  if (gcLines.length) {
    tenderModalError(
      gcLines.length === 1
        ? 'Remove the gift card first — a gift card cannot go on a layaway, because there is nothing to hold. Ring it as its own sale.'
        : 'Remove the ' + gcLines.length + ' gift cards first — gift cards cannot go on a layaway, because there is nothing to hold. Ring them as their own sale.'
    );
    return;
  }

  // MARKER-LAYAWAY-TENDERED — legs already added ARE the opening payment.
  // Adding a split leg clears cart.payment_method so the next tender can be
  // picked, so checking that field told someone who had just paid $500 to
  // pick how they were paying.
  const legs = (cart.payments || []).map(p => ({
    method: p.method,
    amount_cents: p.amount_cents,
    reference: p.reference || null,
  }));

  const amtEl = document.getElementById('splitAmountInput');
  const typed = amtEl && amtEl.value ? Math.round(parseFloat(String(amtEl.value).replace(/[^0-9.]/g, '')) * 100) : null;

  if (!legs.length) {
    // Nothing tendered yet — the old path, unchanged.
    if (!cart.payment_method) { tenderModalError('Pick how the opening payment is being made, or add one above.'); return; }
    if (!['cash', 'check', 'store_credit', 'mark_paid'].includes(cart.payment_method)) {
      tenderModalError('Card on a layaway is coming; use cash, check, store credit or mark paid for the opening payment.'); return;
    }
  } else {
    const bad = legs.filter(l => !['cash', 'check', 'store_credit', 'mark_paid'].includes(l.method));
    if (bad.length) {
      tenderModalError('A layaway cannot open on ' + bad[0].method.replace('_', ' ')
        + ' yet — remove that payment, or use cash, check, store credit or mark paid.');
      return;
    }
  }
  const btn = document.getElementById('layawayBtn'); btn.disabled = true;

  // MARKER-INTENT-DRAFTS — the plan converts the cart's draft, so make sure
  // one exists. Opening a layaway is as deliberate as it gets.
  try { await flushDraftSave(true); } catch (e) {}

  try {
    const r = await fetch(ROUTES.layawayOpen, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
      body: JSON.stringify({
        customer_id: cart.customer.id,
        draft_id: cart.draft_id || null, // MARKER-LAYAWAY-DRAFT
        // MARKER-LAYAWAY-TENDERED — legs when a split has begun, otherwise
        // the single typed amount as before.
        payments: legs.length ? legs : null,
        opening_amount_cents: legs.length ? null : typed,
        payment_method: legs.length ? legs[0].method : cart.payment_method,
        payment_reference: legs.length ? null : (document.getElementById('tenderRefInput').value.trim() || null),
        // MARKER-LAYAWAY-NO-GIFTCARD — no filter here any more. A gift card in
        // the cart is refused above, with a reason; silently sending fewer
        // lines than the cart shows is how a total stops matching the goods.
        items: cart.items.map(serializeLine),
      })
    });
    const d = await r.json();
    if (!d.ok) { tenderModalError(d.error || 'Could not open the layaway.'); btn.disabled = false; return; }
    // MARKER-LAYAWAY-DRAFT — clear the failed-attempt error. It was sitting
    // above the success panel saying the opening payment was too small, on a
    // layaway that had just opened.
    tenderModalError('');

    const lr = document.getElementById('layawayResult');
    lr.style.display = '';
    lr.innerHTML = `<div style="padding:12px;border:0.5px solid rgba(126,224,129,.4);border-radius:8px;font-size:13px">
      <div style="font-weight:650">${escapeHtml(d.label)} opened</div>
      <div style="color:var(--ia-text-dim);margin-top:4px;line-height:1.6">
        ${d.held} unit${d.held === 1 ? '' : 's'} held on the shelf · ${d.ordered} on special order<br>
        ${fmt(d.paid_cents)} taken · ${fmt(d.balance_cents)} to go${d.scheduled_cents ? ' · next ' + fmt(d.scheduled_cents) + ' due ' + (d.next_due_on || '') : ''}
      </div></div>`;
    document.getElementById('tenderConfirmBtn').style.display = 'none';
    btn.textContent = 'Done'; btn.disabled = false;
    btn.onclick = () => {
      closeModal('tenderModal');
      // MARKER-LAYAWAY-DRAFT — a full reset. The customer stayed attached, so
      // the next sale silently began as theirs; and draft_id still pointed at
      // the layaway's own sale row, so the next autosave would have written
      // into it.
      cart.items = []; cart.payments = []; cart.payment_method = null;
      cart.customer = null; cart.draft_id = null; cart.payment_reference = null;
      cart.tipCents = 0; cart.discountCents = 0; cart.discountCode = null;
      renderCart();
      document.getElementById('tenderConfirmBtn').style.display = '';
      btn.textContent = 'Put on layaway'; btn.onclick = null;
      if (cart.customer) loadCustomerOpen(cart.customer.id);
    };
  } catch (e) { tenderModalError('Could not open the layaway.'); btn.disabled = false; }
});
// ---------------------------------------------------------------------------

// MARKER-TENDER-LAYOUT — presentation only. Nothing here records a payment;
// it fills in the figures the old modal never showed and keeps the action
// button honest about what it is about to do.
function tenderPaint() {
  const due = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();

  const amtEl = document.getElementById('tenderAmountDue');
  const subEl = document.getElementById('tenderAmountSub');
  if (amtEl) { amtEl.textContent = fmt(due); }
  if (subEl) {
    const who = cart.customer ? (cart.customer.name || 'Customer') : 'No customer';
    const n = cart.items.length;
    subEl.textContent = who + ' · ' + n + ' item' + (n === 1 ? '' : 's')
      + (cart.payments.length ? ' · ' + fmt(splitPaid()) + ' already tendered' : '');
  }

  // Cash panel only for cash, and only then do quick keys make sense.
  const cashRow = document.getElementById('tenderCashRow');
  if (cashRow) {
    const isCash = cart.payment_method === 'cash';
    cashRow.style.display = isCash ? '' : 'none';
    if (isCash) { tenderQuickKeys(due); tenderChange(); }
  }

  tenderButtonLabel(due);
}

function splitPaid() {
  return cart.payments.reduce((n, p) => n + p.amount_cents, 0);
}

// Round up to the next $5, $10 and $20 above what is owed — the notes a
// person actually hands over. Exact first, because it is the common case.
function tenderQuickKeys(due) {
  const box = document.getElementById('tenderQuickKeys');
  if (!box) { return; }
  // MARKER-CASH-SIMPLE — Exact, then the next three amounts a person hands
  // over. Rounding up to $5/$10/$20 gave only "Exact" on a round total ($20
  // due → every step is $20); a note that exactly matches steps to the next.
  const opts = [];
  [500, 1000, 2000, 5000, 10000].forEach(n => {
    let v = Math.ceil(due / n) * n;
    if (v === due && n >= 2000) { v = due + n; }
    if (v > due && !opts.includes(v)) { opts.push(v); }
  });
  opts.sort((a, b) => a - b);
  const label = v => (v % 100 === 0 ? '$' + (v / 100) : fmt(v));
  box.innerHTML = '<button type="button" data-cash="' + due + '">Exact</button>'
    + opts.slice(0, 3).map(v => '<button type="button" data-cash="' + v + '">' + label(v) + '</button>').join('');
  box.querySelectorAll('[data-cash]').forEach(b => b.addEventListener('click', () => {
    document.getElementById('tenderCashInput').value = (parseInt(b.dataset.cash, 10) / 100).toFixed(2);
    box.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    tenderChange();
  }));
}

// MARKER-REGISTER-LINE-FIX — the cash box was never cleared, and whatever sat
// in it is copied into the payment amount, so last sale's $5 would record a
// $5 payment on this sale's $40. Cleared on every open and every tender change.
function resetCashTender() {
  const inp = document.getElementById('tenderCashInput');
  if (inp) { inp.value = ''; }
  const out = document.getElementById('tenderChangeAmt');
  if (out) { out.textContent = fmt(0); }
  window.cashChangeCents = 0;
}

function tenderChange() {
  const input = document.getElementById('tenderCashInput');
  const out = document.getElementById('tenderChangeAmt');
  if (!input || !out) { return; }
  const due = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();
  const got = Math.round((parseFloat(String(input.value).replace(/[^0-9.]/g, '')) || 0) * 100);
  window.cashChangeCents = Math.max(0, got - due); // shown again on the receipt

  // MARKER-CASH-SIMPLE — short cash is a partial payment: say what's still owed.
  const short = got > 0 && got < due;
  const lab = document.getElementById('tenderChangeLabel');
  if (lab) { lab.textContent = short ? 'Still owed' : 'Change due'; }
  if (out.parentNode) { out.parentNode.classList.toggle('owed', short); }
  out.textContent = fmt(short ? due - got : Math.max(0, got - due));

  // The existing split path reads splitAmountInput. Keep the two in step so
  // cash typed here behaves exactly as cash typed there always has — and an
  // emptied box goes back to the full amount instead of a stale partial.
  const split = document.getElementById('splitAmountInput');
  if (split) { split.value = ((got > 0 ? Math.min(got, due) : due) / 100).toFixed(2); }

  tenderButtonLabel(due);
}

function tenderButtonLabel(due) {
  const btn = document.getElementById('tenderConfirmBtn');
  if (!btn) { return; }
  if (cart.layaway_target) { btn.textContent = 'Take payment'; return; }
  if (!cart.payment_method) { btn.textContent = 'Continue'; return; }

  const typed = (() => {
    const el = document.getElementById('splitAmountInput');
    const v = el && el.value ? Math.round(parseFloat(String(el.value).replace(/[^0-9.]/g, '')) * 100) : null;
    return (v && !isNaN(v)) ? v : null;
  })();
  const amount = (typed !== null && typed < due) ? typed : due;

  // MARKER-CASH-SIMPLE — short cash says so on the button.
  if (cart.payment_method === 'cash' && amount < due) {
    btn.textContent = 'Take ' + fmt(amount) + ' cash · ' + fmt(due - amount) + ' still owed';
    return;
  }

  btn.textContent = ({
    card:         'Charge ' + fmt(amount),
    cash:         'Complete cash payment · ' + fmt(amount),
    check:        'Record check · ' + fmt(amount),
    store_credit: 'Apply store credit · ' + fmt(amount),
    gift_card:    'Apply gift card · ' + fmt(amount),
    payment_link: 'Send a payment link',
    mark_paid:    'Record as already paid',
  })[cart.payment_method] || ('Take ' + fmt(amount));
}

// The "Other payment methods" fold.
document.getElementById('tenderMoreBtn')?.addEventListener('click', function () {
  const grid = document.getElementById('tenderOtherGrid');
  const open = grid.style.display !== 'none';
  grid.style.display = open ? 'none' : '';
  this.setAttribute('aria-expanded', open ? 'false' : 'true');
});

document.getElementById('tenderCashInput')?.addEventListener('input', function () {
  document.querySelectorAll('#tenderQuickKeys button').forEach(x => x.classList.remove('on'));
  tenderChange();
});

// MARKER-CASH-SIMPLE — Split payment: show the amount field for the tender
// picked (or the next one picked). Add payment records each part as before.
document.getElementById('tenderSplitLink')?.addEventListener('click', function () {
  cart.splitOpen = true;
  const rowEl = document.getElementById('splitAmountRow');
  const hintEl = document.getElementById('splitHint');
  if (rowEl) { rowEl.style.display = 'flex'; }
  if (hintEl) {
    hintEl.style.display = '';
    hintEl.textContent = cart.payment_method
      ? 'Type the first part, then Add payment. Pick the next method for the rest.'
      : 'Pick how the first part is paid, type it, then Add payment.';
  }
  const inp = document.getElementById('splitAmountInput');
  if (inp && cart.payment_method) { setTimeout(() => { inp.focus(); inp.select(); }, 30); }
});
document.getElementById('splitAmountInput')?.addEventListener('input', function () {
  tenderButtonLabel(cart.payments.length > 0 ? splitRemaining() : tenderDueCents());
});

// MARKER-QUICK-ADD — a button per chosen service. Adds through addToCart, the
// same path the search results use, so a quick-added line is indistinguishable
// from a searched one everywhere downstream.
(function () {
  var list = window.QUICK_SERVICES || [];
  var wrap = document.getElementById('quickAddWrap');
  var grid = document.getElementById('quickAddGrid');
  if (!wrap || !grid || !list.length) { return; }

  wrap.style.display = '';
  grid.innerHTML = list.map(function (s) {
    return '<button type="button" class="reg-quick-btn" data-qs="' + s.id + '">'
      + '<span class="qs-n">' + escapeHtml(s.name) + '</span>'
      + '<span class="qs-p">' + fmt(s.price_cents || 0) + '</span>'
      + '</button>';
  }).join('');

  grid.querySelectorAll('[data-qs]').forEach(function (b) {
    b.addEventListener('click', function () {
      var s = list.find(function (x) { return x.id === b.dataset.qs; });
      if (!s) { return; }
      addToCart({
        type: 'service',
        source_id: s.id,
        name: s.name,
        price_cents: s.price_cents || 0,
        is_taxable: true,
      });
    });
  });
})();

// MARKER-HOLD ---------------------------------------------------------------
document.getElementById('holdSaleBtn')?.addEventListener('click', async function () {
  if (!cart.items.length) { showError('Nothing to hold — the cart is empty.'); return; }

  // MARKER-INTENT-DRAFTS — this is the act that creates the record.
  await flushDraftSave(true);
  if (!cart.draft_id) { showError('Could not park this cart. Try again.'); return; }

  const suggested = cart.customer ? (cart.customer.name || '') : '';
  const label = window.IntakeConfirm && typeof IntakeConfirm.prompt === 'function'
    ? await IntakeConfirm.prompt({
        title: 'Hold this sale',
        message: 'Give it a name you will recognise — the customer, the bike, whatever you would say out loud.',
        value: suggested,
        placeholder: 'Blue Santa Cruz guy',
        confirmText: 'Hold it',
      })
    : window.prompt('Name this held sale', suggested);

  if (!label) { return; }

  try {
    const r = await fetch(ROUTES.holdDraft.replace('__ID__', cart.draft_id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
      body: JSON.stringify({ label: String(label).slice(0, 60) }),
    });
    const d = await r.json();
    if (!d.ok) { showError('Could not hold this sale.'); return; }

    if (window.IntakeToast) { IntakeToast.success('Held as "' + d.label + '".'); }

    // Clear the screen for the next customer — that is the point of holding.
    cart.items = []; cart.payments = []; cart.payment_method = null;
    cart.customer = null; cart.draft_id = null; cart.tipCents = 0;
    cart.discountCents = 0; cart.discountCode = null;
    renderCart();
    if (typeof loadDrafts === 'function') { loadDrafts(); }
  } catch (e) { showError('Could not hold this sale.'); }
});

// MARKER-PAY-PERSIST — record a payment on the sale, then mirror what the sale
// holds. The ledger is the truth; cart.payments is a copy of it for drawing.
async function persistPayment(leg) {
  const optimistic = Object.assign({}, leg, { pending: true });
  cart.payments.push(optimistic);
  renderSplit();

  try {
    const r = await fetch(ROUTES.recordPayment, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
      body: JSON.stringify({
        draft_id: cart.draft_id || null,
        amount_cents: leg.amount_cents,
        method: leg.method,
        reference: leg.reference,
        change_cents: leg.change_cents || 0,
        stripe_payment_intent_id: leg.payment_intent || null,
        customer_id: cart.customer ? cart.customer.id : null,
        items: cart.items.map(serializeLine),
      }),
    });
    const d = await r.json();

    if (!d.ok) {
      // Take the optimistic row back out — it is not on the ledger.
      cart.payments = cart.payments.filter(p => p !== optimistic);
      renderSplit();
      tenderModalError(d.error || 'The payment could not be recorded.');
      return false;
    }

    // Mirror the sale. From here the cart is backed by a record that survives
    // a refresh, a crash, or a closed tab.
    cart.draft_id = d.draft_id;
    cart.payments = d.payments;
    renderSplit();
    if (typeof tenderPaint === 'function') { tenderPaint(); }

    // MARKER-PAID-REPAINT — the panel too. Its Paid / Still owed rows live in
    // the total-writing routine, and its "Not saved" line is only touched by
    // the autosave path, which a payment does not go through. Without these
    // two calls the panel kept saying nothing had happened.
    renderCart();
    setSaveStatus('saved');
    return true;
  } catch (e) {
    cart.payments = cart.payments.filter(p => p !== optimistic);
    renderSplit();
    tenderModalError('The payment could not be recorded. Nothing was taken.');
    return false;
  }
}

function calcSubtotal() { return cart.items.reduce((sum, i) => sum + Math.round(((typeof i.effective_price_cents === 'number') ? i.effective_price_cents : i.price_cents) * i.qty), 0); }
function calcRefundSubtotal() {
  return cart.refund_lines.reduce((sum, r) => sum + Math.round(r.price_cents * r.qty), 0);
}
// Refund-line tax: snapshot from the original sale, summed across refund lines.
// Always honor the snapshot — refunds preserve historical tax even if rate changed.
function calcRefundTax() {
  return cart.refund_lines.reduce((s, r) => s + (r.tax_cents || 0), 0);
}
function calcTax() {
  // tax_locked: per-line tax was set externally (e.g. by the appointment bridge).
  if (cart.tax_locked) {
    return cart.items.reduce((s, i) => s + (i.tax_cents || 0), 0);
  }
  if (!CFG.taxRate) return 0;
  // MARKER-REGISTER-DISCOUNT — the server spreads a whole-sale discount over
  // the lines before taxing them, so the client must do the same or the
  // displayed total won't match what gets charged.
  const gross = calcSubtotal();
  const disc  = Math.min(cart.discountCents || 0, gross);
  let taxable = 0;
  cart.items.forEach(i => {
    const line = Math.round(i.price_cents * i.qty);
    if (!i.is_taxable) return;
    const share = (disc > 0 && gross > 0) ? Math.floor(line * disc / gross) : 0;
    taxable += Math.max(0, line - share);
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
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const refundTax = calcRefundTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;

  // Display values reflect the NET cart (new lines minus refund lines).
  // Total = (subtotal - discount + tax + surcharge + tip) - (refund subtotal + refund tax).
  const netSub   = sub - refundSub;
  const netTax   = tax - refundTax;
  const total    = (sub - disc + tax + surch + tip) - (refundSub + refundTax);

  document.getElementById('subVal').textContent = fmt(netSub);
  document.getElementById('taxVal').textContent = fmt(netTax);
  document.getElementById('totalVal').textContent = fmt(total);

  // MARKER-PAID-VISIBLE — a panel reading "Total $929.00" while $387 has been
  // taken tells a cashier something untrue. Same maths the tender modal uses,
  // so the two cannot disagree.
  (function () {
    const paid = (cart.payments || []).reduce((n, x) => n + (x.amount_cents || 0), 0);
    const row  = document.getElementById('cartPaidRow');
    const rem  = document.getElementById('cartRemainRow');
    if (!row || !rem) { return; }
    if (paid <= 0) { row.style.display = 'none'; rem.style.display = 'none'; return; }
    row.style.display = '';
    rem.style.display = '';
    document.getElementById('cartPaidAmt').textContent = fmt(paid);
    document.getElementById('cartRemainAmt').textContent = fmt(Math.max(0, total - paid));
  })();

  if (disc > 0) { document.getElementById('discountRow').style.display = ''; document.getElementById('discVal').textContent = fmtNeg(disc); }
  else { document.getElementById('discountRow').style.display = 'none'; }
  if (surch > 0) { document.getElementById('surchargeRow').style.display = ''; document.getElementById('surchLabel').textContent = CFG.surchargeLabel; document.getElementById('surchVal').textContent = fmt(surch); }
  else { document.getElementById('surchargeRow').style.display = 'none'; }
  if (tip > 0) { document.getElementById('tipRow').style.display = ''; document.getElementById('tipVal').textContent = fmt(tip); }
  else { document.getElementById('tipRow').style.display = 'none'; }
  queueDisplayMirror(); // MARKER-REGISTER-RECON-DISPLAY
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

// MARKER-GIFTCARDS -- sell-modal + tender balance check --------------------
window.gcTender = null;
const gcSell = { kind: 'physical', cents: null };

if (document.getElementById('sellGiftCardBtn')) document.getElementById('sellGiftCardBtn').addEventListener('click', () => {
  gcSell.kind = 'physical'; gcSell.cents = null;
  document.getElementById('gcKindPhysical').classList.add('selected');
  document.getElementById('gcKindEgift').classList.remove('selected');
  document.getElementById('gcPhysicalFields').style.display = '';
  document.getElementById('gcEgiftFields').style.display = 'none';
  document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  ['gcCustomAmount','gcSellCode','gcSellEmail','gcSellMessage'].forEach(id => { document.getElementById(id).value = ''; });
  document.getElementById('gcSellMessage').value = GC_CFG.default_message || ''; // MARKER-GC-SETTINGS
  document.getElementById('gcSellErr').style.display = 'none';
  openModal('gcSellModal');
});

function gcSetKind(kind) {
  gcSell.kind = kind;
  document.getElementById('gcKindPhysical').classList.toggle('selected', kind === 'physical');
  document.getElementById('gcKindEgift').classList.toggle('selected', kind === 'egift');
  document.getElementById('gcPhysicalFields').style.display = kind === 'physical' ? '' : 'none';
  document.getElementById('gcEgiftFields').style.display = kind === 'egift' ? '' : 'none';
}
document.getElementById('gcKindPhysical').addEventListener('click', () => gcSetKind('physical'));
document.getElementById('gcKindEgift').addEventListener('click', () => gcSetKind('egift'));

document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(x => x.classList.remove('selected'));
    b.classList.add('selected');
    gcSell.cents = parseInt(b.dataset.cents, 10);
    document.getElementById('gcCustomAmount').value = '';
  });
});
document.getElementById('gcCustomAmount').addEventListener('input', () => {
  document.querySelectorAll('#gcAmountGrid .reg-tender-btn').forEach(x => x.classList.remove('selected'));
  gcSell.cents = null;
});

// MARKER-GC-SETTINGS -- limits + default message from register settings.
const GC_CFG = @json(['min' => $gcCfg['min_cents'], 'max' => $gcCfg['max_cents'], 'default_message' => $gcCfg['default_message']]);

// MARKER-TENDERFIX -- clear every trace of a checked gift card. The balance
// shown in this modal is a snapshot taken at Check time; carrying it into the
// next sale shows a cashier money that may already be spent.
function resetGiftTender() {
  window.gcTender = null;
  const code = document.getElementById('gcTenderCode');
  if (code) code.value = '';
  const row = document.getElementById('gcTenderRow');
  if (row) row.style.display = 'none';
  const bal = document.getElementById('gcTenderBalance');
  if (bal) bal.style.display = 'none';
  const amt = document.getElementById('gcTenderBalanceAmt');
  if (amt) amt.textContent = '';
  const err = document.getElementById('gcTenderErr');
  if (err) { err.textContent = ''; err.style.display = 'none'; }
}

// MARKER-TENDERFIX -- errors raised while the tender modal is open must land
// INSIDE it. showError() writes to #errBanner on the page behind the dialog,
// where it is invisible to whoever is looking at the modal.
function tenderModalError(msg) {
  const el = document.getElementById('tenderModalErr');
  if (!el) { showError(msg); return; }
  el.textContent = msg;
  el.style.display = msg ? '' : 'none';
}

function gcSellError(msg) {
  const el = document.getElementById('gcSellErr');
  el.textContent = msg; el.style.display = '';
}

document.getElementById('gcSellAddBtn').addEventListener('click', async () => {
  document.getElementById('gcSellErr').style.display = 'none';
  let cents = gcSell.cents;
  const custom = document.getElementById('gcCustomAmount').value.trim();
  if (!cents && custom) {
    const f = parseFloat(custom.replace(/[^0-9.]/g, ''));
    if (!isNaN(f) && f > 0) cents = Math.round(f * 100);
  }
  // MARKER-GC-SETTINGS -- same floor/ceiling the server enforces at activation.
  if (!cents) { gcSellError('Pick or enter an amount.'); return; }
  if (cents < GC_CFG.min || cents > GC_CFG.max) {
    gcSellError('Gift card amounts must be between $' + (GC_CFG.min / 100).toFixed(2) + ' and $' + (GC_CFG.max / 100).toFixed(2) + '.');
    return;
  }

  const gift = { kind: gcSell.kind };
  let label;
  if (gcSell.kind === 'physical') {
    const code = document.getElementById('gcSellCode').value.trim();
    if (!code) { gcSellError('Scan or type the card code.'); return; }
    // Reject a code already in use before it can poison the commit.
    try {
      const r = await fetch(ROUTES.giftCardLookup + '?code=' + encodeURIComponent(code), { headers: { 'Accept': 'application/json' } });
      if (r.ok) { gcSellError('That card code is already in use.'); return; }
    } catch (e) { /* offline: server re-checks at commit */ }
    gift.code = code;
    label = 'Gift card \u00b7 ' + code.slice(-4);
  } else {
    const email = document.getElementById('gcSellEmail').value.trim();
    if (!email || !email.includes('@')) { gcSellError('Recipient email is required for an e-gift card.'); return; }
    gift.recipient_email = email;
    const msg = document.getElementById('gcSellMessage').value.trim();
    if (msg) gift.gift_message = msg;
    label = 'E-gift card \u00b7 ' + email;
  }

  const line = {type:'gift_card', source_id:null, name:label, price_cents:cents, is_taxable:false};
  addToCart(line);
  cart.items[cart.items.length - 1].gift = gift;
  queueDraftSave();
  closeModal('gcSellModal');
});

document.getElementById('gcTenderCheckBtn').addEventListener('click', async () => {
  const code = document.getElementById('gcTenderCode').value.trim();
  const err = document.getElementById('gcTenderErr');
  const bal = document.getElementById('gcTenderBalance');
  err.style.display = 'none'; bal.style.display = 'none';
  window.gcTender = null;
  if (!code) return;
  try {
    const r = await fetch(ROUTES.giftCardLookup + '?code=' + encodeURIComponent(code), { headers: { 'Accept': 'application/json' } });
    const data = await r.json();
    if (!r.ok || !data.ok) { err.textContent = (data && data.error) || 'No gift card found for that code.'; err.style.display = ''; return; }
    if (data.status !== 'active') { err.textContent = 'Card ' + data.masked + ' is ' + data.status + '.'; err.style.display = ''; return; }
    window.gcTender = { code: code, balance: data.balance_cents };
    document.getElementById('gcTenderBalanceAmt').textContent = fmt(data.balance_cents);
    bal.style.display = 'flex';
    const inp = document.getElementById('splitAmountInput');
    if (inp) { inp.value = (Math.min(data.balance_cents, splitRemaining()) / 100).toFixed(2); }
    // MARKER-TENDERFIX -- if the card can't cover what's left, say so on the
    // button itself and make pressing it start the split.
    gcSyncTenderButton();
  } catch (e) {
    err.textContent = 'Could not check the card — network error.'; err.style.display = '';
  }
});
// MARKER-GIFTCARDS end ------------------------------------------------------

function openCustomerModal() {
  // MARKER-SO-CUSTOMER — a fresh open with no pending action clears any
  // stale one, so an abandoned prompt can't fire on a later, unrelated pick.
  if (!window.__custPickArmed) { window.afterCustomerPick = null; }
  window.__custPickArmed = false;
  custNewReset(); // MARKER-REG-CUSTPICK
  document.getElementById('customerSearchInput').value = '';
  document.getElementById('customerResults').style.display = 'none';
  openModal('customerModal');
  setTimeout(() => document.getElementById('customerSearchInput').focus(), 50);
}
// MARKER-REG-CUSTPICK — create on no match ----------------------------------
let custNewTouched = false; // MARKER-CUST-ADDR — the user has typed in a field
function custNewShow(q) {
  const wrap = document.getElementById('custNewFields');
  const first = document.getElementById('custNewFirst');
  const last  = document.getElementById('custNewLast');
  // MARKER-CUST-ADDR — keep splitting the typed name until the user edits a
  // field themselves. Splitting only on first show froze the last name at
  // its first letter while the search box kept being typed into.
  if (!custNewTouched) {
    const parts = (q || '').trim().split(/\s+/);
    if (parts.length >= 2 && !q.includes('@') && !/\d/.test(q)) {
      first.value = parts[0];
      last.value  = parts.slice(1).join(' ');
    } else if (q && q.includes('@')) {
      document.getElementById('custNewEmail').value = q.trim();
    } else if (q && /^[\d\s()+.-]+$/.test(q)) {
      document.getElementById('custNewPhone').value = q.trim();
    }
  }
  wrap.style.display = '';
  document.getElementById('custNewAttachBtn').style.display = '';
}
function custNewHide() {
  document.getElementById('custNewFields').style.display = 'none';
  document.getElementById('custNewAttachBtn').style.display = 'none';
}
function custNewReset() {
  custNewHide();
  custNewTouched = false; // MARKER-CUST-ADDR
  ['custNewFirst', 'custNewLast', 'custNewEmail', 'custNewPhone',
   'custNewAddr', 'custNewCity', 'custNewState', 'custNewPost'].forEach(id => { document.getElementById(id).value = ''; });
  const err = document.getElementById('custNewErr'); err.style.display = 'none'; err.textContent = '';
}
function custNewError(msg) {
  const err = document.getElementById('custNewErr'); err.textContent = msg; err.style.display = '';
}
// MARKER-CUST-ADDR — once a field is typed in, the search box stops driving it.
['custNewFirst', 'custNewLast', 'custNewEmail', 'custNewPhone'].forEach(id => {
  document.getElementById(id).addEventListener('input', () => { custNewTouched = true; });
});
document.getElementById('custNewAttachBtn').addEventListener('click', async () => {
  const first = document.getElementById('custNewFirst').value.trim();
  const last  = document.getElementById('custNewLast').value.trim();
  const email = document.getElementById('custNewEmail').value.trim();
  const phone = document.getElementById('custNewPhone').value.trim();
  const addr  = document.getElementById('custNewAddr').value.trim();   // MARKER-CUST-ADDR
  const city  = document.getElementById('custNewCity').value.trim();
  const st    = document.getElementById('custNewState').value.trim();
  const post  = document.getElementById('custNewPost').value.trim();
  if (!first || !last || !email) { custNewError('First name, last name and email are required.'); return; }

  const btn = document.getElementById('custNewAttachBtn');
  btn.disabled = true;
  try {
    const res = await fetch('{{ route('tenant.customers.store') }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: JSON.stringify({
        first_name: first, last_name: last, email: email, phone: phone || null,
        address_line1: addr || null, city: city || null, state: st || null, postcode: post || null, // MARKER-CUST-ADDR
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok || !data.id) {
      const msg = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Could not create the customer.');
      custNewError(msg);
      return;
    }
    cart.customer = { id: data.id, name: (first + ' ' + last).trim(), email: email, phone: phone || null };
    closeModal('customerModal');
    renderCart();
    queueDraftSave();
    // Same resume as picking an existing customer — an Add to order that
    // asked for a customer finishes here.
    if (typeof window.afterCustomerPick === 'function') {
      const resume = window.afterCustomerPick;
      window.afterCustomerPick = null;
      resume();
    }
  } catch (e) {
    custNewError(e.message || 'Could not create the customer.');
  } finally {
    btn.disabled = false;
  }
});

let custTimer = null;
document.getElementById('customerSearchInput').addEventListener('input', () => {
  clearTimeout(custTimer);
  custTimer = setTimeout(searchCustomers, 250);
});
async function searchCustomers() {
  const q = document.getElementById('customerSearchInput').value.trim();
  const box = document.getElementById('customerResults');
  if (q.length < 2) { box.style.display = 'none'; custNewHide(); return; } // MARKER-REG-CUSTPICK
  const url = new URL(ROUTES.search, window.location.origin);
  url.searchParams.set('q', q);
  url.searchParams.set('type', 'customer');
  try {
    const res = await fetch(url, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.customers || !data.customers.length) {
      // MARKER-REG-CUSTPICK — the results give way to the create fields, as
      // the appointment modal does. A two-word query with no @ or digits is
      // almost always a name, so pre-split it.
      box.style.display = 'none';
      custNewShow(q);
      return;
    }
    custNewHide(); // MARKER-REG-CUSTPICK
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
        // MARKER-SO-CUSTOMER — resume the action that needed a customer.
        if (typeof window.afterCustomerPick === 'function') {
          const resume = window.afterCustomerPick;
          window.afterCustomerPick = null;
          resume();
        }
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
      return linePriceFields(i, out); // MARKER-REGISTER-LINE-FIX
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
    cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
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
  // MARKER-PATCH-170C — pre-flight validation FIRST. If the cart can't
  // be committed (e.g. service line without customer), block the tender
  // modal entirely and show a focused dialog explaining what to fix.
  const blocker = preflightCheck();
  if (blocker) {
    openPreflightModal(blocker);
    return;
  }

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
    cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
    document.getElementById('refundTenderConfirmBtn').disabled = true;
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('refundTenderLede').textContent =
      'Customer is owed ' + fmt(Math.abs(net)) + '. How is the refund being given?';
    openModal('refundTenderModal');
    return;
  }

  // Standard sale-direction tender flow (net > 0).
  // MARKER-TENDER-KEEPS-PAID — do NOT wipe cart.payments here. They are rows on
  // the sale's ledger now, not a scratch list for this open; clearing them made
  // the modal show the full total on a sale that was already part-paid, and
  // "Remaining" overstate the balance by exactly what had been taken. The
  // in-browser state that is safe to reset on open still resets below.
  cart.payment_method = null;
  if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
  cart.payment_reference = null;
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderManualRow').style.display = 'none'; // MARKER-PATCH-630
  document.getElementById('tenderRefInput').value = '';
  // MARKER-TENDER-AMOUNT — everything else here was cleared per sale; the
  // amount was not, so the last customer's partial figure greeted the next.
  { const amt = document.getElementById('splitAmountInput'); if (amt) amt.value = ''; }
  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  resetGiftTender();          // MARKER-TENDERFIX -- fresh card check every sale
  tenderModalError('');
  // MARKER-LAYAWAY-REGISTER — opened from the cart: no plan target. The
  // button shows when this could be a layaway: a customer, product lines,
  // no refunds, no split already started.
  cart.layaway_target = null;
  { const lb = document.getElementById('layawayBtn'), lr = document.getElementById('layawayResult');
    if (lr) { lr.style.display = 'none'; lr.innerHTML = ''; }
    if (lb) {
      const eligible = window.CAN_LAYAWAY === true && cart.items.some(i => i.type === 'product')
        && cart.refund_lines.length === 0 && cart.payments.length === 0;
      lb.style.display = eligible ? '' : 'none';
    }
  }
  resetCashTender(); // MARKER-REGISTER-LINE-FIX
  cart.splitOpen = false; // MARKER-CASH-SIMPLE
  openModal('tenderModal');
  if (typeof tenderPaint === 'function') { tenderPaint(); } // MARKER-TENDER-LAYOUT
});

// Refund-tender modal handlers
document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.refundTender;
    // MARKER-GC-FUNCTIONS -- reveal the code box only for the gift tender.
    const rgr = document.getElementById('refundGiftRow');
    if (rgr) rgr.style.display = cart.payment_method === 'gift_card' ? '' : 'none';
    document.getElementById('refundTenderConfirmBtn').disabled = false;
  });
});

document.getElementById('refundTenderConfirmBtn').addEventListener('click', () => {
  closeModal('refundTenderModal');
  // Refund-direction commits skip the tip step entirely.
  commitTransaction({});
});

// MARKER-SPLIT-TENDER — split-tender engine. Zero recorded payments = the
// classic single-tender flow, unchanged. Split only activates via "Add
// payment". Stripe card / payment-link / mark_paid stay full-amount-only
// until stage 2 and grey out mid-split.
const SplitCard = { pendingAmountCents: null }; // MARKER-SPLIT-ANYORDER
function splitDueCents() {
  const t = computeTotalsForCommit();
  return Math.max(0, t.total_cents + (cart.tipCents || 0) - (calcRefundSubtotal() + calcRefundTax()));
}
function splitPaidCents() { return cart.payments.reduce((s, p) => s + p.amount_cents, 0); }
function splitRemaining() { return Math.max(0, splitDueCents() - splitPaidCents()); }
// MARKER-TENDERUX -- why a tender can't join a split that still owes money.
const SPLIT_BLOCK_REASON = {
  payment_link: "The customer pays this from their phone later, after they've left — it can't cover the rest of a split here. Take the remainder another way, or clear the payments above and send the link for the whole sale.",
  mark_paid:    "This records the sale as already paid elsewhere, so it can't cover a balance the register is still asking for. Clear the payments above to use it for the whole sale.",
};

function renderSplit() {
  const list = document.getElementById('splitPayList');
  const remRow = document.getElementById('splitRemainRow');
  if (!list || !remRow) return;
  const active = cart.payments.length > 0;
  remRow.style.display = active ? '' : 'none';
  list.innerHTML = '';
  cart.payments.forEach((p, i) => {
    const row = document.createElement('div');
    row.className = 'reg-split-row';
    // MARKER-SPLIT-ANYORDER — charged card rows are locked: removing one
    // refunds real money, so it's an explicit Void, never a quiet ✕.
    const removeCtl = p.locked
      ? '<span class="x" style="font-size:11px;font-weight:700">Void</span>'
      : '<span class="x">✕</span>';
    row.innerHTML = '<span>' + (p.label || p.method) + (p.locked && p.reference ? ' <span style="opacity:.55;font-size:11px">' + p.reference + '</span>' : '') + '</span>'
      + '<span class="amt">' + fmt(p.amount_cents) + '</span>'
      + removeCtl
      + (p.change_cents ? '<span class="chg">Change due ' + fmt(p.change_cents) + '</span>' : '');
    row.querySelector('.x').addEventListener('click', async () => {
      // A leg that is not on the ledger yet is only in this tab: drop it.
      if (!p.locked && !p.id) { cart.payments.splice(i, 1); renderSplit(); return; }

      // MARKER-VOID-PERSISTED — a ledger row is reversed on the server. The
      // old path posted straight to the Stripe refund endpoint, which for a
      // cash leg meant asking Stripe to refund "undefined". The server now
      // decides what a void means for this method, and the register mirrors
      // whatever the ledger says afterwards.
      const isCard = /pi_[A-Za-z0-9]+/.test(String(p.reference || ''));
      const msg = isCard
        ? 'Void this ' + fmt(p.amount_cents) + ' card charge? The customer will be refunded in Stripe.'
        : 'Void this ' + fmt(p.amount_cents) + ' ' + (p.label || p.method) + ' payment? It will be reversed on the sale.';
      if (!(await iaConfirm(msg))) return;

      try {
        const res = await fetch(ROUTES.voidPayment, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ draft_id: cart.draft_id, payment_id: p.id }),
        });
        const data = await res.json();
        if (!data.ok) { tenderModalError(data.error || 'Could not void that payment.'); return; }
        cart.payments = data.payments;
        renderSplit();
        if (typeof tenderPaint === 'function') { tenderPaint(); }
        renderCart();
      } catch (e) {
        tenderModalError('Could not void that payment. Nothing was changed.');
      }
    });
    list.appendChild(row);
  });
  const rem = splitRemaining();
  document.getElementById('splitRemain').textContent = fmt(rem);
  remRow.classList.toggle('zero', rem === 0);
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => {
    const t = b.dataset.tender;
    // MARKER-SPLIT-STRIPE — Stripe card now joins a split as the FINAL leg
    // (it charges exactly the remaining balance). Link + mark_paid wait.
    const stage2 = t === 'payment_link' || t === 'mark_paid';
    b.classList.toggle('split-disabled', active && stage2);
    // MARKER-TENDERUX — say why, on hover and on tap.
    if (active && stage2) {
      b.setAttribute('title', SPLIT_BLOCK_REASON[t] || '');
    } else {
      b.removeAttribute('title');
    }
  });
  const confirmBtn = document.getElementById('tenderConfirmBtn');
  if (active) {
    confirmBtn.disabled = rem !== 0;
    confirmBtn.textContent = rem === 0 ? 'Complete' : fmt(rem) + ' remaining';
  } else {
    confirmBtn.textContent = 'Confirm';
  }
}
document.getElementById('splitAddBtn').addEventListener('click', () => {
  if (!cart.payment_method) return;
  const raw = document.getElementById('splitAmountInput').value;
  let c = Math.round(parseFloat(String(raw).replace(/[^0-9.]/g, '')) * 100);
  if (isNaN(c) || c <= 0) return;
  const rem = splitRemaining();
  if (rem === 0) return;
  let change = 0;
  if (cart.payment_method === 'cash' && c > rem) { change = c - rem; c = rem; }
  if (c > rem) c = rem;
  // MARKER-SPLIT-ANYORDER — a Stripe card leg charges immediately for the
  // typed amount; on success the row lands locked (real money moved).
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    SplitCard.pendingAmountCents = c;
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }
  // MARKER-GIFTCARDS -- gift leg needs a checked card; cap at its balance
  if (cart.payment_method === 'gift_card') {
    if (!window.gcTender || !gcTender.code) { showError('Check the gift card first.'); return; }
    if (c > gcTender.balance) c = gcTender.balance;
    if (c <= 0) return;
  }
  // MARKER-PAID-VISIBLE — wipe the previous message before the numbers move.
  // A stale "$800 still to collect" sitting under a correct "$542 remaining"
  // is worse than no message: at a till the red number wins.
  tenderModalError('');

  const selBtn = document.querySelector('#tenderModal .reg-tender-btn.selected');

  // MARKER-PAY-PERSIST — to the ledger, not to a list in this tab. A refresh
  // between two legs used to lose the first one along with the whole cart.
  persistPayment({
    method: cart.payment_method,
    amount_cents: c,
    change_cents: change,
    reference: cart.payment_method === 'gift_card' ? gcTender.code
             : ((document.getElementById('tenderRefInput').value || '').trim() || null),
    label: selBtn ? selBtn.textContent.trim().split('\n')[0].trim() : cart.payment_method,
  });

  cart.payment_method = null;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('splitAmountRow').style.display = 'none';
  document.getElementById('splitHint').style.display = 'none';
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderRefInput').value = '';
  resetGiftTender();   // MARKER-TENDERFIX -- the leg holds the code now
  tenderModalError('');
  renderSplit();
});

document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    // MARKER-TENDERUX — the button stays clickable so a tap can explain
    // itself; a touchscreen has no hover to reveal the title.
    if (btn.classList.contains('split-disabled')) {
      tenderModalError(SPLIT_BLOCK_REASON[btn.dataset.tender] || 'That tender is not available while a split is open.');
      return;
    }
    document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    // MARKER-REGISTER-LINE-FIX — a different tender starts with an empty cash box.
    if (btn.dataset.tender !== cart.payment_method) { resetCashTender(); }
    cart.payment_method = btn.dataset.tender;
    // MARKER-SPLIT-ANYORDER — no ordering rules: any number of payments,
    // any order, any amount. Card gets the same amount field as everything.
    // MARKER-SPLIT-TENDER — amount entry for splittable tenders
    (function () {
      const t = btn.dataset.tender;
      const splittable = !(t === 'payment_link' || t === 'mark_paid'); // MARKER-SPLIT-ANYORDER
      const rowEl = document.getElementById('splitAmountRow');
      const hintEl = document.getElementById('splitHint');
      // MARKER-CASH-SIMPLE — the amount field keeps its value (Collect reads
      // it) but only shows once a split is asked for or already under way.
      const showRow = splittable && (cart.splitOpen || cart.payments.length > 0);
      if (rowEl && splittable) {
        rowEl.style.display = showRow ? 'flex' : 'none';
        if (hintEl) hintEl.style.display = showRow ? '' : 'none';
        const inp = document.getElementById('splitAmountInput');
        inp.value = (splitRemaining() / 100).toFixed(2);
        if (showRow) { setTimeout(() => { inp.focus(); inp.select(); }, 30); }
        else if (t === 'cash') { setTimeout(() => { const c = document.getElementById('tenderCashInput'); if (c) c.focus(); }, 30); }
      } else if (rowEl) {
        rowEl.style.display = 'none'; if (hintEl) hintEl.style.display = 'none';
      }
    })();
    // MARKER-TENDERFIX -- do NOT force-enable: while a split is open,
    // renderSplit() owns this button (disabled until the remainder is
    // covered). Force-enabling it produced a button that looked ready and
    // then did nothing when pressed.
    if (cart.payments.length > 0) {
      renderSplit();
    } else {
      document.getElementById('tenderConfirmBtn').disabled = false;
      document.getElementById('tenderConfirmBtn').textContent = 'Confirm';
    }
    tenderModalError('');
    // MARKER-PATCH-170C — reference field only meaningful for checks now.
    // Card no longer needs a hand-typed reference (with direct payments the
    // brand+last4 becomes the reference automatically; without direct payments
    // the field was always low-value friction).
    const showRef = ['check'].includes(cart.payment_method);
    document.getElementById('tenderRefRow').style.display = showRef ? '' : 'none';

    // MARKER-PATCH-630 — manual tenders: show instructions + amount-prefilled link
    const manualRow = document.getElementById('tenderManualRow');
    if (btn.dataset.manual) {
      const total = ((calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents) - (calcRefundSubtotal() + calcRefundTax())) / 100;
      document.getElementById('tenderManualInstr').textContent = btn.dataset.instructions || '';
      const wrap = document.getElementById('tenderManualLinkWrap');
      if (btn.dataset.linktpl && total > 0) {
        const link = btn.dataset.linktpl.replace('{amount}', total.toFixed(2));
        document.getElementById('tenderManualLink').textContent = link;
        document.getElementById('tenderManualSms').href = 'sms:?&body=' + encodeURIComponent('Pay ' + btn.dataset.name + ': ' + link);
        wrap.style.display = '';
      } else {
        wrap.style.display = 'none';
      }
      manualRow.style.display = '';
    } else {
      manualRow.style.display = 'none';
    }
    // MARKER-GIFTCARDS -- gift tender: code row + block on refund carts
    (function () {
      const isGift = btn.dataset.tender === 'gift_card';
      const gr = document.getElementById('gcTenderRow');
      if (gr) gr.style.display = isGift ? '' : 'none';
      if (isGift && cart.refund_lines.length > 0) {
        showError('Gift card tender isn\'t available on transactions with refund lines yet — ring the sale separately.');
      }
      if (!isGift) { window.gcTender = null; const b = document.getElementById('gcTenderBalance'); if (b) b.style.display = 'none'; }
    })();
    renderTotals();
    // MARKER-REGISTER-LINE-FIX — choosing a tender never repainted the panel,
    // so Cash received / Change due only appeared as the sale completed.
    if (typeof tenderPaint === 'function') { tenderPaint(); }
  });
});

// MARKER-PATCH-630 — copy the manual payment link
document.getElementById('tenderManualCopy').addEventListener('click', function () {
  const t = document.getElementById('tenderManualLink').textContent;
  navigator.clipboard.writeText(t).then(() => { this.textContent = 'Copied ✓'; setTimeout(() => { this.textContent = 'Copy link'; }, 1400); });
});

// MARKER-PATCH-170 — Direct Payments hand-keyed card flow
// When the card tender is selected AND the tenant has direct payments
// enabled, intercept to run the Stripe Payment Element BEFORE commit.
// Other tender types (cash, check, etc.) flow unchanged.
let DirectPay = {
  stripe: null,
  elements: null,
  paymentElement: null,
  clientSecret: null,
  paymentIntentId: null,
  inFlight: false,
};

// MARKER-PATCH-172 — Send-payment-link state
let PaymentLink = {
  saleId: null,
  sessionId: null,
  checkoutUrl: null,
  pollHandle: null,
};

// Show the Send-payment-link tender button when direct payments are enabled.
if (ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
  const btn = document.getElementById('tenderPaymentLinkBtn');
  if (btn) btn.style.display = '';
}

async function openCardPaymentModal() {
  // MARKER-PATCH-170B + 170C — pre-charge validation. The Charge button
  // pre-flight modal already catches this upstream, but defense-in-depth
  // in case openCardPaymentModal is reached via some other path.
  const hasServiceLine = cart.items.some(i => i.type === 'service');
  if (hasServiceLine && !cart.customer) {
    closeModal('tenderModal');
    openPreflightModal({
      title: 'Add a customer',
      message: 'A customer is required when the sale includes a service.',
      actionLabel: 'Add customer →',
      actionFn: () => { closeModal('preflightModal'); openCustomerModal(); },
    });
    return;
  }

  const errBox = document.getElementById('cardPaymentError');
  errBox.style.display = 'none';
  errBox.textContent = '';
  document.getElementById('cardPaymentChargeBtn').disabled = true;
  document.getElementById('cardPaymentSpinner').style.display = 'none';

  const totals = computeTotalsForCommit();
  // MARKER-SPLIT-ANYORDER — a pending split card leg charges the typed
  // amount; otherwise (single-tender card) the full total as always.
  // MARKER-LAYAWAY-CARD — a plan payment charges the plan's amount. This has
  // to be decided HERE: the line below is the single source of the charge, and
  // anything set before this function runs is overwritten by it.
  const amountCents = LayawayCard.planId
    ? LayawayCard.amountCents
    : (SplitCard.pendingAmountCents != null
        ? SplitCard.pendingAmountCents
        : totals.total_cents + (cart.tipCents || 0));
  DirectPay.chargeAmountCents = amountCents;
  document.getElementById('cardPaymentAmount').textContent = fmt(amountCents);
  document.getElementById('cardPaymentChargeLabel').textContent = 'Charge ' + fmt(amountCents);

  openModal('cardPaymentModal');

  // Reset Stripe.js elements between opens
  if (DirectPay.paymentElement) {
    try { DirectPay.paymentElement.unmount(); } catch (e) {}
  }
  DirectPay.elements = null;
  DirectPay.paymentElement = null;
  DirectPay.clientSecret = null;
  DirectPay.paymentIntentId = null;

  // Create the PaymentIntent
  let intent;
  try {
    const res = await fetch(ROUTES.paymentIntentCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        amount_cents: amountCents,
        // MARKER-PATCH-170B — preflight context
        customer_id: cart.customer ? cart.customer.id : null,
        has_service_line: cart.items.some(i => i.type === 'service'),
      }),
    });
    intent = await res.json();
    if (!intent.ok) throw new Error(intent.error || 'Could not initialize card payment.');
  } catch (e) {
    errBox.textContent = e.message;
    errBox.style.display = '';
    return;
  }

  DirectPay.clientSecret = intent.client_secret;
  DirectPay.paymentIntentId = intent.payment_intent;

  // Lazy-init Stripe.js with the tenant\'s publishable key
  if (!DirectPay.stripe) {
    DirectPay.stripe = Stripe(intent.publishable_key);
  }

  DirectPay.elements = DirectPay.stripe.elements({
    clientSecret: DirectPay.clientSecret,
    appearance: {
      theme: 'night',
      variables: {
        colorPrimary: '#BEF264',
        colorBackground: '#1c1c1c',
        colorText: '#f0f0f0',
        colorDanger: '#f87171',
        fontFamily: '-apple-system, BlinkMacSystemFont, sans-serif',
        borderRadius: '6px',
      },
    },
  });
  DirectPay.paymentElement = DirectPay.elements.create('payment', {
    layout: 'tabs',
  });
  DirectPay.paymentElement.mount('#card-payment-element');
  DirectPay.paymentElement.on('ready', () => {
    document.getElementById('cardPaymentChargeBtn').disabled = false;
  });
  DirectPay.paymentElement.on('change', (ev) => {
    document.getElementById('cardPaymentChargeBtn').disabled = !!ev.empty;
    if (ev.error) {
      errBox.textContent = ev.error.message;
      errBox.style.display = '';
    } else {
      errBox.style.display = 'none';
    }
  });
}

async function confirmCardPayment() {
  if (DirectPay.inFlight) return;
  DirectPay.inFlight = true;

  const errBox = document.getElementById('cardPaymentError');
  errBox.style.display = 'none';
  const chargeBtn = document.getElementById('cardPaymentChargeBtn');
  chargeBtn.disabled = true;
  document.getElementById('cardPaymentSpinner').style.display = '';

  let result;
  try {
    result = await DirectPay.stripe.confirmPayment({
      elements: DirectPay.elements,
      redirect: 'if_required',
    });
  } catch (e) {
    errBox.textContent = e.message || 'Payment failed.';
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  if (result.error) {
    errBox.textContent = result.error.message;
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  // Verify with our server (Stripe is source of truth, not the client)
  let conf;
  try {
    const res = await fetch(ROUTES.paymentIntentConfirm, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ payment_intent: DirectPay.paymentIntentId }),
    });
    conf = await res.json();
    if (!conf.ok) throw new Error(conf.error || 'Could not verify payment.');
  } catch (e) {
    errBox.textContent = e.message;
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  // Stash Stripe metadata for the sale commit
  cart.stripe_payment_intent_id = conf.payment_intent;
  cart.stripe_charge_id         = conf.stripe_charge_id;
  cart.card_brand               = conf.card_brand;
  cart.card_last4               = conf.card_last4;
  cart.card_funding             = conf.card_funding;
  cart.payment_reference        = (conf.card_brand && conf.card_last4)
    ? (conf.card_brand + ' ····' + conf.card_last4)
    : null;

  // MARKER-SPLIT-ANYORDER — a split card charge lands as a LOCKED row (the
  // charge is live; removal is an explicit Void). Remainder left → reopen
  // the tender modal and keep taking payments, any order. Covered → commit.
  if (SplitCard.pendingAmountCents != null) {
    cart.payments.push({
      method: 'card',
      amount_cents: DirectPay.chargeAmountCents,
      change_cents: 0,
      reference: cart.payment_reference,
      label: 'Card',
      locked: true,
      stripe_payment_intent_id: conf.payment_intent,
    });
    SplitCard.pendingAmountCents = null;
    cart.payment_method = null;
    cart.payment_reference = null;
    closeModal('cardPaymentModal');
    DirectPay.inFlight = false;
    if (splitRemaining() > 0) {
      renderSplit();
      resetGiftTender();      // MARKER-TENDERFIX
      tenderModalError('');
      openModal('tenderModal');
  if (typeof tenderPaint === 'function') { tenderPaint(); } // MARKER-TENDER-LAYOUT
      return;
    }
    cart.payment_method = 'split';
    commitTransaction({});
    return;
  }

  // MARKER-LAYAWAY-CARD — pointed at a plan there is no cart to commit: the
  // charge belongs on an existing sale's ledger. Same card, same Stripe
  // metadata, different destination.
  if (LayawayCard.planId) {
    closeModal('cardPaymentModal');
    DirectPay.inFlight = false;
    recordLayawayCardPayment(conf);
    return;
  }

  // Close modal and run the existing commit pipeline.
  // MARKER-PATCH-170B — wrap commit in our own try; if commitTransaction
  // shows the failure banner, we still hold the PI in cart.stripe_payment_intent_id.
  // commitTransaction itself calls autoRefundOnCommitFailure() if its commit fails.
  closeModal('cardPaymentModal');
  DirectPay.inFlight = false;
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
}

// MARKER-PATCH-170B — called by commitTransaction's error path when the
// charge has already authorized but the commit step failed. Refunds the
// PaymentIntent server-side and clears the Stripe metadata from the cart
// so the user doesn\'t double-charge.
async function autoRefundOnCommitFailure(reason) {
  if (!cart.stripe_payment_intent_id) return;
  const pi = cart.stripe_payment_intent_id;
  // Optimistically clear from cart so a retry doesn\'t re-send the stale PI
  cart.stripe_payment_intent_id = null;
  cart.stripe_charge_id = null;
  cart.card_brand = null;
  cart.card_last4 = null;
  cart.card_funding = null;
  cart.payment_reference = null;

  try {
    const res = await fetch(ROUTES.paymentIntentAutoRefund, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ payment_intent: pi, reason: reason || 'commit_failed' }),
    });
    const data = await res.json();
    const banner = document.getElementById('errBanner');
    if (data.ok) {
      banner.textContent = (banner.textContent || '') + ' The card charge was automatically refunded.';
    } else {
      banner.textContent = (banner.textContent || '') + ' WARNING: card was charged but refund failed — check Stripe dashboard for payment intent ' + pi;
    }
    banner.style.display = '';
  } catch (e) {
    const banner = document.getElementById('errBanner');
    banner.textContent = 'Card was charged but refund attempt failed. Stripe payment intent: ' + pi + '. Please refund manually in Stripe dashboard.';
    banner.style.display = '';
  }
}

document.getElementById('cardPaymentCancelBtn').addEventListener('click', () => {
  if (DirectPay.paymentElement) {
    try { DirectPay.paymentElement.unmount(); } catch (e) {}
  }
  DirectPay.inFlight = false;
  closeModal('cardPaymentModal');
});
document.getElementById('cardPaymentChargeBtn').addEventListener('click', confirmCardPayment);

// Helper used by openCardPaymentModal to compute the current charge total.
// Mirrors the math in commitTransaction\'s totals without firing a save.
function computeTotalsForCommit() {
  const sub = calcSubtotal();
  const tax = Math.round(sub * (CFG.taxRate || 0));
  const total = sub + tax - (cart.discountCents || 0);
  return { subtotal_cents: sub, tax_cents: tax, total_cents: Math.max(0, total) };
}

// MARKER-TENDERFIX -- what is the sale still asking for right now?
function tenderDueCents() {
  // MARKER-LAYAWAY-REGISTER — pointed at a plan, the amount due is the plan's.
  if (cart.layaway_target) { return cart.layaway_target.scheduled_cents || cart.layaway_target.balance_cents; }
  return (calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents)
       - (calcRefundSubtotal() + calcRefundTax());
}

// MARKER-TENDERFIX -- a checked card that can't cover the remainder is not an
// error, it's a split waiting to happen. Label the button with the action.
function gcSyncTenderButton() {
  const btn = document.getElementById('tenderConfirmBtn');
  if (!btn) return;
  const due = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();
  if (cart.payment_method === 'gift_card' && window.gcTender && gcTender.balance < due) {
    btn.disabled = false;
    btn.textContent = 'Add gift card ' + fmt(gcTender.balance) + ' — ' + fmt(due - gcTender.balance) + ' left';
  } else if (cart.payments.length > 0) {
    renderSplit();
  } else {
    btn.disabled = false;
    btn.textContent = 'Confirm';
  }
}

document.getElementById('tenderConfirmBtn').addEventListener('click', () => {
  // MARKER-BIZ-REGISTER — a PO-required customer is asked once, here, rather
  // than the invoice being rejected weeks later for a missing reference.
  if (cart.customer && cart.customer.po_required && !cart.po_number) {
    // MARKER-TENANT-CONFIRM — was window.prompt. This gates completing a sale
    // for a PO-required customer, so a suppressed dialog here meant a sale
    // that could not be rung through, with nothing on screen to say why.
    // In-app prompt; on a value, the same click is resumed.
    const askPo = (window.IntakeConfirm && typeof IntakeConfirm.prompt === 'function')
      ? IntakeConfirm.prompt({
          title: 'PO number required',
          message: (cart.customer.name || 'This customer') + ' requires a PO number for this sale.',
          placeholder: 'PO number',
          confirmText: 'Continue',
        })
      : Promise.resolve(window.prompt((cart.customer.name || 'This customer') + ' requires a PO number for this sale.'));

    askPo.then(function (po) {
      if (po === null || po === undefined) return;   // cancelled — do not complete
      const clean = String(po).trim();
      if (!clean) {
        if (window.IntakeToast) IntakeToast.error('A PO number is required for this customer.');
        return;
      }
      cart.po_number = clean;
      document.getElementById('tenderConfirmBtn').click(); // resume with the PO set
    });
    return;
  }

  // MARKER-TENDER-AMOUNT — a typed amount that does not cover what is due is
  // a split leg, not a full payment. Collect used to ignore this field and
  // complete for the whole total on the selected tender; now it does what Add
  // payment does and keeps the modal open for the rest. It can never charge
  // more than the person typed.
  {
    const amtEl = document.getElementById('splitAmountInput');
    const raw   = amtEl ? String(amtEl.value || '').replace(/[^0-9.]/g, '') : '';
    const typed = raw === '' ? null : Math.round(parseFloat(raw) * 100);
    const due   = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();

    if (!cart.layaway_target && typed !== null && !isNaN(typed) && typed > 0 && typed < due) {
      document.getElementById('splitAddBtn').click();
      tenderModalError(fmt(splitRemaining()) + ' still to collect — add another payment, or Collect when the amount covers it.');
      return;
    }
  }

  cart.payment_reference = document.getElementById('tenderRefInput').value.trim() || null;

  // MARKER-LAYAWAY-REGISTER — paying on a plan is not a sale. Record it and
  // stop; nothing below this line applies to a layaway payment.
  if (cart.layaway_target) { payOnLayaway(); return; }

  // MARKER-GIFTCARDS -- single gift tender: require a checked card whose
  // balance covers the full total; otherwise it belongs in a split.
  // MARKER-TENDERFIX -- gift tender. A short balance now STARTS the split
  // (the button already said it would) instead of erroring behind the modal.
  if (cart.payment_method === 'gift_card') {
    if (!window.gcTender || !gcTender.code) { tenderModalError('Check the gift card balance first.'); return; }
    const gcDue = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();
    if (gcTender.balance < gcDue) {
      const amtEl = document.getElementById('splitAmountInput');
      if (amtEl) amtEl.value = (gcTender.balance / 100).toFixed(2);
      document.getElementById('splitAddBtn').click();
      tenderModalError('');
      return;
    }
    if (cart.payments.length === 0) {
      cart.payment_reference = gcTender.code;
    }
  }

  // MARKER-SPLIT-TENDER — split path: tenders recorded row by row; the tip
  // modal is skipped (set tips before splitting so remaining math is stable).
  if (cart.payments.length > 0) {
    // MARKER-TENDERFIX -- was a bare `return`: the button did nothing and
    // never said why, so the split got abandoned and re-rung as one tender.
    if (splitRemaining() !== 0) {
      tenderModalError(fmt(splitRemaining()) + ' still to collect — add a payment for the rest, or remove a line above.');
      return;
    }
    cart.payment_method = 'split';
    cart.payment_reference = null;
    closeModal('tenderModal');
    commitTransaction({});
    return;
  }

  // MARKER-PATCH-170 — Direct Payments path
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }

  // MARKER-PATCH-172 — Send-payment-link path
  if (cart.payment_method === 'payment_link' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    closeModal('tenderModal');
    openPaymentLinkModal();
    return;
  }

  // Default path (cash, check, store_credit, mark_paid, or card-without-Stripe)
  closeModal('tenderModal');
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
});

// MARKER-PATCH-172 — Send-payment-link modal flow
async function openPaymentLinkModal() {
  const statusText = document.getElementById('paymentLinkStatusText');
  statusText.textContent = 'Creating payment link…';
  document.getElementById('paymentLinkQR').innerHTML = '';
  document.getElementById('paymentLinkUrl').textContent = '';
  openModal('paymentLinkModal');

  const totals = computeTotalsForCommit();
  const amountCents = totals.total_cents + (cart.tipCents || 0);
  document.getElementById('paymentLinkAmountValue').textContent = fmt(amountCents);

  let resp;
  try {
    const res = await fetch(ROUTES.checkoutSessionCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        amount_cents: amountCents,
        customer_id: cart.customer ? cart.customer.id : null,
        has_service_line: cart.items.some(i => i.type === 'service'),
        description: 'Purchase at ' + document.title,
        items: cart.items.map(serializeLine),
        tip_cents: cart.tipCents || 0,
        discount_cents: cart.discountCents || 0,
        sale_id: cart.draft_id || null,
      }),
    });
    resp = await res.json();
    if (!resp.ok) throw new Error(resp.error || 'Could not create payment link.');
  } catch (e) {
    closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
    showError(e.message);
    return;
  }

  PaymentLink.saleId = resp.sale_id;
  PaymentLink.sessionId = resp.session_id;
  PaymentLink.checkoutUrl = resp.checkout_url;
  DisplayMirror.payUrl = resp.checkout_url; // MARKER-REGISTER-RECON-DISPLAY
  queueDisplayMirror(true);

  // Render QR code
  const qrEl = document.getElementById('paymentLinkQR');
  qrEl.innerHTML = '';
  if (typeof qrcode === 'function') {
    const qr = qrcode(0, 'L');
    qr.addData(resp.checkout_url);
    qr.make();
    qrEl.innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
    // Constrain SVG size
    const svg = qrEl.querySelector('svg');
    if (svg) { svg.style.width = '200px'; svg.style.height = '200px'; }
  } else {
    qrEl.textContent = '(QR library failed to load. Use the URL below.)';
  }

  document.getElementById('paymentLinkUrl').textContent = resp.checkout_url;
  document.getElementById('paymentLinkStatusText').textContent = 'Waiting for customer to pay…';

  // Start polling
  startPaymentLinkPolling();
}

function startPaymentLinkPolling() {
  stopPaymentLinkPolling();
  PaymentLink.pollHandle = setInterval(checkPaymentLinkStatus, 3000);
}
function stopPaymentLinkPolling() {
  if (PaymentLink.pollHandle) {
    clearInterval(PaymentLink.pollHandle);
    PaymentLink.pollHandle = null;
  }
}

async function checkPaymentLinkStatus() {
  if (!PaymentLink.saleId) return;
  try {
    const res = await fetch(ROUTES.checkoutSessionCheck, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ sale_id: PaymentLink.saleId }),
    });
    const data = await res.json();
    if (!data.ok) return;

    if (data.status === 'succeeded') {
      stopPaymentLinkPolling();
      closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
      // Show the receipt screen using the existing flow
      showReceipt({ sale_number: data.sale_number, total_cents: data.total_cents, sale_id: data.sale_id }); // MARKER-PATCH-322
      // Clear cart since the sale completed
      cart.draft_id = null;
      cart.customer = null;
      cart.items = [];
      cart.refund_lines = [];
      cart.tipCents = 0;
      cart.discountCents = 0;
      renderAll();
      PaymentLink.saleId = null;
      PaymentLink.sessionId = null;
      PaymentLink.checkoutUrl = null;
      return;
    }

    if (data.status === 'expired') {
      stopPaymentLinkPolling();
      document.getElementById('paymentLinkStatusText').textContent = 'Link expired. Cancel and try again.';
    }
  } catch (e) {
    // Transient network errors — keep polling.
  }
}

document.getElementById('paymentLinkCopyBtn').addEventListener('click', () => {
  if (!PaymentLink.checkoutUrl) return;
  navigator.clipboard.writeText(PaymentLink.checkoutUrl).then(() => {
    const btn = document.getElementById('paymentLinkCopyBtn');
    const orig = btn.textContent;
    btn.textContent = 'Copied ✓';
    setTimeout(() => { btn.textContent = orig; }, 1500);
  });
});

// MARKER-PATCH-192 — "Cancel link": explicit destructive action. Expires the
// Stripe session and marks the sale cancelled. Only fires on deliberate click.
document.getElementById('paymentLinkCancelBtn').addEventListener('click', async () => {
  if (!(await iaConfirm('Cancel this payment link? The customer will no longer be able to pay it.'))) return; // MARKER-INLINE-CONFIRM-1
  stopPaymentLinkPolling();
  if (PaymentLink.saleId) {
    try {
      await fetch(ROUTES.checkoutSessionCancel, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ sale_id: PaymentLink.saleId }),
      });
    } catch (e) {}
  }
  PaymentLink.saleId = null;
  PaymentLink.sessionId = null;
  PaymentLink.checkoutUrl = null;
  closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
});

// MARKER-PATCH-192 — "Done — keep link live": the operator steps away while the
// customer pays on their own time. Stops the foreground poll and closes the
// modal, but leaves the sale PENDING and the Stripe session active. The webhook
// will promote it when the customer pays; the appointment surfaces the pending
// state so it's never lost. Does NOT cancel anything.
document.getElementById('paymentLinkDoneBtn').addEventListener('click', () => {
  stopPaymentLinkPolling();
  PaymentLink.saleId = null;
  PaymentLink.sessionId = null;
  PaymentLink.checkoutUrl = null;
  closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
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

// MARKER-REGISTER-DISCOUNT — whole-sale discount handlers.
document.getElementById('discountBtn').addEventListener('click', () => {
  document.getElementById('discCodeMsg').textContent = '';
  document.getElementById('discCodeInput').value = cart.discountCode || '';
  document.getElementById('discAmtInput').value = cart.discountCents && !cart.discountCode
    ? (cart.discountCents / 100).toFixed(2) : '';
  document.getElementById('discPctInput').value = '';
  openModal('discountModal');
});

document.getElementById('discCodeApply').addEventListener('click', async () => {
  const code = (document.getElementById('discCodeInput').value || '').trim();
  const msg  = document.getElementById('discCodeMsg');
  if (!code) { msg.style.color = 'var(--ia-text-dim)'; msg.textContent = 'Enter a code first.'; return; }

  const sub = calcSubtotal();
  msg.style.color = 'var(--ia-text-dim)';
  msg.textContent = 'Checking…';

  try {
    const qs = new URLSearchParams({
      code: code,
      subtotal_cents: String(sub),
    });
    if (cart.customer) qs.set('customer_id', cart.customer.id);

    const res  = await fetch(ROUTES.discountValidate + '?' + qs.toString(), {
      headers: { 'Accept': 'application/json' },
    });
    const data = await res.json();

    if (!data.ok) {
      msg.style.color = '#e07a7a';
      msg.textContent = data.reason || 'That code cannot be used.';
      return;
    }

    cart.discountCents = data.amount_cents;
    cart.discountCode  = data.code;
    msg.style.color = 'var(--ia-accent)';
    msg.textContent = (data.summary ? data.summary + ' — ' : '') + fmt(data.amount_cents) + ' off.';
    document.getElementById('discAmtInput').value = '';
    document.getElementById('discPctInput').value = '';
    renderTotals();
  } catch (e) {
    msg.style.color = '#e07a7a';
    msg.textContent = 'Could not check that code.';
  }
});

document.getElementById('discApplyBtn').addEventListener('click', () => {
  const amt = parseFloat(document.getElementById('discAmtInput').value);
  const pct = parseFloat(document.getElementById('discPctInput').value);
  const sub = calcSubtotal();

  // A manual amount replaces any code — one whole-sale discount at a time.
  if (!isNaN(pct) && pct > 0) {
    cart.discountCents = Math.min(sub, Math.floor(sub * Math.min(pct, 100) / 100));
    cart.discountCode  = null;
  } else if (!isNaN(amt) && amt > 0) {
    cart.discountCents = Math.min(sub, Math.round(amt * 100));
    cart.discountCode  = null;
  }

  closeModal('discountModal');
  renderTotals();
});

document.getElementById('discClearBtn').addEventListener('click', () => {
  cart.discountCents = 0;
  cart.discountCode  = null;
  document.getElementById('discCodeInput').value = '';
  document.getElementById('discAmtInput').value = '';
  document.getElementById('discPctInput').value = '';
  document.getElementById('discCodeMsg').textContent = '';
  closeModal('discountModal');
  renderTotals();
});

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
        po_number: cart.po_number || null, // MARKER-BIZ-REGISTER
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
        override_reserved: !!cart.override_reserved, // MARKER-RESERVE-OVERRIDE
        items: hasNewSale ? cart.items.map(serializeLine) : [],
        refund: {
          original_sale_id: cart.refund_meta.original_sale_id,
          // MARKER-REFUND-QTY — the quantity the cashier chose is now sent and
          // is authoritative on the server, along with where the goods went.
          items: cart.refund_lines.map(r => ({
            sale_item_id: r.original_item_id,
            quantity: r.qty,
            disposition: r.disposition || 'restock',
          })),
          item_ids: cart.refund_lines.map(r => r.original_item_id),
          refund_method: cart.payment_method,
          // MARKER-GC-FUNCTIONS
          gift_card_code: (cart.payment_method === 'gift_card'
            ? (document.getElementById('refundGiftCode')?.value || '').trim() || null
            : null),
        },
      };
    } else if (cart.draft_id) {
      // Draft-backed pure sale — promote draft to paid (existing path).
      url = ROUTES.commitDraft + '/' + cart.draft_id + '/commit';
      payload = {
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        po_number: cart.po_number || null, // MARKER-BIZ-REGISTER
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        tip_cents: cart.tipCents,
        customer_id: cart.customer ? cart.customer.id : null,
        skip_receipt: cart.skipReceipt ? 1 : 0, // MARKER-PATCH-161
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
      };
    } else {
      // Fallback path — pure sale, no draft, send full cart.
      url = ROUTES.storeSale;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        discount_cents: cart.discountCents,
        // MARKER-REGISTER-DISCOUNT — the field the server actually applies
        sale_discount_cents: cart.discountCents || 0,
        discount_code: cart.discountCode || null,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        po_number: cart.po_number || null, // MARKER-BIZ-REGISTER
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        items: cart.items.map(serializeLine),
        skip_receipt: cart.skipReceipt ? 1 : 0, // MARKER-PATCH-161
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
      };
    }

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      // MARKER-PATCH-170B — auto-refund the card if we authorized one
      if (cart.stripe_payment_intent_id) {
        await autoRefundOnCommitFailure(data.error || 'commit_failed');
      }
      showError(data.error || 'Could not complete the transaction.');
      return;
    }
    showReceipt(data);
  } catch (e) {
    // MARKER-OFFLINE-SYNC — network failure: queue the sale on-device when eligible.
    if (await osTryQueueCommit()) return;
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
  if (i.type === 'gift_card') { // MARKER-GIFTCARDS
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
    out.gift_card = i.gift || {};
  }
  return linePriceFields(i, out); // MARKER-REGISTER-LINE-FIX
}

// MARKER-REGISTER-LINE-FIX — a line's price edit (MARKER-LINE-PRICE) on the
// wire. Since Sep 12 these fields never left the browser: the screen showed
// the edited price and the sale recorded the full one. Discount is per unit
// on screen and per line on the wire; an override is a new unit price. The
// server drops both for staff without register.line_price.
function linePriceFields(i, out) {
  if (i.type === 'gift_card') { return out; }
  const d = Math.max(0, Math.round((i.line_discount_cents || 0) * i.qty));
  if (d > 0) { out.discount_cents = d; }
  if (i.line_override_cents) { out.unit_price_cents = i.line_override_cents; }
  return out;
}

// MARKER-REGISTER-LINE-FIX — a resumed held sale gets its price edit back.
// The server returns the stored unit price, the item's catalog price and the
// per-unit discount; the same rule as applyLinePrice() rebuilds the line.
function restoreLinePrice(i, line) {
  if (line.type === 'open_item' || line.type === 'gift_card') { return line; }
  const cat  = (typeof i.catalog_price_cents === 'number') ? i.catalog_price_cents : null;
  const disc = Math.max(0, i.line_discount_cents || 0);
  let unit = line.price_cents;
  if (cat !== null && unit !== cat) {
    if (unit > cat) {
      line.line_override_cents = unit;          // priced up: a new price
    } else {
      line.line_discount_cents = cat - unit;    // priced down: a discount
    }
    line.price_cents = cat;
  }
  if (disc > 0) { line.line_discount_cents = (line.line_discount_cents || 0) + disc; }
  const base = line.line_override_cents || line.price_cents;
  const eff  = base - (line.line_discount_cents || 0);
  if (eff !== line.price_cents) { line.effective_price_cents = eff; }
  return line;
}

function showError(msg) {
  const el = document.getElementById('errBanner');
  el.textContent = msg;
  el.style.display = '';
  // MARKER-PATCH-170C — shake to draw attention, even on repeat errors.
  // Re-trigger by removing then re-adding the class on the next frame.
  el.classList.remove('reg-err--shake');
  requestAnimationFrame(() => {
    requestAnimationFrame(() => { el.classList.add('reg-err--shake'); });
  });
}

// MARKER-PATCH-170C — pre-flight cart validation.
// Returns null if the cart is commit-able, or a blocker object
// { title, message, actionLabel, actionFn } describing what's wrong.
// Order matters: surface the most-actionable problem first.
function preflightCheck() {
  // Service-line-without-customer is the only blocker we know about today.
  // More can be added (e.g. price-zero items, missing location) without
  // changing the call site.
  const hasServiceLine = cart.items.some(i => i.type === 'service');
  if (hasServiceLine && !cart.customer) {
    return {
      // MARKER-REGISTER-LINE-FIX — two line-price fields sat here (put in the
      // wrong function on Sep 12). They referenced a line that doesn't exist
      // in this scope, so Collect payment threw on every cart with a service
      // and no customer. They live in linePriceFields() now.
      title: 'Add a customer',
      message: 'A customer is required when the sale includes a service. Attach a customer and we\'ll continue.',
      actionLabel: 'Add customer →',
      actionFn: () => {
        closeModal('preflightModal');
        openCustomerModal();
      },
    };
  }
  return null;
}

function openPreflightModal(blocker) {
  document.getElementById('preflightTitle').textContent = blocker.title;
  document.getElementById('preflightLede').textContent  = blocker.message;
  const btn = document.getElementById('preflightActionBtn');
  btn.textContent = blocker.actionLabel;
  // Replace previous click handler — clone the node to drop bound listeners.
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', blocker.actionFn);
  openModal('preflightModal');
}
// MARKER-PATCH-187 — after a completed sale the receipt sits briefly, then the
// register auto-resets to a fresh state. A visible countdown shows it coming;
// clicking "New sale" (or any cart interaction) resets immediately and cancels
// the timer.
const RECEIPT_AUTO_RESET_SECONDS = 45;
let receiptResetTimer = null;
let receiptCountdownTimer = null;
let receiptSaleId = null;        // MARKER-PATCH-322
let receiptCustomerEmail = null; // MARKER-PATCH-322

function clearReceiptTimers() {
  if (receiptResetTimer) { clearTimeout(receiptResetTimer); receiptResetTimer = null; }
  if (receiptCountdownTimer) { clearInterval(receiptCountdownTimer); receiptCountdownTimer = null; }
}

async function resetRegisterToFresh() {
  clearReceiptTimers();
  cart.draft_id = null;
  cart.customer = null;
  cart.items = [];
  cart.refund_lines = [];
  cart.refund_meta = null;
  cart.tipCents = 0; cart.discountCents = 0; cart.discountCode = null; // MARKER-REGISTER-DISCOUNT
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  if (typeof resetGiftTender === 'function') resetGiftTender(); // MARKER-TENDERFIX
  closeModal('receiptModal');
  renderCart();
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  refreshDraftsBanner(await loadDrafts());
}

function showReceipt(data) {
  document.getElementById('receiptNum').textContent = data.sale_number;
  document.getElementById('receiptTotal').textContent = fmt(data.total_cents);
  // MARKER-REGISTER-LINE-FIX — cash change, from the Cash received box.
  { const row = document.getElementById('receiptChange');
    const chg = (cart && cart.payment_method === 'cash') ? (window.cashChangeCents || 0) : 0;
    if (row) {
      row.style.display = chg > 0 ? '' : 'none';
      document.getElementById('receiptChangeAmt').textContent = fmt(chg);
    }
    window.cashChangeCents = 0; }
  openModal('receiptModal');

  // MARKER-PATCH-322 — capture the sale for print/email before the cart clears.
  receiptSaleId = data.sale_id || null;
  receiptCustomerEmail = (typeof cart !== 'undefined' && cart && cart.customer && cart.customer.email) ? cart.customer.email : null;
  var _rPrint = document.getElementById('receiptPrintBtn');
  var _rEmail = document.getElementById('receiptEmailBtn');
  var _rPrompt = document.getElementById('receiptEmailPrompt');
  var _rMsg = document.getElementById('receiptEmailMsg');
  if (_rPrint) _rPrint.style.display = receiptSaleId ? '' : 'none';
  if (_rEmail) _rEmail.style.display = receiptSaleId ? '' : 'none';
  if (_rPrompt) _rPrompt.style.display = 'none';
  if (_rMsg) { _rMsg.style.display = 'none'; _rMsg.textContent = ''; }

  // MARKER-PATCH-232B — round-trip receipts: when the register was opened
  // with a return_to, the receipt offers (and the countdown takes) the way
  // back instead of resetting to a fresh register.
  const backBtn = document.getElementById('receiptBackTo');
  if (backBtn) {
    if (window.registerReturnTo) {
      backBtn.href = window.registerReturnTo;
      backBtn.style.display = '';
      backBtn.textContent = 'Back to where you were →';
      const autoEl = document.getElementById('receiptAutoReset');
      if (autoEl) autoEl.innerHTML = 'Heading back in <span id="receiptCountdown">45</span>s';
    } else {
      backBtn.style.display = 'none';
    }
  }

  // Start the auto-reset countdown.
  clearReceiptTimers();
  let remaining = RECEIPT_AUTO_RESET_SECONDS;
  const countdownEl = document.getElementById('receiptCountdown');
  if (countdownEl) countdownEl.textContent = remaining;
  receiptCountdownTimer = setInterval(() => {
    remaining -= 1;
    if (countdownEl) countdownEl.textContent = Math.max(0, remaining);
    if (remaining <= 0) clearInterval(receiptCountdownTimer);
  }, 1000);
  receiptResetTimer = setTimeout(() => {
    if (window.registerReturnTo) { window.location.href = window.registerReturnTo; return; }
    resetRegisterToFresh();
  }, RECEIPT_AUTO_RESET_SECONDS * 1000);
}

document.getElementById('receiptNewSale').addEventListener('click', () => { resetRegisterToFresh(); });

// MARKER-PATCH-322 — print + email the just-completed receipt.
(function () {
  var printBtn = document.getElementById('receiptPrintBtn');
  var emailBtn = document.getElementById('receiptEmailBtn');
  var promptEl = document.getElementById('receiptEmailPrompt');
  var inputEl  = document.getElementById('receiptEmailInput');
  var sendEl   = document.getElementById('receiptEmailSend');
  var msgEl    = document.getElementById('receiptEmailMsg');

  // Stop the auto-reset countdown once the cashier interacts here.
  function holdReset() {
    try { clearReceiptTimers(); } catch (e) {}
    var a = document.getElementById('receiptAutoReset');
    if (a) a.style.display = 'none';
  }

  if (printBtn) printBtn.addEventListener('click', function () {
    if (!receiptSaleId) return;
    holdReset();
    if (window.openPrintComposer) { window.openPrintComposer('sale', receiptSaleId, { type: 'receipt', format: 't80' }); return; } // MARKER-PATCH-338
    if (!ROUTES.saleReceipt) return;
    var url = ROUTES.saleReceipt.replace('__ID__', encodeURIComponent(receiptSaleId)) + '?embed=1';
    var f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.src = url;
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (e) { window.open(url.replace('?embed=1', ''), '_blank'); }
      setTimeout(function () { f.remove(); }, 2000);
    };
    document.body.appendChild(f);
  });

  function sendReceipt(email) {
    if (!receiptSaleId || !ROUTES.resendReceipt) return;
    var url = ROUTES.resendReceipt.replace('__ID__', encodeURIComponent(receiptSaleId));
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (sendEl) sendEl.disabled = true;
    fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      body: email ? ('email=' + encodeURIComponent(email)) : ''
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (sendEl) sendEl.disabled = false;
      if (d && d.ok) {
        var to = email || receiptCustomerEmail;
        if (msgEl) { msgEl.style.display = ''; msgEl.textContent = 'Receipt sent' + (to ? ' to ' + to : '') + '.'; }
        if (promptEl) promptEl.style.display = 'none';
      } else if (msgEl) {
        msgEl.style.display = ''; msgEl.textContent = (d && d.error) || 'Could not send receipt.';
      }
    })
    .catch(function () { if (sendEl) sendEl.disabled = false; if (msgEl) { msgEl.style.display = ''; msgEl.textContent = 'Could not send receipt.'; } });
  }

  if (emailBtn) emailBtn.addEventListener('click', function () {
    if (!receiptSaleId) return;
    holdReset();
    if (receiptCustomerEmail) { sendReceipt(null); }
    else { if (promptEl) promptEl.style.display = 'flex'; if (inputEl) inputEl.focus(); }
  });
  if (sendEl) sendEl.addEventListener('click', function () {
    var v = ((inputEl && inputEl.value) || '').trim();
    if (!v || v.indexOf('@') < 0) { if (inputEl) inputEl.focus(); return; }
    sendReceipt(v);
  });
  if (inputEl) inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (sendEl) sendEl.click(); } });
})();

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
  // MARKER-INTENT-DRAFTS — these are sales somebody chose to keep, so say so.
  // "open drafts" described a thing that happened to you; "held" describes a
  // thing you did.
  const held = others.filter(d => d.held);
  const shown = held.length ? held : others;
  const word = shown.length === 1 ? 'held sale' : 'held sales';
  document.getElementById('draftsBannerLabel').textContent =
    shown.length + ' ' + word + ' at this location';
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
    list.innerHTML = '<div class="reg-empty">No held or recovered carts.</div>';
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
    cart.tax_locked = !!data.draft.tax_locked;
    // MARKER-PAY-PERSIST — the money comes back with the cart. This is the
    // whole point: take $300, refresh, and the $300 is still there.
    cart.payments = data.draft.payments || [];
    cart.items = (data.draft.items || []).map(i => restoreLinePrice(i, {
      key: ++lineKey,
      type: i.type,
      source_id: i.source_id,
      name: i.name,
      price_cents: i.price_cents,
      qty: i.qty,
      is_taxable: i.is_taxable,
      tax_cents: i.tax_cents || 0,
      tax_rate_snapshot: i.tax_rate_snapshot,
    }));
    closeModal('draftsModal');
    renderCart();
    // MARKER-PAID-VISIBLE — renderSplit draws the legs; it does not touch the
    // panel totals or the modal header. Resuming a part-paid sale showed the
    // full total until a tender was clicked.
    if (typeof renderSplit === 'function') { renderSplit(); }
    if (typeof tenderPaint === 'function') { tenderPaint(); }
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error loading draft.');
    closeModal('draftsModal');
  }
}

// MARKER-NO-ORPHAN-MONEY — the drafts list reaches the same hole: discarding
// a part-paid sale used to delete the money with it, server-side, with no
// check at all. The server now refuses; this turns that refusal into a choice.
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

    // MARKER-NO-ORPHAN-MONEY — the server refuses a draft holding payments.
    // Turn that into the one action that resolves it.
    if (!data.ok && data.needs_refund) {
      const go = await iaConfirm('This sale is holding ' + fmt(data.paid_cents)
        + ' in payments. Refund it all and void the sale?');
      if (!go) { return; }
      const r2 = await fetch(ROUTES.voidSale, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
        body: JSON.stringify({ draft_id: id }),
      });
      const d2 = await r2.json();
      if (!d2.ok) { showError(d2.error || 'Could not void that sale.'); return; }
      if (window.IntakeToast) { IntakeToast.success(fmt(data.paid_cents) + ' refunded. Sale voided.'); }
      refreshDraftsBanner(await loadDrafts());
      return;
    }
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

// Auto-load a draft from ?draft=X in the URL. Used by the cash-pays-for-class
// flow in ClassController::registerViaCash, which prepares a drop-in cart and
// redirects here so the admin can take payment. Removes the param after load
// so a refresh doesn't re-trigger.
(function autoloadDraftFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const draftId = params.get('draft');
  if (!draftId) return;
  // Strip the param so this only fires once.
  params.delete('draft');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);
  resumeDraft(draftId);
})();

// MARKER-PATCH-195 — Payment-link status view. Opened from the appointment
// "Payment link sent" banner via ?status=<sale_id>. Shows a live timeline of
// the outstanding link, polls for resolution, and offers copy / cancel.
const LinkStatus = { saleId: null, sessionId: null, url: null, poll: null };

function lsRenderTimeline(sale, liveStatus) {
  const paid = (liveStatus === 'succeeded') || sale.payment_status === 'paid' || (sale.paid_cents > 0);
  const expired = (liveStatus === 'expired') || sale.sale_status === 'cancelled';
  const created = sale.created_at ? lsFmtDate(sale.created_at) : '';
  const rows = [];
  rows.push(['done', 'Link created', created]);
  rows.push(['done', 'Link sent to customer', sale.customer && sale.customer.email ? sale.customer.email : '']);
  if (paid) {
    rows.push(['done', 'Payment received', sale.paid_at ? lsFmtDate(sale.paid_at) : '']);
    rows.push(['done', 'Recorded to ledger', sale.payments && sale.payments.length ? (sale.payments[0].method_label || 'card') : '']);
  } else if (expired) {
    rows.push(['', 'Link expired without payment', '']);
  } else {
    rows.push(['now', 'Awaiting payment', 'checking automatically…']);
    rows.push(['', 'Payment received', '— pending —']);
    rows.push(['', 'Recorded to ledger', '— pending —']);
  }
  return rows.map(r =>
    '<div class="ls-te ' + r[0] + '"><div class="tt">' + esc(r[1]) + '</div>' +
    (r[2] ? '<div class="td">' + esc(r[2]) + '</div>' : '') + '</div>'
  ).join('');
}

function lsSetPill(status) {
  const el = document.getElementById('lsStatusPill');
  if (status === 'succeeded' || status === 'paid') { el.className = 'ls-pill paid'; el.textContent = 'Paid'; }
  else if (status === 'expired') { el.className = 'ls-pill expired'; el.textContent = 'Expired'; }
  else { el.className = 'ls-pill pending'; el.textContent = 'Awaiting payment'; }
}

function lsFmtDate(iso){ if(!iso) return ''; const d=new Date(iso); if(isNaN(d.getTime())) return iso; return d.toLocaleString(undefined,{year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}); }
function esc(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function openLinkStatus(saleId) {
  if (!saleId) return;
  LinkStatus.saleId = saleId;
  openModal('linkStatusModal');
  document.getElementById('lsHeader').textContent = 'Loading…';
  document.getElementById('lsTimeline').innerHTML = '';
  // Fetch the sale detail (showSaleJson — includes checkout + payments).
  let sale = null;
  try {
    const showUrl = ROUTES.saleShow ? ROUTES.saleShow.replace('__ID__', encodeURIComponent(saleId)) : null;
    if (showUrl) {
      const r = await fetch(showUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
      const d = await r.json();
      if (d.ok) sale = d.sale;
    }
  } catch (e) {}
  if (!sale) { document.getElementById('lsHeader').textContent = 'Could not load this sale.'; return; }

  const status = (sale.payment_status === 'paid' || sale.paid_cents > 0) ? 'paid'
               : (sale.sale_status === 'cancelled' ? 'expired' : 'pending');
  lsSetPill(status);
  document.getElementById('lsHeader').innerHTML =
    fmt(sale.total_cents) + ' · ' + esc(sale.customer ? sale.customer.name : 'No customer') +
    (sale.sale_number ? ' · <span style="font-family:var(--ia-font-mono);font-size:11px">' + esc(sale.sale_number) + '</span>' : '');
  document.getElementById('lsTimeline').innerHTML = lsRenderTimeline(sale, status === 'paid' ? 'succeeded' : (status === 'expired' ? 'expired' : 'pending'));

  // Cancel-link action only while still pending.
  const cancelBtn = document.getElementById('lsCancelLinkBtn');
  cancelBtn.style.display = (status === 'pending') ? '' : 'none';

  // Poll for resolution while pending.
  if (LinkStatus.poll) clearInterval(LinkStatus.poll);
  if (status === 'pending') {
    LinkStatus.poll = setInterval(async () => {
      try {
        const res = await fetch(ROUTES.checkoutSessionCheck, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ sale_id: saleId }),
        });
        const d = await res.json();
        if (!d.ok) return;
        if (d.status === 'succeeded' || d.status === 'expired') {
          clearInterval(LinkStatus.poll); LinkStatus.poll = null;
          openLinkStatus(saleId); // re-render terminal state
        }
      } catch (e) {}
    }, 4000);
  }
}

function lsClose() {
  if (LinkStatus.poll) { clearInterval(LinkStatus.poll); LinkStatus.poll = null; }
  closeModal('linkStatusModal');
}

document.getElementById('lsCloseBtn').addEventListener('click', lsClose);
document.getElementById('lsCancelLinkBtn').addEventListener('click', async () => {
  if (!LinkStatus.saleId) return;
  if (!(await iaConfirm('Cancel this payment link? The customer will no longer be able to pay it.'))) return; // MARKER-INLINE-CONFIRM-1
  try {
    await fetch(ROUTES.checkoutSessionCancel, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ sale_id: LinkStatus.saleId }),
    });
  } catch (e) {}
  lsClose();
});

// Autoload from ?status=<sale_id> (from the appointment banner).
(function autoloadStatusFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const sid = params.get('status');
  if (!sid) return;
  params.delete('status');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);
  openLinkStatus(sid);
})();

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

// Auto-open the refund picker when arriving from the sale-detail modal's
// "Refund this sale" button (?refund=SALE_NUMBER). Looks the sale up by
// number directly so it doesn't depend on the search input being populated.
(function autoloadRefundFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const saleNumber = params.get('refund');
  if (!saleNumber) return;
  // Strip the param so a refresh doesn't re-trigger.
  params.delete('refund');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);

  (async () => {
    try {
      const url = new URL(ROUTES.lookupSale, window.location.origin);
      url.searchParams.set('sale_number', saleNumber);
      const r = await fetch(url, {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (!d.ok) { showError(d.error || 'Sale not found.'); return; }
      refundPickerSale = d.sale;
      renderRefundPicker();
      openModal('refundModal');
    } catch (e) {
      showError('Network error loading sale.');
    }
  })();
})();

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
    // Tax on a partial refund is a proportional share of original line tax.
    const fullQty = item.quantity || 1;
    const taxShare = item.tax_cents
      ? Math.round((item.tax_cents * qty) / fullQty)
      : 0;
    cart.refund_lines.push({
      key: ++lineKey,
      original_sale_id:  sale.id,
      original_item_id:  item.id,
      type:              item.type,
      name:              item.name,
      qty:               qty,
      price_cents:       item.unit_price_cents,
      tax_cents:         taxShare,
      is_taxable:        !!item.is_taxable,
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
  // MARKER-PATCH-232B — capture return_to BEFORE replaceState wipes the
  // query string. Local paths only; anything else is ignored.
  const rawReturnTo = params.get('return_to') || '';
  window.registerReturnTo = (rawReturnTo.startsWith('/') && !rawReturnTo.startsWith('//')) ? rawReturnTo : null;
  const resumeId = params.get('resume');
  if (!resumeId) return;
  // Strip the param from the URL so a refresh doesn't re-trigger.
  const cleanUrl = window.location.pathname;
  window.history.replaceState({}, '', cleanUrl);
  // Reuse the existing resumeDraft path — it handles drafts and quotes both.
  resumeDraft(resumeId);
})();

/* ===================================================================
   Appointment tray — lazy-loads on click. Lists every pending sale
   that came from a completed appointment, lets staff jump to one.
   =================================================================== */
(function () {
  var toggle = document.getElementById('appointment-tray-toggle');
  var listEl = document.getElementById('appointment-tray-list');
  if (!toggle || !listEl) return;

  var loaded = false;
  var open = false;

  toggle.addEventListener('click', function () {
    if (!loaded) {
      fetch('{{ route("tenant.register.appointment-tray", ["subdomain" => tenant()->subdomain]) }}', {
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok || !data.sales || !data.sales.length) {
            listEl.innerHTML = '<div style="padding:14px;font-size:12px;color:var(--ia-text-dim);text-align:center">No pending appointment sales.</div>';
            return;
          }
          listEl.innerHTML = data.sales.map(function (s) {
            // MARKER-PATCH-180 — row carries data-sale-id; a × dismiss button
            // removes the parked draft from the tray. Resume happens on the
            // row body (not the buttons), wired via delegation below.
            return '<div class="appt-tray-row" data-sale-id="' + escapeHtml(s.id) + '" style="display:grid;grid-template-columns:1fr auto auto auto;gap:14px;align-items:center;padding:10px 12px;background:var(--ia-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin:4px 0">'
                 + '<div class="appt-tray-resume" style="cursor:pointer">'
                 + '<div style="font-weight:500;font-size:13px">' + escapeHtml(s.customer_name) + (s.ra_number ? ' — Appt ' + escapeHtml(s.ra_number) : '') + '</div>'
                 + '<div style="font-size:11px;color:var(--ia-text-dim);margin-top:2px">' + escapeHtml(s.sale_number) + ' · ' + s.item_count + ' line' + (s.item_count === 1 ? '' : 's') + '</div>'
                 + '</div>'
                 + '<div style="font-weight:500;font-size:14px">' + escapeHtml(s.total_display) + '</div>'
                 + '<button type="button" class="ia-btn ia-btn--primary ia-btn--sm appt-tray-pay">Take payment →</button>'
                 + '<button type="button" class="appt-tray-dismiss" aria-label="Remove from list" title="Remove from list" style="background:none;border:none;color:var(--ia-text-dim);font-size:18px;line-height:1;cursor:pointer;padding:4px 8px">×</button>'
                 + '</div>';
          }).join('');
          loaded = true;
          wireTrayRowActions();
        });
    }
    open = !open;
    listEl.style.display = open ? 'block' : 'none';
    toggle.textContent = open ? 'Hide list' : 'View list';
  });

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // MARKER-PATCH-180 — wire resume/pay/dismiss on tray rows.
  function wireTrayRowActions() {
    listEl.querySelectorAll('.appt-tray-row').forEach(function (row) {
      var saleId = row.getAttribute('data-sale-id');
      var resume = function () { window.location.href = '?resume=' + saleId; };
      var body = row.querySelector('.appt-tray-resume');
      var pay  = row.querySelector('.appt-tray-pay');
      if (body) body.addEventListener('click', resume);
      if (pay)  pay.addEventListener('click', function (e) { e.stopPropagation(); resume(); });
      var dismiss = row.querySelector('.appt-tray-dismiss');
      if (dismiss) dismiss.addEventListener('click', async function (e) {
        e.stopPropagation();
        dismiss.disabled = true;
        try {
          var res = await fetch('{{ route("tenant.register.appointment-tray.dismiss", ["subdomain" => tenant()->subdomain]) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ sale_id: saleId }),
            credentials: 'same-origin',
          });
          var data = await res.json();
          if (!data.ok) { dismiss.disabled = false; if (window.IntakeToast) IntakeToast.error(data.error || 'Could not remove.'); return; }
          row.style.transition = 'opacity .2s ease';
          row.style.opacity = '0';
          setTimeout(function () {
            row.remove();
            // Decrement the banner count; hide the whole banner if empty.
            var countEl = document.querySelector('#appointment-tray-banner div[style*="font-weight:500"]');
            var banner = document.getElementById('appointment-tray-banner');
            if (!listEl.querySelector('.appt-tray-row')) {
              if (banner) banner.style.display = 'none';
              listEl.style.display = 'none';
            } else if (countEl) {
              var n = (listEl.querySelectorAll('.appt-tray-row').length);
              countEl.textContent = n + (n === 1 ? ' appointment is' : ' appointments are') + ' ready for checkout';
            }
          }, 210);
        } catch (err) {
          dismiss.disabled = false;
          if (window.IntakeToast) IntakeToast.error('Network error.');
        }
      });
    });
  }
})();
</script>

@if(($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true))
{{-- MARKER-PATCH-170 — Stripe.js for Direct Payments hand-keyed flow --}}
<script src="https://js.stripe.com/v3/"></script>
{{-- MARKER-PATCH-172 — QR code library for send-payment-link --}}
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
@endif
@endpush
