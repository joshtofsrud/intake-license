/**
 * Date range picker.
 *
 * Auto-attaches to any element with [data-dr]. Each instance is independent
 * — a page can have multiple pickers without stomping each other.
 *
 * Selection model:
 *   - First click sets `from`, clears `to`. Calendar shows single highlight.
 *   - Second click: if later than `from`, sets `to`. If earlier, swaps so
 *     the earlier date becomes new `from` and prior `from` becomes `to`.
 *   - Same-date second click → single-day range (from == to).
 *   - Hover preview shows the prospective range while user is mid-selection.
 *
 * The hidden inputs `data-dr-from` / `data-dr-to` are the source of truth at
 * page-render time. While the popover is open we work with internal state and
 * only commit to the inputs (and submit the parent form) when Apply fires.
 *
 * Dates are kept as ISO strings (YYYY-MM-DD) inside the inputs. Internally
 * we use Date objects normalized to local-midnight to avoid timezone drift.
 */
(function () {
    'use strict';

    const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    /**
     * Convert a Date to YYYY-MM-DD using local-time components. Avoids the
     * UTC drift you get with toISOString() when local timezone is behind UTC.
     */
    function toIso(d) {
        if (!d) return '';
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    /** Parse YYYY-MM-DD as local-midnight. */
    function fromIso(s) {
        if (!s) return null;
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m) return null;
        return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
    }

    /** Strip time component from a Date for safe comparisons. */
    function dayOnly(d) {
        return new Date(d.getFullYear(), d.getMonth(), d.getDate());
    }

    /** Pretty short label like "May 7, 2026". */
    function fmtShort(d) {
        if (!d) return '';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function isSameDay(a, b) {
        if (!a || !b) return false;
        return a.getFullYear() === b.getFullYear()
            && a.getMonth() === b.getMonth()
            && a.getDate() === b.getDate();
    }

    function isBetween(d, lo, hi) {
        return d >= lo && d <= hi;
    }

    class DateRangePicker {
        constructor(root) {
            this.root = root;
            this.fromInput = root.querySelector('[data-dr-from]');
            this.toInput   = root.querySelector('[data-dr-to]');
            this.trigger   = root.querySelector('[data-dr-trigger]');
            this.label     = root.querySelector('[data-dr-label]');
            this.pop       = root.querySelector('[data-dr-pop]');
            this.title     = root.querySelector('[data-dr-title]');
            this.grid      = root.querySelector('[data-dr-grid]');
            this.summary   = root.querySelector('[data-dr-summary-text]');
            this.prevBtn   = root.querySelector('[data-dr-prev]');
            this.nextBtn   = root.querySelector('[data-dr-next]');
            this.clearBtn  = root.querySelector('[data-dr-clear]');
            this.cancelBtn = root.querySelector('[data-dr-cancel]');
            this.applyBtn  = root.querySelector('[data-dr-apply]');

            // Working state — separate from committed input values
            this.from = fromIso(this.fromInput.value);
            this.to   = fromIso(this.toInput.value);
            // hoverDate is set during mid-selection (have from, no to yet)
            this.hoverDate = null;

            // View month: anchor on `from` if set, else today
            const anchor = this.from || new Date();
            this.viewYear  = anchor.getFullYear();
            this.viewMonth = anchor.getMonth();

            this.today = dayOnly(new Date());

            this.bind();
            this.updateLabel();
        }

        bind() {
            this.trigger.addEventListener('click', () => this.toggle());
            this.prevBtn.addEventListener('click', () => this.shiftMonth(-1));
            this.nextBtn.addEventListener('click', () => this.shiftMonth(1));
            this.clearBtn.addEventListener('click', () => this.clearAndApply());
            this.cancelBtn.addEventListener('click', () => this.close(false));
            this.applyBtn.addEventListener('click', () => this.apply());

            this.root.querySelectorAll('[data-dr-preset]').forEach(btn => {
                btn.addEventListener('click', () => this.applyPreset(btn.dataset.drPreset));
            });

            // Click outside closes without applying
            this.outsideHandler = (e) => {
                if (!this.pop.hidden && !this.root.contains(e.target)) {
                    this.close(false);
                }
            };
            document.addEventListener('click', this.outsideHandler, true);

            // Esc closes without applying
            this.keyHandler = (e) => {
                if (this.pop.hidden) return;
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.close(false);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    this.apply();
                }
            };
            document.addEventListener('keydown', this.keyHandler);
        }

        toggle() {
            if (this.pop.hidden) this.open();
            else this.close(false);
        }

        open() {
            // Snapshot current input values in case user cancels
            this.savedFrom = this.fromInput.value;
            this.savedTo   = this.toInput.value;
            this.from = fromIso(this.fromInput.value);
            this.to   = fromIso(this.toInput.value);
            this.hoverDate = null;
            const anchor = this.from || new Date();
            this.viewYear  = anchor.getFullYear();
            this.viewMonth = anchor.getMonth();
            this.pop.hidden = false;
            this.render();
        }

        close(commit) {
            if (!commit) {
                // Restore to snapshot values; selection state was internal
                this.fromInput.value = this.savedFrom || '';
                this.toInput.value   = this.savedTo || '';
            }
            this.pop.hidden = true;
        }

        apply() {
            // If user picked only "from" but not "to", treat as a single-day range
            if (this.from && !this.to) this.to = this.from;
            this.fromInput.value = toIso(this.from);
            this.toInput.value   = toIso(this.to);
            this.updateLabel();
            this.close(true);
            this.submitForm();
        }

        clearAndApply() {
            this.from = null;
            this.to   = null;
            this.fromInput.value = '';
            this.toInput.value   = '';
            this.updateLabel();
            this.close(true);
            this.submitForm();
        }

        submitForm() {
            // Find the parent <form> and submit it. If the picker is rendered
            // outside a form (rare), we just leave the inputs updated.
            const form = this.root.closest('form');
            if (form) form.submit();
        }

        updateLabel() {
            if (this.fromInput.value && this.toInput.value) {
                const f = fromIso(this.fromInput.value);
                const t = fromIso(this.toInput.value);
                if (isSameDay(f, t)) {
                    this.label.textContent = fmtShort(f);
                } else {
                    this.label.textContent = `${fmtShort(f)} — ${fmtShort(t)}`;
                }
            } else if (this.fromInput.value) {
                this.label.textContent = `From ${fmtShort(fromIso(this.fromInput.value))}`;
            } else if (this.toInput.value) {
                this.label.textContent = `Until ${fmtShort(fromIso(this.toInput.value))}`;
            } else {
                this.label.textContent = this.label.dataset.placeholder || 'Date range';
            }
        }

        shiftMonth(delta) {
            this.viewMonth += delta;
            if (this.viewMonth < 0) { this.viewMonth = 11; this.viewYear -= 1; }
            else if (this.viewMonth > 11) { this.viewMonth = 0; this.viewYear += 1; }
            this.render();
        }

        /**
         * Click-on-cell logic. Two clicks = a range.
         */
        selectDate(d) {
            if (!this.from || (this.from && this.to)) {
                // First click, or starting fresh after a complete range
                this.from = d;
                this.to = null;
            } else {
                // Second click — close out the range
                if (d < this.from) {
                    // Earlier than current from → swap
                    this.to = this.from;
                    this.from = d;
                } else {
                    this.to = d;
                }
            }
            this.hoverDate = null;
            this.render();
        }

        applyPreset(name) {
            const today = new Date();
            const t = dayOnly(today);
            let from, to;
            switch (name) {
                case 'today':
                    from = t; to = t; break;
                case 'last_7':
                    to = t;
                    from = new Date(t); from.setDate(t.getDate() - 6);
                    break;
                case 'this_month':
                    from = new Date(t.getFullYear(), t.getMonth(), 1);
                    to = new Date(t.getFullYear(), t.getMonth() + 1, 0);
                    break;
                case 'last_30':
                    to = t;
                    from = new Date(t); from.setDate(t.getDate() - 29);
                    break;
                case 'this_year':
                    from = new Date(t.getFullYear(), 0, 1);
                    to = new Date(t.getFullYear(), 11, 31);
                    break;
                default:
                    return;
            }
            this.from = from;
            this.to = to;
            // Snap view to the from-month
            this.viewYear = from.getFullYear();
            this.viewMonth = from.getMonth();
            this.hoverDate = null;
            this.render();
        }

        /**
         * Build the calendar grid for the current view month and apply
         * range/highlight classes per cell.
         */
        render() {
            this.title.textContent = `${MONTHS[this.viewMonth]} ${this.viewYear}`;
            this.grid.innerHTML = '';

            const firstDow = new Date(this.viewYear, this.viewMonth, 1).getDay();
            const daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();

            // The row position helps draw clean range bars at row edges.
            // Cell index in grid = firstDow + (day - 1). Row = floor(idx / 7).
            // First col of row = idx % 7 === 0; last col = idx % 7 === 6.
            for (let i = 0; i < firstDow; i++) {
                const empty = document.createElement('button');
                empty.type = 'button';
                empty.className = 'ia-dr-cell is-empty';
                empty.disabled = true;
                this.grid.appendChild(empty);
            }

            // Determine the effective range for highlighting. If user is
            // mid-selection (from set, no to, hovering elsewhere), preview
            // a virtual range using the hover.
            let lo = null, hi = null;
            if (this.from && this.to) {
                lo = this.from < this.to ? this.from : this.to;
                hi = this.from < this.to ? this.to : this.from;
            } else if (this.from && this.hoverDate) {
                lo = this.from < this.hoverDate ? this.from : this.hoverDate;
                hi = this.from < this.hoverDate ? this.hoverDate : this.from;
            } else if (this.from) {
                lo = this.from; hi = this.from;
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const cellDate = new Date(this.viewYear, this.viewMonth, day);
                const cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'ia-dr-cell';
                cell.textContent = day;

                if (isSameDay(cellDate, this.today)) cell.classList.add('is-today');

                if (lo && hi) {
                    const inRange = isBetween(cellDate, lo, hi);
                    if (inRange) {
                        const isStart = isSameDay(cellDate, lo);
                        const isEnd   = isSameDay(cellDate, hi);
                        if (isStart) cell.classList.add('is-range-start');
                        if (isEnd)   cell.classList.add('is-range-end');
                        if (!isStart && !isEnd) cell.classList.add('is-in-range');

                        // Track row-edge cells for clean band rendering
                        const cellIdx = firstDow + (day - 1);
                        if (cellIdx % 7 === 0) cell.classList.add('is-row-start');
                        if (cellIdx % 7 === 6) cell.classList.add('is-row-end');
                    }
                }

                cell.addEventListener('click', () => this.selectDate(cellDate));
                cell.addEventListener('mouseenter', () => {
                    // Only show hover preview when mid-selection
                    if (this.from && !this.to) {
                        this.hoverDate = cellDate;
                        this.render();
                    }
                });
                this.grid.appendChild(cell);
            }

            this.renderSummary();
        }

        renderSummary() {
            if (this.from && this.to) {
                const lo = this.from < this.to ? this.from : this.to;
                const hi = this.from < this.to ? this.to : this.from;
                if (isSameDay(lo, hi)) {
                    this.summary.textContent = fmtShort(lo);
                } else {
                    const days = Math.round((hi - lo) / 86400000) + 1;
                    this.summary.textContent = `${fmtShort(lo)} → ${fmtShort(hi)} (${days} days)`;
                }
            } else if (this.from) {
                this.summary.textContent = `From ${fmtShort(this.from)} — pick end date`;
            } else {
                this.summary.textContent = 'Click a date to start';
            }
        }
    }

    function init() {
        document.querySelectorAll('[data-dr]').forEach(root => {
            if (root.__drInstance) return;
            root.__drInstance = new DateRangePicker(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
