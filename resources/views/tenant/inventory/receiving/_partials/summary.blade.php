{{-- MARKER-PO: the numbers above the lines, the invoice check, and what a
     commit would do (read by the in-app commit dialog). --}}
@php $money = fn ($c) => '$' . number_format(((int) $c) / 100, 2); @endphp
@if($invoiceFlag)
  <div class="rcv-banner">
    <b>Invoice doesn't match.</b>
    Invoice {{ $money($shipment->invoice_total_cents) }}; received value + freight {{ $money($shipment->invoice_total_cents - $invoiceGap) }}.
    Off by {{ $money(abs($invoiceGap)) }} ({{ $invoiceGap > 0 ? 'invoice is higher' : 'invoice is lower' }}).
    Check the cost on each line. The shipment can still be committed and stays flagged.
  </div>
@endif
@if($pos->isNotEmpty())
  <div class="rcv-stats">
    <div><span>Owed on POs</span><b>{{ $stats['owed'] }}</b></div>
    <div><span>Received</span><b class="acc">{{ $stats['received'] }}</b></div>
    <div><span>Still owed after this</span><b class="amb">{{ $stats['still'] }}</b></div>
    <div><span>Special orders filled</span><b class="acc">{{ $stats['filled'] }}</b></div>
  </div>
@else
  <div class="rcv-stats">
    <div><span>Expected</span><b data-stat="expected">{{ $shipment->expected_count }}</b></div>
    <div><span>Received</span><b class="acc" data-stat="received">{{ $shipment->received_count }}</b></div>
    <div><span>Backorder</span><b class="amb" data-stat="backorder">{{ $shipment->backorder_count }}</b></div>
    <div><span>Unexpected</span><b class="amb" data-stat="unexpected">{{ $shipment->unexpected_count }}</b></div>
  </div>
@endif
<script type="application/json" id="rcv-plan">@json($plan)</script>
