@extends('layouts.tenant.app')
@section('title', 'Reports · Search')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
<style>
/* MARKER-SHOP-SEARCH-V2: Reports › Search */
.ia-section [hidden],.sr-mdl [hidden]{display:none !important}
.sr-tabs{display:flex;gap:2px;overflow-x:auto;border-bottom:.5px solid var(--ia-border);margin:0 0 22px;scrollbar-width:none}
.sr-tabs::-webkit-scrollbar{display:none}
.sr-tabs a{padding:10px 14px;font-size:13px;color:var(--ia-text-muted);white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-.5px;display:flex;gap:6px;align-items:center;text-decoration:none}
.sr-tabs a:hover{color:var(--ia-text)}
.sr-tabs a.on{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.sr-pip{font-size:10px;font-weight:600;padding:1px 6px;border-radius:99px;background:#FAEEDA;color:#854F0B}
.sr-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.sr-head h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600;margin:0 0 4px}
.sr-head p{font-size:12.5px;color:var(--ia-text-muted);line-height:1.55;max-width:72ch;margin:0}
.sr-sec{margin-bottom:30px}
.sr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:26px}
.sr-kpi{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:12px;padding:14px 16px}
.sr-kpi .l{font-size:12px;color:var(--ia-text-muted)}
.sr-kpi .v{font-size:24px;font-weight:500;letter-spacing:-.01em;margin-top:4px}
.sr-kpi .s{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
.sr-kpi.lock .v{filter:blur(5px);user-select:none}
.sr-seg{display:inline-flex;border:.5px solid var(--ia-border);border-radius:8px;overflow:hidden}
.sr-seg a{padding:6px 12px;font-size:12.5px;color:var(--ia-text-muted);text-decoration:none}
.sr-seg a.on{background:var(--ia-surface-2);color:var(--ia-text);font-weight:500}
.sr-t{width:100%;border-collapse:collapse;font-size:13px}
.sr-t th{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-muted);font-weight:600;text-align:left;padding:8px 10px;border-bottom:.5px solid var(--ia-border);white-space:nowrap}
.sr-t td{padding:10px;border-bottom:.5px solid var(--ia-border);vertical-align:middle}
.sr-t .n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.sr-t th.n{text-align:right}
.sr-low{color:#c2410c;font-weight:600}
.sr-pill{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;background:var(--ia-surface-2);color:var(--ia-text-muted);white-space:nowrap}
.sr-pill.b{background:rgba(59,130,246,.14);color:#2563eb}
.sr-pill.a{background:rgba(234,179,8,.16);color:#a16207}
.sr-pill.g{background:rgba(34,197,94,.14);color:#15803d}
.sr-rule{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:.5px solid var(--ia-border)}
.sr-rule .x{flex:1;min-width:0}
.sr-rule .t{font-size:13.5px;font-weight:500}
.sr-rule .d{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.sr-rule .ty{min-width:78px;text-align:center}
.sr-lock{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border:.5px dashed var(--ia-border-strong);border-radius:10px;font-size:12.5px;color:var(--ia-text-muted);margin-bottom:10px}
.sr-lock a{text-decoration:underline;color:var(--ia-text)}
.sr-note{font-size:12px;color:var(--ia-text-muted);line-height:1.55;max-width:72ch}
.sr-empty{padding:26px 10px;color:var(--ia-text-muted);font-size:13px}
.sr-explain{border:.5px solid var(--ia-border);border-radius:12px;padding:12px 16px;margin-top:10px;font-size:12.5px;color:var(--ia-text-muted);line-height:1.6}
.sr-explain summary{cursor:pointer;color:var(--ia-text);font-weight:500}
.sr-explain b{color:var(--ia-text);font-weight:500}
.sr-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:12px 0;border-bottom:.5px solid var(--ia-border)}
.sr-row .t{font-size:13.5px;font-weight:500}
.sr-row .d{font-size:12px;color:var(--ia-text-muted);margin-top:2px;max-width:56ch;line-height:1.5}
.sr-chk{display:flex;gap:14px;flex-wrap:wrap}
.sr-chk label{display:flex;gap:6px;align-items:center;font-size:13px}
.sr-save{position:sticky;bottom:0;display:flex;gap:12px;align-items:center;padding:12px 0;background:var(--ia-bg);border-top:.5px solid var(--ia-border);margin-top:10px;z-index:3}
.sr-dirty{font-size:12px;color:var(--ia-text-muted)}
.sr-dirty.on{color:#d97706;font-weight:500}
.tg{position:relative;width:34px;height:20px;flex:none;display:inline-block}
.tg input{opacity:0;width:100%;height:100%;position:absolute;inset:0;margin:0;cursor:pointer;z-index:2}
.tg i{position:absolute;inset:0;border-radius:99px;background:var(--ia-border-strong);transition:.14s}
.tg i:before{content:'';position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;top:3px;left:3px;transition:.14s;box-shadow:0 1px 2px rgba(0,0,0,.25)}
.tg input:checked + i{background:#7aa82a}
.tg input:checked + i:before{transform:translateX(14px)}
.tg input:disabled + i{opacity:.5}
.sr-thumb{width:84px;height:52px;object-fit:cover;border-radius:6px;display:block;background:var(--ia-surface-2)}
.sr-cat{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:.5px solid var(--ia-border)}
.sr-cat img,.sr-cat .ph{width:52px;height:40px;object-fit:cover;border-radius:6px;background:var(--ia-surface-2);flex:none}
.sr-cat .x{flex:1;min-width:0;font-size:13.5px}
.sr-cat .x small{display:block;color:var(--ia-text-muted);font-size:11.5px}

/* modal */
.sr-mdl{position:fixed;inset:0;z-index:1200;background:rgba(0,0,0,.55);display:none;align-items:flex-start;justify-content:center;padding:6vh 16px;overflow:auto}
.sr-mdl.on{display:flex}
.sr-box{background:var(--ia-surface);color:var(--ia-text);border:.5px solid var(--ia-border);border-radius:14px;width:min(520px,100%);padding:20px 22px;box-shadow:0 30px 80px rgba(0,0,0,.4)}
.sr-box.wide{width:min(940px,100%)}
.sr-box h4{font-size:16px;font-weight:600;margin:0 0 12px}
.sr-box label.f{display:block;font-size:12px;color:var(--ia-text-muted);margin:12px 0 5px;font-weight:500}
.sr-box .ia-input,.sr-box .ia-select{width:100%}
.sr-box .bt{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
.sr-box .err{color:#dc2626;font-size:12px;margin-top:6px}
.sr-grid2{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.sr-drop{border:1px dashed var(--ia-border-strong);border-radius:10px;overflow:hidden;position:relative;cursor:pointer;aspect-ratio:16/10;background:var(--ia-surface-2);display:grid;place-items:center;font-size:12.5px;color:var(--ia-text-muted);text-align:center}
.sr-drop img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.sr-drop.has span{position:absolute;left:0;right:0;bottom:0;padding:7px 10px;background:rgba(0,0,0,.6);color:#fff;font-size:12px}
.sr-drop.over{border-color:var(--ia-accent)}
.sr-kw{display:flex;flex-wrap:wrap;gap:5px;align-items:center;min-height:40px;padding:5px 8px}
.sr-kw span{display:inline-flex;gap:5px;align-items:center;font-size:12px;background:var(--ia-surface-2);border-radius:99px;padding:3px 9px}
.sr-kw span button{border:0;background:none;color:inherit;cursor:pointer;padding:0;font-size:11px;opacity:.7}
.sr-kw input{border:0;background:none;outline:none;flex:1;min-width:120px;font:inherit;color:inherit;font-size:13px}
.sr-media{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:8px;max-height:300px;overflow:auto;margin-top:8px}
.sr-media img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid transparent}
.sr-media img:hover{border-color:var(--ia-accent)}
.sr-pick{border:.5px solid var(--ia-border);border-radius:8px;max-height:220px;overflow:auto;margin-top:6px}
.sr-pick a{display:flex;gap:10px;align-items:center;padding:7px 10px;font-size:13px;cursor:pointer;color:inherit;text-decoration:none}
.sr-pick a:hover,.sr-pick a.on{background:var(--ia-surface-2)}
.sr-pick img{width:32px;height:32px;object-fit:contain;background:#fff;border-radius:6px;flex:none}
.sr-pick small{color:var(--ia-text-muted)}
.sr-prev{border:.5px solid var(--ia-border);border-radius:12px;padding:12px;background:var(--ia-surface-2)}
.sr-prev .ban{position:relative;border-radius:10px;overflow:hidden;min-height:120px;background:#333;color:#fff;margin-top:8px}
.sr-prev .ban img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.sr-prev .ban::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.6),rgba(0,0,0,0))}
.sr-prev .ban div{position:relative;z-index:1;padding:18px}
.sr-prev .ban b{display:block;font-size:17px}
.sr-prev .row{display:flex;gap:10px;align-items:center;background:var(--ia-surface);border-radius:10px;padding:7px;margin-top:8px}
.sr-prev .row img{width:70px;height:44px;object-fit:cover;border-radius:6px;background:#444}
@media (max-width:760px){.sr-grid2{grid-template-columns:1fr}.sr-t .hide-sm{display:none}}
</style>
@endpush

@section('content')
@php
  $money = fn ($c) => '$' . number_format(((int) $c) / 100, ((int) $c) % 100 ? 2 : 0);
  $tabs = ['overview' => 'Overview', 'none' => 'No results', 'rules' => 'Rules', 'promotions' => 'Promotions', 'display' => 'Display'];
  $plansUrl = route('tenant.feature_addons.index');
  $promoJson = ($promos ?? collect())->keyBy('id')->map(fn ($p) => [
      'id' => $p->id, 'title' => $p->title, 'subtitle' => $p->subtitle, 'image_url' => $p->image_url, 'image_media_id' => $p->image_media_id,
      'link_type' => $p->link_type, 'link_value' => $p->link_value, 'button_label' => $p->button_label,
      'keywords' => (array) $p->keywords, 'category_ids' => (array) $p->category_ids, 'placements' => (array) $p->placements,
      'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'enabled' => (bool) $p->enabled,
  ])->all() ?: new \stdClass;
  $promoOld = session('promo_open') ? array_merge(old(), ['id' => session('promo_open') === 'new' ? null : session('promo_open')]) : null;
  $lockRow = fn ($feature, $what) => '<div class="sr-lock"><span>🔒</span><div style="flex:1"><b style="color:var(--ia-text)">' . e($what) . ' ' . (str_ends_with($what, 's') ? 'are' : 'is') . ' on the ' . e($unlock[$feature]) . ' plan.</b> Anything you already made keeps working; you cannot add new ones. <a href="' . e($plansUrl) . '">See plans</a></div></div>';
@endphp
<div class="ia-section ia-section--wide">

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Reports</h1>
    <p class="ia-page-subtitle">Shop search · last {{ $days }} days</p>
  </div>
</div>

@include('tenant.reports._tab_subnav', ['active' => 'search'])

@if(! $shopOn)
  <div class="sr-lock"><span>🔒</span><div><b style="color:var(--ia-text)">Search is part of your online store.</b> Turn on the online store to let customers search what you sell. <a href="{{ $plansUrl }}">See plans</a></div></div>
@else

<nav class="sr-tabs" aria-label="Search report">
  @foreach($tabs as $k => $label)
    <a href="{{ route('tenant.reports.search', ['tab' => $k, 'days' => $days]) }}" class="{{ $tab === $k ? 'on' : '' }}">{{ $label }}@if($k === 'none' && $zeroN)<span class="sr-pip">{{ $zeroN }}</span>@endif</a>
  @endforeach
</nav>

@if(! $canEdit)
  <div class="sr-note" style="margin:-8px 0 16px">You can read this report. Changing search needs access to E-commerce in Roles &amp; access.</div>
@endif

{{-- ================================================================ OVERVIEW --}}
@if($tab === 'overview')
  <div class="sr-head">
    <div><h3>Search in your shop</h3><p>Everything shoppers typed into your shop's search, and what they did next.</p></div>
    <span class="sr-seg">@foreach([7, 30, 90] as $d)<a href="{{ route('tenant.reports.search', ['tab' => 'overview', 'days' => $d]) }}" class="{{ $days === $d ? 'on' : '' }}">{{ $d }} days</a>@endforeach</span>
  </div>
  <div class="sr-kpis">
    <div class="sr-kpi"><div class="l">Searches</div><div class="v">{{ number_format($ov['searches']) }}</div><div class="s">@if($ov['change'] !== null){{ $ov['change'] >= 0 ? '+' : '' }}{{ $ov['change'] }}% on the {{ $days }} days before @else &nbsp; @endif</div></div>
    @foreach([['Clicked a result', 'clicked', 'of searches'], ['Added to cart', 'added', 'of searches'], ['Bought', 'bought', $money($ov['revenue']) . ' from search']] as [$l, $k, $s])
      <div class="sr-kpi {{ $can['report'] ? '' : 'lock' }}"><div class="l">{{ $l }}</div><div class="v">{{ $ov[$k] }}%</div><div class="s">{{ $can['report'] ? $s : 'On the ' . $unlock['report'] . ' plan' }}</div></div>
    @endforeach
    <div class="sr-kpi"><div class="l">No results</div><div class="v">{{ $ov['zero_pct'] }}%</div><div class="s">{{ number_format($ov['zero']) }} search{{ $ov['zero'] === 1 ? '' : 'es' }}</div></div>
  </div>

  <div class="sr-sec">
    <div class="sr-head"><div><h3>Top searches</h3><p>Click "Add rule" to steer a search. A search many people make with a low click rate is showing the wrong things first.</p></div></div>
    @if(! $top)
      <div class="sr-empty">No searches in these {{ $days }} days yet. They appear here as shoppers search your shop.</div>
    @else
      <div style="overflow-x:auto">
      <table class="sr-t">
        <thead><tr><th>Search</th><th class="n">Searches</th><th class="n hide-sm">Results</th>@if($can['report'])<th class="n">Clicked</th><th class="n hide-sm">Added</th><th class="n hide-sm">Bought</th><th class="n hide-sm">Revenue</th>@endif<th>Rules</th><th></th></tr></thead>
        <tbody>
        @foreach($top as $r)
          <tr>
            <td><b>{{ $r['q'] }}</b></td>
            <td class="n">{{ number_format($r['n']) }}</td>
            <td class="n hide-sm {{ $r['results'] < 3 ? 'sr-low' : '' }}">{{ $r['results'] }}</td>
            @if($can['report'])
              <td class="n {{ $r['clicked'] < 40 && $r['n'] >= 20 ? 'sr-low' : '' }}">{{ $r['clicked'] }}%</td>
              <td class="n hide-sm">{{ $r['added'] }}%</td>
              <td class="n hide-sm">{{ $r['bought'] }}%</td>
              <td class="n hide-sm">{{ $money($r['revenue']) }}</td>
            @endif
            <td>@foreach($r['rules'] as $t)<span class="sr-pill b">{{ $t }}</span> @endforeach</td>
            <td class="n">@if($canEdit)<button type="button" class="ia-btn ia-btn--sm" data-rule="{{ json_encode(['from_term' => $r['q'], 'back' => 'overview']) }}">Add rule…</button>@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    @endif
    @if(! $can['report'])
      <div style="margin-top:12px">{!! $lockRow('report', 'The search-to-purchase report') !!}</div>
    @endif
  </div>
@endif

{{-- ================================================================ NO RESULTS --}}
@if($tab === 'none')
  <div class="sr-head">
    <div><h3>Searches with no results</h3><p>Shoppers who wanted something and did not find it. Fix it with a synonym when you sell it under another name, a redirect when a page answers it, or leave it as a signal of what to stock. Dismissed searches leave this list and stay counted.</p></div>
    <span class="sr-seg">@foreach([7, 30, 90] as $d)<a href="{{ route('tenant.reports.search', ['tab' => 'none', 'days' => $d]) }}" class="{{ $days === $d ? 'on' : '' }}">{{ $d }} days</a>@endforeach</span>
  </div>
  @if(! $zero)
    <div class="sr-empty">Nothing to look at. New searches with no results appear here.</div>
  @else
    <div style="overflow-x:auto">
    <table class="sr-t">
      <thead><tr><th>Search</th><th class="n">Times</th><th class="hide-sm">Last</th><th>Likely fix</th><th></th></tr></thead>
      <tbody>
      @foreach($zero as $z)
        <tr>
          <td><b>{{ $z['q'] }}</b></td>
          <td class="n">{{ $z['n'] }}</td>
          <td class="hide-sm" style="white-space:nowrap">{{ $z['last']->isToday() ? 'Today' : ($z['last']->isYesterday() ? 'Yesterday' : $z['last']->diffForHumans()) }}</td>
          <td style="max-width:46ch;color:var(--ia-text-muted)">{{ $z['fix'] }}</td>
          <td class="n" style="white-space:nowrap">
            @if($canEdit)
              @if($z['kind'] === 'syn' || $z['kind'] === 'none')<button type="button" class="ia-btn ia-btn--sm" data-rule="{{ json_encode(['type' => 'synonym', 'from_term' => $z['q'], 'to_value' => $z['to'] ?? '', 'back' => 'none']) }}">Add synonym…</button>@endif
              @if($z['kind'] === 'redirect')<button type="button" class="ia-btn ia-btn--sm" data-rule="{{ json_encode(['type' => 'redirect', 'from_term' => $z['q'], 'to_value' => $z['to'] ?? '', 'back' => 'none']) }}">Redirect…</button>@endif
              <form method="POST" action="{{ route('tenant.reports.search.dismiss') }}" style="display:inline">@csrf<input type="hidden" name="q" value="{{ $z['q'] }}"><button class="ia-btn ia-btn--sm">Dismiss</button></form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif
@endif

{{-- ================================================================ RULES --}}
@if($tab === 'rules')
  @php $own = $rules->whereIn('type', ['synonym', 'redirect', 'stop']); $pbh = $rules->whereIn('type', ['pin', 'boost', 'hide']); @endphp
  <div class="sr-sec">
    <div class="sr-head">
      <div><h3>Your rules</h3><p>A synonym makes two words find each other. A redirect sends a search straight to a page. Stop words are ignored when they are what stops a search matching.</p></div>
      @if($canEdit)<button type="button" class="ia-btn ia-btn--primary ia-btn--sm" data-rule="{{ json_encode(['back' => 'rules']) }}">Add a rule</button>@endif
    </div>
    @if(! $can['synonyms']){!! $lockRow('synonyms', 'Synonyms') !!}@endif
    @if(! $can['redirects']){!! $lockRow('redirects', 'Redirects') !!}@endif
    @forelse($own as $r)
      <div class="sr-rule">
        <span class="sr-pill ty {{ $r->type === 'redirect' ? 'a' : ($r->type === 'stop' ? '' : 'b') }}">{{ \App\Models\Tenant\TenantSearchRule::TYPES[$r->type] }}</span>
        <div class="x">
          @if($r->type === 'synonym')<div class="t">{{ $r->from_term }} ↔ {{ $r->to_value }}</div><div class="d">Either one finds the other</div>
          @elseif($r->type === 'redirect')<div class="t">"{{ $r->from_term }}" goes to {{ $r->label ?: $r->to_value }}</div><div class="d">The shopper lands on that page instead of results · used {{ number_format($r->hits) }} time{{ $r->hits === 1 ? '' : 's' }}</div>
          @else<div class="t">{{ $r->from_term }}</div><div class="d">Ignored when they cause no match</div>@endif
        </div>
        @if($canEdit)
          <button type="button" class="ia-btn ia-btn--sm" data-rule="{{ json_encode(['id' => $r->id, 'type' => $r->type, 'from_term' => $r->from_term, 'to_value' => $r->to_value, 'label' => $r->label, 'back' => 'rules']) }}">Edit</button>
          <button type="button" class="ia-btn ia-btn--sm" data-del="{{ route('tenant.reports.search.rule.delete', $r->id) }}" data-del-what="{{ $r->type === 'stop' ? 'These stop words' : '"' . $r->from_term . '"' }}">Remove…</button>
        @endif
      </div>
    @empty
      <div class="sr-empty">No rules yet. Search works without them; add one when a search shows the wrong things.</div>
    @endforelse
  </div>

  <div class="sr-sec">
    <div class="sr-head"><div><h3>Intake defaults</h3><p>Set by Intake for every shop. You can switch one off for your shop; you cannot edit it. Your own synonym for the same word always wins.</p></div></div>
    <form method="POST" action="{{ route('tenant.reports.search.defaults') }}" data-dirty-form>
      @csrf
      @foreach($defaults->where('type', 'synonym') as $d)
        <div class="sr-rule">
          <span class="sr-pill ty">Intake</span>
          <div class="x"><div class="t">{{ $d['from'] }} ↔ {{ $d['to'] }}</div></div>
          <label class="tg"><input type="checkbox" name="on[]" value="{{ $d['id'] }}" @checked($d['on']) @disabled(! $canEdit)><i></i></label>
        </div>
      @endforeach
      @if($defaults->where('type', 'stop')->isNotEmpty())
        <div class="sr-rule" style="align-items:flex-start">
          <span class="sr-pill ty">Stop words</span>
          <div class="x"><div class="sr-chk">@foreach($defaults->where('type', 'stop') as $d)<label><input type="checkbox" name="on[]" value="{{ $d['id'] }}" @checked($d['on']) @disabled(! $canEdit)>{{ $d['from'] }}</label>@endforeach</div><div class="d">Untick a word to let it count in your shop's search.</div></div>
        </div>
      @endif
      @if($canEdit)
        <div class="sr-save"><button class="ia-btn ia-btn--primary">Save changes</button><span class="sr-dirty" data-dirty>No unsaved changes</span></div>
      @endif
    </form>
  </div>

  <div class="sr-sec">
    <div class="sr-head">
      <div><h3>Pin, boost and hide</h3><p>Change the order for one search. Pin puts an item first, boost moves it up, hide keeps it out of that search. Nothing here removes an item from your shop.</p></div>
      @if($canEdit && $can['pbh'])<button type="button" class="ia-btn ia-btn--sm" data-rule="{{ json_encode(['type' => 'pin', 'back' => 'rules']) }}">Pin, boost or hide…</button>@endif
    </div>
    @if(! $can['pbh']){!! $lockRow('pbh', 'Pin, boost and hide') !!}@endif
    @forelse($pbh as $r)
      <div class="sr-rule">
        <span class="sr-pill ty a">{{ \App\Models\Tenant\TenantSearchRule::TYPES[$r->type] }}</span>
        <div class="x"><div class="t">"{{ $r->from_term }}"</div><div class="d">{{ $r->item?->name ?? 'An item that no longer exists' }}{{ $r->type === 'pin' ? ' shows first' : ($r->type === 'boost' ? ' moves up' : ' is hidden from this search') }}</div></div>
        @if($canEdit)
          <button type="button" class="ia-btn ia-btn--sm" data-rule="{{ json_encode(['id' => $r->id, 'type' => $r->type, 'from_term' => $r->from_term, 'item_id' => $r->item_id, 'item_name' => $r->item?->name, 'back' => 'rules']) }}">Edit</button>
          <button type="button" class="ia-btn ia-btn--sm" data-del="{{ route('tenant.reports.search.rule.delete', $r->id) }}" data-del-what="This {{ strtolower(\App\Models\Tenant\TenantSearchRule::TYPES[$r->type]) }}">Remove…</button>
        @endif
      </div>
    @empty
      @if($can['pbh'])<div class="sr-empty">None yet.</div>@endif
    @endforelse
  </div>
@endif

{{-- ================================================================ PROMOTIONS --}}
@if($tab === 'promotions')
  @php
    $catNames = collect($cats)->pluck('name', 'id');
    $placeNames = \App\Models\Tenant\TenantSearchPromotion::PLACES;
  @endphp
  <div class="sr-sec">
    <div class="sr-head">
      <div><h3>Promotions</h3><p>An image with a headline and a link, shown when shoppers search for the words you pick, or browse the categories you pick. Use them for sales, new ranges, events or anything you want found.</p></div>
      @if($canEdit && $can['promotions'])<button type="button" class="ia-btn ia-btn--primary ia-btn--sm" data-promo="new">New promotion</button>@endif
    </div>
    @if(! $can['promotions']){!! $lockRow('promotions', 'Promotions') !!}@endif
    @if($promos->isEmpty())
      <div class="sr-empty">No promotions yet.</div>
    @else
      <div style="overflow-x:auto">
      <table class="sr-t">
        <thead><tr><th style="width:96px"></th><th>Promotion</th><th class="hide-sm">Shows for</th><th class="hide-sm">Where</th><th>When</th><th class="n">Seen</th><th class="n">Clicks</th><th></th></tr></thead>
        <tbody>
        @foreach($promos as $p)
          @php
            $st = $p->status();
            $stPill = ['showing' => '<span class="sr-pill g">Showing</span>', 'off' => '<span class="sr-pill">Off</span>', 'scheduled' => '<span class="sr-pill a">Starts ' . e($p->starts_on?->format('M j')) . '</span>', 'ended' => '<span class="sr-pill">Ended</span>'][$st];
            $linkText = match ($p->link_type) { 'category' => 'Category: ' . ($catNames[$p->link_value] ?? '?'), 'brand' => 'Brand: ' . $p->link_value, 'page' => 'Page: ' . $p->link_value, 'item' => 'An item', default => $p->link_value ?: 'Shop' };
          @endphp
          <tr>
            <td>@if($p->image_url)<img class="sr-thumb" src="{{ $p->image_url }}" alt="">@else<span class="sr-thumb"></span>@endif</td>
            <td><b>{{ $p->title }}</b><div style="font-size:12px;color:var(--ia-text-muted)">{{ $p->subtitle ? $p->subtitle . ' · ' : '' }}links to {{ $linkText }}</div></td>
            <td class="hide-sm" style="max-width:220px">@foreach((array) $p->keywords as $k)<span class="sr-pill" style="margin:1px">{{ $k }}</span> @endforeach @foreach((array) $p->category_ids as $c)<span class="sr-pill b" style="margin:1px">{{ $catNames[$c] ?? 'Category' }}</span> @endforeach</td>
            <td class="hide-sm" style="font-size:12px;color:var(--ia-text-muted)">{!! collect((array) $p->placements)->map(fn ($w) => e($placeNames[$w] ?? $w))->implode('<br>') !!}</td>
            <td style="font-size:12px;white-space:nowrap">{{ $p->starts_on || $p->ends_on ? ($p->starts_on?->format('M j') ?? 'Now') . ' – ' . ($p->ends_on?->format('M j') ?? 'no end') : 'Always' }}<br>{!! $stPill !!}</td>
            <td class="n">{{ number_format($p->seen_count) }}</td>
            <td class="n">{{ number_format($p->click_count) }}@if($p->seen_count)<div style="font-size:11px;color:var(--ia-text-muted)">{{ number_format($p->click_count / $p->seen_count * 100, 1) }}%</div>@endif</td>
            <td class="n" style="white-space:nowrap">@if($canEdit)<button type="button" class="ia-btn ia-btn--sm" data-promo="{{ $p->id }}">Edit</button> <button type="button" class="ia-btn ia-btn--sm" data-del="{{ route('tenant.reports.search.promo.delete', $p->id) }}" data-del-promo>Delete…</button>@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    @endif
    <div class="sr-note" style="margin-top:10px">Seen counts once per shopper visit. Clicks count once per visit too, so a shopper going back and forth counts once.</div>
  </div>
@endif

{{-- ================================================================ DISPLAY --}}
@if($tab === 'display')
  <form method="POST" action="{{ route('tenant.reports.search.display') }}" data-dirty-form id="srDisplay">
    @csrf
    <div class="sr-sec">
      <div class="sr-head"><div><h3>What shoppers see</h3><p>How search looks in your shop's menu bar and on the results page. It takes the colours and fonts of your website template.</p></div>
        <a class="ia-btn ia-btn--sm" href="{{ url('/shop') }}" target="_blank" rel="noopener">Open your shop ↗</a></div>
      @foreach(\App\Support\ShopSearch\SearchConfig::DISPLAY as $k => [$label, $help])
        <div class="sr-row"><div><div class="t">{{ $label }}</div>@if($help)<div class="d">{{ $help }}</div>@endif</div><label class="tg"><input type="checkbox" name="{{ $k }}" value="1" @checked($display[$k]) @disabled(! $canEdit)><i></i></label></div>
      @endforeach
      <div class="sr-row"><div><div class="t">Filters on the results page</div><div class="d">Counts update as the shopper picks.</div></div>
        <div class="sr-chk">@foreach(\App\Support\ShopSearch\SearchConfig::FACETS as $k => $label)<label><input type="checkbox" name="facets[]" value="{{ $k }}" @checked(in_array($k, $display['facets'], true)) @disabled(! $canEdit)>{{ $label }}</label>@endforeach</div></div>
      <div class="sr-row"><div><div class="t">Results layout</div><div class="d">Shoppers can still switch.</div></div>
        <select class="ia-select" name="layout" style="width:auto" @disabled(! $canEdit)>@foreach(\App\Support\ShopSearch\SearchConfig::LAYOUTS as $k => $label)<option value="{{ $k }}" @selected($display['layout'] === $k)>{{ $label }}</option>@endforeach</select></div>
      <div class="sr-row" style="align-items:flex-start"><div><div class="t">Popular searches</div><div class="d">Shown in the empty search box and when a search finds nothing.</div></div>
        <div style="display:flex;flex-direction:column;gap:6px;min-width:260px">
          <label style="display:flex;gap:6px;font-size:13px"><input type="radio" name="popular_mode" value="top" @checked($display['popular_mode'] === 'top') @disabled(! $canEdit)> Your top searches</label>
          <label style="display:flex;gap:6px;font-size:13px"><input type="radio" name="popular_mode" value="list" @checked($display['popular_mode'] === 'list') @disabled(! $canEdit)> A list you choose</label>
          <textarea class="ia-input" name="popular_list" rows="2" placeholder="e.g. gift card, helmets, tubes" @disabled(! $canEdit)>{{ implode(', ', $display['popular_list']) }}</textarea>
        </div></div>
      <div class="sr-row"><div><div class="t">Words in the empty search box</div></div>
        <input class="ia-input" name="placeholder" value="{{ $display['placeholder'] }}" maxlength="60" style="width:260px" @disabled(! $canEdit)></div>
    </div>

    <div class="sr-sec">
      <div class="sr-head"><div><h3>Category pictures</h3><p>The tiles shoppers see in the search box and on the results page. Without a picture, the photo of an item in that category is used.</p></div></div>
      @forelse($cats as $c)
        <div class="sr-cat" data-cat="{{ $c['id'] }}">
          @if($c['image'] ?: $c['auto'])<img src="{{ $c['image'] ?: $c['auto'] }}" alt="" data-cat-img>@else<span class="ph" data-cat-img></span>@endif
          <div class="x">{{ $c['name'] }}<small data-cat-note>{{ $c['image'] ? 'Your picture' : ($c['auto'] ? 'Photo of an item in it' : 'No picture yet') }}</small></div>
          <input type="hidden" name="cat_img[{{ $c['id'] }}]" value="{{ $c['image'] }}" data-cat-val>
          @if($canEdit)
            <button type="button" class="ia-btn ia-btn--sm" data-cat-choose>Choose…</button>
            <button type="button" class="ia-btn ia-btn--sm" data-cat-clear @if(! $c['image']) hidden @endif>Use an item photo</button>
          @endif
        </div>
      @empty
        <div class="sr-empty">No categories yet.</div>
      @endforelse
    </div>

    @if($canEdit)
      <div class="sr-save"><button class="ia-btn ia-btn--primary">Save changes</button><span class="sr-dirty" data-dirty>No unsaved changes</span></div>
    @endif
  </form>
@endif

<details class="sr-explain">
  <summary>What search does</summary>
  <p style="margin:8px 0 0"><b>It always works without setup.</b> Search matches every word against an item's name, brand, subtitle, codes and category, forgives small typos, understands plurals, and logs every search.</p>
  <p style="margin:6px 0 0"><b>Rules only steer it.</b> A synonym makes two words find each other. A redirect sends a search to a page. Pin, boost and hide change the order for one search; they never remove an item from your shop.</p>
  <p style="margin:6px 0 0"><b>Clicked, Added and Bought</b> follow the same shopper's visit from search to checkout, using the visit your shop already keeps for the cart; no new cookie is set. Shoppers whose browser asks not to be tracked are counted as searches only.</p>
  <p style="margin:6px 0 0"><b>A change applies within a minute</b> of saving, in the search box and on the results page.</p>
</details>

@endif
</div>

{{-- ================================================================ modals --}}
<div class="sr-mdl" id="srRule" role="dialog" aria-modal="true" aria-labelledby="srRuleH">
  <form class="sr-box" method="POST" action="{{ route('tenant.reports.search.rule') }}" id="srRuleF">
    @csrf
    <h4 id="srRuleH">Add a rule</h4>
    <input type="hidden" name="id"><input type="hidden" name="back" value="rules">
    <label class="f" for="srRt">Type</label>
    <select class="ia-select" name="type" id="srRt">
      <option value="synonym" @disabled(! $can['synonyms'])>Synonym{{ $can['synonyms'] ? '' : ' (' . $unlock['synonyms'] . ' plan)' }}</option>
      <option value="redirect" @disabled(! $can['redirects'])>Redirect to a page{{ $can['redirects'] ? '' : ' (' . $unlock['redirects'] . ' plan)' }}</option>
      <option value="stop" @disabled(! $can['synonyms'])>Stop words{{ $can['synonyms'] ? '' : ' (' . $unlock['synonyms'] . ' plan)' }}</option>
      <option value="pin" @disabled(! $can['pbh'])>Pin an item to the top{{ $can['pbh'] ? '' : ' (' . $unlock['pbh'] . ' plan)' }}</option>
      <option value="boost" @disabled(! $can['pbh'])>Boost an item{{ $can['pbh'] ? '' : ' (' . $unlock['pbh'] . ' plan)' }}</option>
      <option value="hide" @disabled(! $can['pbh'])>Hide an item from this search{{ $can['pbh'] ? '' : ' (' . $unlock['pbh'] . ' plan)' }}</option>
    </select>
    <div data-for="synonym redirect pin boost hide">
      <label class="f" for="srFrom" data-lbl-from>When the shopper searches</label>
      <input class="ia-input" name="from_term" id="srFrom" maxlength="120" autocomplete="off">
    </div>
    <div data-for="stop">
      <label class="f" for="srStop">Words to ignore when they stop a match</label>
      <textarea class="ia-input" id="srStop" rows="2" placeholder="e.g. the, for, with" data-stop></textarea>
    </div>
    <div data-for="synonym">
      <label class="f" for="srTo">Treated as</label>
      <input class="ia-input" name="to_value" id="srTo" maxlength="120" placeholder="e.g. wireless charger" autocomplete="off">
      <div class="sr-note" style="margin-top:6px">Works both ways, in the search box and on the results page.</div>
    </div>
    <div data-for="redirect">
      <label class="f" for="srPage">Send the shopper to</label>
      <select class="ia-select" id="srPage" data-page>
        @foreach(($pages ?? []) as $pg)<option value="{{ $pg['url'] }}" data-title="{{ $pg['title'] }}">{{ $pg['title'] }} ({{ $pg['url'] }})</option>@endforeach
        <option value="__url">A web address…</option>
      </select>
      <input class="ia-input" id="srUrl" placeholder="https://… or /page" style="margin-top:6px" hidden data-url>
      <input type="hidden" name="label" data-label>
    </div>
    <div data-for="pin boost hide">
      <label class="f" for="srItemQ">Item</label>
      <input class="ia-input" id="srItemQ" placeholder="Type a name or SKU" autocomplete="off" data-item-q>
      <input type="hidden" name="item_id" data-item-id>
      <div class="sr-pick" data-item-list hidden></div>
      <div class="sr-note" style="margin-top:6px" data-item-chosen></div>
    </div>
    <div class="bt"><button type="button" class="ia-btn" data-close>Cancel</button><button class="ia-btn ia-btn--primary" data-submit>Add rule</button></div>
  </form>
</div>

@if($tab === 'promotions')
<div class="sr-mdl" id="srPromo" role="dialog" aria-modal="true" aria-labelledby="srPromoH">
  <form class="sr-box wide" method="POST" action="{{ route('tenant.reports.search.promo') }}" id="srPromoF">
    @csrf
    <h4 id="srPromoH">New promotion</h4>
    <input type="hidden" name="id">
    <div class="sr-grid2">
      <div>
        <label class="f">Image</label>
        <div class="sr-drop" data-drop tabindex="0" role="button" aria-label="Choose an image"><span>Drop an image here or click to upload</span></div>
        <input type="file" accept="image/*" hidden data-file>
        <input type="hidden" name="image_url"><input type="hidden" name="image_media_id">
        <div style="display:flex;gap:8px;margin-top:6px"><button type="button" class="ia-btn ia-btn--sm" data-media>From your Media library</button><button type="button" class="ia-btn ia-btn--sm" data-noimg>No image</button></div>
        <div class="sr-media" data-media-grid hidden></div>
        <label class="f" for="srPt">Headline</label>
        <input class="ia-input" name="title" id="srPt" maxlength="120" placeholder="e.g. 20% off chargers" required>
        <label class="f" for="srPs">Line under it</label>
        <input class="ia-input" name="subtitle" id="srPs" maxlength="160" placeholder="e.g. Through Sunday">
        <label class="f" for="srPb">Button words</label>
        <input class="ia-input" name="button_label" id="srPb" maxlength="40" placeholder="Shop the offer">
        <label class="f" for="srLt">Links to</label>
        <div style="display:flex;gap:8px">
          <select class="ia-select" name="link_type" id="srLt" style="max-width:150px">@foreach(\App\Models\Tenant\TenantSearchPromotion::LINKS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
          <input type="hidden" name="link_value" data-link-val>
          <select class="ia-select" data-link="category">@foreach($cats as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select>
          <select class="ia-select" data-link="page" hidden>@foreach($pages as $pg)<option value="{{ ltrim($pg['url'], '/') }}">{{ $pg['title'] }}</option>@endforeach</select>
          <select class="ia-select" data-link="brand" hidden>@foreach($brands as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach</select>
          <input class="ia-input" data-link="url" placeholder="https://… or /page" hidden>
          <div data-link="item" hidden style="flex:1;position:relative"><input class="ia-input" placeholder="Type a name or SKU" data-pitem-q autocomplete="off"><div class="sr-pick" data-pitem-list hidden style="position:absolute;left:0;right:0;z-index:5;background:var(--ia-surface)"></div></div>
        </div>
        @error('link_value')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div>
        <label class="f">Show when shoppers search for</label>
        <div class="ia-input sr-kw" data-kw><input placeholder="Add a word, press Enter" data-kw-in></div>
        <input type="hidden" name="keywords" data-kw-val>
        <div class="sr-note" style="margin-top:4px">Close spellings count too: "chargr" finds a promotion set for "charger".</div>
        @error('keywords')<div class="err">{{ $message }}</div>@enderror
        <label class="f">…or when they browse these categories</label>
        <div class="sr-chk" style="max-height:120px;overflow:auto">@foreach($cats as $c)<label><input type="checkbox" name="category_ids[]" value="{{ $c['id'] }}">{{ $c['name'] }}</label>@endforeach</div>
        <label class="f">Where it shows</label>
        <div class="sr-chk">@foreach(\App\Models\Tenant\TenantSearchPromotion::PLACES as $k => $l)<label><input type="checkbox" name="placements[]" value="{{ $k }}">{{ $l }}</label>@endforeach</div>
        @error('placements')<div class="err">{{ $message }}</div>@enderror
        <label class="f">When</label>
        <div style="display:flex;gap:8px;align-items:center"><input class="ia-input" type="date" name="starts_on"><span style="color:var(--ia-text-muted)">to</span><input class="ia-input" type="date" name="ends_on"></div>
        <div class="sr-note" style="margin-top:4px">Leave both empty to show it until you turn it off.</div>
        @error('ends_on')<div class="err">{{ $message }}</div>@enderror
        <div class="sr-row" style="border:0;padding-bottom:0"><div class="t">On</div><label class="tg"><input type="checkbox" name="enabled" value="1" checked><i></i></label></div>
        <label class="f">Preview</label>
        <div class="sr-prev">
          <div style="font-size:11px;color:var(--ia-text-muted)">In the search box</div>
          <div class="row"><img data-pv-img alt=""><div><b data-pv-t style="font-size:13px;display:block">Headline</b><span data-pv-s style="font-size:12px;color:var(--ia-text-muted)"></span></div></div>
          <div style="font-size:11px;color:var(--ia-text-muted);margin-top:10px">Banner above results</div>
          <div class="ban"><img data-pv-img alt=""><div><b data-pv-t>Headline</b><span data-pv-s style="font-size:13px;opacity:.85"></span></div></div>
        </div>
      </div>
    </div>
    <div class="bt"><button type="button" class="ia-btn" data-close>Cancel</button><button class="ia-btn ia-btn--primary" data-submit>Add promotion</button></div>
  </form>
</div>
@endif

<form method="POST" id="srDelF" hidden>@csrf</form>
<input type="file" accept="image/*" hidden id="srCatFile">

@push('scripts')
<script>
(function () {
  'use strict';
  var csrf = '{{ csrf_token() }}';
  var URL_ITEMS = @json(route('tenant.reports.search.items'));
  var URL_FEED  = @json(route('tenant.media.feed'));
  var URL_UP    = @json(route('tenant.uploads.store'));
  var PROMOS = {!! json_encode($promoJson) !!};
  var OLD = {!! json_encode($promoOld) !!};
  function $(s, e) { return (e || document).querySelector(s); }
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  function esc(x) { var d = document.createElement('div'); d.textContent = x == null ? '' : String(x); return d.innerHTML; }
  function err(m) { if (window.IntakeToast) IntakeToast.error(m); }

  function openM(m) { m.classList.add('on'); var f = $('input:not([type=hidden]):not([hidden]),select', m); if (f) setTimeout(function () { f.focus(); }, 30); }
  function closeM(m) { m.classList.remove('on'); }
  $$('.sr-mdl').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) closeM(m); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('.sr-mdl.on').forEach(closeM); });

  // ------------------------------------------------ delete (in-app confirm)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del]'); if (!b) return;
    var promo = b.hasAttribute('data-del-promo');
    IntakeConfirm.show({
      title: promo ? 'Delete this promotion?' : 'Remove this rule?',
      message: promo ? 'It stops showing straight away. Its seen and click counts go with it.' : (b.getAttribute('data-del-what') + ' stops applying within a minute. Searches already logged keep their history.'),
      confirmText: promo ? 'Delete' : 'Remove', danger: true
    }).then(function (ok) { if (!ok) return; var f = $('#srDelF'); f.action = b.getAttribute('data-del'); f.submit(); });
  });

  // ------------------------------------------------ unsaved changes
  $$('[data-dirty-form]').forEach(function (f) {
    var lab = $('[data-dirty]', f); if (!lab) return;
    var mark = function () { lab.textContent = 'Unsaved changes'; lab.classList.add('on'); f.__dirty = true; };
    f.addEventListener('input', mark); f.addEventListener('change', mark);
    f.addEventListener('submit', function () { f.__dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (f.__dirty) { e.preventDefault(); e.returnValue = ''; } });
    f.__mark = mark;
  });

  // ------------------------------------------------ item picker
  function picker(input, list, onPick) {
    var t = null, seq = 0;
    input.addEventListener('input', function () {
      clearTimeout(t); var q = input.value.trim(), my = ++seq;
      if (q.length < 2) { list.hidden = true; return; }
      t = setTimeout(function () {
        fetch(URL_ITEMS + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
          if (my !== seq) return;
          list.innerHTML = (d.items || []).map(function (i) { return '<a data-id="' + esc(i.id) + '" data-name="' + esc(i.name) + '">' + (i.img ? '<img src="' + esc(i.img) + '" alt="">' : '<img alt="">') + '<span>' + esc(i.name) + (i.sku ? ' <small>' + esc(i.sku) + '</small>' : '') + '</span></a>'; }).join('') || '<div class="sr-empty" style="padding:10px">No items match.</div>';
          list.hidden = false;
        }).catch(function () {});
      }, 200);
    });
    list.addEventListener('click', function (e) { var a = e.target.closest('a[data-id]'); if (!a) return; list.hidden = true; onPick(a.getAttribute('data-id'), a.getAttribute('data-name')); });
  }

  // ------------------------------------------------ rule modal
  var RM = $('#srRule'), RF = $('#srRuleF');
  if (RM) {
    var rt = $('#srRt', RM);
    function syncType() {
      var t = rt.value;
      $$('[data-for]', RM).forEach(function (d) { d.hidden = d.getAttribute('data-for').split(' ').indexOf(t) < 0; });
      $('[data-lbl-from]', RM).textContent = t === 'synonym' ? 'Shopper types' : 'When the shopper searches';
      $('#srFrom').required = t !== 'stop';
      $('#srTo').required = t === 'synonym';
      syncPage();
    }
    function syncPage() {
      var s = $('[data-page]', RM), u = $('[data-url]', RM);
      u.hidden = s.value !== '__url';
    }
    rt.addEventListener('change', syncType);
    $('[data-page]', RM).addEventListener('change', syncPage);
    picker($('[data-item-q]', RM), $('[data-item-list]', RM), function (id, name) {
      $('[data-item-id]', RM).value = id; $('[data-item-q]', RM).value = ''; $('[data-item-chosen]', RM).innerHTML = 'Chosen: <b>' + esc(name) + '</b>';
    });
    RF.addEventListener('submit', function (e) {
      var t = rt.value;
      if (t === 'redirect') {
        var s = $('[data-page]', RM), v = s.value === '__url' ? $('[data-url]', RM).value.trim() : s.value;
        if (!v) { e.preventDefault(); err('Pick where the search should go.'); return; }
        $('#srTo').value = v;
        $('[data-label]', RM).value = s.value === '__url' ? '' : (s.options[s.selectedIndex].getAttribute('data-title') || '');
      }
      if (t === 'stop') { $('#srFrom').value = $('[data-stop]', RM).value; if (!$('#srFrom').value.trim()) { e.preventDefault(); err('Add at least one word.'); } }
      if ((t === 'pin' || t === 'boost' || t === 'hide') && !$('[data-item-id]', RM).value) { e.preventDefault(); err('Pick an item first.'); }
      if (t !== 'synonym' && t !== 'redirect') $('#srTo').value = '';
    });
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rule]'); if (!b) return;
      var d = JSON.parse(b.getAttribute('data-rule') || '{}');
      RF.reset();
      RF.elements.id.value = d.id || '';
      RF.elements.back.value = d.back || 'rules';
      var first = $$('option', rt).filter(function (o) { return !o.disabled; })[0];
      rt.value = d.type || (first ? first.value : 'synonym');
      if (rt.selectedOptions[0] && rt.selectedOptions[0].disabled && !d.id) rt.value = first ? first.value : 'synonym';
      rt.disabled = !!d.id; // an existing rule keeps its type
      $('#srFrom').value = d.from_term || '';
      $('#srTo').value = d.type === 'synonym' ? (d.to_value || '') : '';
      $('[data-stop]', RM).value = d.type === 'stop' ? (d.from_term || '') : '';
      $('[data-item-id]', RM).value = d.item_id || '';
      $('[data-item-chosen]', RM).innerHTML = d.item_name ? 'Chosen: <b>' + esc(d.item_name) + '</b>' : '';
      var ps = $('[data-page]', RM);
      if (d.type === 'redirect' && d.to_value) {
        var match = $$('option', ps).filter(function (o) { return o.value === d.to_value; })[0];
        if (match) ps.value = d.to_value; else { ps.value = '__url'; $('[data-url]', RM).value = d.to_value; }
      }
      $('#srRuleH').textContent = d.id ? 'Edit rule' : (d.from_term ? 'Add a rule for "' + d.from_term + '"' : 'Add a rule');
      $('[data-submit]', RM).textContent = d.id ? 'Save rule' : 'Add rule';
      syncType(); openM(RM);
    });
    // a disabled select is not sent; send the type anyway
    RF.addEventListener('submit', function () { if (rt.disabled) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'type'; h.value = rt.value; RF.appendChild(h); } });
  }

  // ------------------------------------------------ uploads and the media library
  function upload(file) {
    var fd = new FormData(); fd.append('file', file); fd.append('type', 'general');
    return fetch(URL_UP, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j.ok) throw new Error(j.message || 'Upload failed.'); return j; });
  }
  function mediaGrid(grid, onPick) {
    grid.hidden = !grid.hidden; if (grid.hidden) return;
    grid.innerHTML = '<div class="sr-note">Loading…</div>';
    fetch(URL_FEED, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
      grid.innerHTML = (j.media || []).map(function (m) { return '<img src="' + esc(m.url) + '" data-id="' + esc(m.id) + '" alt="' + esc(m.original_name || '') + '" title="' + esc(m.original_name || '') + '">'; }).join('') || '<div class="sr-note">Your Media library has no pictures yet.</div>';
    }).catch(function () { grid.innerHTML = '<div class="sr-note">Could not load your Media library.</div>'; });
    grid.onclick = function (e) { var i = e.target.closest('img[data-id]'); if (!i) return; grid.hidden = true; onPick(i.src, i.getAttribute('data-id')); };
  }

  // ------------------------------------------------ promotion editor
  var PM = $('#srPromo'), PF = $('#srPromoF');
  if (PM) {
    var kws = [];
    function drawKw() {
      var box = $('[data-kw]', PM), inp = $('[data-kw-in]', PM);
      $$('span', box).forEach(function (s) { s.remove(); });
      kws.forEach(function (k, i) { var s = document.createElement('span'); s.innerHTML = esc(k) + ' <button type="button" aria-label="Remove ' + esc(k) + '">✕</button>'; s.querySelector('button').onclick = function () { kws.splice(i, 1); drawKw(); }; box.insertBefore(s, inp); });
      $('[data-kw-val]', PM).value = kws.join(', ');
    }
    $('[data-kw-in]', PM).addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ',') && this.value.trim()) { e.preventDefault(); this.value.split(',').forEach(function (w) { w = w.trim().toLowerCase(); if (w && kws.indexOf(w) < 0) kws.push(w); }); this.value = ''; drawKw(); }
      if (e.key === 'Backspace' && !this.value && kws.length) { kws.pop(); drawKw(); }
    });
    function setImg(url, mid) {
      PF.elements.image_url.value = url || ''; PF.elements.image_media_id.value = mid || '';
      var d = $('[data-drop]', PM);
      d.classList.toggle('has', !!url);
      d.innerHTML = url ? '<img src="' + esc(url) + '" alt=""><span>Click or drop to change</span>' : '<span>Drop an image here or click to upload</span>';
      prev();
    }
    function prev() {
      $$('[data-pv-t]', PM).forEach(function (e) { e.textContent = PF.elements.title.value || 'Headline'; });
      $$('[data-pv-s]', PM).forEach(function (e) { e.textContent = PF.elements.subtitle.value || ''; });
      $$('[data-pv-img]', PM).forEach(function (e) { if (PF.elements.image_url.value) { e.src = PF.elements.image_url.value; e.style.visibility = ''; } else { e.removeAttribute('src'); e.style.visibility = 'hidden'; } });
    }
    PF.elements.title.addEventListener('input', prev); PF.elements.subtitle.addEventListener('input', prev);
    var drop = $('[data-drop]', PM), file = $('[data-file]', PM);
    function takeFile(f) { if (!f) return; drop.innerHTML = '<span>Uploading…</span>'; upload(f).then(function (j) { setImg(j.url, j.media_id); }).catch(function (x) { setImg(PF.elements.image_url.value, PF.elements.image_media_id.value); err(x.message); }); }
    drop.addEventListener('click', function () { file.click(); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } });
    file.addEventListener('change', function () { takeFile(file.files[0]); file.value = ''; });
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); takeFile(e.dataTransfer.files[0]); });
    $('[data-media]', PM).addEventListener('click', function () { mediaGrid($('[data-media-grid]', PM), setImg); });
    $('[data-noimg]', PM).addEventListener('click', function () { setImg('', ''); });

    var lt = PF.elements.link_type;
    function syncLink() { $$('[data-link]', PM).forEach(function (el) { el.hidden = el.getAttribute('data-link') !== lt.value; }); }
    lt.addEventListener('change', syncLink);
    picker($('[data-pitem-q]', PM), $('[data-pitem-list]', PM), function (id, name) { $('[data-pitem-q]', PM).value = name; $('[data-pitem-q]', PM).setAttribute('data-id', id); });
    PF.addEventListener('submit', function (e) {
      var t = lt.value, el = $('[data-link="' + t + '"]', PM), v = '';
      if (t === 'item') v = $('[data-pitem-q]', PM).getAttribute('data-id') || '';
      else v = (el.value || '').trim();
      $('[data-link-val]', PM).value = v;
      var pend = $('[data-kw-in]', PM).value.trim(); if (pend) { kws.push(pend.toLowerCase()); drawKw(); }
      if (!kws.length && !$$('input[name="category_ids[]"]:checked', PM).length) { e.preventDefault(); err('Add a word or pick a category, so it knows when to show.'); return; }
      if (!$$('input[name="placements[]"]:checked', PM).length) { e.preventDefault(); err('Pick at least one place for it to show.'); return; }
      if (t !== 'url' && !v) { e.preventDefault(); err('Pick where the promotion links to.'); }
    });

    function openPromo(p) {
      PF.reset();
      PF.elements.id.value = p.id || '';
      PF.elements.title.value = p.title || ''; PF.elements.subtitle.value = p.subtitle || ''; PF.elements.button_label.value = p.button_label || '';
      kws = (p.keywords || []).slice(); drawKw();
      $$('input[name="category_ids[]"]', PM).forEach(function (c) { c.checked = (p.category_ids || []).indexOf(c.value) > -1; });
      $$('input[name="placements[]"]', PM).forEach(function (c) { c.checked = (p.placements || ['dropdown']).indexOf(c.value) > -1; });
      PF.elements.starts_on.value = p.starts_on || ''; PF.elements.ends_on.value = p.ends_on || '';
      PF.elements.enabled.checked = p.enabled !== false && p.enabled !== 0 && p.enabled !== '0';
      lt.value = p.link_type || 'category';
      var el = $('[data-link="' + lt.value + '"]', PM);
      if (lt.value === 'item') { var q = $('[data-pitem-q]', PM); q.value = p.link_value ? 'Item chosen' : ''; q.setAttribute('data-id', p.link_value || ''); }
      else if (el && p.link_value) el.value = p.link_value;
      syncLink();
      setImg(p.image_url || '', p.image_media_id || '');
      $('#srPromoH').textContent = p.id ? 'Edit promotion' : 'New promotion';
      $('[data-submit]', PM).textContent = p.id ? 'Save promotion' : 'Add promotion';
      openM(PM);
    }
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-promo]'); if (!b) return;
      var id = b.getAttribute('data-promo');
      openPromo(id === 'new' ? {} : (PROMOS[id] || {}));
    });
    if (OLD) {
      var base = OLD.id && PROMOS[OLD.id] ? PROMOS[OLD.id] : {};
      openPromo(Object.assign({}, base, {
        id: OLD.id, title: OLD.title, subtitle: OLD.subtitle, button_label: OLD.button_label, image_url: OLD.image_url, image_media_id: OLD.image_media_id,
        link_type: OLD.link_type, link_value: OLD.link_value, keywords: (OLD.keywords || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean),
        category_ids: OLD.category_ids || [], placements: OLD.placements || [], starts_on: OLD.starts_on, ends_on: OLD.ends_on, enabled: !!OLD.enabled
      }));
    }
  }

  // ------------------------------------------------ category pictures
  var catFile = $('#srCatFile'), catRow = null;
  function setCat(row, url, note) {
    $('[data-cat-val]', row).value = url;
    var img = $('[data-cat-img]', row);
    if (url) { if (img.tagName !== 'IMG') { var n = document.createElement('img'); n.setAttribute('data-cat-img', ''); img.replaceWith(n); img = n; } img.src = url; }
    $('[data-cat-note]', row).textContent = note;
    $('[data-cat-clear]', row).hidden = !url;
    var f = $('#srDisplay'); if (f && f.__mark) f.__mark();
  }
  document.addEventListener('click', function (e) {
    var ch = e.target.closest('[data-cat-choose]'), cl = e.target.closest('[data-cat-clear]');
    if (cl) { setCat(cl.closest('[data-cat]'), '', 'Photo of an item in it, after you save'); return; }
    if (!ch) return;
    catRow = ch.closest('[data-cat]');
    var grid = $('[data-cat-grid]', catRow);
    if (!grid) {
      grid = document.createElement('div'); grid.className = 'sr-media'; grid.setAttribute('data-cat-grid', ''); grid.hidden = true;
      grid.style.flexBasis = '100%';
      var up = document.createElement('button'); up.type = 'button'; up.className = 'ia-btn ia-btn--sm'; up.textContent = 'Upload a picture'; up.style.margin = '6px 0';
      up.onclick = function () { catFile.click(); };
      var wrap = document.createElement('div'); wrap.style.flexBasis = '100%'; wrap.appendChild(up); wrap.appendChild(grid);
      catRow.style.flexWrap = 'wrap'; catRow.appendChild(wrap);
    }
    mediaGrid(grid, function (url) { setCat(catRow, url, 'Your picture'); });
  });
  if (catFile) catFile.addEventListener('change', function () {
    var f = catFile.files[0]; catFile.value = ''; if (!f || !catRow) return;
    upload(f).then(function (j) { setCat(catRow, j.url, 'Your picture'); var g = $('[data-cat-grid]', catRow); if (g) g.hidden = true; }).catch(function (x) { err(x.message); });
  });
})();
</script>
@endpush
@endsection
