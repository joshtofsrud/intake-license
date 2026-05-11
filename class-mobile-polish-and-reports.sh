#!/bin/bash
# ============================================================================
# class-mobile-polish-and-reports.sh   (patch #34)
# ----------------------------------------------------------------------------
# Five concerns, all view-layer:
#
#   1. Tab strip affordance (Option B): fade-out gradient on right edge
#      of .cl-subnav + scroll-hint animation on page load. Reuses pattern
#      from resfilter-scroll-hint.sh (#30). Lives in mobile-forms.css so
#      it covers Templates / Schedule / Memberships / Packs / Reports
#      automatically (shared partial = 5 pages, one fix).
#
#   2. Templates list — parallel mobile render. Same parallel-render pattern
#      as customer list (#27). Desktop 6-col grid stays. Mobile gets
#      title + 2-line-clamp description + edit/delete actions top-right
#      + Dur/Cap/Price/Upcoming meta row below.
#
#   3. Schedule list — parallel mobile render, grouped by day with sticky
#      day labels. Desktop expand-on-tap stays; mobile tap goes straight
#      to session detail (no inline expand on mobile, redundant with #33).
#      Capacity bar gets its own row so +waitlist pill no longer clips.
#
#   4. Reports range bar — vertical 2-row card on mobile. Top: Range/value.
#      Bottom: segmented control with Today/Week/Month/📅 Custom all in
#      one row, flex-equal. Custom range button becomes 4th segment.
#
#   5. Reports KPI cards — 2×2 grid kept, but tighter. Label gets line-height
#      to wrap cleanly. Value drops 28px → 26px. Delta meta row flex-wraps.
#
# Files touched (all views/CSS, no migration, no controller):
#   resources/views/tenant/classes/templates.blade.php           (mobile section)
#   resources/views/tenant/classes/sessions.blade.php            (mobile section)
#   resources/views/tenant/reports/index.blade.php               (mobile media)
#   public/css/tenant/mobile-forms.css                           (tab scroll-hint)
#   resources/views/layouts/tenant/app.blade.php                 (load JS hint)
#   public/js/tenant/cl-subnav-hint.js                           (NEW, the JS)
#
# Deploy (no migration, no composer):
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail

REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 34: class mobile polish + reports range/KPI polish"

# ----------------------------------------------------------------------------
# 1. Tab strip affordance — CSS in mobile-forms.css + standalone JS file
# ----------------------------------------------------------------------------

# 1a. Append CSS rules to mobile-forms.css for the .cl-subnav scroll-fade.
python3 <<'PYEOF'
from pathlib import Path
p = Path("public/css/tenant/mobile-forms.css")
s = p.read_text()
marker = "/* cl-subnav scroll-hint affordance (patch #34) */"
if marker in s:
    print("    SKIP mobile-forms.css (cl-subnav block already present)")
else:
    block = """

""" + marker + """
/* The .cl-subnav appears on Templates / Schedule / Memberships / Packs /
   Reports. Five tabs overflow a phone viewport. On mobile we:
   - allow horizontal scroll
   - hide the native scrollbar
   - add a fade-out gradient on the right edge to imply "more to the right"
   - bump padding-right so the fade doesn't sit on top of tab text
   The accompanying JS (cl-subnav-hint.js) animates a brief scroll on
   first load to teach the affordance, matching pattern from patch #30. */
@media (max-width: 1023px) {
  .cl-subnav {
    position: relative;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    flex-wrap: nowrap;
    scrollbar-width: none;
    padding-right: 28px;
  }
  .cl-subnav::-webkit-scrollbar { display: none; }
  .cl-subnav-tab {
    flex-shrink: 0;
    white-space: nowrap;
  }
  /* Fade-out gradient. Sits inside a wrapper div in the partial; if the
     partial isn't wrapped, this falls through gracefully. We position
     the fade via a pseudo-element on a parent .cl-subnav-wrap class.
     For the existing un-wrapped markup we rely on a sticky pseudo via
     `position: sticky` on a sentinel element appended in JS, OR an
     overlay div added at render time. To keep this patch view-only and
     not touch every Blade subnav, we use a CSS-only fade via a
     pseudo-element on .cl-subnav itself, positioned absolute inside
     the scroll container. */
}

/* CSS-only fade overlay using a pseudo on a sticky child element.
   The trick: a position:sticky pseudo positioned to the right of the
   scroll viewport gives us an edge that always tracks the right side
   regardless of scroll position. We inject this via the wrapper class. */
@media (max-width: 1023px) {
  .cl-subnav-wrap {
    position: relative;
  }
  .cl-subnav-wrap::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 36px;
    background: linear-gradient(to right, transparent, var(--ia-bg, #0A0A0A) 80%);
    pointer-events: none;
    z-index: 2;
    border-bottom: 0.5px solid var(--ia-border);
  }
}
"""
    p.write_text(s + block)
    print("    APPENDED cl-subnav mobile rules to mobile-forms.css")
