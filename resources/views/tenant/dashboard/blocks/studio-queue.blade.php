{{-- Block: Studio queue and details (MARKER-DASH-STUDIO). Jobs in the shop,
     oldest first: due and not started (Waiting), In progress, and done but not
     collected (Ready). Picking a row shows its details and actions beside the
     list; the filters count what each one holds. --}}
@php
  $q = $studio['queue'] ?? null;
  $assetP = $studio['assetP'] ?? 'items';
@endphp
@if ($q)
<section class="sd-queue sd-in" style="--d:220ms" data-sd-live="queue">
  <div class="sd-sec-h">
    <div><h2>{{ $dashBlock['title'] ?? 'Queue' }}</h2><span>Jobs in the shop, oldest first</span></div>
    @php $all = array_sum($q['counts']); @endphp
    <div class="sd-seg" role="tablist" aria-label="Show">
      <button type="button" class="on" data-f="all">All {{ $all }}</button>
      <button type="button" data-f="waiting">Waiting {{ $q['counts']['waiting'] }}</button>
      <button type="button" data-f="active">In progress {{ $q['counts']['active'] }}</button>
      <button type="button" data-f="ready">Ready {{ $q['counts']['ready'] }}</button>
    </div>
  </div>

  @if (! $q['rows'])
    <div class="sd-empty">Nothing in the shop right now.
      <div class="sd-empty-acts"><a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ route('tenant.appointments.index', ['new' => 1]) }}">New booking</a><a class="ia-btn ia-btn--sm" href="{{ route('tenant.appointments.index') }}">All work orders</a></div>
    </div>
  @else
    <div class="sd-list" id="sdList">
      @foreach ($q['rows'] as $i => $r)
        <div class="sd-row sd-g-{{ $r['group'] }}{{ $i === 0 ? ' sel' : '' }}" data-group="{{ $r['group'] }}" data-i="{{ $i }}" tabindex="0" role="button" aria-label="{{ $r['who'] }}, {{ $r['title'] }}, {{ $r['groupLabel'] }}">
          <div class="sd-av">{{ $r['initials'] }}</div>
          <div class="sd-who"><b>{{ $r['who'] }}</b><small>{{ $r['number'] }}</small></div>
          <div class="sd-job">{{ $r['title'] }}@if (count($r['assets']) > 1) <button type="button" class="sd-multi" data-assets='@json($r['assets'])'>{{ count($r['assets']) }} {{ $assetP }}</button>@endif</div>
          <div class="sd-st{{ $r['late'] ? ' late' : '' }}">{{ $r['late'] ? 'Late' : $r['groupLabel'] }}</div>
          <div class="sd-age" title="Since it was booked for">{{ $r['age'] }}</div>
        </div>
      @endforeach
      <div class="sd-none" hidden>None here right now.</div>
    </div>
    @if ($q['more'])
      <a class="sd-link sd-more" href="{{ route('tenant.appointments.index') }}">{{ $q['more'] }} more in Work orders →</a>
    @endif
    @php $sdJson = array_map(fn ($r) => \Illuminate\Support\Arr::only($r, ['who', 'number', 'title', 'groupLabel', 'group', 'age', 'late', 'assets', 'notes', 'total', 'due', 'pay', 'url', 'customerUrl', 'phone']), $q['rows']); @endphp
    <script type="application/json" id="sdQueue">@json($sdJson)</script>
    <section class="sd-detail" id="sdDetail" aria-live="polite"
      data-can-register="{{ $see->can('register') ? 1 : 0 }}" data-register-url="{{ $see->can('register') ? route('tenant.register.index') : '' }}" data-asset-p="{{ $assetP }}"></section>
  @endif
</section>
@endif
