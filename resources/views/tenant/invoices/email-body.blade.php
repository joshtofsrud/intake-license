{{-- MARKER-PATCH-204 — short email body; the invoice itself is the PDF attachment. --}}
@php $isPaid = $terms === 'paid'; @endphp
<div style="font-family:Inter,-apple-system,sans-serif;font-size:14px;line-height:1.7;color:#333">
  <p style="font-size:18px;font-weight:700;margin:0 0 14px;letter-spacing:-.2px">
    {{ $isPaid ? 'Your receipt is attached' : 'Your invoice is attached' }}
  </p>
  <p style="margin:0 0 18px;color:#444">
    Hi {{ explode(' ', trim($customer['name']))[0] ?: 'there' }}, thanks for trusting {{ $tenant->name }} with your work.
    Your {{ $isPaid ? 'paid receipt' : 'invoice' }} for work order <b>{{ $number }}</b> is attached as a PDF.
  </p>
  <table cellpadding="0" cellspacing="0" style="background:#f8f8f6;border:1px solid #e8e8e4;border-radius:8px;padding:14px 18px;margin-bottom:18px">
    <tr><td style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#888;font-weight:700;padding-bottom:4px">
      {{ $isPaid ? 'Total paid' : 'Balance due' }}
    </td></tr>
    <tr><td style="font-size:22px;font-weight:700">{{ format_money($isPaid ? $total : $balance) }}</td></tr>
    @if(!$isPaid)
    <tr><td style="font-size:12px;color:#666;padding-top:4px">{{ $terms === 'due_now' ? 'Due now.' : 'Due on completion.' }}</td></tr>
    @endif
  </table>
  <p style="margin:0;color:#555;font-size:13px">Questions? Just reply to this email@if($tenant->phone) or call {{ $tenant->phone }}@endif.</p>
</div>
