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
  .or-pills > button { padding:5px 12px; border-radius:99px; font-size:12.5px; background:rgba(255,255,255,.06); color:inherit; opacity:.6; border:0; cursor:pointer; }
  .or-pills > button.on { opacity:1; font-weight:600; box-shadow:inset 0 0 0 1px currentColor; }
  /* the calendar pill reads as one of the pills; its popover keeps its own look */
  .or-pills .adr-trigger.or-cal { border:0; border-radius:99px; padding:5px 12px; font-size:12.5px; background:rgba(255,255,255,.06); }
  .or-pills .adr-trigger.or-cal.on { color:inherit; font-weight:600; box-shadow:inset 0 0 0 1px currentColor; }
  .or-pills .adr-pop { left:auto; right:0; }
  .or-fn { display:flex; align-items:stretch; flex-wrap:wrap; margin-top:18px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
  .or-fs { flex:1 1 0; min-width:110px; padding:14px 14px 14px 10px; border:0; border-bottom:2px solid transparent; background:none; color:inherit; text-align:left; cursor:pointer; font:inherit; }
  .or-fs:hover { background:rgba(255,255,255,.03); }
  .or-fs.on { border-bottom-color:var(--sx-violet); background:rgba(139,92,246,.08); }
  .or-drill-h { display:flex; align-items:center; gap:10px; padding:12px 0 6px; font-size:13px; font-weight:600; color:var(--sx-dim); }
  .or-drill-h button { margin-left:auto; background:none; border:0; color:var(--sx-faint); cursor:pointer; font-size:12.5px; }
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
  /* MARKER-OUTREACH-SEND-DETAIL */
  .or-howrow { margin:10px 0 4px; }
  .or-sends { border-top:1px solid var(--sx-line); }
  .or-send { border-bottom:1px solid var(--sx-line); }
  .or-send.on { background:rgba(255,255,255,.02); }
  .or-send-h { display:flex; gap:16px; align-items:flex-start; width:100%; padding:10px 8px; background:none; border:0; color:inherit; font:inherit; text-align:left; cursor:pointer; }
  .or-send-h:hover { background:rgba(255,255,255,.03); }
  .or-send-when { width:170px; flex-shrink:0; }
  .or-send-when .sx-sub { display:block; }
  .or-send-h .sx-sub { color:var(--sx-faint); font-size:12.5px; }
  .or-send-sum > .sx-sub:last-child { display:block; margin-top:2px; }
  .or-send-sum { flex:1; min-width:0; }
  .or-send-chev { color:var(--sx-faint); width:14px; flex-shrink:0; }
  .or-send-b { padding:4px 8px 16px 194px; }
  .or-send-counts { display:flex; gap:6px; flex-wrap:wrap; margin:6px 0 8px; }
  .or-suggest { border-left:2px solid var(--sx-violet); padding:4px 0 4px 12px; margin:4px 0 12px; }
  .or-suggest-h { font-size:12.5px; font-weight:600; color:var(--sx-dim); margin-bottom:4px; }
  .or-suggest-i { font-size:13px; line-height:1.5; margin:4px 0; }
  @media (max-width:860px) { .or-send-b { padding-left:8px; } .or-send-when { width:120px; } }
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
        @foreach (['today' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All time'] as $k => $label)
            <button type="button" class="{{ $rDays === (string) $k ? 'on' : '' }}" wire:click="$set('rDays', '{{ $k }}')">{{ $label }}</button>
        @endforeach
        {{-- MARKER-OUTREACH-RANGE: the single-calendar picker, any range --}}
        <x-admin.date-range wire="setRange" placeholder="" title="Pick dates" trigger-class="or-cal {{ $rDays === 'custom' ? 'on' : '' }}"
            :from-value="$rDays === 'custom' ? ($rFrom ?? '') : ''" :to-value="$rDays === 'custom' ? ($rTo ?? '') : ''" />
    </div>
</div>

@if ($fn['emailed'] === 0)
    <div class="or-empty">No emails sent from Email outreach or a Prospects card in this window{{ $rIndustry !== '' || $rTemplate !== '' ? ' with these filters' : '' }}.</div>
@else
{{-- MARKER-OUTREACH-DRILL: every number opens the shops behind it --}}
<div class="or-fn">
    @foreach ([
        ['emailed', 'emailed', 'shops', ''],
        ['opened', 'opened', $pct($fn['opened'], $fn['emailed']) . ' · runs high', ''],
        ['visited', 'visited the site', $pct($fn['visited'], $fn['emailed']) . ' of emailed', 'lime'],
        ['replied', 'replied', $pct($fn['replied'], $fn['emailed']), ''],
        ['demo', 'demo or trial', $pct($fn['demo'], $fn['emailed']), ''],
        ['won', 'won', $pct($fn['won'], $fn['emailed']), ''],
    ] as $i => $fr)
        @php [$k, $label, $sub, $cls] = $fr; @endphp
        @if ($i > 0)<div class="or-fa">›</div>@endif
        <button type="button" class="or-fs {{ $drill === $k ? 'on' : '' }}" wire:click="toggleDrill('{{ $k }}')" title="Show these shops"><b class="{{ $cls }}">{{ number_format($fn[$k]) }}</b><span>{{ $label }}</span><i>{{ $sub }}</i></button>
    @endforeach
</div>
@if ($drill && isset($res['lists'][$drill]))
    @php
        $dl = $res['lists'][$drill];
        $dlNames = ['emailed' => 'Emailed', 'opened' => 'Opened', 'visited' => 'Visited the site', 'replied' => 'Replied', 'demo' => 'Demo or trial', 'won' => 'Won'];
        $inboxUrl = \App\Filament\Pages\PlatformInbox::getUrl();
    @endphp
    <div class="or-drill">
        <div class="or-drill-h">{{ $dlNames[$drill] }} · {{ count($dl) }} {{ count($dl) === 1 ? 'shop' : 'shops' }}<button type="button" wire:click="toggleDrill('{{ $drill }}')">Close ×</button></div>
        <table class="sx-t">
            <tbody>
            @forelse (array_slice($dl, 0, 50) as $r)
                <tr wire:key="dl-{{ $drill }}-{{ $r['id'] }}">
                    <td>{{ $r['shop'] }}<div class="sx-sub">{{ $r['what'] }} · {{ $r['when']->setTimezone($tz)->format('M j, g:i a') }}</div></td>
                    <td class="n" style="white-space:nowrap">
                        @if (! empty($r['thread']))<a class="sx-btn sm" style="text-decoration:none" href="{{ $inboxUrl }}?tab=messages&show=all&id={{ $r['thread'] }}">Open thread</a>@endif
                        <a class="sx-btn sm" style="text-decoration:none" href="{{ $pipe }}?open={{ $r['id'] }}">Open in Prospects</a>
                    </td>
                </tr>
            @empty
                <tr><td class="sx-sub">No shops here in this window.</td></tr>
            @endforelse
            @if (count($dl) > 50)<tr><td class="sx-sub" colspan="2">+ {{ count($dl) - 50 }} more</td></tr>@endif
            </tbody>
        </table>
        @if ($drill === 'replied' && count($res['lists']['auto']))
            <div class="sx-sub" style="margin-top:8px"><span class="or-tag" style="border-color:rgba(251,191,36,.5);color:#fbbf24">not counted</span>Only an automatic reply came back from: {{ implode(', ', array_slice($res['lists']['auto'], 0, 8)) }}{{ count($res['lists']['auto']) > 8 ? '…' : '' }}</div>
        @endif
    </div>
@endif
@endif

<div class="or-howrow">@include('filament.partials._or-info', ['label' => 'How these are counted', 'body' => "Shops, not emails, from emails sent here or from a Prospects card; each shop is judged from its first email in the window. Opened comes from the email provider and runs high, because Apple Mail opens every email to preload it, so trust Visited more. Visited means a person at the shop opened their own link after the email: mail filters that check the link don't count (see After each send below), and neither do you when signed in. Replied is a real answer that came back into the Platform inbox; help-desk receipts and out-of-office replies don't count. Click any number to see the shops behind it. Demo or trial and Won are the shop reaching that stage after the email. <b>Not counted:</b> bulk campaigns from Platform email."])</div>

{{-- MARKER-OUTREACH-SCANNERS: who actually looked after each batch, and how many filter checks there were --}}
{{-- MARKER-OUTREACH-SEND-DETAIL: every row opens: shops and where they stand, filter checks, suggestions --}}
@php $after = $this->afterSends(); $detail = $this->sendDetail(); @endphp
@if ($after)
<h2 class="ig-h2" style="display:flex;align-items:center;gap:8px">After each send @include('filament.partials._or-info', ['label' => null, 'body' => "Business email filters open every link in a hidden browser right after delivery, so each send is followed by a burst of <b>filter checks</b>. Only <b>people</b> count as Visited, appear on a shop's timeline and show in Marketing traffic.<br><br>A <b>person</b> moved the mouse, scrolled, tapped or typed; came from a home or phone connection; was on a phone; or stayed 30 seconds or more. A <b>filter check</b> came from a mail filter's or data centre's network (Microsoft, Proofpoint, Mimecast, Google and others); opened the link several times in the same few seconds; came and went within two minutes of the email; or quickly re-checked a link a filter had already opened. Too brief to tell is <b>unclear</b> and isn't counted either. Visits before Oct 28, 2026 were judged on timing alone.<br><br>Sends less than 45 minutes apart are one batch. Click a batch to see every shop in it and where it stands."])</h2>
<div class="or-sends">
    @foreach ($after as $b)
        @php $isOpen = $openSend === $b['key']; @endphp
        <div class="or-send {{ $isOpen ? 'on' : '' }}" wire:key="as-{{ $b['key'] }}">
            <button type="button" class="or-send-h" wire:click="toggleSend('{{ $b['key'] }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                <span class="or-send-when">{{ $b['at']->setTimezone($tz)->format('D M j, g:i a') }}<span class="sx-sub">{{ $b['sent'] }} {{ $b['sent'] === 1 ? 'shop' : 'shops' }} emailed</span></span>
                <span class="or-send-sum">
                    <span class="or-tag {{ $b['peopleShops'] ? 'hot' : '' }}">{{ $b['peopleShops'] }} {{ $b['peopleShops'] === 1 ? 'person' : 'people' }}</span>
                    @if ($b['people'])<span class="sx-sub" style="display:inline">{{ collect($b['people'])->pluck('shop')->unique()->take(3)->implode(', ') }}{{ collect($b['people'])->pluck('shop')->unique()->count() > 3 ? '…' : '' }}</span>@endif
                    <span class="sx-sub">{{ $b['scanners'] === 0 ? 'No filter checks' : $b['scanners'] . ' filter ' . ($b['scanners'] === 1 ? 'check' : 'checks') . ' at ' . $b['scannerShops'] . ' ' . ($b['scannerShops'] === 1 ? 'shop' : 'shops') . ', not counted' . ($b['scanWhy'] ? ' (' . collect($b['scanWhy'])->map(fn ($n, $k) => $k . ' ' . $n)->implode(' · ') . ')' : '') }}{{ $b['unsure'] ? ' · ' . $b['unsure'] . ' unclear' : '' }}</span>
                </span>
                <span class="or-send-chev" wire:loading.remove wire:target="toggleSend('{{ $b['key'] }}')">{{ $isOpen ? '▾' : '▸' }}</span>
                <span class="or-send-chev" wire:loading wire:target="toggleSend('{{ $b['key'] }}')">…</span>
            </button>
            @if ($isOpen)
                <div class="or-send-b">
                    @if (! $detail)
                        <div class="sx-sub">These sends are no longer in the filters above. Change the filters or pick another batch.</div>
                    @else
                        @php
                            $c = $detail['count'];
                            $stateNames = ['replied' => 'Replied', 'looked' => 'Looked', 'opened' => 'Opened, no click', 'filter' => 'Filter check only', 'nothing' => 'Nothing yet'];
                        @endphp
                        @if ($detail['suggest'])
                            <div class="or-suggest">
                                <div class="or-suggest-h">Nobody looked yet. What to try</div>
                                @foreach ($detail['suggest'] as $sg)
                                    <div class="or-suggest-i">{{ $sg['text'] }}@if ($sg['kind'] === 'followup') <button type="button" class="sx-btn sm" wire:click="toggleDrill('opened')" style="margin-left:6px">Show opened</button>@endif</div>
                                @endforeach
                            </div>
                        @endif
                        <div class="or-send-counts">
                            @foreach ($stateNames as $k => $nm)
                                <span class="or-tag {{ in_array($k, ['replied', 'looked'], true) && $c[$k] ? 'hot' : '' }}">{{ $nm }} {{ $c[$k] }}</span>
                            @endforeach
                        </div>
                        <div x-data="{ all: false }">
                            <table class="sx-t">
                                <tbody>
                                @foreach ($detail['shops'] as $i => $sh)
                                    <tr wire:key="sd-{{ $b['key'] }}-{{ $sh['id'] }}" @if ($i >= 20) x-show="all" x-cloak @endif>
                                        <td>{{ $sh['shop'] }}<div class="sx-sub">{{ $sh['email'] }}{{ $sh['place'] ? ' · ' . $sh['place'] : '' }}</div></td>
                                        <td><span class="or-tag {{ in_array($sh['state'], ['replied', 'looked'], true) ? 'hot' : '' }}">{{ $stateNames[$sh['state']] }}</span>@if ($sh['note'])<div class="sx-sub">{{ $sh['note'] }}</div>@endif</td>
                                        <td class="n"><a class="sx-btn sm" style="text-decoration:none" href="{{ $pipe }}?open={{ $sh['id'] }}">Open</a></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if (count($detail['shops']) > 20)
                                <button type="button" class="sx-btn sm" style="margin-top:8px" @click="all = ! all" x-text="all ? 'Show fewer' : 'Show all {{ count($detail['shops']) }}'"></button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
@endif

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
