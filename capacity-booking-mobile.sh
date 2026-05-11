#!/bin/bash
# ============================================================================
# capacity-booking-mobile.sh   (patch #41)
# ----------------------------------------------------------------------------
# Follow-up to the time-zone-only mobile audit pass. Three pages get touched
# for capacity-style booking polish:
#
#   1. /admin/capacity (capacity admin)
#      6-col grid `80px 70px 1fr 1fr 100px 100px` is desktop-only — minimum
#      width is ~460px which overflows or crushes on a 390px phone. Convert
#      to a stacked card layout on mobile. Each day becomes its own card
#      with label + closed toggle on top, then time inputs + cap field
#      flowing below.
#
#   2. /book (public booking — what customers see)
#      Mostly OK already. Polish: calendar cells minimum size on phone,
#      step-nav buttons stack on very narrow screens, top-bar spacing.
#
#   3. /admin/booking-editor (intake form editor)
#      Three-column live-preview editor is inherently desktop. Add a
#      "Best on desktop" mobile notice (same pattern as #38 receiving forms).
#
# Files touched:
#   resources/views/tenant/capacity/index.blade.php          (mobile CSS)
#   resources/views/tenant/booking-editor/index.blade.php    (mobile notice)
#   public/css/booking.css                                   (polish)
#
# Deploy (CSS only):
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 41: capacity-style booking mobile pass"

# ----------------------------------------------------------------------------
# 1. Capacity admin — append mobile-stack rules to the inline <style> block
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/capacity/index.blade.php")
s = p.read_text()

marker = "/* Capacity mobile pass (patch #41) */"
if marker in s:
    print("    SKIP capacity mobile (already applied)")
else:
    # Find the closing of the inline <style> block. The file uses a long
    # @push('styles') with inline rules ending in `</style>` + `@endpush`.
    # We anchor on the last unique declaration we know is near the end.
    #
    # The capacity Blade's <style> block closes after several override-modal
    # rules. Easiest unique anchor: `cap-input-clear` is the override input's
    # X button which exists once near the bottom.
    style_close_anchor = "</style>\n@endpush"

    # The file has TWO @endpush blocks. Find the first @push('styles') ending.
    # Both push blocks exist? Let's verify.
    pushes = s.count("@push('styles')")
    closes = s.count("</style>\n@endpush")

    if pushes != closes:
        raise SystemExit(f"ABORT: @push('styles') count {pushes} != close count {closes}")

    # We want the FIRST </style>\n@endpush since that's the styles block.
    # Multiple closes means we can't use s.replace; use index-based.
    first_close_idx = s.index(style_close_anchor)

    mobile_css = """

""" + marker + """

@media (max-width: 768px) {
  /* Page-head mode pill — keep visible but tighter */
  .cap-mode-pill {
    font-size: 11.5px;
    padding: 5px 11px;
  }

  /* Resource summary card — tighten and let chips wrap. */
  .cap-resource-summary {
    padding: 12px 14px !important;
  }
  .cap-resource-chip {
    font-size: 11px;
    padding: 3px 9px 3px 7px;
  }

  /* Card-head — title row + button — stack vertically. */
  .cap-card-head {
    flex-direction: column;
    align-items: stretch !important;
    gap: 10px;
    padding: 12px 14px;
  }
  .cap-card-head .ia-btn { align-self: flex-start; }

  /* Day-row grid → stack on mobile.
     Layout uses grid-template-areas; primary field name (max OR interval)
     stays consistent ("max" CSS class = daily-cap input, "interval" CSS
     class = slot-interval input) regardless of which is the "primary"
     for the current booking mode. The display:none on .cap-day-advanced-only
     handles hiding the secondary field; the grid area is just left empty.
  */
  .cap-day-header {
    display: none; /* No room for 6 column labels — labels move inline below */
  }
  .cap-day-row {
    grid-template-columns: 1fr auto;
    grid-template-areas:
      "label    toggle"
      "time     time"
      "max      max"
      "interval interval";
    gap: 10px 12px;
    padding: 14px 16px;
  }
  .cap-day-label   { grid-area: label; font-size: 14.5px; }
  .cap-day-toggle  { grid-area: toggle; }
  .cap-day-time    { grid-area: time; flex-wrap: wrap; }
  .cap-day-time input { width: 100%; max-width: 130px; padding: 8px 10px; font-size: 13px; }
  .cap-day-fields-when-closed { grid-area: time; }
  .cap-day-max     { grid-area: max; }
  .cap-day-interval{ grid-area: interval; }
  .cap-day-max input,
  .cap-day-interval input { text-align: left; padding: 8px 10px; font-size: 13px; }

  /* Inline labels via ::before — since the column header is hidden on
     mobile, each input needs an inline label so users know what it is.
     Labels are field-name-based (always correct regardless of which mode
     promotes the field to "primary"). */
  .cap-day-max::before,
  .cap-day-interval::before {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ia-text-3);
    font-weight: 600;
    margin-bottom: 4px;
  }
  .cap-day-max::before {
    content: 'Daily cap';
  }
  .cap-day-interval::before {
    content: 'Slot interval (min)';
  }

  /* Legend — already wraps but tighten font + padding */
  .cap-legend {
    padding: 10px 14px 14px;
    font-size: 12px;
  }

  /* Override modal — tighten card on mobile */
  .cap-modal-card {
    max-width: calc(100vw - 24px);
    margin: 12px;
  }
  .cap-modal-body {
    padding: 12px 14px;
  }
  .cap-modal-actions {
    padding: 12px 14px;
    gap: 8px;
  }
  .cap-modal-actions .ia-btn { flex: 1; }

  /* Override modal calendar — touch-size day cells */
  .ov-cal-grid {
    gap: 4px;
  }
  .ov-cal-grid > div {
    min-height: 36px;
    font-size: 13px;
  }
}
"""
    s = s[:first_close_idx] + mobile_css + s[first_close_idx:]
    p.write_text(s)
    print("    APPENDED capacity mobile CSS to <style> block")
