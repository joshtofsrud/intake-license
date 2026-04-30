@extends('marketing.layout')
@section('title', 'Features — Intake')
@section('meta_description', 'Everything Intake includes: booking forms, CRM, work orders, page builder, payments, and more.')

@push('styles')
<style>
.mk-feat-hero{padding:clamp(48px,7vw,88px) 0 clamp(32px,5vw,56px);text-align:center;border-bottom:0.5px solid var(--mk-border)}
.mk-feature-block{display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px,5vw,72px);align-items:center;padding:clamp(40px,6vw,72px) 0;border-bottom:0.5px solid var(--mk-border)}
.mk-feature-block:last-of-type{border-bottom:none}
.mk-feature-block.reverse .mk-feature-text{order:2}
.mk-feature-block.reverse .mk-feature-visual{order:1}
.mk-feature-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--mk-accent);font-weight:600;margin-bottom:10px}
.mk-feature-h3{font-size:clamp(20px,3vw,30px);font-weight:700;letter-spacing:-.02em;margin-bottom:12px;line-height:1.2}
.mk-feature-desc{font-size:15px;color:var(--mk-muted);line-height:1.7;margin-bottom:20px}
.mk-feature-points{display:flex;flex-direction:column;gap:10px}
.mk-feature-point{font-size:14px;color:rgba(255,255,255,.65);display:flex;align-items:flex-start;gap:10px;line-height:1.5}
.mk-feature-point-dot{width:5px;height:5px;border-radius:50%;background:var(--mk-accent);flex-shrink:0;margin-top:7px}
.mk-feature-visual{background:rgba(255,255,255,.03);border:0.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:28px;min-height:220px;display:flex;flex-direction:column;gap:10px}
.mk-visual-row{background:rgba(255,255,255,.04);border-radius:7px;padding:12px 16px;border:0.5px solid var(--mk-border)}
.mk-visual-row-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px}
.mk-visual-label{font-size:12px;font-weight:600}
.mk-visual-badge{font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600}
.mk-visual-badge.green{background:rgba(190,242,100,.12);color:var(--mk-accent)}
.mk-visual-badge.blue{background:rgba(56,138,221,.15);color:#85B7EB}
.mk-visual-badge.amber{background:rgba(186,117,23,.18);color:#EF9F27}
.mk-visual-sub{font-size:11px;color:var(--mk-muted)}
.mk-visual-stat-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.mk-visual-stat{background:rgba(255,255,255,.04);border:0.5px solid var(--mk-border);border-radius:7px;padding:12px;text-align:center}
.mk-visual-stat-val{font-size:20px;font-weight:700;color:var(--mk-accent)}
.mk-visual-stat-lbl{font-size:10px;color:var(--mk-muted);margin-top:2px;text-transform:uppercase;letter-spacing:.06em}
.mk-visual-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-top:4px}
.mk-cal-day{aspect-ratio:1;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--mk-muted)}
.mk-cal-day.avail{background:rgba(190,242,100,.12);color:var(--mk-accent);font-weight:600}
.mk-cal-day.sel{background:var(--mk-accent);color:var(--mk-accent-text);font-weight:700}
@media(max-width:760px){.mk-feature-block{grid-template-columns:1fr}.mk-feature-block.reverse .mk-feature-text{order:1}.mk-feature-block.reverse .mk-feature-visual{order:2}}
</style>
@endpush

@section('content')

<section class="mk-feat-hero">
  <div class="mk-container">
    <div class="mk-eyebrow">Features</div>
    <h1 class="mk-section-title" style="font-size:clamp(28px,5vw,52px);max-width:580px;margin:0 auto 12px">
      Everything a service shop needs
    </h1>
    <p class="mk-section-sub" style="margin:0 auto">
      Intake is purpose-built for shops that do work — not just sell things.
    </p>
  </div>
</section>

