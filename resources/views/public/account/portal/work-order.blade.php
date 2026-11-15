@extends('public.account._shell')
@php
  // MARKER-PORTAL-DETAIL
  $pageTitle = 'Work order ' . ($appt->ra_number ?? '');
  $sym   = tenant()->currency_symbol ?: '$';
  $money = fn ($c) => ((int) $c < 0 ? '-' : '') . $sym . number_format(abs((int) $c) / 100, 2);
  $title = $appt->portalTitle();
  // appointment_date / appointment_time are naive tenant-local: format, never convert (PATCH-361)
  $dateStr = $appt->appointment_date ? \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j, Y') : null;
  $timeStr = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') : null;
  $statusLabel = \App\Support\AppointmentStatus::LABELS[$appt->status] ?? ucfirst(str_replace('_', ' ', (string) $appt->status));
  $stepOrder = ['awaiting' => 0, 'scheduled' => 1, 'active' => 2, 'done' => 3];
  $stepAt = $stepOrder[$role] ?? null;
  $steps = [
    ['Booked',      tlocal_date($appt->created_at, 'M j')],
    ['Confirmed',   $dateStr ? \Carbon\Carbon::parse($appt->appointment_date)->format('M j') : null],
    ['In progress', $appt->promised_at ? 'ready by ' . tlocal($appt->promised_at, 'M j') : null],
    ['Done',        $appt->completed_at ? tlocal_date($appt->completed_at, 'M j') : null],
  ];
  $assetNoun = tenant()->asset_label_singular ?: 'Item';
  $balance = (int) ($base['balance'] ?? 0);
@endphp
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'bookings'])

<a href="{{ route('tenant.customer.portal.bookings') }}" class="ac-back">&larr; All bookings</a>

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

<div class="ac-dhead">
  <div>
    <div class="ac-dtitle">{{ $title }}</div>
    <div class="ac-dsub">
      @if($appt->ra_number) Work order {{ $appt->ra_number }} &middot; @endif
      <span class="ac-pill ac-pill--{{ $appt->status }}">{{ $statusLabel }}</span>
    </div>
  </div>
  <div class="ac-dact">
    <a href="{{ route('tenant.customer.portal.work_order.print', $appt->id) }}" target="_blank" rel="noopener">Print</a>
    <a href="#conversation">Message</a>
  </div>
</div>

@if($role === 'cancelled')
  <div class="ac-banner ac-banner--overdue"><span><b>This booking was cancelled.</b> Questions? Send us a message below.</span></div>
@elseif($stepAt !== null)
  <div class="ac-steps">
    @foreach($steps as $i => [$label, $when])
      <div class="ac-step {{ $i < $stepAt ? 'done' : '' }} {{ $i === $stepAt ? 'now' : '' }}">
        {{ $i < $stepAt ? '✓ ' : '' }}{{ $label }}
        @if($when && $i <= $stepAt)<small>{{ $when }}</small>@endif
      </div>
    @endforeach
  </div>
@endif

<dl class="ac-kv">
  @if($dateStr)<dt>Appointment</dt><dd>{{ $dateStr }}@if($timeStr) &middot; {{ $timeStr }}@endif</dd>@endif
  @if($appt->promised_at)<dt>Ready by</dt><dd>{{ tlocal_datetime($appt->promised_at, 'D, M j \a\t g:i A') }}</dd>@endif
  @if($appt->completed_at)<dt>Completed</dt><dd>{{ tlocal_datetime($appt->completed_at, 'D, M j \a\t g:i A') }}</dd>@endif
  @if($appt->receiving_method_snapshot)<dt>Drop-off</dt><dd style="text-transform:capitalize">{{ str_replace('_', ' ', $appt->receiving_method_snapshot) }}@if($appt->receiving_time_snapshot) &middot; {{ $appt->receiving_time_snapshot }}@endif</dd>@endif
  @if($appt->tracking_number)<dt>Tracking</dt><dd>{{ $appt->tracking_number }}</dd>@endif
  @if($appt->location)<dt>Location</dt><dd>{{ $appt->location->name }}</dd>@endif
  <dt>Booked</dt><dd>{{ tlocal_datetime($appt->created_at, 'M j, Y') }}</dd>
</dl>

{{-- unpinned intake answers --}}
@if(($responses[''] ?? collect())->isNotEmpty())
  <div class="ac-section-title">Details</div>
  <div class="ac-list">
    @foreach($responses[''] as $r)
      <div class="ac-ans"><b>{{ $r->field_label_snapshot ?: $r->field->label }}</b>{{ $r->response_value }}</div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">Work &amp; parts</div>
