{{-- MARKER-PATCH-494 — Roles & access: custom named roles, per-section visibility --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Roles & access';
  $visibleKeys = array_keys($sections);
  $countFor = function ($role) use ($visibleKeys) {
      if ($role->sections === null) return count($visibleKeys);
      return count(array_intersect($visibleKeys, $role->sections));
  };
  $selChecked = $selected && $selected->sections !== null
      ? array_values(array_intersect($visibleKeys, $selected->sections))
      : $visibleKeys;
  $selIsOwner = $selected && $selected->is_system && $selected->name === 'Owner';
@endphp

@push('styles')
<style>
.ra-cols { display:grid; grid-template-columns:250px 1fr; gap:22px; align-items:start; }
@media(max-width:840px){ .ra-cols { grid-template-columns:1fr; } }

.ra-list { background:var(--ia-surface); border:0.5px solid var(--ia-border); border-radius:var(--ia-r-lg); overflow:hidden; }
.ra-list-h { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--ia-text-dim); font-weight:600; padding:14px 16px 10px; }
.ra-role { display:flex; align-items:center; gap:10px; padding:11px 16px; border-left:2px solid transparent; color:inherit; text-decoration:none; }
.ra-role:hover { background:rgba(255,255,255,.025); }
.ra-role.on { background:var(--ia-accent-soft); border-left-color:var(--ia-accent); }
.ra-role .nm { font-size:13.5px; font-weight:600; }
.ra-role.on .nm { color:var(--ia-accent); }
.ra-role .ct { font-size:11px; color:var(--ia-text-dim); margin-top:1px; }
.ra-role .lock { margin-left:auto; font-size:10px; color:var(--ia-text-dim); }
.ra-add { padding:12px 16px; border-top:0.5px solid var(--ia-border); }
.ra-add-btn { font-size:12.5px; color:var(--ia-accent); background:none; border:0; cursor:pointer; padding:0; }
.ra-add-form { display:none; gap:8px; margin-top:10px; }
.ra-add-form.open { display:flex; }

.ra-editor { background:var(--ia-surface); border:0.5px solid var(--ia-border); border-radius:var(--ia-r-lg); padding:20px 22px; }
.ra-ehead { display:flex; align-items:center; gap:12px; margin-bottom:4px; }
.ra-ehead input { font-size:17px; font-weight:600; background:transparent; border:0; color:var(--ia-text); border-bottom:1px solid transparent; padding:2px 0; min-width:0; }
.ra-ehead input:focus { outline:none; border-bottom-color:var(--ia-accent); }
.ra-badge { font-size:10.5px; font-weight:600; color:var(--ia-accent); background:var(--ia-accent-soft); border:1px solid var(--ia-accent); border-radius:99px; padding:2px 10px; white-space:nowrap; }
.ra-edesc { font-size:12px; color:var(--ia-text-dim); margin-bottom:20px; }

.ra-grp { margin-bottom:18px; }
.ra-grp-h { display:flex; align-items:center; justify-content:space-between; margin-bottom:9px; }
.ra-grp-h .t { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--ia-text-dim); font-weight:600; }
.ra-grp-h .all { font-size:11px; color:var(--ia-accent); background:none; border:0; cursor:pointer; padding:0; }
.ra-secs { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
@media(max-width:560px){ .ra-secs { grid-template-columns:1fr; } }
.ra-sec { display:flex; align-items:center; gap:10px; padding:9px 12px; border:0.5px solid var(--ia-border); border-radius:9px; background:rgba(255,255,255,.02); }
.ra-sec.off { opacity:.5; }
.ra-sec .sn { font-size:12.5px; font-weight:500; }
.ra-tog { appearance:none; -webkit-appearance:none; margin-left:auto; width:34px; height:20px; border-radius:99px; background:rgba(255,255,255,.06); border:1px solid var(--ia-border); position:relative; flex:none; cursor:pointer; transition:.15s; }
.ra-tog::after { content:""; position:absolute; top:2px; left:2px; width:14px; height:14px; border-radius:50%; background:#8a8a88; transition:.15s; }
.ra-tog:checked { background:var(--ia-accent-soft); border-color:var(--ia-accent); }
.ra-tog:checked::after { left:16px; background:var(--ia-accent); }
.ra-tog:disabled { cursor:default; opacity:.6; }

.ra-save { display:flex; align-items:center; gap:12px; margin-top:20px; border-top:0.5px solid var(--ia-border); padding-top:16px; flex-wrap:wrap; }
.ra-save .note { font-size:11.5px; color:var(--ia-text-dim); margin-left:auto; }

.ra-preview { margin-top:24px; }
.ra-preview .ph { font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--ia-text-dim); font-weight:600; margin-bottom:12px; }
.ra-navframe { display:flex; gap:16px; flex-wrap:wrap; }
.ra-navcol { width:220px; background:var(--ia-surface); border:0.5px solid var(--ia-border); border-radius:12px; padding:12px 10px; }
.ra-navcol .nc-t { font-size:11px; color:var(--ia-text-dim); padding:2px 8px 8px; font-weight:600; }
.ra-ni { display:flex; align-items:center; gap:9px; padding:6px 10px; border-radius:7px; font-size:12.5px; color:var(--ia-text-dim); }
.ra-ni::before { content:""; width:5px; height:5px; border-radius:50%; background:currentColor; flex:none; }
.ra-ni.hidden { opacity:.28; text-decoration:line-through; }
.ra-ni-head { font-size:9.5px; text-transform:uppercase; letter-spacing:.1em; color:var(--ia-text-dim); padding:12px 10px 4px; }
.ra-cap { font-size:11.5px; color:var(--ia-text-dim); max-width:240px; margin-top:6px; }
.ra-cap b { color:var(--ia-accent); }
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Roles &amp; access</h1>
    <p class="ia-page-subtitle">Build roles for how your shop actually works, and choose exactly which sections each one sees. Hidden sections leave the nav <em>and</em> are blocked by URL.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.team.index') }}" class="ia-btn ia-btn--ghost">&larr; Team</a>
  </div>
</div>

@if(session('success'))<div class="ia-flash ia-flash--success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="ia-flash ia-flash--error">{{ session('error') }}</div>@endif

<div class="ra-cols">
  {{-- roles list --}}
  <div class="ra-list">
    <div class="ra-list-h">Roles</div>
    @foreach($roles as $role)
      <a class="ra-role {{ $selected && $selected->id === $role->id ? 'on' : '' }}"
         href="{{ route('tenant.team.roles', ['role' => $role->id]) }}">
        <div>
          <div class="nm">{{ $role->name }}</div>
          <div class="ct">
            @if($role->is_system && $role->name === 'Owner')
              Full access
            @else
              {{ $countFor($role) }} of {{ count($visibleKeys) }} sections
            @endif
            · {{ $role->users_count }} {{ Str::plural('person', $role->users_count) }}
          </div>
        </div>
        @if($role->is_system && $role->name === 'Owner')<span class="lock">🔒</span>@endif
      </a>
    @endforeach
    <div class="ra-add">
      <button type="button" class="ra-add-btn" onclick="document.getElementById('ra-add-form').classList.toggle('open')">＋ New role</button>
      <form id="ra-add-form" class="ra-add-form" method="POST" action="{{ route('tenant.team.roles.store') }}">
        @csrf
        <input type="text" name="name" class="ia-input" placeholder="Role name" maxlength="60" required style="flex:1;min-width:0">
        <button class="ia-btn ia-btn--primary ia-btn--sm">Create</button>
      </form>
    </div>
  </div>

  {{-- editor --}}
  <div>
    @if($selected)
    <form method="POST" action="{{ route('tenant.team.roles.update', $selected->id) }}" class="ra-editor" id="ra-editor">
      @csrf @method('PATCH')
      <div class="ra-ehead">
        <input type="text" name="name" value="{{ $selected->name }}" maxlength="60" required
               @if($selIsOwner) disabled @endif>
        <span class="ra-badge">{{ $selected->users_count }} {{ Str::plural('person', $selected->users_count) }}</span>
      </div>
      <div class="ra-edesc">
        @if($selIsOwner)
          Owner always has everything and can't be edited.
        @else
          Choose what this role can open. Changes apply the next time each person loads a page.
        @endif
      </div>

      @foreach($groups as $gKey => $gLabel)
        @php $gSections = array_filter($sections, fn($d) => $d['group'] === $gKey); @endphp
        @continue(empty($gSections))
        <div class="ra-grp" data-group="{{ $gKey }}">
          <div class="ra-grp-h">
            <div class="t">{{ $gLabel }}</div>
            @unless($selIsOwner)<button type="button" class="all" data-toggle-all="{{ $gKey }}">Toggle all</button>@endunless
          </div>
          <div class="ra-secs">
            @foreach($gSections as $key => $def)
              @php $on = $selIsOwner || in_array($key, $selChecked, true); @endphp
              <label class="ra-sec {{ $on ? '' : 'off' }}">
                <span class="sn">{{ $def['label'] }}</span>
                <input type="checkbox" class="ra-tog" name="sections[]" value="{{ $key }}"
                       @checked($on) @if($selIsOwner) disabled @endif>
              </label>
            @endforeach
          </div>
        </div>
      @endforeach

      @unless($selIsOwner)
      <div class="ra-save">
        <button class="ia-btn ia-btn--primary">Save role</button>
        <a href="{{ route('tenant.team.roles', ['role' => $selected->id]) }}" class="ia-btn ia-btn--ghost">Cancel</a>
        @if(!$selected->is_system && $selected->users_count === 0)
          <button class="ia-btn ia-btn--ghost" form="ra-delete"
                  onclick="return confirm('Delete the {{ $selected->name }} role?')">Delete</button>
        @endif
        <span class="note">Owners always keep full access — no one can lock the account.</span>
      </div>
      @endunless
    </form>
    @if(!$selIsOwner && !$selected->is_system && $selected->users_count === 0)
      <form id="ra-delete" method="POST" action="{{ route('tenant.team.roles.destroy', $selected->id) }}">
        @csrf @method('DELETE')
      </form>
    @endif

    {{-- nav preview --}}
    <div class="ra-preview">
      <div class="ph">What a <span id="ra-pv-name">{{ $selected->name }}</span> sees</div>
      <div class="ra-navframe">
        <div class="ra-navcol" id="ra-navcol">
          <div class="nc-t">{{ $currentTenant->name }}</div>
        </div>
        <div class="ra-cap">Hidden items don't render at all in the real nav — struck through here just to make the point. Typing a hidden section's URL redirects out, so it's a <b>real permission</b>, not just a hidden link.</div>
      </div>
    </div>
    @endif
  </div>
</div>

<script>
(function () {
  const SECTIONS = @json(collect($sections)->map(fn($def, $key) => ['key' => $key, 'label' => $def['label'], 'group' => $def['group']])->values());
  const GROUPS   = @json($groups);
  const editor   = document.getElementById('ra-editor');
  const navcol   = document.getElementById('ra-navcol');
  if (!editor || !navcol) return;

  function checkedKeys() {
    const s = new Set();
    editor.querySelectorAll('.ra-tog').forEach(cb => { if (cb.checked || cb.disabled) s.add(cb.value); });
    return s;
  }

  function renderPreview() {
    const on = checkedKeys();
    navcol.querySelectorAll('.ra-ni, .ra-ni-head').forEach(el => el.remove());
    let lastGroup = null;
    SECTIONS.forEach(sec => {
      if (sec.group !== lastGroup && sec.group !== 'main') {
        if (sec.group !== lastGroup) {
          const h = document.createElement('div');
          h.className = 'ra-ni-head'; h.textContent = GROUPS[sec.group] || sec.group;
          navcol.appendChild(h);
        }
      }
      lastGroup = sec.group;
      const el = document.createElement('div');
      el.className = 'ra-ni' + (on.has(sec.key) ? '' : ' hidden');
      el.textContent = sec.label;
      navcol.appendChild(el);
    });
  }

  editor.addEventListener('change', e => {
    if (!e.target.classList.contains('ra-tog')) return;
    e.target.closest('.ra-sec').classList.toggle('off', !e.target.checked);
    renderPreview();
  });

  editor.querySelectorAll('[data-toggle-all]').forEach(btn => {
    btn.addEventListener('click', () => {
      const grp = editor.querySelector(`.ra-grp[data-group="${btn.dataset.toggleAll}"]`);
      const togs = [...grp.querySelectorAll('.ra-tog')];
      const target = !togs.every(t => t.checked);
      togs.forEach(t => { t.checked = target; t.closest('.ra-sec').classList.toggle('off', !target); });
      renderPreview();
    });
  });

  const nameInput = editor.querySelector('.ra-ehead input');
  if (nameInput) nameInput.addEventListener('input', () => {
    document.getElementById('ra-pv-name').textContent = nameInput.value || 'member';
  });

  renderPreview();
})();
</script>
@endsection
