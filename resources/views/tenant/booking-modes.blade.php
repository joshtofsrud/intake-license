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

{{-- MARKER-PATCH-521 — P&D settings promoted above the mode form --}}
{{-- MARKER-PATCH-510 — Pickup & delivery (route windows + behavior) --}}
@if($currentTenant->deliveries_enabled)
<div style="margin-top:36px;border-top:0.5px solid var(--ia-border);padding-top:26px;max-width:760px">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 4px">Pickup &amp; delivery</h2>
  <p style="font-size:12.5px;color:var(--ia-text-muted);margin:0 0 18px">Route windows are the capacity customers book pickups against — pickups and deliveries share each window's stop count. Booking-flow integration is coming next; these settings take effect then.</p>

  {{-- windows list --}}
  <div style="border:0.5px solid var(--ia-border);border-radius:15px;background:var(--ia-surface);overflow:hidden;margin-bottom:16px">
    <div style="padding:14px 18px;border-bottom:0.5px solid var(--ia-border)">
      <div style="font-size:14px;font-weight:600">Route windows</div>
      <div style="font-size:12px;color:var(--ia-text-muted)">When you run routes, and how many stops fit in each window.</div>
    </div>
    <div style="padding:12px 18px">
      @forelse($routeWindows as $w)
        <div style="display:flex;align-items:center;gap:12px;padding:9px 12px;border:0.5px solid var(--ia-border);border-radius:10px;margin-bottom:7px;{{ $w->is_active ? '' : 'opacity:.45' }}">
          <span style="font-weight:600;font-size:13px;min-width:88px">{{ $w->label }}</span>
          <span style="font-size:11.5px;color:var(--ia-text-muted)">
            {{ collect($w->days)->map(fn($d) => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][$d-1])->join(', ') }}
          </span>
          <form method="POST" action="{{ route('tenant.route_windows.update', $w->id) }}" style="margin-left:auto;display:flex;align-items:center;gap:8px">
            @csrf @method('PATCH')
            <input type="number" name="max_stops" value="{{ $w->max_stops }}" min="1" max="50"
                   style="width:58px;padding:6px 8px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:7px;color:var(--ia-text);font-size:12.5px;text-align:center">
            <span style="font-size:11px;color:var(--ia-text-muted)">stops</span>
            <button class="ia-btn ia-btn--ghost ia-btn--sm">Save</button>
          </form>
          <form method="POST" action="{{ route('tenant.route_windows.destroy', $w->id) }}"
                onsubmit="return confirm('Remove the {{ $w->label }} window?')">
            @csrf @method('DELETE')
            <button class="ia-btn ia-btn--ghost ia-btn--sm" style="color:var(--ia-text-muted)">×</button>
          </form>
        </div>
      @empty
        <div style="font-size:12.5px;color:var(--ia-text-muted);padding:6px 0 10px">No windows yet — add your first below.</div>
      @endforelse

      <form method="POST" action="{{ route('tenant.route_windows.store') }}"
            style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:0.5px dashed var(--ia-border);padding-top:12px;margin-top:6px">
        @csrf
        <input type="text" name="label" placeholder="8–10 am" required maxlength="40"
               style="width:100px;padding:7px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:12.5px">
        <input type="time" name="starts_at" required
               style="padding:6px 8px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:12.5px">
        <span style="color:var(--ia-text-muted);font-size:12px">to</span>
        <input type="time" name="ends_at" required
               style="padding:6px 8px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:12.5px">
        <span style="display:flex;gap:4px">
          @foreach(['M','T','W','T','F','S','S'] as $i => $d)
            <label style="font-size:10.5px;color:var(--ia-text-muted);display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer">
              {{ $d }}<input type="checkbox" name="days[]" value="{{ $i + 1 }}" @checked($i < 6)>
            </label>
          @endforeach
        </span>
        <input type="number" name="max_stops" value="3" min="1" max="50"
               style="width:52px;padding:7px 8px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:12.5px;text-align:center">
        <span style="font-size:11px;color:var(--ia-text-muted)">stops</span>
        <button class="ia-btn ia-btn--primary ia-btn--sm">Add window</button>
      </form>
    </div>
  </div>

  {{-- behavior knobs --}}
  <form method="POST" action="{{ route('tenant.route_windows.settings') }}"
        style="border:0.5px solid var(--ia-border);border-radius:15px;background:var(--ia-surface);padding:16px 18px">
    @csrf @method('PATCH')
    <div style="font-size:14px;font-weight:600;margin-bottom:2px">Behavior</div>
    <div style="font-size:12px;color:var(--ia-text-muted);margin-bottom:14px">How booking, payment, and the Ready step behave.</div>

    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:14px">
      <label style="font-size:12px;color:var(--ia-text-muted)">Booking flavor
        <select name="pd_flavor" style="display:block;margin-top:5px;padding:8px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:13px">
          <option value="queue" @selected($pd['flavor'] === 'queue')>Queue — customer books pickup only</option>
          <option value="anchored" @selected($pd['flavor'] === 'anchored')>Anchored — customer picks the service day</option>
        </select>
      </label>
      <label style="font-size:12px;color:var(--ia-text-muted)">Windows offered at Ready
        <input type="number" name="pd_windows_offered" min="1" max="6" value="{{ $pd['windows_offered'] }}"
               style="display:block;margin-top:5px;width:90px;padding:8px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:13px">
      </label>
      <label style="font-size:12px;color:var(--ia-text-muted)">Assume first window if no reply by (hour, 24h)
        <input type="number" name="pd_assume_first_hour" min="12" max="23" value="{{ $pd['assume_first_hour'] }}"
               style="display:block;margin-top:5px;width:90px;padding:8px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:13px">
      </label>
      <label style="font-size:12px;color:var(--ia-text-muted)">Pickup up to N days before the service date
        {{-- MARKER-PATCH-520 --}}
        <input type="number" name="pd_pickup_lead_days" min="0" max="7"
               value="{{ (int) (((array) ($currentTenant->settings ?? []))['pd_pickup_lead_days'] ?? 1) }}"
               style="display:block;margin-top:5px;width:90px;padding:8px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:13px">
      </label>
      <label style="font-size:12px;color:var(--ia-text-muted)">Turnaround shown at booking
        <input type="text" name="pd_turnaround_label" maxlength="30" value="{{ $pd['turnaround'] }}"
               style="display:block;margin-top:5px;width:120px;padding:8px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-size:13px">
      </label>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
      <label style="font-size:13px;display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="pd_auto_propose" value="1" @checked($pd['auto_propose'])>
        Text delivery windows automatically when work hits <b>Ready</b>
      </label>
      <label style="font-size:13px;display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="pd_online_pay_at_booking" value="1" @checked($pd['online_pay'])>
        Online bookings pay at booking <span style="color:var(--ia-text-muted);font-size:11.5px">(staff bookings always choose)</span>
      </label>
      <label style="font-size:13px;display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="pd_pay_before_delivery" value="1" @checked($pd['pay_before'])>
        Require any remaining balance paid before delivery <span style="color:var(--ia-text-muted);font-size:11.5px">(off = collect at the door)</span>
      </label>
      <label style="font-size:13px;display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="pd_need_by_enabled" value="1" @checked($pd['need_by'])>
        Allow customers to add a "need it by" date
      </label>
    </div>

    <button class="ia-btn ia-btn--primary ia-btn--sm">Save pickup &amp; delivery settings</button>
  </form>
