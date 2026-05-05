{{-- resources/views/marketing/invest.blade.php --}}
@extends('marketing.layout')

@section('title', 'Invest in Intake — Software for service shops, built by an operator')
@section('meta_description', 'Equity crowdfunding raise on Republic. $100k target, $5M valuation cap. Founded by a 4-store bike retail operator building a service-business OS.')

@push('styles')
<style>

/* ================================================================
   Invest — /invest
   Component prefix: iv-
   ================================================================ */

/* ── Hero ─────────────────────────────────────────────────────── */
.iv-hero {
    padding: clamp(64px, 10vw, 120px) 0 clamp(48px, 7vw, 88px);
    text-align: center;
    border-bottom: 0.5px solid var(--mk-border);
}
.iv-hero h1 {
    font-size: clamp(34px, 5.5vw, 64px);
    font-weight: 800;
    letter-spacing: -.03em;
    line-height: 1.05;
    margin-bottom: 22px;
    max-width: 820px;
    margin-left: auto;
    margin-right: auto;
}
.iv-hero h1 em { font-style: normal; color: var(--mk-accent); }
.iv-hero-sub {
    font-size: clamp(15px, 1.8vw, 18px);
    color: var(--mk-muted);
    max-width: 620px;
    margin: 0 auto 18px;
    line-height: 1.65;
}
.iv-hero-founder {
    font-size: 14px;
    color: rgba(255,255,255,.55);
    max-width: 620px;
    margin: 0 auto 36px;
    line-height: 1.65;
    font-style: italic;
}
.iv-hero-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: center;
    margin-bottom: 18px;
}
.iv-hero-meta {
    display: inline-flex;
    gap: 16px;
    flex-wrap: wrap;
    justify-content: center;
    font-size: 12px;
    color: var(--mk-dim);
    padding: 10px 18px;
    border: 0.5px solid var(--mk-border);
    border-radius: 99px;
}
.iv-hero-meta strong { color: var(--mk-text); font-weight: 600; }
.iv-hero-meta-divider { color: var(--mk-border2); }

/* ── Two-column body sections ────────────────────────────────── */
.iv-prose {
    max-width: 720px;
    margin: 0 auto;
}
.iv-prose p {
    font-size: 16px;
    line-height: 1.75;
    color: rgba(255,255,255,.78);
    margin-bottom: 18px;
}
.iv-prose p:last-child { margin-bottom: 0; }
.iv-prose strong { color: var(--mk-text); font-weight: 600; }
.iv-prose h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--mk-text);
    margin: 32px 0 14px;
    letter-spacing: -.01em;
}

/* ── Cost-of-gap callout list ────────────────────────────────── */
.iv-cost-list {
    margin: 28px 0;
    border-top: 0.5px solid var(--mk-border);
}
.iv-cost-row {
    display: grid;
    grid-template-columns: 100px 1fr;
    gap: 24px;
    padding: 18px 0;
    border-bottom: 0.5px solid var(--mk-border);
    align-items: start;
}
.iv-cost-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--mk-accent);
    font-weight: 600;
    padding-top: 4px;
}
.iv-cost-text {
    font-size: 15px;
    line-height: 1.65;
    color: rgba(255,255,255,.78);
}
.iv-cost-text strong { color: var(--mk-text); }

/* ── Product grid ────────────────────────────────────────────── */
.iv-prod-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-top: 32px;
}
.iv-prod-card {
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    padding: 22px;
    transition: border-color .15s;
}
.iv-prod-card:hover { border-color: rgba(255,255,255,.16); }
.iv-prod-card h4 {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--mk-text);
}
.iv-prod-card p {
    font-size: 13px;
    color: var(--mk-muted);
    line-height: 1.6;
}
@media (max-width: 860px) { .iv-prod-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .iv-prod-grid { grid-template-columns: 1fr; } }

.iv-prod-note {
    margin-top: 28px;
    padding: 16px 20px;
    background: rgba(190,242,100,.05);
    border-left: 3px solid var(--mk-accent);
    border-radius: 4px;
    font-size: 13px;
    color: rgba(255,255,255,.72);
    line-height: 1.6;
}
.iv-prod-note code {
    font-family: 'SF Mono', Menlo, monospace;
    font-size: 12px;
    background: rgba(255,255,255,.06);
    padding: 2px 6px;
    border-radius: 3px;
    color: var(--mk-accent);
}