<div class="mk-container">

  {{-- Booking form --}}
  <div class="mk-feature-block">
    <div class="mk-feature-text">
      <div class="mk-feature-eyebrow">Booking form</div>
      <h2 class="mk-feature-h3">A booking experience customers love</h2>
      <p class="mk-feature-desc">Your booking form lives at your own URL — branded with your colors, logo, and fonts. Customers pick their service, choose a date, fill in details, and pay. All in one seamless flow.</p>
      <div class="mk-feature-points">
        @foreach(['Multi-step form with progress indicator','Service catalog with tier pricing (Standard, Full Service, Rush)','Availability calendar based on your capacity rules','Stripe and PayPal payment at booking','Custom form fields for bike details, ski specs, and more'] as $pt)
          <div class="mk-feature-point"><div class="mk-feature-point-dot"></div>{{ $pt }}</div>
        @endforeach
      </div>
    </div>
    <div class="mk-feature-visual">
      <div style="font-size:11px;color:var(--mk-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.07em">Booking form preview</div>
      <div style="display:flex;gap:6px;margin-bottom:12px">
        @foreach(['Services','Schedule','Details','Review'] as $i => $s)
          <div style="display:flex;align-items:center;gap:5px;font-size:11px;{{ $i === 0 ? 'color:var(--mk-accent)' : 'color:var(--mk-muted)' }}">
            <div style="width:16px;height:16px;border-radius:50%;{{ $i === 0 ? 'background:var(--mk-accent);color:var(--mk-accent-text)' : 'border:0.5px solid var(--mk-border2);color:var(--mk-muted)' }};display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700">{{ $i+1 }}</div>
            {{ $s }}
          </div>
          @if(!$loop->last)<div style="flex:1;height:0.5px;background:var(--mk-border);margin-top:8px"></div>@endif
        @endforeach
      </div>
      @foreach(['Fox 36 GRIP2 Full Service — $180','Brake bleed (pair) — $65','Drivetrain clean + lube — $45'] as $item)
        <div class="mk-visual-row">
          <div class="mk-visual-row-head">
            <span class="mk-visual-label" style="font-size:12px">{{ $item }}</span>
            <span class="mk-visual-badge green">Selected</span>
          </div>
        </div>
      @endforeach
      <div style="background:var(--mk-accent);border-radius:7px;padding:10px;text-align:center;font-size:13px;font-weight:700;color:var(--mk-accent-text);margin-top:4px">Continue → Schedule</div>
    </div>
  </div>

  {{-- Work orders --}}
  <div class="mk-feature-block reverse">
    <div class="mk-feature-visual">
      <div style="font-size:11px;color:var(--mk-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.07em">Work orders today</div>
      <div class="mk-visual-stat-grid" style="margin-bottom:12px">
        <div class="mk-visual-stat"><div class="mk-visual-stat-val">8</div><div class="mk-visual-stat-lbl">Open</div></div>
        <div class="mk-visual-stat"><div class="mk-visual-stat-val">3</div><div class="mk-visual-stat-lbl">Ready</div></div>
        <div class="mk-visual-stat"><div class="mk-visual-stat-val" style="color:#85B7EB">$1,240</div><div class="mk-visual-stat-lbl">Today</div></div>
      </div>
      @foreach([['SPK-A3F9','Jane Smith','In progress','amber'],['SPK-B2E8','Tom Lee','Ready','green'],['SPK-C1D7','Mia Park','Confirmed','blue']] as $row)
        <div class="mk-visual-row">
          <div class="mk-visual-row-head">
            <span class="mk-visual-label">{{ $row[0] }} · {{ $row[1] }}</span>
            <span class="mk-visual-badge {{ $row[3] }}">{{ $row[2] }}</span>
          </div>
        </div>
      @endforeach
    </div>
    <div class="mk-feature-text">
      <div class="mk-feature-eyebrow">Work orders</div>
      <h2 class="mk-feature-h3">Every job tracked from drop-off to pickup</h2>
      <p class="mk-feature-desc">Each booking creates a work order with a unique reference number. Move it through statuses, add charges for unexpected parts, and leave notes for your team.</p>
      <div class="mk-feature-points">
        @foreach(['Unique RA reference number per job','Status flow: Pending → Confirmed → In Progress → Completed → Closed','Add extra charges mid-job','Internal staff notes (never shown to customers)','Payment status tracking — unpaid, partial, paid'] as $pt)
          <div class="mk-feature-point"><div class="mk-feature-point-dot"></div>{{ $pt }}</div>
        @endforeach
      </div>
    </div>
  </div>

  {{-- Customer CRM --}}
  <div class="mk-feature-block">
    <div class="mk-feature-text">
      <div class="mk-feature-eyebrow">Customer CRM</div>
      <h2 class="mk-feature-h3">Know every customer, every time</h2>
      <p class="mk-feature-desc">Intake automatically builds a customer profile every time someone books. See their history, total spend, and last service date at a glance.</p>
      <div class="mk-feature-points">
        @foreach(['Auto-created from every booking','Full booking history per customer','Lifetime spend and visit count','200-character notes (never visible to customers)','Search by name, email, or phone'] as $pt)
          <div class="mk-feature-point"><div class="mk-feature-point-dot"></div>{{ $pt }}</div>
        @endforeach
      </div>
    </div>
    <div class="mk-feature-visual">
      <div style="font-size:11px;color:var(--mk-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.07em">Customer profile</div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <div style="width:40px;height:40px;border-radius:50%;background:var(--mk-accent-dim);border:0.5px solid rgba(190,242,100,.25);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--mk-accent)">JS</div>
        <div>
          <div style="font-size:14px;font-weight:600">Jane Smith</div>
          <div style="font-size:12px;color:var(--mk-muted)">jane@example.com</div>
        </div>
      </div>
      <div class="mk-visual-stat-grid">
        <div class="mk-visual-stat"><div class="mk-visual-stat-val">12</div><div class="mk-visual-stat-lbl">Visits</div></div>
        <div class="mk-visual-stat"><div class="mk-visual-stat-val" style="color:#85B7EB">$940</div><div class="mk-visual-stat-lbl">Spent</div></div>
        <div class="mk-visual-stat"><div class="mk-visual-stat-val" style="font-size:13px">Nov 3</div><div class="mk-visual-stat-lbl">Last visit</div></div>
      </div>
    </div>
  </div>

  {{-- Capacity --}}
  <div class="mk-feature-block reverse">
    <div class="mk-feature-visual">
      <div style="font-size:11px;color:var(--mk-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.07em">Pick a date</div>
      <div class="mk-visual-cal">
        @foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d)
          <div style="font-size:9px;color:var(--mk-dim);text-align:center;padding:2px 0;text-transform:uppercase">{{ $d }}</div>
        @endforeach
        @php $days = [null,null,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30]; $avail=[4,5,7,8,11,12,14,18,19,21,22,25,26]; $sel=14; @endphp
        @foreach($days as $day)
          @if($day === null)
            <div></div>
          @else
            <div class="mk-cal-day {{ $day === $sel ? 'sel' : (in_array($day,$avail) ? 'avail' : '') }}">{{ $day }}</div>
          @endif
        @endforeach
      </div>
    </div>
    <div class="mk-feature-text">
      <div class="mk-feature-eyebrow">Capacity management</div>
      <h2 class="mk-feature-h3">Never get overbooked again</h2>
      <p class="mk-feature-desc">Set how many appointments you accept per day of the week. Override specific dates for holidays or high-demand periods. Customers only see days with remaining capacity.</p>
      <div class="mk-feature-points">
        @foreach(['Per-day-of-week booking limits','Date overrides for holidays and special days','Minimum notice requirement (e.g. no same-day bookings)','Booking window control (e.g. up to 60 days ahead)'] as $pt)
          <div class="mk-feature-point"><div class="mk-feature-point-dot"></div>{{ $pt }}</div>
        @endforeach
      </div>
    </div>
  </div>

</div>

{{-- CTA --}}
<section class="mk-section" style="text-align:center;border-bottom:none">
  <div class="mk-container">
    <h2 class="mk-section-title" style="margin-bottom:8px">See it in action</h2>
    <p class="mk-section-sub" style="margin:0 auto 28px">Free 14-day trial. No credit card required.</p>
    <a href="{{ route('platform.signup') }}" class="mk-btn mk-btn--primary">Start your free trial →</a>
  </div>
</section>

@endsection