PYEOF

# ----------------------------------------------------------------------------
# 2. Public booking page — extend booking.css media queries
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("public/css/booking.css")
s = p.read_text()

marker = "/* Capacity-style booking polish (patch #41) */"
if marker in s:
    print("    SKIP public booking polish (already applied)")
else:
    # The existing 560px block ends with:
    #     .bk-step-label { display: none; }
    # }
    old_560 = """@media (max-width: 560px) {
  .bk-item-grid { grid-template-columns: 1fr 1fr; }
  .bk-field-grid-2 { grid-template-columns: 1fr; }
  .bk-step-label { display: none; }
}"""

    if s.count(old_560) != 1:
        raise SystemExit(f"ABORT: 560px anchor count = {s.count(old_560)}, expected 1")

    new_560 = """@media (max-width: 560px) {
  .bk-item-grid { grid-template-columns: 1fr 1fr; }
  .bk-field-grid-2 { grid-template-columns: 1fr; }
  .bk-step-label { display: none; }

  """ + marker + """
  /* Calendar cells — keep readable at narrow widths. aspect-ratio:1 makes
     them shrink with column width; we set a min-height so they remain
     touchable (Apple HIG: 44pt = ~44px). */
  .bk-cal-day { min-height: 40px; font-size: 13px; }
  .bk-cal-day-name { font-size: 11px; }

  /* Step nav — let buttons stack if container is too narrow. The Next
     button has long labels like "Continue → Schedule" which can overflow. */
  .bk-nav {
    flex-direction: column-reverse;
    gap: 8px;
    align-items: stretch;
  }
  .bk-back, .bk-next, .bk-submit { width: 100%; justify-content: center; }

  /* Top bar — keep on one line but tighten. */
  .bk-top-bar { padding: 10px var(--p-gutter); }

  /* Section title scales down a bit further on phones. */
  .bk-section-title { font-size: 20px; }
  .bk-section-sub { font-size: 13px; }

  /* Calendar legend — let items wrap with reasonable spacing. */
  .bk-cal-legend { flex-wrap: wrap; gap: 8px 14px; }
}"""

    s = s.replace(old_560, new_560)
    p.write_text(s)
    print("    EXTENDED booking.css 560px block")
PYEOF

# ----------------------------------------------------------------------------
# 3. Booking editor — "Best on desktop" notice
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/booking-editor/index.blade.php")
s = p.read_text()

if "bke-mobile-notice" in s:
    print("    SKIP booking-editor notice (already applied)")
else:
    # Add a notice div + CSS. The notice shows ≤640px and explains that
    # editing the form on phone isn't practical.
    css_anchor = "@media (max-width: 1100px) {"
    if s.count(css_anchor) != 1:
        raise SystemExit("ABORT: booking-editor 1100px anchor not unique")

    notice_css = """/* "Best on desktop" notice (patch #41). Form editor is inherently
   3-column with live preview — touch-edit isn't practical. */
.bke-mobile-notice {
  display: none;
  background: rgba(250,180,106,.08);
  border: 0.5px solid rgba(250,180,106,.25);
  border-radius: var(--ia-r-lg);
  padding: 14px 16px;
  margin: 12px 0 16px;
}
.bke-mobile-notice-title {
  font-size: 13px; font-weight: 600;
  color: #FAB46A;
  margin-bottom: 4px;
  display: flex; align-items: center; gap: 6px;
}
.bke-mobile-notice-body {
  font-size: 12px;
  color: var(--ia-text-muted);
  line-height: 1.5;
}
@media (max-width: 640px) {
  .bke-mobile-notice { display: block; }
}

"""
    s = s.replace(css_anchor, notice_css + css_anchor)

    # Insert the notice as the first thing inside @section('content').
    content_anchor = "@section('content')"
    if s.count(content_anchor) != 1:
        raise SystemExit("ABORT: booking-editor @section('content') not unique")

    notice_html = """@section('content')

{{-- Mobile "best on desktop" notice (patch #41). Form editor uses a
     3-column layout with live preview; mobile users get a heads-up
     rather than a half-working interface. --}}
<div class="bke-mobile-notice">
  <div class="bke-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="bke-mobile-notice-body">
    The form editor uses a 3-column layout with live preview. Editing on mobile works, but it's much faster on a larger screen.
  </div>
</div>
"""
    # Replace @section('content') with the notice block (the notice block
    # itself starts with @section('content')).
    s = s.replace(content_anchor, notice_html, 1)
    p.write_text(s)
    print("    UPDATED booking-editor — added best-on-desktop notice")
PYEOF

cat <<EONOTE

==> Patch 41 applied locally.

Deploy:
  git add -A
  git commit -m "feat(mobile): capacity admin + public booking + booking-editor polish (#41)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

  (CSS only, no migration, no composer.)

What this fixes:
  - Capacity admin: 6-col day grid → stacked cards on mobile with inline
    labels above each input
  - Public booking: calendar cells stay tap-friendly (40px min), step-nav
    buttons stack vertically when narrow, section title scales, top bar
    tightens
  - Booking editor: amber "best on desktop" notice at top ≤640px

Smoke test:
  1. /admin/capacity on phone — day rows render as cards, inputs labeled
     and tappable, override modal calendar has 36px+ touch targets
  2. /book on phone — calendar cells are tappable, Continue/Back stack
  3. /admin/booking-editor on phone — amber notice at top, page still works
EONOTE
