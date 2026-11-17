@extends('layouts.tenant.app')
@php $pageTitle = 'Campaign report'; @endphp

{{-- MARKER-SHOP-CAMPAIGN-REPORT --}}
@push('styles')
<style>
  .cr-wrap{max-width:1080px}
  .cr-crumb{color:var(--ia-text-muted);font-size:12.5px;margin-bottom:12px}
  .cr-crumb a{color:var(--ia-text-muted);text-decoration:none}
  .cr-crumb a:hover{color:var(--ia-text)}
  .cr-head{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
  .cr-head h1{font-size:20px;font-weight:700;margin:0;color:var(--ia-text)}
  .cr-sub{color:var(--ia-text-muted);font-size:12.5px;margin-top:3px}
  .cr-pill{font-size:11px;padding:2px 9px;border-radius:99px;background:var(--ia-hover);color:var(--ia-text-muted)}
  .cr-pill.sent{background:var(--ia-accent-soft);color:var(--ia-text)}
  .cr-pill.sending{background:rgba(96,165,250,.12);color:#93C5FD}
  .cr-pill.scheduled{background:rgba(251,191,36,.12);color:#FCD34D}
  .cr-prog{margin:16px 0 4px}
  .cr-bar{display:flex;height:7px;border-radius:5px;overflow:hidden;background:var(--ia-hover)}
  .cr-bar i{display:block;height:100%}
  .cr-prog .l{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);margin-top:7px}
  .cr-prog b{color:var(--ia-text);font-weight:600}
  /* one ruled strip, not a wall of cards */
  .cr-k{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));border-top:1px solid var(--ia-border);border-bottom:1px solid var(--ia-border);margin:18px 0}
  @media(max-width:860px){.cr-k{grid-template-columns:repeat(3,minmax(0,1fr))}}
  .cr-k a{display:block;padding:12px 14px;text-decoration:none;color:inherit;border-right:1px solid var(--ia-border)}
  .cr-k a:last-child{border-right:0}
  .cr-k a:hover{background:var(--ia-hover)}
  .cr-k a.on{box-shadow:inset 0 -2px 0 var(--ia-accent)}
  .cr-k .k{display:block;font-size:11.5px;color:var(--ia-text-muted)}
  .cr-k .v{display:block;font-size:21px;font-weight:700;color:var(--ia-text);letter-spacing:-.02em;line-height:1.25}
  .cr-k .d{display:block;font-size:11.5px;color:var(--ia-text-muted)}
  .cr-k .bad .v{color:#f08a8a}
  .cr-side{display:grid;grid-template-columns:minmax(0,2fr) minmax(220px,1fr);gap:26px;align-items:start}
  @media(max-width:860px){.cr-side{grid-template-columns:1fr}}
  .cr-h{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600;margin:0 0 8px}
  .cr-plot{position:relative;height:120px}
  .cr-plot svg{position:absolute;inset:0;width:100%;height:100%}
  .cr-axis{display:flex;justify-content:space-between;font-size:11px;color:var(--ia-text-muted);margin-top:4px}
  .cr-key{display:flex;gap:14px;font-size:11.5px;color:var(--ia-text-muted);margin-top:6px}
  .cr-key i{display:inline-block;width:10px;height:3px;border-radius:2px;vertical-align:middle;margin-right:5px}
  .cr-mini{font-size:12.5px;line-height:1.9;color:var(--ia-text)}
  .cr-mini .r{display:flex;gap:8px}.cr-mini .r span:first-child{flex:1;color:var(--ia-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cr-filters{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:22px 0 10px}
  .cr-chip{border:1px solid var(--ia-border);border-radius:99px;padding:5px 11px;font-size:12.5px;color:var(--ia-text-muted);text-decoration:none}
  .cr-chip:hover{color:var(--ia-text)}
  .cr-chip.on{border-color:var(--ia-accent);background:var(--ia-accent-soft);color:var(--ia-text);font-weight:600}
  .cr-filters form{margin-left:auto}
  .cr-tbl{width:100%;border-collapse:collapse;font-size:13px}
  .cr-tbl th{text-align:left;color:var(--ia-text-muted);font-weight:500;font-size:11.5px;padding:8px;border-bottom:1px solid var(--ia-border)}
  .cr-tbl td{padding:9px 8px;border-bottom:1px solid var(--ia-border);color:var(--ia-text);vertical-align:top}
  .cr-tbl .em{font-size:11.5px;color:var(--ia-text-muted)}
  .cr-tbl a{color:var(--ia-text);text-decoration:none}
  .cr-tbl a:hover{text-decoration:underline}
  .cr-ev{display:inline-block;font-size:11px;border-radius:99px;padding:1px 8px;margin:0 4px 3px 0;border:1px solid var(--ia-border);color:var(--ia-text-muted);white-space:nowrap}
  .cr-ev.good{border-color:rgba(127,217,143,.45);color:#7fd98f}
  .cr-ev.bad{border-color:rgba(240,138,138,.5);color:#f08a8a}
  .cr-ev.warn{border-color:rgba(240,196,106,.5);color:#f0c46a}
  .cr-pager{display:flex;gap:10px;align-items:center;justify-content:flex-end;font-size:12.5px;color:var(--ia-text-muted);margin-top:10px}
  .cr-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.6;margin-top:16px}
  .cr-code{display:flex;gap:22px;flex-wrap:wrap;font-size:12.5px;color:var(--ia-text-muted);border-left:2px solid var(--ia-accent);padding:4px 0 4px 12px;margin:4px 0 0}
  .cr-code b{color:var(--ia-text);font-size:15px}
</style>
@endpush

@section('content')
@php
  $S = $sum; $L = $list;
  $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
  $eta = $S['eta_minutes'];
  $etaTxt = $eta === null ? null : ($eta < 90 ? 'about ' . $eta . ' min left' : 'about ' . round($eta / 60, 1) . ' h left');
  $count = ['all' => $S['total'], 'opened' => $S['opened'], 'clicked' => $S['clicked'], 'unopened' => max(0, $S['delivered'] - $S['opened']),
            'bounced' => $S['bounced'], 'complained' => $S['complained'], 'unsubscribed' => $S['unsubscribed'],
            'pending' => $S['pending'], 'skipped' => $S['skipped'], 'failed' => $S['failed']];
  $u = fn (array $q) => route('tenant.campaigns.results', $campaign->id) . '?' . http_build_query(array_filter($q + ['f' => $filter, 'q' => $search], fn ($v) => $v !== '' && $v !== 'all' && $v !== null));
  $tl = $S['timeline'];
  $live = in_array($campaign->status, ['sending', 'scheduled'], true);
@endphp
<div class="cr-wrap">
  <div class="cr-crumb"><a href="{{ route('tenant.campaigns.index') }}">Campaigns</a> → <a href="{{ route('tenant.campaigns.show', $campaign->id) }}">{{ $campaign->name }}</a> → Report</div>

  <div class="cr-head">
    <h1>{{ $campaign->name }}</h1>
    <span class="cr-pill {{ $campaign->status }}">{{ ucfirst($campaign->status) }}</span>
  </div>
  <div class="cr-sub">
    {{ $campaign->subject }}
    @if($S['first_sent']) · first sent {{ tlocal(\Carbon\Carbon::parse($S['first_sent'], 'UTC'), 'D M j, g:i a') }}@endif
    @if($campaign->status === 'sent' && $campaign->sent_at) · finished {{ tlocal($campaign->sent_at, 'D M j, g:i a') }}@endif
    @if($campaign->status === 'scheduled' && $campaign->scheduled_at) · starts {{ tlocal($campaign->scheduled_at, 'D M j, g:i a') }}@endif
  </div>

  {{-- sending progress --}}
  @if($S['total'] > 0)
    <div class="cr-prog">
      <div class="cr-bar">
        <i style="width:{{ $S['sent'] / $S['total'] * 100 }}%;background:var(--ia-accent)"></i>
        <i style="width:{{ $S['bounced'] / $S['total'] * 100 }}%;background:#f08a8a"></i>
        <i style="width:{{ ($S['skipped'] + $S['failed']) / $S['total'] * 100 }}%;background:var(--ia-border-strong, #555)"></i>
      </div>
      <div class="l">
        <span><b>{{ number_format($S['went_out']) }}</b> sent</span>
        <span><b>{{ number_format($S['pending']) }}</b> waiting</span>
        <span><b>{{ number_format($S['skipped']) }}</b> skipped</span>
        <span><b>{{ number_format($S['failed']) }}</b> failed</span>
        <span>of <b>{{ number_format($S['total']) }}</b> · {{ $S['progress'] }}% done</span>
        @if($S['pending'] > 0)<span>{{ number_format($S['last_hour']) }} sent in the last hour{{ $etaTxt ? ' · ' . $etaTxt : '' }}</span>@endif
      </div>
    </div>
  @elseif($campaign->status === 'scheduled')
    <div class="cr-legend">The list of people is built when it starts sending, so anyone who unsubscribes before then is left out. Progress shows here from then.</div>
  @else
    <div class="cr-legend">Nothing has been sent yet.</div>
  @endif

  {{-- headline rates; each one filters the list below --}}
  <div class="cr-k">
    <a href="{{ $u(['f' => 'all', 'page' => null]) }}" class="{{ $filter === 'all' ? 'on' : '' }}"><span class="k">Delivered</span><span class="v">{{ number_format($S['delivered']) }}</span><span class="d">of {{ number_format($S['went_out']) }} sent</span></a>
    <a href="{{ $u(['f' => 'opened', 'page' => null]) }}" class="{{ $filter === 'opened' ? 'on' : '' }}"><span class="k">Opened</span><span class="v">{{ $pct($S['open_rate']) }}</span><span class="d">{{ number_format($S['opened']) }} {{ $S['opened'] === 1 ? 'person' : 'people' }}</span></a>
    <a href="{{ $u(['f' => 'clicked', 'page' => null]) }}" class="{{ $filter === 'clicked' ? 'on' : '' }}"><span class="k">Clicked</span><span class="v">{{ $pct($S['click_rate']) }}</span><span class="d">{{ number_format($S['clicked']) }} {{ $S['clicked'] === 1 ? 'person' : 'people' }}</span></a>
    <a href="{{ $u(['f' => 'bounced', 'page' => null]) }}" class="{{ $filter === 'bounced' ? 'on' : '' }} {{ ($S['bounce_rate'] ?? 0) >= 5 ? 'bad' : '' }}"><span class="k">Bounced</span><span class="v">{{ $pct($S['bounce_rate']) }}</span><span class="d">{{ number_format($S['bounced']) }} {{ $S['bounced'] === 1 ? 'address' : 'addresses' }}</span></a>
    <a href="{{ $u(['f' => 'unsubscribed', 'page' => null]) }}" class="{{ $filter === 'unsubscribed' ? 'on' : '' }}"><span class="k">Unsubscribed</span><span class="v">{{ $pct($S['unsub_rate']) }}</span><span class="d">{{ number_format($S['unsubscribed']) }} {{ $S['unsubscribed'] === 1 ? 'person' : 'people' }}</span></a>
    <a href="{{ $u(['f' => 'complained', 'page' => null]) }}" class="{{ $filter === 'complained' ? 'on' : '' }} {{ $S['complained'] > 0 ? 'bad' : '' }}"><span class="k">Marked spam</span><span class="v">{{ number_format($S['complained']) }}</span><span class="d">{{ $S['complained'] > 0 ? 'they won\'t get your campaigns again' : 'none' }}</span></a>
  </div>

  @if($attribution)
    <div class="cr-h">Discount code {{ $attribution['code'] }}</div>
    <div class="cr-code">
      <span><b>{{ number_format($attribution['uses']) }}</b> uses</span>
      <span><b>${{ number_format($attribution['sales_cents'] / 100, 2) }}</b> in sales</span>
      <span><b>${{ number_format($attribution['given_cents'] / 100, 2) }}</b> given away</span>
      <span>{{ $attribution['summary'] }}</span>
    </div>
    <div style="height:18px"></div>
  @endif

  <div class="cr-side">
    <div>
      <div class="cr-h">Over time · by {{ $tl['unit'] }}</div>
      @if(count($tl['labels']) > 1)
        @php
          $W = 640; $H = 120; $P = 6; $mx = max(1, max($tl['sent'] ?: [1]));
          $path = function ($a) use ($W, $H, $P, $mx) { $n = count($a); $o = [];
            foreach ($a as $i => $v) { $o[] = ($i ? 'L' : 'M') . round($n > 1 ? $i / ($n - 1) * $W : $W, 1) . ' ' . round($H - $P - $v / $mx * ($H - 2 * $P), 1); }
            return implode(' ', $o); };
        @endphp
        <div class="cr-plot">
          <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none">
            <path d="{{ $path($tl['sent']) }}" fill="none" stroke="currentColor" stroke-opacity=".45" stroke-width="2" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
            <path d="{{ $path($tl['opened']) }}" fill="none" stroke="var(--ia-accent)" stroke-width="2" vector-effect="non-scaling-stroke"/>
            <path d="{{ $path($tl['clicked']) }}" fill="none" stroke="#7fd98f" stroke-width="2" vector-effect="non-scaling-stroke"/>
          </svg>
        </div>
        <div class="cr-axis"><span>{{ $tl['labels'][0] }}</span><span>{{ $tl['labels'][intdiv(count($tl['labels']), 2)] }}</span><span>{{ end($tl['labels']) }}</span></div>
        <div class="cr-key"><span><i style="background:currentColor;opacity:.45"></i>Sent</span><span><i style="background:var(--ia-accent)"></i>Opened</span><span><i style="background:#7fd98f"></i>Clicked</span><span>running totals</span></div>
      @else
        <div class="cr-legend" style="margin-top:0">The line starts once sending has been going for more than an hour.</div>
      @endif
    </div>
    <div class="cr-mini">
      <div class="cr-h">Why some didn't go out</div>
      @forelse($S['skip_reasons'] as $why => $n)
        <div class="r"><span title="{{ $why }}">{{ $why }}</span><span>{{ number_format($n) }}</span></div>
      @empty
        <div style="color:var(--ia-text-muted)">Everyone was sent to.</div>
      @endforelse
      @if($S['bounce_types'])
        <div class="cr-h" style="margin-top:12px">Bounce types</div>
        @foreach($S['bounce_types'] as $bt => $n)
          <div class="r"><span>{{ $bt }}</span><span>{{ number_format($n) }}</span></div>
        @endforeach
      @endif
    </div>
  </div>

  <div class="cr-filters">
    @foreach($filters as $fk => $fl)
      @if($fk === 'all' || ($count[$fk] ?? 0) > 0 || $filter === $fk)
        <a class="cr-chip {{ $filter === $fk ? 'on' : '' }}" href="{{ $u(['f' => $fk, 'page' => null]) }}">{{ $fl }} {{ number_format($count[$fk] ?? 0) }}</a>
      @endif
    @endforeach
    <form method="GET" action="{{ route('tenant.campaigns.results', $campaign->id) }}">
      @if($filter !== 'all')<input type="hidden" name="f" value="{{ $filter }}">@endif
      <input type="search" name="q" value="{{ $search }}" class="ia-input" placeholder="Search name or email" style="width:220px">
    </form>
  </div>

  <table class="cr-tbl">
    <thead><tr><th>Customer</th><th>Sent</th><th>What happened</th></tr></thead>
    <tbody>
    @forelse($L['rows'] as $r)
      @php $nm = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')); @endphp
      <tr>
        <td>
          @if($r->customer_id)
            <a href="{{ route('tenant.customers.show', $r->customer_id) }}">{{ $nm !== '' ? $nm : $r->email }}</a>
          @else
            {{ $nm !== '' ? $nm : $r->email }}
          @endif
          @if($nm !== '')<div class="em">{{ $r->email }}</div>@endif
        </td>
        <td style="white-space:nowrap">
          @if(in_array($r->status, ['sent', 'bounced'], true) && $r->sent_at){{ tlocal(\Carbon\Carbon::parse($r->sent_at, 'UTC'), 'M j, g:i a') }}
          @elseif($r->status === 'pending')<span class="cr-ev">waiting</span>
          @elseif($r->status === 'skipped')<span class="cr-ev" title="{{ $r->error_message }}">skipped · {{ \Illuminate\Support\Str::limit($r->error_message ?: 'no reason', 40) }}</span>
          @elseif($r->status === 'failed')<span class="cr-ev bad" title="{{ $r->error_message }}">failed</span>@endif
        </td>
        <td>
          @if($r->opened_at)<span class="cr-ev good">opened {{ tlocal(\Carbon\Carbon::parse($r->opened_at, 'UTC'), 'M j, g:i a') }}{{ $r->open_count > 1 ? ' · ' . $r->open_count . '×' : '' }}</span>@endif
          @if($r->clicked_at)<span class="cr-ev good">clicked {{ tlocal(\Carbon\Carbon::parse($r->clicked_at, 'UTC'), 'M j, g:i a') }}{{ $r->click_count > 1 ? ' · ' . $r->click_count . '×' : '' }}</span>@endif
          @if($r->status === 'bounced')<span class="cr-ev bad" title="{{ $r->error_message }}">bounced · {{ $r->bounce_type ?: 'bounce' }}</span>@endif
          @if($r->complained_at)<span class="cr-ev bad">marked spam</span>@endif
          @if($r->unsubscribed_at)<span class="cr-ev warn">unsubscribed</span>@endif
          @if($r->status === 'sent' && ! $r->opened_at)<span style="color:var(--ia-text-muted);font-size:12px">no opens yet</span>@endif
        </td>
      </tr>
    @empty
      <tr><td colspan="3" style="text-align:center;color:var(--ia-text-muted);padding:22px">
        {{ trim($search) !== '' ? 'Nobody matches "' . $search . '".' : 'Nobody here yet.' }}
        @if($filter !== 'all' || trim($search) !== '') <a href="{{ route('tenant.campaigns.results', $campaign->id) }}" style="color:var(--ia-accent)">Show everyone</a>@endif
      </td></tr>
    @endforelse
    </tbody>
  </table>
  @if($L['pages'] > 1)
    <div class="cr-pager">
      <span>{{ number_format($L['from']) }}–{{ number_format($L['to']) }} of {{ number_format($L['total']) }}</span>
      @if($L['page'] > 1)<a class="ia-btn ia-btn--ghost ia-btn--sm" href="{{ $u(['page' => $L['page'] - 1]) }}">Previous</a>@endif
      @if($L['page'] < $L['pages'])<a class="ia-btn ia-btn--ghost ia-btn--sm" href="{{ $u(['page' => $L['page'] + 1]) }}">Next</a>@endif
    </div>
  @endif

  <div class="cr-legend">
    Rates are out of delivered emails; bounces are out of everything sent. Opens are approximate: some mail apps open every email automatically and others block the tracking image, so treat opens as a guide. Clicks are reliable, and a click always counts as an open.
    Unsubscribes and spam marks count against the campaign that caused them from this update on; earlier ones aren't on reports. Replies go to your reply-to mailbox and aren't counted here.
    @if($live) This page refreshes every 20 seconds while the campaign is going out. @endif
  </div>
</div>
@if($live)
  <script>setTimeout(function () { if (!document.querySelector('.cr-filters input:focus')) location.reload(); }, 20000);</script>
@endif
@endsection
