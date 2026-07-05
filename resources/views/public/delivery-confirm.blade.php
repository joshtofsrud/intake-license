{{-- MARKER-PATCH-528 — public delivery window confirm page (/d/{token}) --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
  $tz = $tenant->timezone();
  $isPending = $proposal->isPending() || $proposal->status === 'no_reply'; // MARKER-PATCH-534 — link keeps working after the no-reply flag
  $deadline = $proposal->expires_at?->copy()->setTimezone($tz);
  $noun = $tenant->asset_label_singular ?: 'order'; // MARKER-PATCH-535
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $tenant->name }} — Delivery</title>
<style>
  :root { --accent: {{ $accent }}; --accent-text: {{ $accentText }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font: 15px/1.55 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #101010; color: #f0f0f0; -webkit-font-smoothing: antialiased; }
  .wrap { max-width: 460px; margin: 0 auto; padding: 40px 20px 60px; }
  .shop { font-size: 18px; font-weight: 700; margin-bottom: 26px; }
  .shop-logo { max-height: 44px; max-width: 220px; display: block; margin-bottom: 26px; } /* MARKER-PATCH-541 */
  h1 { font-size: 24px; font-weight: 700; letter-spacing: -.01em; margin-bottom: 6px; }
  .sub { font-size: 14px; opacity: .65; margin-bottom: 24px; }
  .err { background: rgba(255,90,90,.12); border: 1px solid rgba(255,90,90,.4); border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 16px; }
  .win { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; background: rgba(255,255,255,.04); border: 1.5px solid rgba(255,255,255,.12); border-radius: 12px; padding: 15px 16px; margin-bottom: 10px; color: inherit; font: inherit; cursor: pointer; }
  .win.sel { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 10%, transparent); }
  .win.full { opacity: .4; cursor: default; }
  .win input { accent-color: var(--accent); width: 17px; height: 17px; flex: none; }
  .win-t { font-weight: 600; font-size: 14.5px; }
  .win-d { font-size: 12px; opacity: .6; margin-top: 1px; }
  .win-cap { margin-left: auto; font-size: 11px; opacity: .6; white-space: nowrap; }
  .note { font-size: 12.5px; opacity: .6; margin: 14px 0 22px; }
  .btn { display: block; width: 100%; border: 0; border-radius: 12px; padding: 15px; background: var(--accent); color: var(--accent-text); font: 700 15px inherit; font-family: inherit; cursor: pointer; }
  .btn:disabled { opacity: .4; cursor: default; }
  .done { text-align: center; padding: 30px 0 10px; }
  .done-ic { width: 58px; height: 58px; border-radius: 50%; background: var(--accent); color: var(--accent-text); font-size: 26px; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; }
  .done-when { font-size: 19px; font-weight: 700; margin-top: 4px; }
  /* MARKER-PATCH-540 */
  .card { background: rgba(255,255,255,.04); border: 1.5px solid rgba(255,255,255,.12); border-radius: 14px; padding: 4px 18px; margin: 6px 0 18px; }
  .card-row { display: flex; gap: 16px; padding: 13px 0; border-bottom: 1px solid rgba(255,255,255,.08); }
  .card-k { flex: none; width: 54px; font-size: 12px; opacity: .55; padding-top: 2px; }
  .card-v { font-size: 14.5px; line-height: 1.45; }
  .btn--ghost { background: none; border: 1.5px solid rgba(255,255,255,.18); color: #f0f0f0; }
</style>
</head>
<body>
<div class="wrap">
  @php $logo = $tenant->emailLogoUrl(); @endphp
  @if($logo)
    <img class="shop-logo" src="{{ $logo }}" alt="{{ $tenant->name }}">{{-- MARKER-PATCH-541 --}}
  @else
    <div class="shop">{{ $tenant->name }}</div>
  @endif

  @if($isPending)
    <h1>Your {{ $noun }} is ready! 🎉</h1>
    <p class="sub">Pick the delivery window that works — we'll bring it to you.</p>
    @if($error)<div class="err">{{ $error }}</div>@endif

    <form method="POST" action="{{ route('tenant.delivery_confirm.save', $proposal->token) }}" id="dc-form">
      @csrf
      @foreach($windows as $w)
        <label class="win {{ $w['full'] ? 'full' : '' }}">
          <input type="radio" name="pick" value="{{ $w['window_id'] }}|{{ $w['date'] }}" {{ $w['full'] ? 'disabled' : '' }}>
          <span>
            <span class="win-t">{{ $w['human'] }}</span><br>
            <span class="win-d">{{ $w['label'] }}</span>
          </span>
          <span class="win-cap">{{ $w['full'] ? 'Full' : $w['remaining'] . ' spot' . ($w['remaining'] === 1 ? '' : 's') . ' left' }}</span>
        </label>
      @endforeach
      <input type="hidden" name="window_id" id="dc-window-id">
      <input type="hidden" name="date" id="dc-date">
      <button class="btn" type="submit" id="dc-btn" disabled>Confirm window</button>
    </form>
    <script>
      document.querySelectorAll('.win input').forEach(function (r) {
        r.addEventListener('change', function () {
          document.querySelectorAll('.win').forEach(function (w) { w.classList.remove('sel'); });
          r.closest('.win').classList.add('sel');
          var parts = r.value.split('|');
          document.getElementById('dc-window-id').value = parts[0];
          document.getElementById('dc-date').value = parts[1];
          document.getElementById('dc-btn').disabled = false;
        });
      });
    </script>

  @elseif(in_array($proposal->status, ['confirmed', 'assumed']))
    {{-- MARKER-PATCH-540 — confirmation card with add-to-calendar + next steps --}}
    @php
      $win = collect($proposal->windows)->first(fn ($w) => $w['window_id'] === $proposal->confirmed_window_id && $w['date'] === $proposal->confirmed_date?->toDateString());
      $start = $delivery?->scheduled_at?->copy()->setTimezone($tz);
      $end   = $start?->copy()->addMinutes($delivery->window_minutes ?: 60);
      $gcal  = null;
      if ($start && $end) {
          $gcal = 'https://calendar.google.com/calendar/render?' . http_build_query([
              'action'   => 'TEMPLATE',
              'text'     => $tenant->name . ' — ' . ucfirst($noun) . ' delivery',
              'dates'    => $start->format('Ymd\THis') . '/' . $end->format('Ymd\THis'),
              'details'  => 'We\'ll deliver your ' . $noun . ' during this window.',
              'location' => $delivery->address ?? '',
          ]);
      }
    @endphp
    <div class="done">
      <div class="done-ic">✓</div>
      <h1>You're all set!</h1>
      <p class="sub">Your {{ $noun }} is on the schedule</p>
    </div>
    <div class="card">
      <div class="card-row">
        <span class="card-k">When</span>
        <span class="card-v"><b>{{ $proposal->confirmed_date?->format('l, F j') }}</b><br>{{ $win['label'] ?? '' }}</span>
      </div>
      @if($delivery?->address)
        <div class="card-row">
          <span class="card-k">Where</span>
          <span class="card-v">{{ $delivery->address }}</span>
        </div>
      @endif
      <div class="card-row" style="border-bottom:0">
        <span class="card-k">From</span>
        <span class="card-v">{{ $tenant->name }}</span>
      </div>
    </div>
    @if($gcal)
      <a class="btn" style="text-align:center;text-decoration:none;margin-bottom:10px" href="{{ $gcal }}" target="_blank" rel="noopener">Add to calendar</a>
    @endif
    <a class="btn btn--ghost" style="text-align:center;text-decoration:none" href="{{ $tenant->publicUrl() }}">Back to {{ $tenant->name }}</a>
    <p class="note" style="text-align:center;margin-top:16px">Need to change it? Reply to our text or give us a call — we'll sort it out.</p>

  @else
    <h1>This link has expired</h1>
    <p class="sub">No worries — reach out to {{ $tenant->name }} and we'll get your delivery scheduled.</p>
  @endif
</div>
</body>
</html>
