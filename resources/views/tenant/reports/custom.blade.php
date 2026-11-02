@extends('layouts.tenant.app')
@section('title', 'Reports · Custom')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
{{-- MARKER-REPORT-BUILDER-CORE MARKER-REPORT-BUILDER-V2 — the report builder. Read-only: nothing here changes shop data. --}}
<div class="ia-section ia-section--wide" id="rb-app">

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Reports</h1>
    <p class="ia-page-subtitle" id="subTitle">Build your own report, ask for one in plain words, and save it for your team.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center" id="headBtns"></div>
</div>

@include('tenant.reports._tab_subnav', ['active' => 'custom'])

<!-- ================= LIST ================= -->
<section class="rb-view on" id="v-list">
  <div class="rb-askwrap">
    <div class="rb-lbl" style="margin-bottom:8px">Ask for a report</div>
    <div class="rb-ask"><input class="rb-inp rb-askin" id="askList" placeholder='Try: "margin by category compared to last year"' autocomplete="off"><button class="ia-btn ia-btn--primary" type="button" data-ask="askList">Build it</button></div>
    <div class="rb-ex" id="exList"></div>
    <div id="askOutList"></div>
  </div>

  <div class="rb-sec">
    <div class="rb-h"><h2>Templates</h2><span class="rb-hint">open in the builder — change anything, then save your own copy</span><button class="rb-link" id="tplMore" style="margin-left:auto" type="button"></button></div>
    <div class="rb-tiles" id="tplList"></div>
  </div>

  <div class="rb-sec" style="margin-top:40px">
    <div class="rb-h"><h2>Your reports</h2></div>
    <div class="rb-bar">
      <div class="rb-seg" id="listSeg"><button data-f="all" class="on" type="button">All</button><button data-f="mine" type="button">Mine</button><button data-f="shared" type="button">Shared with me</button></div>
      <span class="rb-hint" id="listCount"></span>
      <span class="sp"></span>
      <select class="rb-sel" id="listSort" aria-label="Sort reports"><option value="new">Newest first</option><option value="old">Oldest first</option><option value="name">Name A–Z</option></select>
      <input class="rb-inp" id="listQ" placeholder="Search reports" style="width:200px">
    </div>
    <div class="rb-tbl"><table class="ia-table" id="savedTbl"></table></div>
  </div>
</section>

<!-- ================= BUILDER ================= -->
<section class="rb-view" id="v-build">
  <div class="rb-titlebar" style="margin-bottom:12px"><a class="rb-link" id="backLink" href="#" onclick="return false">← All reports</a></div>

  <div class="rb-askwrap" style="margin-bottom:18px">
    <div class="rb-ask"><input class="rb-inp rb-askin" id="askBuild" placeholder="Ask in your own words — it fills in the settings below, and you can change any of them" autocomplete="off"><button class="ia-btn ia-btn--sm" type="button" data-ask="askBuild">Ask</button></div>
    <div id="askOutBuild"></div>
  </div>
  <div id="whyBanner"></div>

  <div class="rb-build">
    <div class="rb-panel" id="panel"></div>
    <div>
      <div class="rb-titlebar">
        <input class="rb-title" id="rTitle" value="" aria-label="Report name">
        <span class="rb-pill warn rb-saved-dirty" id="dirty">Unsaved changes</span>
        <span class="sp" style="flex:1"></span>
        <button class="ia-btn ia-btn--sm" type="button" id="btnExport">Export CSV</button>
        <button class="ia-btn ia-btn--sm" type="button" id="btnShare">Share</button>
        <button class="ia-btn ia-btn--sm ia-btn--primary" type="button" id="btnSave">Save</button>
      </div>
      <div id="readBox"></div>
      <div class="rb-bar" style="margin-bottom:6px" id="viewBar">
        <div class="rb-seg" id="outSeg"><button data-o="table" class="on" type="button">Table</button><button data-o="bar" type="button">Bar</button><button data-o="line" type="button">Line</button></div>
        <select class="rb-sel" id="chartMeasure" style="display:none" aria-label="Measure shown"></select>
        <span class="sp"></span><span class="rb-hint" id="rowHint"></span>
      </div>
      <div id="preview"></div>
      <div id="stands"></div>
      <div id="related"></div>
      <div class="rb-legend" style="margin-top:26px" id="legend"></div>
    </div>
  </div>
