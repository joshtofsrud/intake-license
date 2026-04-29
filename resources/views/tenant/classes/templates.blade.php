@extends('layouts.tenant.app')
@php $pageTitle = 'Class Templates'; @endphp

@push('styles')
<style>
.cl-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.cl-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-subnav-tab:hover{color:var(--ia-text)}
.cl-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.cl-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-table-head{display:grid;grid-template-columns:1fr 70px 70px 90px 80px 48px;gap:14px;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2);font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500}
.cl-table-row{display:grid;grid-template-columns:1fr 70px 70px 90px 80px 48px;gap:14px;align-items:center;padding:13px 16px;border-bottom:0.5px solid var(--ia-border);font-size:13.5px;transition:background var(--ia-t)}
.cl-table-row:last-child{border-bottom:none}
.cl-table-row:hover{background:var(--ia-hover)}
.cl-table-row.is-inactive{opacity:.5}
.cl-name{font-weight:500;color:var(--ia-text)}
.cl-meta{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.cl-num{text-align:right;font-variant-numeric:tabular-nums;color:var(--ia-text-muted);font-size:13px}
.cl-actions{display:flex;gap:6px;justify-content:flex-end}
.cl-action-btn{width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.cl-action-btn:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:400;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .15s}
.cl-modal-overlay.is-open{opacity:1;pointer-events:all}
.cl-modal{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);width:100%;max-width:520px;padding:24px}
.cl-modal-title{font-size:15px;font-weight:600;margin-bottom:18px;color:var(--ia-text)}
.cl-field{margin-bottom:14px}
.cl-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500;margin-bottom:5px}
.cl-input,.cl-select,.cl-textarea{width:100%;padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t);font-family:inherit}
.cl-input:focus,.cl-select:focus,.cl-textarea:focus{border-color:var(--ia-accent)}
.cl-select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.4)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.cl-field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cl-field-triple{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.cl-modal-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:0.5px solid var(--ia-border)}
.cl-empty{padding:48px;text-align:center}
.cl-empty-icon{font-size:28px;margin-bottom:10px;opacity:.3}
.cl-empty-title{font-size:15px;font-weight:500;color:var(--ia-text);margin-bottom:6px}
.cl-empty-body{font-size:13px;color:var(--ia-text-muted)}
.cl-price-wrap{position:relative}
.cl-price-wrap .cl-input{padding-left:22px}
.cl-price-sym{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:13px;color:var(--ia-text-muted);pointer-events:none}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes</h1>
    <p class="ia-page-subtitle">Group sessions your customers can register for.</p>
  </div>
  <div class="ia-page-head-right">
    <button class="ia-btn ia-btn--primary" onclick="openAddModal()">+ New template</button>
  </div>
</div>

<nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}" class="cl-subnav-tab is-active">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-subnav-tab">Schedule</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}" class="cl-subnav-tab">Packs</a>
</nav>

