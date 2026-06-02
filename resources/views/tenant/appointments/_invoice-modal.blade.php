{{-- MARKER-PATCH-205 — invoice export composer, wired to patch-204 endpoints. --}}
@php
  $invPaid  = $appointment->isPaid();
  $invTerms = $appointment->invoice_terms ?? 'on_completion';
@endphp

<style>
  .inv-dialog{border:0.5px solid var(--ia-border-strong,rgba(255,255,255,.22));border-radius:14px;background:var(--ia-surface,#1c1c1c);color:var(--ia-text,#f0f0f0);padding:0;width:min(460px,92vw);box-shadow:0 30px 80px rgba(0,0,0,.6)}
  .inv-dialog::backdrop{background:rgba(0,0,0,.6)}
  .inv-h{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 20px;border-bottom:0.5px solid var(--ia-border,rgba(255,255,255,.13))}
  .inv-h .t{font-size:15px;font-weight:600}
  .inv-h .s{font-size:12px;color:var(--ia-text-dim,rgba(255,255,255,.55));margin-top:2px}
  .inv-x{background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-size:17px;cursor:pointer;line-height:1;padding:2px 6px}
  .inv-x:hover{color:var(--ia-text,#f0f0f0)}
  .inv-b{padding:18px 20px}
  .inv-grp{margin-bottom:16px}
  .inv-grp:last-child{margin-bottom:0}
  .inv-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:600;color:var(--ia-text-dim,rgba(255,255,255,.55));display:block;margin-bottom:8px}
  .inv-seg{display:flex;gap:6px;background:var(--ia-input-bg,rgba(255,255,255,.07));border:0.5px solid var(--ia-border,rgba(255,255,255,.13));border-radius:8px;padding:4px}
  .inv-seg label{flex:1;text-align:center;padding:9px;border-radius:5px;font-size:13px;cursor:pointer;color:var(--ia-text-muted,rgba(255,255,255,.78));position:relative}
  .inv-seg input{position:absolute;opacity:0;pointer-events:none}
  .inv-seg label:has(input:checked){background:var(--ia-accent,#BEF264);color:#0a0a0a;font-weight:600}
  .inv-paid{font-size:13px;color:#86efac;background:rgba(74,222,128,.10);border:0.5px solid rgba(74,222,128,.25);border-radius:8px;padding:11px 13px}
  .inv-ta,.inv-inp{width:100%;padding:9px 11px;border-radius:8px;border:0.5px solid var(--ia-border,rgba(255,255,255,.13));background:var(--ia-input-bg,rgba(255,255,255,.07));color:var(--ia-text,#f0f0f0);font-size:13px;font-family:inherit}
  .inv-ta{min-height:74px;resize:vertical;line-height:1.5}
  .inv-ta:focus,.inv-inp:focus{outline:none;border-color:var(--ia-accent,#BEF264);box-shadow:0 0 0 3px var(--ia-accent-soft,rgba(190,242,100,.12))}
  .inv-hlp{font-size:11.5px;color:var(--ia-text-dim,rgba(255,255,255,.55));margin-top:6px}
  .inv-f{display:flex;gap:8px;align-items:center;padding:14px 20px;border-top:0.5px solid var(--ia-border,rgba(255,255,255,.13));background:var(--ia-surface-2,#262626);flex-wrap:wrap}
  .inv-f .spacer{flex:1}
</style>

<dialog id="invoiceModal" class="inv-dialog">
  <form method="POST" id="invoiceForm" action="{{ route('tenant.appointments.invoice.email', $appointment->id) }}">
    @csrf
    <div class="inv-h">
      <div>
        <div class="t">Export invoice</div>
        <div class="s">{{ $appointment->ra_number }} · {{ $appointment->customerName() }}</div>
      </div>
      <button type="button" class="inv-x" onclick="document.getElementById('invoiceModal').close()">&times;</button>
    </div>

    <div class="inv-b">
      <div class="inv-grp">
        <span class="inv-lbl">Payment terms</span>
        @if($invPaid)
          <div class="inv-paid">&checkmark; Paid in full — prints as a paid receipt.</div>
        @else
          <div class="inv-seg">
            <label><input type="radio" name="terms" value="due_now" {{ $invTerms === 'due_now' ? 'checked' : '' }}><span>Due now</span></label>
            <label><input type="radio" name="terms" value="on_completion" {{ $invTerms !== 'due_now' ? 'checked' : '' }}><span>On completion</span></label>
          </div>
        @endif
      </div>

      <div class="inv-grp">
        <span class="inv-lbl">Note on invoice</span>
        <textarea class="inv-ta" name="note" placeholder="Optional — prints on the customer's invoice.">{{ $appointment->invoice_note }}</textarea>
      </div>

      <div class="inv-grp">
        <span class="inv-lbl">Email to</span>
        <input class="inv-inp" type="email" name="email" value="{{ $appointment->customer_email }}" placeholder="customer@email.com">
        <div class="inv-hlp">Sends as a PDF attachment through your Postmark stream.</div>
      </div>
    </div>

    <div class="inv-f">
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="invGo('preview')">Preview</button>
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" onclick="invGo('download')">Download</button>
      <div class="spacer"></div>
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Email invoice</button>
    </div>
  </form>
</dialog>

<script>
  function invGo(kind){
    var f = document.getElementById('invoiceForm');
    var checked = f.querySelector('input[name=terms]:checked');
    var terms = checked ? checked.value : '';
    var note  = encodeURIComponent(f.querySelector('[name=note]').value || '');
    var base = (kind === 'preview')
      ? @json(route('tenant.appointments.invoice.preview', $appointment->id))
      : @json(route('tenant.appointments.invoice.download', $appointment->id));
    var url = base + '?terms=' + encodeURIComponent(terms) + '&note=' + note;
    if (kind === 'preview') { window.open(url, '_blank'); }
    else { window.location = url; }
  }
  (function(){
    var d = document.getElementById('invoiceModal');
    if (d) d.addEventListener('click', function(e){ if (e.target === d) d.close(); });
  })();
</script>
