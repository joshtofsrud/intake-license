@extends('layouts.tenant.app')
@php
  $pageTitle = 'Resources';
  $reservedLime = '#BEF264';
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Resources</h1>
    <p class="ia-page-subtitle">The columns of your calendar — staff, benches, or work stations.</p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

<div class="ia-card" style="padding:18px 20px;margin-bottom:20px">
  <h2 class="ia-h3" style="margin-bottom:12px">Add a resource</h2>
  <form method="POST" action="{{ route('tenant.resources.store') }}" id="add-resource-form">
    @csrf
    <div style="display:grid;grid-template-columns:1.2fr 1.2fr 1fr auto;gap:10px;align-items:end">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Maya Rodriguez" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Subtitle (optional)</label>
        <input type="text" name="subtitle" maxlength="120" placeholder="e.g. Owner · Senior stylist" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Color</label>
        <x-tenant.color-picker :swatches="$swatches" name="color_hex" :selected="$swatches[0]" :reserved="$reservedLime" />
      </div>
      <div>
        <button type="submit" class="ia-btn ia-btn--primary">Add</button>
      </div>
    </div>
  </form>
</div>

<div class="ia-card" style="padding:0;overflow:hidden">
  <div style="padding:14px 20px;border-bottom:0.5px solid var(--ia-border);display:flex;align-items:center;justify-content:space-between">
    <span class="ia-label">{{ $resources->count() }} resource{{ $resources->count() === 1 ? '' : 's' }}</span>
    <span style="font-size:11px;opacity:.5">Drag rows to reorder · Drag affects calendar column order</span>
  </div>

  @if($resources->isEmpty())
    <div class="ia-empty" style="padding:40px;text-align:center">
      <div class="ia-empty-title">No resources yet</div>
      <div class="ia-empty-body" style="margin-top:6px">Add your first resource above to start filling your calendar.</div>
    </div>
  @else
    <div id="resource-list" data-csrf="{{ csrf_token() }}">
      @foreach($resources as $r)
        <div class="resource-row" data-resource-id="{{ $r->id }}"
             style="display:grid;grid-template-columns:auto 1.2fr 1.2fr 1fr auto auto;gap:14px;align-items:center;padding:12px 20px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface);{{ $r->is_active ? '' : 'opacity:.45' }}">
          <div class="drag-handle" style="cursor:grab;opacity:.4;font-size:14px;user-select:none">⋮⋮</div>

          <input type="text" data-field="name" value="{{ $r->name }}" maxlength="120" class="ia-input resource-edit" style="width:100%">

          <input type="text" data-field="subtitle" value="{{ $r->subtitle }}" maxlength="120" placeholder="—" class="ia-input resource-edit" style="width:100%">

          <div>
            <x-tenant.color-picker :swatches="$swatches" name="color_hex" :selected="$r->color_hex" :reserved="$reservedLime" :resourceId="$r->id" :compact="true" />
          </div>

          <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
            <input type="checkbox" data-field="is_active" {{ $r->is_active ? 'checked' : '' }} class="resource-edit-toggle">
            <span>{{ $r->is_active ? 'Active' : 'Inactive' }}</span>
          </label>

          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="deactivateResource('{{ $r->id }}')" style="font-size:11px">
            {{ $r->is_active ? 'Deactivate' : 'Already off' }}
          </button>
        </div>
      @endforeach
    </div>
  @endif
</div>

@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/resources.css') }}">
@endpush

@push('scripts')
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
  <script>
    (function () {
      'use strict';

      var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      var list = document.getElementById('resource-list');

      // ---- Drag-to-reorder ----
      if (list && window.Sortable) {
        Sortable.create(list, {
          handle: '.drag-handle',
          animation: 150,
          onEnd: function () {
            var ids = Array.from(list.querySelectorAll('.resource-row'))
                          .map(function (r) { return r.getAttribute('data-resource-id'); });
            fetch("{{ route('tenant.resources.reorder') }}", {
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

      // ---- Inline edit on blur ----
      document.querySelectorAll('.resource-edit, .resource-edit-toggle').forEach(function (el) {
        var evt = el.type === 'checkbox' ? 'change' : 'blur';
        el.addEventListener(evt, function () {
          var row = el.closest('.resource-row');
          var id  = row.getAttribute('data-resource-id');
          var field = el.getAttribute('data-field');
          var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
          var body = {};
          body[field] = value;
          fetch("{{ url('admin/resources') }}/" + id, {
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

      // ---- Color picker change handler (per-row) ----
      window.onResourceColorChange = function (resourceId, color) {
        if (!resourceId) return; // top form, ignore
        fetch("{{ url('admin/resources') }}/" + resourceId, {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
          body: JSON.stringify({ color_hex: color }),
        });
      };

      // ---- Deactivate ----
      window.deactivateResource = function (id) {
        if (!confirm('Deactivate this resource? Past appointments stay visible. New appointments cannot be assigned to it.')) return;
        fetch("{{ url('admin/resources') }}/" + id, {
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
