{{--
  MARKER-RENTAL-SECTIONS — rental_browse public render. The /rentals
  browse experience as an embeddable section: window picker (GET back to
  the same page, #rental-browse anchor) + grouped live availability.
  Same RentalAvailabilityService the reserve lock re-verifies.
--}}
@php
  $rbTenant = $tenant ?? $currentTenant ?? null;
  $rbGroups = []; $rbStart = null; $rbDue = null; $rbError = null; $rbCount = 0;
  if ($rbTenant && $rbTenant->rentals_visible) {
      $rbTz = $rbTenant->timezone();
      $rbDefStart = \Carbon\Carbon::now($rbTz)->addDay()->setTime(9, 0);
      $rbDefDue   = \Carbon\Carbon::now($rbTz)->addDay()->setTime(17, 0);
      try {
          $rbStart = request()->filled('starts') ? \Carbon\Carbon::parse(request('starts'), $rbTz) : $rbDefStart;
          $rbDue   = request()->filled('due')    ? \Carbon\Carbon::parse(request('due'), $rbTz)    : $rbDefDue;
      } catch (\Throwable $e) {
          $rbStart = $rbDefStart; $rbDue = $rbDefDue;
          $rbError = 'That date didn\'t parse — showing tomorrow instead.';
      }
      if ($rbDue->lessThanOrEqualTo($rbStart)) { $rbDue = $rbStart->copy()->addHours(4); $rbError = 'Return time must be after pickup — adjusted it for you.'; }

      $rbUnits = app(\App\Services\RentalAvailabilityService::class)->availableUnits(
          $rbTenant->id, null, $rbStart->copy()->utc(), $rbDue->copy()->utc(), onlineOnly: true,
      );
      $rbCount = $rbUnits->count();
      $rbCatNames = \App\Models\Tenant\TenantRentalCategory::where('tenant_id', $rbTenant->id)
          ->whereNull('archived_at')->orderBy('sort_order')->orderBy('name')->get(['id','name'])->keyBy('id');
      foreach ($rbUnits as $u) {
          if (!$u->model) continue;
          $catName = $rbCatNames[$u->category_id]->name ?? 'Other';
          $key = $u->model->id;
          if (!isset($rbGroups[$catName][$key])) $rbGroups[$catName][$key] = ['model' => $u->model, 'count' => 0, 'sizes' => []];
          $rbGroups[$catName][$key]['count']++;
          if ($u->size && !in_array($u->size, $rbGroups[$catName][$key]['sizes'], true)) $rbGroups[$catName][$key]['sizes'][] = $u->size;
      }
  }
  $rbShowDeposit = ($c['show_deposit'] ?? '0') === '1';
@endphp

@if($rbTenant && $rbTenant->rentals_visible)
<section class="p-section" id="rental-browse" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>
  <div class="p-container">
    <div class="p-section-head-wrap" style="text-align:center">
      @if(!empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif
    </div>

    <form method="GET" action="#rental-browse" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;justify-content:center;margin-top:28px">
      <div>
        <label style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;display:block;margin-bottom:5px;font-weight:600">Pickup</label>
        <input type="datetime-local" name="starts" value="{{ $rbStart->format('Y-m-d\TH:i') }}" required style="padding:9px 12px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px">
      </div>
      <div>
        <label style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;display:block;margin-bottom:5px;font-weight:600">Return</label>
        <input type="datetime-local" name="due" value="{{ $rbDue->format('Y-m-d\TH:i') }}" required style="padding:9px 12px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px">
      </div>
      <button type="submit" class="p-btn p-btn--primary">Check</button>
    </form>
    @if($rbError)<div style="text-align:center;margin-top:10px;font-size:13px;opacity:.6">{{ $rbError }}</div>@endif

    @if($rbCount === 0)
      <div style="text-align:center;margin-top:36px;opacity:.55;font-size:14.5px">Nothing free in that window — try different times.</div>
    @else
      @foreach($rbGroups as $catName => $models)
        <div style="margin-top:36px">
          <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin-bottom:12px;font-weight:650">{{ $catName }}</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">
            @foreach($models as $g)
              @php $m = $g['model']; @endphp
              <div style="border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);padding:20px 22px;background:rgba(255,255,255,.6);display:flex;flex-direction:column">
                @if($m->image_url)<div style="aspect-ratio:16/10;border-radius:10px;background:url('{{ $m->image_url }}') center/cover no-repeat;margin-bottom:12px"></div>@endif
                <div style="font-size:16px;font-weight:650;line-height:1.3">{{ $m->name }}</div>
                @if($m->subtitle)<div style="font-size:12.5px;opacity:.55;margin-top:2px">{{ $m->subtitle }}</div>@endif
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
                  @if($m->daily_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->daily_rate_cents) }}</b>/day</span>@endif
                  @if($m->hourly_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->hourly_rate_cents) }}</b>/hr</span>@endif
                  @if($m->weekend_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->weekend_rate_cents) }}</b>/weekend</span>@endif
                  @if($rbShowDeposit && $m->deposit_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px">{{ format_money($m->deposit_cents) }} deposit</span>@endif
                </div>
                <div style="font-size:11.5px;opacity:.5;margin-top:10px">{{ $g['count'] }} available @if($g['sizes']) · {{ implode(', ', $g['sizes']) }} @endif</div>
                <div style="margin-top:auto;padding-top:16px">
                  <a class="p-btn p-btn--primary" style="width:100%;text-align:center" href="{{ route('tenant.rentals.reserve', ['model' => $m->id, 'starts' => $rbStart->format('Y-m-d\TH:i'), 'due' => $rbDue->format('Y-m-d\TH:i')]) }}">Reserve</a>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      @endforeach
    @endif
  </div>
</section>
@endif