</div>
@endif

{{-- MARKER-PATCH-516 styles --}}
<style>
  .bm-acc{border:0.5px solid var(--ia-border);border-radius:12px;background:var(--ia-surface-2);margin-bottom:10px;overflow:hidden}
  .bm-acc-h{display:flex;align-items:center;gap:10px;width:100%;padding:12px 16px;background:none;border:0;cursor:pointer;color:var(--ia-text);font-family:inherit;text-align:left}
  .bm-acc-t{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
  .bm-acc-badge{font-size:10.5px;color:var(--ia-accent);background:var(--ia-accent-soft);border:0.5px solid var(--ia-accent);border-radius:99px;padding:1px 9px}
  .bm-acc-badge.zero{color:var(--ia-text-muted);background:var(--ia-surface);border-color:var(--ia-border)}
  .bm-acc-chev{margin-left:auto;color:var(--ia-text-muted);font-size:11px;transition:transform .15s}
  .bm-acc.open .bm-acc-chev{transform:rotate(180deg)}
  .bm-acc-b{display:none;border-top:0.5px solid var(--ia-border);padding:2px 16px 8px}
  .bm-acc.open .bm-acc-b{display:block}
  /* MARKER-PATCH-521 — hard-clear the legacy card box on accordion rows */
  .bm-acc .bm-item{display:flex;align-items:center;gap:12px;padding:9px 0;border:0;border-bottom:0.5px solid var(--ia-border);background:transparent;border-radius:0;margin:0;box-shadow:none}
  .bm-acc .bm-item:last-child{border-bottom:0}
  .bm-acc-b{padding-bottom:6px}
  .bm-save--sticky{margin-top:18px}
  .bm-acc .bm-item:last-child{border-bottom:none}
  .bm-chk{width:16px;height:16px;accent-color:var(--ia-accent);flex:none}
  .bm-acc .bm-item-main{flex:1;min-width:0}
  .bm-acc .bm-item-fields{display:none;align-items:center;gap:10px}
  .bm-acc .bm-item.checked .bm-item-fields{display:flex}
  .bm-save--sticky{position:sticky;bottom:14px;display:flex;align-items:center;gap:14px;background:var(--ia-surface);border:0.5px solid var(--ia-border-2,var(--ia-border));border-radius:12px;padding:11px 16px;box-shadow:0 10px 30px rgba(0,0,0,.45);z-index:5}
  .bm-save-note{font-size:12px;color:var(--ia-text-muted)}
  .bm-save-note b{color:var(--ia-accent)}
</style>
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

    {{-- MARKER-PATCH-516 — collapse-in-place: accordions, badges, checked-only fields --}}
    @forelse($categories as $cat)
      @if($cat->items->count())
        @php $bmShown = $cat->items->where('simple_enabled', true)->count(); @endphp
        <div class="bm-acc {{ $bmShown ? 'open' : '' }}">
          <button type="button" class="bm-acc-h" onclick="this.parentElement.classList.toggle('open')">
            <span class="bm-acc-t">{{ $cat->name }}</span>
            <span class="bm-acc-badge {{ $bmShown ? '' : 'zero' }}" data-cat-badge>{{ $bmShown }} of {{ $cat->items->count() }} shown</span>
            <span class="bm-acc-chev">▾</span>
          </button>
          <div class="bm-acc-b">
            @foreach($cat->items as $item)
              <div class="bm-item {{ $item->simple_enabled ? 'checked' : '' }}">
                <input type="checkbox" class="bm-chk" name="items[{{ $item->id }}][simple_enabled]" value="1" {{ $item->simple_enabled ? 'checked' : '' }} aria-label="Show {{ $item->name }} in Simple menu">
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
          </div>
        </div>
      @endif
    @empty
      <div class="bm-empty">No services yet. Add services first, then curate the Simple menu here.</div>
    @endforelse
  </div>

  {{-- MARKER-PATCH-516 — sticky save with live shown count --}}
  <div class="bm-save bm-save--sticky">
    <button type="submit" class="bm-btn">Save booking mode</button>
    <span class="bm-save-note"><b id="bm-shown-count">0</b> services shown to customers</span>
  </div>

  <script>
  (function () {
    // MARKER-PATCH-516 — checked-row fields, per-category badges, live count
    function refresh() {
      var total = 0;
      document.querySelectorAll('.bm-acc').forEach(function (acc) {
        var rows = acc.querySelectorAll('.bm-item');
        var shown = 0;
        rows.forEach(function (row) {
          var on = row.querySelector('.bm-chk').checked;
          row.classList.toggle('checked', on);
          if (on) shown++;
        });
        total += shown;
        var badge = acc.querySelector('[data-cat-badge]');
        if (badge) {
          badge.textContent = shown + ' of ' + rows.length + ' shown';
          badge.classList.toggle('zero', shown === 0);
        }
      });
      var count = document.getElementById('bm-shown-count');
      if (count) count.textContent = total;
    }
    document.querySelectorAll('.bm-chk').forEach(function (cb) {
      cb.addEventListener('change', refresh);
    });
    refresh();
  })();
  </script>
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
