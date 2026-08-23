#!/usr/bin/env python3
"""Team member page: stop the contact fields clipping on mobile.

Regression from apply-team-contact-block.py. Two causes:
  * .pd-field is a fixed `180px 1fr` grid with no mobile override, so a
    narrow viewport keeps spending 180px on the label.
  * The name/email/phone inputs carried INLINE min-width (280/320px).
    An inline style can't be overridden by a media query, so the value
    column could never shrink — the email, the phone and the hint text
    ran off the right edge.

Fix: the widths move to a class, and the existing 720px breakpoint
stacks label over value with full-width controls.
Run from repo root: python3 apply-team-page-mobile-fix.py
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

VIEW = 'resources/views/tenant/team/show.blade.php'

# 1) Inline widths -> class, so the breakpoint can actually win.
sub(VIEW,
    """<input class="ia-input" name="name" value="{{ $member->name }}" style="min-width:280px">""",
    """<input class="ia-input pd-input" name="name" value="{{ $member->name }}">""",
    "markup: name input")

sub(VIEW,
    """<input class="ia-input" type="email" name="email" value="{{ $member->email }}" style="min-width:320px">""",
    """<input class="ia-input pd-input pd-input--wide" type="email" name="email" value="{{ $member->email }}">""",
    "markup: email input")

sub(VIEW,
    """<input class="ia-input" type="tel" name="phone" value="{{ $member->phone }}" maxlength="32" style="min-width:200px" placeholder="(509) 555-0142">""",
    """<input class="ia-input pd-input pd-input--narrow" type="tel" name="phone" value="{{ $member->phone }}" maxlength="32" placeholder="(509) 555-0142">""",
    "markup: phone input")

# 2) Class definitions + a real mobile stack.
sub(VIEW,
    """@media (max-width:720px) {
  .pd-head { flex-wrap:wrap; }
  .pd-contact-tiles { margin-left:0; width:100%; order:3; }
}""",
    """/* MARKER-TEAM-MOBILE — widths live here, not inline, so the breakpoint
   below can override them. Inline styles beat media queries. */
.pd-input { min-width:280px; max-width:100%; }
.pd-input--wide { min-width:320px; }
.pd-input--narrow { min-width:200px; }
@media (max-width:720px) {
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
}""",
    "css: mobile stack")

print("Done. No migration needed. view:clear after deploy.")
