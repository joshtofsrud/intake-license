@extends('layouts.tenant.app')

@php $pageTitle = 'Payment reconciliation'; @endphp
@section('title', 'Payment reconciliation')

@push('styles')
<style>
  .rec-wrap{max-width:980px;margin:0 auto;padding:8px 0 60px}
  .rec-head{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:8px;flex-wrap:wrap;gap:12px}
  .rec-head h1{font-size:22px;font-weight:700;letter-spacing:-.01em;margin:0}
  .rec-sub{color:var(--ia-text-muted);font-size:13px;margin-top:4px;max-width:620px}
  .rec-range{display:flex;gap:6px}
  .rec-range a{font-size:12px;padding:6px 11px;border-radius:var(--ia-r-md);border:1px solid var(--ia-border);color:var(--ia-text-muted);text-decoration:none}
  .rec-range a.on{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent);font-weight:600}
  .rec-summary{font-size:12.5px;color:var(--ia-text-dim);margin:14px 0}
  .rec-card{background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
  .rec-empty{padding:40px 24px;text-align:center;color:var(--ia-text-muted)}
  .rec-empty .ic{font-size:30px;display:block;margin-bottom:10px}
  table.rec{width:100%;border-collapse:collapse;font-size:13px}
  table.rec th,table.rec td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--ia-border)}
  table.rec th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-dim);font-weight:600}
  table.rec td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:500}
  table.rec tr:last-child td{border-bottom:none}
  .rec-pi{font-family:var(--ia-font-mono);font-size:11px;color:var(--ia-text-dim)}
  .rec-cand{font-size:12px;color:var(--ia-text-muted)}
  .rec-cand b{color:var(--ia-text);font-weight:500}
  .rec-none{font-size:12px;color:var(--ia-text-dim);font-style:italic}
  .rec-err{background:rgba(248,113,113,.08);border:0.5px solid rgba(248,113,113,.3);border-radius:var(--ia-r-md);padding:12px 16px;color:var(--ia-text);font-size:13px;margin-bottom:16px}
  .rec-btn{border:none;border-radius:var(--ia-r-md);padding:6px 12px;font-size:12px;font-weight:500;font-family:inherit;cursor:pointer;background:var(--ia-accent);color:var(--ia-accent-text)}
  .rec-btn:disabled{opacity:.5;cursor:default}
</style>
@endpush

@section('content')
<div class="rec-wrap">
  <div class="rec-head">
    <div>
      <h1>Payment reconciliation</h1>
      <div class="rec-sub">Succeeded Stripe payments with no matching record in your ledger — money taken that hasn't been recorded against a sale. This should normally be empty.</div>
    </div>
    <div class="rec-range">
      @foreach([7,30,90] as $d)
        <a href="{{ route('tenant.register.reconciliation', ['days' => $d]) }}" class="{{ $days === $d ? 'on' : '' }}">{{ $d }}d</a>
      @endforeach
    </div>
  </div>

  @if($error)
    <div class="rec-err">{{ $error }}</div>
  @endif

  <div class="rec-summary">Scanned {{ $scanned }} succeeded Stripe payment{{ $scanned === 1 ? '' : 's' }} over the last {{ $days }} days · {{ count($unmatched) }} unmatched.</div>

  <div class="rec-card">
    @if(empty($unmatched))
      <div class="rec-empty">
        <span class="ic">✅</span>
        Everything reconciles. No stranded payments in this window.
      </div>
    @else
      <table class="rec">
        <thead>
          <tr><th>Stripe payment</th><th>Date</th><th>Candidate sale</th><th class="num">Amount</th><th></th></tr>
        </thead>
        <tbody>
          @foreach($unmatched as $u)
            <tr data-pi="{{ $u['pi_id'] }}">
              <td>
                <div class="rec-pi">{{ $u['pi_id'] }}</div>
                @if($u['description'])<div class="rec-cand">{{ $u['description'] }}</div>@endif
              </td>
              <td>{{ tlocal(\Carbon\Carbon::createFromTimestamp($u['created'], 'UTC'), 'M j, Y g:i A') }}</td>
              <td>
                @if($u['candidate_sale'])
                  <div class="rec-cand"><b>{{ $u['candidate_sale']['sale_number'] ?? 'draft' }}</b> · {{ $u['candidate_sale']['customer'] ?? 'no customer' }}</div>
                  <div class="rec-cand">{{ ucfirst($u['candidate_sale']['status']) }} · {{ ucfirst($u['candidate_sale']['payment_status']) }} · {{ format_money($u['candidate_sale']['total_cents']) }}</div>
                @else
                  <span class="rec-none">No sale references this payment</span>
                @endif
              </td>
              <td class="num">{{ format_money($u['amount_cents']) }}</td>
              <td>
                @if($u['candidate_sale'])
                  <button type="button" class="rec-btn"
                          data-reconcile="{{ $u['pi_id'] }}"
                          data-sale="{{ $u['candidate_sale']['id'] }}">Record to sale</button>
                @else
                  <span class="rec-none">Open in Stripe</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<script>
(function(){
  var RECORD_URL = @json(route('tenant.register.reconciliation.record'));
  var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  document.querySelectorAll('[data-reconcile]').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var pi = btn.getAttribute('data-reconcile');
      var saleId = btn.getAttribute('data-sale');
      if (!confirm('Record this Stripe payment against the candidate sale? This writes a ledger entry.')) return;
      btn.disabled = true; btn.textContent = 'Recording…';
      try {
        var res = await fetch(RECORD_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ payment_intent: pi, sale_id: saleId }),
        });
        var d = await res.json();
        if (d.ok) {
          var row = btn.closest('tr');
          if (row) { row.style.opacity = '0.45'; }
          btn.textContent = 'Recorded ✓';
        } else {
          alert(d.error || 'Could not reconcile.');
          btn.disabled = false; btn.textContent = 'Record to sale';
        }
      } catch (e) {
        alert('Network error.');
        btn.disabled = false; btn.textContent = 'Record to sale';
      }
    });
  });
})();
</script>
@endsection
