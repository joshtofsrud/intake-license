@extends('layouts.tenant.app')

@php $pageTitle = 'Quotes'; @endphp

@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .quotes-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .quotes-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}
  .quotes-empty p{font-size:13px;line-height:1.5;max-width:420px;margin:0 auto}

  .quotes-table-wrap{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);overflow:hidden
  }
  .quotes-table{width:100%;border-collapse:collapse}
  .quotes-table thead th{
    text-align:left;padding:12px 16px;font-size:11px;font-weight:600;
    color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;
    background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border)
  }
  .quotes-table tbody td{
    padding:14px 16px;font-size:13px;color:var(--ia-text);
    border-bottom:0.5px solid var(--ia-border);vertical-align:middle
  }
  .quotes-table tbody tr:last-child td{border-bottom:none}
  .quotes-table tbody tr:hover{background:var(--ia-hover)}

  .q-customer-name{font-weight:500}
  .q-customer-email{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .q-meta-line{color:var(--ia-text-dim);font-size:12px}
  .q-total{font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
  .q-actions{display:flex;gap:6px;justify-content:flex-end}
  .q-btn-convert{
    padding:6px 12px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-sm);font-size:12px;font-weight:500;
    font-family:inherit;cursor:pointer
  }
  .q-btn-convert:hover{filter:brightness(.93)}
  .q-btn-discard{
    padding:6px 10px;background:transparent;border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-sm);color:var(--ia-text-dim);font-size:12px;
    font-family:inherit;cursor:pointer
  }
  .q-btn-discard:hover{color:#F09595;border-color:#F09595}

  .quotes-count{font-size:13px;color:var(--ia-text-dim);margin-bottom:14px}

  .reg-modal-bg{
    position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;
    align-items:center;justify-content:center;z-index:1000;padding:20px
  }
  .reg-modal-bg.open{display:flex}
  .reg-modal{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-xl);padding:24px;width:100%;max-width:380px
  }
  .reg-modal h2{font-size:18px;font-weight:600;margin-bottom:8px;color:var(--ia-text)}
  .reg-modal .lede{color:var(--ia-text-dim);font-size:13px;margin-bottom:18px}
  .reg-modal-actions{display:flex;gap:8px;margin-top:18px}
  .reg-btn-secondary{
    flex:1;padding:11px;background:var(--ia-surface-2);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-weight:500;
    font-family:inherit;cursor:pointer
  }
  .reg-btn-secondary:hover{border-color:var(--ia-border-strong)}
  .reg-btn-primary{
    flex:1;padding:11px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-sm);font-size:13px;font-weight:600;
    font-family:inherit;cursor:pointer
  }
  .reg-btn-primary:hover:not(:disabled){filter:brightness(.93)}
  .reg-btn-primary:disabled{opacity:.4;cursor:not-allowed}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Quotes</h1>
    <p class="ia-page-subtitle">Saved carts customers can come back to.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Sale</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link active">Quotes</a>
  <a href="{{ route('tenant.register.refunds.index') }}" class="reg-tab-link">Refunds</a>
</div>

@if($quotes->isEmpty())
  <div class="quotes-empty">
    <h3>No quotes yet</h3>
    <p>Save a cart as a quote from the register and it'll appear here. Quotes stay live until you discard them.</p>
  </div>
@else
  <div class="quotes-count">
    {{ $quotes->count() }} {{ $quotes->count() === 1 ? 'quote' : 'quotes' }}
  </div>
  <div class="quotes-table-wrap">
    <table class="quotes-table">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Items</th>
          <th style="text-align:right">Total</th>
          <th>Saved</th>
          <th>Staff</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($quotes as $q)
          <tr data-quote-id="{{ $q['id'] }}">
            <td>
              <div class="q-customer-name">{{ $q['customer'] ?? 'Walk-in' }}</div>
              @if($q['customer_email'])
                <div class="q-customer-email">{{ $q['customer_email'] }}</div>
              @endif
            </td>
            <td>
              {{ $q['item_count'] }} {{ $q['item_count'] === 1 ? 'item' : 'items' }}
              @if($q['location_name'])
                <div class="q-meta-line">{{ $q['location_name'] }}</div>
              @endif
            </td>
            <td class="q-total">${{ number_format($q['total_cents'] / 100, 2) }}</td>
            <td class="q-meta-line">{{ $q['updated_at'] ? \Carbon\Carbon::parse($q['updated_at'])->diffForHumans() : '' }}</td>
            <td class="q-meta-line">{{ $q['started_by'] ?? '—' }}</td>
            <td>
              <div class="q-actions">
                <button type="button" class="q-btn-convert" data-convert="{{ $q['id'] }}">Convert to sale</button>
                <button type="button" class="q-btn-discard" data-discard="{{ $q['id'] }}">Discard</button>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

<div class="reg-modal-bg" id="confirmModal">
  <div class="reg-modal">
    <h2 id="confirmTitle">Are you sure?</h2>
    <div class="lede" id="confirmMessage"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="confirmCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="confirmOkBtn">Confirm</button>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
const ROUTES = {
  draftBase:    @json(url('/admin/register/drafts')),
  registerBase: @json(route('tenant.register.index')),
};
const CSRF = document.querySelector('meta[name=csrf-token]').content;

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function confirmDialog(message, confirmLabel = 'Confirm', title = 'Are you sure?') {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    const okBtn = document.getElementById('confirmOkBtn');
    const cancelBtn = document.getElementById('confirmCancelBtn');
    okBtn.textContent = confirmLabel;
    const cleanup = (result) => {
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      closeModal('confirmModal');
      resolve(result);
    };
    const onOk = () => cleanup(true);
    const onCancel = () => cleanup(false);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    openModal('confirmModal');
  });
}

document.querySelectorAll('[data-convert]').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.convert;
    window.location = ROUTES.registerBase + '?resume=' + encodeURIComponent(id);
  });
});

document.querySelectorAll('[data-discard]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id = btn.dataset.discard;
    const ok = await confirmDialog(
      'This quote will be permanently deleted.',
      'Discard quote',
      'Discard this quote?'
    );
    if (!ok) return;
    try {
      const res = await fetch(ROUTES.draftBase + '/' + id, {
        method: 'DELETE',
        headers: {'Accept':'application/json', 'X-CSRF-TOKEN': CSRF},
      });
      const data = await res.json();
      if (!data.ok) {
        alert(data.error || 'Could not discard quote.');
        return;
      }
      const row = document.querySelector('[data-quote-id="' + id + '"]');
      if (row) row.remove();
      const remaining = document.querySelectorAll('[data-quote-id]').length;
      if (remaining === 0) window.location.reload();
      else {
        const countEl = document.querySelector('.quotes-count');
        if (countEl) countEl.textContent = remaining + (remaining === 1 ? ' quote' : ' quotes');
      }
    } catch (e) {
      alert('Network error discarding quote.');
    }
  });
});
</script>
@endpush
