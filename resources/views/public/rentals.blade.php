<!DOCTYPE html>
{{-- public rental availability browse, inside the shop's own header and footer.
     MARKER-RENTAL-PAGES-THEME: styles come from public._rental-theme. --}}
@php
  $tname  = $currentTenant->name ?? 'Rentals';
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{!! \App\Support\SiteLook::schemeScript($currentTenant ?? tenant()) !!}
@include('partials.mobile-input-zoom')
<title>Rentals — {{ $tname }}</title>
@include('public._theme-fonts')
@include('public._rental-theme')
<style>
  .rb-picker { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; margin: 26px 0 10px; }
  .rb-picker .rt-label { margin-top: 0; }
  .rb-hours { font-size: 13px; color: var(--rt-muted); margin-bottom: 26px; }
  .rb-filter { font-size: 13.5px; margin: 0 0 22px; }
  .rb-filter a { color: var(--rt-muted); text-decoration: underline; text-underline-offset: 2px; }
  .rb-cat { margin-bottom: 36px; }
  .rb-cat h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: var(--rt-muted); margin-bottom: 14px; font-family: var(--p-font-body); font-weight: 650; }
  .rb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
  .rb-card { display: flex; flex-direction: column; background: var(--rt-card); border: 1px solid var(--rt-line); border-radius: var(--rt-r); overflow: hidden; transition: border-color .15s, transform .15s; }
  .rb-card:hover { border-color: color-mix(in srgb, var(--p-accent) 55%, transparent); transform: translateY(-2px); }
  .rb-photo { aspect-ratio: 16/10; background: var(--p-tile-bg, #ffffff) center/contain no-repeat; border-bottom: 1px solid var(--rt-line); }
  .rb-photo.none { display: grid; place-items: center; background: var(--rt-field); color: var(--rt-muted); font-size: 12px; }
  .rb-body { padding: 16px 18px 18px; display: flex; flex-direction: column; flex: 1; }
  .rb-name { font-size: 17px; font-weight: 650; line-height: 1.3; font-family: var(--p-font-heading); }
  .rb-subt { font-size: 13px; color: var(--rt-muted); margin-top: 2px; }
  .rb-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
  .rb-chip { font-size: 12.5px; border: 1px solid var(--rt-line); border-radius: 99px; padding: 3px 10px; }
  .rb-meta { font-size: 12.5px; color: var(--rt-muted); margin: 10px 0 16px; }
  .rb-body .rt-btn { margin-top: auto; }
  .rb-empty { text-align: center; padding: 64px 20px; color: var(--rt-muted); font-size: 15px; border: 1px dashed var(--rt-line); border-radius: var(--rt-r); }
  .rb-empty a { color: var(--p-text); text-decoration: underline; }
  @media (max-width: 640px) { .rb-picker { grid-template-columns: 1fr; } .rb-picker .rt-btn { width: 100%; } }
</style>
  @include('public._ga4')
  @include('public._site-scripts', ['ssPart' => 'head']) {{-- MARKER-SITE-SCRIPTS --}}
</head>
<body>
@include('public._launch-ribbon', ['lrModule' => 'rent'])
@include('public._chrome-inline', ['chromePos' => 'top'])
<div class="rt-wrap">
  <h1 class="rt-h1">Rentals</h1>
  <p class="rt-sub">Pick your dates — everything below is free for that whole window.</p>

  <form class="rt-card rb-picker" method="GET" action="{{ route('tenant.rentals.browse') }}">
    <div>
      <label class="rt-label" for="rbStart">Pickup</label>
      <input class="rt-input" id="rbStart" type="datetime-local" name="starts" value="{{ $startLocal->format('Y-m-d\TH:i') }}" required>
    </div>
    <div>
      <label class="rt-label" for="rbDue">Return</label>
      <input class="rt-input" id="rbDue" type="datetime-local" name="due" value="{{ $dueLocal->format('Y-m-d\TH:i') }}" required>
      @if(!empty($activeCategory))<input type="hidden" name="category" value="{{ $activeCategory->id }}">@endif
    </div>
    <button type="submit" class="rt-btn">Check availability</button>
  </form>

  @php
    // a message sent back from the reserve page (sold out, outside hours, paused) used to be dropped here
    $error = $error ?: session('browse_error');
    $rhHours = \App\Support\Launch\RentalRules::hours($currentTenant ?? tenant());
  @endphp
  @if(array_filter($rhHours['days'], fn ($d) => $d['open']))
    <p class="rb-hours">Pickup and return: {{ \App\Support\Launch\RentalRules::summary($rhHours) }}</p>
  @else
    <div class="rb-hours"></div>
  @endif
  @if($error)<div class="rt-err" style="display:block;margin:0 0 22px">{{ $error }}</div>@endif

  {{-- active category chip --}}
  @if(!empty($activeCategory))
    <p class="rb-filter">Showing <b>{{ $activeCategory->name }}</b> · <a href="{{ route('tenant.rentals.browse', ['starts' => $startLocal->format('Y-m-d\TH:i'), 'due' => $dueLocal->format('Y-m-d\TH:i')]) }}">show everything</a></p>
  @endif

  @if(empty($groups))
    <div class="rb-empty">Nothing free for that window — try different dates, or <a href="/contact">get in touch</a> and we'll sort you out.</div>
  @else
    @foreach($groups as $catName => $models)
      <section class="rb-cat">
        <h2>{{ $catName }}</h2>
        <div class="rb-grid">
          @foreach($models as $entry)
            @php $m = $entry['model']; $rbUrl = route('tenant.rentals.reserve', ['model' => $m->id, 'starts' => $startLocal->format('Y-m-d\TH:i'), 'due' => $dueLocal->format('Y-m-d\TH:i')]); @endphp
            <article class="rb-card">
              @if($m->image_url)
                <a href="{{ $rbUrl }}" class="rb-photo" style="background-image:url('{{ e($m->image_url) }}')" aria-label="Reserve {{ $m->name }}"></a>
              @else
                <a href="{{ $rbUrl }}" class="rb-photo none">{{ $tname }}</a>
              @endif
              <div class="rb-body">
                <div class="rb-name">{{ $m->name }}</div>
                @if($m->subtitle)<div class="rb-subt">{{ $m->subtitle }}</div>@endif
                <div class="rb-chips">
                  @if($m->daily_rate_cents)<span class="rb-chip"><b>{{ format_money($m->daily_rate_cents) }}</b>{{ $m->additional_day_rate_cents ? ' first day' : ' / day' }}</span>@endif
                  @if($m->daily_rate_cents && $m->additional_day_rate_cents)<span class="rb-chip"><b>{{ format_money($m->additional_day_rate_cents) }}</b> each extra day</span>@endif
                  @if($m->hourly_rate_cents)<span class="rb-chip"><b>{{ format_money($m->hourly_rate_cents) }}</b> / hr</span>@endif
                  @if($m->weekend_rate_cents)<span class="rb-chip"><b>{{ format_money($m->weekend_rate_cents) }}</b> / weekend</span>@endif
                </div>
                <div class="rb-meta">{{ $entry['count'] }} available{{ count($entry['sizes']) ? ' · sizes ' . implode(', ', $entry['sizes']) : '' }}</div>
                <a class="rt-btn block" href="{{ $rbUrl }}">Reserve</a>
              </div>
            </article>
          @endforeach
        </div>
      </section>
    @endforeach
    <p class="rt-note" style="text-align:center">{{ $unitCount }} unit{{ $unitCount === 1 ? '' : 's' }} free for this window.</p>
  @endif
</div>
@include('public._chrome-inline', ['chromePos' => 'bottom'])
@include('public._site-scripts', ['ssPart' => 'body']) {{-- MARKER-SITE-SCRIPTS --}}
</body>
</html>
