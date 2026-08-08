#!/usr/bin/env bash
# apply-parts-panel-empty-state.sh
#
# Serviced-assets panel, option 2: fix the per-asset Parts block when it is empty.
#
#   Before — a collapsed disclosure reading "Parts & products  0 ▾". Opening it
#            showed a faint "No products yet." line above a bare picker input.
#   After  — when empty the block is open, carries no count chip, and shows a
#            sunk box explaining what belongs there with one button that reveals
#            and focuses the picker. Populated state is unchanged.
#
# Also: the work-order disclosure's "0/4" becomes "4 fields, none filled".
#
# Guarded by MARKER-PARTS-EMPTY — re-running is a no-op. Exact string
# replacement, no regex. Anchors survive apply-asset-noun-hardcoded-labels.sh
# whether or not that patch has been applied.
set -euo pipefail
cd "$(dirname "$0")"

python3 - <<'PYEOF'
import sys

PATH_ = "resources/views/tenant/appointments/show-multi-asset.blade.php"
MARKER = "MARKER-PARTS-EMPTY"

src = open(PATH_, encoding="utf-8").read()
if MARKER in src:
    print("  skip (already patched)")
    raise SystemExit(0)

edits = []

# ---------------------------------------------------------------- 1. CSS
old_css = """.ma-asset-parts-empty {
  font-size: 12px;
  opacity: .4;
  margin: 4px 0 12px;
}"""
new_css = """.ma-asset-parts-empty {
  font-size: 12px;
  opacity: .4;
  margin: 4px 0 12px;
}
/* MARKER-PARTS-EMPTY — real empty state for the per-asset parts block */
.ma-parts-blank {
  display: flex; align-items: center; gap: 16px;
  padding: 14px 16px; margin: 2px 0 4px;
  background: rgba(0,0,0,0.18);
  border: 1px solid var(--ia-border);
  border-radius: 8px;
}
.ma-parts-blank-txt { font-size: 12px; line-height: 1.5; opacity: .6; }
.ma-parts-blank-txt b { display: block; font-size: 12.5px; opacity: .85; margin-bottom: 1px; font-weight: 600; }
.ma-parts-blank-btn {
  margin-left: auto; white-space: nowrap;
  font: inherit; font-size: 12px; padding: 7px 13px;
  background: transparent; border: 1px solid var(--ia-border);
  border-radius: 7px; color: var(--ia-text-dim); cursor: pointer;
}
.ma-parts-blank-btn:hover { border-color: var(--ia-accent, #BEF264); color: var(--ia-accent, #BEF264); }"""
edits.append((old_css, new_css))

# ------------------------------------------------- 2. open when empty, no 0 chip
old_open = """          <details class="ma-asset-parts" data-aa-id="{{ $aa->id }}" @if($aa->parts->isNotEmpty()) open @endif>
            <summary class="ma-asset-parts-head">
              <span class="ma-asset-parts-title">Parts &amp; products</span>
              <span class="ma-asset-parts-count">{{ $aa->parts->count() }}</span>
              <span class="ma-asset-parts-chev">▾</span>
            </summary>"""
new_open = """          {{-- MARKER-PARTS-EMPTY — empty parts blocks open with no count chip --}}
          <details class="ma-asset-parts" data-aa-id="{{ $aa->id }}" open>
            <summary class="ma-asset-parts-head">
              <span class="ma-asset-parts-title">Parts &amp; products</span>
              @if($aa->parts->isNotEmpty())
                <span class="ma-asset-parts-count">{{ $aa->parts->count() }}</span>
              @endif
              <span class="ma-asset-parts-chev">▾</span>
            </summary>"""
edits.append((old_open, new_open))

# ------------------------------------------------------- 3. the empty state box
old_blank = """              @else
                <p class="ma-asset-parts-empty">No products yet.</p>
              @endif"""
new_blank = """              @else
                {{-- MARKER-PARTS-EMPTY --}}
                <div class="ma-parts-blank">
                  <div class="ma-parts-blank-txt">
                    <b>No parts on this {{ tenant()->asset_label_singular ?: 'item' }} yet</b>
                    Anything fitted during the job — and anything sold alongside it — gets added
                    here and priced on the ticket.
                  </div>
                  <button type="button" class="ma-parts-blank-btn"
                          onclick="this.closest('.ma-asset-parts-body').querySelector('.ma-asset-part-pickerwrap').hidden = false;
                                   this.closest('.ma-asset-parts-body').querySelector('.ma-asset-part-picker').focus();
                                   this.closest('.ma-parts-blank').hidden = true;">
                    + Add a part
                  </button>
                </div>
              @endif"""
edits.append((old_blank, new_blank))

# ------------------------------- 4. hide the picker until the button reveals it
old_picker = """              <div class="ma-asset-part-pickerwrap">
                <input type="text" class="ia-input ma-asset-part-picker\""""
new_picker = """              <div class="ma-asset-part-pickerwrap" @if($aa->parts->isEmpty()) hidden @endif>
                <input type="text" class="ia-input ma-asset-part-picker\""""
edits.append((old_picker, new_picker))

# -------------------------------------------- 5. work-order count reads as prose
# label is built in PHP, not with inline @if — a directive glued to a word
# character is not compiled by Blade (\B@) and would fatal the whole view.
old_wolabel = """              $aaFilledCount      = $appointment->workOrderFields->filter(fn($f) => !empty($aaResponses[$f->id]->response_value ?? null))->count();"""
new_wolabel = """              $aaFilledCount      = $appointment->workOrderFields->filter(fn($f) => !empty($aaResponses[$f->id]->response_value ?? null))->count();
              // MARKER-PARTS-EMPTY — prose instead of "0/4"
              $aaWoTotal          = $appointment->workOrderFields->count();
              $aaWoLabel          = $aaFilledCount === 0
                                      ? $aaWoTotal . ($aaWoTotal === 1 ? ' field, none filled' : ' fields, none filled')
                                      : $aaFilledCount . ' of ' . $aaWoTotal . ' filled';"""
edits.append((old_wolabel, new_wolabel))

old_wo = """                <span class="ma-asset-parts-count">{{ $aaFilledCount }}/{{ $appointment->workOrderFields->count() }}</span>"""
new_wo = """                <span class="ma-asset-parts-count">{{ $aaWoLabel }}</span>"""
edits.append((old_wo, new_wo))

for old, new in edits:
    c = src.count(old)
    if c != 1:
        print(f"  !! anchor matched {c} times (expected 1): {old.strip()[:64]}")
        sys.exit(1)
    src = src.replace(old, new)

open(PATH_, "w", encoding="utf-8").write(src)
print(f"  patched {len(edits)} sites  {PATH_}")
PYEOF

echo
echo "  Next: php artisan optimize:clear"
