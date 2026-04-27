@php
  \$pageTitle = 'Drop-off methods';
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Drop-off methods</h1>
    <p class="ia-page-subtitle">How customers tell you they're getting their items to you. Shown on the booking page.</p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

<div class="ia-card" style="padding:18px 20px;margin-bottom:20px">
  <h2 class="ia-h3" style="margin-bottom:12px">Add a drop-off method</h2>
  <form method="POST" action="{{ route('tenant.receiving-methods.store') }}" id="add-method-form">
    @csrf
    <div style="display:grid;grid-template-columns:1.2fr 1.6fr auto;gap:10px;align-items:end">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Scheduled appointment" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Description (optional)</label>
        <input type="text" name="description" maxlength="500" placeholder="e.g. Drop off during business hours" class="ia-input" style="width:100%">
      </div>
      <div>
        <button type="submit" class="ia-btn ia-btn--primary">Add</button>
      </div>
    </div>
    <div style="display:flex;gap:18px;margin-top:12px;font-size:12px">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="ask_for_time" value="1"> Ask for arrival time
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="ask_for_tracking" value="1"> Ask for shipment tracking number
      </label>
    </div>
  </form>
</div>

<div class="ia-card" style="padding:0;overflow:hidden">
  <div style="padding:14px 20px;border-bottom:0.5px solid var(--ia-border);display:flex;align-items:center;justify-content:space-between">
    <span class="ia-label">{{ \$methods->count() }} method{{ \$methods->count() === 1 ? '' : 's' }}</span>
    <span style="font-size:11px;opacity:.5">Drag rows to reorder · Order shown on booking page</span>
  </div>

  @if(\$methods->isEmpty())
    <div class="ia-empty" style="padding:40px;text-align:center">
      <div class="ia-empty-title">No drop-off methods yet</div>
      <div class="ia-empty-body" style="margin-top:6px">Add your first method above. Common ones: scheduled appointment, walk-in, ship to us, curbside.</div>
    </div>
  @else
    <div id="method-list" data-csrf="{{ csrf_token() }}">
      @foreach(\$methods as \$m)
        <div class="method-row" data-method-id="{{ \$m->id }}"
             style="display:grid;grid-template-columns:auto 1.2fr 1.6fr auto auto auto auto;gap:14px;align-items:center;padding:12px 20px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface);{{ \$m->is_active ? '' : 'opacity:.45' }}">
          <div class="drag-handle" style="cursor:grab;opacity:.4;font-size:14px;user-select:none">⋮⋮</div>

          <input type="text" data-field="name" value="{{ \$m->name }}" maxlength="120" class="ia-input method-edit" style="width:100%">

          <input type="text" data-field="description" value="{{ \$m->description }}" maxlength="500" placeholder="—" class="ia-input method-edit" style="width:100%">

          <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a time field on the booking page when this method is selected">
            <input type="checkbox" data-field="ask_for_time" {{ \$m->ask_for_time ? 'checked' : '' }} class="method-edit-toggle">
            <span>Time</span>
          </label>

          <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a tracking-number field on the booking page when this method is selected">
            <input type="checkbox" data-field="ask_for_tracking" {{ \$m->ask_for_tracking ? 'checked' : '' }} class="method-edit-toggle">
            <span>Tracking</span>
          </label>

          <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap">
            <input type="checkbox" data-field="is_active" {{ \$m->is_active ? 'checked' : '' }} class="method-edit-toggle">
            <span>{{ \$m->is_active ? 'Active' : 'Inactive' }}</span>
          </label>

          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="deactivateMethod('{{ \$m->id }}')" style="font-size:11px">
            {{ \$m->is_active ? 'Deactivate' : 'Already off' }}
          </button>
        </div>
      @endforeach
    </div>
  @endif
</div>

@endsection

@push('scripts')
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
  <script>
    (function () {
      'use strict';

      var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      var list = document.getElementById('method-list');

      // Drag-to-reorder
      if (list && window.Sortable) {
        Sortable.create(list, {
          handle: '.drag-handle',
          animation: 150,
          onEnd: function () {
            var ids = Array.from(list.querySelectorAll('.method-row'))
                          .map(function (r) { return r.getAttribute('data-method-id'); });
            fetch("{{ route('tenant.receiving-methods.reorder') }}", {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
              },
              body: JSON.stringify({ order: ids }),
            });
          }
        });
      }

      // Inline edit on blur (text) / change (checkbox)
      document.querySelectorAll('.method-edit, .method-edit-toggle').forEach(function (el) {
        var evt = el.type === 'checkbox' ? 'change' : 'blur';
        el.addEventListener(evt, function () {
          var row = el.closest('.method-row');
          var id  = row.getAttribute('data-method-id');
          var field = el.getAttribute('data-field');
          var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
          var body = {};
          body[field] = value;
          fetch("{{ url('admin/receiving-methods') }}/" + id, {
            method: 'PATCH',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'Accept': 'application/json',
            },
            body: JSON.stringify(body),
          }).then(function (r) {
            if (!r.ok) {
              row.style.outline = '1px solid #d04444';
              setTimeout(function () { row.style.outline = ''; }, 1500);
            }
          });
        });
      });

      window.deactivateMethod = function (id) {
        if (!confirm('Deactivate this drop-off method? Past bookings keep their snapshot. New bookings cannot use it.')) return;
        fetch("{{ url('admin/receiving-methods') }}/" + id, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
        }).then(function (r) {
          if (r.ok) window.location.reload();
        });
      };
    })();
  </script>
@endpush
