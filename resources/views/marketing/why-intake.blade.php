{{-- resources/views/marketing/why-intake.blade.php --}}
@extends('marketing.layout')

@section('title', 'Why Intake — Replace 5 vendors with one platform')
@section('meta_description', "Most bike shops pay \$400–900/month for tools that don't talk to each other. Intake replaces all of them.")

@push('styles')
<style>

/* ================================================================
   Why Intake — /why-intake
   Component prefix: wi-
   ================================================================ */

/* ── Hero ─────────────────────────────────────────────────────── */
.wi-hero {
    padding: clamp(64px, 10vw, 120px) 0 clamp(48px, 7vw, 88px);
    text-align: center;
    border-bottom: 0.5px solid var(--mk-border);
}
.wi-hero h1 {
    font-size: clamp(36px, 6vw, 72px);
    font-weight: 800;
    letter-spacing: -.03em;
    line-height: 1.04;
    margin-bottom: 20px;
    max-width: 760px;
    margin-left: auto;
    margin-right: auto;
}
.wi-hero h1 em { font-style: normal; color: var(--mk-accent); }
.wi-hero-sub {
    font-size: clamp(15px, 2vw, 19px);
    color: var(--mk-muted);
    max-width: 520px;
    margin: 0 auto 32px;
    line-height: 1.65;
}
.wi-hero-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: center;
    margin-bottom: 14px;
}
.wi-hero-note { font-size: 12px; color: var(--mk-dim); }

/* Vendor crossout list */
.wi-vendor-list {
    display: inline-flex;
    flex-direction: column;
    gap: 8px;
    margin: 36px auto 0;
    text-align: left;
}
.wi-vendor-row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--mk-muted);
    opacity: 0;
    transform: translateX(-10px);
    animation: wi-slide-in 0.35s forwards;
}
.wi-vendor-row:nth-child(1) { animation-delay: .15s; }
.wi-vendor-row:nth-child(2) { animation-delay: .28s; }
.wi-vendor-row:nth-child(3) { animation-delay: .41s; }
.wi-vendor-row:nth-child(4) { animation-delay: .54s; }
.wi-vendor-row:nth-child(5) { animation-delay: .67s; }
@keyframes wi-slide-in {
    to { opacity: 1; transform: translateX(0); }
}
.wi-vendor-struck {
    text-decoration: line-through;
    color: rgba(255,255,255,.2);
    font-size: 13px;
}
.wi-vendor-arrow { color: rgba(255,255,255,.15); font-size: 11px; }
.wi-vendor-intake { font-size: 13px; font-weight: 600; color: var(--mk-text); }
.wi-vendor-badge {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .04em;
    color: var(--mk-accent-text);
    background: var(--mk-accent);
    padding: 2px 7px;
    border-radius: 4px;
}

/* ── Pain section ─────────────────────────────────────────────── */
.wi-pain-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 32px;
}
.wi-pain-card {
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    padding: 22px 18px;
    transition: border-color .15s;
}
.wi-pain-card:hover { border-color: rgba(255,255,255,.16); }
.wi-pain-vendor {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--mk-dim);
    font-weight: 600;
    margin-bottom: 10px;
}
.wi-pain-icon { font-size: 22px; margin-bottom: 10px; }
.wi-pain-title { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.wi-pain-desc { font-size: 12px; color: var(--mk-muted); line-height: 1.6; margin-bottom: 16px; }
.wi-pain-cost {
    font-size: 17px;
    font-weight: 700;
    color: var(--mk-text);
    letter-spacing: -.02em;
}
.wi-pain-cost-unit { font-size: 12px; font-weight: 400; color: var(--mk-dim); }

/* Total callout */
.wi-total-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border2);
    border-radius: var(--mk-r-lg);
    padding: 24px 28px;
    gap: 24px;
    flex-wrap: wrap;
}
.wi-total-label { font-size: 14px; color: var(--mk-muted); line-height: 1.5; }
.wi-total-label strong { color: var(--mk-text); font-weight: 600; }
.wi-total-nums {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-shrink: 0;
}
.wi-total-num { text-align: center; }
.wi-total-num .big {
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -.03em;
    line-height: 1;
}
.wi-total-num .small {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--mk-dim);
    margin-top: 4px;
}
.wi-total-old .big { color: rgba(255,255,255,.35); text-decoration: line-through; }
.wi-total-new .big { color: var(--mk-accent); }
.wi-total-vs { font-size: 12px; color: var(--mk-dim); font-weight: 600; }

