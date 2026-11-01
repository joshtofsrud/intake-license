@extends('layouts.tenant.app')
@section('title', 'Reports · Custom')

@push('styles')
@include('tenant.reports._tab_styles')
<style>
  /* MARKER-REPORT-BUILDER-CORE MARKER-REPORT-LAYOUT */
  .cr-grid { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 28px; align-items: start; }
  @media (max-width: 900px) { .cr-grid { grid-template-columns: 1fr; } }
  .cr-sect { padding: 14px 0; border-bottom: 1px solid var(--ia-border, #1f1f1f); }
  .cr-lbl { font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ia-text-3, #888); font-weight: 700; margin-bottom: 8px; display: flex; justify-content: space-between; }
  .cr-sel { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--ia-border, #1f1f1f); background: transparent; color: inherit; font: inherit; font-size: 13px; }
  .cr-hint { font-size: 12px; color: var(--ia-text-3, #888); margin-top: 6px; line-height: 1.45; }
  .cr-chk { display: flex; gap: 8px; align-items: center; padding: 5px 0; font-size: 13px; cursor: pointer; }
  .cr-fr { margin-top: 8px; }
  .cr-fr span { display: block; font-size: 11.5px; color: var(--ia-text-3, #888); margin-bottom: 3px; }
  .cr-btn { display: inline-block; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--ia-border, #1f1f1f); background: transparent; color: inherit; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; }
  .cr-btn.p { background: #BEF264; color: #0a0a0a; border-color: #BEF264; }
  .cr-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
  .cr-title { font-size: 17px; font-weight: 800; letter-spacing: -0.01em; }
  .cr-tot td { font-weight: 800; border-top: 2px solid var(--ia-border-2, #2a2a2a); }
  .cr-legend { margin-top: 26px; padding: 14px 16px; border-left: 2px solid var(--ia-border-2, #2a2a2a); font-size: 12.5px; line-height: 1.55; color: var(--ia-text-3, #888); }
  .cr-legend b { color: var(--ia-text, #f0f0f0); }
  .cr-legend .row { display: flex; gap: 8px; margin-top: 4px; }
  .cr-legend .row > span:first-child { min-width: 92px; color: var(--ia-text, #f0f0f0); font-weight: 600; }
  .cr-list a.nm { font-weight: 700; color: inherit; text-decoration: none; }
  .cr-confirm { display: none; gap: 6px; align-items: center; font-size: 12px; }
  .cr-confirm.on { display: inline-flex; }
  .cr-sugg { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
  .cr-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-top: 4px; }
  @media (max-width: 1250px) { .cr-tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  @media (max-width: 900px) { .cr-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (max-width: 560px) { .cr-tiles { grid-template-columns: 1fr; } }
  .cr-tiles .cr-tile.more { display: none; }
  .cr-tiles.all .cr-tile.more { display: flex; }
  .cr-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 6px; }
  .cr-tile { display: flex; flex-direction: column; gap: 6px; padding: 16px 16px 14px; min-height: 178px; border: 1px solid var(--ia-border, #1f1f1f); border-radius: 10px; color: inherit; text-decoration: none; }
  .cr-tile:hover { border-color: var(--ia-border-2, #2a2a2a); background: rgba(127,127,127,0.06); }
  .cr-tile .ico { width: 42px; height: 42px; border-radius: 9px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; color: #BEF264; background: rgba(190,242,100,0.10); }
  .cr-tile .ico svg { width: 22px; height: 22px; }
  .cr-tile .nm { font-size: 14.5px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.3; }
  .cr-tile .ds { font-size: 12px; color: var(--ia-text-3, #888); line-height: 1.45; }
  .cr-tile .go { margin-top: auto; padding-top: 8px; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ia-text-3, #888); }
</style>
@endpush

@section('content')
@php
  $def = $catalog[$cfg['ds']];
  $money = fn ($v) => '$' . number_format((float) $v, 2);
  $fmt = function ($v, $type) use ($money) {
      if ($v === null) return '—';
      if ($type === 'money') return $money($v);
      if ($type === 'pct')   return number_format((float) $v, 1) . '%';
      return rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
  };
  $runQuery = array_filter([
      'ds' => $cfg['ds'], 'm' => $cfg['m'], 'dim' => $cfg['dim'], 'f' => $cfg['f'],
      'range' => $cfg['range'], 'sort' => $cfg['sort'], 'limit' => $cfg['limit'], 'go' => 1,
  ], fn ($x) => $x !== [] && $x !== null);
  $exportQuery = $saved ? ['saved' => $saved->id] : $runQuery;
  // Two screens, as in the mockup: the landing (templates, your reports) and the builder.
  $showBuilder = $result !== null || request()->boolean('new');
@endphp
<div style="padding: 32px 40px;">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">Build your own report from your shop's data.</div>

  <div class="rep-controls">
    @include('tenant.reports._tab_subnav', ['active' => 'custom'])
  </div>

  @if(!$showBuilder)
    {{-- ------------------------------------------------------------- landing --}}
    <div class="cr-head">
      <div class="cr-lbl" style="margin:0;">Templates</div>
      <a class="cr-btn p" href="{{ route('tenant.reports.custom', ['new' => 1]) }}">+ New report</a>
    </div>
    <div class="cr-hint" style="margin:0 0 12px;">Start from one of these, or press New report to build your own.</div>
    <div class="cr-tiles big" id="cr-tiles">
      @foreach($templates as $i => $tpl)
        <a class="cr-tile{{ $i >= 8 ? ' more' : '' }}" href="{{ route('tenant.reports.custom', $tpl['cfg']) }}">
          <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Services\Tenant\ReportBuilder\ReportIcons::get($tpl['icon']) !!}</svg></span>
          <span class="nm">{{ $tpl['name'] }}</span>
          <span class="ds">{{ $tpl['desc'] }}</span>
          <span class="go">{{ $tpl['data'] }}</span>
        </a>
      @endforeach
    </div>
    @if(count($templates) > 8)
      <button class="cr-btn" type="button" style="margin-top:12px;" onclick="var g=document.getElementById('cr-tiles');var on=g.classList.toggle('all');this.textContent=on?'Show fewer':'Show all {{ count($templates) }}';">Show all {{ count($templates) }}</button>
    @endif

    <div style="margin-top:34px;">
        <div class="cr-lbl">Your reports</div>
        @if(count($myReports) === 0)
          <div class="cr-hint">Nothing saved yet. Run a report, then press Save this report.</div>
        @else
          <table class="rep-tbl cr-list">
            <thead><tr><th>Name</th><th>Data</th><th>Created by</th><th>Created</th><th>Who can open</th><th></th></tr></thead>
            <tbody>
              @foreach($myReports as $r)
                <tr>
                  <td><a class="nm" href="{{ route('tenant.reports.custom', ['saved' => $r->id]) }}">{{ $r->name }}</a></td>
                  <td>{{ $catalog[$r->dataset]['label'] ?? $r->dataset }}</td>
                  <td>{{ $r->by_name ?? 'Unknown' }}</td>
                  <td>{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('M j, Y') }}</td>
                  <td>{{ $r->visibility === 'team' ? 'Everyone' : 'Only the person who built it' }}</td>
                  <td style="text-align:right;">
                    @if($isManager || $r->user_id === (\Illuminate\Support\Facades\Auth::guard('tenant')->id()))
                      <button class="cr-btn" type="button" onclick="this.style.display='none';document.getElementById('del-{{ $r->id }}').classList.add('on')">Delete</button>
                      <form method="POST" action="{{ route('tenant.reports.custom.delete', $r->id) }}" class="cr-confirm" id="del-{{ $r->id }}">
                        @csrf
                        Delete this report?
                        <button class="cr-btn" type="submit">Yes, delete</button>
                        <button class="cr-btn" type="button" onclick="this.parentNode.classList.remove('on');this.parentNode.previousElementSibling.style.display=''">Cancel</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>

      <div class="cr-legend">
        <b>How this behaves.</b>
        <div class="row"><span>Read-only:</span><span>reports never change anything in your shop.</span></div>
        <div class="row"><span>Whole shop:</span><span>every report covers all your locations unless you filter by location.</span></div>
        <div class="row"><span>Revenue:</span><span>paid sales before tax and tips. Refunds, unpaid sales and quotes are left out.</span></div>
        <div class="row"><span>Margin:</span><span>counted only on lines where a cost was saved at the time of the sale, so lines without a cost don't pull the percentage down.</span></div>
        <div class="row"><span>Staff:</span><span>per-person figures are shown to owners and managers only.</span></div>
        @if($cfg['ds'] === 'dist')
          <div class="row"><span>Distributor:</span><span>only items you carry, from the distributor each came from. Pace comes from the distributor's stock dropping, not from anyone's sales, and restocks are never counted as sales. Another shop's data is never shown.</span></div>
        @endif
        <div class="row"><span>Saved:</span><span>a saved report re-runs on today's data each time you open it. Nothing is emailed or pinned.</span></div>
      </div>
  @else
  <div class="cr-grid">
    {{-- ------------------------------------------------------------- builder --}}
    <form method="GET" action="{{ route('tenant.reports.custom') }}" id="cr-form">
      <input type="hidden" name="go" value="1">

      <div class="cr-sect">
        <div class="cr-lbl">Data</div>
        <select class="cr-sel" name="ds" onchange="document.getElementById('cr-form').submit()">
          @foreach($catalog as $key => $d)
            <option value="{{ $key }}" @selected($cfg['ds'] === $key)>{{ $d['label'] }}</option>
          @endforeach
        </select>
        <div class="cr-hint">{{ $def['note'] }}</div>
        @if(!empty($def['cav']))
          <ul class="cr-hint" style="margin:6px 0 0;padding-left:16px;">
            @foreach($def['cav'] as $line)<li>{{ $line }}</li>@endforeach
          </ul>
        @endif
      </div>

      <div class="cr-sect">
        <div class="cr-lbl"><span>Show</span><span>up to {{ $maxMeasures }}</span></div>
        @foreach($def['measures'] as $key => $m)
          <label class="cr-chk">
            <input type="checkbox" name="m[]" value="{{ $key }}" @checked(in_array($key, $cfg['m'], true))>
            {{ $m['label'] }}
          </label>
        @endforeach
      </div>

      <div class="cr-sect">
        <div class="cr-lbl">Break down by</div>
        <select class="cr-sel" name="dim">
          @foreach($dims as $key => $d)
            <option value="{{ $key }}" @selected($cfg['dim'] === $key)>{{ $d['label'] }}</option>
          @endforeach
        </select>
      </div>

      <div class="cr-sect">
        <div class="cr-lbl">Only include</div>
        @foreach($def['filters'] as $key => $label)
          @if(isset($filterOpts[$key]))
            <div class="cr-fr">
              <span>{{ $label }}</span>
              <select class="cr-sel" name="f[{{ $key }}]">
                <option value="">Any</option>
                @foreach($filterOpts[$key] as $val => $name)
                  <option value="{{ $val }}" @selected(($cfg['f'][$key] ?? '') === (string) $val)>{{ $name }}</option>
                @endforeach
              </select>
            </div>
          @endif
        @endforeach
      </div>

      @if(!$def['snapshot'])
        <div class="cr-sect">
          <div class="cr-lbl">Date range</div>
          <select class="cr-sel" name="range">
            @foreach($ranges as $key => $label)
              <option value="{{ $key }}" @selected($cfg['range'] === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      @else
        <input type="hidden" name="range" value="{{ $cfg['range'] }}">
      @endif

      <div class="cr-sect">
        <div class="cr-lbl">Order and limit</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <select class="cr-sel" name="sort">
            <option value="desc" @selected($cfg['sort'] === 'desc')>Highest first</option>
            <option value="asc" @selected($cfg['sort'] === 'asc')>Lowest first</option>
          </select>
          <select class="cr-sel" name="limit">
            @foreach([0 => 'Show all', 5 => 'Top 5', 10 => 'Top 10', 15 => 'Top 15', 25 => 'Top 25'] as $v => $l)
              <option value="{{ $v }}" @selected($cfg['limit'] === $v)>{{ $l }}</option>
            @endforeach
          </select>
        </div>
        <div class="cr-hint">Time breakdowns stay in date order.</div>
      </div>

      <div style="padding-top:16px;">
        <button class="cr-btn p" type="submit">Run report</button>
      </div>
    </form>

    {{-- ------------------------------------------------------------- results --}}
    <div>
      @if($result)
        <div style="margin-bottom:10px;"><a href="{{ route('tenant.reports.custom') }}" style="font-size:12.5px;color:var(--ia-text-3,#888);text-decoration:none;">&larr; All reports</a></div>
        <div class="cr-bar">
          <div class="cr-title">{{ $saved ? $saved->name : $def['label'] . ' by ' . strtolower($dims[$cfg['dim']]['label']) }}</div>
          <span style="color:var(--ia-text-3,#888);font-size:12.5px;">{{ count($result['rows']) }} rows · {{ $result['range_label'] }}</span>
          <span style="flex:1"></span>
          <a class="cr-btn" href="{{ route('tenant.reports.custom.export', $exportQuery) }}">Export CSV</a>
          <button class="cr-btn" type="button" onclick="document.getElementById('cr-save').style.display='block'">Save this report</button>
        </div>

        <form method="POST" action="{{ route('tenant.reports.custom.save') }}" id="cr-save" style="display:none;margin-bottom:14px;padding:14px 16px;border:1px solid var(--ia-border,#1f1f1f);border-radius:10px;">
          @csrf
          <input type="hidden" name="ds" value="{{ $cfg['ds'] }}">
          @foreach($cfg['m'] as $m)<input type="hidden" name="m[]" value="{{ $m }}">@endforeach
          <input type="hidden" name="dim" value="{{ $cfg['dim'] }}">
          @foreach($cfg['f'] as $fk => $fv)<input type="hidden" name="f[{{ $fk }}]" value="{{ $fv }}">@endforeach
          <input type="hidden" name="range" value="{{ $cfg['range'] }}">
          <input type="hidden" name="sort" value="{{ $cfg['sort'] }}">
          <input type="hidden" name="limit" value="{{ $cfg['limit'] }}">
          <div style="display:grid;grid-template-columns:1fr 200px auto;gap:8px;align-items:end;">
            <div><div class="cr-lbl">Name</div><input class="cr-sel" type="text" name="name" maxlength="120" required value="{{ $def['label'] }} by {{ strtolower($dims[$cfg['dim']]['label']) }}"></div>
            <div><div class="cr-lbl">Who can open it</div>
              <select class="cr-sel" name="visibility"><option value="me">Only me</option><option value="team">Everyone on the team</option></select></div>
            <button class="cr-btn p" type="submit">Save report</button>
          </div>
        </form>

        @if(count($result['rows']) === 0)
          <div class="rep-empty">
            Nothing matches these settings in {{ strtolower($result['range_label']) }}.
            <div class="cr-sugg" style="justify-content:center;">
              @if(!$def['snapshot'] && $cfg['range'] !== 'last_90')
                <a class="cr-btn" href="{{ route('tenant.reports.custom', array_merge($runQuery, ['range' => 'last_90'])) }}">Try the last 90 days</a>
              @endif
              @if($cfg['f'])
                <a class="cr-btn" href="{{ route('tenant.reports.custom', array_merge($runQuery, ['f' => []])) }}">Clear the filters</a>
              @endif
            </div>
          </div>
        @else
          <table class="rep-tbl">
            <thead><tr>
              @foreach($result['columns'] as $col)
                <th class="{{ $col['key'] === 'label' ? '' : 'right' }}">{{ $col['label'] }}</th>
              @endforeach
            </tr></thead>
            <tbody>
              @foreach($result['rows'] as $row)
                <tr>
                  @foreach($result['columns'] as $col)
                    @if($col['key'] === 'label')
                      <td class="rep-cell-name">{{ $row['label'] }}</td>
                    @else
                      <td class="right">{{ $fmt($row[$col['key']], $col['type']) }}</td>
                    @endif
                  @endforeach
                </tr>
              @endforeach
              @if($cfg['limit'] === 0 && !$result['truncated'])
                <tr class="cr-tot">
                  <td>Total</td>
                  @foreach($result['columns'] as $col)
                    @if($col['key'] !== 'label')
                      <td class="right">{{ $fmt($result['totals'][$col['key']] ?? null, $col['type']) }}</td>
                    @endif
                  @endforeach
                </tr>
              @endif
            </tbody>
          </table>
          @if($result['truncated'])
            <div class="cr-hint" style="margin-top:10px;">This report has more than {{ number_format(\App\Services\Tenant\ReportBuilder\ReportCatalog::ROW_CAP) }} rows, so only the first {{ number_format(\App\Services\Tenant\ReportBuilder\ReportCatalog::ROW_CAP) }} are shown and no total is given. Narrow the date range or add a filter.</div>
          @endif
        @endif
      @else
        <div class="rep-empty" style="text-align:left;padding:8px 0 18px;">Pick what to show on the left, then press Run report.</div>
      @endif


      <div class="cr-legend">
        <b>How this behaves.</b>
        <div class="row"><span>Read-only:</span><span>reports never change anything in your shop.</span></div>
        <div class="row"><span>Whole shop:</span><span>every report covers all your locations unless you filter by location.</span></div>
        <div class="row"><span>Revenue:</span><span>paid sales before tax and tips. Refunds, unpaid sales and quotes are left out.</span></div>
        <div class="row"><span>Margin:</span><span>counted only on lines where a cost was saved at the time of the sale, so lines without a cost don't pull the percentage down.</span></div>
        <div class="row"><span>Staff:</span><span>per-person figures are shown to owners and managers only.</span></div>
        @if($cfg['ds'] === 'dist')
          <div class="row"><span>Distributor:</span><span>only items you carry, from the distributor each came from. Pace comes from the distributor's stock dropping, not from anyone's sales, and restocks are never counted as sales. Another shop's data is never shown.</span></div>
        @endif
        <div class="row"><span>Saved:</span><span>a saved report re-runs on today's data each time you open it. Nothing is emailed or pinned.</span></div>
      </div>
    </div>
  </div>
  @endif
</div>
@endsection
