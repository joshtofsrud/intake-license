@extends('layouts.tenant.app')

@php $pageTitle = 'Transaction History'; @endphp

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

  .h-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .h-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}

  .h-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-bottom:14px;flex-wrap:wrap
  }
  .h-search{
    flex:1;min-width:220px;max-width:380px;padding:9px 12px;
    background:var(--ia-input-bg);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;
    font-family:inherit
  }
  .h-search:focus{outline:none;border-color:var(--ia-accent)}
  .h-count{font-size:13px;color:var(--ia-text-dim)}

  .h-filters{
    display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px
  }
  .h-chip{
    padding:6px 12px;background:transparent;
    border:0.5px solid var(--ia-border);border-radius:99px;
    color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer;
    transition:all var(--ia-t)
  }
  .h-chip:hover{color:var(--ia-text);border-color:var(--ia-border-strong)}
  .h-chip.active{
    background:var(--ia-accent);color:var(--ia-accent-text);
    border-color:var(--ia-accent);font-weight:500
  }

  .h-table-wrap{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);overflow:hidden
  }
  .h-table{width:100%;border-collapse:collapse}
  .h-table thead th{
    text-align:left;padding:12px 14px;font-size:11px;font-weight:600;
    color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;
    background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border);
    user-select:none;white-space:nowrap
  }
  .h-table thead th.sortable{cursor:pointer}
  .h-table thead th.sortable:hover{color:var(--ia-text)}
  .h-table thead th .sort-arrow{
    display:inline-block;margin-left:4px;font-size:10px;
    color:var(--ia-text-muted);opacity:.5
  }
  .h-table thead th.sort-active .sort-arrow{
    color:var(--ia-accent);opacity:1
  }
  .h-table tbody td{
    padding:12px 14px;font-size:13px;color:var(--ia-text);
    border-bottom:0.5px solid var(--ia-border);vertical-align:middle
  }
  .h-table tbody tr:last-child td{border-bottom:none}
  .h-table tbody tr:hover{background:var(--ia-hover)}

  .h-sale-num{font-family:'SF Mono',Menlo,monospace;font-size:12px}
  .h-sale-num.muted{color:var(--ia-text-dim);font-style:italic}
  .h-status{
    display:inline-block;padding:2px 8px;border-radius:99px;
    font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:600
  }
  .h-status.draft{background:rgba(255,255,255,.06);color:var(--ia-text-dim)}
  .h-status.quote{background:rgba(190,242,100,.15);color:var(--ia-accent)}
  .h-status.unpaid{background:rgba(255,180,80,.15);color:#FFB450}
  .h-status.paid{background:rgba(120,200,120,.15);color:#78c878}
  .h-status.partial{background:rgba(255,180,80,.15);color:#FFB450}
  .h-status.refunded{background:rgba(240,149,149,.15);color:#F09595}

  .h-total{font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
  .h-total.refund{color:#F09595}
  .h-tx-group{font-family:'SF Mono',Menlo,monospace;font-size:11px;color:var(--ia-text-dim)}
  .h-tx-group.linked{color:var(--ia-accent)}
  .h-meta{color:var(--ia-text-dim);font-size:12px}

  .h-empty-search{
    padding:40px 20px;text-align:center;color:var(--ia-text-dim);font-size:13px
  }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Transaction History</h1>
    <p class="ia-page-subtitle">Every cart, sale, refund, draft, and quote.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link active">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
</div>

@if($rows->isEmpty())
  <div class="h-empty">
    <h3>No transactions yet</h3>
  </div>
@else
  <div class="h-filters" id="hFilters">
    <button type="button" class="h-chip active" data-filter="all">All</button>
    <button type="button" class="h-chip" data-filter="draft">Drafts</button>
    <button type="button" class="h-chip" data-filter="quote">Quotes</button>
    <button type="button" class="h-chip" data-filter="unpaid">Unpaid</button>
    <button type="button" class="h-chip" data-filter="paid">Paid</button>
    <button type="button" class="h-chip" data-filter="partial">Partial</button>
    <button type="button" class="h-chip" data-filter="refunded">Refunded</button>
  </div>

  <div class="h-toolbar">
    <div class="h-count">
      <span id="hShownCount">{{ $rows->count() }}</span> of {{ $rows->count() }}
    </div>
    <input type="text" class="h-search" id="hSearch" placeholder="Search sale #, customer name, or email…" autocomplete="off">
  </div>

  <div class="h-table-wrap">
    <table class="h-table">
      <thead>
        <tr>
          <th class="sortable" data-sort="sale_number">Sale #<span class="sort-arrow">↕</span></th>
          <th class="sortable" data-sort="status">Status<span class="sort-arrow">↕</span></th>
          <th class="sortable" data-sort="customer">Customer<span class="sort-arrow">↕</span></th>
          <th>Items</th>
          <th class="sortable" data-sort="total" style="text-align:right">Total<span class="sort-arrow">↕</span></th>
          <th>Tx group</th>
          <th class="sortable sort-active" data-sort="date">Date<span class="sort-arrow">↓</span></th>
          <th>Staff</th>
        </tr>
      </thead>
      <tbody id="hTbody">
        @foreach($rows as $r)
          <tr data-id="{{ $r['id'] }}"
              data-status="{{ $r['payment_status'] }}"
              data-sale-number="{{ $r['sale_number'] ?? '' }}"
              data-customer="{{ strtolower($r['customer'] ?? '') }}"
              data-email="{{ strtolower($r['customer_email'] ?? '') }}"
              data-total="{{ $r['total_cents'] }}"
              data-date="{{ $r['paid_at'] ?? $r['updated_at'] }}">
            <td>
              @if($r['sale_number'])
                <span class="h-sale-num">{{ $r['sale_number'] }}</span>
              @else
                <span class="h-sale-num muted">—</span>
              @endif
            </td>
            <td>
              <span class="h-status {{ $r['payment_status'] }}">{{ $r['payment_status'] }}</span>
            </td>
            <td>
              {{ $r['customer'] ?? '—' }}
              @if($r['customer_email'])
                <div class="h-meta">{{ $r['customer_email'] }}</div>
              @endif
            </td>
            <td>
              {{ $r['item_count'] }} {{ $r['item_count'] === 1 ? 'item' : 'items' }}
              @if($r['location_name'])
                <div class="h-meta">{{ $r['location_name'] }}</div>
              @endif
            </td>
            <td class="h-total {{ $r['is_refund'] ? 'refund' : '' }}">
              {{ $r['is_refund'] ? '-' : '' }}${{ number_format($r['total_cents'] / 100, 2) }}
            </td>
            <td>
              @if($r['transaction_id'])
                <span class="h-tx-group linked" title="{{ $r['transaction_id'] }}">
                  {{ substr($r['transaction_id'], -6) }}
                </span>
              @else
                <span class="h-tx-group">—</span>
              @endif
              @if($r['refund_of_sale_number'])
                <div class="h-meta">refund of {{ $r['refund_of_sale_number'] }}</div>
              @endif
            </td>
            <td class="h-meta">
              {{ $r['paid_at']
                ? \Carbon\Carbon::parse($r['paid_at'])->diffForHumans()
                : ($r['updated_at'] ? \Carbon\Carbon::parse($r['updated_at'])->diffForHumans() : '—') }}
            </td>
            <td class="h-meta">{{ $r['started_by'] ?? '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@endsection

@push('scripts')
<script>
const tbody = document.getElementById('hTbody');
const allRows = tbody ? Array.from(tbody.querySelectorAll('tr[data-id]')) : [];
const totalCount = allRows.length;
const shownCount = document.getElementById('hShownCount');

let currentSort = { key: 'date', dir: 'desc' };
let currentSearch = '';
let activeFilters = new Set(['all']);

function applyFilters() {
  if (!tbody) return;
  const q = currentSearch.toLowerCase().trim();

  let filtered = allRows.filter(row => {
    if (q) {
      const saleNum = (row.dataset.saleNumber || '').toLowerCase();
      const name = row.dataset.customer || '';
      const email = row.dataset.email || '';
      if (!saleNum.includes(q) && !name.includes(q) && !email.includes(q)) return false;
    }
    if (!activeFilters.has('all')) {
      if (!activeFilters.has(row.dataset.status)) return false;
    }
    return true;
  });

  filtered.sort((a, b) => {
    let av, bv;
    switch (currentSort.key) {
      case 'sale_number':
        av = a.dataset.saleNumber || '';
        bv = b.dataset.saleNumber || '';
        break;
      case 'status':
        av = a.dataset.status || '';
        bv = b.dataset.status || '';
        break;
      case 'customer':
        av = a.dataset.customer || '';
        bv = b.dataset.customer || '';
        break;
      case 'total':
        av = parseInt(a.dataset.total, 10) || 0;
        bv = parseInt(b.dataset.total, 10) || 0;
        break;
      case 'date':
      default:
        av = a.dataset.date || '';
        bv = b.dataset.date || '';
        break;
    }
    if (av < bv) return currentSort.dir === 'asc' ? -1 : 1;
    if (av > bv) return currentSort.dir === 'asc' ? 1 : -1;
    return 0;
  });

  // Re-render
  allRows.forEach(r => r.remove());
  filtered.forEach(r => tbody.appendChild(r));

  let emptyMsg = tbody.querySelector('.empty-search-row');
  if (filtered.length === 0) {
    if (!emptyMsg) {
      emptyMsg = document.createElement('tr');
      emptyMsg.className = 'empty-search-row';
      const cell = document.createElement('td');
      cell.setAttribute('colspan', '8');
      cell.className = 'h-empty-search';
      emptyMsg.appendChild(cell);
      tbody.appendChild(emptyMsg);
    }
    emptyMsg.querySelector('.h-empty-search').textContent = 'No transactions match the current filters.';
  } else if (emptyMsg) {
    emptyMsg.remove();
  }

  if (shownCount) shownCount.textContent = filtered.length;
}

// Search input
const searchInput = document.getElementById('hSearch');
if (searchInput) {
  searchInput.addEventListener('input', (e) => {
    currentSearch = e.target.value;
    applyFilters();
  });
}

// Status filter chips (multi-select)
document.querySelectorAll('.h-chip').forEach(chip => {
  chip.addEventListener('click', () => {
    const filter = chip.dataset.filter;
    if (filter === 'all') {
      // 'All' resets — clear others, set only 'all'
      activeFilters.clear();
      activeFilters.add('all');
    } else {
      // Toggle this filter; remove 'all' if it was active
      activeFilters.delete('all');
      if (activeFilters.has(filter)) {
        activeFilters.delete(filter);
      } else {
        activeFilters.add(filter);
      }
      // If nothing's active, default back to 'all'
      if (activeFilters.size === 0) activeFilters.add('all');
    }
    document.querySelectorAll('.h-chip').forEach(c => {
      c.classList.toggle('active', activeFilters.has(c.dataset.filter));
    });
    applyFilters();
  });
});

// Sortable headers
document.querySelectorAll('.h-table thead th.sortable').forEach(th => {
  th.addEventListener('click', () => {
    const key = th.dataset.sort;
    if (currentSort.key === key) {
      currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
      currentSort.key = key;
      currentSort.dir = key === 'date' ? 'desc' : 'asc';
    }
    document.querySelectorAll('.h-table thead th.sortable').forEach(h => {
      h.classList.remove('sort-active');
      const arrow = h.querySelector('.sort-arrow');
      if (arrow) arrow.textContent = '↕';
    });
    th.classList.add('sort-active');
    const arrow = th.querySelector('.sort-arrow');
    if (arrow) arrow.textContent = currentSort.dir === 'asc' ? '↑' : '↓';
    applyFilters();
  });
});
</script>
@endpush