@media (max-width: 1024px) { .wi-pain-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 680px)  { .wi-pain-grid { grid-template-columns: 1fr 1fr; } }

/* ── Feature cards ────────────────────────────────────────────── */
.wi-feat-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.wi-feat-card {
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    padding: 22px;
    transition: border-color .15s;
}
.wi-feat-card:hover { border-color: rgba(255,255,255,.16); }
.wi-feat-icon {
    width: 36px; height: 36px;
    background: var(--mk-accent-dim);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 14px;
    font-size: 18px;
}
.wi-feat-title { font-size: 14px; font-weight: 600; margin-bottom: 6px; }
.wi-feat-desc  { font-size: 13px; color: var(--mk-muted); line-height: 1.6; margin-bottom: 14px; }
.wi-feat-replaces {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
}
.wi-feat-replaces-label { font-size: 11px; color: var(--mk-dim); }
.wi-feat-chip {
    font-size: 11px;
    color: rgba(255,255,255,.25);
    background: rgba(255,255,255,.05);
    border: 0.5px solid var(--mk-border);
    padding: 2px 8px;
    border-radius: 4px;
    text-decoration: line-through;
}

@media (max-width: 860px) { .wi-feat-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .wi-feat-grid { grid-template-columns: 1fr; } }

/* ── Comparison table ─────────────────────────────────────────── */
.wi-table-wrap {
    overflow-x: auto;
    border-radius: var(--mk-r-lg);
    border: 0.5px solid var(--mk-border);
}
.wi-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 680px;
}
.wi-table th {
    padding: 14px 18px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--mk-dim);
    background: rgba(255,255,255,.02);
    border-bottom: 0.5px solid var(--mk-border);
    white-space: nowrap;
}
.wi-table th.wi-th-intake {
    color: var(--mk-accent);
    font-size: 13px;
}
.wi-table td {
    padding: 13px 18px;
    font-size: 13px;
    color: var(--mk-muted);
    border-bottom: 0.5px solid var(--mk-border);
    vertical-align: middle;
}
.wi-table tr:last-child td { border-bottom: none; }
.wi-table td.wi-td-feature {
    font-weight: 500;
    color: var(--mk-text);
}
.wi-table td.wi-td-intake {
    background: var(--mk-accent-dim);
    color: var(--mk-text);
    font-weight: 600;
}
.wi-table tr.wi-cat-row td {
    background: rgba(255,255,255,.015);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .1em;
    font-weight: 600;
    color: var(--mk-dim);
    padding: 10px 18px 8px;
    border-bottom: 0.5px solid var(--mk-border);
}
.wi-table tr.wi-cat-row td.wi-td-intake { background: rgba(190,242,100,.05); }
.wi-table tr.wi-price-row td {
    background: rgba(255,255,255,.03);
    font-weight: 700;
    color: var(--mk-text);
    font-size: 14px;
    padding: 18px;
    border-top: 0.5px solid var(--mk-border2);
}
.wi-table tr.wi-price-row td.wi-td-intake {
    color: var(--mk-accent);
    font-size: 16px;
    background: var(--mk-accent-dim);
}
.wi-price-note {
    display: block;
    font-size: 11px;
    font-weight: 400;
    color: var(--mk-dim);
    margin-top: 3px;
}
.wi-check { color: var(--mk-accent); font-weight: 700; }
.wi-cross  { color: rgba(255,255,255,.15); }
.wi-partial { font-size: 12px; color: var(--mk-dim); }

</style>
@endpush

@section('content')

