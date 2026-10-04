{{-- MARKER-MKT-NAV — reads the intake.works menu itself, so it can't
     disagree with it. Replaces the "Show in site navigation" checkbox. --}}
@php
  $mnPos = \App\Support\MarketingNav::positionFor((string) $page->id);
@endphp
<div class="pb2-status-nav" style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--pb2-text-dim);margin-top:10px;padding-top:10px;border-top:.5px solid var(--pb2-border)">
  @if($mnPos === null)
    <span style="width:7px;height:7px;border-radius:50%;background:var(--pb2-text-faint);flex:none"></span>
    <span>Not in the menu</span>
    <a href="{{ url('/admin/navigation') }}" style="margin-left:auto;color:var(--pb2-accent)">Add it on Navigation →</a>
  @elseif(! $page->is_published)
    <span style="width:7px;height:7px;border-radius:50%;background:#F0C46A;flex:none"></span>
    <span>In the menu · <b style="color:var(--pb2-text)">hidden until published</b></span>
    <a href="{{ url('/admin/navigation') }}" style="margin-left:auto;color:var(--pb2-accent)">Edit menu →</a>
  @else
    <span style="width:7px;height:7px;border-radius:50%;background:var(--pb2-accent);flex:none"></span>
    <span>In the menu · <b style="color:var(--pb2-text)">{{ \App\Support\MarketingNav::ordinal($mnPos['index']) }} of {{ $mnPos['total'] }}</b></span>
    <a href="{{ url('/admin/navigation') }}" style="margin-left:auto;color:var(--pb2-accent)">Edit menu →</a>
  @endif
</div>
