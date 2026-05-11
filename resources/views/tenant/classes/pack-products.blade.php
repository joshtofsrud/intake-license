@extends('layouts.tenant.app')
@php $pageTitle = 'Class Packs'; @endphp

@push('styles')
<style>
.cl-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.cl-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-subnav-tab:hover{color:var(--ia-text)}
.cl-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.cl-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-table-head{display:grid;grid-template-columns:1fr 80px 100px 100px 80px 48px;gap:14px;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2);font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500}
.cl-table-row{display:grid;grid-template-columns:1fr 80px 100px 100px 80px 48px;gap:14px;align-items:center;padding:13px 16px;border-bottom:0.5px solid var(--ia-border);font-size:13.5px;transition:background var(--ia-t)}
.cl-table-row:last-child{border-bottom:none}
.cl-table-row:hover{background:var(--ia-hover)}
.cl-table-row.is-inactive{opacity:.5}
.cl-name{font-weight:500;color:var(--ia-text)}
.cl-meta{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.cl-num{text-align:right;font-variant-numeric:tabular-nums;color:var(--ia-text-muted);font-size:13px}
.cl-actions{display:flex;gap:6px;justify-content:flex-end}
.cl-action-btn{width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.cl-action-btn:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-empty{padding:48px;text-align:center}
.cl-empty-icon{font-size:28px;margin-bottom:10px;opacity:.3}
.cl-empty-title{font-size:15px;font-weight:500;color:var(--ia-text);margin-bottom:6px}
.cl-empty-body{font-size:13px;color:var(--ia-text-muted)}
.cl-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:400;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .15s}
.cl-modal-overlay.is-open{opacity:1;pointer-events:all}
.cl-modal{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);width:100%;max-width:480px;padding:24px}
.cl-modal-title{font-size:15px;font-weight:600;margin-bottom:18px;color:var(--ia-text)}
.cl-field{margin-bottom:14px}
.cl-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500;margin-bottom:5px}
.cl-input,.cl-select,.cl-textarea{width:100%;padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t);font-family:inherit}
.cl-input:focus,.cl-select:focus,.cl-textarea:focus{border-color:var(--ia-accent)}
.cl-field-triple{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.cl-modal-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:0.5px solid var(--ia-border)}
.cl-price-wrap{position:relative}
.cl-price-wrap .cl-input{padding-left:22px}
.cl-price-sym{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:13px;color:var(--ia-text-muted);pointer-events:none}
.cl-per-credit{font-size:11px;color:var(--ia-text-muted);margin-top:4px}

