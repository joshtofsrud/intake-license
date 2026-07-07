@extends('public._booking-shell')
@php
  // MARKER-PATCH-597 — simple flow now extends _booking-shell. Theme/color vars
  // are computed in the shell; keep only view-local derivations here.
  $pageTitle = 'Book online';
  $showBackLink = true;
  $isDark = (($bk['theme'] ?? 'light') === 'dark'); // pushed styles need this locally
  $mode   = $bookingMode ?? 'drop_off';
  $flowMode = $flowMode ?? 'simple';
  $simpleServices = $simpleServices ?? collect();
  $h1 = $bk['step1_heading'] ?? 'Pick a service';
  $h2 = $bk['step2_heading'] ?? 'Choose a time';
  $h3 = $bk['step3_heading'] ?? 'Your details';
@endphp

@push('styles')
<style>
  /* simple flow uses a softer radius scale than the shell default */
  :root { --p-r: 9px; --p-r-lg: 14px; }
    .wrap{ max-width:640px; margin:0 auto; padding:28px 18px 60px; }

    /* progress */
    #bk-progress{ display:flex; align-items:center; gap:0; margin-bottom:26px; }
    .pg{ display:flex; align-items:center; gap:8px; }
    .pg-num{ width:26px; height:26px; border-radius:50%; border:1.5px solid var(--p-border); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:600; color:var(--p-muted); flex:none; }
    .pg.on .pg-num{ border-color:var(--p-accent); color:var(--p-accent); background:color-mix(in srgb,var(--p-accent) 14%, transparent); }
    .pg.done .pg-num{ border-color:var(--p-accent); background:var(--p-accent); color:var(--p-accent-text); }
    .pg-lbl{ font-size:12.5px; font-weight:500; color:var(--p-muted); }
    .pg.on .pg-lbl{ color:var(--p-text); }
    .pg-line{ flex:1; height:1.5px; background:var(--p-border); margin:0 10px; min-width:14px; }

    .bk-section{ display:none; }
    .bk-section.active{ display:block; animation:fade .2s ease; }
    @keyframes fade{ from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }
    .bk-section-title{ font-family:var(--p-font-heading); font-size:clamp(20px,4vw,26px); font-weight:700; letter-spacing:-.01em; margin-bottom:5px; }
    .bk-section-sub{ font-size:13.5px; color:var(--p-muted); margin-bottom:20px; }

    /* service tiles */
    .svc-grid{ display:flex; flex-direction:column; gap:10px; }
    .svc{ display:flex; align-items:center; gap:14px; background:var(--p-card); border:1px solid var(--p-border); border-radius:var(--p-r-lg); padding:16px 18px; cursor:pointer; text-align:left; width:100%; font-family:inherit; color:inherit; transition:border-color .12s, transform .1s; }
    .svc:hover{ border-color:var(--p-accent); }
    .svc:active{ transform:scale(.995); }
    .svc.sel{ border-color:var(--p-accent); background:color-mix(in srgb,var(--p-accent) 8%, transparent); }
    .svc-body{ flex:1; min-width:0; }
    .svc-name{ font-size:15px; font-weight:600; margin-bottom:3px; }
    .svc-tag{ font-size:12.5px; color:var(--p-muted); line-height:1.4; }
    .svc-meta{ text-align:right; flex:none; }
    .svc-price{ font-size:14px; font-weight:600; }
    .svc-dur{ font-size:11.5px; color:var(--p-muted); margin-top:2px; }
    .svc-check{ width:20px; height:20px; flex:none; border-radius:50%; border:1.5px solid var(--p-border); display:flex; align-items:center; justify-content:center; color:var(--p-accent-text); }
    .svc.sel .svc-check{ background:var(--p-accent); border-color:var(--p-accent); }
    .svc-check svg{ width:12px; height:12px; opacity:0; }
    .svc.sel .svc-check svg{ opacity:1; }

    /* calendar */
    .cal{ background:var(--p-card); border:1px solid var(--p-border); border-radius:var(--p-r-lg); padding:16px; }
    .cal-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .cal-title{ font-size:14px; font-weight:600; }
    .cal-nav{ display:flex; gap:6px; }
    .cal-nav button{ width:30px; height:30px; border-radius:8px; border:1px solid var(--p-border); background:transparent; color:var(--p-text); cursor:pointer; display:flex; align-items:center; justify-content:center; }
    .cal-nav button:disabled{ opacity:.3; cursor:default; }
    .cal-dow{ display:grid; grid-template-columns:repeat(7,1fr); gap:4px; margin-bottom:4px; }
    .cal-dow span{ text-align:center; font-size:10.5px; color:var(--p-muted); font-weight:600; }
    .cal-grid{ display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
    .cal-day{ aspect-ratio:1; border:0; background:transparent; border-radius:8px; font-family:inherit; font-size:13px; color:var(--p-text); cursor:pointer; display:flex; align-items:center; justify-content:center; }
    .cal-day.empty{ visibility:hidden; }
    .cal-day.off{ color:var(--p-muted); opacity:.3; cursor:default; }
    .cal-day.avail:hover{ background:color-mix(in srgb,var(--p-accent) 18%, transparent); }
    .cal-day.sel{ background:var(--p-accent); color:var(--p-accent-text); font-weight:600; }
    .slots{ display:flex; flex-wrap:wrap; gap:8px; margin-top:16px; }
    .slot{ padding:9px 14px; border:1px solid var(--p-border); border-radius:8px; background:transparent; color:var(--p-text); font-family:inherit; font-size:13px; cursor:pointer; }
    .slot:hover{ border-color:var(--p-accent); }
    .slot.sel{ background:var(--p-accent); color:var(--p-accent-text); border-color:var(--p-accent); font-weight:600; }
    .cal-empty{ text-align:center; color:var(--p-muted); font-size:13px; padding:18px 0; }

    /* details form */
    .row{ display:flex; gap:12px; }
    .fld{ margin-bottom:14px; flex:1; }
    .fld label{ display:block; font-size:12px; font-weight:500; color:var(--p-muted); margin-bottom:6px; }
    .fld input, .fld textarea, .fld select{ width:100%; background:var(--p-field); border:1px solid var(--p-border); border-radius:var(--p-r); padding:11px 13px; font-family:inherit; font-size:14px; color:var(--p-text); }
    .fld input:focus, .fld textarea:focus{ outline:none; border-color:var(--p-accent); }
    .fld textarea{ min-height:74px; resize:vertical; }
    .pay{ margin-top:6px; }
    .pay-opt{ display:flex; align-items:center; gap:10px; border:1px solid var(--p-border); border-radius:var(--p-r); padding:12px 14px; margin-bottom:8px; cursor:pointer; }
    .pay-opt.sel{ border-color:var(--p-accent); }
    .pay-opt input{ accent-color:var(--p-accent); }
    #card-element{ background:var(--p-field); border:1px solid var(--p-border); border-radius:var(--p-r); padding:12px 13px; margin-top:8px; }
    .summary{ background:var(--p-card); border:1px solid var(--p-border); border-radius:var(--p-r-lg); padding:14px 16px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; }
    .summary .s-name{ font-size:14px; font-weight:600; }
    .summary .s-when{ font-size:12px; color:var(--p-muted); margin-top:2px; }
    .summary .s-edit{ font-size:12px; color:var(--p-accent); background:none; border:0; cursor:pointer; font-family:inherit; }

    /* nav buttons */
    .actions{ display:flex; gap:10px; margin-top:24px; }
    .btn{ flex:1; padding:14px; border-radius:var(--p-r); font-family:inherit; font-size:15px; font-weight:600; cursor:pointer; border:1px solid var(--p-border); background:transparent; color:var(--p-text); }
    .btn.primary{ background:var(--p-accent); color:var(--p-accent-text); border-color:var(--p-accent); }
    .btn.primary:disabled{ opacity:.45; cursor:default; }
    .btn.back{ flex:0 0 auto; padding:14px 20px; }
    .err{ color:#e2685f; font-size:13px; margin-top:12px; display:none; }
    .switch{ text-align:center; margin-top:20px; font-size:12.5px; color:var(--p-muted); }
    .switch a{ color:var(--p-accent); text-decoration:none; }
    @media (max-width:520px){ .row{ flex-direction:column; gap:0; } .pg-lbl{ display:none; } }
</style>
@endpush

@section('content')
  <div class="wrap">
    <div id="bk-progress">
      <div class="pg on" data-step="1"><span class="pg-num">1</span><span class="pg-lbl">{{ $bk['step1_label'] ?? 'Service' }}</span></div>
      <div class="pg-line"></div>
      <div class="pg" data-step="2"><span class="pg-num">2</span><span class="pg-lbl">{{ $bk['step2_label'] ?? 'Schedule' }}</span></div>
      <div class="pg-line"></div>
      <div class="pg" data-step="3"><span class="pg-num">3</span><span class="pg-lbl">{{ $bk['step3_label'] ?? 'Details' }}</span></div>
    </div>

    {{-- STEP 1 — service --}}
    <section class="bk-section active" id="bk-step-1">
      <div class="bk-section-title">{{ $h1 }}</div>
      <div class="bk-section-sub">{{ $bk['step1_sub'] ?? 'Choose the service you need.' }}</div>
      <div class="svc-grid">
        @forelse($simpleServices as $svc)
          <button type="button" class="svc" data-id="{{ $svc['id'] }}" data-price="{{ $svc['price_cents'] }}" data-name="{{ $svc['name'] }}">
            <div class="svc-body">
              <div class="svc-name">{{ $svc['name'] }}</div>
              @if($svc['tagline'])<div class="svc-tag">{{ $svc['tagline'] }}</div>@endif
            </div>
            <div class="svc-meta">
              @if($svc['price_cents'] > 0)<div class="svc-price">${{ number_format($svc['price_cents']/100, 2) }}</div>@endif
              @if($svc['duration'])<div class="svc-dur">{{ $svc['duration'] >= 60 ? round($svc['duration']/60,1).' hr' : $svc['duration'].' min' }}</div>@endif
            </div>
            <span class="svc-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg></span>
          </button>
        @empty
          <div class="cal-empty">No services are available to book right now.</div>
        @endforelse
      </div>
      <div class="actions">
        <button type="button" class="btn primary" id="to-schedule" disabled>Continue</button>
      </div>
      @if($flowMode === 'choice')
        <div class="switch">Need to add multiple items? <a href="{{ url('/book?flow=full') }}">Switch to full setup</a></div>
      @endif
    </section>

    {{-- STEP 2 — schedule --}}
    <section class="bk-section" id="bk-step-2">
      <div class="bk-section-title">{{ $h2 }}</div>
      <div class="bk-section-sub">{{ $bk['step2_sub'] ?? 'Pick a date that works for you.' }}</div>
      <div class="cal">
        <div class="cal-head">
          <div class="cal-title" id="cal-title">—</div>
          <div class="cal-nav">
            <button type="button" id="cal-prev" aria-label="Previous month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg></button>
            <button type="button" id="cal-next" aria-label="Next month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
          </div>
        </div>
        <div class="cal-dow"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>
        <div class="cal-grid" id="cal-grid"></div>
        <div class="slots" id="cal-slots"></div>
      </div>
      <div class="actions">
        <button type="button" class="btn back" data-back="1">Back</button>
        <button type="button" class="btn primary" id="to-details" disabled>Continue</button>
      </div>
    </section>

    {{-- STEP 3 — details --}}
    <section class="bk-section" id="bk-step-3">
      <div class="bk-section-title">{{ $h3 }}</div>
      <div class="bk-section-sub">{{ $bk['step3_sub'] ?? 'Who you are and anything we should know.' }}</div>

      <div class="summary">
        <div>
          <div class="s-name" id="sum-name">—</div>
          <div class="s-when" id="sum-when">—</div>
        </div>
        <button type="button" class="s-edit" data-back="1">Change</button>
      </div>

      <div class="row">
        <div class="fld"><label>First name</label><input type="text" id="bk-first-name" autocomplete="given-name"></div>
        <div class="fld"><label>Last name</label><input type="text" id="bk-last-name" autocomplete="family-name"></div>
      </div>
      <div class="row">
        <div class="fld"><label>Email</label><input type="email" id="bk-email" autocomplete="email"></div>
        <div class="fld"><label>Phone</label><input type="tel" id="bk-phone" autocomplete="tel"></div>
      </div>

      @foreach($formSections as $section)
        @foreach($section->fields as $f)
          <div class="fld" data-field-key="{{ $f->field_key ?? '' }}" data-field-label="{{ $f->label }}">
            <label>{{ $f->label }}@if($f->is_required) *@endif</label>
            @if(($f->field_type ?? 'text') === 'textarea')
              <textarea data-resp="{{ $f->id }}"></textarea>
            @else
              <input type="text" data-resp="{{ $f->id }}">
            @endif
          </div>
        @endforeach
      @endforeach

      @if($receivingMethods->count())
        <div class="fld">
          <label>How are you dropping off?</label>
          <select id="bk-receiving">
            @foreach($receivingMethods as $rm)<option value="{{ $rm->name }}">{{ $rm->name }}</option>@endforeach
          </select>
        </div>
      @endif

      <div class="pay" id="pay-block" style="display:none;">
        <div class="fld"><label>Payment</label></div>
        @if($stripeEnabled)
          <label class="pay-opt sel"><input type="radio" name="pay" value="stripe" checked> Pay by card</label>
        @endif
        @if($paypalEnabled)
          <label class="pay-opt"><input type="radio" name="pay" value="paypal"> PayPal</label>
        @endif
        <div id="card-element" style="display:none;"></div>
      </div>

      <div class="err" id="bk-err"></div>
      <div class="actions">
        <button type="button" class="btn back" data-back="2">Back</button>
        <button type="button" class="btn primary" id="bk-submit">Book it</button>
      </div>
    </section>
  </div>

@endsection

@push('scripts')
  <script>
  (function(){
    var CFG = {
      mode: @json($mode),
      stripeEnabled: @json((bool) $stripeEnabled),
      paypalEnabled: @json((bool) $paypalEnabled),
      stripeKey: @json($stripePublishableKey ?? ''),
    };
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var state = { service:null, price:0, name:'', date:null, time:null, resource:null, calY:null, calM:null };

    function $(s,r){ return (r||document).querySelector(s); }
    function $all(s,r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }

    /* ---- step navigation (drives the tracker via .bk-section.active) ---- */
    function go(step){
      $all('.bk-section').forEach(function(s){ s.classList.remove('active'); });
      $('#bk-step-'+step).classList.add('active');
      $all('#bk-progress .pg').forEach(function(p){
        var n = +p.dataset.step;
        p.classList.toggle('on', n === step);
        p.classList.toggle('done', n < step);
      });
      window.scrollTo({top:0, behavior:'smooth'});
      if (step === 2 && state.calY === null) initCal();
      if (step === 3) refreshSummary();
    }
    $all('[data-back]').forEach(function(b){ b.addEventListener('click', function(){ go(+b.dataset.back); }); });

    /* ---- step 1: service ---- */
    $all('.svc').forEach(function(el){
      el.addEventListener('click', function(){
        $all('.svc').forEach(function(s){ s.classList.remove('sel'); });
        el.classList.add('sel');
        state.service = el.dataset.id;
        state.price = parseInt(el.dataset.price, 10) || 0;
        state.name = el.dataset.name;
        $('#to-schedule').disabled = false;
      });
    });
    $('#to-schedule').addEventListener('click', function(){ if(state.service) go(2); });

    /* ---- step 2: calendar ---- */
    function fmtMonth(y,m){ return new Date(y, m-1, 1).toLocaleString('en-US',{month:'long', year:'numeric'}); }
    function initCal(){ var d = new Date(); state.calY = d.getFullYear(); state.calM = d.getMonth()+1; loadMonth(); }
    function loadMonth(){
      $('#cal-title').textContent = fmtMonth(state.calY, state.calM);
      $('#cal-grid').innerHTML = '<div class="cal-empty" style="grid-column:1/-1">Loading…</div>';
      $('#cal-slots').innerHTML = '';
      var url = '/book/availability?year='+state.calY+'&month='+state.calM+'&service_id='+encodeURIComponent(state.service);
      fetch(url, {headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(data){
        renderMonth(data);
      }).catch(function(){ $('#cal-grid').innerHTML = '<div class="cal-empty" style="grid-column:1/-1">Could not load availability.</div>'; });
    }
    function renderMonth(data){
      var avail = {}; (data.dates||[]).forEach(function(d){ avail[d]=true; });
      state._slots = data.slots || {}; state._slotRes = data.slot_resources || {};
      var first = new Date(state.calY, state.calM-1, 1).getDay();
      var days = new Date(state.calY, state.calM, 0).getDate();
      var html = '';
      for (var i=0;i<first;i++) html += '<div class="cal-day empty"></div>';
      for (var d=1; d<=days; d++){
        var ds = state.calY+'-'+String(state.calM).padStart(2,'0')+'-'+String(d).padStart(2,'0');
        var cls = avail[ds] ? 'avail' : 'off';
        var sel = (state.date === ds) ? ' sel' : '';
        html += '<button type="button" class="cal-day '+cls+sel+'" data-date="'+ds+'" '+(avail[ds]?'':'disabled')+'>'+d+'</button>';
      }
      $('#cal-grid').innerHTML = html;
      $all('.cal-day.avail').forEach(function(b){ b.addEventListener('click', function(){ pickDate(b.dataset.date); }); });
      var now = new Date();
      $('#cal-prev').disabled = (state.calY < now.getFullYear()) || (state.calY === now.getFullYear() && state.calM <= now.getMonth()+1);
    }
    function pickDate(ds){
      state.date = ds; state.time = null; state.resource = null;
      $all('.cal-day').forEach(function(b){ b.classList.toggle('sel', b.dataset.date === ds); });
      if (CFG.mode === 'time_slots'){
        var slots = (state._slots && state._slots[ds]) || [];
        var sh = '';
        slots.forEach(function(t){ sh += '<button type="button" class="slot" data-time="'+t+'">'+fmtTime(t)+'</button>'; });
        $('#cal-slots').innerHTML = sh || '<div class="cal-empty">No times left on this day.</div>';
        $all('.slot').forEach(function(b){ b.addEventListener('click', function(){ pickSlot(ds, b.dataset.time, b); }); });
        $('#to-details').disabled = true;
      } else {
        $('#cal-slots').innerHTML = '';
        $('#to-details').disabled = false;
      }
    }
    function pickSlot(ds, t, el){
      state.time = t;
      $all('.slot').forEach(function(s){ s.classList.remove('sel'); });
      el.classList.add('sel');
      var res = (state._slotRes && state._slotRes[ds] && state._slotRes[ds][t]) || [];
      state.resource = res.length ? res[0] : null;
      $('#to-details').disabled = false;
    }
    function fmtTime(t){ var p=t.split(':'); var h=+p[0], m=p[1]; var ap=h>=12?'PM':'AM'; var hh=((h+11)%12)+1; return hh+':'+m+' '+ap; }
    $('#cal-prev').addEventListener('click', function(){ if(this.disabled)return; state.calM--; if(state.calM<1){state.calM=12;state.calY--;} loadMonth(); });
    $('#cal-next').addEventListener('click', function(){ state.calM++; if(state.calM>12){state.calM=1;state.calY++;} loadMonth(); });
    $('#to-details').addEventListener('click', function(){ if(state.date) go(3); });

    /* ---- step 3: summary + payment ---- */
    function fmtDate(ds){ var d=new Date(ds+'T00:00:00'); return d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'}); }
    function refreshSummary(){
      $('#sum-name').textContent = state.name || 'Service';
      var when = state.date ? fmtDate(state.date) : '';
      if (state.time) when += ' · ' + fmtTime(state.time);
      $('#sum-when').textContent = when;
      var needsPay = state.price > 0 && (CFG.stripeEnabled || CFG.paypalEnabled);
      $('#pay-block').style.display = needsPay ? 'block' : 'none';
      if (needsPay && CFG.stripeEnabled) mountCard();
    }
    var stripe=null, card=null, cardMounted=false;
    function mountCard(){
      if (cardMounted || !window.Stripe || !CFG.stripeKey) return;
      var chosen = document.querySelector('input[name="pay"]:checked');
      if (!chosen || chosen.value !== 'stripe') { $('#card-element').style.display='none'; return; }
      stripe = Stripe(CFG.stripeKey); card = stripe.elements().create('card'); card.mount('#card-element');
      $('#card-element').style.display='block'; cardMounted = true;
    }
    $all('input[name="pay"]').forEach(function(r){ r.addEventListener('change', function(){
      $all('.pay-opt').forEach(function(o){ o.classList.toggle('sel', o.contains(r) && r.checked); });
      if (r.value === 'stripe' && r.checked) mountCard(); else $('#card-element').style.display='none';
    }); });

    /* ---- submit ---- */
    function collectResponses(){
      var responses = {}, labels = {};
      $all('[data-resp]').forEach(function(el){
        var key = el.dataset.resp, v = (el.value||'').trim();
        if (v){ responses[key] = v; var fld = el.closest('[data-field-key]'); labels[key] = fld ? (fld.dataset.fieldLabel||'') : ''; }
      });
      return {responses:responses, labels:labels};
    }
    function err(msg){ var e=$('#bk-err'); e.textContent=msg; e.style.display='block'; }
    function payMethod(){
      if (state.price > 0 && (CFG.stripeEnabled || CFG.paypalEnabled)){
        var c = document.querySelector('input[name="pay"]:checked'); return c ? c.value : 'none';
      }
      return 'none';
    }

    $('#bk-submit').addEventListener('click', function(){
      $('#bk-err').style.display='none';
      var fn=$('#bk-first-name').value.trim(), ln=$('#bk-last-name').value.trim(), em=$('#bk-email').value.trim();
      if(!state.service) return err('Please pick a service.');
      if(!state.date) return err('Please choose a date.');
      if(CFG.mode==='time_slots' && !state.time) return err('Please choose a time.');
      if(!fn||!ln) return err('Please enter your name.');
      if(!em) return err('Please enter your email.');

      var resp = collectResponses();
      var rec = $('#bk-receiving'); 
      var pm = payMethod();
      var btn = $('#bk-submit'); btn.disabled = true; btn.textContent = 'Booking…';

      var payload = {
        first_name: fn, last_name: ln, email: em, phone: $('#bk-phone').value.trim(),
        date: state.date, appointment_time: state.time, resource_id: state.resource,
        receiving_method: rec ? rec.value : null,
        items: [{ service_item_id: state.service, addon_ids: [] }],
        responses: resp.responses, response_labels: resp.labels,
        payment_method: pm,
      };

      fetch('/book/submit', {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
        body: JSON.stringify(payload),
      }).then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
      .then(function(res){
        var j = res.j;
        if (!j.success){ btn.disabled=false; btn.textContent='Book it'; return err(j.message || 'Something went wrong.'); }
        if (j.redirect){ window.location = j.redirect; return; }
        if (j.payment === 'stripe'){ return confirmCard(j.client_secret, j.pending_token); }
        if (j.payment === 'paypal' && j.approve_url){ window.location = j.approve_url; return; }
        btn.disabled=false; btn.textContent='Book it'; err('Could not complete booking.');
      }).catch(function(){ btn.disabled=false; btn.textContent='Book it'; err('Network error — please try again.'); });
    });

    function confirmCard(clientSecret, token){
      if (!stripe || !card){ return err('Card form not ready.'); }
      stripe.confirmCardPayment(clientSecret, { payment_method:{ card: card } }).then(function(result){
        if (result.error){ var b=$('#bk-submit'); b.disabled=false; b.textContent='Book it'; return err(result.error.message); }
        fetch('/book/finalize', {
          method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
          body: JSON.stringify({ pending_token: token }),
        }).then(function(r){return r.json();}).then(function(j){
          if (j.success && j.redirect){ window.location = j.redirect; }
          else { var b=$('#bk-submit'); b.disabled=false; b.textContent='Book it'; err(j.message || 'Payment verification failed.'); }
        });
      });
    }
  })();
  </script>
@endpush