/* ── Verticals ───────────────────────────────────────────────── */
.iv-vert-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-top: 32px;
}
.iv-vert-card {
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    padding: 24px;
    transition: border-color .15s;
}
.iv-vert-card:hover { border-color: rgba(255,255,255,.16); }
.iv-vert-tag {
    display: inline-block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 4px;
    margin-bottom: 12px;
}
.iv-vert-tag-strongest { background: var(--mk-accent); color: var(--mk-accent-text); }
.iv-vert-tag-underserved { background: rgba(190,242,100,.18); color: var(--mk-accent); }
.iv-vert-tag-medium { background: rgba(255,255,255,.08); color: var(--mk-muted); }
.iv-vert-card h4 {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--mk-text);
}
.iv-vert-card p {
    font-size: 14px;
    color: var(--mk-muted);
    line-height: 1.6;
}
@media (max-width: 680px) { .iv-vert-grid { grid-template-columns: 1fr; } }

/* ── Comparison table ────────────────────────────────────────── */
.iv-table-wrap {
    overflow-x: auto;
    border-radius: var(--mk-r-lg);
    border: 0.5px solid var(--mk-border);
    margin-top: 32px;
}
.iv-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 720px;
}
.iv-table th {
    padding: 14px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--mk-dim);
    background: rgba(255,255,255,.02);
    border-bottom: 0.5px solid var(--mk-border);
    white-space: nowrap;
}
.iv-table th.iv-th-intake {
    color: var(--mk-accent);
    font-size: 13px;
}
.iv-table td {
    padding: 12px 16px;
    font-size: 13px;
    color: var(--mk-muted);
    border-bottom: 0.5px solid var(--mk-border);
    vertical-align: middle;
}
.iv-table tr:last-child td { border-bottom: none; }
.iv-table td.iv-td-feature {
    font-weight: 500;
    color: var(--mk-text);
}
.iv-table td.iv-td-intake {
    background: var(--mk-accent-dim);
    color: var(--mk-text);
    font-weight: 600;
}
.iv-check { color: var(--mk-accent); font-weight: 700; }
.iv-cross  { color: rgba(255,255,255,.18); }
.iv-partial { font-size: 12px; color: var(--mk-dim); }
.iv-full-link {
    margin-top: 14px;
    font-size: 13px;
    color: var(--mk-muted);
}
.iv-full-link a { color: var(--mk-accent); border-bottom: 0.5px dashed var(--mk-accent); }
.iv-full-link a:hover { filter: brightness(1.15); }

/* ── Use of funds ────────────────────────────────────────────── */
.iv-funds-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 28px;
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    overflow: hidden;
}
.iv-funds-table td {
    padding: 14px 20px;
    font-size: 14px;
    border-bottom: 0.5px solid var(--mk-border);
}
.iv-funds-table tr:last-child td { border-bottom: none; }
.iv-funds-table .iv-funds-label { color: var(--mk-text); }
.iv-funds-table .iv-funds-amount {
    text-align: right;
    color: var(--mk-text);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    font-family: 'SF Mono', Menlo, monospace;
    font-size: 14px;
}
.iv-funds-table tr.iv-funds-total td {
    background: rgba(190,242,100,.05);
    border-top: 0.5px solid var(--mk-accent);
    font-weight: 700;
    color: var(--mk-text);
    font-size: 15px;
}
.iv-funds-table tr.iv-funds-total .iv-funds-amount {
    color: var(--mk-accent);
    font-size: 16px;
}
.iv-funds-detail {
    font-size: 12px;
    color: var(--mk-dim);
    display: block;
    margin-top: 2px;
    font-weight: 400;
}

.iv-enables {
    margin-top: 28px;
    padding-top: 24px;
    border-top: 0.5px solid var(--mk-border);
}
.iv-enables h3 {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--mk-dim);
    font-weight: 600;
    margin-bottom: 14px;
}
.iv-enables ul { list-style: none; }
.iv-enables li {
    padding: 8px 0 8px 24px;
    font-size: 14px;
    color: rgba(255,255,255,.78);
    position: relative;
    line-height: 1.55;
}
.iv-enables li::before {
    content: '→';
    position: absolute;
    left: 0;
    color: var(--mk-accent);
    font-weight: 700;
}

/* ── Vision timeline ─────────────────────────────────────────── */
.iv-timeline {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-top: 32px;
}
.iv-timeline-card {
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    padding: 22px;
}
.iv-timeline-year {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--mk-accent);
    font-weight: 600;
    margin-bottom: 10px;
}
.iv-timeline-num {
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -.02em;
    line-height: 1;
    margin-bottom: 4px;
}
.iv-timeline-num-sub {
    font-size: 12px;
    color: var(--mk-dim);
    margin-bottom: 14px;
}
.iv-timeline-card p {
    font-size: 13px;
    color: var(--mk-muted);
    line-height: 1.6;
}
@media (max-width: 760px) { .iv-timeline { grid-template-columns: 1fr; } }