PYEOF

# 1b. Create the JS that does the scroll-hint dance on page load.
mkdir -p public/js/tenant
if [ -f "public/js/tenant/cl-subnav-hint.js" ]; then
  echo "    SKIP cl-subnav-hint.js (already exists)"
else
  cat > public/js/tenant/cl-subnav-hint.js <<'EOF'
/* Class subnav scroll-hint (patch #34).
 *
 * On a phone viewport (≤1023px), if any .cl-subnav scrolls horizontally
 * (i.e. tab content overflows), perform a one-time scroll-and-bounce on
 * page load to teach the user the bar scrolls. Matches the affordance
 * pattern established in resfilter-scroll-hint.sh (#30).
 *
 * - Only animates if .cl-subnav.scrollWidth > .clientWidth + 16px slack
 * - Respects prefers-reduced-motion (skips animation entirely)
 * - Cancels on first user touch (don't fight a user already scrolling)
 * - Only animates once per page load
 */
(function () {
  'use strict';

  if (window.matchMedia('(min-width: 1024px)').matches) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var bar = document.querySelector('.cl-subnav');
  if (!bar) return;

  var overflow = bar.scrollWidth - bar.clientWidth;
  if (overflow < 16) return;

  var cancelled = false;
  function onTouch() {
    cancelled = true;
    bar.removeEventListener('touchstart', onTouch);
    bar.removeEventListener('wheel', onTouch);
  }
  bar.addEventListener('touchstart', onTouch, { passive: true });
  bar.addEventListener('wheel', onTouch, { passive: true });

  // Wait a beat for layout to settle, then nudge right and back.
  setTimeout(function () {
    if (cancelled) return;
    bar.scrollTo({ left: 36, behavior: 'smooth' });
    setTimeout(function () {
      if (cancelled) return;
      bar.scrollTo({ left: 0, behavior: 'smooth' });
    }, 380);
  }, 220);
})();
EOF
  echo "    CREATED public/js/tenant/cl-subnav-hint.js"
fi

# 1c. Wrap every .cl-subnav nav in <div class="cl-subnav-wrap"> so the fade
#     pseudo-element has a relative-positioned ancestor. Five files share the
#     pattern: templates, sessions, memberships-products, pack-products, reports.
python3 <<'PYEOF'
from pathlib import Path

# The exact markup pattern is the same across all 5 views (5 hardcoded <a>
# tabs). We wrap the <nav class="cl-subnav">...</nav> block in a div.
targets = [
    "resources/views/tenant/classes/templates.blade.php",
    "resources/views/tenant/classes/sessions.blade.php",
    "resources/views/tenant/classes/membership-products.blade.php",
    "resources/views/tenant/classes/pack-products.blade.php",
    "resources/views/tenant/classes/reports.blade.php",
]

for tgt in targets:
    pth = Path(tgt)
    if not pth.exists():
        print(f"    SKIP {tgt} (not found)")
        continue
    s = pth.read_text()
    if 'class="cl-subnav-wrap"' in s:
        print(f"    SKIP {tgt} (already wrapped)")
        continue
    open_anchor  = '<nav class="cl-subnav">'
    close_anchor = '</nav>'
    if s.count(open_anchor) != 1:
        # Some files don't use this subnav at all (e.g. reports.blade.php
        # might use a different one). Silent skip.
        print(f"    SKIP {tgt} (no .cl-subnav nav)")
        continue
    # Replace the first <nav class="cl-subnav"> with a wrapper+nav,
    # and the next </nav> after it with </nav></div>.
    idx = s.index(open_anchor)
    end_idx = s.index(close_anchor, idx)
    new_s = (s[:idx]
             + '<div class="cl-subnav-wrap">' + open_anchor
             + s[idx + len(open_anchor):end_idx]
             + close_anchor + '</div>'
             + s[end_idx + len(close_anchor):])
    pth.write_text(new_s)
    print(f"    WRAPPED .cl-subnav in {tgt}")
