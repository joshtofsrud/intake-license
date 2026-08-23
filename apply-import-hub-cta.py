#!/usr/bin/env python3
"""Import hub: give the primary action a body.

Defect: each type card on /imports is wrapped in a bare <a> with no
button, no arrow, and no hover or focus styling — so the only thing
that reads as clickable on the whole screen is the starter-CSV link.
Keyboard users get no focus ring at all.

Fix: an explicit "Import customers" / "Import inventory" button on each
card, real hover + focus-visible states, starter CSV demoted to
secondary. Also: the preview screen's "Import 0 rows" button is
disabled with no explanation — say why.
Run from repo root: python3 apply-import-hub-cta.py
"""
import sys

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

STYLES = 'resources/views/tenant/imports/_styles.blade.php'
INDEX  = 'resources/views/tenant/imports/index.blade.php'
PREV   = 'resources/views/tenant/imports/preview.blade.php'

# ---------------------------------------------------------------- styles
sub(STYLES,
    """.imp-type{background:var(--ia-surface);border-radius:var(--ia-r-lg);
  box-shadow:inset 0 0 0 .5px var(--ia-border);padding:20px 24px}
.imp-type:hover{background:var(--ia-surface-2)}
.imp-type-hit{display:flex;flex-direction:column;gap:9px;text-decoration:none;color:var(--ia-text)}""",
    """/* MARKER-IMPORT-CTA — the card is the action, so it has to look like one. */
.imp-type{background:var(--ia-surface);border-radius:var(--ia-r-lg);
  box-shadow:inset 0 0 0 .5px var(--ia-border);padding:20px 24px;
  display:flex;flex-direction:column;transition:background .14s,box-shadow .14s}
.imp-type:hover{background:var(--ia-surface-2);box-shadow:inset 0 0 0 .5px var(--ia-border-strong)}
.imp-type:focus-within{box-shadow:inset 0 0 0 1px var(--ia-accent)}
.imp-type-hit{display:flex;flex-direction:column;gap:9px;text-decoration:none;color:var(--ia-text);
  border-radius:var(--ia-r,8px);outline:none}
.imp-type-hit:focus-visible{outline:2px solid var(--ia-accent);outline-offset:3px}
.imp-type-go{display:flex;align-items:center;gap:10px;margin-top:14px;padding-top:14px;
  border-top:.5px solid var(--ia-border)}
.imp-type-go .ia-btn{text-decoration:none}
.imp-type-go .imp-type-alt{font-size:11.5px;color:var(--ia-text-dim);text-decoration:none;
  border-bottom:.5px solid transparent}
.imp-type-go .imp-type-alt:hover{color:var(--ia-text);border-bottom-color:currentColor}
.imp-type-go .imp-type-alt:focus-visible{outline:2px solid var(--ia-accent);outline-offset:2px;border-radius:3px}
.imp-type-arrow{margin-left:auto;font-size:13px;color:var(--ia-text-dim);
  opacity:0;transform:translateX(-3px);transition:opacity .14s,transform .14s}
.imp-type:hover .imp-type-arrow{opacity:1;transform:none}""",
    "styles: card affordance")

# ---------------------------------------------------------------- index
sub(INDEX,
    """        </a>
        <div class="imp-type-links">
          <a href="{{ route('tenant.imports.template', $impKey) }}">Download a starter CSV</a>
        </div>""",
    """        </a>
        {{-- MARKER-IMPORT-CTA — explicit primary action; starter CSV is the aside. --}}
        <div class="imp-type-go">
          <a href="{{ route('tenant.imports.create', ['type' => $impKey]) }}" class="ia-btn ia-btn--primary ia-btn--sm">
            Import {{ strtolower($t['label']) }}
          </a>
          <a href="{{ route('tenant.imports.template', $impKey) }}" class="imp-type-alt">Download a starter CSV</a>
          <span class="imp-type-arrow" aria-hidden="true">&rarr;</span>
        </div>""",
    "index: primary button")

# ---------------------------------------------------------------- preview
sub(PREV,
    """  <button type="submit" class="ia-btn ia-btn--primary" @disabled($writes === 0)>
    Import {{ number_format($writes) }} {{ Str::plural('row', $writes) }}
  </button>""",
    """  {{-- MARKER-IMPORT-CTA — a dead button with no reason reads as a broken page. --}}
  @if($writes === 0)
    <span style="font-size:12px;color:var(--ia-text-dim);align-self:center;text-align:center">
      @if(($c['error'] ?? 0) > 0)
        Nothing can be written yet — every row has an error. Fix the file and upload it again.
      @else
        Nothing to write — every row already matches what's in Intake.
      @endif
    </span>
  @endif
  <button type="submit" class="ia-btn ia-btn--primary" @disabled($writes === 0)>
    Import {{ number_format($writes) }} {{ Str::plural('row', $writes) }}
  </button>""",
    "preview: explain the disabled state")

print("Done. No migration needed. view:clear after deploy.")
