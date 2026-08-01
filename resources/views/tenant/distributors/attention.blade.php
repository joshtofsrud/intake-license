@extends('layouts.tenant.app')
@php $pageTitle = 'Catalog Attention'; @endphp

{{-- MARKER-PATCH-HLC7C --}}

@push('styles')
<style>
.at-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px;margin-bottom:18px}
.at-chips{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.at-chip{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:11px 16px;min-width:120px}
.at-chip .v{font-size:22px;font-weight:700;font-family:var(--ia-mono)}
.at-chip .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-top:2px}
.at-tbl{width:100%;border-collapse:collapse;font-size:13px}
.at-tbl th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--ia-border)}
.at-tbl td{padding:11px 10px;border-bottom:.5px solid var(--ia-border);vertical-align:middle}
.at-mono{font-family:var(--ia-mono)}
.at-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
/* MARKER-PATCH-558 */
.at-sync{display:flex;align-items:stretch;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:14px;margin-bottom:20px;overflow:hidden}
.at-sync-stat{padding:16px 20px;border-right:0.5px solid var(--ia-border);flex:1;min-width:0}
.at-sync-stat .k{font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600}
.at-sync-stat .v{font-size:15px;font-weight:700;margin-top:5px}
.at-sync-stat .d{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
.at-sync-act{padding:14px 18px;display:flex;flex-direction:column;justify-content:center;gap:8px;flex:none;min-width:230px}
.at-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;vertical-align:1px}
.at-dot.ok{background:#8FD14F}.at-dot.bad{background:#F26D6D}.at-dot.run{background:var(--ia-accent)}.at-dot.idle{background:var(--ia-text-muted)}
.at-chg{font-size:12.5px;line-height:1.6;max-width:430px}
.at-chg .old{color:var(--ia-text-muted);text-decoration:line-through;text-decoration-color:rgba(242,109,109,.55)}
.at-hl{background:rgba(205,233,138,.16);border-radius:3px;padding:0 2px;color:#cde98a}
.at-chg .nb{color:#F26D6D;font-weight:700}
.at-chg .when{font-size:11px;color:var(--ia-text-muted)}
.at-rowact{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.at-rowact .at-btn{padding:5px 11px;font-size:12px}
.at-b-map{background:rgba(226,75,74,.16);color:#f0a3a3}
.at-b-msrp{background:rgba(239,159,39,.16);color:#f0c78a}
.at-b-van{background:rgba(120,140,170,.16);color:#aebbcf}
.at-b-title{background:rgba(190,242,100,.15);color:#cde98a}
.at-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.at-sel{padding:7px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.at-seg{display:inline-flex;border:1px solid var(--ia-border-strong);border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:14px}
.at-segbtn{padding:8px 16px;font-size:13px;font-weight:600;color:var(--ia-text-dim);text-decoration:none;border-right:1px solid var(--ia-border-strong)}
.at-segbtn:last-child{border-right:0}
.at-segbtn.active{background:var(--ia-accent);color:var(--ia-accent-text)}
.at-bar{position:sticky;bottom:0;background:var(--ia-surface);border-top:1px solid var(--ia-border);padding:14px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.at-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.at-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.at-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.at-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.at-empty{text-align:center;padding:48px 20px;color:var(--ia-text-dim)}
.at-empty .big{font-size:34px;margin-bottom:8px}
.at-dim{color:var(--ia-text-dim)}
.at-toggle{font-size:12px;color:var(--ia-text-dim)}
.at-toggle a{color:var(--ia-accent);text-decoration:none}
</style>
@endpush

@section('content')
@php
  $fmt = fn($c) => $c !== null ? '$' . number_format($c/100, 2) : '—';
  // MARKER-PATCH-558 — plain speech, not jargon
  $badge = function($r){
    return match($r){
      'below_map'     => ['at-b-map','Below MAP'],
      'off_msrp'      => ['at-b-msrp','Off MSRP'],
      'title_changed' => ['at-b-title','Renamed by distributor'],
      'cost_vanished' => ['at-b-van','Cost removed'],
      'map_vanished'  => ['at-b-van','MAP removed'],
      'msrp_vanished' => ['at-b-van','MSRP removed'],
      default         => ['at-b-van', str_replace('_',' ', $r)],
    };
  };
  // MARKER-PATCH-558 — highlight words in $new that aren't in $old
  $wordDiff = function(?string $old, ?string $new): string {
    if (blank($new)) return '—';
    $oldWords = array_map('mb_strtolower', preg_split('/\s+/', (string) $old) ?: []);
    return collect(preg_split('/\s+/', $new))
        ->map(fn ($w) => in_array(mb_strtolower($w), $oldWords, true)
            ? e($w) : '<span class="at-hl">' . e($w) . '</span>')
        ->implode(' ');
  };
@endphp
<div style="max-width:980px">
  {{-- MARKER-ATTENTION-PER-DIST — this queue spans every connected
       distributor, so it can't be titled after one of them. --}}
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Distributor catalogs</h1>
  @include('layouts.tenant._inventory-tabs')

  {{-- MARKER-PATCH-558 — sync status card (supersedes the 555 button row):
     plain language for shop owners, not developers. --}}
  @php
    $srStats = $lastSyncRun ? (json_decode($lastSyncRun->stats ?? '[]', true) ?: []) : [];
    $srRunning = $lastSyncRun && ! $lastSyncRun->finished_at;
  @endphp
  <div class="at-sync">
    <div class="at-sync-stat">
      <div class="k">Catalog sync</div>
      <div class="v">
        @if(!$lastSyncRun) <span class="at-dot idle"></span>Never run
        @elseif($srRunning) <span class="at-dot run"></span>Checking now…
        @elseif($lastSyncRun->error) <span class="at-dot bad"></span>Last check failed
        @else <span class="at-dot ok"></span>Healthy @endif
      </div>
      <div class="d">Checks your connected distributors nightly at 5:00 am</div>
    </div>
    <div class="at-sync-stat">
      <div class="k">Last checked</div>
      <div class="v">{{ $lastSyncRun ? tlocal($lastSyncRun->started_at, 'M j, g:i a') : '—' }}</div>
      <div class="d">
        @if($lastSyncRun && $lastSyncRun->error)
          <span style="color:#f0a3a3">{{ \Illuminate\Support\Str::limit($lastSyncRun->error, 60) }}</span>
        @elseif(!empty($srStats))
          {{ number_format($srStats['linked'] ?? 0) }} items ·
          {{ number_format($srStats['cost_updated'] ?? 0) }} costs updated{{ !empty($lastSyncRun->dry_run) ? ' · preview only' : '' }}
        @else
          run a check to see where things stand
        @endif
      </div>
    </div>
    <div class="at-sync-stat">
      <div class="k">Needs review</div>
      <div class="v" style="color:{{ ($counts['total'] ?? 0) > 0 ? 'var(--ia-accent)' : 'inherit' }}">
        {{ ($counts['total'] ?? 0) === 1 ? '1 item' : ($counts['total'] ?? 0) . ' items' }}
      </div>
      <div class="d">{{ ($counts['total'] ?? 0) > 0 ? 'changes waiting for your call below' : 'nothing waiting on you' }}</div>
    </div>
    <div class="at-sync-act">
      <form method="POST" action="{{ route('tenant.distributors.attention.sync') }}">@csrf
        <button class="ia-btn ia-btn--primary" style="width:100%">Check for changes now</button>
      </form>
      <form method="POST" action="{{ route('tenant.distributors.attention.sync') }}">@csrf
        <input type="hidden" name="mode" value="dry">
        <button class="ia-btn ia-btn--ghost" style="width:100%">Preview only (saves nothing)</button>
      </form>
    </div>
  </div>

  <div class="at-chips">
    <div class="at-chip"><div class="v">{{ $counts['total'] }}</div><div class="k">Open</div></div>
    <div class="at-chip"><div class="v" style="color:#cde98a">{{ $counts['title'] ?? 0 }}</div><div class="k">Titles</div></div>
    <div class="at-chip"><div class="v" style="color:#f0a3a3">{{ $counts['below_map'] }}</div><div class="k">Below MAP</div></div>
    <div class="at-chip"><div class="v" style="color:#f0c78a">{{ $counts['off_msrp'] }}</div><div class="k">Off MSRP</div></div>
    <div class="at-chip"><div class="v" style="color:#aebbcf">{{ $counts['vanished'] }}</div><div class="k">Vanished</div></div>
  </div>

  <form method="GET" action="{{ route('tenant.distributors.attention') }}" class="at-filter">
    @if($stock !== 'all')<input type="hidden" name="stock" value="{{ $stock }}">@endif
    <select name="brand" class="at-sel">
      <option value="">All brands</option>
      @foreach(($brandOptions ?? []) as $b)<option value="{{ $b }}" @selected(($filters['brand'] ?? null)===$b)>{{ $b }}</option>@endforeach
    </select>
    <select name="category" class="at-sel">
      <option value="">All categories</option>
      @foreach(($categoryOptions ?? []) as $c)<option value="{{ $c }}" @selected(($filters['category'] ?? null)===$c)>{{ $c }}</option>@endforeach
    </select>
    <select name="reason" class="at-sel">
      <option value="">All reasons</option>
      <option value="title_changed" @selected(($filters['reason'] ?? null)==='title_changed')>Title changed</option>
      <option value="below_map" @selected(($filters['reason'] ?? null)==='below_map')>Below MAP</option>
      <option value="off_msrp" @selected(($filters['reason'] ?? null)==='off_msrp')>Off MSRP</option>
      <option value="cost_vanished" @selected(($filters['reason'] ?? null)==='cost_vanished')>Cost removed</option>
      <option value="map_vanished" @selected(($filters['reason'] ?? null)==='map_vanished')>MAP removed</option>
      <option value="msrp_vanished" @selected(($filters['reason'] ?? null)==='msrp_vanished')>MSRP removed</option>
      <option value="cost_vanished" @selected(($filters['reason'] ?? null)==='cost_vanished')>Cost removed</option>
      <option value="map_vanished" @selected(($filters['reason'] ?? null)==='map_vanished')>MAP removed</option>
      <option value="msrp_vanished" @selected(($filters['reason'] ?? null)==='msrp_vanished')>MSRP removed</option>
    </select>
    <button class="at-btn primary" type="submit">Filter</button>
    @if(($filters['brand'] ?? null) || ($filters['category'] ?? null) || ($filters['reason'] ?? null))
      <a class="at-btn" href="{{ route('tenant.distributors.attention', $stock !== 'all' ? ['stock' => $stock] : []) }}">Clear</a>
    @endif
  </form>

  @php
    $segLink = fn ($s) => route('tenant.distributors.attention', array_filter([
        'stock'    => $s === 'all' ? null : $s,
        'brand'    => $filters['brand'] ?? null,
        'category' => $filters['category'] ?? null,
        'reason'   => $filters['reason'] ?? null,
    ]));
  @endphp
  <div class="at-seg">
    <a class="at-segbtn {{ $stock === 'all' ? 'active' : '' }}" href="{{ $segLink('all') }}">All ({{ $counts['total'] }})</a>
    <a class="at-segbtn {{ $stock === 'in' ? 'active' : '' }}" href="{{ $segLink('in') }}">In stock ({{ $counts['in'] ?? 0 }})</a>
    <a class="at-segbtn {{ $stock === 'out' ? 'active' : '' }}" href="{{ $segLink('out') }}">Out of stock ({{ $counts['out'] ?? 0 }})</a>
  </div>

  @if($flags->isEmpty())
    <div class="at-card"><div class="at-empty"><div class="big">✓</div>All clear — no pricing attention needed right now.</div></div>
  @else
    <form method="POST" action="{{ route('tenant.distributors.attention.resolve') }}">
      @csrf
      <input type="hidden" name="action" id="at-action" value="">
      <input type="hidden" name="f_brand" value="{{ $filters['brand'] ?? '' }}">
      <input type="hidden" name="f_category" value="{{ $filters['category'] ?? '' }}">
      <input type="hidden" name="f_reason" value="{{ $filters['reason'] ?? '' }}">
      <input type="hidden" name="f_stock" value="{{ $stock }}">
      <script>function setAct(a){document.getElementById('at-action').value=a;}</script>
      <div class="at-card" style="padding:6px 14px">
        <table class="at-tbl">
          <thead><tr>
            <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.at-cb').forEach(c=>c.checked=this.checked)"></th>
            <th style="width:24%">Item</th><th style="width:13%">What happened</th><th>The change</th><th style="text-align:right">Act on it</th>
          </tr></thead>
          <tbody>
          {{-- MARKER-PATCH-558 — every row states the change itself --}}
          @foreach($flags as $f)
            @php
              [$bc, $bl] = $badge($f->reason);
              $item = $f->item; $d = $f->detail ?? []; $cat = $item?->distributorCatalog;
              // MARKER-ATTENTION-PER-DIST — which distributor raised this one.
              $flagDist = $cat?->distributor_code;
              $sell = $item->shop_sell_price_cents ?? null;
            @endphp
            <tr>
              <td><input class="at-cb" type="checkbox" name="flag_ids[]" value="{{ $f->id }}"></td>
              <td>
                <div style="font-weight:600">{{ $item->name ?? '—' }}</div>
                {{-- MARKER-BLADE-WORD-BOUNDARY — the directive must not touch the
                     word before it. Blade's statement regex starts with \B@, so
                     a directive glued to the preceding word is left as literal
                     text while the matching
                     @endif compiled, giving an endif with no if and a fatal
                     compile error for the whole view. --}}
                <div class="at-dim at-mono" style="font-size:11px">
                  {{ $item->sku ?? '' }} · {{ $item->computed_stock_count ?? 0 }} in stock
                  @if($flagDist)
                    · <span style="font-weight:700">{{ $flagDist }}</span>
                  @endif
                </div>
              </td>
              <td><span class="at-badge {{ $bc }}">{{ $bl }}</span></td>
              <td class="at-chg">
                @if($f->reason === 'title_changed')
                  <span class="old">{{ $d['old'] ?? $item->name }}</span> →<br>
                  {!! $wordDiff($d['old'] ?? $item->name, $d['new'] ?? $cat?->display_name) !!}
                  <div class="when">your item still uses the name on the left</div>
                @elseif($f->reason === 'below_map')
                  Your price <span class="nb">{{ $fmt($d['sell_cents'] ?? $sell) }}</span> is
                  <span class="nb">{{ $fmt($d['delta_cents'] ?? null) }} under</span> the
                  {{ $fmt($d['map_cents'] ?? $item->catalog_map_cents) }} minimum advertised price.
                  @if(($d['prev_map_cents'] ?? null) && ($d['prev_map_cents'] !== ($d['map_cents'] ?? null)))
                    <div class="when">MAP moved from {{ $fmt($d['prev_map_cents']) }} in a recent catalog</div>
                  @endif
                @elseif($f->reason === 'off_msrp')
                  @if(($d['prev_msrp_cents'] ?? null) && ($d['prev_msrp_cents'] !== ($d['msrp_cents'] ?? null)))
                    MSRP moved <span class="old">{{ $fmt($d['prev_msrp_cents']) }}</span> →
                    <b>{{ $fmt($d['msrp_cents'] ?? $item->catalog_msrp_cents) }}</b>; your price is still <b>{{ $fmt($d['sell_cents'] ?? $sell) }}</b>
                  @else
                    Your <b>{{ $fmt($d['sell_cents'] ?? $sell) }}</b> is
                    <b>{{ $d['pct_under'] ?? '?' }}% under</b> the {{ $fmt($d['msrp_cents'] ?? $item->catalog_msrp_cents) }} MSRP
                  @endif
                  <div class="when">you may be leaving margin on the table</div>
                @elseif(str_ends_with($f->reason, '_vanished'))
                  @php $what = ['cost_vanished' => 'a dealer cost', 'map_vanished' => 'a MAP', 'msrp_vanished' => 'an MSRP'][$f->reason] ?? 'this data'; @endphp
                  {{ $cat?->distributor_code ?: 'The distributor' }} no longer publishes {{ $what }} for this item
                  @if($d['prev_cost_cents'] ?? $d['prev_map_cents'] ?? $d['prev_msrp_cents'] ?? null)
                    — it was <b>{{ $fmt($d['prev_cost_cents'] ?? $d['prev_map_cents'] ?? $d['prev_msrp_cents']) }}</b>
                  @endif.
                  <div class="when">often means discontinued or a supplier change</div>
                @else
                  <span class="at-dim">flag opened {{ tlocal($f->created_at, 'M j') }}</span>
                @endif
              </td>
              <td>
                <div class="at-rowact">
                  @if($f->reason === 'title_changed')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('adopt_title')">Use new name</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('keep_title')">Keep mine</button>
                  @elseif($f->reason === 'below_map')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('raise_map')">Raise to {{ $fmt($d['map_cents'] ?? $item->catalog_map_cents) }}</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('acknowledge')">Dismiss</button>
                  @elseif($f->reason === 'off_msrp')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('match_msrp')">Match {{ $fmt($d['msrp_cents'] ?? $item->catalog_msrp_cents) }}</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('acknowledge')">Dismiss</button>
                  @else
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('acknowledge')">Dismiss</button>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>

      <div class="at-bar">
        <span class="at-dim" style="font-size:12px">With selected:</span>
        <button class="at-btn primary" type="submit" onclick="setAct('adopt_title')">Adopt new title</button>
        <button class="at-btn" type="submit" onclick="setAct('keep_title')">Keep mine</button>
        <span class="at-dim" style="opacity:.4">|</span>
        <button class="at-btn primary" type="submit" onclick="setAct('raise_map')">Raise to MAP</button>
        <button class="at-btn" type="submit" onclick="setAct('match_msrp')">Match MSRP</button>
        <button class="at-btn" type="submit" onclick="setAct('acknowledge')">Dismiss</button>
        <label class="at-dim" style="font-size:12px;margin-left:auto;cursor:pointer">
          <input type="checkbox" name="select_all" value="1"> apply to all {{ $flags->count() }} matching the filter
        </label>
      </div>
    </form>
  @endif
</div>
@endsection