PYEOF

# 1d. Load the JS in the tenant layout's @stack('scripts') area.
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/layouts/tenant/app.blade.php")
if not p.exists():
    raise SystemExit("ABORT: tenant app layout not found at expected path")
s = p.read_text()
marker = "cl-subnav-hint.js"
if marker in s:
    print("    SKIP layout (cl-subnav-hint.js already included)")
else:
    # Insert just before the closing </body> tag. Defer so it doesn't block paint.
    anchor = "</body>"
    insert = '  <script defer src="{{ asset(\'js/tenant/cl-subnav-hint.js\') }}"></script>\n'
    if s.count(anchor) != 1:
        raise SystemExit(f"ABORT: </body> count = {s.count(anchor)}, expected 1")
    p.write_text(s.replace(anchor, insert + anchor))
    print("    UPDATED layout — included cl-subnav-hint.js")
PYEOF

# ----------------------------------------------------------------------------
# 2. Templates list — mobile parallel render
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/templates.blade.php")
s = p.read_text()

if "cl-tpl-mobile" in s:
    print("    SKIP templates mobile render (already present)")
else:
    # 2a. Append CSS to the <style> block (just before </style>).
    css_block = """
/* Mobile card list — parallel render (patch #34). Desktop table stays. */
.cl-tpl-mobile{display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-tpl-row-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:8px}
.cl-tpl-row-m:last-child{border-bottom:none}
.cl-tpl-row-m.is-inactive{opacity:.55}
.cl-tpl-top-m{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.cl-tpl-identity-m{min-width:0;flex:1}
.cl-tpl-name-m{font-size:15px;font-weight:500;color:var(--ia-text);line-height:1.25}
.cl-tpl-inactive-badge{display:inline-block;font-size:10px;padding:1px 6px;border-radius:10px;background:var(--ia-surface-2);color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em;margin-left:6px;vertical-align:1px}
.cl-tpl-desc-m{font-size:12px;color:var(--ia-text-muted);margin-top:3px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cl-tpl-actions-m{display:flex;gap:4px;flex-shrink:0}
.cl-tpl-icon-btn-m{width:32px;height:32px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);font-family:inherit}
.cl-tpl-icon-btn-m:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-tpl-meta-row-m{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;align-items:center}
.cl-tpl-meta-item-m{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.cl-tpl-meta-label-m{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,rgba(255,255,255,.38));font-weight:500}
.cl-tpl-meta-value-m{color:var(--ia-text);font-weight:500}
@media(max-width:640px){
  .cl-card > .cl-table-head,
  .cl-card > .cl-table-row{display:none}
  .cl-tpl-mobile{display:block}
}
"""
    style_close = "</style>\n@endpush"
    if s.count(style_close) != 1:
        raise SystemExit(f"ABORT: </style>@endpush count = {s.count(style_close)}, expected 1")
    s = s.replace(style_close, css_block + style_close)

    # 2b. Append mobile card list AFTER the @forelse...@endforelse desktop table.
    #     The marker is the closing </div> of the .cl-card. We'll insert the
    #     mobile section right after the @empty/@endforelse block, still inside
    #     the same .cl-card so the rounded corners / border stay unified.
    # The existing @forelse block ends with @endforelse and then </div> closes
    # the .cl-card. We need to insert our mobile section between @endforelse
    # and that closing </div>. Robust anchor:
    desk_anchor = "  @endforelse\n</div>\n\n{{-- Add modal --}}"
    mobile_block = """  @endforelse

  {{-- Mobile card list (parallel render, ≤640px). Same data, different shape.
       Desktop 6-col grid above hides on mobile via the CSS swap. --}}
  <div class="cl-tpl-mobile">
    @forelse($templates as $t)
      <div class="cl-tpl-row-m {{ $t->is_active ? '' : 'is-inactive' }}">
        <div class="cl-tpl-top-m">
          <div class="cl-tpl-identity-m">
            <div class="cl-tpl-name-m">
              {{ $t->name }}@if(!$t->is_active)<span class="cl-tpl-inactive-badge">Inactive</span>@endif
            </div>
            @if($t->description || $t->instructorResource)
              <div class="cl-tpl-desc-m">{{ $t->instructorResource?->name ?? 'No instructor set' }}@if($t->description) · {{ $t->description }}@endif</div>
            @endif
          </div>
          <div class="cl-tpl-actions-m">
            <button type="button" class="cl-tpl-icon-btn-m" title="Edit" onclick="openEditModal({{ $t->toJson() }}, {{ $t->sessions_count ?? 0 }})">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="cl-tpl-icon-btn-m" title="Delete" onclick="confirmDelete('{{ $t->id }}','{{ addslashes($t->name) }}')">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h10M5 4V2.5h4V4M5.5 6.5v4M8.5 6.5v4M3 4l.5 7.5h7L11 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div class="cl-tpl-meta-row-m">
          <span class="cl-tpl-meta-item-m"><span class="cl-tpl-meta-label-m">Dur</span> <span class="cl-tpl-meta-value-m">{{ $t->duration_minutes }}m</span></span>
          <span class="cl-tpl-meta-item-m"><span class="cl-tpl-meta-label-m">Cap</span> <span class="cl-tpl-meta-value-m">{{ $t->default_capacity }}</span></span>
          <span class="cl-tpl-meta-item-m"><span class="cl-tpl-meta-label-m">Price</span> <span class="cl-tpl-meta-value-m">{{ $t->price_cents > 0 ? '$'.number_format($t->price_cents/100,2) : 'Free' }}</span></span>
          @if($t->sessions_count > 0)
            <span class="cl-tpl-meta-item-m" style="margin-left:auto"><span class="cl-tpl-meta-label-m">Upcoming</span> <span class="cl-tpl-meta-value-m">{{ $t->sessions_count }}</span></span>
          @endif
        </div>
      </div>
    @empty
      {{-- The desktop empty state above renders too; on mobile that one shows. --}}
    @endforelse
  </div>
</div>

{{-- Add modal --}}"""
    if s.count(desk_anchor) != 1:
        raise SystemExit(f"ABORT: desktop-table close anchor count = {s.count(desk_anchor)}, expected 1")
    s = s.replace(desk_anchor, mobile_block)

    p.write_text(s)
    print("    UPDATED templates.blade.php — mobile card list appended")
