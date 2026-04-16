@extends('emails.layout')

@section('body')
@php
  $accent     = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
@endphp

<p style="font-size:18px;font-weight:700;margin:0 0 16px;letter-spacing:-.01em">
  Your booking is confirmed ✓
</p>

<p style="margin:0 0 20px;color:#444">
  Hi {{ $appointment->customer_first_name }}, thanks for booking with {{ $tenant->name }}.
  Here's a summary of your upcoming appointment.
</p>

{{-- Reference card --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8f6;border-radius:8px;padding:20px;margin-bottom:24px;border:1px solid #e8e8e4">
  <tr>
    <td style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#888;padding-bottom:4px" colspan="2">
      Reference number
    </td>
  </tr>
  <tr>
    <td style="font-size:24px;font-weight:700;letter-spacing:.04em;padding-bottom:16px" colspan="2">
      {{ $appointment->ra_number }}
    </td>
  </tr>
  <tr>
    <td style="font-size:13px;color:#666;padding:4px 0;width:120px">Date</td>
    <td style="font-size:13px;font-weight:500">{{ $appointment->appointment_date->format('l, F j, Y') }}</td>
  </tr>
  @if($appointment->receiving_method_snapshot)
  <tr>
    <td style="font-size:13px;color:#666;padding:4px 0">Drop-off</td>
    <td style="font-size:13px;font-weight:500">{{ $appointment->receiving_method_snapshot }}</td>
  </tr>
  @endif
  <tr>
    <td style="font-size:13px;color:#666;padding:4px 0">Total</td>
    <td style="font-size:13px;font-weight:500">{{ format_money($appointment->total_cents) }}</td>
  </tr>
</table>

{{-- Services --}}
@if($appointment->items && $appointment->items->isNotEmpty())
<p style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:0 0 8px">
  Services booked
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px">
  @foreach($appointment->items as $item)
  <tr>
    <td style="font-size:14px;padding:6px 0;border-bottom:1px solid #f0f0ee">
      {{ $item->item_name_snapshot }} — {{ $item->tier_name_snapshot }}
    </td>
    <td style="font-size:14px;text-align:right;padding:6px 0;border-bottom:1px solid #f0f0ee;white-space:nowrap">
      {{ format_money($item->price_cents) }}
    </td>
  </tr>
  @endforeach
</table>
@endif

{{-- CTA --}}
<p style="margin:24px 0 8px;font-size:14px;color:#444">
  Keep your reference number handy — you'll need it if you contact us about this job.
</p>

<p style="margin:24px 0 0;font-size:15px;color:#444">
  See you soon,<br>
  <strong>The {{ $tenant->name }} team</strong>
</p>

@endsection
