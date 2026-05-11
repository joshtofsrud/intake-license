#!/bin/bash
# ============================================================================
# classes-reports-pagination-and-mobile.sh   (patch #35)
# ----------------------------------------------------------------------------
# Two concerns shipping together for the /admin/classes/reports page:
#
# 1. PAGINATION on customer-row panels
#    Each panel can show up to 25 customers (service-side PANEL_LIMIT).
#    On both desktop and mobile, this makes panels tall enough to dominate
#    the page. Replace with paged view: 10 rows per page, prev/next + page
#    indicator at the bottom of each panel. All client-side JS — the rows
#    are already in the DOM (server already capped at 25). Export CSV still
#    available per panel for full-list use cases.
#
#    Applies to all 5 customer-row panels:
#      - Drop-in regulars (via _report-panel partial)
#      - At-risk active members
#      - Used-up packs
#      - Recently cancelled
#      - Lapsed memberships (inline full-width, same row markup)
#
# 2. MOBILE polish for the same page
#    - Headline strip: 4-col → 2-col → 1-col at narrowing breakpoints
#    - rp-row 4-col grid (avatar, main, meta, cta) collapses to 2-row
#      layout on mobile: avatar+name+fact on top, fact-meta+cta on bottom
#    - Panel padding tightens on mobile (matches Intake card convention)
#    - Top-earning-products table gets sensible mobile column widths +
#      smaller font; rank pill stays
#    - cl-subnav already wrapped by patch #34; nothing more there
#
# Files touched (all view-layer, no migration, no controller):
#   resources/views/tenant/classes/reports.blade.php           (CSS + JS + markup)
#   resources/views/tenant/classes/_report-panel.blade.php     (pager markup)
#
# Deploy (CSS/Blade/JS only):
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 35: classes reports pagination + mobile polish"

# ----------------------------------------------------------------------------
# 1. _report-panel.blade.php — add data-rp-panel attribute + pager footer
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/_report-panel.blade.php")
s = p.read_text()

if "data-rp-panel" in s:
    print("    SKIP _report-panel.blade.php (already patched)")
else:
    # Anchor 1: outer .rp-panel wrapper — add data attribute for the pager JS
    #           to find this panel.
    old_outer = '<div class="rp-panel">'
    new_outer = '<div class="rp-panel" data-rp-panel="{{ $exportSlug }}">'
    if s.count(old_outer) != 1:
        raise SystemExit(f"ABORT: outer .rp-panel anchor count = {s.count(old_outer)}, expected 1")
    s = s.replace(old_outer, new_outer)

    # Anchor 2: append the pager footer just inside the closing of .rp-row-list.
    #           The pager is hidden by default; JS reveals it when rows > 10.
    old_close = '''        @empty
            <div class="rp-empty">{{ $emptyText }}</div>
        @endforelse
    </div>
</div>'''
    new_close = '''        @empty
            <div class="rp-empty">{{ $emptyText }}</div>
        @endforelse
    </div>
    <div class="rp-pager" data-rp-pager hidden>
        <button type="button" class="rp-pager-btn" data-rp-prev aria-label="Previous page">‹</button>
        <span class="rp-pager-status" data-rp-status>1–10 of —</span>
        <button type="button" class="rp-pager-btn" data-rp-next aria-label="Next page">›</button>
    </div>
</div>'''
    if s.count(old_close) != 1:
        raise SystemExit(f"ABORT: _report-panel close anchor not found uniquely")
    s = s.replace(old_close, new_close)

    p.write_text(s)
    print("    UPDATED _report-panel.blade.php — added data-rp-panel + pager footer")
PYEOF

# ----------------------------------------------------------------------------
# 2. reports.blade.php — wrap inline Lapsed panel, add pager CSS,
#                        mobile polish CSS, pager JS
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/reports.blade.php")
s = p.read_text()

# 2a. Mark the inline Lapsed memberships full-width panel with data-rp-panel
#     so the same pager JS picks it up. Identify by its distinct title.
old_lapsed_wrap = '''    {{-- Panel: Lapsed (full width) --}}
    <div class="rp-panel full">'''
new_lapsed_wrap = '''    {{-- Panel: Lapsed (full width) --}}
    <div class="rp-panel full" data-rp-panel="lapsed-memberships">'''
