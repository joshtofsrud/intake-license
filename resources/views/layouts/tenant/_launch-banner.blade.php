{{-- MARKER-LAUNCH-CHECKLIST: loud on every page while a live store or booking page has a broken
     required step or is paused; a quiet line on the dashboard while required steps are left. --}}
@php
  $lbT = $currentTenant ?? null;
  $lbA = [];
  try { $lbA = $lbT ? \App\Support\Launch\Launch::attention($lbT) : []; } catch (\Throwable $e) { report($e); }
  $lbDash = request()->routeIs('tenant.dashboard');
@endphp
@foreach($lbA as $lbMod => $a)
  @php
    $lbPaused = $a['public'] === 'paused';
    $lbWhat = $lbMod === 'rent' ? 'Online booking' : 'Checkout';
  @endphp
  @if($lbPaused || $a['broken'])
    <div class="ia-standing is-urgent" style="margin-bottom:14px">
      <span class="ia-standing-dot"></span>
      <div class="ia-standing-txt">
        <b>{{ $a['label'] }}: {{ $a['broken'] ? $a['broken'][0] . ' stopped being true.' : 'paused by Intake.' }}</b>
        @if($lbPaused)
          {{ $lbWhat }} is paused{{ $a['broken'] ? ' until it passes again, then reopens by itself' : '' }}. Visitors can still look; orders and bookings already made are not touched.
        @else
          Customers may not be able to finish.
        @endif
        <a class="ia-standing-cta" href="{{ $a['home'] }}">{{ $a['broken'] ? 'Fix it' : 'See why' }}</a>
      </div>
    </div>
  @elseif($lbDash && $a['nudge'] && $a['public'] === 'preview' && $a['checked'] && $a['req_open'])
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:0 0 18px;padding:12px 0;border-bottom:.5px solid var(--ia-border)">
      <div style="flex:1;min-width:220px">
        <div style="font-size:13.5px;font-weight:500">Finish setting up {{ strtolower($a['label']) }}</div>
        <div style="font-size:12px;color:var(--ia-text-muted);line-height:1.55">
          {{ count($a['req_open']) }} required {{ count($a['req_open']) === 1 ? 'step' : 'steps' }} left before you can go live: {{ strtolower(implode(', ', array_slice($a['req_open'], 0, 4))) }}{{ count($a['req_open']) > 4 ? '…' : '' }}.
        </div>
      </div>
      <a class="ia-btn ia-btn--primary ia-btn--sm" href="{{ $a['home'] }}">Open the checklist</a>
    </div>
  @endif
@endforeach
