@extends('layouts.tenant.app')
@section('title', 'Connected POS')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/connected-pos.css') }}?v={{ filemtime(public_path('css/tenant/connected-pos.css')) }}">
@endpush

@section('content')
{{-- MARKER-CONNECTED-POS-SECTION: the shop's own outside POS (not the Intake Register), as a section of its own.
     Tabs: Overview · Items · Customers · Sending · Settings. Items come into Intake through Inventory › Import;
     sending online orders is set in E-commerce. --}}
@php
  $pc = $pos['conn'];
  $cnt = $pos['counts'] ?? collect();
  $waiting = (int) ($cnt['pending'] ?? 0);
  $failed = (int) ($cnt['failed'] ?? 0);
  $twoWay = $pc ? $pc->isTwoWay() : true;
  $tabs = [
    'overview'  => ['Overview', null, false],
    'items'     => ['Items', $pos['items']['new'] ? number_format($pos['items']['new']) . ' new' : null, false],
    'customers' => ['Customers', null, false],
    'sending'   => ['Sending', $failed ? number_format($failed) . ' failed' : ($waiting ? number_format($waiting) . ' waiting' : null), (bool) $failed],
    'settings'  => ['Settings', null, false],
  ];
@endphp
<div class="ia-section ia-section--wide">

  @if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:14px;max-width:980px">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px;max-width:980px">{{ session('error') }}</div>@endif
  @if(request('pos') === 'connected')<div class="ia-flash ia-flash--success" style="margin-bottom:14px;max-width:980px">Your POS is connected. Nothing has been brought in yet: read its catalog under Items, then choose what to add.</div>@endif
  @if(request('pos') === 'checkfailed')<div class="ia-flash ia-flash--error" style="margin-bottom:14px;max-width:980px">Your POS approved Intake, but reading it failed. The reason is shown below.</div>@endif
  @if(request('pos_error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px;max-width:980px">{{ \Illuminate\Support\Str::limit((string) request('pos_error'), 220) }}</div>@endif

  <div class="cpx-head">
    <div>
      <h1 class="ia-page-title">Connected POS</h1>
      @if($pc)
        <div class="cpx-sub">
          <span class="cpx-dot {{ $pc->status === 'connected' ? '' : 'warn' }}"></span>
          <span>{{ $pos['label'] }} · {{ $pc->external_account_name ?: 'POS account' }} · {{ $pc->status === 'connected' ? 'connected' : 'needs attention' }} · last checked {{ $pc->last_checked_at ? $pc->last_checked_at->diffForHumans() : 'never' }}</span>
          <span class="cpx-mode {{ $twoWay ? '' : 'ro' }}" title="{{ $twoWay ? 'Intake reads from your Connected POS and sends to it.' : 'Intake reads from your Connected POS and sends nothing to it.' }}">{{ $pc->modeLabel() }}</span>
        </div>
        @if($pc->last_error)<div class="cpx-sub" style="color:#F26D6D">{{ $pc->last_error }}</div>@endif
      @else
        <div class="cpx-sub">The POS your shop already uses, connected to Intake. Not the Intake Register.</div>
      @endif
    </div>
    @if($pc)
      <div class="ia-page-actions">
        <form method="POST" action="{{ route('tenant.cpos.check') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Check connection</button></form>
        @if($pc->status !== 'connected')
          <form method="POST" action="{{ route('tenant.cpos.connect', $pc->adapter_code) }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Reconnect</button></form>
        @endif
        <form method="POST" action="{{ route('tenant.cpos.disconnect') }}">@csrf<button class="ia-btn ia-btn--ghost ia-btn--sm" type="submit" data-confirm="Disconnect your Connected POS? Intake's access is deleted, and reading and sending stop. Items and customers already in Intake stay. You can connect again any time.">Disconnect</button></form>
      </div>
    @endif
  </div>

  <details class="cp-legend" @if(! $pc) open @endif>
    <summary>What Connected POS does</summary>
    <ul>
      <li><b>Connected POS</b> is the outside POS your shop uses, not the Intake Register.</li>
      <li><b>Nothing is created in Intake on its own.</b> Intake reads your Connected POS catalog and links products that are plainly items you already carry (same barcode or part number). Everything else waits in <b>Inventory › Import</b> until you add it.</li>
      <li><b>2 Way Data:</b> Intake reads from your Connected POS and sends to it. Linked items take its price and stock, about every minute. Only what you turn on is sent: online orders and their refunds (set in E-commerce), items an order needs, items you send from Inventory, and your item edits if you choose.</li>
      <li><b>Reading Only:</b> after you switch over to Intake. The catalog and customers can still be read, nothing is sent, and Intake owns price and stock.</li>
      <li><b>Customers</b> come in only when you turn them on. A match by email or phone only has its blank fields filled; nobody is opted in to marketing.</li>
      <li>When both sides changed the same field on an item, nothing is overwritten; it waits under <b>Sending › Needs review</b>.</li>
      <li><b>Not yet:</b> sales history.</li>
    </ul>
  </details>

  @if(! $pc)
    @include('tenant.connected-pos._connect')
  @else
    <div class="cpx-tabs">
      @foreach($tabs as $k => [$lbl, $pip, $warn])
        <a class="cpx-tab {{ $tab === $k ? 'on' : '' }}" href="{{ route('tenant.cpos.home', ['tab' => $k]) }}">{{ $lbl }} @if($pip)<span class="cpx-pip {{ $warn ? 'warn' : '' }}">{{ $pip }}</span>@endif</a>
      @endforeach
    </div>
    @include('tenant.connected-pos._' . $tab)
  @endif
</div>
@endsection