</section>

<div class="rb-scrim" id="scrim"></div>

<!-- share -->
<aside class="rb-drawer" id="drawer">
  <div class="rb-drawer-h"><div><h3>Share</h3><div class="rb-hint" id="shName"></div></div><button class="rb-x" data-close type="button">×</button></div>
  <div class="rb-drawer-b">
    <div class="rb-lbl">Who can open this report</div>
    <div class="rb-radio" id="shVis">
      <label><input type="radio" name="vis" value="me" checked> Only me</label>
      <label><input type="radio" name="vis" value="mgr"> Owner and managers</label>
      <label><input type="radio" name="vis" value="all"> Everyone with Reports access</label>
    </div>
    <div class="rb-hint" style="margin-top:6px" id="shVisNote"></div>
    <div class="rb-legend" style="margin-top:26px;margin-bottom:0">
      <b>What this changes.</b>
      <div class="row"><span>Opening:</span><span>people you share with see the report in Your reports. It runs on today's data each time it's opened.</span></div>
      <div class="row"><span>Limits:</span><span>staff hours can only be shared with owner and managers. Per-person sales figures are shown to owner and managers only.</span></div>
      <div class="row"><span>Changes:</span><span>only you, or an owner or manager, can change or delete it. Anyone else who saves it gets their own copy.</span></div>
    </div>
  </div>
  <div class="rb-drawer-f"><button class="ia-btn ia-btn--sm" data-close type="button">Cancel</button><button class="ia-btn ia-btn--sm ia-btn--primary" id="shSave" type="button">Save</button></div>
</aside>

<!-- drill-down -->
<aside class="rb-drawer" id="drawerDrill"></aside>

<!-- calculation -->
<div class="rb-modal" id="mCalc" style="width:min(560px,calc(100% - 32px))">
  <div class="rb-drawer-h"><div><h3 id="cTitle">Add a calculation</h3><div class="rb-hint">Combine measures — from any data that can be split the same way — into a new one.</div></div><button class="rb-x" data-close type="button">×</button></div>
  <div class="rb-drawer-b">
    <div class="rb-lbl">Name</div>
    <input class="rb-inp" id="cName" placeholder="e.g. Sales per labor hour">
    <div class="rb-lbl" style="margin-top:16px">Formula</div>
    <input class="rb-inp rb-fx" id="cF" placeholder="[Revenue] / [Hours worked]" autocomplete="off" spellcheck="false">
    <div class="rb-bar" style="margin:8px 0 0">
      <select class="rb-sel" id="cPick" style="max-width:250px"></select>
      <button class="ia-btn ia-btn--sm" type="button" id="cIns">Insert</button>
      <span class="sp"></span>
      <div class="rb-seg" id="cOps"><button data-op=" + " type="button">+</button><button data-op=" - " type="button">−</button><button data-op=" * " type="button">×</button><button data-op=" / " type="button">÷</button><button data-op="(" type="button">(</button><button data-op=")" type="button">)</button></div>
    </div>
    <div class="rb-bar" style="margin:12px 0 0"><span class="rb-hint">Shown as</span><select class="rb-sel" id="cT"><option value="$">Dollars</option><option value="n">Whole number</option><option value="%">Percent</option><option value="d">Number with a decimal</option></select></div>
    <div class="rb-calc-prev" id="cPrev"></div>
    <div class="rb-lbl" style="margin-top:18px">Suggested</div>
    <div class="rb-chips" id="cSugg"></div>
    <label style="font-size:13px;display:flex;gap:8px;align-items:center;margin-top:16px"><input type="checkbox" class="rb-chk" id="cLib" checked> Keep it in my calculations so other reports can use it</label>
  </div>
  <div class="rb-drawer-f"><button class="ia-btn ia-btn--sm" data-close type="button">Cancel</button><button class="ia-btn ia-btn--sm ia-btn--primary" id="cSave" type="button">Add to report</button></div>
</div>
<div class="rb-toast" id="toast"></div>

</div>

<script type="application/json" id="rb-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="{{ asset('js/tenant/report-builder.js') }}?v={{ filemtime(public_path('js/tenant/report-builder.js')) }}" defer></script>
@endsection
