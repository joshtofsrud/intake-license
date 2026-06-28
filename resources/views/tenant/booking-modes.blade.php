@extends('layouts.tenant.app')
@php $pageTitle = 'Booking Mode'; @endphp

@push('styles')
<style>
  .bm-intro { font-size: 13px; color: var(--ia-text-muted); margin-bottom: 22px; line-height: 1.55; max-width: 660px; }
  .bm-flash { background: color-mix(in srgb, var(--ia-accent) 14%, transparent); border: .5px solid var(--ia-accent); color: var(--ia-text); font-size: 13px; padding: 11px 15px; border-radius: var(--ia-r-md); margin-bottom: 20px; }

  .bm-modes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 32px; }
  .bm-mode { position: relative; background: var(--ia-surface); border: 1px solid var(--ia-border); border-radius: var(--ia-r-lg); padding: 18px; cursor: pointer; transition: border-color .12s, background .12s; }
  .bm-mode:hover { border-color: color-mix(in srgb, var(--ia-accent) 50%, var(--ia-border)); }
  .bm-mode.sel { border-color: var(--ia-accent); background: color-mix(in srgb, var(--ia-accent) 7%, transparent); }
  .bm-mode input { position: absolute; opacity: 0; pointer-events: none; }
  .bm-mode-h { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
  .bm-dot { width: 16px; height: 16px; border-radius: 50%; border: 1.5px solid var(--ia-border); flex: none; }
  .bm-mode.sel .bm-dot { border-color: var(--ia-accent); background: radial-gradient(circle at center, var(--ia-accent) 0 5px, transparent 5px); }
  .bm-mode p { font-size: 12px; color: var(--ia-text-muted); line-height: 1.5; }

  .bm-section-h { font-size: 13px; font-weight: 600; margin: 0 0 4px; }
  .bm-section-sub { font-size: 12px; color: var(--ia-text-muted); margin-bottom: 16px; max-width: 620px; line-height: 1.5; }
  .bm-cat { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-text-muted); margin: 20px 0 8px; }
  .bm-item { display: flex; align-items: center; gap: 14px; background: var(--ia-surface); border: .5px solid var(--ia-border); border-radius: var(--ia-r-md); padding: 12px 14px; margin-bottom: 8px; }
  .bm-toggle { flex: none; }
  .bm-toggle input { width: 18px; height: 18px; accent-color: var(--ia-accent); cursor: pointer; }
  .bm-item-main { flex: 1; min-width: 0; }
  .bm-item-name { font-size: 13.5px; font-weight: 500; }
  .bm-item-price { font-size: 11.5px; color: var(--ia-text-muted); margin-top: 2px; }
  .bm-item-fields { display: flex; gap: 10px; align-items: center; flex: none; }
  .bm-sort { width: 58px; }
  .bm-tag { width: 220px; }
  .bm-item-fields input { background: var(--ia-surface-2); border: .5px solid var(--ia-border); border-radius: var(--ia-r-sm, 6px); padding: 7px 9px; font-size: 12.5px; color: var(--ia-text); font-family: inherit; }
  .bm-item-fields input:focus { outline: none; border-color: var(--ia-accent); }
  .bm-item-fields label { font-size: 10px; color: var(--ia-text-muted); display: block; margin-bottom: 3px; }
  .bm-empty { font-size: 13px; color: var(--ia-text-muted); padding: 16px 0; }
  .bm-save { margin-top: 26px; display: flex; gap: 12px; align-items: center; }
  .bm-btn { background: var(--ia-accent); color: var(--ia-accent-text, #0a0a0a); border: 0; border-radius: var(--ia-r-md); padding: 11px 22px; font-size: 13.5px; font-weight: 600; font-family: inherit; cursor: pointer; }
  .bm-curate { transition: opacity .15s; }
  .bm-curate.dim { opacity: .45; }
  @media (max-width: 720px) {
    .bm-modes { grid-template-columns: 1fr; }
    .bm-tag { width: 140px; }
    .bm-item-fields { flex-direction: column; align-items: flex-end; gap: 6px; }
  }
</style>
@endpush

@section('content')
<div class="bm-intro">
  Choose how customers move through your public booking page. <strong>Advanced</strong> is the full multi-step flow.
  <strong>Simple</strong> shows a curated menu of services in three quick steps. <strong>Let the customer choose</strong>
  opens on a fork so they pick the path that fits. Simple and Advanced both create the same kind of booking — Simple is just a faster front door.
</div>

@if(session('status'))
  <div class="bm-flash">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('tenant.booking_modes.save') }}">
  @csrf

  <div class="bm-modes">
    @php
      $modes = [
        ['v'=>'advanced','t'=>'Advanced','d'=>'The full flow — add each item, choose services per item, review. Best for complex, multi-item jobs.'],
        ['v'=>'simple','t'=>'Simple','d'=>'A curated menu in three steps: pick a service, schedule, leave details. Fastest path to a booking.'],
        ['v'=>'choice','t'=>'Let customer choose','d'=>'Open on a fork. The customer picks Quick or Full and can switch anytime.'],
      ];
    @endphp
    @foreach($modes as $m)
      <label class="bm-mode {{ $mode === $m['v'] ? 'sel' : '' }}" data-mode="{{ $m['v'] }}">
        <input type="radio" name="booking_flow_mode" value="{{ $m['v'] }}" {{ $mode === $m['v'] ? 'checked' : '' }}>
        <div class="bm-mode-h"><span class="bm-dot"></span>{{ $m['t'] }}</div>
        <p>{{ $m['d'] }}</p>
      </label>
    @endforeach
  </div>

  <div class="bm-curate {{ $mode === 'advanced' ? 'dim' : '' }}" id="bm-curate">
    <div class="bm-section-h">Simple menu</div>
    <div class="bm-section-sub">
      Pick which services appear in the Simple flow and the order they show. The tagline is the short line under each
      service tile (defaults to the start of the service description if left blank). Only used by Simple and the Quick path.
    </div>

    @forelse($categories as $cat)
      @if($cat->items->count())
        <div class="bm-cat">{{ $cat->name }}</div>
        @foreach($cat->items as $item)
          <div class="bm-item">
            <div class="bm-toggle">
              <input type="checkbox" name="items[{{ $item->id }}][simple_enabled]" value="1" {{ $item->simple_enabled ? 'checked' : '' }} aria-label="Show {{ $item->name }} in Simple menu">
            </div>
            <div class="bm-item-main">
              <div class="bm-item-name">{{ $item->name }}</div>
              <div class="bm-item-price">
                @if($item->price_cents > 0)${{ number_format($item->price_cents/100, 2) }}@else No price @endif
                @if($item->duration_minutes) · {{ $item->duration_minutes >= 60 ? round($item->duration_minutes/60,1).' hr' : $item->duration_minutes.' min' }}@endif
              </div>
            </div>
            <div class="bm-item-fields">
              <div>
                <label>Order</label>
                <input class="bm-sort" type="number" min="0" name="items[{{ $item->id }}][simple_sort]" value="{{ $item->simple_sort ?? 0 }}">
              </div>
              <div>
                <label>Tagline</label>
                <input class="bm-tag" type="text" maxlength="160" name="items[{{ $item->id }}][simple_tagline]" value="{{ $item->simple_tagline }}" placeholder="Short description">
              </div>
            </div>
          </div>
        @endforeach
      @endif
    @empty
      <div class="bm-empty">No services yet. Add services first, then curate the Simple menu here.</div>
    @endforelse
  </div>

  <div class="bm-save">
    <button type="submit" class="bm-btn">Save booking mode</button>
  </div>
</form>

<script>
  document.querySelectorAll('.bm-mode').forEach(function(el){
    el.addEventListener('click', function(){
      document.querySelectorAll('.bm-mode').forEach(function(m){ m.classList.remove('sel'); });
      el.classList.add('sel');
      document.getElementById('bm-curate').classList.toggle('dim', el.dataset.mode === 'advanced');
    });
  });
</script>
@endsection
