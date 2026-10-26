{{-- MARKER-DASH-BUILDER: a block made in master admin (CustomBlocks). One view
     for every kind: a figure, a figure with its trend, a few records, a
     button, or a note. Self-contained styles, so it looks the same in any
     template's frame. Expects $dashBlock (the placement); $cbPreview (master
     admin's builder) supplies the definition and figures instead. --}}
@php
  $cbPreview = $cbPreview ?? null;
  $cbKey = $dashBlock['b'] ?? '';
  $cbDef = $cbPreview['def'] ?? (\App\Support\Dashboard\CustomBlocks::definitions()[$cbKey] ?? null);
  $cbTenant = $cbPreview['tenant'] ?? tenant();
  $cbSee = $cbPreview ? null : ($see ?? \App\Support\Dashboard\BlockAccess::for(auth('tenant')->user()));
  $cbRes = $cbDef ? ($cbPreview['result'] ?? ($cbDef['kind'] === 'data' ? \App\Support\Dashboard\CustomBlocks::result($cbKey, $cbTenant) : ['kind' => $cbDef['kind']])) : null;
  $cbUrl = function (?string $route, $params = []) use ($cbSee) {
      if (! $route || ! \Illuminate\Support\Facades\Route::has($route)) return null;
      if ($cbSee && ! $cbSee->canRoute($route)) return null;
      try { return route($route, $params); } catch (\Throwable $e) { return null; }
  };
  $cbCfg = $cbDef['config'] ?? [];
  $cbExpired = $cbDef && $cbDef['kind'] === 'note' && ! empty($cbCfg['until'])
      && \Carbon\Carbon::parse($cbCfg['until'], $cbTenant?->timezone() ?? 'UTC')->endOfDay()->isPast();
@endphp
@once
  @push('styles')
    @include('tenant.dashboard.blocks._custom-style')
  @endpush
@endonce
@if ($cbDef && $cbRes && ! $cbExpired)
  @php $cbName = $dashBlock['title'] ?? $cbDef['name']; @endphp
  <div class="cb">
  @if ($cbDef['kind'] === 'note')
    <div class="cb-note"><div class="t">{{ $cbName }}</div><div class="s">{{ $cbCfg['text'] }}</div></div>

  @elseif ($cbDef['kind'] === 'action')
    @php $cbAct = \App\Support\Dashboard\CustomBlocks::ACTIONS[$cbCfg['action']] ?? null; $cbHref = $cbAct ? ($cbPreview ? '#' : $cbUrl($cbAct['route'], $cbAct['params'])) : null; @endphp
    @if ($cbHref)
      <div class="cb-h"><h2>{{ $cbName }}</h2></div>
      @if ($cbCfg['line'])<div class="cb-line">{{ $cbCfg['line'] }}</div>@endif
      <div class="cb-acts"><a href="{{ $cbHref }}" class="ia-btn ia-btn--primary">{{ $cbAct['label'] }}</a></div>
    @endif

  @else
    @php
      $cbSrc = \App\Support\Dashboard\CustomBlocks::sources()[$cbCfg['source']];
      $cbAll = $cbPreview ? null : $cbUrl($cbSrc['opens']);
    @endphp
    <div class="cb-h">
      <h2>{{ $cbName }}</h2>
      <span class="meta">
        @if ($cbRes['measure'] === 'list')
          {{ number_format($cbRes['total']) }} · {{ \App\Support\Dashboard\CustomBlocks::PERIODS[$cbCfg['period']] }}
        @else
          {{ \App\Support\Dashboard\CustomBlocks::meta($cbCfg) }}
        @endif
        @if ($cbAll) · <a href="{{ $cbAll }}">{{ $cbSrc['opens_label'] }} ›</a>@endif
      </span>
    </div>
    @if ($cbCfg['line'])<div class="cb-line">{{ $cbCfg['line'] }}</div>@endif

    @if ($cbRes['measure'] === 'list')
      @if ($cbCfg['display'] === 'table' && $cbRes['rows'])
        <table class="cb-tbl">
          <thead><tr>@foreach ($cbSrc['cols'] as $ci => $ch)<th class="{{ $ci === 2 ? 'r' : '' }}">{{ $ch }}</th>@endforeach</tr></thead>
          <tbody>
            @foreach ($cbRes['rows'] as [$t, $s, $r, $route, $id])
              @php $href = $cbPreview ? null : $cbUrl($route, $id); @endphp
              <tr>
                <td>@if ($href)<a href="{{ $href }}">{{ $t }}</a>@else{{ $t }}@endif</td>
                <td class="s">{{ $s }}</td>
                <td class="r">{{ $r }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @else
      <div class="cb-rows">
        @forelse ($cbRes['rows'] as [$t, $s, $r, $route, $id])
          @php $href = $cbPreview ? null : $cbUrl($route, $id); @endphp
          @if ($href)<a class="cb-row" href="{{ $href }}">@else<div class="cb-row">@endif
            <span><div class="t">{{ $t }}</div><div class="s">{{ $s }}</div></span>
            <span class="r">{{ $r }}</span>
            <span class="arr">{{ $href ? '›' : '' }}</span>
          @if ($href)</a>@else</div>@endif
        @empty
          <div class="cb-calm">Nothing here {{ strtolower(\App\Support\Dashboard\CustomBlocks::PERIODS[$cbCfg['period']]) }}.</div>
        @endforelse
      </div>
      @endif
    @else
      <div class="cb-big">
        <span class="v">{{ \App\Support\Dashboard\CustomBlocks::figure($cbRes, $cbTenant) }}</span>
        @if ($cbCfg['measure'] === 'count')<span class="u">{{ \App\Support\Dashboard\CustomBlocks::unit($cbCfg, $cbRes['value']) }}</span>@endif
        @if ($cbRes['pct'] !== null)
          <span class="d {{ $cbRes['pct'] >= 0 ? 'up' : 'down' }}">{{ $cbRes['pct'] >= 0 ? '▲' : '▼' }} {{ abs($cbRes['pct']) }}%</span>
        @elseif ($cbCfg['compare'] && $cbRes['prev'] !== null)
          <span class="was">nothing to compare with yet</span>
        @endif
      </div>
      @if ($cbCfg['display'] === 'trend' && ! empty($cbRes['series']))
        {{-- stretched to the block's width: the line keeps its thickness, the end dot is dropped --}}
        <div class="cb-spark">{!! preg_replace('/<circle[^>]*\/>/', '', str_replace(['<svg viewBox="0 0 110 28"', '<path '], ['<svg viewBox="0 0 110 28" preserveAspectRatio="none"', '<path vector-effect="non-scaling-stroke" '], \App\Support\Dashboard\BriefView::spark($cbRes['series']))) !!}</div>
      @elseif ($cbCfg['display'] === 'bars' && ! empty($cbRes['series']))
        @php $cbMax = max(1, max($cbRes['series'])); $cbN = count($cbRes['series']); @endphp
        <div class="cb-bars" style="grid-template-columns:repeat({{ $cbN }}, minmax(0, 1fr))">
          @foreach ($cbRes['series'] as $bi => $bv)
            <div title="{{ \App\Support\Dashboard\CustomBlocks::format($bv, $cbRes, $cbTenant) }}"><i class="{{ $bi === $cbRes['today_i'] ? 'cur' : '' }}" style="height:{{ $bv > 0 ? max(4, round($bv / $cbMax * 100)) : 0 }}%"></i></div>
          @endforeach
        </div>
        <div class="cb-bars-x" style="grid-template-columns:repeat({{ $cbN }}, minmax(0, 1fr))">
          @foreach ($cbRes['labels'] as $bi => $bl)<span>{{ $cbN <= 10 || $bi % (int) ceil($cbN / 8) === 0 ? $bl : '' }}</span>@endforeach
        </div>
      @elseif ($cbCfg['display'] === 'progress' && ! empty($cbRes['goal']))
        <div class="cb-prog"><i style="width:{{ min(100, $cbRes['pct_goal']) }}%"></i></div>
        <div class="cb-line" style="margin-top:6px">{{ $cbRes['pct_goal'] }}% of the {{ \App\Support\Dashboard\CustomBlocks::goalText($cbCfg, $cbTenant) }} goal</div>
      @endif
    @endif
  @endif
  </div>
@endif