/* ── Ask / terms ─────────────────────────────────────────────── */
.iv-terms {
    background: rgba(255,255,255,.03);
    border: 0.5px solid var(--mk-border2);
    border-radius: var(--mk-r-lg);
    padding: 32px;
    margin-top: 32px;
}
.iv-terms-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px 32px;
    margin-bottom: 24px;
}
.iv-term-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 0.5px solid var(--mk-border);
    font-size: 14px;
}
.iv-term-row:last-child { border-bottom: none; }
.iv-term-label { color: var(--mk-muted); }
.iv-term-value { color: var(--mk-text); font-weight: 600; }
.iv-terms-cta { display: flex; justify-content: center; margin-top: 8px; }
.iv-terms-disclaimer {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 0.5px solid var(--mk-border);
    font-size: 11px;
    color: var(--mk-dim);
    line-height: 1.6;
    text-align: center;
}
@media (max-width: 600px) { .iv-terms-grid { grid-template-columns: 1fr; } }

/* ── FAQ ──────────────────────────────────────────────────────── */
.iv-faq { max-width: 760px; margin: 32px auto 0; }
.iv-faq-item {
    border-bottom: 0.5px solid var(--mk-border);
    padding: 18px 0;
}
.iv-faq-item:first-child { border-top: 0.5px solid var(--mk-border); }
.iv-faq-q {
    font-size: 15px;
    font-weight: 600;
    color: var(--mk-text);
    margin-bottom: 8px;
    line-height: 1.5;
}
.iv-faq-a {
    font-size: 14px;
    color: var(--mk-muted);
    line-height: 1.7;
}

/* ── Closing CTA ──────────────────────────────────────────────── */
.iv-closing {
    text-align: center;
    padding: clamp(64px, 8vw, 96px) 0;
}
.iv-closing h2 {
    font-size: clamp(28px, 4vw, 44px);
    font-weight: 800;
    letter-spacing: -.02em;
    margin-bottom: 14px;
    line-height: 1.15;
}
.iv-closing p {
    font-size: 16px;
    color: var(--mk-muted);
    max-width: 540px;
    margin: 0 auto 28px;
    line-height: 1.6;
}
.iv-closing-links {
    margin-top: 24px;
    display: flex;
    gap: 18px;
    justify-content: center;
    flex-wrap: wrap;
    font-size: 13px;
    color: var(--mk-muted);
}
.iv-closing-links a { color: var(--mk-muted); border-bottom: 0.5px dashed var(--mk-border2); padding-bottom: 1px; }
.iv-closing-links a:hover { color: var(--mk-text); border-bottom-color: var(--mk-accent); }

</style>
@endpush

@section('content')

