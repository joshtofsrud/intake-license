@extends('layouts.tenant.app')
@php $pageTitle = 'Uncategorized'; @endphp
{{-- MARKER-PATCH-HLC24 --}}
@section('content')
@php $bucketLabel = $activeBucket === '__none__' ? 'No catalog signal' : $activeBucket; @endphp
<style>@media(max-width:880px){.uc-grid{grid-template-columns:1fr !important}}</style>

<div style="max-width:1120px">
  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">Inventory</h1>
      <p class="ia-page-subtitle">Map uncategorized items · {{ number_format($total) }} remaining</p>
    </div>
  </div>

  @include('layouts.tenant._inventory-tabs')

  @if(session('flash'))
    <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
  @endif

  @if($total === 0)
    <div class="ia-card" style="text-align:center;padding:42px;color:var(--ia-text-dim)"><div style="font-size:30px">&#10003;</div>Everything's categorized.</div>
  @else
    {{-- buckets: gather, don't decide --}}
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
      @foreach($buckets as $b)
        @php $on = $activeBucket === $b['key']; @endphp
        <a href="{{ route('tenant.inventory.uncategorized', ['bucket' => $b['key']]) }}"
           style="display:flex;gap:9px;align-items:center;padding:9px 13px;border-radius:8px;text-decoration:none;font-size:13px;border:1px solid {{ $on ? 'var(--ia-accent)' : 'var(--ia-border)' }};background:{{ $on ? 'rgba(190,242,100,.13)' : 'var(--ia-surface)' }};color:var(--ia-text)">
          {{ $b['label'] }} <span style="font-family:var(--ia-mono);font-size:12px;color:{{ $on ? 'var(--ia-accent)' : 'var(--ia-text-dim)' }}">{{ $b['count'] }}</span>
        </a>
      @endforeach
      @if($noneCount > 0)
        @php $on = $activeBucket === '__none__'; @endphp
        <a href="{{ route('tenant.inventory.uncategorized', ['bucket' => '__none__']) }}"
           style="display:flex;gap:9px;align-items:center;padding:9px 13px;border-radius:8px;text-decoration:none;font-size:13px;border:1px dashed {{ $on ? 'var(--ia-accent)' : 'var(--ia-border-strong)' }};background:{{ $on ? 'rgba(190,242,100,.13)' : 'var(--ia-surface)' }};color:var(--ia-text-dim)">
          No catalog signal <span style="font-family:var(--ia-mono);font-size:12px">{{ $noneCount }}</span>
        </a>
      @endif
    </div>
    <p style="font-size:12px;color:var(--ia-text-mute);margin:8px 0 18px">Buckets come from the distributor's catalog category &mdash; they gather like items. They don't decide the destination; that's your call.</p>

    {{-- active bucket --}}
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <span style="font-size:16px;font-weight:600">{{ $bucketLabel }}</span>
      @if($activeBucket !== '__none__')<span style="font-size:11px;color:var(--ia-text-mute);font-family:var(--ia-mono);background:var(--ia-surface-2);border:1px solid var(--ia-border);border-radius:20px;padding:2px 9px">catalog category "{{ $activeBucket }}"</span>@endif
    </div>

    {{-- size sub-groups (touch of A) --}}
    {{-- MARKER-SPLIT-BY-CLIENT — the picker runs in the browser. Nothing is
         remembered; the default still comes from the server-side ranking. --}}
    @if(count($attrOptions))
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-mute);font-weight:600">Split by</span>
        <select id="ucAttr"
                style="background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:7px 10px;font-size:12.5px;min-width:280px">
          <option value="" @selected(! $activeAttr)>— no split —</option>
          @foreach($attrOptions as $o)
            <option value="{{ $o['key'] }}" data-label="{{ $o['label'] }}" @selected($activeAttr === $o['key'])>
              {{ $o['label'] }} — {{ $o['cov'] }}% · {{ $o['vals'] }} values{{ $o['qualifies'] ? '' : ' (' . $o['reason'] . ')' }}
            </option>
          @endforeach
        </select>
        <span id="ucAttrNote" style="font-size:12px;color:var(--ia-text-dim)"></span>
      </div>

      <div id="ucChips" style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:14px"></div>
    @endif

    <form method="POST" action="{{ route('tenant.inventory.uncategorized.assign') }}">
      @csrf
      <input type="hidden" name="category_id" id="catId">
      <div class="uc-grid" style="display:grid;grid-template-columns:1fr 350px;gap:18px;align-items:start">
        {{-- worklist --}}
        <div>
          <div class="ia-card" style="overflow:hidden">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
              <thead><tr style="text-align:left;color:var(--ia-text-dim)">
                <th style="width:28px;padding:10px 14px"><input type="checkbox" id="ucAllBox" onclick="ucAll(this.checked)"></th>
                <th style="padding:10px 14px">Item</th><th style="padding:10px 14px">Brand</th><th style="padding:10px 14px" id="ucColHead">{{ $activeAttrLabel }}</th>
              </tr></thead>
              <tbody>
              @forelse($items as $it)
                {{-- MARKER-ATTRS-QUOTING — double-quoted with {{ }} escaping. This was
                     single-quoted @json, and HLC writes inches as two apostrophes
                     (26''x2.30), which closed the attribute early and mangled the
                     tag on every tire row. --}}
                <tr class="uc-row" data-attrs="{{ json_encode($it->_attrs, JSON_UNESCAPED_SLASHES) }}" style="border-top:.5px solid var(--ia-border)">
                  {{-- MARKER-SPLIT-BY-CLIENT — values for every attribute ride on the row. --}}
                  <td style="padding:11px 14px"><input type="checkbox" class="uc-cb" name="item_ids[]" value="{{ $it->id }}" onchange="ucUpd()"></td>
                  <td style="padding:11px 14px"><div style="font-weight:600">{{ $it->name }}</div><div style="font-size:11px;color:var(--ia-text-dim);font-family:var(--ia-mono)">{{ $it->sku }}</div></td>
                  <td style="padding:11px 14px">{{ optional($it->distributorCatalog)->manufacturer ?? '—' }}</td>
                  <td style="padding:11px 14px"><span class="uc-val"></span></td>
                </tr>
              @empty
                <tr><td colspan="4" style="padding:24px;text-align:center;color:var(--ia-text-dim)">Nothing in this view.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
          <div style="margin:10px 2px 0;font-size:12px;color:var(--ia-text-dim)"><b id="ucN" style="color:var(--ia-accent);font-family:var(--ia-mono)">0</b> selected</div>
          @if($bucketTotal >= 500)<p style="font-size:12px;color:var(--ia-text-mute);margin-top:8px">Showing first 500 of this bucket. Assign some, then more will load.</p>@endif
        </div>

        {{-- destination --}}
        <div class="ia-card" style="position:sticky;top:16px;overflow:hidden">
          <div style="padding:13px 15px;border-bottom:1px solid var(--ia-border);font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-dim);font-weight:700">Assign selected to&hellip;</div>
          @if($recent->count())
            <div style="padding:11px 14px;border-bottom:1px solid var(--ia-border)">
              <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-mute);margin-bottom:7px">Recently used</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                @foreach($recent as $rc)
                  <span onclick="ucPick(@js($rc->id), {{ (int) $rc->_count }}, @js($rc->_path))" style="font-size:12px;padding:4px 10px;border-radius:20px;background:var(--ia-surface-2);border:1px solid var(--ia-border-strong);cursor:pointer;color:var(--ia-text-dim)">{{ $rc->name }}</span>
                @endforeach
              </div>
            </div>
          @endif
          <div id="ucTree" style="padding:8px;max-height:250px;overflow:auto">
            @forelse($tree as $node)
              <div class="uc-node" data-cid="{{ $node['id'] }}" onclick="ucPick(@js($node['id']), {{ $node['count'] }}, @js($node['path']))"
                   style="display:flex;align-items:center;gap:7px;padding:6px 8px;padding-left:{{ 8 + $node['depth'] * 16 }}px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:{{ $node['depth'] === 0 ? '600' : '400' }}">
                @if($node['depth'] > 0)<span style="color:var(--ia-text-mute);font-family:var(--ia-mono);font-size:12px">&#9492;</span>@endif
                {{ $node['name'] }} <span style="margin-left:auto;font-size:11px;color:var(--ia-text-mute);font-family:var(--ia-mono)">{{ $node['count'] }}</span>
              </div>
            @empty
              <div style="padding:14px;font-size:12.5px;color:var(--ia-text-dim)">No categories yet. <a href="{{ route('tenant.inventory.categories.index') }}" style="color:var(--ia-accent)">Create one</a> first.</div>
            @endforelse
          </div>
          <div style="padding:13px 15px;border-top:1px solid var(--ia-border)">
            <div id="ucCrumb" style="font-size:12px;color:var(--ia-text-dim);font-family:var(--ia-mono);margin-bottom:10px;min-height:16px">Pick a destination.</div>
            <div id="ucDelta" style="display:none;align-items:center;gap:9px;font-size:13px;color:var(--ia-text-dim);margin-bottom:12px">
              <span id="ucHave" style="font-family:var(--ia-mono);font-size:17px;font-weight:700;color:var(--ia-text)">0</span><span>&rarr;</span><span id="ucAfter" style="font-family:var(--ia-mono);font-size:17px;font-weight:700;color:var(--ia-accent)">0</span>
              <span id="ucAdding" style="margin-left:auto;font-size:12px;color:var(--ia-accent)"></span>
            </div>
            <button type="submit" class="ia-btn ia-btn--primary" id="ucAssign" disabled style="width:100%;justify-content:center">Select items to assign</button>
          </div>
          <div style="padding:11px 15px;border-top:1px solid var(--ia-border)">
            <span onclick="ucToggleNew()" style="color:var(--ia-accent);font-size:13px;font-weight:600;cursor:pointer">&#65291; New category&hellip;</span>
            <div id="ucNewForm" style="display:none;flex-direction:column;gap:8px;margin-top:10px">
              <input id="ucNewName" placeholder="Category name" style="padding:8px 10px;border-radius:6px;border:1px solid var(--ia-border-strong);background:var(--ia-bg);color:var(--ia-text);font-size:13px">
              <select id="ucNewParent" style="padding:8px 10px;border-radius:6px;border:1px solid var(--ia-border-strong);background:var(--ia-bg);color:var(--ia-text);font-size:13px">
                <option value="">No parent (top level)</option>
                @foreach($tree as $o)<option value="{{ $o['id'] }}">{{ str_repeat('— ', $o['depth']) }}{{ $o['name'] }}</option>@endforeach
              </select>
              <button type="button" onclick="ucCreateCat()" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center">Create &amp; select</button>
            </div>
            <a href="{{ route('tenant.inventory.categories.index') }}" style="display:block;margin-top:8px;color:var(--ia-text-dim);font-size:12px;text-decoration:none">Manage all categories &rarr;</a>
          </div>
        </div>
      </div>
    </form>
  @endif
