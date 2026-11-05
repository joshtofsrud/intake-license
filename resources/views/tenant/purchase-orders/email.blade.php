{{-- MARKER-PO: the email body that carries the PO PDF --}}
<p>Hello,</p>
<p>{{ $revised ? 'Here is a revised purchase order' : 'Please find our purchase order' }} <strong>{{ $po->po_number }}</strong> attached{{ $po->expected_date ? ', needed by ' . $po->expected_date->format('M j') : '' }}.</p>
@if($revised && $po->changes_note)<p>What changed: {{ $po->changes_note }}</p>@endif
<p>{{ $po->lines->count() }} line{{ $po->lines->count() === 1 ? '' : 's' }}. Please quote {{ $po->po_number }} on the packing slip and invoice, and reply to this email with any substitutions or backorders.</p>
<p>Thanks,<br>{{ $tenant->name }}</p>