{{-- ── HERO ───────────────────────────────────────────────────── --}}
<section class="wi-hero">
    <div class="mk-container">

        <p class="mk-eyebrow">For bike shops &amp; service businesses</p>

        <h1>Stop paying five vendors<br>to run <em>one shop.</em></h1>

        <p class="wi-hero-sub">
            Most shops are duct-taping together Mailchimp, an SMS tool, a rental platform, Square, and a website builder. Intake replaces all of them — one platform, one price.
        </p>

        <div class="wi-hero-actions">
            <a href="{{ route('platform.signup') }}" class="mk-btn mk-btn--primary">Start free trial →</a>
            <a href="#compare" class="mk-btn mk-btn--ghost">See the comparison</a>
        </div>

        <p class="wi-hero-note">14-day free trial · No credit card required</p>

        <div class="wi-vendor-list">
            <div class="wi-vendor-row">
                <span class="wi-vendor-struck">Mailchimp / Klaviyo</span>
                <span class="wi-vendor-arrow">→</span>
                <span class="wi-vendor-intake">Intake Campaigns</span>
                <span class="wi-vendor-badge">replaced</span>
            </div>
            <div class="wi-vendor-row">
                <span class="wi-vendor-struck">Attentive / Postscript</span>
                <span class="wi-vendor-arrow">→</span>
                <span class="wi-vendor-intake">Intake SMS</span>
                <span class="wi-vendor-badge">replaced</span>
            </div>
            <div class="wi-vendor-row">
                <span class="wi-vendor-struck">RentMan / Booqable</span>
                <span class="wi-vendor-arrow">→</span>
                <span class="wi-vendor-intake">Intake Rentals</span>
                <span class="wi-vendor-badge">replaced</span>
            </div>
            <div class="wi-vendor-row">
                <span class="wi-vendor-struck">Square / Lightspeed</span>
                <span class="wi-vendor-arrow">→</span>
                <span class="wi-vendor-intake">Intake Payments</span>
                <span class="wi-vendor-badge">replaced</span>
            </div>
            <div class="wi-vendor-row">
                <span class="wi-vendor-struck">Squarespace / Wix</span>
                <span class="wi-vendor-arrow">→</span>
                <span class="wi-vendor-intake">Intake Site</span>
                <span class="wi-vendor-badge">replaced</span>
            </div>
        </div>

    </div>
</section>