@php $groups = $base['assets']; if (!empty($base['loose'])) { $groups[] = ['id' => null, 'name' => null, 'lines' => $base['loose'], 'subtotal' => null]; } @endphp
@forelse($groups as $g)
  <div class="ac-list">
    @if($g['id'])
      @php $as = $assets[$g['id']] ?? null; @endphp
      <div class="ac-grp">{{ $g['name'] ?: $assetNoun }}@if($as && $as->identifier_snapshot)<span>{{ $as->identifier_snapshot }}</span>@endif</div>
      @foreach(($responses[(string) $g['id']] ?? collect()) as $r)
        <div class="ac-ans"><b>{{ $r->field_label_snapshot ?: $r->field->label }}</b>{{ $r->response_value }}</div>
      @endforeach
    @elseif(count($groups) > 1)
      <div class="ac-grp">Other</div>
    @endif
    @forelse($g['lines'] as $ln)
      <div class="ac-line">
        <div>
          <div>{{ $ln['name'] }}</div>
          <div class="ac-line-m">
            {{ ['service' => 'Service', 'addon' => 'Add-on', 'part' => 'Part'][$ln['kind']] ?? '' }}
            @if(!empty($ln['qty']) && ($ln['kind'] ?? '') === 'part') &middot; {{ $ln['qty'] }} &times; {{ $money($ln['unit'] ?? 0) }}@endif
          </div>
        </div>
        <div class="ac-line-r">{{ $money($ln['cents']) }}</div>
      </div>
    @empty
      <div class="ac-empty">Nothing added yet</div>
    @endforelse
    @if($g['id'] && count($groups) > 1)
      <div class="ac-line" style="font-weight:600"><div>Subtotal</div><div class="ac-line-r">{{ $money($g['subtotal']) }}</div></div>
    @endif
  </div>
@empty
  <div class="ac-list"><div class="ac-empty">No work added yet &mdash; we'll fill this in once we take a look.</div></div>
@endforelse

<div class="ac-list">
  <div class="ac-tot"><span>Subtotal</span><span>{{ $money($base['subtotal']) }}</span></div>
  @if((int) $base['discount'] > 0)
    <div class="ac-tot"><span>Discount @if($base['discount_code'])({{ $base['discount_code'] }})@endif</span><span>-{{ $money($base['discount']) }}</span></div>
  @endif
  @if((int) $base['tax'] > 0)<div class="ac-tot"><span>Tax</span><span>{{ $money($base['tax']) }}</span></div>@endif
  <div class="ac-tot ac-tot--grand"><span>Total</span><span>{{ $money($base['total']) }}</span></div>
  @if((int) $base['paid'] !== 0)<div class="ac-tot"><span>Paid</span><span>{{ $money($base['paid']) }}</span></div>@endif
  @if($balance > 0 && $role !== 'cancelled')
    <div class="ac-tot ac-tot--due"><span>Balance due</span><span>{{ $money($balance) }}</span></div>
  @elseif((int) $base['total'] > 0 && $balance <= 0)
    <div class="ac-tot"><span>Status</span><span class="ac-pill ac-pill--paid">Paid in full</span></div>
  @endif
</div>
@if($base['note'])
  <div class="ac-list"><div class="ac-note">{{ $base['note'] }}</div></div>
@endif

@if($payments->isNotEmpty())
  <div class="ac-section-title">Payments</div>
  <div class="ac-list">
    @foreach($payments as $p)
      <div class="ac-line">
        <div><div>{{ $p->isRefund() ? 'Refund' : 'Payment' }} &middot; {{ $p->methodLabel() }}</div>
          <div class="ac-line-m">{{ $p->recorded_at ? tlocal_datetime($p->recorded_at, 'M j, Y g:i A') : '' }}</div></div>
        <div class="ac-line-r">{{ $money($p->amount_cents) }}</div>
      </div>
    @endforeach
  </div>
@endif

@if($specialOrders->isNotEmpty())
  <div class="ac-section-title">Parts on order</div>
  <div class="ac-list">
    @foreach($specialOrders as $so)
      <div class="ac-line">
        <div><div>{{ $so->item_name_snapshot }}@if((int) $so->quantity > 1) &times; {{ (int) $so->quantity }}@endif</div>
          <div class="ac-line-m">
            @if($so->arrived_at) Arrived {{ tlocal_date($so->arrived_at, 'M j') }}
            @elseif($so->expected_arrival_date) Expected {{ $so->expected_arrival_date->format('M j') }}
            @endif
          </div></div>
        <div class="ac-line-r"><span class="ac-pill ac-pill--{{ $so->status === 'arrived' || $so->status === 'pulled' ? 'paid' : 'pending' }}">{{ $so->statusLabel() }}</span></div>
      </div>
    @endforeach
  </div>
@endif

@if($notes->isNotEmpty())
  <div class="ac-section-title">Notes from {{ tenant()->name }}</div>
  <div class="ac-list">
    @foreach($notes as $n)
      <div class="ac-note">{{ $n->note_content }}
        <div class="ac-note-m">{{ $n->created_at ? tlocal_datetime($n->created_at, 'M j, Y g:i A') : '' }}@if($n->user?->name) &middot; {{ \Illuminate\Support\Str::before(trim($n->user->name) . ' ', ' ') }}@endif</div>
      </div>
    @endforeach
  </div>
@endif

@if($receipts->isNotEmpty())
  <div class="ac-section-title">Receipts</div>
  <div class="ac-list">
    @foreach($receipts as $s)
      <a class="ac-list-row" href="{{ route('tenant.customer.portal.sale', $s->id) }}">
        <div><div class="ac-list-name">{{ $s->isRefund() ? 'Refund' : 'Receipt' }} {{ $s->sale_number ? '#' . $s->sale_number : '' }}</div>
          <div class="ac-list-meta">{{ tlocal_date($s->paid_at ?? $s->created_at) }}</div></div>
        <div class="ac-list-right"><span style="font-weight:700">{{ $money($s->total_cents) }}</span><span class="ac-go">&rsaquo;</span></div>
      </a>
    @endforeach
  </div>
@endif

@include('public.account.portal._record-conversation', [
  'conversation' => $conversation,
  'action'       => route('tenant.customer.portal.work_order.message', $appt->id),
  'aboutLabel'   => 'this work order',
])
@endsection
