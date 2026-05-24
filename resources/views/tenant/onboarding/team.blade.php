@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  .team-question {
    background: #1a1a1a; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 22px; margin-bottom: 18px;
  }
  .team-q-label {
    font-size: 14px; font-weight: 600;
    color: #f0f0f0 !important; margin-bottom: 14px;
  }
  .team-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  @media (max-width: 700px) { .team-options { grid-template-columns: 1fr; } }
  .team-opt {
    background: #131313 !important; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 14px;
    cursor: pointer; text-align: center;
    transition: all 0.15s;
    color: #f0f0f0 !important;
  }
  .team-opt:hover { border-color: #5a5a5a; }
  .team-opt.selected {
    border-color: #D4FF3F;
    background: linear-gradient(180deg, rgba(212,255,63,0.05), #131313) !important;
  }
  .team-opt-label {
    font-weight: 600; font-size: 14px;
    color: #f0f0f0 !important;
  }
  .team-opt-meta {
    font-size: 11px; color: #888 !important; margin-top: 4px;
  }

  .owner-card {
    display: flex; align-items: center; gap: 14px;
    background: #131313; border: 1px solid #1f1f1f;
    border-radius: 10px; padding: 16px 18px;
    margin-top: 16px;
  }
  .owner-tag {
    margin-left: auto;
    font-size: 9.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.08em;
    padding: 3px 7px; border-radius: 3px;
    background: #1a1a1a; color: #888 !important;
    border: 1px solid #2a2a2a;
  }

  .team-list { margin-top: 18px; }
  .team-list-label {
    font-size: 12px; font-weight: 600;
    color: #c8c8c8 !important; margin-bottom: 8px;
  }
  .team-row {
    display: grid;
    grid-template-columns: 36px 1fr 1fr 32px;
    gap: 12px; align-items: center;
    padding: 11px 14px;
    background: #131313 !important; border: 1px solid #2a2a2a;
    border-radius: 10px; margin-bottom: 8px;
  }
  .team-row .swatch {
    width: 28px; height: 28px; border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.1);
  }
  .team-input {
    background: transparent !important;
    border: 1px solid #2a2a2a; border-radius: 6px;
    padding: 7px 10px;
    color: #f0f0f0 !important;
    font-family: inherit; font-size: 13px;
  }
  .team-input:focus { outline: none; border-color: #D4FF3F; }
  .team-input.invalid { border-color: #ef4444; }
  .team-x {
    background: transparent; border: none;
    color: #888 !important; cursor: pointer;
    font-size: 18px; padding: 4px;
    text-align: center;
  }
  .team-x:hover { color: #ef4444 !important; }
  .team-add {
    margin-top: 6px;
    background: transparent; border: 1px dashed #2a2a2a;
    border-radius: 10px; padding: 12px;
    width: 100%; color: #888 !important;
    font-family: inherit; font-size: 13px; cursor: pointer;
    transition: all 0.15s;
  }
  .team-add:hover { border-color: #D4FF3F; color: #D4FF3F !important; }

  .helper-block {
    font-size: 11.5px; color: #888 !important;
    margin-top: 12px; line-height: 1.5;
  }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 6 of 8</div>
    <h2 class="screen-title">Who else takes appointments?</h2>
    <p class="screen-sub">Each team member gets their own column on your calendar so customers can book with whoever they prefer.</p>
  </div>

  <div id="ob-error" class="err"></div>

  @php
    use App\Http\Controllers\Tenant\ResourceController;

    $allResources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

    $owner = $allResources->firstWhere(fn($r) => !is_null($r->staff_user_id));
    $additional = $allResources->filter(fn($r) => is_null($r->staff_user_id))->values();
    $isMulti = $additional->count() > 0;

    $usedColors = $allResources->pluck('color_hex')->filter()->unique()->values()->all();
    $availableColors = array_values(array_diff(ResourceController::SWATCHES, $usedColors));
    if (count($availableColors) === 0) {
        $availableColors = ResourceController::SWATCHES;
    }
  @endphp

  <div class="team-question">
    <div class="team-q-label">Are you a solo operation, or are there other people taking bookings?</div>
    <div class="team-options">
      <div class="team-opt {{ !$isMulti ? 'selected' : '' }}" data-mode="solo">
        <div class="team-opt-label">Just me</div>
        <div class="team-opt-meta">Solo shop · simpler setup</div>
      </div>
      <div class="team-opt {{ $isMulti ? 'selected' : '' }}" data-mode="multi">
        <div class="team-opt-label">Multiple people</div>
        <div class="team-opt-meta">Add your team below</div>
      </div>
    </div>

    @if($owner)
      <div class="owner-card">
        <div class="swatch" style="background: {{ $owner->color_hex }}; width:32px; height:32px; border-radius:50%;"></div>
        <div>
          <div style="font-weight:600">{{ $owner->name }}</div>
          <div style="font-size:12px; color:#888">{{ $owner->subtitle ?: 'Owner' }}</div>
        </div>
        <div class="owner-tag">You</div>
      </div>
    @endif
  </div>

  <div class="team-list" id="team-list" style="{{ $isMulti ? '' : 'display:none' }}">
    <div class="team-list-label">Your team</div>
    <div id="team-rows">
      @foreach($additional as $member)
        <div class="team-row" data-resource-id="{{ $member->id }}">
          <div class="swatch" style="background: {{ $member->color_hex }};"></div>
          <input type="text" class="team-input" data-field="name"
                 value="{{ $member->name }}" placeholder="Name" maxlength="100">
          <input type="text" class="team-input" data-field="subtitle"
                 value="{{ $member->subtitle }}" placeholder="Role (optional)" maxlength="100">
          <button type="button" class="team-x" data-remove>×</button>
        </div>
      @endforeach
    </div>
    <button type="button" class="team-add" id="team-add">+ Add team member</button>
    <div class="helper-block">
      Calendar colors are auto-assigned from a curated palette. Each person can change their own from staff settings later.
    </div>
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.services', []) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-continue">Continue → Payment</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL   = @json(route('tenant.onboarding.wizard.team.save', []));
  const ALL_COLORS = @json(\App\Http\Controllers\Tenant\ResourceController::SWATCHES);

  const opts   = document.querySelectorAll('.team-opt');
  const list   = document.getElementById('team-list');
  const rows   = document.getElementById('team-rows');
  const addBtn = document.getElementById('team-add');
  const cont   = document.getElementById('ob-continue');
  const errBox = document.getElementById('ob-error');

  let mode = @json($isMulti ? 'multi' : 'solo');

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) { errBox.textContent = msg; errBox.classList.add('show'); }
  function hideError() { errBox.classList.remove('show'); }

  function rgbToHex(rgb) {
    const m = (rgb || '').match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
    if (!m) return (rgb || '').toLowerCase();
    return '#' + [1,2,3].map(i => parseInt(m[i],10).toString(16).padStart(2,'0')).join('').toLowerCase();
  }

  function nextColor() {
    const used = new Set(
      Array.from(rows.querySelectorAll('.swatch'))
           .map(el => rgbToHex(el.style.background))
    );
    for (const c of ALL_COLORS) {
      if (!used.has(c.toLowerCase())) return c;
    }
    return ALL_COLORS[rows.children.length % ALL_COLORS.length];
  }

  function syncMode(newMode) {
    mode = newMode;
    opts.forEach(o => o.classList.toggle('selected', o.dataset.mode === mode));
    list.style.display = mode === 'multi' ? '' : 'none';
    if (mode === 'multi' && rows.children.length === 0) addRow();
  }

  function addRow() {
    const color = nextColor();
    const row = document.createElement('div');
    row.className = 'team-row';
    row.innerHTML =
      '<div class="swatch" style="background: ' + color + ';"></div>' +
      '<input type="text" class="team-input" data-field="name" placeholder="Name" maxlength="100">' +
      '<input type="text" class="team-input" data-field="subtitle" placeholder="Role (optional)" maxlength="100">' +
      '<button type="button" class="team-x" data-remove>&times;</button>';
    rows.appendChild(row);
    row.querySelector('[data-field="name"]').focus();
    wireRow(row);
  }

  function wireRow(row) {
    row.querySelector('[data-remove]').addEventListener('click', () => row.remove());
  }

  rows.querySelectorAll('.team-row').forEach(wireRow);
  opts.forEach(o => o.addEventListener('click', () => syncMode(o.dataset.mode)));
  addBtn.addEventListener('click', addRow);

  cont.addEventListener('click', async () => {
    hideError();

    const members = [];
    let invalid = false;

    if (mode === 'multi') {
      rows.querySelectorAll('.team-row').forEach(r => {
        const nameEl = r.querySelector('[data-field="name"]');
        const subEl  = r.querySelector('[data-field="subtitle"]');
        const swatch = r.querySelector('.swatch');
        const name   = nameEl.value.trim();

        nameEl.classList.remove('invalid');
        if (!name) { nameEl.classList.add('invalid'); invalid = true; return; }
        members.push({
          name,
          subtitle: subEl.value.trim(),
          color_hex: rgbToHex(swatch.style.background).toUpperCase(),
        });
      });

      if (invalid) {
        showError('Each team member needs a name. Remove rows you do not need.');
        return;
      }
    }

    cont.disabled = true;
    cont.textContent = 'Saving…';

    try {
      const fd = new FormData();
      fd.append('mode', mode);
      members.forEach((m, i) => {
        fd.append('members[' + i + '][name]',      m.name);
        fd.append('members[' + i + '][subtitle]',  m.subtitle);
        fd.append('members[' + i + '][color_hex]', m.color_hex);
      });

      const res = await fetch(SAVE_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error('Save failed (' + res.status + '). ' + text.substring(0, 140));
      }
      const json = await res.json();
      window.location.href = json.next_url;
    } catch (err) {
      showError(err.message || 'Something went wrong.');
      cont.disabled = false;
      cont.textContent = 'Continue → Payment';
    }
  });
})();
</script>
@endsection
