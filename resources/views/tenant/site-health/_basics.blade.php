{{-- MARKER-SITE-HEALTH: the four basics as one line; what each one is sits behind the (i). --}}
@php $ok = collect($sh['basics'])->where('ok', true)->count(); @endphp
@if ($sh['basics'])
  <div class="sh-basics {{ $ok === count($sh['basics']) ? '' : 'part' }}">
    <i>{{ $ok === count($sh['basics']) ? '✓' : '!' }}</i>
    <b>{{ $ok }} of {{ count($sh['basics']) }} basics in place</b>
    <button type="button" class="ia-tip" aria-label="Which basics">i</button><span class="ia-tip-text" hidden>@foreach ($sh['basics'] as $b){{ $b['ok'] ? '✓ ' . $b['yes'] : '✗ ' . $b['no'] }}<br>@endforeach</span>
  </div>
@endif