@if(session('success'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="cl-card">
  <div class="cl-table-head">
    <div>Template</div>
    <div style="text-align:right">Duration</div>
    <div style="text-align:right">Capacity</div>
    <div style="text-align:right">Price</div>
    <div style="text-align:right">Upcoming</div>
    <div></div>
  </div>

  @forelse($templates as $t)
    <div class="cl-table-row {{ $t->is_active ? '' : 'is-inactive' }}">
      <div>
        <div class="cl-name">{{ $t->name }}</div>
        <div class="cl-meta">
          {{ $t->instructorResource?->name ?? 'No instructor set' }}
          @if($t->description)· {{ Str::limit($t->description, 60) }}@endif
        </div>
      </div>
      <div class="cl-num">{{ $t->duration_minutes }}m</div>
      <div class="cl-num">{{ $t->default_capacity }}</div>
      <div class="cl-num">{{ $t->price_cents > 0 ? '$'.number_format($t->price_cents/100,2) : 'Free' }}</div>
      <div class="cl-num">{{ $t->sessions_count }}</div>
      <div class="cl-actions">
        <button class="cl-action-btn" title="Edit" onclick="openEditModal({{ $t->toJson() }})">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
        </button>
        <button class="cl-action-btn" title="Delete" onclick="confirmDelete('{{ $t->id }}','{{ addslashes($t->name) }}')">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h10M5 4V2.5h4V4M5.5 6.5v4M8.5 6.5v4M3 4l.5 7.5h7L11 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>
    </div>
  @empty
    <div class="cl-empty">
      <div class="cl-empty-icon">🎯</div>
      <div class="cl-empty-title">No class templates yet</div>
      <div class="cl-empty-body">Create a template to define a class type — Vinyasa Flow, Spin, HIIT — then schedule sessions from it.</div>
    </div>
  @endforelse
</div>

{{-- Add modal --}}
<div class="cl-modal-overlay" id="add-modal" onclick="if(event.target===this)closeAddModal()">
  <div class="cl-modal">
    <div class="cl-modal-title">New class template</div>
    <form method="POST" action="{{ route('tenant.classes.templates.store') }}">
      @csrf
      <div class="cl-field">
        <label class="cl-label">Name</label>
        <input type="text" name="name" class="cl-input" required maxlength="120" placeholder="e.g. Vinyasa Flow">
      </div>
      <div class="cl-field">
        <label class="cl-label">Description (optional)</label>
        <textarea name="description" class="cl-textarea" rows="2" maxlength="1000"></textarea>
      </div>
      <div class="cl-field-triple">
        <div class="cl-field">
          <label class="cl-label">Duration (min)</label>
          <input type="number" name="duration_minutes" class="cl-input" required min="5" max="480" value="60">
        </div>
        <div class="cl-field">
          <label class="cl-label">Capacity</label>
          <input type="number" name="default_capacity" class="cl-input" required min="1" max="500" value="15">
        </div>
        <div class="cl-field">
          <label class="cl-label">Price</label>
          <div class="cl-price-wrap">
            <span class="cl-price-sym">$</span>
            <input type="number" name="price_dollars" class="cl-input" required min="0" step="0.01" value="0" placeholder="0.00">
          </div>
        </div>
      </div>
      <div class="cl-field">
        <label class="cl-label">Default instructor</label>
        <select name="instructor_resource_id" class="cl-select">
          <option value="">— No default —</option>
          @foreach($resources as $r)
            <option value="{{ $r->id }}">{{ $r->name }}{{ $r->subtitle ? ' · '.$r->subtitle : '' }}</option>
          @endforeach
        </select>
      </div>
      <div class="cl-field">
        <label class="cl-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="hidden" name="is_active" value="0">
          <input type="checkbox" name="is_active" value="1" checked> Active
        </label>
      </div>
      <div class="cl-modal-footer">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeAddModal()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Create template</button>
      </div>
    </form>
  </div>
</div>

{{-- Edit modal --}}
<div class="cl-modal-overlay" id="edit-modal" onclick="if(event.target===this)closeEditModal()">
  <div class="cl-modal">
    <div class="cl-modal-title">Edit template</div>
    <form method="POST" id="edit-form" action="">
      @csrf
      @method('PATCH')
      <div class="cl-field">
        <label class="cl-label">Name</label>
        <input type="text" name="name" id="edit-name" class="cl-input" required maxlength="120">
      </div>
      <div class="cl-field">
        <label class="cl-label">Description</label>
        <textarea name="description" id="edit-description" class="cl-textarea" rows="2" maxlength="1000"></textarea>
      </div>
      <div class="cl-field-triple">
        <div class="cl-field">
          <label class="cl-label">Duration (min)</label>
          <input type="number" name="duration_minutes" id="edit-duration" class="cl-input" required min="5" max="480">
        </div>
        <div class="cl-field">
          <label class="cl-label">Capacity</label>
          <input type="number" name="default_capacity" id="edit-capacity" class="cl-input" required min="1" max="500">
        </div>
        <div class="cl-field">
          <label class="cl-label">Price</label>
          <div class="cl-price-wrap">
            <span class="cl-price-sym">$</span>
            <input type="number" name="price_dollars" id="edit-price" class="cl-input" required min="0" step="0.01">
          </div>
        </div>
      </div>
      <div class="cl-field">
        <label class="cl-label">Default instructor</label>
        <select name="instructor_resource_id" id="edit-instructor" class="cl-select">
          <option value="">— No default —</option>
          @foreach($resources as $r)
            <option value="{{ $r->id }}">{{ $r->name }}{{ $r->subtitle ? ' · '.$r->subtitle : '' }}</option>
          @endforeach
        </select>
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

<form method="POST" id="delete-form" action="" style="display:none">
  @csrf
  @method('DELETE')
</form>

@endsection

@push('scripts')
<script>
(function(){
  var addModal  = document.getElementById('add-modal');
  var editModal = document.getElementById('edit-modal');
  var editForm  = document.getElementById('edit-form');
  var deleteForm = document.getElementById('delete-form');
  var baseUrl   = "{{ route('tenant.classes.templates', ['subdomain' => request()->route('subdomain')]) }}";

  window.openAddModal  = function(){ addModal.classList.add('is-open'); }
  window.closeAddModal = function(){ addModal.classList.remove('is-open'); }

  window.openEditModal = function(t){
    editForm.action = baseUrl + '/' + t.id;
    document.getElementById('edit-name').value        = t.name;
    document.getElementById('edit-description').value = t.description || '';
    document.getElementById('edit-duration').value    = t.duration_minutes;
    document.getElementById('edit-capacity').value    = t.default_capacity;
    document.getElementById('edit-price').value       = (t.price_cents / 100).toFixed(2);
    document.getElementById('edit-active').checked    = t.is_active == 1;
    var sel = document.getElementById('edit-instructor');
    sel.value = t.instructor_resource_id || '';
    editModal.classList.add('is-open');
  }
  window.closeEditModal = function(){ editModal.classList.remove('is-open'); }

  window.confirmDelete = function(id, name){
    if(!confirm('Delete "' + name + '"? This cannot be undone.')) return;
    deleteForm.action = baseUrl + '/' + id;
    deleteForm.submit();
  }

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){ closeAddModal(); closeEditModal(); }
  });
})();
</script>
@endpush
