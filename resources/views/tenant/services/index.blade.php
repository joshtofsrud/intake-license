@extends('layouts.tenant.app')
@php $pageTitle = 'Services'; @endphp

@push('styles')
<style>
.sv-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.sv-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;transition:color var(--ia-t),border-color var(--ia-t)}
.sv-subnav-tab:hover{color:var(--ia-text)}
.sv-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.sv-subnav-tab-count{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:8px;background:var(--ia-surface-2);font-size:11px;color:var(--ia-text-muted)}
.sv-subnav-tab.is-active .sv-subnav-tab-count{background:var(--ia-accent-soft);color:var(--ia-accent)}
.sv-view-toggle{display:inline-flex;background:var(--ia-surface);border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);padding:2px;gap:2px}
.sv-view-toggle-btn{padding:5px 11px;border-radius:6px;font-size:12px;color:var(--ia-text-muted);display:inline-flex;align-items:center;gap:5px;transition:all var(--ia-t);background:none;border:none;cursor:pointer}
.sv-view-toggle-btn:hover{color:var(--ia-text)}
.sv-view-toggle-btn.is-active{background:var(--ia-accent-soft);color:var(--ia-accent)}
.sv-view-toggle-btn svg{width:14px;height:14px}
.sv-filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.sv-search-box{position:relative;flex:1;max-width:340px}
.sv-search-box input{width:100%;padding:8px 12px 8px 34px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none}
.sv-search-box input:focus{border-color:var(--ia-border-strong)}
.sv-search-box svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--ia-text-muted)}
.sv-filter-select{padding:7px 28px 7px 11px;background:var(--ia-input-bg) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.4)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat right 10px center;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);font-size:13px;appearance:none;min-width:120px;color:var(--ia-text);transition:border var(--ia-t)}
.sv-filter-select:hover{border-color:var(--ia-border-strong)}
.sv-filter-select option{background:var(--ia-surface)}
.sv-filter-clear{font-size:12px;color:var(--ia-text-muted);display:inline-flex;align-items:center;gap:4px;padding:6px 8px;border-radius:var(--ia-r-md);background:none;border:none;cursor:pointer;transition:background var(--ia-t)}
.sv-filter-clear:hover{background:var(--ia-hover);color:var(--ia-text)}
.sv-mode-banner{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-md);font-size:12.5px;margin-bottom:14px}
.sv-mode-banner-icon{width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-accent)}
.sv-mode-banner-label{flex:1}
.sv-mode-banner-label b{color:var(--ia-accent);font-weight:600}
.sv-mode-banner-label .muted{color:var(--ia-text-muted);margin-left:4px}
.sv-mode-banner-link{color:var(--ia-accent);font-weight:500;font-size:12px;text-decoration:none}
.sv-mode-banner-link:hover{text-decoration:underline}
.sv-list-wrap{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.sv-list-head-row{display:grid;grid-template-columns:28px 1fr 130px 100px 150px 60px 32px;gap:14px;padding:10px 14px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2);font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500}
.sv-list-row{display:grid;grid-template-columns:28px 1fr 130px 100px 150px 60px 32px;gap:14px;align-items:center;padding:12px 14px;border-bottom:0.5px solid var(--ia-border);font-size:13.5px;transition:background var(--ia-t)}
.sv-list-row:last-of-type{border-bottom:none}
.sv-list-row:hover{background:var(--ia-hover)}
.sv-list-row.is-inactive{opacity:.5}
.sv-list-row.is-expanded{background:var(--ia-hover)}
.sv-drag{color:var(--ia-text-dim);cursor:grab;font-size:14px;text-align:center;user-select:none}
.sv-list-row:hover .sv-drag{color:var(--ia-text-muted)}
.sv-cat{color:var(--ia-text-muted);font-size:12.5px}
.sv-num{text-align:right;font-variant-numeric:tabular-nums}
.sv-time-stack{display:flex;flex-direction:column;align-items:flex-end;gap:2px}
.sv-time-main{font-variant-numeric:tabular-nums}
.sv-time-breakdown{font-size:10.5px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums}
.sv-expand-btn{width:26px;height:26px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.sv-expand-btn:hover{background:var(--ia-hover);color:var(--ia-text)}
.sv-expand-btn svg{width:14px;height:14px;transition:transform .15s}
.sv-list-row.is-expanded .sv-expand-btn svg{transform:rotate(180deg)}
.sv-toggle{width:34px;height:20px;background:var(--ia-border);border-radius:10px;position:relative;cursor:pointer;transition:background var(--ia-t);border:none;flex-shrink:0;padding:0;display:inline-block}
.sv-toggle.is-on{background:var(--ia-accent)}
.sv-toggle::after{content:'';position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:white;transition:transform var(--ia-t)}
.sv-toggle.is-on::after{transform:translateX(14px)}
.sv-cell-editable{padding:5px 8px;border-radius:var(--ia-r-sm);cursor:text;transition:box-shadow var(--ia-t),background var(--ia-t);display:inline-block;min-width:30px;outline:1px solid transparent}
.sv-cell-editable:hover{background:var(--ia-hover);outline:1px solid var(--ia-border)}
.sv-cell-editable.is-editing{background:var(--ia-input-bg);outline:1px solid var(--ia-accent);padding:4px 7px}
.sv-cell-editable.just-saved{animation:svPulseSave .65s ease}
.sv-cell-editable.just-errored{animation:svPulseError .5s ease}
@keyframes svPulseSave{0%{background:var(--ia-accent-soft);outline-color:var(--ia-accent)}100%{background:transparent;outline-color:transparent}}
@keyframes svPulseError{0%,100%{background:transparent}50%{background:var(--ia-red-soft,rgba(239,68,68,.12));outline:1px solid var(--ia-red,#EF4444)}}
.sv-cell-input{background:transparent;border:none;outline:none;font-size:inherit;color:var(--ia-text);width:100%;padding:0;margin:0}
.sv-cell-input::-webkit-outer-spin-button,.sv-cell-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.sv-cell-input[type=number]{-moz-appearance:textfield}
.sv-drawer{grid-column:1 / -1;padding:18px 22px 20px;background:var(--ia-surface-2);border-top:0.5px solid var(--ia-border);display:none}
.sv-list-row.is-expanded + .sv-drawer{display:block}
.sv-drawer-field{margin-bottom:14px}
.sv-drawer-label{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:6px;display:block}
.sv-drawer-input,.sv-drawer-textarea,.sv-drawer-select{width:100%;padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t)}
.sv-drawer-input:focus,.sv-drawer-textarea:focus,.sv-drawer-select:focus{border-color:var(--ia-accent)}
.sv-drawer-textarea{resize:vertical;min-height:60px;line-height:1.5;font-family:inherit}
.sv-drawer-field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sv-drawer-field-triple{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.sv-time-hint{margin-top:6px;font-size:11px;color:var(--ia-text-muted);line-height:1.4}
.sv-time-preview{background:var(--ia-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:12px 14px;margin-top:10px}
.sv-time-preview-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:3px 0}
.sv-time-preview-row + .sv-time-preview-row{border-top:0.5px dashed var(--ia-border);margin-top:3px;padding-top:6px}
.sv-time-preview-label{color:var(--ia-text-muted)}
.sv-time-preview-value{font-variant-numeric:tabular-nums}
.sv-time-preview-row.total{border-top:0.5px solid var(--ia-border) !important;padding-top:7px;margin-top:6px;font-size:13px}
.sv-time-preview-row.total .sv-time-preview-label{color:var(--ia-text);font-weight:500}
.sv-time-preview-row.total .sv-time-preview-value{color:var(--ia-accent);font-weight:600}
.sv-time-preview-row.customer{color:#60A5FA}
.sv-time-preview-row.customer .sv-time-preview-label,.sv-time-preview-row.customer .sv-time-preview-value{color:#60A5FA}
.sv-attached-addons{display:flex;flex-direction:column;gap:6px;margin-bottom:8px}
.sv-attached-addon{display:grid;grid-template-columns:1fr 140px 140px 28px;gap:10px;align-items:center;padding:8px 11px;background:var(--ia-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);font-size:12.5px}
.sv-attached-addon-default{font-size:11px;color:var(--ia-text-muted);margin-left:5px}
.sv-attached-addon-cell{text-align:right;font-variant-numeric:tabular-nums;font-size:12px}
.sv-attached-addon-cell-default{display:block;font-size:10px;color:var(--ia-text-dim);margin-top:1px;font-weight:400}
.sv-attached-addon-remove{color:var(--ia-text-dim);width:24px;height:24px;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.sv-attached-addon-remove:hover{color:var(--ia-red,#EF4444);background:var(--ia-red-soft,rgba(239,68,68,.12))}
.sv-attached-addon-override{font-size:10px;color:var(--ia-accent);margin-left:6px;padding:1px 6px;background:var(--ia-accent-soft);border-radius:3px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
.sv-attach-btn{padding:7px 11px;background:transparent;border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text-muted);font-size:12px;cursor:pointer;transition:all var(--ia-t);width:100%;text-align:left}
.sv-attach-btn:hover{color:var(--ia-text);border-color:var(--ia-text-muted)}
.sv-drawer-actions{display:flex;justify-content:space-between;align-items:center;padding-top:14px;margin-top:18px;border-top:0.5px solid var(--ia-border)}
.sv-drawer-actions-right{display:flex;gap:8px}
.sv-table-wrap{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:auto}
.sv-tbl{width:100%;border-collapse:collapse;font-size:13px}
.sv-tbl thead th{text-align:left;padding:10px 12px;background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border);font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;user-select:none;white-space:nowrap}
.sv-tbl thead th.num,.sv-tbl td.num{text-align:right;font-variant-numeric:tabular-nums}
.sv-tbl thead th.ctr,.sv-tbl td.ctr{text-align:center}
.sv-tbl tbody tr{border-bottom:0.5px solid var(--ia-border);transition:background var(--ia-t)}
.sv-tbl tbody tr:last-child{border-bottom:none}
.sv-tbl tbody tr:hover{background:var(--ia-hover)}
.sv-tbl tbody tr.is-inactive{opacity:.5}
.sv-tbl tbody td{padding:10px 12px}
.sv-tbl-row-menu{color:var(--ia-text-muted);font-size:15px;width:26px;height:26px;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.sv-tbl-row-menu:hover{background:var(--ia-hover);color:var(--ia-text)}
.sv-addons-count{font-size:11px;color:var(--ia-text-muted);padding:2px 7px;background:var(--ia-surface-2);border-radius:10px;display:inline-block}
.sv-addons-count.has-items{color:#60A5FA;background:rgba(96,165,250,.1)}
.sv-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:500;padding:20px}
.sv-modal-overlay.is-visible{display:flex}
.sv-modal{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);width:100%;max-width:520px;max-height:85vh;display:flex;flex-direction:column;overflow:hidden}
.sv-modal-head{padding:16px 18px 12px;border-bottom:0.5px solid var(--ia-border)}
.sv-modal-title{font-size:15px;font-weight:500}
.sv-modal-subtitle{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.sv-modal-search{padding:12px 18px;border-bottom:0.5px solid var(--ia-border);position:relative}
.sv-modal-search input{width:100%;padding:8px 12px 8px 34px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);font-size:13px;color:var(--ia-text);outline:none}
.sv-modal-search svg{position:absolute;left:30px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--ia-text-muted)}
.sv-modal-body{padding:8px;overflow-y:auto;flex:1}
.sv-addon-lib-item{display:grid;grid-template-columns:1fr 90px 70px;gap:10px;align-items:center;padding:10px 12px;border-radius:var(--ia-r-md);cursor:pointer;transition:background var(--ia-t);font-size:13px}
.sv-addon-lib-item:hover{background:var(--ia-hover)}
.sv-addon-lib-item.is-attached{opacity:.5;cursor:not-allowed}
.sv-addon-lib-item.is-attached:hover{background:transparent}
.sv-addon-lib-desc{font-size:11.5px;color:var(--ia-text-muted);margin-top:1px}
.sv-addon-lib-time,.sv-addon-lib-price{text-align:right;font-size:12px;font-variant-numeric:tabular-nums;color:var(--ia-text-muted)}
.sv-addon-lib-attached-badge{font-size:9px;padding:1px 6px;border-radius:3px;background:var(--ia-accent-soft);color:var(--ia-accent);text-transform:uppercase;letter-spacing:.04em;font-weight:600;margin-left:6px}
.sv-addon-lib-create{padding:10px 12px;border-top:0.5px dashed var(--ia-border);color:var(--ia-accent);font-size:12.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-bottom:none;width:100%;text-align:left}
.sv-addon-lib-create:hover{background:var(--ia-accent-soft)}
.sv-modal-foot{padding:12px 18px;border-top:0.5px solid var(--ia-border);display:flex;justify-content:flex-end;gap:8px}
.sv-addon-page-wrap{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.sv-addon-head-row{display:grid;grid-template-columns:1fr 260px 140px 100px 120px 60px 32px;gap:14px;padding:10px 14px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2);font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500}
.sv-addon-row{display:grid;grid-template-columns:1fr 260px 140px 100px 120px 60px 32px;gap:14px;align-items:center;padding:12px 14px;border-bottom:0.5px solid var(--ia-border);transition:background var(--ia-t);font-size:13.5px}
.sv-addon-row:last-of-type{border-bottom:none}
.sv-addon-row:hover{background:var(--ia-hover)}
.sv-addon-row.is-inactive{opacity:.4}
.sv-addon-row-desc{color:var(--ia-text-muted);font-size:12.5px}
.sv-addon-row-time,.sv-addon-row-price{text-align:right;font-variant-numeric:tabular-nums}
.sv-addon-row-usage{text-align:right;font-size:12px;color:var(--ia-text-muted)}
.sv-addon-row-usage b{color:#60A5FA;font-weight:500}
.sv-empty{padding:60px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px}
.sv-view{display:none}
.sv-view.is-active{display:block}

@media (max-width: 1023px) {
  .sv-view-toggle{display:none !important}
  .sv-list-head-row{display:none}
  .sv-list-row{
    display:flex !important;
    flex-direction:column;
    gap:10px;
    padding:14px 14px 12px;
    background:var(--ia-surface);
    border:0.5px solid var(--ia-border);
    border-radius:10px;
    margin-bottom:10px;
    grid-template-columns:none;
  }
  .sv-list-row:last-of-type{border-bottom:0.5px solid var(--ia-border)}
  .sv-list-row .sv-drag{display:none}
  .sv-list-row > div{width:100%;text-align:left}
  .sv-list-row > div:nth-child(2){order:1;font-size:14.5px;font-weight:500;display:flex;justify-content:space-between;align-items:baseline;gap:10px}
  .sv-list-row > div:nth-child(2)::after{content:attr(data-mobile-price);font-variant-numeric:tabular-nums;font-weight:500;font-size:14px}
  .sv-list-row > div:nth-child(3){order:2;font-size:11.5px;color:var(--ia-text-muted);margin-top:-6px}
  .sv-list-row > div:nth-child(4){display:none}
  .sv-list-row > div:nth-child(5){order:3;text-align:left;padding-top:10px;border-top:0.5px solid rgba(255,255,255,.05)}
  .sv-list-row > div:nth-child(6){order:4;position:absolute;top:14px;right:48px;width:auto}
  .sv-list-row > div:nth-child(7){order:5;position:absolute;top:14px;right:14px;width:auto}
  .sv-list-row{position:relative;padding-right:100px}
  .sv-list-row > div:nth-child(5) .sv-time-stack{align-items:flex-start;flex-direction:row;gap:8px;align-items:baseline}
  .sv-list-row > div:nth-child(5) .sv-time-main{color:var(--ia-accent);font-weight:500;font-variant-numeric:tabular-nums;font-size:12.5px}
  .sv-list-row > div:nth-child(5) .sv-time-breakdown{font-size:10.5px}

  .sv-cell-editable{cursor:default !important;pointer-events:none}
  .sv-cell-editable:hover{background:transparent !important;outline:none !important}

  .sv-list-wrap{background:transparent;border:none;border-radius:0;overflow:visible;padding:0}
  .sv-list-row{border-radius:10px}
  #sv-list-body{padding:0 14px}

  .sv-filter-bar{padding:0 14px 12px}
  .sv-filter-select{display:none}
  .sv-filter-clear{display:none !important}
  .sv-filter-icon-btn{display:inline-flex !important;width:36px;height:36px;border-radius:8px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);align-items:center;justify-content:center;color:var(--ia-text-muted);cursor:pointer;padding:0;flex-shrink:0}
  .sv-filter-icon-btn svg{width:15px;height:15px}
  .sv-filter-icon-btn.has-active{color:var(--ia-accent);border-color:var(--ia-accent)}

  .sv-mode-banner{margin:0 14px 12px}
  .sv-subnav{padding:0 14px}

  .sv-drawer{
    position:fixed;
    inset:0;
    top:auto;
    bottom:0;
    left:0;
    right:0;
    max-height:90vh;
    background:var(--ia-surface);
    border-radius:14px 14px 0 0;
    border-top:0.5px solid var(--ia-border-strong);
    border-left:none;
    border-right:none;
    border-bottom:none;
    margin:0;
    padding:0;
    z-index:400;
    display:none;
    overflow-y:auto;
    transform:translateY(100%);
    transition:transform .22s ease;
    box-shadow:0 -8px 24px rgba(0,0,0,.4);
  }
  .sv-list-row.is-expanded + .sv-drawer{
    display:block;
    transform:translateY(0);
  }
  .sv-drawer::before{
    content:'';
    display:block;
    width:36px;
    height:4px;
    background:rgba(255,255,255,.2);
    border-radius:2px;
    margin:10px auto 14px;
  }
  .sv-drawer-mobile-head{
    display:flex !important;
    align-items:center;
    justify-content:space-between;
    padding:0 16px 14px;
    border-bottom:0.5px solid var(--ia-border);
    margin-bottom:14px;
    position:sticky;
    top:0;
    background:var(--ia-surface);
    z-index:1;
  }
  .sv-drawer-mobile-title{font-size:15px;font-weight:500;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .sv-drawer-mobile-close{background:none;border:none;color:var(--ia-text-muted);font-size:22px;cursor:pointer;line-height:1;padding:0 4px}
  .sv-drawer > *:not(.sv-drawer-mobile-head){padding-left:16px;padding-right:16px}
  .sv-drawer-field{margin-bottom:12px}
  .sv-drawer-field-row{grid-template-columns:1fr 1fr}
  .sv-drawer-field-triple{grid-template-columns:1fr 1fr 1fr;gap:8px}
  .sv-drawer-input,.sv-drawer-select,.sv-drawer-textarea{font-size:14px;padding:10px 12px}
  .sv-attached-addon{grid-template-columns:1fr;gap:4px;padding:10px 11px}
  .sv-attached-addon > .sv-attached-addon-cell{text-align:left;display:flex;gap:10px;align-items:baseline;justify-content:space-between}
  .sv-attached-addon > .sv-attached-addon-remove{position:absolute;top:8px;right:8px}
  .sv-attached-addon{position:relative;padding-right:36px}
  .sv-drawer-actions{flex-direction:row;gap:8px;padding:14px 16px;margin-top:14px;position:sticky;bottom:0;background:var(--ia-surface);border-top:0.5px solid var(--ia-border);margin-left:-16px;margin-right:-16px;padding-left:16px;padding-right:16px}
  .sv-drawer-actions-right{flex:1;justify-content:flex-end}

  .sv-modal{max-width:100% !important;max-height:85vh;border-radius:14px 14px 0 0;margin:0;position:fixed !important;bottom:0;left:0;right:0;width:100%;border-bottom:none;border-left:none;border-right:none}
  .sv-modal-overlay{align-items:flex-end !important;padding:0 !important}
  .sv-modal::before{content:'';display:block;width:36px;height:4px;background:rgba(255,255,255,.2);border-radius:2px;margin:10px auto 4px}

  .sv-addon-head-row{display:none}
  .sv-addon-row{
    display:flex !important;
    flex-direction:column;
    gap:6px;
    padding:12px 14px;
    margin:0 14px 10px;
    background:var(--ia-surface);
    border:0.5px solid var(--ia-border);
    border-radius:10px;
    position:relative;
    grid-template-columns:none;
  }
  .sv-addon-row > div{width:100%;text-align:left}
  .sv-addon-row > div:nth-child(1){order:1;font-size:14px;font-weight:500;padding-right:80px}
  .sv-addon-row > div:nth-child(2){order:2;font-size:12px;color:var(--ia-text-muted);padding-right:80px}
  .sv-addon-row > div:nth-child(3){order:3;text-align:left;display:inline-block;width:auto;font-size:12px;color:var(--ia-text-muted)}
  .sv-addon-row > div:nth-child(4){order:4;text-align:left;display:inline-block;width:auto;margin-left:12px;font-size:12.5px;font-variant-numeric:tabular-nums}
  .sv-addon-row > div:nth-child(3)::before{content:'Duration: '}
  .sv-addon-row > div:nth-child(4)::before{content:'Price: '}
  .sv-addon-row > div:nth-child(5){order:5;text-align:left;font-size:11.5px;margin-top:2px}
  .sv-addon-row > div:nth-child(6){order:6;position:absolute;top:12px;right:50px;width:auto}
  .sv-addon-row > div:nth-child(7){order:7;position:absolute;top:12px;right:14px;width:auto}
  .sv-addon-page-wrap{background:transparent;border:none;padding:0}
}

@media (max-width: 767px) {
  .sv-drawer-field-row{grid-template-columns:1fr;gap:10px}
  .sv-drawer-field-triple{grid-template-columns:1fr 1fr 1fr;gap:6px}
  .sv-drawer-input,.sv-drawer-select,.sv-drawer-textarea{font-size:14.5px}
  .ia-page-head{padding-left:14px !important;padding-right:14px !important}
  .ia-page-title{font-size:19px !important}
  .ia-page-subtitle{display:none !important}
}

.sv-filter-icon-btn{display:none}

body.sv-sheet-open{overflow:hidden !important}

.sv-modal-mobile-close{display:none;position:absolute;top:14px;right:12px;background:none;border:none;color:var(--ia-text-muted);font-size:26px;line-height:1;padding:2px 6px;cursor:pointer;border-radius:6px}
.sv-modal-mobile-close:hover{background:var(--ia-hover);color:var(--ia-text)}

@media (max-width: 1023px) {
  .sv-modal-mobile-close{display:inline-block}
  .sv-modal-head{padding-right:50px !important}
  .sv-addon-lib-item{padding:14px 12px;grid-template-columns:1fr 80px 70px}
  .sv-addon-lib-item > div:first-child b{font-size:14px}
  .sv-addon-lib-item .sv-addon-lib-desc{font-size:12px;margin-top:3px}
  .sv-addon-lib-time,.sv-addon-lib-price{font-size:12.5px}
  .sv-modal-body{padding:12px 8px}
  .sv-modal-foot{padding:14px 16px}
  .sv-modal-foot .ia-btn{padding:10px 14px;font-size:13.5px;flex:1}
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Services</h1>
    <p class="ia-page-subtitle">Manage what customers can book and how you charge.</p>
  </div>
  <div class="ia-page-actions" id="sv-page-actions">
    <div class="sv-view-toggle" id="sv-view-toggle">
      <button class="sv-view-toggle-btn is-active" data-view="list" type="button">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
        List
      </button>
      <button class="sv-view-toggle-btn" data-view="table" type="button">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2 7h12M6 3v10"/></svg>
        Table
      </button>
    </div>
    <button type="button" class="ia-btn ia-btn--primary" id="sv-add-btn">+ Add service</button>
  </div>
</div>

<div class="sv-subnav" id="sv-subnav">
  <button type="button" class="sv-subnav-tab is-active" data-tab="services">
    Services <span class="sv-subnav-tab-count" id="sv-count-services">0</span>
  </button>
  <button type="button" class="sv-subnav-tab" data-tab="addons">
    Add-ons library <span class="sv-subnav-tab-count" id="sv-count-addons">0</span>
  </button>
</div>

<div id="sv-tab-services">
  <div class="sv-filter-bar">
    <div class="sv-search-box">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3" stroke-linecap="round"/></svg>
      <input type="text" id="sv-search" placeholder="Search services...">
    </div>
    <select class="sv-filter-select" id="sv-filter-category">
      <option value="">All categories</option>
    </select>
    <select class="sv-filter-select" id="sv-filter-active">
      <option value="">All statuses</option>
      <option value="true">Active only</option>
      <option value="false">Inactive only</option>
    </select>
    <button type="button" class="sv-filter-clear" id="sv-clear-filters" style="display:none">Clear</button>
    <button type="button" class="sv-filter-icon-btn" id="sv-filter-open" title="Filters" aria-label="Filters">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M4 8h8M6 12h4"/></svg>
    </button>
  </div>

  <div class="sv-mode-banner" id="sv-mode-banner">
    <span class="sv-mode-banner-icon">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 4v4l2.5 1.5" stroke-linecap="round"/></svg>
    </span>
    <span class="sv-mode-banner-label" id="sv-mode-banner-label"></span>
    <a href="/capacity" class="sv-mode-banner-link">Change mode →</a>
  </div>

  <div class="sv-view is-active" id="sv-view-list">
    <div class="sv-list-wrap">
      <div class="sv-list-head-row">
        <div></div>
        <div>Service name</div>
        <div>Category</div>
        <div class="sv-num">Price</div>
        <div class="sv-num" id="sv-list-dur-head">Total time</div>
        <div style="text-align:center">Active</div>
        <div></div>
      </div>
      <div id="sv-list-body"></div>
    </div>
  </div>

  <div class="sv-view" id="sv-view-table">
    <div class="sv-table-wrap">
      <table class="sv-tbl">
        <thead>
          <tr>
            <th>Name</th>
            <th>Category</th>
            <th class="num">Price</th>
            <th class="num">Prep</th>
            <th class="num" id="sv-tbl-dur-head">Duration</th>
            <th class="num">Cleanup</th>
            <th class="ctr">Add-ons</th>
            <th class="ctr">Active</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody id="sv-tbl-body"></tbody>
      </table>
    </div>
  </div>
</div>

<div id="sv-tab-addons" style="display:none">
  <div class="sv-filter-bar">
    <div class="sv-search-box">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3" stroke-linecap="round"/></svg>
      <input type="text" id="sv-addon-search" placeholder="Search add-ons...">
    </div>
  </div>
  <div class="sv-addon-page-wrap">
    <div class="sv-addon-head-row">
      <div>Name</div>
      <div>Description</div>
      <div class="sv-addon-row-time">Default time</div>
      <div class="sv-addon-row-price">Price</div>
      <div class="sv-addon-row-usage">Used in</div>
      <div style="text-align:center">Active</div>
      <div></div>
    </div>
    <div id="sv-addon-lib-body"></div>
  </div>
</div>

<div class="sv-modal-overlay" id="sv-filter-sheet">
  <div class="sv-modal" style="max-width:460px">
    <div class="sv-modal-head" style="position:relative">
      <div class="sv-modal-title">Filters</div>
      <div class="sv-modal-subtitle">Narrow down services.</div>
      <button type="button" class="sv-modal-mobile-close" data-filter-close aria-label="Close">&times;</button>
    </div>
    <div class="sv-modal-body" style="padding:16px">
      <div class="sv-drawer-field">
        <label class="sv-drawer-label">Category</label>
        <select class="sv-drawer-select" id="sv-sheet-filter-category"><option value="">All categories</option></select>
      </div>
      <div class="sv-drawer-field">
        <label class="sv-drawer-label">Status</label>
        <select class="sv-drawer-select" id="sv-sheet-filter-active">
          <option value="">All statuses</option>
          <option value="true">Active only</option>
          <option value="false">Inactive only</option>
        </select>
      </div>
    </div>
    <div class="sv-modal-foot">
      <button type="button" class="ia-btn ia-btn--sm" id="sv-filter-reset">Clear all</button>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-filter-close>Done</button>
    </div>
  </div>
</div>

<div class="sv-modal-overlay" id="sv-addon-picker-modal">
  <div class="sv-modal">
    <div class="sv-modal-head" style="position:relative">
      <div class="sv-modal-title">Add an add-on to this service</div>
      <div class="sv-modal-subtitle">Pick from your library, or create a new one.</div>
      <button type="button" class="sv-modal-mobile-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="sv-modal-search">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3" stroke-linecap="round"/></svg>
      <input type="text" id="sv-picker-search" placeholder="Search add-ons...">
    </div>
    <div class="sv-modal-body" id="sv-picker-body"></div>
    <div class="sv-modal-foot">
      <button type="button" class="ia-btn ia-btn--sm" data-modal-close>Cancel</button>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" id="sv-picker-create">+ Create new add-on</button>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
window.SvData = {
  categories: @json($jsCategories),
  library:    @json($jsLibrary),
  mode:       @json($jsMode),
  csrf:       '{{ csrf_token() }}',
  urls: {
    servicesBase: '{{ url("/services") }}',
    addonsBase:   '{{ url("/addons") }}',
  },
  currency: '{{ tenant()->currency_symbol ?? "$" }}',
};
</script>
<script src="{{ asset('js/tenant/services.js') }}" defer></script>
@endpush
