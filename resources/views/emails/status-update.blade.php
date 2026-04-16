@extends('emails.layout')

@section('body')
@php
  $accent     = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
  $statusLabels = [
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Ready for pickup',
    'shipped'     => 'Shipped',
    'closed'      => 'Closed',
    'cancelled'   => 'Cancelled',
  ];
  $statusLabel  = $statusLabels[$appointment->status] ?? ucfirst($appointment->status);
  $isReadyOrDone = in_array($appointment->status, ['completed', 'shipped', 'closed']);
@endphp

<p style="font-size:18px;font-weight:700;margin:0 0 16px;letter-spacing:-.01em">
  Update on your work order
</p>

<p style="margin:0 0 20px;color:#444">
  Hi {{ $appointment->customer_first_name }}, here's a status update on your
  work order with {{ $tenant->name }}.
</p>

{{-- Status card --}}
<table width="100%" cellpadding="0" cellspacing="0"
  style="background:{{ $isReadyOrDone ? '#EAF3DE' : '#f8f8f6' }};border-radius:8px;padding:20px;margin-bottom:24px;border:1px solid {{ $isReadyOrDone ? '#C0DD97' : '#e8e8e4' }}">
  <tr>
    <td style="font-size:13px;color:#666;padding-bottom:4px">Reference number</td>
  </tr>
  <tr>
    <td style="font-size:20px;font-weight:700;padding-bottom:12px">{{ $appointment->ra_number }}</td>
  </tr>
  <tr>
    <td>
      <span style="background:{{ $isReadyOrDone ? '#3B6D11' : '#111' }};color:#fff;padding:4px 14px;border-radius:20px;font-size:13px;font-weight:600">
        {{ $statusLabel }}
      </span>
    </td>
  </tr>
</table>

@if(!empty($vars['status_note']))
<p style="font-size:14px;color:#444;margin:0 0 20px;background:#f8f8f6;padding:14px 16px;border-radius:6px;border-left:3px solid {{ $accent }}">
  {{ $vars['status_note'] }}
</p>
@endif

@if($appointment->status === 'completed')
<p style="font-size:15px;font-weight:500;margin:0 0 20px">
  Your item is ready for pickup. Please contact us to arrange collection.
</p>
@elseif($appointment->status === 'cancelled')
<p style="font-size:14px;color:#666;margin:0 0 20px">
  If you have any questions about this cancellation, please reply to this email.
</p>
@endif

<p style="margin:24px 0 0;font-size:15px;color:#444">
  Thanks,<br>
  <strong>The {{ $tenant->name }} team</strong>
</p>

@endsection
