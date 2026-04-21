/**
 * tenant/addons.js - addon catalog page behavior
 * Response shape: {ok: true, data: ...} matches ServiceController
 */
(function () {
    'use strict';

    const page = document.getElementById('addonsPage');
    if (!page) return;

    const CSRF = page.dataset.csrf;
    const URL_ACTIVATE = page.dataset.activateUrl;
    const URL_CANCEL = page.dataset.cancelUrl;
    const STRIPE_LIVE = page.dataset.stripeLive === '1';

    const modal = document.getElementById('addonModal');
    const modalBody = document.getElementById('addonModalBody');

    let previousFocus = null;

    function openModal(html) {
        modalBody.innerHTML = html;
        modal.setAttribute('aria-hidden', 'false');
        previousFocus = document.activeElement;
        const firstBtn = modal.querySelector('button, [href]');
        if (firstBtn) firstBtn.focus();
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.setAttribute('aria-hidden', 'true');
        modalBody.innerHTML = '';
        document.body.style.overflow = '';
        if (previousFocus) previousFocus.focus();
    }

    modal.addEventListener('click', (e) => {
        if (e.target.dataset.close !== undefined) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') closeModal();
    });

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        });
        const json = await res.json().catch(() => ({ ok: false, error: 'Server returned invalid JSON.' }));
        if (!res.ok && json.ok !== true) {
            throw new Error(json.error || `HTTP ${res.status}`);
        }
        return json;
    }

    function priceString(cents, cadence) {
        if (cadence === 'one_time') return `$${(cents / 100).toFixed(0)} once`;
        if (cents === 0) return '—';
        return `$${(cents / 100).toFixed(0)}/mo`;
    }

    function showPurchaseModal(card) {
        const name = card.dataset.addonName;
        const code = card.dataset.addonCode;
        const price = parseInt(card.dataset.addonPrice, 10) || 0;
        const cadence = card.dataset.addonCadence;

        const priceLine = priceString(price, cadence);
        const stripeWarning = !STRIPE_LIVE
            ? `<p class="addon-modal__banner" style="background:#1e3a5f;border-left:3px solid #60A5FA;color:#bfdbfe;padding:10px 12px;margin:12px 0;border-radius:4px;font-size:0.85rem;">Payment processing is not yet live. This addon will activate immediately and be billed on your next invoice.</p>`
            : '';

        const html = `
            <h2>Add ${escapeHtml(name)}</h2>
            <p>You're about to add ${escapeHtml(name)} to your subscription.</p>
            ${stripeWarning}
            <div class="addon-modal__price-summary">
                <div class="addon-modal__price-row">
                    <span>${escapeHtml(name)}</span>
                    <span>${priceLine}</span>
                </div>
                <div class="addon-modal__price-row addon-modal__price-row--total">
                    <span>Due ${cadence === 'one_time' ? 'today' : 'at next billing cycle'}</span>
                    <span>${priceLine}</span>
                </div>
            </div>
            <div class="addon-modal__actions">
                <button type="button" class="addon-modal__btn addon-modal__btn--secondary" data-close>Cancel</button>
                <button type="button" class="addon-modal__btn addon-modal__btn--primary" id="confirmPurchase">
                    Confirm & add
                </button>
            </div>
            <div class="addon-modal__error" id="purchaseError" style="display:none;"></div>
        `;
        openModal(html);

        const btn = document.getElementById('confirmPurchase');
        const errorBox = document.getElementById('purchaseError');
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = 'Processing…';
            errorBox.style.display = 'none';
            try {
                const res = await post(URL_ACTIVATE, { addon_code: code });
                if (!res.ok) throw new Error(res.error || 'Something went wrong.');
                closeModal();
                showActivatedState(card, res.data);
            } catch (e) {
                errorBox.textContent = e.message || 'Could not complete purchase.';
                errorBox.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Confirm & add';
            }
        });
    }

    function showActivatedState(card, data) {
        card.classList.add('addon-card--active');
        const footer = card.querySelector('.addon-card__footer');
        footer.innerHTML = `
            <span class="addon-card__state addon-card__state--added">✓ Added</span>
            <button type="button" class="addon-card__btn addon-card__btn--manage" data-action="manage">Manage</button>
        `;
    }

    function showManageModal(card) {
        const name = card.dataset.addonName;
        const code = card.dataset.addonCode;

        const html = `
            <h2>Manage ${escapeHtml(name)}</h2>
            <p>Cancel this addon? You'll keep access until the end of your current billing period, after which it will be removed.</p>
            <div class="addon-modal__actions">
                <button type="button" class="addon-modal__btn addon-modal__btn--secondary" data-close>Keep it</button>
                <button type="button" class="addon-modal__btn addon-modal__btn--danger" id="confirmCancel">
                    Cancel addon
                </button>
            </div>
            <div class="addon-modal__error" id="cancelError" style="display:none;"></div>
        `;
        openModal(html);

        const btn = document.getElementById('confirmCancel');
        const errorBox = document.getElementById('cancelError');
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = 'Canceling…';
            errorBox.style.display = 'none';
            try {
                const res = await post(URL_CANCEL, { addon_code: code });
                if (!res.ok) throw new Error(res.error || 'Could not cancel.');
                closeModal();
                showCancelingState(card, res.data);
            } catch (e) {
                errorBox.textContent = e.message || 'Could not cancel addon.';
                errorBox.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Cancel addon';
            }
        });
    }

    function showCancelingState(card, data) {
        card.classList.remove('addon-card--active');
        card.classList.add('addon-card--canceling');
        const footer = card.querySelector('.addon-card__footer');
        const accessUntil = data.access_until ? ` — access until ${formatDate(data.access_until)}` : '';
        footer.innerHTML = `
            <span class="addon-card__state addon-card__state--canceling">Canceling${accessUntil}</span>
        `;
    }

    page.addEventListener('click', (e) => {
        const btn = e.target.closest('.addon-card__btn');
        if (!btn) return;
        const card = btn.closest('.addon-card');
        if (!card) return;
        const action = btn.dataset.action;

        if (action === 'add') {
            showPurchaseModal(card);
        } else if (action === 'manage') {
            showManageModal(card);
        } else if (action === 'reactivate') {
            showPurchaseModal(card);
        }
    });

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function formatDate(iso) {
        try {
            const d = new Date(iso);
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (e) {
            return iso;
        }
    }
})();
