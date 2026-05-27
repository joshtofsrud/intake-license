@extends('emails.layout')

{{-- MARKER-PATCH-160 --}}
{{-- Variables in scope:
     $tenant       App\Models\Tenant
     $appointment  App\Models\Tenant\TenantAppointment (with items, addons, charges, customer)
     $greeting     string
     $subject      string
     $track_pixel  bool
     $pixel_url    string
--}}

@section('body')
@php
  $first   = $appointment->customer_first_name;
  $accent  = $tenant->accent_color ?? '#BEF264';
@endphp

<p style="font-size:18px;font-weight:700;margin:0 0 14px;letter-spacing:-.01em">
  Your work is ready
</p>

<p style="margin:0 0 24px;color:#444;font-size:14px;line-height:1.7">
  {{ $greeting }}
</p>

{{-- Reference card --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8f6;border-radius:8px;padding:16px 20px;margin-bottom:24px;border:1px solid #e8e8e4">
  <tr>
    <td style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#888;font-weight:700;padding-bottom:4px" colspan="2">
      Work order
    </td>
  </tr>
  <tr>
    <td style="font-size:20px;font-weight:700;letter-spacing:.02em;padding-bottom:12px" colspan="2">
      #{{ $appointment->ra_number }}
    </td>
  </tr>
  @if($appointment->appointment_date)
  <tr>
    <td style="font-size:12px;color:#666;padding:3px 0;width:110px">Date</td>
    <td style="font-size:12px;color:#111">{{ \Carbon\Carbon::parse($appointment->appointment_date)->format('l, M j, Y') }}</td>
  </tr>
  @endif
  @if($appointment->receiving_method_snapshot)
  <tr>
    <td style="font-size:12px;color:#666;padding:3px 0">Drop-off</td>
    <td style="font-size:12px;color:#111">{{ $appointment->receiving_method_snapshot }}</td>
  </tr>
  @endif
  <tr>
    <td style="font-size:12px;color:#666;padding:3px 0">Status</td>
    <td style="font-size:12px;color:#111;text-transform:capitalize">{{ str_replace('_', ' ', $appointment->status) }}</td>
  </tr>
</table>

{{-- Work performed --}}
@if($appointment->items && $appointment->items->isNotEmpty())
<p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:0 0 8px">
  Work performed
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px">
  @foreach($appointment->items as $item)
  <tr>
    <td style="padding:8px 0;border-bottom:1px solid #f0f0ee;vertical-align:top">
      <div style="font-size:14px;font-weight:500;color:#111">{{ $item->item_name_snapshot }}</div>
    </td>
    <td style="padding:8px 0;border-bottom:1px solid #f0f0ee;text-align:right;font-size:14px;white-space:nowrap;vertical-align:top">
      {{ format_money($item->price_cents) }}
    </td>
  </tr>
  @endforeach
  @if($appointment->addons && $appointment->addons->isNotEmpty())
    @foreach($appointment->addons as $addon)
    <tr>
      <td style="padding:6px 0 6px 16px;border-bottom:1px solid #f0f0ee;font-size:13px;color:#666;vertical-align:top">
        + {{ $addon->addon_name_snapshot }}
      </td>
      <td style="padding:6px 0;border-bottom:1px solid #f0f0ee;text-align:right;font-size:13px;color:#666;white-space:nowrap;vertical-align:top">
        {{ format_money($addon->price_cents) }}
      </td>
    </tr>
    @endforeach
  @endif
</table>
@endif

{{-- Additional charges (mid-service add-ons, parts, etc) --}}
@if($appointment->charges && $appointment->charges->isNotEmpty())
<p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:14px 0 8px">
  Additional charges
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px">
  @foreach($appointment->charges as $charge)
  <tr>
    <td style="padding:6px 0;border-bottom:1px solid #f0f0ee;font-size:13px;vertical-align:top">{{ $charge->description }}</td>
    <td style="padding:6px 0;border-bottom:1px solid #f0f0ee;font-size:13px;text-align:right;white-space:nowrap;vertical-align:top">{{ format_money($charge->amount_cents) }}</td>
  </tr>
  @endforeach
</table>
@endif

{{-- Totals --}}
<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0 20px">
  @if(($appointment->subtotal_cents ?? 0) > 0)
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">Subtotal</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">{{ format_money($appointment->subtotal_cents) }}</td>
  </tr>
  @endif
  @if(($appointment->tax_cents ?? 0) > 0)
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">Tax</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">{{ format_money($appointment->tax_cents) }}</td>
  </tr>
  @endif
  <tr>
    <td style="padding:10px 0 0;font-size:15px;font-weight:700;border-top:2px solid #111">
      @if($appointment->payment_status === 'paid')
        Total paid
      @else
        Total due
      @endif
    </td>
    <td style="padding:10px 0 0;font-size:15px;font-weight:700;text-align:right;border-top:2px solid #111">{{ format_money($appointment->total_cents) }}</td>
  </tr>
</table>

<p style="margin:24px 0 4px;font-size:13px;color:#555">
  Questions? Reply to this email.
</p>

@if($track_pixel && !empty($pixel_url))
  <img src="{{ $pixel_url }}" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0">
@endif

@endsection
