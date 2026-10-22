{{-- MARKER-OUTREACH-RESULTS: Email outreach > Results. Lives inside .sx-root of sales-email-outreach. No cards. --}}
@php
    $res   = $this->results();
    $fn    = $res['funnel'];
    $pct   = fn ($n, $d) => $d > 0 ? round($n / $d * 100) . '%' : '—';
    $wkMax = max(1, collect($res['weeks'])->max('emailed'));
    $chans = \App\Models\SalesChannel::orderBy('name')->get(['id', 'name']);
    $tplsR = \App\Models\SalesEmailTemplate::orderBy('sort')->orderBy('name')->get(['id', 'name']);
    $pipe  = \App\Filament\Pages\SalesPipeline::getUrl();
    $tz    = \App\Filament\Pages\SalesEmailOutreach::TZ;
@endphp
<style>
  .or-pills { display:flex; gap:6px; margin-left:auto; }
  .or-pills button { padding:5px 12px; border-radius:99px; font-size:12.5px; background:rgba(255,255,255,.06); color:inherit; opacity:.6; border:0; cursor:pointer; }
  .or-pills button.on { opacity:1; font-weight:600; box-shadow:inset 0 0 0 1px currentColor; }
  .or-fn { display:flex; align-items:stretch; flex-wrap:wrap; margin-top:18px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
  .or-fs { flex:1 1 0; min-width:110px; padding:14px 14px 14px 0; }
  .or-fs b { display:block; font-size:24px; font-weight:650; color:#fff; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .or-fs span { display:block; font-size:13px; color:var(--sx-dim); }
  .or-fs i { display:block; font-style:normal; font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .or-fs .lime { color:var(--sx-lime); }
  .or-fa { display:flex; align-items:center; color:var(--sx-faint); padding:0 12px 0 0; font-size:13px; }
  .or-cols { display:grid; grid-template-columns:1fr 1fr; gap:32px; }
  .or-cols > section { min-width:0; }
  .or-wk { display:flex; align-items:flex-end; gap:10px; height:150px; padding-top:10px; border-bottom:1px solid var(--sx-line-2); }
  .or-wk > div { flex:1; display:flex; align-items:flex-end; gap:3px; height:100%; }
  .or-wk i { flex:1; display:block; border-radius:3px 3px 0 0; min-height:1px; }
  .or-wk i.s { background:rgba(139,92,246,.45); } .or-wk i.v { background:var(--sx-lime); }
  .or-wx { display:flex; gap:10px; font-size:11px; color:var(--sx-faint); margin-top:6px; }
  .or-wx span { flex:1; text-align:center; }
  .or-key { display:flex; gap:14px; font-size:12px; color:var(--sx-faint); margin-top:8px; }
  .or-key i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:-1px; }
  .or-tag { display:inline-block; font-size:11.5px; border:1px solid var(--sx-line-2); border-radius:4px; padding:0 6px; color:var(--sx-dim); margin-right:4px; }
  .or-tag.hot { border-color:var(--sx-lime); color:var(--sx-lime); }
  .sx-t th.n, .sx-t td.n { text-align:right; font-variant-numeric:tabular-nums; }
  .sx-t .pc { color:var(--sx-faint); font-size:12px; margin-left:4px; }
  .or-empty { padding:28px 0; color:var(--sx-faint); font-size:13px; }
  @media (max-width:860px) { .or-cols { grid-template-columns:1fr; gap:8px; } .or-fa { display:none; } }
</style>

<div class="sx-bar">
    <select class="sx-in" wire:model.live="rIndustry">
        <option value="">All industries</option>
        @foreach ($chans as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
    </select>
    <select class="sx-in" wire:model.live="rTemplate">
        <option value="">All templates</option>
        @foreach ($tplsR as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        <option value="none">Written by hand</option>
    </select>
    <div class="or-pills">
        @foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All time'] as $k => $label)
            <button type="button" class="{{ $rDays === (string) $k ? 'on' : '' }}" wire:click="$set('rDays', '{{ $k }}')">{{ $label }}</button>
        @endforeach
    </div>
</div>

@if ($fn['emailed'] === 0)
    <div class="or-empty">No emails sent from Email outreach or a Prospects card in this window{{ $rIndustry !== '' || $rTemplate !== '' ? ' with these filters' : '' }}.</div>
@else
<div class="or-fn">
    <div class="or-fs"><b>{{ number_format($fn['emailed']) }}</b><span>emailed</span><i>shops</i></div><div class="or-fa">›</div>
    <div class="or-fs"><b>{{ number_format($fn['opened']) }}</b><span>opened</span><i>{{ $pct($fn['opened'], $fn['emailed']) }} · runs high</i></div><div class="or-fa">›</div>
    <div class="or-fs"><b class="lime">{{ number_format($fn['visited']) }}</b><span>visited the site</span><i>{{ $pct($fn['visited'], $fn['emailed']) }} of emailed</i></div><div class="or-fa">›</div>
    <div class="or-fs"><b>{{ number_format($fn['replied']) }}</b><span>replied</span><i>{{ $pct($fn['replied'], $fn['emailed']) }}</i></div><div class="or-fa">›</div>
    <div class="or-fs"><b>{{ number_format($fn['demo']) }}</b><span>demo or trial</span><i>{{ $pct($fn['demo'], $fn['emailed']) }}</i></div><div class="or-fa">›</div>
    <div class="or-fs"><b>{{ number_format($fn['won']) }}</b><span>won</span><i>{{ $pct($fn['won'], $fn['emailed']) }}</i></div>
</div>
@endif

<p class="sx-lede"><b>How these are counted:</b> shops, not emails, from emails sent here or from a Prospects card; each shop is judged from its first email in the window. Opened comes from the email provider and runs high, because Apple Mail opens every email to preload it, so trust Visited more. Visited means they opened their own link after the email (link scanners don't count, and neither do you when signed in). Replied is an answer that came back into the Platform inbox. Demo or trial and Won are the shop reaching that stage after the email. <b>Not counted:</b> bulk campaigns from Platform email.</p>

<div class="or-cols">
    <section>
        <h2 class="ig-h2">Each week <span style="font-weight:400;color:var(--sx-faint)">· last 8 weeks</span></h2>
        <div class="or-wk">
            @foreach ($res['weeks'] as $w)
                <div><i class="s" style="height:{{ round($w['emailed'] / $wkMax * 100) }}%" title="{{ $w['emailed'] }} emailed"></i><i class="v" style="height:{{ round($w['visited'] / $wkMax * 100) }}%" title="{{ $w['visited'] }} visited"></i></div>
            @endforeach
        </div>
        <div class="or-wx">@foreach ($res['weeks'] as $w)<span>{{ $w['label'] }}</span>@endforeach</div>
        <div class="or-key"><span><i style="background:rgba(139,92,246,.45)"></i>emailed</span><span><i style="background:var(--sx-lime)"></i>visited</span></div>
    </section>
    <section>
        <h2 class="ig-h2">Follow up next <span style="font-weight:400;color:var(--sx-faint)">· engaged since their last email, no reply yet</span></h2>
        <table class="sx-t">
            <tbody>
            @forelse ($res['followUp'] as $f)
                <tr wire:key="fu-{{ $f['id'] }}">
                    <td>{{ $f['shop'] }}<div class="sx-sub"><span class="or-tag {{ $f['kind'] === 'visited' ? 'hot' : '' }}">{{ $f['kind'] === 'visited' ? 'Visited' : 'Opened' }}</span>{{ $f['kind'] === 'visited' ? $f['what'] . ' · ' : '' }}{{ $f['when']->diffForHumans() }}{{ $f['place'] ? ' · ' . $f['place'] : '' }}</div></td>
                    <td class="n"><a class="sx-btn sm" style="text-decoration:none" href="{{ $pipe }}?open={{ $f['id'] }}">Open</a></td>
                </tr>
            @empty
                <tr><td class="sx-sub">Nobody waiting. Shops that open or visit without replying show up here.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="sx-sub" style="font-size:12px;color:var(--sx-faint);margin-top:8px">Open goes to the shop's card in Prospects, where Email uses the same templates.</div>
    </section>
</div>

@if ($fn['emailed'] > 0)
<h2 class="ig-h2">By template</h2>
<table class="sx-t">
    <thead><tr><th>Template</th><th class="n">Emailed</th><th class="n">Opened</th><th class="n">Visited</th><th class="n">Replied</th><th class="n">Demo / trial</th></tr></thead>
    <tbody>
    @foreach ($res['byTemplate'] as $r)
        <tr><td>{{ $r['label'] }}</td><td class="n">{{ number_format($r['emailed']) }}</td><td class="n">{{ $r['opened'] }}<span class="pc">{{ $pct($r['opened'], $r['emailed']) }}</span></td><td class="n">{{ $r['visited'] }}<span class="pc">{{ $pct($r['visited'], $r['emailed']) }}</span></td><td class="n">{{ $r['replied'] }}<span class="pc">{{ $pct($r['replied'], $r['emailed']) }}</span></td><td class="n">{{ $r['demo'] }}</td></tr>
    @endforeach
    </tbody>
</table>

<div class="or-cols">
    <section>
        <h2 class="ig-h2">By industry</h2>
        <table class="sx-t">
            <thead><tr><th>Industry</th><th class="n">Emailed</th><th class="n">Visited</th><th class="n">Replied</th></tr></thead>
            <tbody>
            @foreach ($res['byIndustry'] as $r)
                <tr><td>{{ $r['label'] }}</td><td class="n">{{ number_format($r['emailed']) }}</td><td class="n">{{ $r['visited'] }}<span class="pc">{{ $pct($r['visited'], $r['emailed']) }}</span></td><td class="n">{{ $r['replied'] }}<span class="pc">{{ $pct($r['replied'], $r['emailed']) }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
    <section>
        <h2 class="ig-h2">By landing page</h2>
        <table class="sx-t">
            <thead><tr><th>Their link opened</th><th class="n">Emailed</th><th class="n">Visited</th><th class="n">Pages each</th></tr></thead>
            <tbody>
            @foreach ($res['byPage'] as $r)
                <tr><td>{{ $r['label'] }}<div class="sx-sub">{{ $r['key'] }}</div></td><td class="n">{{ number_format($r['emailed']) }}</td><td class="n">{{ $r['visited'] }}<span class="pc">{{ $pct($r['visited'], $r['emailed']) }}</span></td><td class="n">{{ $r['visited'] ? $r['pages_each'] : '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>
@endif
