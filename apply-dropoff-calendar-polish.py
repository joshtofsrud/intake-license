#!/usr/bin/env python3
"""Day drop-off calendar: make the columns line up, and stop them
running off the right edge.

Two defects and one design pass:
  * .cal-dropoff-col-head had no flex rule, so the count fell BELOW the
    "See all" link instead of sitting opposite the name.
  * The grid was `repeat(N, minmax(220px,1fr))` with `align-items:start`,
    so with more than ~5 resources it scrolled sideways forever and every
    column started its cards at a different height.

Now: heads are one flex row at a fixed height; the count becomes a pill
that IS the link (retiring N repeated "See all →" strings); a capacity
meter fills in the resource colour, amber near cap and red at it; the
resource colour reads as a dot plus a soft header wash; the empty state
holds its drag hint until you hover that column; and the grid wraps onto
new rows via auto-fill instead of extending past the viewport.
Run from repo root: python3 apply-dropoff-calendar-polish.py
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

V = 'resources/views/tenant/calendar/dropoff.blade.php'

# ---------------------------------------------------------------- markup
# The inline grid-template-columns is what forced horizontal overflow.
# auto-fill + minmax lets the browser wrap to a second row instead.
sub(V,
    """  <div class="cal-dropoff-grid" style="grid-template-columns: repeat({{ $resources->count() }}, minmax(220px, 1fr));">""",
    """  {{-- MARKER-DROPOFF-POLISH — auto-fill wraps to a new row instead of
       running off the right edge; the cap keeps 1–2 resources from
       stretching a column across the whole page. --}}
  <div class="cal-dropoff-grid"
       style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); max-width: {{ min($resources->count(), 5) * 260 }}px;">""",
    "markup: wrapping grid")

sub(V,
    """      <div class="cal-dropoff-col" data-resource-id="{{ $r->id }}">
        {{-- MARKER-PATCH-113 - "See all" link added in column header --}}
        <div class="cal-dropoff-col-head" style="border-top: 3px solid {{ $r->color_hex }}">
          <div>
            <div class="cal-dropoff-col-name">{{ $r->name }}</div>
            @if($r->subtitle)
              <div class="cal-dropoff-col-sub">{{ $r->subtitle }}</div>
            @endif
            <a href="{{ route('tenant.appointments.index', ['resource_id' => $r->id, 'date_from' => $date->format('Y-m-d')]) }}"
               class="cal-dropoff-col-seeall">See all →</a>
          </div>
          <div class="cal-dropoff-col-cap {{ $atCap ? 'is-full' : '' }}">
            {{ $count }}@if($cap !== null)<span class="cap-of">/{{ $cap }}</span>@endif
          </div>
        </div>""",
    """      {{-- MARKER-DROPOFF-POLISH — head is one flex row at a fixed height so
           every column starts its cards on the same line, with or without a
           subtitle. The count pill is the link to this person's list. --}}
      @php
        $nearCap = ($cap !== null && !$atCap && $count >= (int) ceil($cap * 0.8) && $cap > 0);
        $capPct  = ($cap !== null && $cap > 0) ? min(100, (int) round($count * 100 / $cap)) : 0;
      @endphp
      <div class="cal-dropoff-col" data-resource-id="{{ $r->id }}" style="--res: {{ $r->color_hex }}">
        <div class="cal-dropoff-col-head">
          <div class="cal-dropoff-col-id">
            <span class="cal-res-dot" aria-hidden="true"></span>
            <div class="cal-dropoff-col-idtext">
              <div class="cal-dropoff-col-name">{{ $r->name }}</div>
              @if($r->subtitle)
                <div class="cal-dropoff-col-sub">{{ $r->subtitle }}</div>
              @endif
            </div>
          </div>
          <a href="{{ route('tenant.appointments.index', ['resource_id' => $r->id, 'date_from' => $date->format('Y-m-d')]) }}"
             class="cal-dropoff-col-cap {{ $atCap ? 'is-full' : ($nearCap ? 'is-near' : '') }}"
             title="See {{ $r->name }}'s appointments">
            {{ $count }}@if($cap !== null)<span class="cap-of">/{{ $cap }}</span>@endif<span class="cap-go" aria-hidden="true">&rarr;</span>
          </a>
        </div>
        @if($cap !== null)
          <div class="cal-cap-meter"><i class="{{ $atCap ? 'is-full' : '' }}" style="width: {{ $capPct }}%"></i></div>
        @endif""",
    "markup: head + meter")

sub(V,
    """            <div class="cal-dropoff-empty">
              <div>No appointments yet.</div>
              <div class="cal-dropoff-empty-hint-desktop">Drag a card here to assign.</div>
              <div class="cal-dropoff-empty-hint-mobile">Tap + below to add one.</div>
            </div>""",
    """            <div class="cal-dropoff-empty">
              <div>Nothing scheduled</div>
              <div class="cal-dropoff-empty-hint-desktop">Drag a card here to assign</div>
              <div class="cal-dropoff-empty-hint-mobile">Tap + below to add one</div>
            </div>""",
    "markup: empty copy")

