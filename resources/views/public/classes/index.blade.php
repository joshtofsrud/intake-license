@extends('public.account._shell')
@php $pageTitle = 'Classes'; @endphp

@push('styles')
<style>
.cl-week-nav{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.cl-week-label{font-size:15px;font-weight:600;flex:1;text-align:center}
.cl-week-btn{width:32px;height:32px;border-radius:var(--p-r);border:1.5px solid var(--p-border);background:transparent;color:var(--p-text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:all .15s}
.cl-week-btn:hover{border-color:var(--p-text)}
.cl-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px}
.cl-filter{padding:5px 14px;border-radius:20px;font-size:13px;border:1.5px solid var(--p-border);background:transparent;color:var(--p-text);cursor:pointer;transition:all .15s;text-decoration:none;opacity:.7}
.cl-filter:hover{opacity:1;border-color:var(--p-text)}
.cl-filter.active{background:var(--p-accent);color:var(--p-accent-text);border-color:var(--p-accent);opacity:1}
.cl-date-group{margin-bottom:24px}
.cl-date-label{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid var(--p-border)}
.cl-card{border:1.5px solid var(--p-border);border-radius:var(--p-r-lg);padding:16px;margin-bottom:10px;cursor:pointer;transition:all .15s;text-decoration:none;display:block;color:var(--p-text)}
.cl-card:hover{border-color:var(--p-accent);background:rgba(0,0,0,.02)}
.cl-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.cl-card-name{font-size:16px;font-weight:600}
.cl-card-instructor{font-size:13px;opacity:.55;margin-top:2px}
.cl-card-time{font-size:13px;font-weight:500;opacity:.7;white-space:nowrap}
.cl-card-footer{display:flex;align-items:center;gap:10px}
.cl-cap-wrap{display:flex;align-items:center;gap:8px;flex:1}
.cl-cap-bar{flex:1;height:3px;background:var(--p-border);border-radius:2px;overflow:hidden}
.cl-cap-fill{height:100%;border-radius:2px;transition:width .3s}
.cl-cap-fill.low{background:#639922}
.cl-cap-fill.med{background:#BA7517}
.cl-cap-fill.high{background:#E24B4A}
.cl-cap-text{font-size:12px;opacity:.5;white-space:nowrap}
.cl-pill{display:inline-flex;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500}
.cl-pill--free{background:#EAF3DE;color:#3B6D11}
.cl-pill--full{background:#FCEBEB;color:#A32D2D}
.cl-pill--waitlist{background:#FAEEDA;color:#633806}
.cl-price{font-size:14px;font-weight:600}
.cl-empty{padding:48px;text-align:center;opacity:.45;font-size:15px}
</style>
@endpush

@section('content')

<div style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between">
  <div>
    <div style="font-size:22px;font-weight:700;font-family:var(--p-font-heading)">Classes</div>
    <div style="font-size:14px;opacity:.5;margin-top:2px">{{ $currentTenant->name }}</div>
  </div>
  @if(!Auth::guard('customer')->check())
    <a href="{{ route('tenant.customer.login') }}" class="ac-btn ac-btn--ghost" style="padding:8px 16px;border-radius:var(--p-r);font-size:13px;font-weight:600;border:1.5px solid var(--p-border)">Sign in</a>
  @endif
</div>

{{-- Week nav --}}
@php
  $prevFrom = $from->copy()->subDays(7)->format('Y-m-d');
  $nextFrom = $from->copy()->addDays(7)->format('Y-m-d');
@endphp
<div class="cl-week-nav">
  <a href="{{ request()->fullUrlWithQuery(['from' => $prevFrom]) }}" class="cl-week-btn">‹</a>
  <div class="cl-week-label">{{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</div>
  <a href="{{ request()->fullUrlWithQuery(['from' => $nextFrom]) }}" class="cl-week-btn">›</a>
</div>

{{-- Filters --}}
<div class="cl-filters">
  <a href="{{ request()->fullUrlWithQuery(['template' => null]) }}"
     class="cl-filter {{ !$templateFilter ? 'active' : '' }}">All</a>
  @foreach($templates as $t)
    <a href="{{ request()->fullUrlWithQuery(['template' => $t->id]) }}"
       class="cl-filter {{ $templateFilter === $t->id ? 'active' : '' }}">{{ $t->name }}</a>
  @endforeach
</div>

{{-- Sessions grouped by date --}}
@php
  $grouped = $sessions->groupBy(fn($s) => $s->starts_at->format('Y-m-d'));
@endphp

@forelse($grouped as $date => $daySessions)
  <div class="cl-date-group">
    <div class="cl-date-label">{{ \Carbon\Carbon::parse($date)->format('l, M j') }}</div>
    @foreach($daySessions as $session)
      @php
        $active = $session->active_registrations_count;
        $cap    = $session->capacity_snapshot;
        $pct    = $cap > 0 ? min(100, round($active / $cap * 100)) : 0;
        $isFull = $pct >= 100;
        $capClass = $pct >= 100 ? 'high' : ($pct >= 75 ? 'med' : 'low');
      @endphp
      <a href="{{ route('tenant.customer.classes.show', ['id' => $session->id]) }}"
         class="cl-card">
        <div class="cl-card-head">
          <div>
            <div class="cl-card-name">{{ $session->template->name }}</div>
            <div class="cl-card-instructor">
              {{ $session->instructor_snapshot ?? $session->instructorResource?->name ?? 'No instructor' }}
              · {{ $session->template->duration_minutes }}min
            </div>
          </div>
          <div class="cl-card-time">{{ tlocal($session->starts_at) }}</div>
        </div>
        <div class="cl-card-footer">
          @if($isFull)
            <span class="cl-pill cl-pill--full">Full — join waitlist</span>
          @else
            <div class="cl-cap-wrap">
              <div class="cl-cap-bar">
                <div class="cl-cap-fill {{ $capClass }}" style="width:{{ $pct }}%"></div>
              </div>
              <span class="cl-cap-text">{{ $cap - $active }} spot{{ $cap - $active === 1 ? '' : 's' }} left</span>
            </div>
            @if($session->template->price_cents > 0)
              <span class="cl-price">${{ number_format($session->template->price_cents / 100, 2) }}</span>
            @else
              <span class="cl-pill cl-pill--free">Free</span>
            @endif
          @endif
        </div>
      </a>
    @endforeach
  </div>
@empty
  <div class="cl-empty">No classes scheduled this week.</div>
@endforelse

@endsection
