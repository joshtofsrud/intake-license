@extends('emails.layout')

{{-- MARKER-PATCH-160 --}}
{{-- Variables in scope:
     $tenant       App\Models\Tenant
     $sale         App\Models\Tenant\TenantSale (with items)
     $greeting     string  customizable greeting body (from template or default)
     $subject      string  used by the layout's <title>
     $track_pixel  bool    whether to include the open-tracking pixel
     $pixel_url    string  pixel URL when $track_pixel is true
--}}

@section('body')
@php
  $first  = $sale->customer?->first_name;
  $cashier = $sale->rangUpBy?->name;
  $saleDate = $sale->paid_at ?? $sale->created_at;
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp

<p style="font-size:18px;font-weight:700;margin:0 0 14px;letter-spacing:-.01em">
  Thanks for your purchase{{ $first ? ', ' . $first : '' }}
</p>

<p style="margin:0 0 24px;color:#444;font-size:14px;line-height:1.7">
  {{ $greeting }}
</p>

{{-- Order header --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8f6;border-radius:8px;padding:16px 20px;margin-bottom:24px;border:1px solid #e8e8e4">
  <tr>
    <td style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#888;font-weight:700;padding-bottom:4px">
      Receipt
    </td>
  </tr>
  <tr>
    <td style="font-size:20px;font-weight:700;letter-spacing:.02em;padding-bottom:6px">
      #{{ $sale->sale_number }}
    </td>
  </tr>
  <tr>
    <td style="font-size:12px;color:#666">
      {{ tlocal($saleDate, 'M j, Y · g:i A') }}
      @if($cashier) · Cashier: {{ $cashier }} @endif
    </td>
  </tr>
</table>

{{-- Line items --}}
<p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:0 0 8px">
  Items
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px">
  @foreach($sale->items as $item)
  <tr>
    <td style="padding:8px 0;border-bottom:1px solid #f0f0ee;vertical-align:top">
      <div style="font-size:14px;font-weight:500;color:#111">{{ $item->name_snapshot }}</div>
      @if($item->description_snapshot)
        <div style="font-size:12px;color:#777;margin-top:2px">{{ $item->description_snapshot }}</div>
      @endif
      @if($item->quantity && (float)$item->quantity != 1)
        <div style="font-size:12px;color:#777;margin-top:2px">
          qty {{ rtrim(rtrim(number_format((float)$item->quantity, 3), '0'), '.') }}
          · {{ format_money($item->unit_price_cents) }} ea
        </div>
      @endif
    </td>
    <td style="padding:8px 0;border-bottom:1px solid #f0f0ee;text-align:right;font-size:14px;white-space:nowrap;vertical-align:top">
      {{ format_money($item->line_total_cents) }}
    </td>
  </tr>
  @endforeach
</table>

{{-- Totals --}}
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px">
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">Subtotal</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">{{ format_money($sale->subtotal_cents) }}</td>
  </tr>
  @if($sale->discount_cents > 0)
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">Discount</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">−{{ format_money($sale->discount_cents) }}</td>
  </tr>
  @endif
  @if($sale->surcharge_cents > 0)
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">{{ $tenant->card_surcharge_label ?? 'Card surcharge' }}</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">{{ format_money($sale->surcharge_cents) }}</td>
  </tr>
  @endif
  @if($sale->tax_cents > 0)
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">Tax</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">{{ format_money($sale->tax_cents) }}</td>
  </tr>
  @endif
  @if($sale->tip_cents > 0)
  <tr>
    <td style="padding:4px 0;font-size:13px;color:#666">Tip</td>
    <td style="padding:4px 0;font-size:13px;text-align:right">{{ format_money($sale->tip_cents) }}</td>
  </tr>
  @endif
  <tr>
    <td style="padding:10px 0 0;font-size:15px;font-weight:700;border-top:2px solid #111;margin-top:8px">Total</td>
    <td style="padding:10px 0 0;font-size:15px;font-weight:700;text-align:right;border-top:2px solid #111">{{ format_money($sale->total_cents) }}</td>
  </tr>
</table>

{{-- Payment row --}}
@if($sale->payment_status === 'paid')
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fafaf7;border:1px solid #e8e8e4;border-radius:6px;padding:12px 16px;margin-bottom:20px">
  <tr>
    <td style="font-size:13px;font-weight:600;color:#111">
      @switch($sale->payment_method)
        @case('card')         Card payment @break
        @case('cash')         Cash @break
        @case('check')        Check @break
        @case('store_credit') Store credit @break
        @case('mark_paid')    Paid @break
        @case('split')        Split payment @break
        @default              Paid
      @endswitch
      @if($sale->payment_reference)
        <span style="color:#888;font-weight:400">· {{ $sale->payment_reference }}</span>
      @endif
    </td>
    <td style="font-size:11px;font-weight:700;text-align:right;color:#228B22;text-transform:uppercase;letter-spacing:.06em">
      Paid
    </td>
  </tr>
</table>
@endif

@if($sale->notes)
<p style="font-size:12px;color:#666;margin:0 0 20px;padding:10px 12px;background:#fafaf7;border-radius:4px">
  {{ $sale->notes }}
</p>
@endif

<p style="margin:24px 0 4px;font-size:13px;color:#555">
  Questions about your purchase? Reply to this email.
</p>

{{-- Open tracking pixel (optional, gated by tenant setting) --}}
@if($track_pixel && !empty($pixel_url))
  <img src="{{ $pixel_url }}" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0">
@endif

@endsection