PYEOF

# ----------------------------------------------------------------------------
# 3. Schedule list — mobile parallel render, grouped by day
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/sessions.blade.php")
s = p.read_text()

if "cl-sched-mobile" in s:
    print("    SKIP sessions mobile render (already present)")
else:
    # 3a. Append CSS to the <style> block.
    css_block = """
/* Schedule list — mobile parallel render (patch #34).
   Desktop expand-on-tap stays. Mobile tap opens full detail (no inline
   expand, which would be redundant with the polish in patch #33). */
.cl-sched-mobile{display:none}
.cl-sched-week-nav-m{display:none}
.cl-sched-day-group{margin-bottom:18px}
.cl-sched-day-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:600;padding:0 4px 6px}
.cl-sched-day-label.is-today{color:var(--ia-accent)}
.cl-sess-card-m{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:12px;padding:14px;margin-bottom:8px;display:flex;flex-direction:column;gap:10px;text-decoration:none;color:inherit;transition:background var(--ia-t)}
.cl-sess-card-m:hover{background:var(--ia-hover)}
.cl-sess-top-m{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.cl-sess-left-m{min-width:0;flex:1}
.cl-sess-time-m{font-size:13px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums}
.cl-sess-name-m{font-size:15px;font-weight:500;color:var(--ia-text);margin-top:2px;line-height:1.25}
.cl-sess-meta-m{font-size:12px;color:var(--ia-text-muted);margin-top:3px;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-sess-right-m{display:flex;align-items:flex-start;flex-shrink:0}
.cl-sess-capacity-row-m{display:flex;align-items:center;gap:8px}
.cl-sess-capacity-bar-m{flex:1;height:5px;background:var(--ia-surface-2);border-radius:3px;overflow:hidden}
.cl-sess-capacity-fill-m{height:100%;background:var(--ia-accent);border-radius:3px}
.cl-sess-capacity-fill-m.is-full{background:#EF4444}
.cl-sess-capacity-text-m{font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;flex-shrink:0;min-width:44px;text-align:right}
.cl-sess-waitlist-m{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:600;background:rgba(239,68,68,.12);color:#EF4444;flex-shrink:0}
@media(max-width:640px){
  .cl-session-grid{display:none}
  .cl-sched-mobile{display:block}
  /* Compact desktop week-nav too; the desktop one has padding/min-width
     hard-coded that overflows on phones. */
  .cl-week-nav{flex-wrap:wrap;gap:6px}
  .cl-week-label{min-width:0;flex:1}
}
"""
    style_close = "</style>\n@endpush"
    if s.count(style_close) != 1:
        raise SystemExit(f"ABORT: sessions </style>@endpush count = {s.count(style_close)}, expected 1")
    s = s.replace(style_close, css_block + style_close)

    # 3b. Insert mobile day-grouped card list AFTER the closing </div> of
    #     .cl-session-grid (the desktop list), but BEFORE the closing @else
    #     of the @if($sessions->isEmpty()) block. The structure is:
    #         @if($sessions->isEmpty())
    #           <empty state>
    #         @else
    #           <div class="cl-session-grid"> ... </div>
    #         @endif
    #     Wait — actually rereading: the empty state renders separately and
    #     there's no @else, just two top-level branches. Let me anchor on
    #     the closing of cl-session-grid div + the next @endif.
    desk_close_anchor = "      </div>\n    @endforeach\n  </div>\n@endif"
    mobile_block = """      </div>
    @endforeach
  </div>

  {{-- Mobile day-grouped card list (parallel render, ≤640px) --}}
  @php
    // Group sessions by Y-m-d so we can render sticky day labels.
    // Sessions are already ordered by starts_at from the controller.
    $byDay = [];
    foreach ($sessions as $sess) {
      $key = $sess->starts_at->format('Y-m-d');
      $byDay[$key] = $byDay[$key] ?? [];
      $byDay[$key][] = $sess;
    }
    $todayKey = now()->format('Y-m-d');
  @endphp
  <div class="cl-sched-mobile">
    @foreach($byDay as $dayKey => $daySessions)
      @php
        $isToday = ($dayKey === $todayKey);
        // Reuse the Carbon instance from the first session of the day —
        // avoids re-parsing the string key.
        $dayDate = $daySessions[0]->starts_at;
      @endphp
      <div class="cl-sched-day-group">
        <div class="cl-sched-day-label {{ $isToday ? 'is-today' : '' }}">
          {{ $dayDate->format('D, M j') }}@if($isToday) · Today @endif
        </div>
        @foreach($daySessions as $session)
          @php
            $pct = $session->capacity_snapshot > 0
              ? min(100, round(($session->active_registrations_count / $session->capacity_snapshot) * 100))
              : 0;
            $isFull = $pct >= 100;
            $showUrl = route('tenant.classes.sessions.show', ['subdomain' => $sub, 'id' => $session->id]);
          @endphp
          <a href="{{ $showUrl }}" class="cl-sess-card-m">
            <div class="cl-sess-top-m">
              <div class="cl-sess-left-m">
                <div class="cl-sess-time-m">{{ $session->starts_at->format('g:i A') }} – {{ $session->ends_at->format('g:i A') }}</div>
                <div class="cl-sess-name-m">{{ $session->template->name }}</div>
                <div class="cl-sess-meta-m">{{ $session->instructor_snapshot ?? 'No instructor' }} · {{ $session->template->duration_minutes }}min</div>
              </div>
              <div class="cl-sess-right-m">
                <span class="cl-status-pill {{ $session->status }}">{{ ucfirst($session->status) }}</span>
              </div>
            </div>
            <div class="cl-sess-capacity-row-m">
              <div class="cl-sess-capacity-bar-m">
                <div class="cl-sess-capacity-fill-m {{ $isFull ? 'is-full' : '' }}" style="width:{{ $pct }}%"></div>
              </div>
              @if($session->waitlist_count > 0)
                <span class="cl-sess-waitlist-m">+{{ $session->waitlist_count }} wait</span>
              @endif
              <span class="cl-sess-capacity-text-m">{{ $session->active_registrations_count }}/{{ $session->capacity_snapshot }}</span>
            </div>
          </a>
        @endforeach
      </div>
    @endforeach
  </div>
@endif"""
    if s.count(desk_close_anchor) != 1:
        raise SystemExit(f"ABORT: sessions desk-close anchor count = {s.count(desk_close_anchor)}, expected 1")
    s = s.replace(desk_close_anchor, mobile_block)

    p.write_text(s)
    print("    UPDATED sessions.blade.php — mobile day-grouped card list appended")
