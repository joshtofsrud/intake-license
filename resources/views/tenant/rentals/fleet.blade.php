@extends('layouts.tenant.app')
@php $pageTitle = 'Fleet'; @endphp

{{-- MARKER-PATCH-227 — scaled fleet: category -> model -> unit, rollups,
     search/filter/paginate, bulk add. Inline-edit reuses the fleet PATCH
     protocol ({field,value}). --}}

@push('styles')
<style>
  .fl-ctl{display:flex;gap:10px;align-items:center;margin:18px 0 16px;flex-wrap:wrap}
  .fl-search{flex:1;min-width:220px;position:relative}
  .fl-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ia-text-dim,#888)}
  .fl-search input{width:100%;background:var(--ia-input-bg,rgba(255,255,255,.07));border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:9px 12px 9px 36px;color:inherit;font:inherit;font-size:13.5px;outline:none}
  .fl-count{font-size:12.5px;opacity:.55;margin-left:auto;white-space:nowrap}
  .fl-cat{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);margin-bottom:12px;overflow:hidden}
  .fl-cat-head{display:grid;grid-template-columns:auto 1fr auto auto;gap:16px;align-items:center;padding:15px 18px;cursor:pointer}
  .fl-disc{width:20px;height:20px;display:flex;align-items:center;justify-content:center;opacity:.5;transition:transform var(--ia-t)}
  .fl-cat.open .fl-disc,.fl-model.open .fl-disc{transform:rotate(90deg);opacity:1}
  .fl-cat-name{font-size:16px;font-weight:640}
  .fl-cat-axis{font-size:11.5px;opacity:.5;margin-top:2px}
  .fl-roll{display:flex;align-items:center;gap:14px}
  .fl-roll-stat{text-align:right}
  .fl-roll-num{font-size:16px;font-weight:680}
  .fl-roll-lbl{font-size:10px;opacity:.5;text-transform:uppercase;letter-spacing:.05em}
  .fl-bar{width:120px;height:7px;border-radius:999px;background:var(--ia-surface-2,#262626);overflow:hidden;display:flex}
  .fl-seg{height:100%}
  .fl-seg.av{background:#7BC96F}.fl-seg.out{background:#5BA3D0}.fl-seg.res{background:#E0A82E}.fl-seg.mt{background:#E0573E}
  .fl-cat-body{display:none;border-top:.5px solid var(--ia-border);padding:6px}
  .fl-cat.open .fl-cat-body{display:block}
  .fl-model{border-radius:var(--ia-r-md);margin:6px;background:var(--ia-bg);border:.5px solid var(--ia-border);overflow:hidden}
  .fl-model-head{display:grid;grid-template-columns:auto 1fr auto auto auto;gap:14px;align-items:center;padding:11px 14px;cursor:pointer}
  .fl-model-head:hover{background:var(--ia-hover,rgba(255,255,255,.05))}
  .fl-model-name{font-size:13.5px;font-weight:600}
  .fl-model-sub{font-size:11.5px;opacity:.5;margin-top:1px}
  .fl-rates{display:flex;gap:6px;flex-wrap:wrap}
  .fl-chip{font-size:11.5px;background:var(--ia-surface-2,#262626);border-radius:var(--ia-r-sm);padding:3px 8px;white-space:nowrap}
  .fl-chip b{font-weight:600}
  .fl-chip.season{background:rgba(224,168,46,.13);color:#E0A82E}
  .fl-mins{font-size:11.5px;opacity:.6;white-space:nowrap}
  /* MARKER-PATCH-236 — pricing form is a drawer behind ✎ Edit, not the default view. */
  .fl-model-body{display:none;border-top:.5px solid var(--ia-border-strong,rgba(255,255,255,.22));padding:14px;background:rgba(255,255,255,.03)}
  /* MARKER-RENTAL-MODEL-PHOTOS */
  .fl-photo{display:flex;align-items:center;gap:10px}
  .fl-photo-thumb{width:64px;height:48px;border-radius:8px;border:.5px solid var(--ia-border);background:rgba(255,255,255,.05) center/cover no-repeat}
  .fl-model.editing .fl-model-body{display:block;animation:fl-slide .14s ease}
  @keyframes fl-slide{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
  .fl-editbtn{font-size:11.5px;padding:4px 10px;border:.5px solid var(--ia-border);border-radius:6px;background:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-weight:550;white-space:nowrap}
  .fl-editbtn:hover{color:var(--ia-text,#f0f0f0);background:var(--ia-hover,rgba(255,255,255,.07))}
  .fl-model.editing .fl-editbtn{background:var(--ia-accent,#BEF264);color:#0a0a0a;border-color:transparent}
  .fl-units{display:none;border-top:.5px solid var(--ia-border);background:rgba(255,255,255,.012);padding:4px}
  .fl-model.open .fl-units{display:block}
  /* MARKER-PATCH-236 — roster grid: condition + last rented + utilization, whole row links to unit detail. */
  .fl-uhead{display:grid;grid-template-columns:120px 80px 1fr 130px 90px 70px 64px;gap:10px;padding:6px 12px;font-size:10px;text-transform:uppercase;letter-spacing:.05em;opacity:.5}
  .fl-uline{display:grid;grid-template-columns:120px 80px 1fr 130px 90px 70px 64px;gap:10px;align-items:center;padding:8px 12px;border-radius:var(--ia-r-sm);font-size:12.5px;text-decoration:none;color:inherit;cursor:pointer}
  /* MARKER-PATCH-236B — constant button, not a hover reveal. */
  .fl-ulink{font-size:11.5px;font-weight:550;padding:4px 11px;border:.5px solid var(--ia-border);border-radius:6px;color:var(--ia-text-dim,rgba(255,255,255,.55));justify-self:end;white-space:nowrap;transition:all .1s}
  .fl-uline:hover .fl-ulink{color:#0a0a0a;background:var(--ia-accent,#BEF264);border-color:transparent}
  .fl-cond{display:flex;gap:8px;font-size:11.5px;align-items:center}
  .fl-uline:hover{background:var(--ia-hover,rgba(255,255,255,.05))}
  .fl-uline input,.fl-uline select{background:transparent;border:.5px solid transparent;border-radius:var(--ia-r-sm);padding:3px 6px;color:inherit;font:inherit;font-size:12.5px;width:100%}
  .fl-uline input:hover,.fl-uline select:hover{border-color:var(--ia-border)}
  /* MARKER-FLEET-IDENT — axis help popover + identifier chips */
  .fl-help{position:relative;display:inline-flex;margin-left:6px}
  .fl-help-dot{width:15px;height:15px;border-radius:50%;border:.5px solid var(--ia-border-strong,rgba(255,255,255,.22));color:var(--ia-text-dim,rgba(255,255,255,.55));background:none;font-size:10px;font-weight:700;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
  .fl-help-dot:hover,.fl-help.open .fl-help-dot{color:#0a0a0a;background:var(--ia-accent,#BEF264);border-color:transparent}
  .fl-help-pop{display:none;position:absolute;left:0;top:21px;z-index:40;width:250px;background:#1c1c1c;border:.5px solid var(--ia-border-strong,rgba(255,255,255,.22));border-radius:var(--ia-r-md);padding:12px 14px;box-shadow:0 12px 32px rgba(0,0,0,.55);font-size:12px;text-transform:none;letter-spacing:0;font-weight:400;color:var(--ia-text,#f0f0f0)}
  .fl-help.open .fl-help-pop{display:block}
  .fl-help-title{display:block;font-weight:650;margin-bottom:7px}
  .fl-help-pop dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:3px 12px}
  .fl-help-pop dt{color:var(--ia-text-dim,rgba(255,255,255,.55))}
  .fl-help-pop dd{margin:0}
  .fl-idf{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .fl-idf-chip{display:inline-flex;align-items:center;gap:6px;background:var(--ia-surface-2,#262626);border:.5px solid var(--ia-border);border-radius:999px;padding:4px 6px 4px 12px;font-size:12.5px}
  .fl-idf-x{width:16px;height:16px;border:none;border-radius:50%;background:rgba(255,255,255,.1);color:var(--ia-text-dim,rgba(255,255,255,.55));font-size:10px;line-height:1;cursor:pointer;padding:0}
  .fl-idf-x:hover{background:#E0573E;color:#fff}
  /* MARKER-FLEET-IDENT-UX — ✓ commit lives inside the input, shown with text */
  .fl-idf-addwrap{position:relative;display:inline-flex;align-items:center}
  .fl-idf-ok{position:absolute;right:5px;width:20px;height:20px;border:none;border-radius:50%;background:var(--ia-accent,#BEF264);color:#0a0a0a;font-size:11px;font-weight:700;line-height:1;cursor:pointer;padding:0;display:none;align-items:center;justify-content:center}
  .fl-idf-addwrap.has-text .fl-idf-ok{display:flex}
  .fl-uline input:focus,.fl-uline select:focus{border-color:var(--ia-accent,#BEF264);outline:none;background:var(--ia-input-bg,rgba(255,255,255,.07))}
  .fl-mono{font-family:var(--ia-font-mono,monospace)}
  .pill{font-size:10px;font-weight:600;border-radius:999px;padding:2px 9px;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
  .pill::before{content:"";width:5px;height:5px;border-radius:50%}
  .pill.av{background:rgba(123,201,111,.13);color:#7BC96F}.pill.av::before{background:#7BC96F}
  .pill.out{background:rgba(91,163,208,.13);color:#5BA3D0}.pill.out::before{background:#5BA3D0}
  .pill.res{background:rgba(224,168,46,.13);color:#E0A82E}.pill.res::before{background:#E0A82E}
  .pill.mt{background:rgba(224,87,62,.13);color:#E0573E}.pill.mt::before{background:#E0573E}
  .fl-fieldgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:4px}
  .fl-fg{display:flex;flex-direction:column;gap:5px}
  .fl-lbl{font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;opacity:.55}
  .fl-inp{background:var(--ia-input-bg,rgba(255,255,255,.07));border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:8px 10px;color:inherit;font:inherit;font-size:13px;outline:none}
  .fl-inp:focus{border-color:var(--ia-accent,#BEF264)}
  .fl-money{position:relative}.fl-money::before{content:"$";position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;font-size:12px}
  .fl-money input{padding-left:20px}
  .fl-pager{display:flex;gap:6px;justify-content:center;margin-top:22px}
  .fl-pager a{padding:6px 11px;border-radius:var(--ia-r-md);border:.5px solid var(--ia-border);text-decoration:none;color:inherit;font-size:12.5px}
  .fl-pager a.cur{background:var(--ia-accent,#BEF264);color:#0a0a0a;border-color:transparent;font-weight:650}
  .fl-add-line{display:flex;gap:8px;align-items:center;padding:8px 12px}
  /* MARKER-PATCH-242 — slim ghost add-forms, no native triangle. The +
     is the affordance and rotates to x when open. */
  details.fl-section{border-style:dashed;background:transparent;transition:background var(--ia-t,.12s)}
  details.fl-section:hover{background:var(--ia-hover,rgba(255,255,255,.05))}
  details.fl-section[open]{border-style:solid;background:var(--ia-surface,#1c1c1c);grid-column:1/-1} /* MARKER-PATCH-243 — open form gets the whole row */
  details.fl-section summary{cursor:pointer;font-size:12.5px;font-weight:550;color:var(--ia-text-dim,rgba(255,255,255,.55));padding:2px 0;display:flex;align-items:center;gap:9px;list-style:none}
  details.fl-section summary::-webkit-details-marker{display:none}
  details.fl-section summary::before{content:'+';font-size:15px;font-weight:600;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;transition:transform .14s ease;flex-shrink:0}
  details.fl-section[open] summary::before{transform:rotate(45deg)}
  details.fl-section[open] summary{color:var(--ia-text,#f0f0f0)}
  details.fl-section summary:hover{color:var(--ia-text,#f0f0f0)}
  /* MARKER-PATCH-243 — category inline edit + checklist manager rows. */
  .fl-cat-editbtn{font-size:11px;padding:2px 8px;border:.5px solid var(--ia-border);border-radius:5px;background:none;color:var(--ia-text-dim,rgba(255,255,255,.55));margin-left:10px;vertical-align:2px}
  .fl-cat-editbtn:hover{color:var(--ia-text,#f0f0f0);background:var(--ia-hover,rgba(255,255,255,.05))}
  .fl-cat-edit{display:none;gap:10px;align-items:end;padding:12px 18px;border-top:.5px solid var(--ia-border);background:rgba(255,255,255,.02);flex-wrap:wrap}
  .fl-cat-edit.open{display:flex}
  .fl-ct-row{border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);padding:10px 12px;margin-bottom:10px}
  .fl-ct-row textarea.fl-inp{min-height:74px;resize:vertical;font-size:12px;line-height:1.5;width:100%}
  /* MARKER-PATCH-245 — edit rows fill their card; content stays readable. */
  .fl-ct-row input[data-ctf=name]{flex:1;min-width:0}
  .fl-ct-wrap{max-width:660px}
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'fleet'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Fleet</h1>
    <p class="ia-page-subtitle">Models define what a thing is and what it costs. Units are the serial-tracked items customers take out.</p>
  </div>
  <div style="display:flex;gap:8px">
    <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('fl-add-model').scrollIntoView({behavior:'smooth'});document.getElementById('fl-add-model-d').open=true">+ Add model</button>
  </div>
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

{{-- control bar --}}
<form method="GET" action="{{ route('tenant.rentals.fleet') }}" class="fl-ctl" id="fl-filter">
  <div class="fl-search">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" name="q" value="{{ $search }}" placeholder="Search models, serials, sizes…" onchange="this.form.submit()">
  </div>
  <select name="category" class="fl-inp" onchange="this.form.submit()">
    <option value="">All categories</option>
    @foreach($allCategories as $c)<option value="{{ $c->id }}" {{ $filterCategory === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
  </select>
  <select name="status" class="fl-inp" onchange="this.form.submit()">
    <option value="">Any status</option>
    @foreach(['available'=>'Available','out'=>'Out','reserved'=>'Reserved','maintenance'=>'Maintenance'] as $k=>$v)
      <option value="{{ $k }}" {{ $filterStatus === $k ? 'selected' : '' }}>{{ $v }}</option>
    @endforeach
  </select>
  <span class="fl-count"><b>{{ $unitTotal }}</b> units · <b>{{ $modelTotal }}</b> models</span>
</form>

{{-- categories --}}
@forelse($categories as $cat)
  @php $r = $rollups[$cat->id] ?? ['total'=>0,'available'=>0,'out'=>0,'reserved'=>0,'maintenance'=>0,'models'=>0]; $t = max(1,$r['total']); @endphp
  <div class="fl-cat {{ $loop->first ? 'open' : '' }}">
    <div class="fl-cat-head" onclick="this.parentElement.classList.toggle('open')">
      <div class="fl-disc"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></div>
      <div>
        <div class="fl-cat-name"><span class="fl-cat-name-txt">{{ $cat->name }}</span>
          {{-- MARKER-PATCH-243 — inline category edit. --}}
          <button type="button" class="fl-cat-editbtn" onclick="event.stopPropagation();this.closest('.fl-cat').querySelector('.fl-cat-edit').classList.toggle('open');this.closest('.fl-cat').classList.add('open')">✎</button>
        </div>
        <div class="fl-cat-axis"><span class="fl-cat-axis-txt">{{ $cat->size_axis ? 'Size axis: ' . $cat->size_axis . ' · ' : '' }}</span>{{ $r['models'] }} model{{ $r['models'] === 1 ? '' : 's' }}</div>
      </div>
      <div class="fl-roll">
        <div class="fl-roll-stat"><div class="fl-roll-num">{{ $r['total'] }}</div><div class="fl-roll-lbl">units</div></div>
        <div class="fl-roll-stat"><div class="fl-roll-num" style="color:#7BC96F">{{ $r['available'] }}</div><div class="fl-roll-lbl">free</div></div>
      </div>
      <div class="fl-bar">
        <div class="fl-seg av" style="width:{{ $r['available']/$t*100 }}%"></div>
        <div class="fl-seg res" style="width:{{ $r['reserved']/$t*100 }}%"></div>
        <div class="fl-seg out" style="width:{{ $r['out']/$t*100 }}%"></div>
        <div class="fl-seg mt" style="width:{{ $r['maintenance']/$t*100 }}%"></div>
      </div>
    </div>
    {{-- MARKER-PATCH-243 — category edit strip: same {field,value} auto-save
         contract as everything else on this page. --}}
    <div class="fl-cat-edit" data-cat="{{ $cat->id }}">
      <div class="fl-fg" style="min-width:200px;flex:1"><span class="fl-lbl">Category name</span><input class="fl-inp" value="{{ $cat->name }}" data-cf="name"></div>
      <div class="fl-fg" style="min-width:200px;flex:1"><span class="fl-lbl">Size axis (optional) @include('tenant.rentals._size_axis_help')</span><input class="fl-inp" value="{{ $cat->size_axis }}" data-cf="size_axis" placeholder="length (cm), Mondopoint…"></div>
      <button type="button" class="ia-btn ia-btn--sm" style="color:#ff8b8b;border-color:rgba(239,68,68,.35)" onclick="flDeleteCategory('{{ $cat->id }}')">Delete category</button>
      <span style="font-size:11px;opacity:.4;flex-basis:100%">Name and axis save as you type. Delete only works once the category is empty.</span>
    </div>
    <div class="fl-cat-body">
      @forelse($modelsByCat[$cat->id] ?? [] as $model)
        <div class="fl-model">
          <div class="fl-model-head" onclick="this.parentElement.classList.toggle('open')">
            <div class="fl-disc"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></div>
            <div><div class="fl-model-name">{{ $model->name }}</div><div class="fl-model-sub">{{ $model->subtitle ?: $cat->name }}{{ $model->conditionTemplate ? ' · ' . $model->conditionTemplate->name : '' }}</div></div>
            <div class="fl-rates">
              @if($model->daily_rate_cents)<span class="fl-chip"><b>{{ format_money($model->daily_rate_cents) }}</b>/day</span>@endif
              @if($model->hourly_rate_cents)<span class="fl-chip"><b>{{ format_money($model->hourly_rate_cents) }}</b>/hr</span>@endif
              @if($model->seasonal_rate_cents)<span class="fl-chip season"><b>{{ format_money($model->seasonal_rate_cents) }}</b>/season</span>@endif
              <span class="fl-chip"><b>{{ format_money($model->deposit_cents) }}</b> dep</span>
            </div>
            <div class="fl-mins">{{ $model->view_units->count() }} unit{{ $model->view_units->count() === 1 ? '' : 's' }}</div>
            <span style="display:flex;gap:8px;align-items:center">
              <span class="pill av">{{ $model->avail_count }} free</span>
              {{-- MARKER-PATCH-236 — pricing form opens on demand. --}}
              <button type="button" class="fl-editbtn" onclick="event.stopPropagation();this.closest('.fl-model').classList.toggle('editing');this.closest('.fl-model').classList.add('open')">✎ Edit</button>
            </span>
          </div>

          {{-- model edit drawer --}}
          <div class="fl-model-body" data-model="{{ $model->id }}">
            <div class="fl-fieldgrid">
              {{-- MARKER-RENTAL-MODEL-PHOTOS — one marketing photo per model.
                   Uploads through the tenant uploads endpoint, then saves the
                   URL over the same data-mf autosave rail as every field. --}}
              <div class="fl-fg" style="grid-column:1/5">
                <span class="fl-lbl">Photo <span style="opacity:.5;text-transform:none;letter-spacing:0">— shows on your public rental pages</span></span>
                <div class="fl-photo" data-photo-wrap>
                  <div class="fl-photo-thumb" data-photo-thumb style="{{ $model->image_url ? 'background-image:url(\'' . $model->image_url . '\')' : '' }}"></div>
                  <button type="button" class="ia-btn ia-btn--sm" data-photo-pick>{{ $model->image_url ? 'Replace' : 'Upload' }}</button>
                  <button type="button" class="ia-btn ia-btn--sm" data-photo-remove style="{{ $model->image_url ? '' : 'display:none' }}">Remove</button>
                  <input type="file" accept="image/jpeg,image/png,image/webp,image/svg+xml" data-photo-file style="display:none">
                  <input type="hidden" data-mf="image_url" value="{{ $model->image_url }}">
                </div>
              </div>
              <div class="fl-fg" style="grid-column:1/3"><span class="fl-lbl">Model name</span><input class="fl-inp" value="{{ $model->name }}" data-mf="name"></div>
              <div class="fl-fg" style="grid-column:3/5"><span class="fl-lbl">Subtitle</span><input class="fl-inp" value="{{ $model->subtitle }}" data-mf="subtitle" placeholder="all-mountain, junior…"></div>
              <div class="fl-fg"><span class="fl-lbl">Hourly</span><div class="fl-money"><input class="fl-inp" value="{{ $model->hourly_rate_cents ? number_format($model->hourly_rate_cents/100,2,'.','') : '' }}" data-mf="hourly_rate" placeholder="—"></div></div>
              <div class="fl-fg"><span class="fl-lbl">Daily</span><div class="fl-money"><input class="fl-inp" value="{{ $model->daily_rate_cents ? number_format($model->daily_rate_cents/100,2,'.','') : '' }}" data-mf="daily_rate" placeholder="—"></div></div>
              <div class="fl-fg"><span class="fl-lbl">Weekend</span><div class="fl-money"><input class="fl-inp" value="{{ $model->weekend_rate_cents ? number_format($model->weekend_rate_cents/100,2,'.','') : '' }}" data-mf="weekend_rate" placeholder="—"></div></div>
              <div class="fl-fg"><span class="fl-lbl">Entire season / lease @if(!tenant()->leasing_available)<span style="opacity:.5">(Scale)</span>@endif</span><div class="fl-money"><input class="fl-inp" value="{{ $model->seasonal_rate_cents ? number_format($model->seasonal_rate_cents/100,2,'.','') : '' }}" data-mf="seasonal_rate" placeholder="—" {{ tenant()->leasing_available ? '' : 'disabled' }}></div></div>
              <div class="fl-fg"><span class="fl-lbl">Deposit</span><div class="fl-money"><input class="fl-inp" value="{{ number_format($model->deposit_cents/100,2,'.','') }}" data-mf="deposit"></div></div>
              <div class="fl-fg" style="grid-column:2/4"><span class="fl-lbl">Checklist</span>
                <select class="fl-inp" data-mf="condition_template_id">
                  <option value="">— none —</option>
                  @foreach($conditionTemplates as $ct)<option value="{{ $ct->id }}" {{ $model->condition_template_id === $ct->id ? 'selected' : '' }}>{{ $ct->name }}</option>@endforeach
                </select>
              </div>
              {{-- MARKER-FLEET-IDENT — up to 3 per-model unit fields (Color, Wheel size…).
                   MARKER-FLEET-IDENT-UX — ✓ commit in the input, reserved names
                   filtered at render so built-in columns can't be duplicated. --}}
              @php
                $mIdents = array_values(array_filter($model->identifiers ?? [],
                    fn ($n) => ! in_array(mb_strtolower($n), ['size', 'serial', 'tag', 'serial / tag', 'status', 'condition', 'identifier'], true)));
              @endphp
              <div class="fl-fg" style="grid-column:1/4">
                <span class="fl-lbl">Identifiers <span style="opacity:.5;text-transform:none;letter-spacing:0">— extra fields on every unit of this model, staff-only</span></span>
                <div class="fl-idf" data-idf-wrap>
                  <input type="hidden" data-mf="identifiers" value="{{ json_encode($mIdents) }}">
                  <span data-idf-chips style="display:contents"></span>
                  <span class="fl-idf-addwrap" data-idf-addwrap>
                    <input class="fl-inp" data-idf-new placeholder="e.g. Color" style="width:130px;padding-right:30px">
                    <button type="button" class="fl-idf-ok" data-idf-add title="Add identifier">✓</button>
                  </span>
                  <span data-idf-max style="font-size:11px;opacity:.45;display:none">3 max</span>
                  <span data-idf-err style="font-size:11px;color:#ff8b8b;display:none"></span>
                </div>
              </div>
              <div class="fl-fg" style="justify-content:end"><button type="button" class="ia-btn" onclick="flArchiveModel('{{ $model->id }}')">Archive model</button></div>
              {{-- MARKER-FLEET-SAVE — explicit save, the app standard. --}}
              <div style="grid-column:1/5;display:flex;gap:12px;align-items:center;justify-content:flex-end;border-top:.5px solid var(--ia-border);padding-top:12px">
                <span data-msave-note style="font-size:11.5px;color:var(--ia-accent,#BEF264);opacity:0;transition:opacity .25s">✓ Saved</span>
                <button type="button" class="ia-btn ia-btn--primary" data-msave>Save model</button>
              </div>
            </div>
          </div>

          {{-- units --}}
          <div class="fl-units">
            {{-- MARKER-PATCH-236 — roster rows: condition and history are the
                 content; the whole row opens the unit detail page where
                 damage, photos, and per-unit edits live. --}}
            @php
              // MARKER-FLEET-IDENT — identifier columns slot between Size and
              // Condition. $mIdents is set (reserved-filtered) in the drawer above.
              $uGrid = count($mIdents)
                  ? '120px 70px ' . str_repeat('minmax(80px,140px) ', count($mIdents)) . '1fr 130px 90px 70px 64px'
                  : null;
            @endphp
            <div class="fl-uhead" @if($uGrid) style="grid-template-columns:{{ $uGrid }}" @endif><span>Serial / tag</span><span>Size</span>@foreach($mIdents as $in)<span>{{ $in }}</span>@endforeach<span>Condition</span><span>Status</span><span>Last rented</span><span>Util 30d</span><span></span></div>
            @foreach($model->view_units as $u)
              @php $m = $unitMeta[$u->id] ?? ['last' => null, 'util' => null, 'flags' => 0, 'photos' => 0]; @endphp
              <a class="fl-uline" data-unit="{{ $u->id }}" data-uvals="{{ json_encode($u->identifier_values ?? []) }}" @if($uGrid) style="grid-template-columns:{{ $uGrid }}" @endif href="{{ route('tenant.rentals.fleet.units.show', $u->id) }}">
                <span class="fl-mono">{{ $u->identifier ?: '—' }}</span>
                <span>{{ $u->size ?: '—' }}{{ !$u->available_for_rent ? ' ·off' : '' }}</span>
                @foreach($mIdents as $in)
                  <span class="fl-ui-cell" onclick="event.preventDefault();event.stopPropagation()"><input data-ui="{{ $in }}" value="{{ ($u->identifier_values ?? [])[$in] ?? '' }}" placeholder="—" style="min-width:0" title="{{ $in }} — saves on change"></span>
                @endforeach
                <span class="fl-cond">
                  @if($m['flags'] > 0)<span style="color:#E0A82E;font-weight:600">⚑ {{ $m['flags'] }} flag{{ $m['flags'] === 1 ? '' : 's' }}</span>@endif
                  @if($m['photos'] > 0)<span style="opacity:.55">{{ $m['photos'] }} photo{{ $m['photos'] === 1 ? '' : 's' }}</span>@endif
                  @if($m['flags'] === 0 && $m['photos'] === 0)<span style="opacity:.3">no incidents</span>@endif
                </span>
                <span onclick="event.preventDefault();event.stopPropagation()">
                  @if($u->derived_status === 'out')<span class="pill out">Out</span>
                  @elseif($u->derived_status === 'reserved')<span class="pill res">Reserved</span>
                  @else
                    <select data-uf="status">
                      @foreach(['available'=>'Available','maintenance'=>'Maintenance','retired'=>'Retired'] as $sk=>$sv)
                        <option value="{{ $sk }}" {{ $u->status === $sk ? 'selected':'' }}>{{ $sv }}</option>
                      @endforeach
                    </select>
                  @endif
                </span>
                <span style="opacity:.55;font-size:11.5px">{{ $m['last'] ? tlocal_date($m['last'], 'M j') : 'never' }}</span>
                <span style="font-size:11.5px;{{ ($m['util'] ?? 0) >= 60 ? 'color:#7BC96F' : 'opacity:.55' }}">{{ $m['util'] !== null ? $m['util'] . '%' : '—' }}</span>
                <span class="fl-ulink">History</span>
              </a>
            @endforeach
            <div class="fl-add-line">
              <button type="button" class="ia-btn ia-btn--sm" onclick="document.getElementById('bulk-{{ $model->id }}').style.display='flex';this.style.display='none'">+ Add units</button>
              {{-- MARKER-FLEET-IDENT — labeled inputs (the headers above belong
                   to the unit list, not this form) + one input per model
                   identifier, applied to every unit in the batch. --}}
              <form method="POST" action="{{ route('tenant.rentals.fleet.units.bulk') }}" id="bulk-{{ $model->id }}" style="display:none;gap:10px;align-items:flex-end;flex-wrap:wrap">
                @csrf
                <input type="hidden" name="model_id" value="{{ $model->id }}">
                <div class="fl-fg" style="width:64px"><span class="fl-lbl">Qty</span><input type="number" name="count" value="1" min="1" max="200" class="fl-inp"></div>
                <div class="fl-fg" style="width:90px"><span class="fl-lbl">Tag prefix</span><input type="text" name="tag_prefix" placeholder="#SK-" class="fl-inp fl-mono"></div>
                <div class="fl-fg" style="width:70px"><span class="fl-lbl">Start #</span><input type="number" name="start_number" value="1" min="0" class="fl-inp"></div>
                <div class="fl-fg" style="width:120px"><span class="fl-lbl">Size</span><input type="text" name="size" placeholder="optional" class="fl-inp"></div>
                @foreach($mIdents as $in)
                  <div class="fl-fg fl-ident-fg" style="width:110px"><span class="fl-lbl">{{ $in }}</span><input type="text" name="ident[{{ $in }}]" placeholder="optional" class="fl-inp"></div>
                @endforeach
                <div class="fl-fg" data-add-submit><span class="fl-lbl">&nbsp;</span><button type="submit" class="ia-btn ia-btn--primary ia-btn--sm" style="height:33px">Add units</button></div>
              </form>
            </div>
          </div>
        </div>
      @empty
        <div style="padding:18px;font-size:12.5px;opacity:.55">No models in this category yet.</div>
      @endforelse
    </div>
  </div>
@empty
  <div class="ia-card" style="padding:40px;text-align:center">
    <div class="ia-empty-title">Your fleet starts here</div>
    <div class="ia-empty-body" style="margin-top:6px">Add a category, then a model, then units. A simple shop just adds one model per item.</div>
  </div>
@endforelse

{{-- pagination --}}
@if($pageCount > 1)
<div class="fl-pager">
  @for($p = 1; $p <= $pageCount; $p++)
    <a href="{{ route('tenant.rentals.fleet', array_filter(['q'=>$search ?: null,'category'=>$filterCategory,'status'=>$filterStatus,'page'=>$p])) }}" class="{{ $p === $page ? 'cur' : '' }}">{{ $p }}</a>
  @endfor
</div>
@endif

{{-- ============ add category / model / checklist (collapsed) ============ --}}
<div style="margin-top:26px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;align-items:start">
  {{-- add category --}}
  <details class="ia-card fl-section" style="padding:11px 16px">
    <summary>Add a category</summary>
    <form method="POST" action="{{ route('tenant.rentals.fleet.categories.store') }}" style="margin-top:12px">
      @csrf
      <div class="fl-fieldgrid" style="grid-template-columns:1fr 1fr">
        <div class="fl-fg"><span class="fl-lbl">Name</span><input class="fl-inp" name="name" placeholder="e.g. Skis" required></div>
        <div class="fl-fg"><span class="fl-lbl">Size axis (optional) @include('tenant.rentals._size_axis_help')</span><input class="fl-inp" name="size_axis" placeholder="length (cm), Mondopoint…"></div>
      </div>
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm" style="margin-top:12px">Add category</button>
    </form>
  </details>

  {{-- add checklist --}}
  <details class="ia-card fl-section" style="padding:11px 16px">
    <summary>Condition checklists{{ $conditionTemplates->isNotEmpty() ? ' (' . $conditionTemplates->count() . ')' : '' }}</summary>

    {{-- MARKER-PATCH-243 — manage existing checklists: name + items edit
         in place (one item per line, same shape as creation), delete with
         confirm. Endpoints shipped in PATCH-218; this is their UI. --}}
    @if($conditionTemplates->isNotEmpty())
      <div class="fl-ct-wrap" style="margin-top:12px">
        @foreach($conditionTemplates as $ct)
          <div class="fl-ct-row" data-ct="{{ $ct->id }}">
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
              <input class="fl-inp" style="font-weight:600" value="{{ $ct->name }}" data-ctf="name">
              <button type="button" class="ia-btn ia-btn--sm" style="color:#ff8b8b;border-color:rgba(239,68,68,.35);flex-shrink:0" onclick="flDeleteChecklist('{{ $ct->id }}')">Delete</button>
            </div>
            <textarea class="fl-inp" data-ctf="items" spellcheck="false">{{ collect($ct->items)->pluck('label')->implode("\n") }}</textarea>
            <div style="font-size:10.5px;opacity:.4;margin-top:4px">One item per line — saves on blur. In-flight rentals keep the checklist they started with.</div>
          </div>
        @endforeach
      </div>
      <div class="fl-ct-wrap" style="font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;opacity:.45;margin-top:16px;padding-top:14px;border-top:.5px solid var(--ia-border)">Add another</div>
    @endif
    <form method="POST" action="{{ route('tenant.rentals.fleet.ct.store') }}" class="fl-ct-wrap" style="margin-top:12px">
      @csrf
      <div class="fl-fg"><span class="fl-lbl">Name</span><input class="fl-inp" name="name" placeholder="e.g. Ski checklist" required></div>
      <div class="fl-fg" style="margin-top:10px"><span class="fl-lbl">Items (one per line)</span><textarea class="fl-inp" name="items" rows="3" required placeholder="Edges — no major gouges&#10;Bindings — DIN intact&#10;Bases — no core shots"></textarea></div>
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm" style="margin-top:12px">Add checklist</button>
    </form>
  </details>

  {{-- add model — MARKER-PATCH-242: lives in the same row now --}}
  <details class="ia-card fl-section" style="padding:11px 16px" id="fl-add-model-d">
    <summary id="fl-add-model">Add a model</summary>
  <form method="POST" action="{{ route('tenant.rentals.fleet.models.store') }}" style="margin-top:12px">
    @csrf
    <div class="fl-fieldgrid">
      <div class="fl-fg" style="grid-column:1/3"><span class="fl-lbl">Model name</span><input class="fl-inp" name="name" placeholder="e.g. Rossignol Experience 80" required></div>
      <div class="fl-fg"><span class="fl-lbl">Category</span>
        <select class="fl-inp" name="category_id" required>
          <option value="">Choose…</option>
          @foreach($allCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
      </div>
      <div class="fl-fg"><span class="fl-lbl">Subtitle</span><input class="fl-inp" name="subtitle" placeholder="all-mountain"></div>
      <div class="fl-fg"><span class="fl-lbl">Hourly</span><div class="fl-money"><input class="fl-inp" name="hourly_rate" placeholder="—"></div></div>
      <div class="fl-fg"><span class="fl-lbl">Daily</span><div class="fl-money"><input class="fl-inp" name="daily_rate" placeholder="—"></div></div>
      <div class="fl-fg"><span class="fl-lbl">Weekend</span><div class="fl-money"><input class="fl-inp" name="weekend_rate" placeholder="—"></div></div>
      <div class="fl-fg"><span class="fl-lbl">Deposit</span><div class="fl-money"><input class="fl-inp" name="deposit" placeholder="0.00"></div></div>
      @if(tenant()->leasing_available)
      <div class="fl-fg"><span class="fl-lbl">Season rate</span><div class="fl-money"><input class="fl-inp" name="seasonal_rate" placeholder="—"></div></div>
      @endif
      <div class="fl-fg"><span class="fl-lbl">First unit tag (optional)</span><input class="fl-inp fl-mono" name="first_unit_identifier" placeholder="#BH-001"></div>
      <div class="fl-fg"><span class="fl-lbl">First unit size</span><input class="fl-inp" name="first_unit_size" placeholder="optional"></div>
    </div>
    <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm" style="margin-top:14px">Add model</button>
    <span style="font-size:11px;opacity:.5;margin-left:8px">Fill a tag/size to also create the first unit. Add more with "Add units" on the model.</span>
  </form>
  </details>
</div>

<script>
(function(){
  var csrf = '{{ csrf_token() }}';
  // MARKER-PATCH-244 — every auto-save reports through one toast; errors
  // are no longer swallowed. Saving… → Saved ✓ / message.
  var toast = document.createElement('div');
  toast.id = 'fl-toast';
  toast.style.cssText = 'position:fixed;bottom:22px;right:22px;z-index:400;font-size:12.5px;font-weight:600;padding:9px 16px;border-radius:9px;background:var(--ia-surface,#1c1c1c);border:.5px solid var(--ia-border,rgba(255,255,255,.13));color:var(--ia-text,#f0f0f0);box-shadow:0 8px 30px rgba(0,0,0,.4);opacity:0;transform:translateY(6px);transition:all .15s ease;pointer-events:none';
  document.body.appendChild(toast);
  var toastTimer = null;
  function showToast(msg, tone){
    toast.textContent = msg;
    toast.style.color = tone === 'err' ? '#ff8b8b' : (tone === 'ok' ? 'var(--ia-accent,#BEF264)' : 'var(--ia-text,#f0f0f0)');
    toast.style.opacity = '1';
    toast.style.transform = 'none';
    clearTimeout(toastTimer);
    if (tone !== 'busy') toastTimer = setTimeout(function(){ toast.style.opacity = '0'; toast.style.transform = 'translateY(6px)'; }, tone === 'err' ? 3500 : 1400);
  }
  function patch(url, field, value, ok, fail){
    showToast('Saving…', 'busy');
    fetch(url, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json','X-HTTP-Method-Override':'PATCH'},
      body: JSON.stringify({field:field, value:value})})
      .then(function(r){
        if (!r.ok && r.status !== 422) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function(j){
        if (j && j.success === false) { showToast(j.message || 'Could not save.', 'err'); if (fail) fail(); return; }
        showToast('Saved ✓', 'ok');
        if (ok) ok();
      })
      .catch(function(){ showToast("Couldn't save — check your connection and retry.", 'err'); if (fail) fail(); });
  }
  // MARKER-RENTAL-MODEL-PHOTOS — pick file → upload → save URL via the
  // hidden data-mf input (change event rides the existing autosave).
  document.querySelectorAll('[data-photo-wrap]').forEach(function(wrap){
    var pick = wrap.querySelector('[data-photo-pick]');
    var rm   = wrap.querySelector('[data-photo-remove]');
    var file = wrap.querySelector('[data-photo-file]');
    var thumb = wrap.querySelector('[data-photo-thumb]');
    var hidden = wrap.querySelector('[data-mf="image_url"]');
    pick.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
      if (!file.files || !file.files[0]) return;
      var fd = new FormData();
      fd.append('file', file.files[0]);
      fd.append('type', 'general');
      showToast('Uploading…', 'busy');
      fetch('{{ route('tenant.uploads.store') }}', {method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:fd})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j || !j.url) { showToast(j && j.message ? j.message : 'Upload failed.', 'err'); return; }
          hidden.value = j.url;
          hidden.dispatchEvent(new Event('change', { bubbles: false }));
          thumb.style.backgroundImage = "url('" + j.url + "')";
          pick.textContent = 'Replace';
          rm.style.display = '';
        })
        .catch(function(){ showToast("Upload failed — check your connection.", 'err'); });
      file.value = '';
    });
    rm.addEventListener('click', function(){
      hidden.value = '';
      hidden.dispatchEvent(new Event('change', { bubbles: false }));
      thumb.style.backgroundImage = '';
      pick.textContent = 'Upload';
      rm.style.display = 'none';
    });
  });

  // Enter commits a text field (blur fires change fires save).
  document.querySelectorAll('[data-mf],[data-uf],[data-cf],[data-ctf]').forEach(function(el){
    if (el.tagName === 'INPUT') {
      el.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
    }
  });
  // model field edits
  // MARKER-FLEET-SAVE — the drawer commits on "Save model" (explicit save is
  // the app standard); fields mark dirty on change instead of auto-saving.
  document.querySelectorAll('.fl-model-body').forEach(function(body){
    var url = '{{ url('admin/rentals/fleet/models') }}/' + body.getAttribute('data-model');
    var dirty = {};
    body.querySelectorAll('[data-mf]').forEach(function(el){
      el.addEventListener('change', function(){ dirty[el.getAttribute('data-mf')] = el; });
    });
    var saveBtn = body.querySelector('[data-msave]');
    var note = body.querySelector('[data-msave-note]');
    function flash(msg){ if (!note) return; note.textContent = msg; note.style.opacity = 1; setTimeout(function(){ note.style.opacity = 0; }, 1600); }
    if (saveBtn) saveBtn.addEventListener('click', function(){
      var fields = Object.keys(dirty);
      if (!fields.length) { flash('Nothing to save'); return; }
      saveBtn.disabled = true;
      var i = 0;
      function done(ok){ saveBtn.disabled = false; if (ok) { dirty = {}; flash('\u2713 Saved'); } }
      function next(){
        if (i >= fields.length) { done(true); return; }
        var f = fields[i++]; var el = dirty[f];
        patch(url, f, el.value, function(){
          // MARKER-PATCH-246 — model head name follows the drawer edit.
          if (f === 'name') {
            var head = body.closest('.fl-model');
            var t = head ? head.querySelector('.fl-model-name') : null;
            if (t) t.textContent = el.value;
          }
          // MARKER-FLEET-IDENT-UX — unit columns sync once identifiers save.
          if (f === 'identifiers') {
            var idents = [];
            try { idents = JSON.parse(el.value || '[]') || []; } catch (e) {}
            var card = body.closest('.fl-model');
            if (card) flSyncIdentCols(card, idents);
          }
          next();
        }, function(){ done(false); });
      }
      next();
    });
  });
  // unit field edits
  // MARKER-FLEET-IDENT-UX — identifier cells save the row's whole map;
  // rebind-safe so live column rebuilds can call it again.
  function flUnitUrl(line){ return '{{ url('admin/rentals/fleet/units') }}/' + line.getAttribute('data-unit'); }
  function flBindIdentCells(line){
    line.querySelectorAll('[data-ui]').forEach(function(el){
      if (el.dataset.uiBound) return;
      el.dataset.uiBound = '1';
      el.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
      el.addEventListener('change', function(){
        var map = {};
        line.querySelectorAll('[data-ui]').forEach(function(x){ if (x.value.trim() !== '') map[x.getAttribute('data-ui')] = x.value.trim(); });
        patch(flUnitUrl(line), 'identifier_values', JSON.stringify(map));
      });
    });
  }
  // MARKER-FLEET-IDENT-UX — rebuild a model's unit columns + add-form fields
  // in place when its identifiers change. No reload.
  function flSyncIdentCols(card, idents){
    var units = card.querySelector('.fl-units');
    if (!units) return;
    var grid = idents.length
        ? '120px 70px ' + Array(idents.length + 1).join('minmax(80px,140px) ') + '1fr 130px 90px 70px 64px'
        : '';
    var head = units.querySelector('.fl-uhead');
    if (head) {
      head.style.gridTemplateColumns = grid;
      head.textContent = '';
      ['Serial / tag', 'Size'].concat(idents, ['Condition', 'Status', 'Last rented', 'Util 30d', '']).forEach(function(h){
        var s = document.createElement('span'); s.textContent = h; head.appendChild(s);
      });
    }
    units.querySelectorAll('.fl-uline').forEach(function(line){
      var vals = {};
      try { vals = JSON.parse(line.getAttribute('data-uvals') || '{}') || {}; } catch (e) { vals = {}; }
      line.querySelectorAll('[data-ui]').forEach(function(x){ vals[x.getAttribute('data-ui')] = x.value; });
      line.setAttribute('data-uvals', JSON.stringify(vals));
      line.querySelectorAll('.fl-ui-cell').forEach(function(c){ c.remove(); });
      line.style.gridTemplateColumns = grid;
      var after = line.children[1];
      idents.forEach(function(name){
        var cell = document.createElement('span');
        cell.className = 'fl-ui-cell';
        cell.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); });
        var i = document.createElement('input');
        i.setAttribute('data-ui', name);
        i.placeholder = '\u2014';
        i.title = name + ' \u2014 saves on change';
        i.style.minWidth = '0';
        i.value = typeof vals[name] === 'string' ? vals[name] : '';
        cell.appendChild(i);
        after.insertAdjacentElement('afterend', cell);
        after = cell;
      });
      flBindIdentCells(line);
    });
    var form = units.querySelector('.fl-add-line form');
    if (form) {
      form.querySelectorAll('.fl-ident-fg').forEach(function(f){ f.remove(); });
      var submit = form.querySelector('[data-add-submit]') || form.querySelector('button[type="submit"]');
      idents.forEach(function(name){
        var fg = document.createElement('div'); fg.className = 'fl-fg fl-ident-fg'; fg.style.width = '110px';
        var l = document.createElement('span'); l.className = 'fl-lbl'; l.textContent = name;
        var i = document.createElement('input'); i.type = 'text'; i.name = 'ident[' + name + ']'; i.placeholder = 'optional'; i.className = 'fl-inp';
        fg.appendChild(l); fg.appendChild(i);
        form.insertBefore(fg, submit);
      });
    }
  }
  document.querySelectorAll('.fl-uline').forEach(function(line){
    line.querySelectorAll('[data-uf]').forEach(function(el){
      el.addEventListener('change', function(){ patch(flUnitUrl(line), el.getAttribute('data-uf'), el.value); });
    });
    flBindIdentCells(line);
  });
  // MARKER-PATCH-243 — category + checklist edit bindings (same patch()
  // contract) and confirmed deletes.
  document.querySelectorAll('.fl-cat-edit').forEach(function(strip){
    var url = '{{ url('admin/rentals/fleet/categories') }}/' + strip.getAttribute('data-cat');
    strip.querySelectorAll('[data-cf]').forEach(function(el){
      el.addEventListener('change', function(){
        var f = el.getAttribute('data-cf');
        patch(url, f, el.value, function(){
          // MARKER-PATCH-246 — the header shows what just saved.
          var cat = strip.closest('.fl-cat');
          if (!cat) return;
          if (f === 'name') {
            var t = cat.querySelector('.fl-cat-name-txt');
            if (t) t.textContent = el.value;
          }
          if (f === 'size_axis') {
            var a = cat.querySelector('.fl-cat-axis-txt');
            if (a) a.textContent = el.value ? 'Size axis: ' + el.value + ' \u00b7 ' : '';
          }
        });
      });
    });
  });
  document.querySelectorAll('.fl-ct-row').forEach(function(row){
    var url = '{{ url('admin/rentals/fleet/condition-templates') }}/' + row.getAttribute('data-ct');
    row.querySelectorAll('[data-ctf]').forEach(function(el){
      el.addEventListener('change', function(){ patch(url, el.getAttribute('data-ctf'), el.value); });
    });
  });
  // MARKER-FLEET-IDENT — identifier chips in the model drawer (3 max).
  document.querySelectorAll('[data-idf-wrap]').forEach(function(wrap){
    var hidden = wrap.querySelector('[data-mf="identifiers"]');
    var chipsAt = wrap.querySelector('[data-idf-chips]');
    var newInp  = wrap.querySelector('[data-idf-new]');
    var addBtn  = wrap.querySelector('[data-idf-add]');
    var maxNote = wrap.querySelector('[data-idf-max]');
    function list(){ try { var l = JSON.parse(hidden.value || '[]'); return Array.isArray(l) ? l : []; } catch (e) { return []; } }
    function save(l){ hidden.value = JSON.stringify(l); draw(); hidden.dispatchEvent(new Event('change', { bubbles: false })); }
    function draw(){
      var l = list();
      chipsAt.textContent = '';
      l.forEach(function(name, i){
        var c = document.createElement('span'); c.className = 'fl-idf-chip';
        var t = document.createElement('span'); t.textContent = name; c.appendChild(t);
        var x = document.createElement('button'); x.type = 'button'; x.className = 'fl-idf-x'; x.textContent = '\u2715';
        x.title = 'Remove ' + name + ' (unit values are kept, hidden until re-added)';
        x.addEventListener('click', function(){ var n = list(); n.splice(i, 1); save(n); });
        c.appendChild(x);
        chipsAt.appendChild(c);
      });
      var full = l.length >= 3;
      newInp.style.display = full ? 'none' : '';
      addBtn.style.display = full ? 'none' : '';
      maxNote.style.display = full ? '' : 'none';
    }
    // MARKER-FLEET-IDENT-UX — inline errors + ✓ visibility.
    var errEl = wrap.querySelector('[data-idf-err]');
    var addwrapEl = wrap.querySelector('[data-idf-addwrap]');
    var RESERVED = ['size', 'serial', 'tag', 'serial / tag', 'status', 'condition', 'identifier'];
    function err(msg){ if (errEl) { errEl.textContent = msg; errEl.style.display = ''; } }
    function commit(){
      var v = newInp.value.trim().slice(0, 30);
      if (!v) return;
      var l = list();
      if (RESERVED.indexOf(v.toLowerCase()) !== -1) { err('\u201C' + v + '\u201D is already a built-in column.'); return; }
      if (l.indexOf(v) !== -1) { err('\u201C' + v + '\u201D is already added.'); return; }
      if (l.length >= 3) return;
      l.push(v); newInp.value = '';
      if (addwrapEl) addwrapEl.classList.remove('has-text');
      save(l);
    }
    addBtn.addEventListener('click', commit);
    newInp.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); commit(); } });
    newInp.addEventListener('input', function(){
      if (addwrapEl) addwrapEl.classList.toggle('has-text', newInp.value.trim() !== '');
      if (errEl) errEl.style.display = 'none';
    });
    draw();
  });
  // MARKER-FLEET-IDENT — size-axis help popovers (click toggles, outside closes).
  document.querySelectorAll('[data-axis-help] .fl-help-dot').forEach(function(dot){
    dot.addEventListener('click', function(e){
      e.stopPropagation();
      var h = dot.closest('[data-axis-help]');
      document.querySelectorAll('[data-axis-help].open').forEach(function(o){ if (o !== h) o.classList.remove('open'); });
      h.classList.toggle('open');
    });
  });
  document.addEventListener('click', function(){
    document.querySelectorAll('[data-axis-help].open').forEach(function(o){ o.classList.remove('open'); });
  });
  // MARKER-FLEET-IDENT — in-app dialogs, no browser prompts.
  window.flDeleteCategory = function(id){
    IntakeConfirm.show({ title: 'Delete this category?', message: 'It must be empty (no models) first.', confirmText: 'Delete', danger: true }).then(function(ok){
      if(!ok) return;
      fetch('{{ url('admin/rentals/fleet/categories') }}/'+id, {method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-HTTP-Method-Override':'DELETE'}})
        .then(function(r){return r.json();}).then(function(j){ if(j.success) location.reload(); else IntakeConfirm.alert({ title: 'Delete failed', message: j.message||'Could not delete.' }); });
    });
  };
  window.flDeleteChecklist = function(id){
    IntakeConfirm.show({ title: 'Delete this checklist?', message: 'Models using it fall back to no checklist; past condition checks are unaffected.', confirmText: 'Delete', danger: true }).then(function(ok){
      if(!ok) return;
      fetch('{{ url('admin/rentals/fleet/condition-templates') }}/'+id, {method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-HTTP-Method-Override':'DELETE'}})
        .then(function(r){return r.json();}).then(function(j){ if(j.success) location.reload(); else IntakeConfirm.alert({ title: 'Delete failed', message: j.message||'Could not delete.' }); });
    });
  };
  window.flArchiveModel = function(id){
    IntakeConfirm.show({ title: 'Archive this model?', message: 'Its units must already be archived.', confirmText: 'Archive', danger: true }).then(function(ok){
      if(!ok) return;
      fetch('{{ url('admin/rentals/fleet/models') }}/'+id, {method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-HTTP-Method-Override':'DELETE'}})
        .then(function(r){return r.json();}).then(function(j){ if(j.success) location.reload(); else IntakeConfirm.alert({ title: 'Archive failed', message: j.message||'Could not archive.' }); });
    });
  };
})();
</script>

@endsection
