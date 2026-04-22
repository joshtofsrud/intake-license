{{--
    Footer — shell section, rendered once per page. Mirror of the old
    layout.blade.php footer. Not editable from the page editor.
--}}
<style>
    .mk-footer {
        padding: clamp(32px, 5vw, 64px) 0 clamp(24px, 3vw, 40px);
        border-top: 0.5px solid var(--mk-border);
    }
    .mk-footer-inner {
        display: grid;
        grid-template-columns: 1.5fr 1fr 1fr 1fr;
        gap: 40px;
        padding-bottom: 40px;
        border-bottom: 0.5px solid var(--mk-border);
        margin-bottom: 28px;
    }
    .mk-footer-brand-name {
        font-size: 15px; font-weight: 700; margin-bottom: 8px;
        display: flex; align-items: center; gap: 8px;
    }
    .mk-footer-tagline { font-size: 13px; color: var(--mk-muted); line-height: 1.6; max-width: 260px; }
    .mk-footer-col-title {
        font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
        font-weight: 600; color: var(--mk-dim); margin-bottom: 12px;
    }
    .mk-footer-link {
        display: block; font-size: 13px; color: var(--mk-muted);
        margin-bottom: 8px; transition: color .12s;
    }
    .mk-footer-link:hover { color: var(--mk-text); }
    .mk-footer-bottom { display: flex; align-items: center; justify-content: space-between; }
    .mk-footer-copy { font-size: 12px; color: var(--mk-dim); }
    .mk-footer-legal { display: flex; gap: 20px; }
    .mk-footer-legal a { font-size: 12px; color: var(--mk-dim); transition: color .12s; }
    .mk-footer-legal a:hover { color: var(--mk-muted); }

    @media (max-width: 860px) { .mk-footer-inner { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 520px) {
        .mk-footer-inner { grid-template-columns: 1fr; }
        .mk-footer-bottom { flex-direction: column; gap: 10px; text-align: center; }
    }
</style>

<footer class="mk-footer">
    <div class="mk-container">
        <div class="mk-footer-inner">
            <div>
                <div class="mk-footer-brand-name">
                    <div class="mk-logo-mark" style="width:22px;height:22px;font-size:10px">I</div>
                    intake
                </div>
                <p class="mk-footer-tagline">Online booking, work orders, and customer management for service shops.</p>
            </div>
            <div>
                <div class="mk-footer-col-title">Product</div>
                <a href="{{ route('marketing.features') }}" class="mk-footer-link">Features</a>
                <a href="{{ route('marketing.pricing') }}"  class="mk-footer-link">Pricing</a>
                <a href="{{ route('marketing.docs') }}"     class="mk-footer-link">Docs</a>
            </div>
            <div>
                <div class="mk-footer-col-title">Company</div>
                <a href="{{ route('marketing.contact') }}"  class="mk-footer-link">Contact</a>
                <a href="#"                                 class="mk-footer-link">Blog</a>
                <a href="#"                                 class="mk-footer-link">Status</a>
            </div>
            <div>
                <div class="mk-footer-col-title">Get started</div>
                <a href="{{ route('platform.signup') }}"    class="mk-footer-link">Free trial</a>
                <a href="{{ route('platform.login') }}"     class="mk-footer-link">Sign in</a>
                <a href="#" data-open-quiz                  class="mk-footer-link">Which plan is right for me?</a>
            </div>
        </div>
        <div class="mk-footer-bottom">
            <div class="mk-footer-copy">© {{ date('Y') }} Intake. All rights reserved.</div>
            <div class="mk-footer-legal">
                <a href="#">Privacy</a>
                <a href="#">Terms</a>
            </div>
        </div>
    </div>
</footer>