# ---------------------------------------------------------------- styles
sub(V,
    """.cal-dropoff-grid {
  display: grid;
  gap: 12px;
  align-items: start;
}
.cal-dropoff-col {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  overflow: hidden;
  min-height: 200px;
}""",
    """/* MARKER-DROPOFF-POLISH */
.cal-dropoff-grid {
  display: grid;
  gap: 12px;
  align-items: stretch;   /* every column the same height across a row */
}
.cal-dropoff-col {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  overflow: hidden;
  min-height: 200px;
  display: flex;
  flex-direction: column;
  transition: border-color 0.15s;
}
.cal-dropoff-col:hover { border-color: var(--ia-border-strong); }""",
    "css: grid + column")

sub(V,
    """.cal-dropoff-col-head {
  padding: 12px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}""",
    """.cal-dropoff-col-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 58px;
  padding: 11px 13px;
  border-bottom: 0.5px solid var(--ia-border);
  background: linear-gradient(to bottom,
              color-mix(in srgb, var(--res, transparent) 13%, transparent), transparent);
}
.cal-dropoff-col-id { display: flex; align-items: center; gap: 8px; min-width: 0; }
.cal-dropoff-col-idtext { min-width: 0; }
.cal-res-dot {
  width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto;
  background: var(--res, var(--ia-text-3));
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--res, transparent) 22%, transparent);
}
.cal-cap-meter { height: 2px; background: rgba(127,127,127,0.14); }
.cal-cap-meter i { display: block; height: 100%; background: var(--res, var(--ia-accent)); transition: width 0.2s; }
.cal-cap-meter i.is-full { background: #d97a7a; }""",
    "css: head + meter")

sub(V,
    """.cal-dropoff-col-name {
  font-weight: 600;
  font-size: 14px;
}""",
    """.cal-dropoff-col-name {
  font-weight: 600;
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}""",
    "css: name truncation")

sub(V,
    """.cal-dropoff-col-cap {
  font-size: 13px;
  color: var(--ia-text-2);
  font-feature-settings: "tnum";
  font-weight: 600;
}
.cal-dropoff-col-cap.is-full {
  color: #d97a7a;
}""",
    """.cal-dropoff-col-cap {
  display: flex;
  align-items: baseline;
  gap: 1px;
  flex: 0 0 auto;
  text-decoration: none;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ia-text-2);
  font-feature-settings: "tnum";
  background: rgba(127,127,127,0.10);
  border: 0.5px solid var(--ia-border);
  border-radius: 99px;
  padding: 3px 9px;
  transition: border-color 0.15s, color 0.15s;
}
.cal-dropoff-col-cap:hover { border-color: var(--ia-border-strong); color: var(--ia-text); }
.cal-dropoff-col-cap:focus-visible { outline: 2px solid var(--ia-accent); outline-offset: 2px; }
.cal-dropoff-col-cap .cap-go { opacity: 0; margin-left: 3px; font-size: 11px; transition: opacity 0.15s; }
.cal-dropoff-col:hover .cap-go { opacity: 0.55; }
.cal-dropoff-col-cap.is-near {
  color: #F59E0B;
  border-color: rgba(245,158,11,0.4);
  background: rgba(245,158,11,0.10);
}
.cal-dropoff-col-cap.is-full {
  color: #d97a7a;
  border-color: rgba(217,122,122,0.45);
  background: rgba(217,122,122,0.12);
}""",
    "css: count pill")

sub(V,
    """.cal-dropoff-col-body {
  padding: 10px;
  min-height: 140px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.cal-dropoff-empty {
  padding: 30px 10px;
  text-align: center;
  font-size: 12px;
  color: var(--ia-text-3);
  font-style: italic;
}""",
    """.cal-dropoff-col-body {
  padding: 10px;
  min-height: 140px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
}
.cal-dropoff-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  padding: 20px 10px;
  text-align: center;
  font-size: 12px;
  color: var(--ia-text-3);
}
/* The drag hint belongs to the column you're pointing at, not all of them. */
.cal-dropoff-empty-hint-desktop { color: transparent; transition: color 0.15s; }
.cal-dropoff-col:hover .cal-dropoff-empty-hint-desktop { color: var(--ia-text-3); }""",
    "css: body + empty state")

# Mobile: the existing override already forces one column; keep the hint
# visible there since hover doesn't exist on touch.
sub(V,
    """  .cal-dropoff-empty-hint-desktop { display: none; }
  .cal-dropoff-empty-hint-mobile  { display: block; margin-top: 4px; }""",
    """  .cal-dropoff-empty-hint-desktop { display: none; }
  .cal-dropoff-empty-hint-mobile  { display: block; margin-top: 4px; color: var(--ia-text-3); }
  /* MARKER-DROPOFF-POLISH — no hover on touch, and the width cap must go. */
  .cal-dropoff-grid[style*="grid-template-columns"] { max-width: none !important; }
  .cal-dropoff-col-head { min-height: 0; }""",
    "css: mobile overrides")

# Dead CSS: nothing renders .cal-dropoff-col-seeall any more (the week view
# doesn't use it either), so the rules go rather than rot.
sub(V,
    """.cal-dropoff-col-seeall {
  display: inline-block;
  margin-top: 4px;
  font-size: 11px;
  color: var(--ia-text-3, #888);
  text-decoration: none;
  letter-spacing: 0.02em;
}
.cal-dropoff-col-seeall:hover {
  color: var(--ia-accent, #BEF264);
}
""",
    "",
    "css: drop dead seeall rules")

print("Done. No migration needed. view:clear after deploy.")