</div>

<script>
  let ucHave = null, ucCid = null;
  function ucSel(){ return document.querySelectorAll('.uc-cb:checked').length; }
  function ucUpd(){
    const n = ucSel();
    document.getElementById('ucN').textContent = n;
    const btn = document.getElementById('ucAssign');
    if (n === 0){ btn.disabled = true; btn.textContent = 'Select items to assign'; }
    else if (!ucCid){ btn.disabled = true; btn.textContent = 'Pick a destination'; }
    else { btn.disabled = false; btn.textContent = 'Assign ' + n + ' item' + (n === 1 ? '' : 's') + ' \u2192'; }
    if (ucCid !== null && ucHave !== null){
      document.getElementById('ucAfter').textContent = ucHave + n;
      document.getElementById('ucAdding').textContent = n ? ('+' + n) : '';
    }
  }
  function ucPick(cid, have, path){
    ucCid = cid; ucHave = have;
    document.getElementById('catId').value = cid;
    document.querySelectorAll('.uc-node').forEach(x => {
      const on = x.dataset.cid === cid;
      x.style.background = on ? 'rgba(190,242,100,.13)' : '';
      x.style.color = on ? 'var(--ia-accent)' : '';
    });
    document.getElementById('ucCrumb').innerHTML = String(path).replace(/\u203a/g, '<span style="color:var(--ia-text-mute)">\u203a</span>');
    document.getElementById('ucDelta').style.display = 'flex';
    document.getElementById('ucHave').textContent = have;
    ucUpd();
  }
  const UC_QUICK = '{{ route('tenant.inventory.categories.quick') }}';
  function ucToggleNew(){ const f = document.getElementById('ucNewForm'); f.style.display = (f.style.display === 'none' || !f.style.display) ? 'flex' : 'none'; }
  async function ucCreateCat(){
    const name = document.getElementById('ucNewName').value.trim();
    if (!name){ return; }
    const parent = document.getElementById('ucNewParent').value || null;
    const token = document.querySelector('input[name=_token]').value;
    let c;
    try {
      const res = await fetch(UC_QUICK, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':token,'Accept':'application/json'}, body: JSON.stringify({ name, parent_id: parent }) });
      if (!res.ok){ alert('Could not create category.'); return; }
      c = await res.json();
    } catch (e){ alert('Could not create category.'); return; }
    const tree = document.getElementById('ucTree');
    const div = document.createElement('div');
    div.className = 'uc-node';
    div.dataset.cid = c.id;
    div.style.cssText = 'display:flex;align-items:center;gap:7px;padding:6px 8px;padding-left:' + (8 + (c.depth || 0) * 16) + 'px;border-radius:6px;cursor:pointer;font-size:13px';
    div.innerHTML = (c.depth > 0 ? '<span style="color:var(--ia-text-mute);font-family:var(--ia-mono);font-size:12px">&#9492;</span> ' : '') + c.name + ' <span style="margin-left:auto;font-size:11px;color:var(--ia-text-mute);font-family:var(--ia-mono)">0</span>';
    div.onclick = function(){ ucPick(c.id, 0, c.path); };
    const parentEl = parent ? document.querySelector('.uc-node[data-cid="' + parent + '"]') : null;
    if (parentEl) { parentEl.insertAdjacentElement('afterend', div); } else { tree.appendChild(div); }
    // Make the new category immediately available as a parent for the next create.
    const psel = document.getElementById('ucNewParent');
    if (psel && !psel.querySelector('option[value="' + c.id + '"]')) {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = '— '.repeat(c.depth || 0) + c.name;
      psel.appendChild(opt);
    }
    ucPick(c.id, 0, c.path);
    ucToggleNew();
    document.getElementById('ucNewName').value = '';
  }

/* MARKER-SPLIT-BY-CLIENT ------------------------------------------------
   Filtering happens here rather than on the server, so the page never
   reloads and never jumps to the top.

   The rule that matters: a hidden row's checkbox is still inside the form.
   Filter to 29", hit select-all, and without this you would assign every
   wheel in the bucket instead of the 71 on screen. So any change that hides
   rows clears their selection first, and select-all is scoped to what's
   visible. */
const UC_VALUES = @json($valuesByAttr ?? []);
const UC_MAX_CHIPS = 16;
let ucAttr = @json($activeAttr);
let ucVal  = null;

function ucAll(checked){
  document.querySelectorAll('.uc-row').forEach(tr => {
    if (tr.style.display === 'none') return;          // visible only
    const cb = tr.querySelector('.uc-cb');
    if (cb) cb.checked = checked;
  });
  ucUpd();
}

function ucLabel(){
  const sel = document.getElementById('ucAttr');  // MARKER-UCSEL-COLLISION
  const opt = sel && sel.selectedOptions[0];
  return (opt && opt.dataset.label) || 'Size';
}

function ucPaintRows(){
  let shown = 0;
  document.querySelectorAll('.uc-row').forEach(tr => {
    let vals = {};
    try { vals = JSON.parse(tr.dataset.attrs || '{}'); } catch (e) {}
    const v = ucAttr ? (vals[ucAttr] || '') : '';

    const cell = tr.querySelector('.uc-val');
    if (cell) {
      cell.innerHTML = v
        ? '<span style="font-size:11px;font-family:var(--ia-mono);padding:2px 8px;border-radius:5px;background:var(--ia-surface-2);border:.5px solid var(--ia-border);color:var(--ia-text-dim)">'
          + v.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</span>'
        : '<span style="color:var(--ia-text-mute)">\u2014</span>';
    }

    const hide = ucVal !== null && v !== ucVal;
    tr.style.display = hide ? 'none' : '';
    if (hide) {
      const cb = tr.querySelector('.uc-cb');
      if (cb) cb.checked = false;                     // never assign unseen rows
    } else { shown++; }
  });

  const head = document.getElementById('ucColHead');
  if (head) head.textContent = ucAttr ? ucLabel() : 'Size';

  const all = document.getElementById('ucAllBox');
  if (all) all.checked = false;

  ucUpd();
  return shown;
}

function ucPaintChips(){
  const box = document.getElementById('ucChips');
  if (!box) return;
  if (!ucAttr){ box.innerHTML = ''; return; }

  const counts = UC_VALUES[ucAttr] || {};
  const entries = Object.entries(counts);
  const total = document.querySelectorAll('.uc-row').length;

  const pill = (label, count, on, val) =>
    '<button type="button" data-val="' + (val === null ? '' : String(val).replace(/"/g,'&quot;')) + '"'
    + ' style="padding:5px 11px;border-radius:20px;font-size:12.5px;font-weight:600;cursor:pointer;'
    + 'border:1px solid ' + (on ? 'var(--ia-text)' : 'var(--ia-border)') + ';'
    + 'background:' + (on ? 'var(--ia-text)' : 'var(--ia-surface-2)') + ';'
    + 'color:' + (on ? 'var(--ia-bg)' : 'var(--ia-text-dim)') + '">'
    + label + ' <span style="font-family:var(--ia-mono);opacity:.7">' + count + '</span></button>';

  let html = pill('All', total, ucVal === null, null);
  entries.slice(0, UC_MAX_CHIPS).forEach(([v, c]) => {
    html += pill(v.replace(/[<>&]/g, ch => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[ch])), c, ucVal === v, v);
  });
  if (entries.length > UC_MAX_CHIPS){
    html += '<span style="font-size:12px;color:var(--ia-text-mute)">+ '
         + (entries.length - UC_MAX_CHIPS) + ' more</span>';
  }
  box.innerHTML = html;

  box.querySelectorAll('button[data-val]').forEach(b => b.addEventListener('click', () => {
    const v = b.dataset.val;
    ucVal = (v === '' ? null : v);
    ucPaintChips();
    ucPaintRows();
  }));
}

function ucNote(){
  const el = document.getElementById('ucAttrNote');
  if (!el) return;
  const n = document.querySelectorAll('.uc-row').length;
  el.textContent = ucAttr
    ? 'grouping ' + n + ' items'
    : 'nothing in this bucket groups usefully \u2014 pick one above if you disagree';
}

/* MARKER-UCSEL-COLLISION — was `const ucSel`, which collided with the
   existing `function ucSel()` above that counts checked rows. Duplicate
   identifiers are a parse-time SyntaxError, so the whole script block was
   thrown away and none of this ever ran. */
const ucAttrSel = document.getElementById('ucAttr');
if (ucAttrSel) ucAttrSel.addEventListener('change', e => {
  ucAttr = e.target.value || null;
  ucVal = null;
  ucNote(); ucPaintChips(); ucPaintRows();
});

ucNote(); ucPaintChips(); ucPaintRows();
</script>
@endsection
