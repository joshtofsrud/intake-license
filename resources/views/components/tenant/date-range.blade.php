@props([
    'fromName'  => 'date_from',
    'toName'    => 'date_to',
    'fromValue' => '',
    'toValue'   => '',
    'placeholder' => 'Date range',
])

{{--
  Date-range picker. Renders as a single calendar-icon button. Click opens
  a popover with one calendar grid + presets. Click once = from, click again = to.
  Hover shows the range preview. Posts via two hidden inputs that match the
  existing date_from / date_to query params, so no backend changes needed.

  Behavior:
    - First click: sets "from", clears "to"
    - Second click on a later date: sets "to" → range complete
    - Second click on an earlier date: swaps (new = from, old = to)
    - Click on the same date: collapses to single-day range
    - Presets jump to common windows
    - Apply submits the parent form. Clear empties both, then submits.
--}}
<div class="ia-dr" data-dr>
    <input type="hidden" name="{{ $fromName }}" value="{{ $fromValue }}" data-dr-from>
    <input type="hidden" name="{{ $toName }}"   value="{{ $toValue }}"   data-dr-to>

    <button type="button" class="ia-btn ia-btn--secondary ia-dr-trigger" data-dr-trigger>
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <rect x="1.75" y="3" width="10.5" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
            <path d="M4.5 1.75v2.5M9.5 1.75v2.5M1.75 6h10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        <span data-dr-label>{{ $placeholder }}</span>
    </button>

    <div class="ia-dr-pop" data-dr-pop hidden>
        <div class="ia-dr-presets">
            <button type="button" class="ia-dr-preset" data-dr-preset="today">Today</button>
            <button type="button" class="ia-dr-preset" data-dr-preset="last_7">Last 7 days</button>
            <button type="button" class="ia-dr-preset" data-dr-preset="this_month">This month</button>
            <button type="button" class="ia-dr-preset" data-dr-preset="last_30">Last 30 days</button>
            <button type="button" class="ia-dr-preset" data-dr-preset="this_year">This year</button>
        </div>

        <div class="ia-dr-cal">
            <div class="ia-dr-head">
                <button type="button" class="ia-dr-nav" data-dr-prev aria-label="Previous month">‹</button>
                <div class="ia-dr-title" data-dr-title></div>
                <button type="button" class="ia-dr-nav" data-dr-next aria-label="Next month">›</button>
            </div>
            <div class="ia-dr-dows">
                <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
            </div>
            <div class="ia-dr-grid" data-dr-grid></div>
        </div>

        <div class="ia-dr-summary" data-dr-summary>
            <span data-dr-summary-text>Click a date to start</span>
        </div>

        <div class="ia-dr-actions">
            <button type="button" class="ia-btn ia-btn--ghost ia-dr-clear" data-dr-clear>Clear</button>
            <button type="button" class="ia-btn ia-btn--secondary ia-dr-cancel" data-dr-cancel>Cancel</button>
            <button type="button" class="ia-btn ia-btn--primary ia-dr-apply" data-dr-apply>Apply</button>
        </div>
    </div>
</div>

@once
@push('styles')
<style>
.ia-dr { position: relative; display: inline-block; }
.ia-dr-trigger { display: inline-flex; align-items: center; gap: 6px; }

.ia-dr-pop {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 100;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
    min-width: 280px;
    user-select: none;
}
.ia-dr-pop[hidden] { display: none; }

.ia-dr-presets {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 0.5px solid var(--ia-border);
}
.ia-dr-preset {
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 5px;
    padding: 4px 9px;
    font-size: 11px;
    color: var(--ia-text-muted);
    cursor: pointer;
    transition: all var(--ia-t);
    font-family: inherit;
}
.ia-dr-preset:hover { color: var(--ia-text); border-color: var(--ia-border-strong); }
.ia-dr-preset.is-active { background: var(--ia-accent-soft); color: var(--ia-accent); border-color: var(--ia-accent); }

.ia-dr-cal { background: var(--ia-surface-2); border: 0.5px solid var(--ia-border); border-radius: 8px; padding: 10px; }
.ia-dr-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.ia-dr-title { font-size: 13px; font-weight: 600; }
.ia-dr-nav {
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 5px;
    width: 26px;
    height: 26px;
    cursor: pointer;
    color: var(--ia-text-muted);
    font-size: 14px;
    line-height: 1;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ia-dr-nav:hover { color: var(--ia-text); border-color: var(--ia-border-strong); }

.ia-dr-dows {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 4px;
    font-size: 9.5px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ia-text-muted);
    font-weight: 600;
    text-align: center;
}
.ia-dr-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }

.ia-dr-cell {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    border-radius: 4px;
    cursor: pointer;
    background: transparent;
    border: none;
    color: var(--ia-text-muted);
    font-family: inherit;
    transition: background var(--ia-t), color var(--ia-t);
    position: relative;
}
.ia-dr-cell:hover:not(:disabled):not(.is-empty) { background: var(--ia-hover); color: var(--ia-text); }
.ia-dr-cell.is-empty { cursor: default; pointer-events: none; }
.ia-dr-cell.is-today { outline: 0.5px solid var(--ia-accent); outline-offset: -1px; }

/* Range states. */
.ia-dr-cell.is-in-range {
    background: var(--ia-accent-soft);
    color: var(--ia-text);
    border-radius: 0;
}
.ia-dr-cell.is-range-start {
    background: var(--ia-accent);
    color: var(--ia-accent-text);
    font-weight: 600;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
.ia-dr-cell.is-range-end {
    background: var(--ia-accent);
    color: var(--ia-accent-text);
    font-weight: 600;
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}
.ia-dr-cell.is-range-start.is-range-end {
    /* Single-day range: corners restored */
    border-radius: 4px;
}
/* End-of-row "in-range" cells need a flat right edge so the band reads
   continuously. Same on start-of-row left edge. We can't easily target row
   ends with CSS pseudo-classes (cells aren't in row groups), so the JS adds
   `is-row-end` / `is-row-start` classes when relevant. */
.ia-dr-cell.is-in-range.is-row-end { border-top-right-radius: 4px; border-bottom-right-radius: 4px; }
.ia-dr-cell.is-in-range.is-row-start { border-top-left-radius: 4px; border-bottom-left-radius: 4px; }

.ia-dr-summary {
    margin-top: 10px;
    padding: 6px 10px;
    background: var(--ia-surface-2);
    border-radius: 6px;
    font-size: 12px;
    color: var(--ia-text-muted);
    text-align: center;
    min-height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ia-dr-actions {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    justify-content: flex-end;
}
.ia-dr-actions .ia-btn { padding: 5px 12px; font-size: 12px; }
.ia-dr-clear { margin-right: auto; color: #EF4444; }
</style>
@endpush

@push('scripts')
<script src="{{ asset('js/tenant/date-range.js') }}?v={{ filemtime(public_path('js/tenant/date-range.js')) }}"></script>
@endpush
@endonce
