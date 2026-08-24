#!/usr/bin/env python3
"""Shrink the category delete confirmation.

The panel listed up to six service names as a bulleted list and split the
actions across two rows, so a routine confirmation took more vertical
space than the category it sat under. It's a decision, not a report.

Now: the names run inline as a sentence (three, then "+4 more"), and
every action sits on one row. The warning itself is unchanged — the
count, the cascade consequence, and the required destination all stay.
Run from repo root: python3 apply-service-category-confirm-compact.py
"""
import sys

JS   = 'public/js/tenant/services.js'
VIEW = 'resources/views/tenant/services/index.blade.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ---------------------------------------------------------------- markup
sub(JS,
    """      panel.innerHTML = ''
        + '<div class="t">\u201c' + esc(cat.name) + '\u201d has ' + mine.length + ' service' + (mine.length === 1 ? '' : 's') + ' in it</div>'
        + '<div class="s">Deleting the category deletes these too, along with their add-ons and pricing:</div>'
        + '<ul>' + mine.slice(0, 6).map(function (s) { return '<li>' + esc(s.name) + '</li>'; }).join('')
        + (mine.length > 6 ? '<li>and ' + (mine.length - 6) + ' more\u2026</li>' : '') + '</ul>'
        + (others.length
            ? '<div class="r"><span>Move them to</span>'
              + '<select data-cat-move>' + opts + '</select>'
              + '<button type="button" class="sv-cat-mini primary" data-cat-do-delete>Move &amp; delete</button></div>'
            : '<div class="s" style="margin-top:8px">There is nowhere to move them \u2014 create another category first.</div>')
        + '<div class="r"><button type="button" class="sv-cat-mini" data-cat-hide-instead>Hide it instead</button>'
        + '<button type="button" class="sv-cat-mini" data-cat-cancel>Cancel</button></div>';""",
    """      // MARKER-SVC-CAT-COMPACT — names inline, actions on one row. This is a
      // confirmation, not an inventory of the category.
      var names = mine.slice(0, 3).map(function (s) { return esc(s.name); }).join(', ');
      if (mine.length > 3) names += ' +' + (mine.length - 3) + ' more';

      panel.innerHTML = ''
        + '<div class="t">Delete \u201c' + esc(cat.name) + '\u201d and its ' + mine.length + ' service' + (mine.length === 1 ? '' : 's') + '?</div>'
        + '<div class="s">' + names + '</div>'
        + '<div class="s">They\\'d be deleted with their add-ons and pricing \u2014 move them somewhere instead.</div>'
        + '<div class="r">'
        + (others.length
            ? '<select data-cat-move>' + opts + '</select>'
              + '<button type="button" class="sv-cat-mini primary" data-cat-do-delete>Move &amp; delete</button>'
            : '<span>Nowhere to move them \u2014 create another category first.</span>')
        + '<button type="button" class="sv-cat-mini" data-cat-hide-instead>Hide instead</button>'
        + '<button type="button" class="sv-cat-mini" data-cat-cancel>Cancel</button>'
        + '</div>';""",
    "js: compact panel")

# ---------------------------------------------------------------- styles
sub(VIEW,
    """.sv-cat-confirm{margin:0 14px 10px;padding:11px 13px;border-radius:9px;font-size:12px;line-height:1.6;
  border:1px solid rgba(251,191,36,.4);background:rgba(251,191,36,.07)}""",
    """.sv-cat-confirm{margin:0 14px 8px;padding:9px 11px;border-radius:8px;font-size:11.5px;line-height:1.5;
  border:1px solid rgba(251,191,36,.4);background:rgba(251,191,36,.07)}""",
    "view: tighter panel")

sub(VIEW,
    """.sv-cat-confirm .t{font-weight:700;font-size:12.5px;margin-bottom:4px;color:#FBBF24}""",
    """.sv-cat-confirm .t{font-weight:700;font-size:12px;margin-bottom:2px;color:#FBBF24}""",
    "view: tighter title")

sub(VIEW,
    """.sv-cat-confirm ul{margin:7px 0 0 16px;color:var(--ia-text-dim)}
.sv-cat-confirm li{margin-bottom:2px}
.sv-cat-confirm .r{display:flex;gap:7px;align-items:center;margin-top:10px;flex-wrap:wrap;font-size:11.5px;color:var(--ia-text-dim)}""",
    """.sv-cat-confirm .r{display:flex;gap:6px;align-items:center;margin-top:8px;flex-wrap:wrap;font-size:11.5px;color:var(--ia-text-dim)}
.sv-cat-confirm .r .sv-cat-mini:last-child{margin-left:auto}""",
    "view: one-row actions")

print("\\nDone. No migration needed. view:clear after deploy.")
