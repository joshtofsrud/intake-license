/**
 * Customer search picker.
 *
 * Auto-attaches to every [data-customer-search] on the page. Each instance
 * is independent — multiple pickers on one page is fine (e.g. a class detail
 * page with both a class-add form and a session-add form).
 *
 * Behavior:
 *   - Focus on input → fetch with empty query, show most-recent 12
 *   - Type → 200ms debounced fetch, replace results
 *   - Click row → fill input with "First Last (email)", set hidden ID, hide dropdown
 *   - Clear button (x) → reset both fields, re-fetch empty
 *   - Esc → close dropdown without selecting
 *   - Click outside → close dropdown
 *   - ↑/↓ → navigate, Enter → select
 *
 * If submitted without a selection, the hidden input is empty. Server-side
 * validation should treat that as "no customer selected" and reject the
 * form. (Existing class registration controller already requires customer_id.)
 */
(function () {
    'use strict';

    const DEBOUNCE_MS = 200;

    function escape(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    class CustomerSearch {
        constructor(root) {
            this.root      = root;
            this.input     = root.querySelector('[data-cs-input]');
            this.idField   = root.querySelector('[data-cs-id]');
            this.results   = root.querySelector('[data-cs-results]');
            this.clearBtn  = root.querySelector('[data-cs-clear]');
            this.searchUrl = root.dataset.searchUrl;

            this.activeIdx = -1;
            this.rows      = [];
            this.debounce  = null;
            this.lastFetch = '';

            this.bind();
        }

        bind() {
            this.input.addEventListener('focus', () => this.onFocus());
            this.input.addEventListener('input', () => this.onInput());
            this.input.addEventListener('keydown', (e) => this.onKey(e));
            this.clearBtn.addEventListener('click', () => this.clear());

            // Outside-click closes
            document.addEventListener('click', (e) => {
                if (!this.root.contains(e.target)) this.close();
            });

            // Reposition on scroll/resize so the dropdown follows its input.
            // Passive scroll listener is cheap; only re-anchors when shown.
            const reposition = () => {
                if (!this.results.hidden) this.positionResults();
            };
            window.addEventListener('scroll', reposition, true);
            window.addEventListener('resize', reposition);
        }

        onFocus() {
            // If user already picked someone, don't re-open. They can clear first.
            if (this.idField.value) return;
            this.fetch('');
        }

        onInput() {
            // Typing invalidates any prior selection
            this.idField.value = '';
            this.clearBtn.hidden = !this.input.value;

            clearTimeout(this.debounce);
            const q = this.input.value;
            this.debounce = setTimeout(() => this.fetch(q), DEBOUNCE_MS);
        }

        onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
                return;
            }
            if (this.results.hidden) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.move(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.move(-1);
            } else if (e.key === 'Enter') {
                if (this.activeIdx >= 0 && this.rows[this.activeIdx]) {
                    e.preventDefault();
                    this.select(this.rows[this.activeIdx]);
                }
            }
        }

        move(delta) {
            if (this.rows.length === 0) return;
            this.activeIdx = (this.activeIdx + delta + this.rows.length) % this.rows.length;
            this.results.querySelectorAll('.ia-cs-row').forEach((row, i) => {
                row.classList.toggle('is-active', i === this.activeIdx);
                if (i === this.activeIdx) row.scrollIntoView({ block: 'nearest' });
            });
        }

        async fetch(q) {
            // Skip duplicate consecutive fetches
            const key = q.trim();
            if (key === this.lastFetch && !this.results.hidden) return;
            this.lastFetch = key;

            try {
                const res = await window.fetch(`${this.searchUrl}?q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('search failed');
                const body = await res.json();
                this.render(body.customers || []);
            } catch (e) {
                console.warn('customer search failed', e);
                this.results.innerHTML = '<div class="ia-cs-empty">Search failed. Try again.</div>';
                this.results.hidden = false;
            }
        }

        render(customers) {
            this.rows = customers;
            this.activeIdx = -1;

            if (customers.length === 0) {
                this.results.innerHTML = '<div class="ia-cs-empty">No matches</div>';
                this.positionResults();
                this.results.hidden = false;
                return;
            }

            this.results.innerHTML = customers.map((c, i) => `
                <div class="ia-cs-row" data-idx="${i}">
                    <div class="ia-cs-row-name">${escape(c.label || 'Unnamed')}</div>
                    <div class="ia-cs-row-meta">${escape(c.email || '')}${c.phone ? ' · ' + escape(c.phone) : ''}</div>
                </div>
            `).join('');

            this.results.querySelectorAll('.ia-cs-row').forEach((row) => {
                row.addEventListener('click', () => {
                    const idx = parseInt(row.dataset.idx, 10);
                    this.select(this.rows[idx]);
                });
            });

            this.positionResults();
            this.results.hidden = false;
        }

        /**
         * Anchor the dropdown directly below the input using viewport
         * coordinates. Required because the dropdown is position:fixed
         * (to escape ancestor overflow:hidden) and so doesn't auto-flow
         * under its DOM parent. Re-runs on scroll/resize while open.
         */
        positionResults() {
            const r = this.input.getBoundingClientRect();
            this.results.style.top   = (r.bottom + 4) + 'px';
            this.results.style.left  = r.left + 'px';
            this.results.style.width = r.width + 'px';
        }

        select(c) {
            this.idField.value = c.id;
            this.input.value = `${c.label || 'Unnamed'} (${c.email || ''})`.trim();
            this.clearBtn.hidden = false;
            this.close();
        }

        clear() {
            this.idField.value = '';
            this.input.value = '';
            this.clearBtn.hidden = true;
            this.input.focus();
            this.fetch('');
        }

        close() {
            this.results.hidden = true;
            this.activeIdx = -1;
        }
    }

    function init() {
        document.querySelectorAll('[data-customer-search]').forEach(root => {
            if (root.__csInstance) return;
            root.__csInstance = new CustomerSearch(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
