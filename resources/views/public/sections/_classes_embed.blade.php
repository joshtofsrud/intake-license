@php
  use App\Models\Tenant\TenantClassSession;
  use App\Models\Tenant\TenantClassTemplate;
  use Carbon\Carbon;

  $weeksAhead = (int) ($c['weeks_ahead'] ?? 2);
  $from = Carbon::now()->startOfWeek();
  $to   = $from->copy()->addWeeks($weeksAhead)->endOfDay();

  $embedSessions = TenantClassSession::where('tenant_id', $tenant->id)
      ->whereIn('status', ['scheduled', 'confirmed'])
      ->whereBetween('starts_at', [$from, $to])
      ->with(['template', 'instructorResource'])
      ->withCount('activeRegistrations')
      ->orderBy('starts_at')
      ->get();

  $embedTemplates = ($c['show_filters'] ?? true)
      ? TenantClassTemplate::where('tenant_id', $tenant->id)->active()->orderBy('name')->get()
      : collect();
@endphp

<section class="p-section" id="classes">
  <div class="p-container">
    @if(!empty($c['heading']))
      <div class="p-section-head-wrap">
        <h2 class="p-section-heading">{{ $c['heading'] }}</h2>
      </div>
    @endif

    <style>
    .ce-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px}
    .ce-filter{padding:5px 14px;border-radius:20px;font-size:13px;border:1.5px solid rgba(0,0,0,.12);background:transparent;color:var(--p-text);cursor:pointer;transition:all .15s;text-decoration:none;opacity:.7}
    .ce-filter:hover{opacity:1}
    .ce-filter.active{background:var(--p-accent);color:var(--p-accent-text);border-color:var(--p-accent);opacity:1}
    .ce-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
    .ce-card{border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg);padding:18px;transition:all .15s;text-decoration:none;display:block;color:var(--p-text)}
    .ce-card:hover{border-color:var(--p-accent);transform:translateY(-1px)}
    .ce-date{font-size:12px;font-weight:500;opacity:.5;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
    .ce-name{font-size:17px;font-weight:700;font-family:var(--p-font-heading);margin-bottom:4px}
    .ce-meta{font-size:13px;opacity:.55;margin-bottom:12px}
    .ce-footer{display:flex;align-items:center;justify-content:space-between}
    .ce-cap-wrap{display:flex;align-items:center;gap:8px;flex:1}
    .ce-cap-bar{flex:1;height:3px;background:rgba(0,0,0,.1);border-radius:2px;overflow:hidden}
    .ce-cap-fill{height:100%;border-radius:2px}
    .ce-cap-fill.low{background:#639922}
    .ce-cap-fill.med{background:#BA7517}
    .ce-cap-fill.high{background:#E24B4A}
    .ce-cap-text{font-size:12px;opacity:.5;white-space:nowrap}
    .ce-price{font-size:14px;font-weight:600}
    .ce-pill{display:inline-flex;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500}
    .ce-pill--free{background:#EAF3DE;color:#3B6D11}
    .ce-pill--full{background:#FCEBEB;color:#A32D2D}
    .ce-empty{padding:48px;text-align:center;border:1.5px dashed rgba(0,0,0,.1);border-radius:var(--p-r-lg)}
    .ce-empty-text{font-size:16px;opacity:.4}
    .ce-week-label{font-size:13px;font-weight:600;opacity:.4;text-transform:uppercase;letter-spacing:.07em;margin:20px 0 10px;padding-bottom:8px;border-bottom:1px solid rgba(0,0,0,.08)}
    </style>

    @if($embedTemplates->isNotEmpty())
      <div class="ce-filters">
        <a href="#classes" class="ce-filter active" onclick="ceFilter(null,this)">All</a>
        @foreach($embedTemplates as $t)
          <a href="#classes" class="ce-filter" onclick="ceFilter('{{ $t->id }}',this)">{{ $t->name }}</a>
        @endforeach
      </div>
    @endif

    @php $grouped = $embedSessions->groupBy(fn($s) => $s->starts_at->format('Y-m-d')); @endphp

    @if($embedSessions->isEmpty())
      <div class="ce-empty">
        <div class="ce-empty-text">No classes scheduled in the next {{ $weeksAhead }} week{{ $weeksAhead > 1 ? 's' : '' }}.</div>
      </div>
    @else
      @foreach($grouped as $date => $daySessions)
        <div class="ce-week-label">{{ \Carbon\Carbon::parse($date)->format('l, M j') }}</div>
        <div class="ce-grid" id="ce-grid-{{ \Carbon\Carbon::parse($date)->format('Ymd') }}">
          @foreach($daySessions as $session)
            @php
              $active = $session->active_registrations_count;
              $cap    = $session->capacity_snapshot;
              $pct    = $cap > 0 ? min(100, round($active / $cap * 100)) : 0;
              $isFull = $pct >= 100;
              $capClass = $pct >= 100 ? 'high' : ($pct >= 75 ? 'med' : 'low');
            @endphp
            <a href="{{ route('tenant.customer.classes.show', ['subdomain' => $tenant->subdomain, 'id' => $session->id]) }}"
               class="ce-card"
               data-template="{{ $session->class_template_id }}">
              <div class="ce-date">{{ $session->starts_at->format('g:i A') }}</div>
              <div class="ce-name">{{ $session->template->name }}</div>
              <div class="ce-meta">
                {{ $session->instructor_snapshot ?? $session->instructorResource?->name ?? '' }}
                @if($session->instructor_snapshot || $session->instructorResource)·@endif
                {{ $session->template->duration_minutes }}min
              </div>
              <div class="ce-footer">
                @if($isFull)
                  <span class="ce-pill ce-pill--full">Full — join waitlist</span>
                @else
                  <div class="ce-cap-wrap">
                    <div class="ce-cap-bar">
                      <div class="ce-cap-fill {{ $capClass }}" style="width:{{ $pct }}%"></div>
                    </div>
                    <span class="ce-cap-text">{{ $cap - $active }} left</span>
                  </div>
                  @if($session->template->price_cents > 0)
                    <span class="ce-price">${{ number_format($session->template->price_cents / 100, 2) }}</span>
                  @else
                    <span class="ce-pill ce-pill--free">Free</span>
                  @endif
                @endif
              </div>
            </a>
          @endforeach
        </div>
      @endforeach
    @endif

    <div style="text-align:center;margin-top:28px">
      <a href="{{ route('tenant.customer.classes', ['subdomain' => $tenant->subdomain]) }}" class="p-btn p-btn--outline">View full schedule</a>
    </div>
  </div>
</section>

<script>
function ceFilter(templateId, btn) {
  document.querySelectorAll('.ce-filter').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.querySelectorAll('.ce-card').forEach(function(card){
    card.style.display = (!templateId || card.dataset.template === templateId) ? '' : 'none';
  });
  return false;
}
</script>
