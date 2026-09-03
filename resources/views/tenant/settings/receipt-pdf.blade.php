{{-- MARKER-BILLING-RECEIPT — print styling only; dompdf ignores most modern CSS,
     so this is tables and inline styles on purpose. --}}
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111; margin: 0; }
  .wrap { padding: 34px 40px; }
  h1 { font-size: 17pt; margin: 0 0 2px; letter-spacing: -.3pt; }
  .muted { color: #666; }
  .small { font-size: 9.5pt; }
  table { width: 100%; border-collapse: collapse; }
  .lines th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .6pt;
              color: #666; border-bottom: 1px solid #ddd; padding: 0 0 6px; font-weight: normal; }
  .lines td { padding: 8px 0; border-bottom: 1px solid #eee; vertical-align: top; }
  .r { text-align: right; }
  .total td { border-top: 2px solid #111; border-bottom: none; font-weight: bold; font-size: 12.5pt; padding-top: 10px; }
  .stamp { display: inline-block; border: 1.5px solid #999; color: #666; padding: 3px 10px;
           border-radius: 4px; font-size: 9pt; text-transform: uppercase; letter-spacing: .8pt; }
</style>
</head><body><div class="wrap">

<table><tr>
  <td style="width:60%">
    <h1>Receipt</h1>
    <div class="muted small">{{ $number }}</div>
  </td>
  <td class="r" style="vertical-align:top">
    <div style="font-weight:bold;font-size:13pt">intake</div>
    <div class="muted small">intake.works</div>
    @if($run->status === 'written_off')
      <div style="margin-top:8px"><span class="stamp">Written off</span></div>
    @elseif($run->status === 'refunded')
      <div style="margin-top:8px"><span class="stamp">Refunded</span></div>
    @endif
  </td>
</tr></table>

<table style="margin-top:22px"><tr>
  <td style="width:50%;vertical-align:top">
    <div class="small muted">Billed to</div>
    <div style="margin-top:3px">{{ $tenant->name }}</div>
    @if($tenant->billing_email)<div class="small muted">{{ $tenant->billing_email }}</div>@endif
  </td>
  <td style="width:50%;vertical-align:top" class="r">
    <div class="small muted">
      @if($run->charged_at)
        Charged {{ \Carbon\Carbon::parse($run->charged_at)->format('F j, Y') }}
      @else
        Issued {{ $run->created_at->format('F j, Y') }}
      @endif
    </div>
    @if($period['from'])
      <div class="small muted">
        Usage {{ \Carbon\Carbon::parse($period['from'])->format('M j') }}
        – {{ \Carbon\Carbon::parse($period['to'])->format('M j, Y') }}
      </div>
    @endif
    @if($run->stripe_payment_intent_id)
      <div class="small muted">Ref {{ $run->stripe_payment_intent_id }}</div>
    @endif
    @if($tenant->card_last4)
      <div class="small muted">{{ strtoupper($tenant->card_brand ?? 'Card') }} ending {{ $tenant->card_last4 }}</div>
    @endif
  </td>
</tr></table>

<table class="lines" style="margin-top:26px">
  <thead><tr>
    <th style="width:52%">Description</th>
    <th class="r">Quantity</th>
    <th class="r">Rate</th>
    <th class="r">Amount</th>
  </tr></thead>
  <tbody>
    @foreach($lines as $l)
      <tr>
        <td>{{ $l['description'] }}</td>
        <td class="r">{{ number_format($l['qty']) }} <span class="muted small">{{ $l['unit'] }}</span></td>
        <td class="r">{{ $l['rate'] > 0 ? '$' . rtrim(rtrim(number_format($l['rate'], 5), '0'), '.') : '—' }}</td>
        <td class="r">${{ number_format($l['cents'] / 100, 2) }}</td>
      </tr>
    @endforeach
    <tr class="total">
      <td colspan="3">{{ $run->status === 'written_off' ? 'Written off — nothing charged' : 'Total charged' }}</td>
      <td class="r">${{ number_format($total / 100, 2) }}</td>
    </tr>
  </tbody>
</table>

@if($run->resolution_reason)
  <p class="small muted" style="margin-top:18px">{{ $run->resolution_reason }}</p>
@endif

<p class="small muted" style="margin-top:26px;line-height:1.5">
  Each line shows the rate at the time of sending; a later price change does not affect this receipt.
  Questions about this charge can go to intake.works.
</p>

</div></body></html>