PYEOF

# ----------------------------------------------------------------------------
# 4 + 5. Reports — range bar restructure + KPI tightening
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/reports/index.blade.php")
s = p.read_text()

marker = "/* Reports mobile — range bar + KPI polish (patch #34) */"
if marker in s:
    print("    SKIP reports mobile media (already present)")
else:
    # Append mobile media block just before the </style> tag.
    css_block = """

  """ + marker + """
  @media (max-width: 640px) {
    /* Range bar becomes a vertical 2-row card */
    .rep-rangebar {
      flex-direction: column;
      align-items: stretch;
      gap: 10px;
      padding: 12px 14px;
    }
    .rep-rangebar > div:first-child {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 8px;
    }
    .rep-rangebar-label { font-size: 10px; }
    .rep-rangebar-current { font-size: 14px; margin-left: 0; }
    .rep-rangebar-controls {
      display: flex;
      gap: 4px;
      width: 100%;
      background: var(--ia-surface-2, #1C1C1C);
      border-radius: 8px;
      padding: 3px;
    }
    /* The internal .rep-toggle gets unwrapped: its 3 links flex equally
       alongside the Custom range button, all in one segmented control. */
    .rep-rangebar-controls .rep-toggle {
      display: contents;  /* let children participate in parent flex */
    }
    .rep-rangebar-controls .rep-toggle a,
    .rep-rangebar-controls .rep-customrange-btn {
      flex: 1;
      padding: 7px 6px;
      font-size: 12px;
      text-align: center;
      border: none;
      background: transparent;
      color: var(--ia-text-muted);
      border-radius: 6px;
      white-space: nowrap;
      min-width: 0;
    }
    .rep-rangebar-controls .rep-toggle a.active,
    .rep-rangebar-controls .rep-customrange-btn.active {
      background: var(--ia-accent, #BEF264);
      color: #0a0a0a;
    }

    /* KPI cards — tighten typography so 2-word labels wrap cleanly
       and deltas don't squeeze onto 2 lines */
    .rep-kpi-card {
      padding: 14px;
      border-radius: 12px;
    }
    .rep-kpi-label {
      line-height: 1.3;
      margin-bottom: 6px;
    }
    .rep-kpi-value {
      font-size: 26px;
    }
    .rep-kpi-meta {
      flex-wrap: wrap;
      gap: 6px;
      font-size: 11px;
      margin-top: 8px;
    }
    .rep-delta {
      font-size: 10px;
      padding: 2px 7px;
    }
  }
"""
    style_close = "  </style>"
    if s.count(style_close) != 1:
        # Fallback: try without trailing space
        style_close = "</style>"
        if s.count(style_close) < 1:
            raise SystemExit(f"ABORT: no </style> tag found in reports view")
    # Replace only the FIRST occurrence (the page may have more nested style
    # tags in odd places; the report page has only one based on inspection).
    idx = s.index(style_close)
    s = s[:idx] + css_block + s[idx:]
    p.write_text(s)
    print("    APPENDED mobile media block to reports/index.blade.php")
PYEOF

# ----------------------------------------------------------------------------
# Done.
# ----------------------------------------------------------------------------
cat <<EONOTE

==> Patch 34 applied locally.

To deploy:
  git add -A
  git commit -m "feat(mobile): class subnav scroll-hint + templates/schedule cards + reports mobile polish (#34)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

  (No migration, no composer install — pure view/CSS/JS.)

What this adds:
  - .cl-subnav fade-out gradient + scroll-hint JS (5 class subpages)
  - Templates list: mobile card list w/ description + meta row
  - Schedule list: mobile day-grouped card list, capacity bar on own row
  - Schedule mobile: tap-to-open detail (no inline expand on mobile)
  - Reports range bar: 2-row card on mobile w/ unified segmented control
  - Reports KPIs: tighter labels, smaller value, flex-wrap meta row

New file: public/js/tenant/cl-subnav-hint.js  (loaded from tenant app layout)

Smoke test (5 min on fitnesstest):
  1. /admin/classes/templates — mobile card list, tab strip nudges on load
  2. /admin/classes/sessions  — sessions group by day; tap card → detail
  3. /admin/reports           — range picker is one row of 4 buttons
EONOTE
