#!/usr/bin/env python3
"""Page builder: follow the admin theme.

The builder declares its own --pb2-* palette in :root with hardcoded dark
values, so it renders dark regardless of the tenant's admin theme. Pick
Light Premium and everything else turns light except the one screen you
spend the most time in.

Fix: the :root block keeps the dark values (Theme C, and a safe default),
and Theme B gets a light override derived from that theme's own
--ia-* palette rather than invented greys, so the builder matches the
rest of the admin instead of merely being pale.

Two things stay dark on purpose in light mode: the preview chrome bar and
the section list's selected state — the preview shows the tenant's real
site, and a bright frame around it makes their page colours hard to
judge.
Run from repo root: python3 apply-builder-theme-follow.py
"""
import sys

EDIT = 'resources/views/tenant/pages/edit.blade.php'

s = open(EDIT).read()

if 'MARKER-BUILDER-THEME' in s:
    print("SKIP (already applied)"); sys.exit(0)

anchor = """  --pb2-info:        #60A5FA;
  --pb2-info-dim:    rgba(96,165,250,0.16);"""

if anchor not in s:
    print("FAIL: --pb2-info anchor not found"); sys.exit(1)

# Find the end of the :root block so the override lands right after it.
i = s.index(anchor)
close = s.index('}', i)

LIGHT = """

/* MARKER-BUILDER-THEME ------------------------------------------------------
   The builder used to hardcode a dark palette in :root, so it ignored the
   admin theme entirely — pick Light Premium and every screen turned light
   except this one. Values below are derived from theme-b's own --ia-*
   palette so the builder matches the rest of the admin, rather than being
   a separately-invented light grey.
   Scoped to body so it beats the :root defaults above without !important. */
body.ia-theme-b {
  --pb2-bg:          #F7F8FA;
  --pb2-surface:     #FFFFFF;
  --pb2-surface-2:   #F1F3F6;
  --pb2-surface-3:   #E7EAEF;
  --pb2-border:      rgba(15,20,25,0.10);
  --pb2-border-2:    rgba(15,20,25,0.20);
  --pb2-text:        #0F1419;
  --pb2-text-dim:    rgba(15,20,25,0.62);
  --pb2-text-faint:  rgba(15,20,25,0.42);
  --pb2-info:        #2563EB;
  --pb2-info-dim:    rgba(37,99,235,0.12);
}

/* The accent is a light lime — legible on dark, not on white. Anything that
   paints text or an icon in it needs a darker ink in light mode. */
body.ia-theme-b .pb2-status.is-live .pb2-status-label,
body.ia-theme-b .pb2-section-item.selected {
  color: #3F6212;
}
body.ia-theme-b .pb2-status-btn--go {
  color: #1A2E05;
}

/* The preview frame stays dark: it contains the tenant's real site, and a
   bright surround makes their own colours hard to judge. */
body.ia-theme-b .pb2-preview-col,
body.ia-theme-b .pb2-preview-bar {
  background: #131313;
  color: rgba(245,245,244,0.55);
}
body.ia-theme-b .pb2-preview-frame-wrap {
  background: #131313;
}"""

s = s[:close + 1] + LIGHT + s[close + 1:]
open(EDIT, 'w').write(s)
print("OK: theme-b builder palette")
print("Done. No migration needed. view:clear after deploy.")