/* Packs mobile card list — parallel render (patch #37) */
.cl-pk-mobile{display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-pk-row-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:8px}
.cl-pk-row-m:last-child{border-bottom:none}
.cl-pk-row-m.is-inactive{opacity:.55}
.cl-pk-top-m{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.cl-pk-identity-m{min-width:0;flex:1}
.cl-pk-name-m{font-size:15px;font-weight:500;color:var(--ia-text);line-height:1.25;display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.cl-pk-name-text-m{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-pk-chip-m{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;flex-shrink:0}
.cl-pk-chip-m.credits{background:rgba(117,168,224,.15);color:#75A8E0}
.cl-pk-chip-m.inactive{background:var(--ia-surface-2);color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em;font-size:10px}
.cl-pk-desc-m{font-size:12px;color:var(--ia-text-muted);margin-top:3px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cl-pk-actions-m{display:flex;gap:4px;flex-shrink:0}
.cl-pk-icon-btn-m{width:32px;height:32px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);font-family:inherit}
.cl-pk-icon-btn-m:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-pk-meta-row-m{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;align-items:center}
.cl-pk-meta-item-m{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.cl-pk-meta-label-m{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,rgba(255,255,255,.38));font-weight:500}
.cl-pk-meta-value-m{color:var(--ia-text);font-weight:500}
.cl-pk-meta-value-m.accent{color:var(--ia-accent)}
@media(max-width:640px){
  .cl-card > .cl-table-head,
  .cl-card > .cl-table-row{display:none}
  .cl-pk-mobile{display:block}
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes</h1>
    <p class="ia-page-subtitle">Credit packs customers buy upfront and redeem per class.</p>
  </div>
  <div class="ia-page-head-right">
    <button class="ia-btn ia-btn--primary" onclick="openAddModal()">+ New pack</button>
  </div>
</div>

<div class="cl-subnav-wrap"><nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}" class="cl-subnav-tab">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-subnav-tab">Schedule</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}" class="cl-subnav-tab is-active">Packs</a>
  <a href="{{ route('tenant.classes.reports') }}" class="cl-subnav-tab">Reports</a>
</nav></div>

@if(session('success'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif

<div class="cl-card">
  <div class="cl-table-head">
    <div>Pack</div>
    <div style="text-align:right">Credits</div>
    <div style="text-align:right">Expires</div>
    <div style="text-align:right">Price</div>
    <div style="text-align:right">Sold</div>
    <div></div>
  </div>

  @forelse($products as $p)
    <div class="cl-table-row {{ $p->is_active ? '' : 'is-inactive' }}">
      <div>
        <div class="cl-name">{{ $p->name }}</div>
        <div class="cl-meta">${{ number_format($p->price_cents / $p->credit_count / 100, 2) }} per class</div>
      </div>
      <div class="cl-num">{{ $p->credit_count }}</div>
      <div class="cl-num">{{ $p->expiry_days }}d</div>
      <div class="cl-num">${{ number_format($p->price_cents / 100, 2) }}</div>
      <div class="cl-num">{{ $p->customer_packs_count }}</div>
      <div class="cl-actions">
        <button class="cl-action-btn" title="Edit" onclick="openEditModal({{ $p->toJson() }})">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
        </button>
      </div>
    </div>
  @empty
    <div class="cl-empty">
      <div class="cl-empty-icon">🎟</div>
      <div class="cl-empty-title">No class packs yet</div>
      <div class="cl-empty-body">Create packs like a 10-class pass that customers buy upfront and redeem one credit per session.</div>
    </div>
  @endforelse

  {{-- Mobile card list (parallel render, ≤640px). Per-class price gets the
       lime accent — it's the implicit-savings signal customers buy on. --}}
  <div class="cl-pk-mobile">
    @forelse($products as $p)
      <div class="cl-pk-row-m {{ $p->is_active ? '' : 'is-inactive' }}">
        <div class="cl-pk-top-m">
          <div class="cl-pk-identity-m">
            <div class="cl-pk-name-m">
              <span class="cl-pk-name-text-m">{{ $p->name }}</span>
              <span class="cl-pk-chip-m credits">{{ $p->credit_count }} credits</span>
              @if(!$p->is_active)
                <span class="cl-pk-chip-m inactive">Inactive</span>
              @endif
            </div>
            @if($p->description)
              <div class="cl-pk-desc-m">{{ $p->description }}</div>
            @endif
          </div>
          <div class="cl-pk-actions-m">
            <button type="button" class="cl-pk-icon-btn-m" title="Edit" onclick="openEditModal({{ $p->toJson() }})">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div class="cl-pk-meta-row-m">
          <span class="cl-pk-meta-item-m"><span class="cl-pk-meta-label-m">Price</span> <span class="cl-pk-meta-value-m">${{ number_format($p->price_cents / 100, 2) }}</span></span>
          <span class="cl-pk-meta-item-m"><span class="cl-pk-meta-label-m">Per class</span> <span class="cl-pk-meta-value-m accent">${{ number_format($p->price_cents / $p->credit_count / 100, 2) }}</span></span>
          <span class="cl-pk-meta-item-m"><span class="cl-pk-meta-label-m">Expires</span> <span class="cl-pk-meta-value-m">{{ $p->expiry_days }}d</span></span>
          <span class="cl-pk-meta-item-m" style="margin-left:auto"><span class="cl-pk-meta-label-m">Sold</span> <span class="cl-pk-meta-value-m">{{ $p->customer_packs_count }}</span></span>
        </div>
      </div>
    @empty
      {{-- Desktop empty state renders above; nothing extra needed here. --}}
    @endforelse
  </div>
</div>

{{-- Add modal --}}
<div class="cl-modal-overlay" id="add-modal" onclick="if(event.target===this)closeAddModal()">
  <div class="cl-modal">
    <div class="cl-modal-title">New class pack</div>
    <form method="POST" action="{{ route('tenant.classes.packs.store') }}">
      @csrf
      <div class="cl-field">
        <label class="cl-label">Name</label>
        <input type="text" name="name" class="cl-input" required maxlength="120" placeholder="e.g. 10-Class Pack">
      </div>
      <div class="cl-field">
        <label class="cl-label">Description (optional)</label>
        <textarea name="description" class="cl-textarea" rows="2" maxlength="500"></textarea>
      </div>
      <div class="cl-field-triple">
        <div class="cl-field">
          <label class="cl-label">Credits</label>
          <input type="number" name="credit_count" id="add-credits" class="cl-input" required min="1" max="999" value="10" oninput="updatePerCredit('add')">
        </div>
        <div class="cl-field">
          <label class="cl-label">Expires (days)</label>
          <input type="number" name="expiry_days" class="cl-input" required min="1" max="730" value="180">
        </div>
        <div class="cl-field">
          <label class="cl-label">Price</label>
          <div class="cl-price-wrap">
            <span class="cl-price-sym">$</span>
            <input type="number" name="price_dollars" id="add-price" class="cl-input" required min="0" step="0.01" value="100.00" oninput="updatePerCredit('add')">
          </div>
          <div class="cl-per-credit" id="add-per-credit">$10.00 per class</div>
        </div>
      </div>
      <div class="cl-field">
        <label class="cl-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="hidden" name="is_active" value="0">
          <input type="checkbox" name="is_active" value="1" checked> Active
        </label>
      </div>
      <div class="cl-modal-footer">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeAddModal()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Create pack</button>
      </div>
    </form>
  </div>
</div>

{{-- Edit modal --}}
<div class="cl-modal-overlay" id="edit-modal" onclick="if(event.target===this)closeEditModal()">
  <div class="cl-modal">
    <div class="cl-modal-title">Edit pack</div>
    <form method="POST" id="edit-form" action="">
      @csrf @method('PATCH')
      <div class="cl-field">
        <label class="cl-label">Name</label>
        <input type="text" name="name" id="edit-name" class="cl-input" required maxlength="120">
      </div>
      <div class="cl-field">
        <label class="cl-label">Description</label>
        <textarea name="description" id="edit-description" class="cl-textarea" rows="2" maxlength="500"></textarea>
      </div>
      <div class="cl-field-triple">
        <div class="cl-field">
          <label class="cl-label">Credits</label>
          <input type="number" name="credit_count" id="edit-credits" class="cl-input" required min="1" max="999" oninput="updatePerCredit('edit')">
        </div>
        <div class="cl-field">
          <label class="cl-label">Expires (days)</label>
          <input type="number" name="expiry_days" id="edit-expiry" class="cl-input" required min="1" max="730">
        </div>
        <div class="cl-field">
          <label class="cl-label">Price</label>
          <div class="cl-price-wrap">
            <span class="cl-price-sym">$</span>
            <input type="number" name="price_dollars" id="edit-price" class="cl-input" required min="0" step="0.01" oninput="updatePerCredit('edit')">
          </div>
          <div class="cl-per-credit" id="edit-per-credit"></div>
        </div>
      </div>
      <div class="cl-field">
        <label class="cl-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="hidden" name="is_active" value="0">
          <input type="checkbox" name="is_active" id="edit-active" value="1"> Active
        </label>
      </div>
      <div class="cl-modal-footer">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Save changes</button>
      </div>
    </form>
  </div>
</div>

@endsection

@push('scripts')
<script>
(function(){
  var addModal  = document.getElementById('add-modal');
  var editModal = document.getElementById('edit-modal');
  var editForm  = document.getElementById('edit-form');
  var baseUrl   = "{{ route('tenant.classes.packs', ['subdomain' => request()->route('subdomain')]) }}";

  window.updatePerCredit = function(prefix){
    var credits = parseFloat(document.getElementById(prefix+'-credits').value) || 1;
    var price   = parseFloat(document.getElementById(prefix+'-price').value)   || 0;
    var per     = credits > 0 ? (price / credits) : 0;
    document.getElementById(prefix+'-per-credit').textContent = '$'+per.toFixed(2)+' per class';
  };

  window.openAddModal  = function(){ addModal.classList.add('is-open'); updatePerCredit('add'); }
  window.closeAddModal = function(){ addModal.classList.remove('is-open'); }

  window.openEditModal = function(p){
    editForm.action = baseUrl + '/' + p.id;
    document.getElementById('edit-name').value        = p.name;
    document.getElementById('edit-description').value = p.description || '';
    document.getElementById('edit-credits').value     = p.credit_count;
    document.getElementById('edit-expiry').value      = p.expiry_days;
    document.getElementById('edit-price').value       = (p.price_cents / 100).toFixed(2);
    document.getElementById('edit-active').checked    = p.is_active == 1;
    updatePerCredit('edit');
    editModal.classList.add('is-open');
  }
  window.closeEditModal = function(){ editModal.classList.remove('is-open'); }

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){ closeAddModal(); closeEditModal(); }
  });
})();
</script>
@endpush