{{-- ── HERO ───────────────────────────────────────────────────── --}}
<section class="iv-hero">
    <div class="mk-container">

        <p class="mk-eyebrow">Equity crowdfunding · Coming soon to Republic</p>

        <h1>Software for service shops, built by someone who <em>ran four of them.</em></h1>

        <p class="iv-hero-sub">
            Bike shops, salons, yoga studios, pet groomers — the tools you're stuck with were built for chains, not for you. Intake replaces the duct-taped stack of Square, Acuity, Mailchimp, and a spreadsheet with one product designed around the way independent service shops actually work.
        </p>

        <p class="iv-hero-founder">
            Built by Josh Tofsrud — partner in a four-store bike retail brand ($6M annual revenue, $2.3M ecommerce), founder of a premium bicycle wheel company, and 18 years of running events end-to-end. Intake is the software I needed and couldn't buy.
        </p>

        <div class="iv-hero-actions">
            {{-- TODO: replace # with Republic listing URL once live --}}
            <a href="#" class="mk-btn mk-btn--primary">Invest on Republic →</a>
            <a href="#product" class="mk-btn mk-btn--ghost">See the product</a>
        </div>

        <div class="iv-hero-meta">
            <span><strong>$100k</strong> target</span>
            <span class="iv-hero-meta-divider">·</span>
            <span><strong>$5M</strong> valuation cap</span>
            <span class="iv-hero-meta-divider">·</span>
            <span>SAFE w/ 20% discount</span>
        </div>

    </div>
</section>

{{-- ── PROBLEM ────────────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">The problem</p>
        <h2 class="mk-section-title">The independent service shop is the most underserved category in SaaS.</h2>

        <div class="iv-prose">

            <p>
                Walk into any bike shop, salon, yoga studio, or grooming shop and you'll find the same thing: three to five disconnected pieces of software, each one charging monthly, each one missing one critical thing the shop actually needs.
            </p>
            <p>
                The bike shop runs Lightspeed for retail, manages repairs in a spreadsheet, books service drop-offs through a separate calendar app, and sends marketing through Mailchimp. The salon pays Vagaro $90 a month and gets nickel-and-dimed on every booking fee, every text message, every add-on. The yoga studio renews Mindbody at $349 because switching seems impossible. The grooming shop tracks pet records on paper because no software handles drop-offs the way a groomer actually works.
            </p>
            <p>
                Each piece of software was built for a different shape of business. None of them were built for the shop owner who works the counter, manages two staff, runs Saturday morning rush, and goes home to do payroll on the dining room table.
            </p>

            <h3>The cost of that gap</h3>

            <div class="iv-cost-list">
                <div class="iv-cost-row">
                    <div class="iv-cost-label">Time</div>
                    <div class="iv-cost-text">5–10 hours a week stitching software together, retyping customer info, fixing missed bookings.</div>
                </div>
                <div class="iv-cost-row">
                    <div class="iv-cost-label">Money</div>
                    <div class="iv-cost-text"><strong>$200–400/month</strong> in stacked SaaS fees per location, plus payment processing markups.</div>
                </div>
                <div class="iv-cost-row">
                    <div class="iv-cost-label">Customers</div>
                    <div class="iv-cost-text">Every system seam is a place customers fall through. The double-booked appointment. The repair quote that never made it back to the customer. The retail purchase that didn't get logged against the customer profile.</div>
                </div>
                <div class="iv-cost-row">
                    <div class="iv-cost-label">Brand</div>
                    <div class="iv-cost-text">The shop's website, booking page, and receipts all look like they came from four different companies. Because they did.</div>
                </div>
            </div>

            <p>
                The independent shop owner doesn't need more features. They need <strong>fewer products doing more of the work</strong>, designed by someone who's actually stood behind the counter.
            </p>

        </div>
    </div>
</section>

{{-- ── PRODUCT ────────────────────────────────────────────────── --}}
<section class="mk-section" id="product">
    <div class="mk-container">

        <p class="mk-eyebrow">The product</p>
        <h2 class="mk-section-title">What Intake does today.</h2>
        <p class="mk-section-sub">Already built and running on real Stripe billing — not vapor.</p>

        <div class="iv-prod-grid">

            <div class="iv-prod-card">
                <h4>Booking &amp; calendar</h4>
                <p>Customer-facing booking page. Day, week, and resource calendar views. Capacity rules, prep and cleanup time, drop-off scheduling with dual-cap windows. Multi-location, drag-to-reschedule.</p>
            </div>

            <div class="iv-prod-card">
                <h4>Point of sale</h4>
                <p>Full register with product search, services, walk-in customer attach, tip and tax handling, multi-tender payments, line-item refunds, draft cart auto-save, and quotes.</p>
            </div>

            <div class="iv-prod-card">
                <h4>Inventory</h4>
                <p>Products with variants, locations, multi-warehouse stock, receiving workflow, vendor catalogs. Designed so a bike shop with 4,000 SKUs and a salon with 40 both feel native.</p>
            </div>

            <div class="iv-prod-card">
                <h4>Customer records</h4>
                <p>One profile per customer regardless of whether they bought retail, booked a service, or did both. Service history, purchase history, notes, custom intake forms.</p>
            </div>

            <div class="iv-prod-card">
                <h4>Marketing</h4>
                <p>Block-builder pages for the shop's website. Branded customer-facing booking page on a custom subdomain. Email templates and campaigns with image library and per-tier quotas.</p>
            </div>

            <div class="iv-prod-card">
                <h4>Multi-tenant architecture</h4>
                <p>Built from day one to scale to thousands of tenants on shared infrastructure. Subdomain routing, advisory locking on shared resources, indexed tenant-scoped queries throughout.</p>
            </div>

        </div>

        <div class="iv-prod-note">
            Currently running a live dog-food tenant on real Stripe billing at <code>thebikehub.intake.works</code>. Every feature listed above ships through that tenant first.
        </div>

    </div>
</section>

{{-- ── VERTICALS ──────────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">Who it's for</p>
        <h2 class="mk-section-title">Built for four verticals at launch.</h2>
        <p class="mk-section-sub">Most service shop software either picks one vertical and goes deep, or stays so generic it serves none of them well. Intake's architecture splits the difference.</p>

        <div class="iv-prose" style="margin-bottom:0">
            <p>
                A shared core, with <strong>industry packs</strong> that change vocabulary, defaults, and key workflows per vertical. Each industry pack ships as a tenant onboarding choice — the product reorganizes itself around what that vertical actually needs.
            </p>
        </div>

        <div class="iv-vert-grid">

            <div class="iv-vert-card">
                <span class="iv-vert-tag iv-vert-tag-strongest">Strongest fit</span>
                <h4>Bike shops</h4>
                <p>Work orders, repair tracking, drop-off scheduling, retail POS, inventory with vendor catalog support. Replaces Lightspeed Retail Pro + Ascend RMS + a spreadsheet.</p>
            </div>

            <div class="iv-vert-card">
                <span class="iv-vert-tag iv-vert-tag-underserved">Underserved</span>
                <h4>Pet grooming shops</h4>
                <p>Drop-off scheduling, pet records, retail, walk-in management. Most shops still use paper — there's almost no software competition in this category.</p>
            </div>

            <div class="iv-vert-card">
                <span class="iv-vert-tag iv-vert-tag-medium">Price disruption</span>
                <h4>Salons &amp; barbershops</h4>
                <p>Booking, walk-in management, retail, client history. Replaces Vagaro / GlossGenius / Boulevard at a fraction of the cost, with no per-booking fees.</p>
            </div>

            <div class="iv-vert-card">
                <span class="iv-vert-tag iv-vert-tag-medium">Mindbody alternative</span>
                <h4>Yoga &amp; fitness studios</h4>
                <p>Class scheduling, membership management, walk-in drop-ins, retail. At $349/mo for a small studio, the room is wide open for a $79 alternative that does the same job.</p>
            </div>

        </div>

    </div>
</section>

{{-- ── COMPARISON ─────────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">Competitive snapshot</p>
        <h2 class="mk-section-title">How Intake compares.</h2>
        <p class="mk-section-sub">The full feature comparison runs 47 rows across 12 competitors. Here's the snapshot.</p>

        <div class="iv-table-wrap">
            <table class="iv-table">
                <thead>
                    <tr>
                        <th style="width:30%">Feature</th>
                        <th class="iv-th-intake">✦ Intake</th>
                        <th>Mindbody</th>
                        <th>Vagaro</th>
                        <th>Square Apt</th>
                        <th>Lightspeed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="iv-td-feature">Starting price</td>
                        <td class="iv-td-intake">$29/mo</td>
                        <td>$129/mo</td>
                        <td>$30/mo</td>
                        <td>Free</td>
                        <td>$89/mo</td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Top tier</td>
                        <td class="iv-td-intake">$199/mo</td>
                        <td>$349+/mo</td>
                        <td>$90+/mo</td>
                        <td>$69/mo</td>
                        <td>$289/mo</td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Free trial</td>
                        <td class="iv-td-intake"><span class="iv-check">✓</span> 14 days</td>
                        <td><span class="iv-partial">demo only</span></td>
                        <td><span class="iv-check">✓</span> 30 days</td>
                        <td><span class="iv-check">✓</span></td>
                        <td><span class="iv-partial">demo only</span></td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Annual contract required</td>
                        <td class="iv-td-intake">No</td>
                        <td>Yes</td>
                        <td>No</td>
                        <td>No</td>
                        <td><span class="iv-partial">discount</span></td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Drop-off / repair workflow</td>
                        <td class="iv-td-intake"><span class="iv-check">✓</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-check">✓</span></td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Class booking + waitlist</td>
                        <td class="iv-td-intake"><span class="iv-check">✓</span></td>
                        <td><span class="iv-check">✓</span></td>
                        <td><span class="iv-check">✓</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">White-label booking page</td>
                        <td class="iv-td-intake"><span class="iv-check">✓</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-partial">limited</span></td>
                        <td><span class="iv-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Custom subdomain</td>
                        <td class="iv-td-intake"><span class="iv-check">✓</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Marketplace exposure to other businesses</td>
                        <td class="iv-td-intake">No (your customers stay yours)</td>
                        <td>Yes</td>
                        <td>Yes</td>
                        <td>No</td>
                        <td>No</td>
                    </tr>
                    <tr>
                        <td class="iv-td-feature">Industry-pack architecture</td>
                        <td class="iv-td-intake">Unique</td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                        <td><span class="iv-cross">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="iv-prose" style="margin-top:32px">
            <p>
                Two things worth pulling out of this table. <strong>Marketplace exposure</strong> — Vagaro and Mindbody both put the shop's customers into a competitive marketplace where other shops can advertise to them. Most owners don't realize this when they sign up. Intake doesn't have a marketplace; your customers are your customers.
            </p>
            <p>
                <strong>Industry-pack architecture</strong> — nobody else does this. Competitors either lock you into one vertical's vocabulary (Vagaro is "appointments," Mindbody is "classes," Lightspeed is "retail orders") or stay so generic the product feels like a kit you have to assemble. Intake's industry packs are the bridge.
            </p>
        </div>

    </div>
</section>

{{-- ── MARKET ─────────────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">The market</p>
        <h2 class="mk-section-title">The market is bigger than the spreadsheets show.</h2>

        <div class="iv-prose">
            <p>
                Standard analyst reports undercount this market three ways:
            </p>
            <p>
                <strong>The shops still on paper.</strong> The grooming industry is overwhelmingly on paper. A meaningful slice of bike shops still run service tickets on triplicate carbon forms. These shops aren't switching from another SaaS — they're entering the category for the first time. Their TAM doesn't show up in competitor revenue numbers because there's no competitor revenue to count.
            </p>
            <p>
                <strong>Stacked SaaS spend.</strong> A salon paying $90/mo to Vagaro is also paying $20 to Mailchimp, $40 to a separate booking page tool, and 2.9% + $0.30 per transaction to a payment processor that's not their booking software. The "Vagaro market size" only counts the $90.
            </p>
            <p>
                <strong>Multi-location chains under 10 stores.</strong> Lightspeed and Mindbody both lose interest at this size. Square wins on price but loses on functionality. There's a soft middle of 2–9 location operators that no current product serves well.
            </p>

            <h3>Unit economics</h3>

            <p>
                Blended ARPU target: <strong>$60/month</strong> across the $29 / $79 / $199 tiers plus add-ons. Year-5 target: <strong>4,000 paying tenants → $2.88M ARR</strong>. Gross margin 80%+ at scale (multi-tenant infrastructure, no per-tenant cost beyond storage).
            </p>
            <p>
                Path to that target: vertical-specific Google Ads campaigns (designed and ready to launch), industry partnerships, founder network in the bike industry, content marketing.
            </p>
            <p>
                4,000 tenants is roughly <strong>0.4% of the U.S. small-business service-shop market</strong>. We don't need to win the market. We need to win our slice of it.
            </p>
        </div>

    </div>
</section>

{{-- ── FOUNDER STORY ──────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">The founder</p>
        <h2 class="mk-section-title">Why I'm the right person to build this.</h2>

        <div class="iv-prose">
            <p>
                I'm not a SaaS founder who interviewed shop owners and decided this was a market opportunity. I'm a shop owner who built software because the existing tools were costing me hours every week and breaking my customer experience.
            </p>
            <p>
                <strong>I helped grow a four-location bike retail brand</strong> that scaled to $6M in annual revenue, with $2.3M coming from ecommerce. I joined as a partner during the early growth phase, contributed to brand development before joining, and was directly involved in roughly 85% of the company's growth — store operations, ecommerce expansion, hiring, training, community programs. We closed in early 2026 — bittersweet decision, business partners were ready to be done, and continuing without them didn't make sense.
            </p>
            <p>
                <strong>I built a premium bicycle wheel company</strong> focused on service quality and product performance instead of price. Sold the most expensive single component on a bicycle into a market dominated by aggressive discounters, and it worked because the service standard was uncompromising.
            </p>
            <p>
                <strong>I've run cycling events end-to-end for 18 years.</strong> Built my own race-timing system from scratch, handled every layer of regulation, branding, sponsorship, and operations. The kind of full-stack execution that translates directly into building software the same way: do the parts most people skip, refuse the shortcuts that compromise the customer experience.
            </p>
            <p>
                Across all three businesses, the constant frustration was software. Either it was too expensive (Mindbody, Lightspeed Retail Pro), too generic (Square Appointments, Acuity), or too narrow (every single-purpose tool I had to chain together). Nothing was built for the way an independent service shop actually operates.
            </p>
            <p>
                So I built it. Intake exists because I needed it to exist. The product is the synthesis of 18 years of operating service businesses and watching software fail them at the moments that matter most.
            </p>
            <p>
                <strong>Now I want to do this full-time.</strong> This raise is what makes that possible.
            </p>
        </div>

    </div>
</section>

{{-- ── USE OF FUNDS ───────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">Use of funds</p>
        <h2 class="mk-section-title">What $100,000 funds.</h2>
        <p class="mk-section-sub">Six months of full-time founder commitment plus the supporting team and infrastructure to ship the product into the market.</p>

        <table class="iv-funds-table">
            <tr>
                <td class="iv-funds-label">
                    Founder runway
                    <span class="iv-funds-detail">6 months full-time</span>
                </td>
                <td class="iv-funds-amount">$24,000</td>
            </tr>
            <tr>
                <td class="iv-funds-label">
                    Part-time developer
                    <span class="iv-funds-detail">6 months · service work + bug triage</span>
                </td>
                <td class="iv-funds-amount">$18,000</td>
            </tr>
            <tr>
                <td class="iv-funds-label">
                    First part-time employee
                    <span class="iv-funds-detail">Kicks in at 100 tenants · ~3 months</span>
                </td>
                <td class="iv-funds-amount">$9,000</td>
            </tr>
            <tr>
                <td class="iv-funds-label">
                    Legal
                    <span class="iv-funds-detail">Republic raise filings, terms, basic corporate</span>
                </td>
                <td class="iv-funds-amount">$15,000</td>
            </tr>
            <tr>
                <td class="iv-funds-label">
                    Infrastructure
                    <span class="iv-funds-detail">Hosting, S3, transactional email, monitoring · 6 months</span>
                </td>
                <td class="iv-funds-amount">$7,000</td>
            </tr>
            <tr>
                <td class="iv-funds-label">
                    Marketing
                    <span class="iv-funds-detail">Google Ads campaigns across four verticals, content, design</span>
                </td>
                <td class="iv-funds-amount">$20,000</td>
            </tr>
            <tr>
                <td class="iv-funds-label">
                    Republic platform fee
                    <span class="iv-funds-detail">~7% of raise</span>
                </td>
                <td class="iv-funds-amount">$7,000</td>
            </tr>
            <tr class="iv-funds-total">
                <td class="iv-funds-label">Total</td>
                <td class="iv-funds-amount">$100,000</td>
            </tr>
        </table>

        <div class="iv-enables">
            <h3>What this enables</h3>
            <ul>
                <li><strong>Founder full-time on Intake</strong> — sales, customer onboarding, vertical expansion, product strategy</li>
                <li><strong>Developer support</strong> — frees founder from service-call interrupts during onboarding push</li>
                <li><strong>First employee at 100 tenants</strong> — the moment customer support becomes a real workload</li>
                <li><strong>Live Google Ads campaign</strong> in all four verticals (campaign design complete; needs spend)</li>
                <li><strong>Legal cleanup</strong> to make a future priced round straightforward</li>
            </ul>
        </div>

    </div>
</section>

{{-- ── VISION ─────────────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">Vision</p>
        <h2 class="mk-section-title">Where this goes.</h2>

        <div class="iv-timeline">

            <div class="iv-timeline-card">
                <div class="iv-timeline-year">Year 1</div>
                <div class="iv-timeline-num">500</div>
                <div class="iv-timeline-num-sub">paying tenants</div>
                <p>Bike shops and pet groomers as the lead verticals. Salons and yoga in soft launch. Real revenue, real retention data, real founder-built customer base.</p>
            </div>

            <div class="iv-timeline-card">
                <div class="iv-timeline-year">Year 3</div>
                <div class="iv-timeline-num">2,000</div>
                <div class="iv-timeline-num-sub">paying tenants</div>
                <p>All four verticals at scale. Industry partnerships with vendor catalogs (QBP, Hawley, Trek for bike; comparable distributors for the others). Mobile app for staff. Branded customer apps for higher tiers.</p>
            </div>

            <div class="iv-timeline-card">
                <div class="iv-timeline-year">Year 5</div>
                <div class="iv-timeline-num">4,000</div>
                <div class="iv-timeline-num-sub">paying tenants · $2.88M ARR</div>
                <p>Profitable. Vertical-specific feature depth that competitors can't match without re-architecting their products. Either continuing as an independent profitable SaaS, or in a position to take a larger growth round on terms that respect what's been built.</p>
            </div>

        </div>

        <div class="iv-prose" style="margin-top:32px">
            <p>
                The thing I want investors to understand: this is built to be a real business, not a venture-scale moonshot. The architecture is honest about that. The unit economics work at small scale. The exit path is either a strategic acquisition by a larger SaaS player who recognizes the vertical depth, or a durable cash-flowing business. Both are good outcomes for early investors.
            </p>
        </div>

    </div>
</section>

{{-- ── THE ASK / TERMS ────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">The ask</p>
        <h2 class="mk-section-title">The terms.</h2>

        <div class="iv-terms">

            <div class="iv-terms-grid">
                <div class="iv-term-row">
                    <span class="iv-term-label">Raising</span>
                    <span class="iv-term-value">$100,000</span>
                </div>
                <div class="iv-term-row">
                    <span class="iv-term-label">Instrument</span>
                    <span class="iv-term-value">SAFE</span>
                </div>
                <div class="iv-term-row">
                    <span class="iv-term-label">Valuation cap</span>
                    <span class="iv-term-value">$5,000,000</span>
                </div>
                <div class="iv-term-row">
                    <span class="iv-term-label">Discount</span>
                    <span class="iv-term-value">20%</span>
                </div>
                <div class="iv-term-row">
                    <span class="iv-term-label">Minimum check</span>
                    <span class="iv-term-value">$100</span>
                </div>
                <div class="iv-term-row">
                    <span class="iv-term-label">Closing</span>
                    <span class="iv-term-value">{{-- TODO date --}} TBA</span>
                </div>
            </div>

            <div class="iv-terms-cta">
                {{-- TODO: Republic listing URL --}}
                <a href="#" class="mk-btn mk-btn--primary">Invest on Republic →</a>
            </div>

            <div class="iv-terms-disclaimer">
                SAFE notes convert at the next priced round, capped at the $5M valuation. If a later round prices the company higher, your $100 effectively bought in at $5M — the upside is yours. If the company doesn't reach a priced round, the SAFE remains until a liquidity event.<br><br>
                Investing in startups is risky. Most startups fail. Only invest what you can afford to lose. Read the full Form C on Republic before investing.
            </div>

        </div>

    </div>
</section>

{{-- ── FAQ ────────────────────────────────────────────────────── --}}
<section class="mk-section">
    <div class="mk-container">

        <p class="mk-eyebrow">FAQ</p>
        <h2 class="mk-section-title">Common questions.</h2>

        <div class="iv-faq">

            <div class="iv-faq-item">
                <div class="iv-faq-q">What stage is the product?</div>
                <div class="iv-faq-a">Live and running on production infrastructure. Multi-tenant SaaS with a live dog-food tenant on real Stripe billing. Pre-launch in the sense that we haven't opened paid signups to outside customers yet — that's what this raise funds.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">When are you opening to paying customers?</div>
                <div class="iv-faq-a">Within 60 days of the raise closing. The Google Ads campaign launch is gated on this.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">What was the founder's role at the bike retail business?</div>
                <div class="iv-faq-a">Joined as a partner during the early growth phase, contributed to brand development before joining, and was directly involved in roughly 85% of the company's growth — operations, ecommerce, hiring, training, community programs. Closed in early 2026 by mutual agreement of the partners.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">What if Intake doesn't reach 4,000 tenants?</div>
                <div class="iv-faq-a">The architecture and unit economics work at much smaller numbers. At 500 tenants and $60 ARPU, the business is at $360k ARR with low operational overhead — a reasonable solo-founder + small-team operation. The 4,000 number is the ambition, not the survival threshold.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">What's the biggest risk?</div>
                <div class="iv-faq-a">Customer acquisition. The product is built; the open question is what CAC looks like at scale across the four verticals. The Google Ads playbook is the first answer to that question, and the founder's industry network is the second. Both are real but unproven.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">Why Republic and not VC?</div>
                <div class="iv-faq-a">The right structure for this stage of the business. VC requires a 100x return path; Intake's path is more like 10–20x with much higher base-case probability. Republic lets retail investors who emotionally connect with the product participate, and keeps the cap table clean for whatever comes next.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">Are you full-time on this?</div>
                <div class="iv-faq-a">Going full-time is what this raise funds. Currently building the product nights and weekends while transitioning out of the bike retail business.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">Who's on the team?</div>
                <div class="iv-faq-a">Solo founder. The raise funds a part-time developer and a first part-time employee. Building deliberately small to keep the unit economics honest.</div>
            </div>

            <div class="iv-faq-item">
                <div class="iv-faq-q">How is customer support handled at launch?</div>
                <div class="iv-faq-a">Founder-led for the first 100 tenants. First part-time employee added at the 100-tenant mark. This is intentional — direct founder contact with early customers is the highest-quality input the product can get during the launch phase.</div>
            </div>

        </div>

    </div>
</section>

{{-- ── CLOSING CTA ────────────────────────────────────────────── --}}
<section class="iv-closing">
    <div class="mk-container">
        <h2>Build a service-business operating system with us.</h2>
        <p>Intake is software for the people who actually run independent service shops. Built by someone who ran them. Designed to replace four products with one. Live, working, ready for customers.</p>
        {{-- TODO: Republic listing URL --}}
        <a href="#" class="mk-btn mk-btn--primary">Invest on Republic →</a>
        <div class="iv-closing-links">
            <a href="{{ route('marketing.home') }}">intake.works</a>
            {{-- TODO: real LinkedIn URL --}}
            <a href="#">LinkedIn</a>
            {{-- TODO: real email href --}}
            <a href="mailto:josh@intake.works">Email Josh</a>
        </div>
    </div>
</section>

@endsection