{{-- ── PAIN / COST SECTION ────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">The real cost of "good enough"</p>
        <h2 class="mk-section-title">Your tools are costing you more than you think.</h2>
        <p class="mk-section-sub">Add it up: five tools, five logins, five support queues — and none of them talk to each other.</p>

        <div class="wi-pain-grid">

            <div class="wi-pain-card">
                <div class="wi-pain-vendor">Email marketing</div>
                <div class="wi-pain-icon">📧</div>
                <div class="wi-pain-title">Mailchimp / Klaviyo</div>
                <div class="wi-pain-desc">Pricing grows with your list. Automations cost extra. Doesn't know anything about your actual customers or their bookings.</div>
                <div class="wi-pain-cost">$50–200 <span class="wi-pain-cost-unit">/ mo</span></div>
            </div>

            <div class="wi-pain-card">
                <div class="wi-pain-vendor">SMS marketing</div>
                <div class="wi-pain-icon">💬</div>
                <div class="wi-pain-title">Attentive / Postscript</div>
                <div class="wi-pain-desc">Per-message pricing, separate contact list, another integration to maintain. Most shops set it up once and forget it.</div>
                <div class="wi-pain-cost">$100–300 <span class="wi-pain-cost-unit">/ mo</span></div>
            </div>

            <div class="wi-pain-card">
                <div class="wi-pain-vendor">Rental management</div>
                <div class="wi-pain-icon">🚲</div>
                <div class="wi-pain-title">RentMan / Booqable</div>
                <div class="wi-pain-desc">Great for inventory. Disconnected from your bookings, your customers, and everything else you use daily.</div>
                <div class="wi-pain-cost">$79–199 <span class="wi-pain-cost-unit">/ mo</span></div>
            </div>

            <div class="wi-pain-card">
                <div class="wi-pain-vendor">Point of sale</div>
                <div class="wi-pain-icon">💳</div>
                <div class="wi-pain-title">Square / Lightspeed</div>
                <div class="wi-pain-desc">A terminal and a dashboard — but your service history, waitlist, and campaigns all live somewhere else.</div>
                <div class="wi-pain-cost">2.6% <span class="wi-pain-cost-unit">+ monthly fee</span></div>
            </div>

            <div class="wi-pain-card">
                <div class="wi-pain-vendor">Website builder</div>
                <div class="wi-pain-icon">🌐</div>
                <div class="wi-pain-title">Squarespace / Wix</div>
                <div class="wi-pain-desc">A site that has no idea who your customers are, what they've booked, or when their bike is ready for pickup.</div>
                <div class="wi-pain-cost">$23–65 <span class="wi-pain-cost-unit">/ mo</span></div>
            </div>

        </div>

        <div class="wi-total-bar">
            <div class="wi-total-label">
                Average shop pays <strong>$400–900/month</strong> for five tools<br>that weren't designed to work together.
            </div>
            <div class="wi-total-nums">
                <div class="wi-total-num wi-total-old">
                    <div class="big">~$600<span style="font-size:16px;font-weight:500">/mo</span></div>
                    <div class="small">5 vendors</div>
                </div>
                <div class="wi-total-vs">vs</div>
                <div class="wi-total-num wi-total-new">
                    <div class="big">$79<span style="font-size:16px;font-weight:500">/mo</span></div>
                    <div class="small">Intake</div>
                </div>
            </div>
        </div>

    </div>
</section>

{{-- ── FEATURE CARDS ──────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">What's inside</p>
        <h2 class="mk-section-title">Everything a shop needs. Nothing it doesn't.</h2>
        <p class="mk-section-sub">Built around how service businesses actually work — jobs, customers, and time — not generic CRM modules.</p>

        <div class="wi-feat-grid">

            <div class="wi-feat-card">
                <div class="wi-feat-icon">📅</div>
                <div class="wi-feat-title">Booking &amp; Calendar</div>
                <div class="wi-feat-desc">Online booking with resource-aware scheduling. Your team sees one real-time calendar — no double-bookings, no phone tag.</div>
                <div class="wi-feat-replaces">
                    <span class="wi-feat-replaces-label">Replaces</span>
                    <span class="wi-feat-chip">Calendly</span>
                    <span class="wi-feat-chip">Acuity</span>
                </div>
            </div>

            <div class="wi-feat-card">
                <div class="wi-feat-icon">🔧</div>
                <div class="wi-feat-title">Work Orders</div>
                <div class="wi-feat-desc">Appointments become work orders automatically. Track the job, the parts, the status, and the customer — from intake to pickup.</div>
                <div class="wi-feat-replaces">
                    <span class="wi-feat-replaces-label">Replaces</span>
                    <span class="wi-feat-chip">Paper tickets</span>
                    <span class="wi-feat-chip">Spreadsheets</span>
                </div>
            </div>

            <div class="wi-feat-card">
                <div class="wi-feat-icon">📣</div>
                <div class="wi-feat-title">Campaigns &amp; SMS</div>
                <div class="wi-feat-desc">Email and SMS campaigns built on your actual customer data. Send tune-up reminders to everyone who hasn't been in since April — in three clicks.</div>
                <div class="wi-feat-replaces">
                    <span class="wi-feat-replaces-label">Replaces</span>
                    <span class="wi-feat-chip">Mailchimp</span>
                    <span class="wi-feat-chip">Klaviyo</span>
                    <span class="wi-feat-chip">Postscript</span>
                </div>
            </div>

            <div class="wi-feat-card">
                <div class="wi-feat-icon">🚲</div>
                <div class="wi-feat-title">Rental Management</div>
                <div class="wi-feat-desc">Fleet inventory, availability, drop-off tracking, and per-resource booking. Built for shops that rent bikes, not just sell them.</div>
                <div class="wi-feat-replaces">
                    <span class="wi-feat-replaces-label">Replaces</span>
                    <span class="wi-feat-chip">RentMan</span>
                    <span class="wi-feat-chip">Booqable</span>
                </div>
            </div>

            <div class="wi-feat-card">
                <div class="wi-feat-icon">💳</div>
                <div class="wi-feat-title">Payments</div>
                <div class="wi-feat-desc">Collect payment at checkout. Stripe-powered, built in. No separate POS subscription. No manual reconciliation at end of day.</div>
                <div class="wi-feat-replaces">
                    <span class="wi-feat-replaces-label">Replaces</span>
                    <span class="wi-feat-chip">Square</span>
                    <span class="wi-feat-chip">Lightspeed</span>
                </div>
            </div>

            <div class="wi-feat-card">
                <div class="wi-feat-icon">🌐</div>
                <div class="wi-feat-title">Your Public Site</div>
                <div class="wi-feat-desc">A booking-connected public presence that knows your services, your hours, and your live availability. Always up-to-date. No plugins.</div>
                <div class="wi-feat-replaces">
                    <span class="wi-feat-replaces-label">Replaces</span>
                    <span class="wi-feat-chip">Squarespace</span>
                    <span class="wi-feat-chip">Wix</span>
                </div>
            </div>

        </div>

    </div>
</section>

{{-- ── COMPARISON TABLE ───────────────────────────────────────── --}}
<section class="mk-section" id="compare">
    <div class="mk-container">

        <p class="mk-eyebrow">Compare</p>
        <h2 class="mk-section-title">Intake vs. the stack.</h2>
        <p class="mk-section-sub">How Intake measures up against patching together five separate tools.</p>

        <div class="wi-table-wrap">
            <table class="wi-table">
                <thead>
                    <tr>
                        <th style="width:30%">Feature</th>
                        <th class="wi-th-intake">✦ Intake</th>
                        <th>Mailchimp / Klaviyo</th>
                        <th>RentMan / Booqable</th>
                        <th>Square / Lightspeed</th>
                        <th>Squarespace / Wix</th>
                    </tr>
                </thead>
                <tbody>

                    {{-- Bookings --}}
                    <tr class="wi-cat-row">
                        <td>Bookings &amp; Scheduling</td>
                        <td class="wi-td-intake"></td>
                        <td></td><td></td><td></td><td></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Online booking page</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">Add-on</span></td>
                        <td><span class="wi-partial">Basic</span></td>
                        <td><span class="wi-partial">Widget only</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Resource-aware scheduling</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Drop-off job workflow</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Work orders linked to bookings</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">Basic tickets</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>

                    {{-- Marketing --}}
                    <tr class="wi-cat-row">
                        <td>Marketing</td>
                        <td class="wi-td-intake"></td>
                        <td></td><td></td><td></td><td></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Email campaigns</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">Basic</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">SMS campaigns</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-partial">Add-on</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Campaigns use booking history</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-partial">Manual sync</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Waitlist &amp; SMS notifications</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>

                    {{-- Payments --}}
                    <tr class="wi-cat-row">
                        <td>Payments</td>
                        <td class="wi-td-intake"></td>
                        <td></td><td></td><td></td><td></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Accept customer payments</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">Limited</span></td>
                        <td><span class="wi-check">✓</span></td>
                        <td><span class="wi-partial">E-com add-on</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Payment tied to work order</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">Separate</span></td>
                        <td><span class="wi-cross">—</span></td>
                    </tr>

                    {{-- Online presence --}}
                    <tr class="wi-cat-row">
                        <td>Online Presence</td>
                        <td class="wi-td-intake"></td>
                        <td></td><td></td><td></td><td></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Public-facing website</td>
                        <td class="wi-td-intake"><span class="wi-check">✓</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">Basic page</span></td>
                        <td><span class="wi-check">✓</span></td>
                    </tr>
                    <tr>
                        <td class="wi-td-feature">Booking embedded natively in site</td>
                        <td class="wi-td-intake"><span class="wi-check">✓ Native</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-cross">—</span></td>
                        <td><span class="wi-partial">3rd-party widget</span></td>
                    </tr>

                    {{-- Pricing row --}}
                    <tr class="wi-price-row">
                        <td>Monthly cost</td>
                        <td class="wi-td-intake">
                            From $29/mo
                            <span class="wi-price-note">all features included</span>
                        </td>
                        <td>$50–200<span class="wi-price-note">email only</span></td>
                        <td>$79–199<span class="wi-price-note">rentals only</span></td>
                        <td>$0 + 2.6%<span class="wi-price-note">POS only</span></td>
                        <td>$23–65<span class="wi-price-note">website only</span></td>
                    </tr>

                </tbody>
            </table>
        </div>

    </div>
</section>

{{-- ── CTA ────────────────────────────────────────────────────── --}}
<section class="mk-section" style="text-align:center;border-bottom:none">
    <div class="mk-container">
        <h2 class="mk-section-title" style="margin-bottom:8px">One platform. Your whole shop.</h2>
        <p class="mk-section-sub" style="margin:0 auto 28px">Join bike shops and service businesses that have ditched the stack.</p>
        <a href="{{ route('platform.signup') }}" class="mk-btn mk-btn--primary">Start free trial →</a>
    </div>
</section>

@endsection
