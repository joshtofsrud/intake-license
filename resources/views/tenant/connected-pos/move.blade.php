@extends('layouts.tenant.app')
@section('title', 'Move to Intake')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/connected-pos.css') }}?v={{ filemtime(public_path('css/tenant/connected-pos.css')) }}">
@endpush

@section('content')
{{-- MARKER-CONNECTED-POS-SECTION: the Connected POS section as a checklist, for a shop moving to Intake.
     At the top of the menu while "How you use it" is Moving to Intake and the shop hasn't switched over. --}}
@php $pc = $pos['conn']; $switched = $pc && ! $pc->isTwoWay(); @endphp
<div class="ia-section ia-section--wide">
  @if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:14px;max-width:760px">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px;max-width:760px">{{ session('error') }}</div>@endif

  <div class="cpx-head">
    <div>
      <h1 class="ia-page-title">Move to Intake</h1>
      <div class="cpx-sub">@if($pc) From {{ $pos['label'] }} · @endif {{ $move['done'] }} of {{ $move['total'] }} done @if($pc)<span class="cpx-mode {{ $pc->isTwoWay() ? '' : 'ro' }}">{{ $pc->modeLabel() }}</span>@endif</div>
    </div>
    <div class="ia-page-actions"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.cpos.home') }}">Open Connected POS</a></div>
  </div>

  <details class="cp-legend">
    <summary>How moving over works</summary>
    <ul>
      <li>Your current POS keeps working the whole time. Nothing changes there, and nothing is copied until you ask for it at each step.</li>
      <li>Steps can be done in any order after connecting, stopped part-way, and picked up again.</li>
      <li><b>Switch over</b> is the moment Intake becomes your POS: your Connected POS is read one last time, then becomes <b>Reading Only</b>. You can go back to 2 Way Data under Connected POS › Settings.</li>
      <li>This page leaves the menu once you've switched over, or if you set <i>How you use it</i> back to Staying on Connected POS. Everything on it is also under <b>Connected POS</b>.</li>
      <li><b>Not yet:</b> sales history.</li>
    </ul>
  </details>

  <div class="cpx-prog" style="max-width:760px"><i style="width:{{ round(100 * $move['done'] / max(1, $move['total'])) }}%"></i></div>
  <div style="margin-top:10px">@include('tenant.connected-pos._move-steps')</div>

  @if($switched)
    <p class="ec-note" style="margin-top:18px">You've switched over. This page leaves the menu now; your Connected POS stays under <a class="cpx-lnk" href="{{ route('tenant.cpos.home') }}">Connected POS</a> until you disconnect it.</p>
  @endif
</div>
@include('tenant.connected-pos._live')
@endsection
