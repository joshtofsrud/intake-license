<!DOCTYPE html>
{{-- MARKER-PATCH-239 — public rental availability browse. Standalone page
     in the public design language (same approach as booking.blade). The
     Reserve CTA points at /contact until PATCH-240 ships real checkout. --}}
@php
  $accent = $currentTenant->accent_color ?? '#BEF264';
  $tname  = $currentTenant->name ?? 'Rentals';
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rentals — {{ $tname }}</title>
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.6; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  .wrap { max-width: 980px; margin: 0 auto; padding: 28px 20px 80px; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
  .top a.home { font-weight: 700; font-size: 16px; }
  h1 { font-size: 26px; font-weight: 650; letter-spacing: -.02em; }
  .sub { font-size: 14px; opacity: .55; margin-top: 4px; }
  .picker { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 14px; padding: 18px 20px; margin: 22px 0 30px; }
  .picker label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; opacity: .5; display: block; margin-bottom: 5px; font-weight: 600; }
  .picker input { font: inherit; font-size: 14px; padding: 9px 12px; border: 1.5px solid rgba(0,0,0,.12); border-radius: 9px; background: #fff; }
  .picker button { font: inherit; font-size: 14px; font-weight: 650; padding: 10px 22px; border: none; border-radius: 9px; background: var(--acc); color: #111; cursor: pointer; }
  .err { background: #fef3c7; border: 1px solid #f59e0b; color: #78350f; border-radius: 9px; padding: 10px 14px; font-size: 13px; margin-bottom: 18px; }
  .cat { margin-bottom: 30px; }
  .cat h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .07em; opacity: .45; margin-bottom: 12px; font-weight: 650; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; }
  .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 14px; padding: 18px 20px; display: flex; flex-direction: column; }
  .card .name { font-size: 16px; font-weight: 650; line-height: 1.3; }
  .card .subt { font-size: 12.5px; opacity: .55; margin-top: 2px; }
  .chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 11px; }
  .chip { font-size: 12px; background: rgba(0,0,0,.05); border-radius: 6px; padding: 3px 9px; }
  .meta { font-size: 12px; opacity: .55; margin-top: 10px; }
  .card .cta { margin-top: 14px; text-align: center; font-size: 13.5px; font-weight: 650; padding: 9px 0; border-radius: 9px; background: var(--acc); color: #111; }
  .empty { text-align: center; padding: 60px 20px; opacity: .55; font-size: 15px; }
</style>
</head>
<body>
@include('public._chrome-inline', ['chromePos' => 'top']) {{-- MARKER-PATCH-581 --}}
<div class="wrap">
  <div class="top">
    <a class="home" href="/">{{ $tname }}</a>
    <a href="/contact" style="font-size:13.5px;opacity:.6">Contact</a>
  </div>

  <h1>Rentals</h1>
  <p class="sub">Pick your window — everything below is genuinely available for those times.</p>

  <form class="picker" method="GET" action="{{ route('tenant.rentals.browse') }}">
    <div>
      <label>Pickup</label>
      <input type="datetime-local" name="starts" value="{{ $startLocal->format('Y-m-d\TH:i') }}" required>
    </div>
    <div>
      <label>Return</label>
      <input type="datetime-local" name="due" value="{{ $dueLocal->format('Y-m-d\TH:i') }}" required>
    </div>
    <button type="submit">Check availability</button>
  </form>

  @if($error)<div class="err">{{ $error }}</div>@endif

  @if(empty($groups))
    <div class="empty">Nothing free for that window — try different dates, or <a href="/contact" style="text-decoration:underline">get in touch</a> and we'll sort you out.</div>
  @else
    @foreach($groups as $catName => $models)
      <div class="cat">
        <h2>{{ $catName }}</h2>
        <div class="grid">
          @foreach($models as $entry)
            @php $m = $entry['model']; @endphp
            <div class="card">
              <div class="name">{{ $m->name }}</div>
              @if($m->subtitle)<div class="subt">{{ $m->subtitle }}</div>@endif
              <div class="chips">
                @if($m->daily_rate_cents)<span class="chip"><b>{{ format_money($m->daily_rate_cents) }}</b>/day</span>@endif
                @if($m->hourly_rate_cents)<span class="chip"><b>{{ format_money($m->hourly_rate_cents) }}</b>/hr</span>@endif
                @if($m->weekend_rate_cents)<span class="chip"><b>{{ format_money($m->weekend_rate_cents) }}</b>/weekend</span>@endif
              </div>
              <div class="meta">{{ $entry['count'] }} available{{ count($entry['sizes']) ? ' · sizes ' . implode(', ', $entry['sizes']) : '' }}</div>
              {{-- MARKER-PATCH-240 — real online reservation. --}}
              <a class="cta" href="{{ route('tenant.rentals.reserve', ['model' => $m->id, 'starts' => $startLocal->format('Y-m-d\TH:i'), 'due' => $dueLocal->format('Y-m-d\TH:i')]) }}">Reserve</a>
            </div>
          @endforeach
        </div>
      </div>
    @endforeach
    <p style="font-size:12px;opacity:.4;text-align:center;margin-top:10px">{{ $unitCount }} unit{{ $unitCount === 1 ? '' : 's' }} free for this window.</p>
  @endif
</div>
@include('public._chrome-inline', ['chromePos' => 'bottom']) {{-- MARKER-PATCH-581 --}}
</body>
</html>
