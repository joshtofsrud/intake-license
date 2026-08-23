#!/usr/bin/env python3
"""Team member page: make the mobile rules actually apply.

apply-team-page-mobile-fix.py added the right rules in the wrong place.
The @media (max-width:720px) block landed ABOVE the base `.pd-field`
declaration. Media queries carry no extra specificity, so the later base
rule (`grid-template-columns:180px 1fr`) won the cascade and the mobile
override was dead on arrival — the patch deployed, the layout didn't
change, and it looked like the deploy had failed.

Fix: the block moves to the end of the stylesheet, after every rule it
needs to override. Also adds the same treatment for .pd-device and
.pd-loc-grid, which have the same fixed-column problem further down the
page (Credentials / Locations).
Run from repo root: python3 apply-team-mobile-cascade-fix.py
"""
import sys

VIEW = 'resources/views/tenant/team/show.blade.php'

MEDIA_BLOCK = """@media (max-width:720px) {
  .pd-head { flex-wrap:wrap; }
  .pd-contact-tiles { margin-left:0; width:100%; order:3; }
  /* Label over value — 180px of label on a phone leaves nothing for the
     field, which is what pushed the email and hint off-screen. */
  .pd-field { grid-template-columns:1fr; gap:6px; align-items:start; }
  .pd-field-value { flex-direction:column; align-items:stretch; gap:8px; }
  .pd-field-value .ia-input,
  .pd-field-value select,
  .pd-field-value .pd-input { width:100%; min-width:0; }
  .pd-field-value button { width:100%; }
  .pd-field-value > div { max-width:100%; }
}
"""

NEW_BLOCK = """
/* MARKER-TEAM-MOBILE-CASCADE — this block MUST stay last. A media query
   adds no specificity, so anything below it with equal specificity wins
   and these rules silently stop applying. */
@media (max-width:720px) {
  .pd-head { flex-wrap:wrap; }
  .pd-contact-tiles { margin-left:0; width:100%; order:3; }
  /* 180px of label on a phone leaves nothing for the field — that is what
     pushed the email, phone and hint text off the right edge. */
  .pd-field { grid-template-columns:1fr; gap:6px; align-items:start; padding:14px 0; }
  .pd-field-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; }
  .pd-field-value { flex-direction:column; align-items:stretch; gap:8px; }
  .pd-field-value .ia-input,
  .pd-field-value select,
  .pd-field-value .pd-input { width:100%; min-width:0; }
  .pd-field-value button { width:100%; }
  .pd-field-value > div,
  .pd-field-value > span { max-width:100%; }
  .pd-field-value form { width:100%; }
  /* Same fixed-column problem on the cards further down. */
  .pd-device { grid-template-columns:1fr; gap:8px; }
  .pd-loc-grid { grid-template-columns:1fr; }
}
"""

s = open(VIEW).read()

if 'MARKER-TEAM-MOBILE-CASCADE' in s:
    print("SKIP (already applied)"); sys.exit(0)

# Lift the misplaced block out, if it's still there (apply-team-page-
# mobile-fix.py put it above the rules it needed to override).
if MEDIA_BLOCK in s:
    s = s.replace(MEDIA_BLOCK, '', 1)
    print("OK: removed the misplaced media block")

# ...and put it back at the very end of the stylesheet.
# The earlier patch's media block leaves an orphan closing brace behind in
# the first <style> element once its opening line is lifted out.
ORPHAN = """                    color:var(--ia-text-dim); font-weight:700; margin-bottom:10px; }

}
</style>"""
FIXED = """                    color:var(--ia-text-dim); font-weight:700; margin-bottom:10px; }
</style>"""
if ORPHAN in s:
    s = s.replace(ORPHAN, FIXED, 1)
    print("OK: removed orphan closing brace")


# NOTE: this view has TWO <style> blocks inside one @push('styles').
# The base .pd-* rules live in the SECOND one, so the override has to go
# at the end of the LAST block — appending to the first leaves it ahead
# of the rules it needs to beat, which is the whole bug being fixed.
if '</style>' not in s:
    print("FAIL: no closing </style> in the styles block"); sys.exit(1)
cut = s.rindex('</style>')
s = s[:cut] + NEW_BLOCK + s[cut:]

open(VIEW, 'w').write(s)
print("OK: media block moved to the end of the stylesheet")
print("OK: added .pd-device / .pd-loc-grid mobile columns")
print("Done. No migration needed. view:clear after deploy.")