if "data-rp-panel=\"lapsed-memberships\"" in s:
    print("    SKIP lapsed-memberships wrap (already patched)")
elif s.count(old_lapsed_wrap) != 1:
    raise SystemExit(f"ABORT: lapsed-memberships anchor count = {s.count(old_lapsed_wrap)}, expected 1")
else:
    s = s.replace(old_lapsed_wrap, new_lapsed_wrap)
    print("    UPDATED reports.blade.php — tagged lapsed-memberships panel")

# 2b. Append pager markup at the end of the inline Lapsed panel's .rp-row-list.
old_lapsed_close = '''                <div class="rp-empty">No lapsed memberships in the last 90 days.</div>
            @endforelse
        </div>
    </div>

    {{-- Panel: Top earning products (full width, tabular) --}}'''
new_lapsed_close = '''                <div class="rp-empty">No lapsed memberships in the last 90 days.</div>
            @endforelse
        </div>
        <div class="rp-pager" data-rp-pager hidden>
            <button type="button" class="rp-pager-btn" data-rp-prev aria-label="Previous page">‹</button>
            <span class="rp-pager-status" data-rp-status>1–10 of —</span>
            <button type="button" class="rp-pager-btn" data-rp-next aria-label="Next page">›</button>
        </div>
    </div>

    {{-- Panel: Top earning products (full width, tabular) --}}'''
if 'data-rp-pager hidden' in s and s.count('data-rp-pager hidden') >= 2:
    print("    SKIP lapsed-memberships pager footer (already present)")
elif s.count(old_lapsed_close) != 1:
    raise SystemExit(f"ABORT: lapsed-memberships close anchor not found uniquely")
else:
    s = s.replace(old_lapsed_close, new_lapsed_close)
    print("    UPDATED reports.blade.php — appended pager to lapsed panel")

# 2c. Append pager CSS + mobile polish CSS at the end of the <style> block.
marker = "/* Pager + mobile polish (patch #35) */"
if marker in s:
    print("    SKIP CSS additions (already present)")
else:
    css = """
""" + marker + """
.rp-pager{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:10px 0 12px;border-top:0.5px solid var(--ia-border);margin-top:4px}
.rp-pager-btn{width:28px;height:28px;border-radius:6px;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text-muted);font-size:14px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-family:inherit;transition:all var(--ia-t)}
.rp-pager-btn:hover:not(:disabled){background:var(--ia-hover);color:var(--ia-text);border-color:var(--ia-border-strong)}
.rp-pager-btn:disabled{opacity:.35;cursor:not-allowed}
.rp-pager-status{font-size:11.5px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;min-width:80px;text-align:right}

/* Rows that aren't on the active page are hidden via this class — set/cleared
   by the pager JS. Using a class lets us also do display:flex for active pages
   without re-computing inline styles. */
.rp-row.is-page-hidden{display:none}

@media (max-width: 768px) {
  /* Page-level: tighter outer padding on phones. Existing 1024px rule
     already collapses panels to 1col; we need the narrower breakpoint
     for row-internal layout. */
  .rp-panel { padding: 14px 14px 4px; border-radius: 12px; }
  .rp-panel-head { flex-wrap: wrap; gap: 8px; }
  .rp-panel-title { font-size: 13.5px; flex-wrap: wrap; row-gap: 4px; }
  .rp-panel-sub { font-size: 11.5px; }
  .rp-panel-actions { width: 100%; justify-content: flex-end; }

  /* Headline strip: 4 → 2 → 1 (mobile) */
  .rp-headline { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
  .rp-head-card { padding: 14px 14px; }
  .rp-head-num { font-size: 24px; }
  .rp-head-label { font-size: 12px; }
  .rp-head-sub { font-size: 11px; }

  /* rp-row 4-col grid → 2-row stacked layout.
     Top row: [avatar][name+fact, flex:1]
     Bottom row: [meta][cta] right-aligned, indented under name.
     Achieves a card-feel without dropping the avatar. */
  .rp-row {
    grid-template-columns: 32px 1fr auto;
    grid-template-areas:
      "avatar main meta"
      ".      cta  cta";
    row-gap: 6px;
  }
  .rp-row .rp-avatar  { grid-area: avatar; }
  .rp-row .rp-row-main { grid-area: main; }
  .rp-row .rp-row-meta { grid-area: meta; }
  .rp-row > .rp-export-btn { grid-area: cta; justify-self: end; }

  /* Top earning products table: tighten columns on mobile */
  .rp-tep-table th { font-size: 10px; padding: 6px 6px 6px 0; }
  .rp-tep-table td { font-size: 12px; padding: 10px 6px 10px 0; }
  .rp-tep-meta { display: none; } /* free up vertical space */
  .rp-tep-rank { width: 20px; height: 20px; font-size: 10px; margin-right: 6px; }
}

@media (max-width: 480px) {
  .rp-headline { grid-template-columns: 1fr; }
}
"""
    style_close = "</style>\n@endpush"
    if s.count(style_close) != 1:
        raise SystemExit(f"ABORT: </style>@endpush count = {s.count(style_close)}, expected 1")
    s = s.replace(style_close, css + style_close)
    print("    APPENDED pager + mobile CSS to reports.blade.php")

