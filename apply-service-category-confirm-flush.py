#!/usr/bin/env python3
"""Seat the delete confirmation in the list instead of floating in it.

The panel was inset 14px on each side with its own rounded border, so it
sat as a separate box with visible gaps between it and the rows above and
below. Now it spans the full row width and joins the list's own dividing
lines — a highlighted row rather than a card dropped on top of one.
Run from repo root: python3 apply-service-category-confirm-flush.py
"""
import sys

VIEW = 'resources/views/tenant/services/index.blade.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

sub(VIEW,
    """.sv-cat-confirm{margin:0 14px 8px;padding:9px 11px;border-radius:8px;font-size:11.5px;line-height:1.5;
  border:1px solid rgba(251,191,36,.4);background:rgba(251,191,36,.07)}
.sv-cat-confirm.is-danger{border-color:rgba(240,120,120,.4);background:rgba(240,120,120,.07)}""",
    """/* MARKER-SVC-CAT-FLUSH — full-bleed inside the list so it reads as a
   highlighted row, not a card floating between two others. */
.sv-cat-confirm{margin:0;padding:10px 14px;border-radius:0;font-size:11.5px;line-height:1.5;
  border:0;border-top:1px solid rgba(251,191,36,.35);border-bottom:1px solid rgba(251,191,36,.35);
  border-left:3px solid rgba(251,191,36,.55);background:rgba(251,191,36,.07)}
.sv-cat-confirm.is-danger{border-top-color:rgba(240,120,120,.35);border-bottom-color:rgba(240,120,120,.35);
  border-left-color:rgba(240,120,120,.55);background:rgba(240,120,120,.07)}""",
    "view: flush panel")

print("Done. No migration needed. view:clear after deploy.")