# 2d. Append pager JS at the end of the @section('content') (before the closing
#     @endsection). The script can live anywhere in the rendered HTML.
js_marker = "// Classes-reports pager (patch #35)"
if js_marker in s:
    print("    SKIP pager JS (already present)")
else:
    js = """

@push('scripts')
<script>
// """ + js_marker[3:] + """
//
// Each [data-rp-panel] block has a [data-rp-pager] footer. If the panel has
// more than PAGE_SIZE rows (.rp-row anchors), slice into pages and wire up
// prev/next. Otherwise hide the pager.
(function () {
  'use strict';
  var PAGE_SIZE = 10;

  function initPanel(panel) {
    var rows  = panel.querySelectorAll('.rp-row-list > a.rp-row');
    var pager = panel.querySelector('[data-rp-pager]');
    if (!pager) return;

    if (rows.length <= PAGE_SIZE) {
      pager.hidden = true;
      return;
    }

    var prev   = pager.querySelector('[data-rp-prev]');
    var next   = pager.querySelector('[data-rp-next]');
    var status = pager.querySelector('[data-rp-status]');
    var pages  = Math.ceil(rows.length / PAGE_SIZE);
    var page   = 0;

    function render() {
      var start = page * PAGE_SIZE;
      var end   = Math.min(start + PAGE_SIZE, rows.length);
      rows.forEach(function (r, i) {
        if (i >= start && i < end) {
          r.classList.remove('is-page-hidden');
        } else {
          r.classList.add('is-page-hidden');
        }
      });
      status.textContent = (start + 1) + '–' + end + ' of ' + rows.length;
      prev.disabled = (page === 0);
      next.disabled = (page >= pages - 1);
    }

    prev.addEventListener('click', function (e) {
      e.preventDefault();
      if (page > 0) { page--; render(); }
    });
    next.addEventListener('click', function (e) {
      e.preventDefault();
      if (page < pages - 1) { page++; render(); }
    });

    pager.hidden = false;
    render();
  }

  document.querySelectorAll('[data-rp-panel]').forEach(initPanel);
})();
</script>
@endpush
"""
    endsection = "@endsection"
    if s.count(endsection) != 1:
        raise SystemExit(f"ABORT: @endsection count = {s.count(endsection)}, expected 1")
    s = s.replace(endsection, js + endsection)
    print("    APPENDED pager JS to reports.blade.php")

p.write_text(s)
PYEOF

# ----------------------------------------------------------------------------
# Done.
# ----------------------------------------------------------------------------
cat <<EONOTE

==> Patch 35 applied locally.

To deploy:
  git add -A
  git commit -m "feat(classes-reports): client-side pagination + mobile polish (#35)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

  (No migration, no composer install — view/CSS/JS only.)

What this adds:
  - 10-per-page client-side pagination on all 5 customer-row panels:
      Drop-in regulars · At-risk members · Used-up packs ·
      Recently cancelled · Lapsed memberships
  - Pager footer (prev / "1–10 of N" / next), hidden when ≤10 rows
  - Mobile-polish CSS for the same page:
      headline 4→2→1 col, rp-row 2-row grid, panel padding tightens,
      top-earning-products table tightens, hides meta line

Out of scope:
  - Server-side pagination (service still caps at 25; export CSV is the
    escape hatch for >25). Bump PANEL_LIMIT later if real data warrants.
  - Top earning products table is left unpaginated — tabular, not customer
    rows, separate problem.
EONOTE
