@extends('layouts.tenant.app')
@php $pageTitle = 'Help & Guides'; @endphp

@push('styles')
<style>
.help-page { max-width: 900px; }
.help-hero {
  background: linear-gradient(135deg, var(--ia-accent-soft), rgba(255,255,255,.02));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 40px;
  margin-bottom: 24px;
  text-align: center;
}
.help-hero-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
.help-hero-sub { font-size: 15px; opacity: .6; max-width: 540px; margin: 0 auto; }

.help-org {
  border: 0.5px dashed var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 16px 20px;
  margin-bottom: 28px;
  font-size: 13px;
  opacity: .8;
  line-height: 1.65;
}
.help-org-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 600;
  margin-bottom: 8px;
}
.help-org strong { color: var(--ia-text); font-weight: 600; }

.help-toc-group {
  margin-bottom: 18px;
}
.help-toc-group-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 600;
  margin-bottom: 10px;
  padding-left: 4px;
}
.help-toc {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 10px;
}
.help-toc-card {
  padding: 16px 18px;
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  cursor: pointer;
  transition: border-color .12s, transform .12s;
  text-decoration: none;
  color: inherit;
  display: block;
}
.help-toc-card:hover { border-color: var(--ia-accent); transform: translateY(-1px); }
.help-toc-icon { font-size: 22px; margin-bottom: 8px; }
.help-toc-title { font-size: 14px; font-weight: 600; margin-bottom: 3px; }
.help-toc-desc { font-size: 11.5px; opacity: .55; line-height: 1.4; }

.help-section {
  margin-bottom: 48px;
  scroll-margin-top: 20px;
}
.help-section-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
  padding-bottom: 12px;
  border-bottom: 0.5px solid var(--ia-border);
}
.help-section-icon { font-size: 26px; }
.help-section-title { font-size: 19px; font-weight: 700; }
.help-section-sub {
  font-size: 13px;
  opacity: .55;
  margin-left: auto;
  font-style: italic;
}

.help-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 22px 24px;
  margin-bottom: 14px;
}
.help-card-title {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.help-card-num {
  background: var(--ia-accent);
  color: var(--ia-accent-text);
  width: 22px; height: 22px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; flex-shrink: 0;
}
.help-text { font-size: 13.5px; line-height: 1.7; opacity: .8; }
.help-text p { margin-bottom: 10px; }
.help-text ul { padding-left: 20px; margin-bottom: 10px; }
.help-text li { margin-bottom: 6px; }
.help-text strong { color: var(--ia-text); }
.help-text code {
  font-family: 'JetBrains Mono', 'SF Mono', monospace;
  font-size: 12px;
  background: rgba(255,255,255,.05);
  padding: 1px 6px;
  border-radius: 4px;
}

.help-tip {
  background: var(--ia-accent-soft);
  border-left: 3px solid var(--ia-accent);
  padding: 12px 16px;
  border-radius: 0 var(--ia-r-md) var(--ia-r-md) 0;
  font-size: 12.5px;
  margin: 14px 0;
  line-height: 1.6;
}
.help-tip-label {
  font-weight: 600;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 4px;
  opacity: .7;
}

.help-warning {
  background: rgba(245,158,11,.08);
  border-left: 3px solid #F59E0B;
  padding: 12px 16px;
  border-radius: 0 var(--ia-r-md) var(--ia-r-md) 0;
  font-size: 12.5px;
  margin: 14px 0;
  line-height: 1.6;
}
.help-warning-label {
  font-weight: 600;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 4px;
  color: #F59E0B;
}

.help-flow {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin: 14px 0;
}
.help-flow-step {
  background: rgba(255,255,255,.04);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 500;
}
.help-flow-arrow { opacity: .3; font-size: 14px; }

.help-back-top {
  text-align: center;
  padding: 24px;
  opacity: .4;
  font-size: 13px;
}
.help-back-top a { color: var(--ia-accent); text-decoration: underline; }
</style>
@endpush

@section('content')
<div class="help-page">

  {{-- Hero --}}
  <div class="help-hero">
    <div class="help-hero-title">Help &amp; Guides</div>
    <div class="help-hero-sub">Everything you need to run your shop on Intake. Sections below mirror the order they appear in your sidebar.</div>
  </div>

  {{-- How this guide is organized --}}
  <div class="help-org">
    <div class="help-org-label">How this guide is organized</div>
    The sidebar on the left has three groups: <strong>top-level</strong> (Dashboard, Schedule, Customers), <strong>Manage</strong> (the things you set up once and edit occasionally), and <strong>Settings</strong> (account-level configuration). This guide follows that exact order, so &ldquo;the page below the heading&rdquo; matches &ldquo;the menu item in your sidebar.&rdquo;
  </div>

  {{-- ============================================================ --}}
  {{-- TABLE OF CONTENTS --}}
  {{-- ============================================================ --}}
  <div class="help-toc-group">
    <div class="help-toc-group-label">Day-to-day</div>
    <div class="help-toc">
      <a href="#dashboard" class="help-toc-card">
        <div class="help-toc-icon">📊</div>
        <div class="help-toc-title">Dashboard</div>
        <div class="help-toc-desc">Today, what needs attention, growth</div>
      </a>
      <a href="#schedule" class="help-toc-card">
        <div class="help-toc-icon">📅</div>
        <div class="help-toc-title">Schedule</div>
        <div class="help-toc-desc">Calendar, drag-to-reschedule, drawer view</div>
      </a>
      <a href="#customers" class="help-toc-card">
        <div class="help-toc-icon">👥</div>
        <div class="help-toc-title">Customers</div>
        <div class="help-toc-desc">Profiles, history, notes, search</div>
      </a>
    </div>
  </div>

  <div class="help-toc-group">
    <div class="help-toc-group-label">Manage your shop</div>
    <div class="help-toc">
      <a href="#services" class="help-toc-card">
        <div class="help-toc-icon">🛠</div>
        <div class="help-toc-title">Services</div>
        <div class="help-toc-desc">Catalog, pricing, durations, add-ons</div>
      </a>
      <a href="#resources" class="help-toc-card">
        <div class="help-toc-icon">👨‍🔧</div>
        <div class="help-toc-title">Resources</div>
        <div class="help-toc-desc">Staff, stations, capacity, eligibility</div>
      </a>
      <a href="#work-order-fields" class="help-toc-card">
        <div class="help-toc-icon">🔧</div>
        <div class="help-toc-title">Work Order Fields</div>
        <div class="help-toc-desc">Custom fields per appointment</div>
      </a>
      <a href="#intake-form-editor" class="help-toc-card">
        <div class="help-toc-icon">📝</div>
        <div class="help-toc-title">Intake Form Editor</div>
        <div class="help-toc-desc">Customize what customers fill out</div>
      </a>
      <a href="#capacity" class="help-toc-card">
        <div class="help-toc-icon">🕐</div>
        <div class="help-toc-title">Capacity</div>
        <div class="help-toc-desc">Hours, daily caps, date overrides</div>
      </a>
      <a href="#pages" class="help-toc-card">
        <div class="help-toc-icon">🌐</div>
        <div class="help-toc-title">Pages</div>
        <div class="help-toc-desc">Build your public website</div>
      </a>
      <a href="#email" class="help-toc-card">
        <div class="help-toc-icon">✉️</div>
        <div class="help-toc-title">Email</div>
        <div class="help-toc-desc">Templates, tokens, transactional copy</div>
      </a>
      <a href="#waitlist" class="help-toc-card">
        <div class="help-toc-icon">⏳</div>
        <div class="help-toc-title">Waitlist</div>
        <div class="help-toc-desc">Capture demand on full days</div>
      </a>
      <a href="#campaigns" class="help-toc-card">
        <div class="help-toc-icon">📣</div>
        <div class="help-toc-title">Campaigns</div>
        <div class="help-toc-desc">Email and SMS to your customers</div>
      </a>
    </div>
  </div>

  <div class="help-toc-group">
    <div class="help-toc-group-label">Settings &amp; account</div>
    <div class="help-toc">
      <a href="#whats-new" class="help-toc-card">
        <div class="help-toc-icon">✨</div>
        <div class="help-toc-title">What&rsquo;s New</div>
        <div class="help-toc-desc">Recent shipped features</div>
      </a>
      <a href="#whats-coming" class="help-toc-card">
        <div class="help-toc-icon">🗺️</div>
        <div class="help-toc-title">What&rsquo;s Coming</div>
        <div class="help-toc-desc">Public roadmap</div>
      </a>
      <a href="#branding" class="help-toc-card">
        <div class="help-toc-icon">🎨</div>
        <div class="help-toc-title">Branding</div>
        <div class="help-toc-desc">Logo, colors, fonts, identity</div>
      </a>
      <a href="#settings" class="help-toc-card">
        <div class="help-toc-icon">⚙️</div>
        <div class="help-toc-title">Settings</div>
        <div class="help-toc-desc">General, booking, payments, billing, domain</div>
      </a>
      <a href="#addons" class="help-toc-card">
        <div class="help-toc-icon">🧩</div>
        <div class="help-toc-title">Add-ons</div>
        <div class="help-toc-desc">Optional features and integrations</div>
      </a>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- DASHBOARD --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="dashboard">
    <div class="help-section-head">
      <span class="help-section-icon">📊</span>
      <span class="help-section-title">Dashboard</span>
      <span class="help-section-sub">your home page</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Dashboard is built around three questions, in this order:</p>
        <ul>
          <li><strong>What&rsquo;s happening today?</strong> — Today&rsquo;s appointments, who&rsquo;s coming in, in what order.</li>
          <li><strong>What needs my attention?</strong> — Pending confirmations, failed payments, unread messages.</li>
          <li><strong>Am I growing?</strong> — Recent trends in bookings and revenue.</li>
        </ul>
        <p>Every number on the page is clickable and drills into the underlying list. If you see &ldquo;3 new customers this week,&rdquo; clicking it takes you to those 3 customers, not a generic list.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Quick-look drawer</div>
      <div class="help-text">
        <p>Click any appointment row in the &ldquo;Today&rdquo; section and a drawer slides in from the right with the key details — customer info, services booked, total, work-order fields. The drawer is read-mostly; for full edits, click <strong>Open full view</strong> at the bottom.</p>
        <p>Press <code>Esc</code> or click outside the drawer to close it.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- SCHEDULE --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="schedule">
    <div class="help-section-head">
      <span class="help-section-icon">📅</span>
      <span class="help-section-title">Schedule</span>
      <span class="help-section-sub">your calendar</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Schedule page is the calendar where you live. It shows two views depending on your booking mode:</p>
        <ul>
          <li><strong>Drop-off mode</strong> — per-resource swimlanes. No time axis, just stacks of appointments per resource per day. Day view and week view both available.</li>
          <li><strong>Time-slot mode</strong> — traditional time-axis calendar with appointments as blocks. Day, week, and month views.</li>
        </ul>
        <p>You can switch booking modes anytime from <strong>Capacity</strong>, but it&rsquo;s usually a one-time decision based on how your shop runs.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">1</span> Drag to reschedule</div>
      <div class="help-text">
        <p>Grab any appointment card and drag it to a different day or resource column. The system checks for conflicts in real time:</p>
        <ul>
          <li>Drop in an open slot &rarr; appointment moves immediately</li>
          <li>Drop in a busy slot &rarr; you&rsquo;ll see a confirm dialog showing the conflict, with an option to override</li>
          <li>Drop on an inactive resource &rarr; not allowed, the card snaps back</li>
        </ul>
        <p>Every move writes an audit note to the appointment so you have a paper trail. The customer is not automatically notified — that&rsquo;s on you to decide.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">2</span> Click to peek</div>
      <div class="help-text">
        <p>A single click on any appointment card opens the same right-side drawer used on the Dashboard. Quick way to glance at customer info, total, status, and work-order details without leaving the calendar.</p>
        <div class="help-tip">
          <div class="help-tip-label">Tip</div>
          The system distinguishes drag from click automatically — a drag never opens the drawer, and a click never moves the appointment. You don&rsquo;t have to think about it.
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">3</span> Status colors</div>
      <div class="help-text">
        <p>Cards are color-coded by status:</p>
        <ul>
          <li><strong>Pending</strong> &mdash; amber, dashed border. Customer booked but you haven&rsquo;t confirmed.</li>
          <li><strong>Confirmed</strong> &mdash; lime, solid border. Ready to go.</li>
          <li><strong>In progress</strong> &mdash; blue accent. Work has started.</li>
          <li><strong>Completed</strong> &mdash; muted gray with a check. Job done.</li>
          <li><strong>Cancelled / refunded</strong> &mdash; hidden by default. Toggle &ldquo;Show cancelled&rdquo; to see them.</li>
        </ul>
        <p>Resource colors (set on the Resources page) appear as a left border and tinted background, so you can scan a busy day and see at a glance who&rsquo;s doing what.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- CUSTOMERS --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="customers">
    <div class="help-section-head">
      <span class="help-section-icon">👥</span>
      <span class="help-section-title">Customers</span>
      <span class="help-section-sub">your CRM</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Every booking automatically creates or matches a customer profile. There&rsquo;s no separate &ldquo;add customer&rdquo; step — they show up as soon as someone books for the first time.</p>
        <p>Each customer has:</p>
        <ul>
          <li>Full booking history with statuses and totals</li>
          <li>Lifetime spend and visit count</li>
          <li>Up to 200 characters of internal notes (never visible to the customer)</li>
          <li>Search by name, email, phone, or any partial match</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Booking on a customer&rsquo;s behalf</div>
      <div class="help-text">
        <p>From any customer&rsquo;s detail page, click <strong>+ New appointment</strong> to start a booking with that customer pre-filled. You&rsquo;ll be dropped into the calendar with the QuickBook modal already open and their info attached. Saves a lot of typing on phone bookings.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- SERVICES --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="services">
    <div class="help-section-head">
      <span class="help-section-icon">🛠</span>
      <span class="help-section-title">Services</span>
      <span class="help-section-sub">what customers can book</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Services page is where you define everything customers can book. Each service has a name, description, price, duration, and an optional list of add-ons. Services are grouped into <strong>categories</strong> for organization on the booking form.</p>
        <p>Two view modes:</p>
        <ul>
          <li><strong>List view</strong> — expandable rows with a drawer for editing. Best for working through one service at a time.</li>
          <li><strong>Table view</strong> — dense grid showing all services and their key fields side-by-side. Best for bulk reviews.</li>
        </ul>
        <p>Most fields are inline-editable — click any cell, type, hit Enter or Tab. The pulse animation tells you the save landed.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">1</span> Service vs. add-on</div>
      <div class="help-text">
        <p>The distinction matters for how customers see things on the booking form:</p>
        <ul>
          <li>A <strong>service</strong> is the main thing the customer is booking — &ldquo;Standard Tune-Up,&rdquo; &ldquo;Massage 60-min,&rdquo; &ldquo;Brake Pad Replacement.&rdquo; They pick one.</li>
          <li>An <strong>add-on</strong> is an extra they can tack on — &ldquo;Extra wax,&rdquo; &ldquo;Hot stones,&rdquo; &ldquo;Brake bleed.&rdquo; They&rsquo;re shown after the service is selected.</li>
        </ul>
        <p>Add-ons live in a shared library (the &ldquo;Add-ons library&rdquo; tab at the top of Services). Once an add-on exists, you can attach it to any number of services and override the price or duration on a per-service basis.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">2</span> Time math: prep, duration, cleanup</div>
      <div class="help-text">
        <p>Each service has three time fields:</p>
        <ul>
          <li><strong>Prep before (min)</strong> — buffer before the customer-facing time. Not shown to customer.</li>
          <li><strong>Service duration (min)</strong> — the customer-facing time. This is what they see when picking a slot.</li>
          <li><strong>Cleanup after (min)</strong> — buffer after. Also not shown.</li>
        </ul>
        <p>The wall-clock time the calendar blocks for a job is <strong>prep + duration + cleanup + sum of all add-on durations</strong>. The customer-facing time is <strong>duration + sum of add-on durations</strong>. The drawer shows you both numbers as you edit.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">3</span> Available with (resource eligibility)</div>
      <div class="help-text">
        <p>If you have multiple staff/stations and certain services can only be performed by certain people, use the <strong>Available with</strong> chips in the service drawer.</p>
        <p>Three states:</p>
        <ul>
          <li><strong>All chips blue (default)</strong> — anyone can perform this service. No restriction.</li>
          <li><strong>Some chips lime, others struck through</strong> — only the selected resources can perform it. The booking calendar will reflect this — days with no eligible resources free will be hidden from customers.</li>
          <li><strong>Click a struck-through chip</strong> to add it back. <strong>Click a selected chip</strong> to remove it. Deselect everything to return to the all-eligible default.</li>
        </ul>
        <p>The section only appears if your shop has 2 or more resources. Single-resource shops have no eligibility decision to make.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Slot weight (advanced)</div>
      <div class="help-text">
        <p>Most jobs take one slot of capacity. Some — a full bike build, a long-form bodywork session — realistically eat 2 or 3 slots&rsquo; worth of bench time. Set the <strong>slot weight</strong> in the drawer (1, 2, 3, or 4) and that service will count for more than one booking against the day&rsquo;s capacity.</p>
        <p>You can also override slot weight on individual appointments from the appointment detail page. Useful for one-off jobs that turned out to be bigger than expected.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- RESOURCES --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="resources">
    <div class="help-section-head">
      <span class="help-section-icon">👨‍🔧</span>
      <span class="help-section-title">Resources</span>
      <span class="help-section-sub">staff, stations, or anything that limits capacity</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>A <strong>resource</strong> is anything that limits how many appointments can happen at once. For most shops that&rsquo;s staff (mechanics, stylists, instructors). For some it&rsquo;s stations (lifts, chairs, treatment rooms). Either works — Intake doesn&rsquo;t care.</p>
        <p>Each resource has:</p>
        <ul>
          <li>A <strong>name</strong> and optional <strong>subtitle</strong> (e.g. &ldquo;Mike&rdquo; / &ldquo;Suspension specialist&rdquo;)</li>
          <li>A <strong>color</strong> for visual identification on the calendar</li>
          <li>An optional <strong>per-day cap</strong> — the most appointments this resource can handle in one day</li>
          <li>An <strong>active toggle</strong> — flip off to retire a resource without deleting their history</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Per-day caps and how they add up</div>
      <div class="help-text">
        <p>Each resource&rsquo;s per-day cap stacks into a shop-wide total. If you have three mechanics with caps of 5/4/6, your total daily capacity is 15.</p>
        <p>This sum is what the <strong>Daily cap</strong> on the Capacity page falls back to when you leave it blank. So most shops can leave the shop-wide cap empty and just manage capacity per resource — the math works out.</p>
        <div class="help-tip">
          <div class="help-tip-label">Tip</div>
          Leave the resource cap blank to mean &ldquo;no per-resource limit&rdquo; — that resource won&rsquo;t contribute to the sum, and capacity for them is bounded only by the time-slot grid (in time-slot mode) or the shop-wide override (in drop-off mode).
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Drag to reorder</div>
      <div class="help-text">
        <p>Resources appear in calendar columns in the order shown on this page. Grab the drag handle (⋮⋮) on any row to reorder them. The change saves automatically.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- WORK ORDER FIELDS --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="work-order-fields">
    <div class="help-section-head">
      <span class="help-section-icon">🔧</span>
      <span class="help-section-title">Work Order Fields</span>
      <span class="help-section-sub">custom fields per appointment</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Work Order Fields are custom fields you define once and apply to every appointment. They&rsquo;re what makes Intake adapt to your industry — a bike shop tracks <strong>Bike brand</strong>, <strong>Model</strong>, <strong>Serial number</strong>; a tailor tracks <strong>Garment type</strong>, <strong>Fabric</strong>; a tattoo studio tracks <strong>Placement</strong>, <strong>Stencil ready</strong>.</p>
        <p>Each field has:</p>
        <ul>
          <li>A <strong>label</strong> (what the customer sees)</li>
          <li>A <strong>type</strong> (text, dropdown, number, etc.)</li>
          <li>A <strong>required toggle</strong></li>
          <li>An optional <strong>identifier flag</strong> — the most important field (usually a unique tag like a bike serial or claim number) that gets promoted to the appointment&rsquo;s top-level identifier</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">The identifier field</div>
      <div class="help-text">
        <p>One field can be marked as the <strong>identifier</strong>. Whatever the customer enters there gets shown prominently on the appointment card, the dashboard, the customer&rsquo;s history, and email confirmations. It&rsquo;s the &ldquo;label&rdquo; for the appointment in your day-to-day.</p>
        <p>Examples:</p>
        <ul>
          <li>Bike shop: identifier = bike model + color (&ldquo;Specialized Stumpjumper, red&rdquo;)</li>
          <li>Tattoo studio: identifier = appointment topic (&ldquo;Forearm dragon&rdquo;)</li>
          <li>Tailor: identifier = garment (&ldquo;Charcoal suit&rdquo;)</li>
        </ul>
        <div class="help-tip">
          <div class="help-tip-label">Why it matters</div>
          When you&rsquo;re looking at a list of 12 appointments today, &ldquo;Specialized Stumpjumper&rdquo; tells you what it is at a glance. &ldquo;Standard Tune-Up&rdquo; doesn&rsquo;t.
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Editing fields after appointments exist</div>
      <div class="help-text">
        <p>Fields are <strong>snapshotted</strong> onto each appointment when it&rsquo;s created. That means if you rename a field later, old appointments keep showing the old label. Same for dropdown options — removing an option doesn&rsquo;t break appointments that were already filled out with it.</p>
        <p>This is intentional. It means you can evolve your fields without rewriting history.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- INTAKE FORM EDITOR --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="intake-form-editor">
    <div class="help-section-head">
      <span class="help-section-icon">📝</span>
      <span class="help-section-title">Intake Form Editor</span>
      <span class="help-section-sub">customize the customer-facing booking form</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Intake Form Editor is where you customize the booking form your customers see. Out of the box it works fine — name, email, phone, and your custom Work Order Fields. The editor lets you tweak labels, change order, add help text, and reorder steps.</p>
        <p>The form has four steps in this order: <strong>Services &rarr; Schedule &rarr; Details &rarr; Review</strong>. Step labels are editable; step order isn&rsquo;t.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Drop-off methods (drop-off mode only)</div>
      <div class="help-text">
        <p>If your shop is in drop-off mode, customers see a &ldquo;How are you dropping off?&rdquo; question on the Schedule step. The options come from <strong>Settings &rarr; Booking &rarr; Drop-off Methods</strong>. Each method can also toggle whether the form asks for a specific time and a tracking number.</p>
        <p>For example, if you accept both walk-ins and mail-in jobs, you might have:</p>
        <ul>
          <li><strong>In person</strong> — asks for time, doesn&rsquo;t ask for tracking</li>
          <li><strong>Shipping</strong> — doesn&rsquo;t ask for time, asks for tracking</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Preview as customer</div>
      <div class="help-text">
        <p>Click <strong>Preview as customer</strong> from the editor to see exactly what your booking form looks like to a real customer. The preview honors your branding, drop-off methods, services, and form fields. Use it whenever you&rsquo;re changing copy or adjusting fields — it&rsquo;s the fastest sanity check.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- CAPACITY --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="capacity">
    <div class="help-section-head">
      <span class="help-section-icon">🕐</span>
      <span class="help-section-title">Capacity</span>
      <span class="help-section-sub">when you&rsquo;re open and how much you can take on</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Capacity page is where you tell Intake when you&rsquo;re open and how much work you can take on. Two sections share the page: <strong>Weekly defaults</strong> for your normal schedule, and <strong>Date overrides</strong> for one-off changes like holidays or vacations.</p>
        <p>Customers only ever see dates that have remaining capacity. Closed days, full days, and dates outside your booking window stay hidden on the public booking calendar.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">1</span> Weekly defaults</div>
      <div class="help-text">
        <p>One row per day of the week, Sunday through Saturday. For each day you set:</p>
        <ul>
          <li><strong>Closed</strong> — toggle if you&rsquo;re never open that day. The other fields hide automatically.</li>
          <li><strong>Open hours</strong> — when bookings can start and end on that day.</li>
          <li><strong>Daily cap</strong> — the maximum number of bookings you&rsquo;ll accept on that day. Leave blank to use the sum of your individual resource caps from the <strong>Resources</strong> page (e.g. three staff with 5 bookings each = 15/day total).</li>
        </ul>
        <p>Click <strong>Show advanced</strong> to expose the <strong>Slot interval</strong> field, which controls how long each bookable time slot is in time-slot mode. Most shops never need to touch this.</p>
        <div class="help-tip">
          <div class="help-tip-label">Tip</div>
          The × button inside the Daily cap field clears it back to &ldquo;no limit.&rdquo; The cap then falls back to whatever your resources&rsquo; per-day caps add up to.
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">2</span> Date overrides</div>
      <div class="help-text">
        <p>Overrides change capacity for specific dates without touching your weekly defaults. Use them for:</p>
        <ul>
          <li><strong>Holidays</strong> &mdash; closed for Christmas, Thanksgiving, etc.</li>
          <li><strong>Vacations</strong> &mdash; closed for a week or two while you&rsquo;re away.</li>
          <li><strong>Half-days</strong> &mdash; reduced capacity for early closures, training days, or special events.</li>
          <li><strong>Busy days</strong> &mdash; lower the cap when you know a day will be unusually demanding (one big build, off-site service, etc.).</li>
        </ul>
        <p>Click <strong>+ Add override</strong> and the date picker opens. You can select <strong>any number of dates</strong> in one go — perfect for a multi-day vacation.</p>
        <div class="help-flow">
          <span class="help-flow-step">+ Add override</span>
          <span class="help-flow-arrow">&rarr;</span>
          <span class="help-flow-step">Pick dates on calendar</span>
          <span class="help-flow-arrow">&rarr;</span>
          <span class="help-flow-step">Closed or capped?</span>
          <span class="help-flow-arrow">&rarr;</span>
          <span class="help-flow-step">Save</span>
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Closed vs. capped — which to pick</div>
      <div class="help-text">
        <p><strong>Closed on these dates</strong> means the shop is shut. No bookings, full stop. Use this for vacations and holidays.</p>
        <p>Leaving the box <em>unchecked</em> and entering a <strong>Daily cap</strong> means the shop is still open, just with reduced capacity. Useful for shorter-than-normal days or when you want to limit yourself to a couple of bookings on a planning day.</p>
        <div class="help-tip">
          <div class="help-tip-label">Why this matters</div>
          When you mark dates as closed, the daily cap is irrelevant — Intake hides the field entirely so you don&rsquo;t have to think about it. If you change your mind and uncheck the box, the cap field comes back.
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Updating an existing override</div>
      <div class="help-text">
        <p>Already-overridden dates show in <strong>amber</strong> on the date picker. Clicking one selects it again — when you save, the existing override is updated with your new settings (closed/cap/note), no duplicate created.</p>
        <p>To remove an override entirely, find it in the list under the calendar and click the <strong>×</strong> on its row. The date returns to whatever your weekly default says for that day of the week.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- PAGES --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="pages">
    <div class="help-section-head">
      <span class="help-section-icon">🌐</span>
      <span class="help-section-title">Pages</span>
      <span class="help-section-sub">your public website</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Pages section is where you build and manage your shop&rsquo;s public website. Out of the box you get a home page with a hero, an about section, services display, and contact info. Edit any of it inline, add new pages, rearrange sections.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Block builder</div>
      <div class="help-text">
        <p>Pages are made of <strong>blocks</strong> — modular sections you stack to build a page. Block types include:</p>
        <ul>
          <li><strong>Hero</strong> — large headline + image + CTA</li>
          <li><strong>Paragraph</strong> — rich-text content with formatting toolbar</li>
          <li><strong>Image</strong> — single image with optional caption</li>
          <li><strong>Services list</strong> — auto-pulls from your Services page</li>
          <li><strong>Gallery</strong> — multi-image grid</li>
          <li><strong>Contact form</strong> — captures inquiries that land in your inbox</li>
        </ul>
        <p>Drag blocks to reorder. Click any block to edit its settings in the right panel. The preview on the left updates live.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Image library + storage quotas</div>
      <div class="help-text">
        <p>All images you upload across your shop live in one shared library. Per-tier storage quotas:</p>
        <ul>
          <li><strong>Starter</strong> &mdash; 100 MB total, max 5 MB per file</li>
          <li><strong>Branded</strong> &mdash; 500 MB total, max 5 MB per file</li>
          <li><strong>Scale</strong> &mdash; 2 GB total, max 5 MB per file</li>
        </ul>
        <p>Files larger than 5 MB get rejected at upload — compress them first or upgrade tier.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- EMAIL --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="email">
    <div class="help-section-head">
      <span class="help-section-icon">✉️</span>
      <span class="help-section-title">Email</span>
      <span class="help-section-sub">transactional templates</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Email page is where you customize the messages your shop sends automatically. Templates include:</p>
        <ul>
          <li><strong>Booking confirmation</strong> — sent when a customer books</li>
          <li><strong>Booking reminder</strong> — sent 24 hours before the appointment</li>
          <li><strong>Status changes</strong> — sent when an appointment moves to in-progress, completed, etc.</li>
          <li><strong>Cancellation confirmation</strong> — sent when a customer cancels via the manage link</li>
          <li><strong>Waitlist notifications</strong> — sent when a slot opens up</li>
        </ul>
        <p>Each template has a default that works fine — you don&rsquo;t have to edit anything. But if you want your voice to come through, every template is fully editable with the same block builder used on Pages.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Tokens</div>
      <div class="help-text">
        <p>Templates use <strong>tokens</strong> — placeholders that get replaced with real data when the email actually goes out. Common tokens:</p>
        <ul>
          <li><code>{{customer_first_name}}</code> &mdash; the customer&rsquo;s first name</li>
          <li><code>{{appointment_date}}</code> &mdash; formatted date of the appointment</li>
          <li><code>{{appointment_time}}</code> &mdash; formatted time</li>
          <li><code>{{ra_number}}</code> &mdash; the RA / job number</li>
          <li><code>{{shop_name}}</code> &mdash; your shop name</li>
        </ul>
        <p>The full list is shown in a sidebar inside the editor. Click any token to insert it at your cursor.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- WAITLIST --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="waitlist">
    <div class="help-section-head">
      <span class="help-section-icon">⏳</span>
      <span class="help-section-title">Waitlist</span>
      <span class="help-section-sub">capture demand on full days</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>When a day is full, customers see a &ldquo;Join the waitlist&rdquo; option on your booking calendar. They give their preferred date, contact info, and what they want. If a slot opens up — someone cancels, you add capacity — the system can text them automatically.</p>
        <p>The Waitlist page shows everyone currently waiting, sorted by when they joined. From here you can:</p>
        <ul>
          <li><strong>See the queue</strong> at a glance, with their requested date and service</li>
          <li><strong>Convert one</strong> directly to a booking with one click</li>
          <li><strong>Send manual notifications</strong> if the auto-notify didn&rsquo;t fit a particular case</li>
          <li><strong>Remove</strong> entries that are stale or no longer needed</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Auto-notify on cancellation</div>
      <div class="help-text">
        <p>When an appointment is cancelled, the system automatically checks the waitlist for anyone wanting that date and that type of service. If there&rsquo;s a match, an SMS goes out offering them the slot.</p>
        <p>The first person to claim it gets it. Others get a polite &ldquo;sorry, taken&rdquo; message.</p>
        <div class="help-tip">
          <div class="help-tip-label">Off by default</div>
          Auto-notify SMS is opt-in — you turn it on per-tenant from <strong>Settings &rarr; Booking</strong>. Most shops want it on, but it&rsquo;s your choice.
        </div>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- CAMPAIGNS --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="campaigns">
    <div class="help-section-head">
      <span class="help-section-icon">📣</span>
      <span class="help-section-title">Campaigns</span>
      <span class="help-section-sub">email and SMS to your customers</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Campaigns are bulk emails or texts you send to segments of your customer list. Different from transactional Email — those are automatic, one-per-event. Campaigns are deliberate, &ldquo;I&rsquo;m running a promo this month&rdquo; sends.</p>
        <p>Common use cases:</p>
        <ul>
          <li>Tune-up reminders to anyone who hasn&rsquo;t been in for 6+ months</li>
          <li>Holiday hours announcements</li>
          <li>New service launches</li>
          <li>End-of-season promos</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">1</span> Build the audience</div>
      <div class="help-text">
        <p>Pick who gets the message using filters:</p>
        <ul>
          <li><strong>Last visit before / after</strong> — re-engage lapsed customers, or thank recent ones</li>
          <li><strong>Service category</strong> — only customers who&rsquo;ve booked a particular service type</li>
          <li><strong>Total spend above / below</strong> — VIP segment, or first-time customers</li>
        </ul>
        <p>The audience preview shows you exactly how many customers match before you send.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">2</span> Compose</div>
      <div class="help-text">
        <p>Same block builder as Email and Pages. Subject line, preview text, body. Tokens work here too — <code>{{customer_first_name}}</code> personalizes each send.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">3</span> Send</div>
      <div class="help-text">
        <p>Schedule for now or pick a future date/time. The send-queue shows progress in real time. Sends are throttled so you don&rsquo;t trip your provider&rsquo;s rate limits.</p>
        <div class="help-warning">
          <div class="help-warning-label">Don&rsquo;t spam</div>
          Aggressive marketing kills opt-in lists fast. Most shops should send no more than 1 campaign per month. Quality over frequency.
        </div>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- WHAT'S NEW --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="whats-new">
    <div class="help-section-head">
      <span class="help-section-icon">✨</span>
      <span class="help-section-title">What&rsquo;s New</span>
      <span class="help-section-sub">recent shipped features</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>What&rsquo;s New is the changelog — a running list of what we&rsquo;ve shipped recently. Bug fixes, new features, design polish. Worth a glance every couple of weeks so you know what&rsquo;s changed.</p>
        <p>Each entry has a date, a short title, and a brief description. If something affects how you use Intake day-to-day, you&rsquo;ll see it here first.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- WHAT'S COMING --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="whats-coming">
    <div class="help-section-head">
      <span class="help-section-icon">🗺️</span>
      <span class="help-section-title">What&rsquo;s Coming</span>
      <span class="help-section-sub">public roadmap</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>What&rsquo;s Coming shows what&rsquo;s in active development and what&rsquo;s being planned. It&rsquo;s the public roadmap — no surprises, no &ldquo;coming soon&rdquo; mystery.</p>
        <p>Items are loosely grouped by status: <strong>In progress</strong>, <strong>Up next</strong>, <strong>Considering</strong>. The timeline is fluid — priorities shift based on what shops actually ask for.</p>
        <div class="help-tip">
          <div class="help-tip-label">Have a request?</div>
          Email feedback@intake.works any time. Real customer feedback drives the roadmap more than anything else.
        </div>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- BRANDING --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="branding">
    <div class="help-section-head">
      <span class="help-section-icon">🎨</span>
      <span class="help-section-title">Branding</span>
      <span class="help-section-sub">make it yours</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The Branding page is where you make Intake look like your shop. Everything you set here flows through to your booking form, public website, and customer emails.</p>
        <ul>
          <li><strong>Logo</strong> — upload light and dark variants (we pick the right one based on background)</li>
          <li><strong>Accent color</strong> — the highlight color used on buttons, links, and selected states</li>
          <li><strong>Background color</strong> — the base color of your customer-facing pages</li>
          <li><strong>Fonts</strong> — pick from a curated list of web fonts for headings and body text</li>
          <li><strong>Favicon</strong> — the tiny icon shown in browser tabs</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Light vs. dark</div>
      <div class="help-text">
        <p>Customers see your shop in either a light or dark theme depending on what you pick. The accent color and logo work for both — Intake does the contrast math for you.</p>
        <p>Inside the admin (the screen you&rsquo;re looking at right now), you can use either theme too. That&rsquo;s a separate per-user preference under your account, not a tenant-wide setting.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- SETTINGS --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="settings">
    <div class="help-section-head">
      <span class="help-section-icon">⚙️</span>
      <span class="help-section-title">Settings</span>
      <span class="help-section-sub">general, booking, payments, billing, domain</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Settings has five tabs across the top:</p>
        <ul>
          <li><strong>General</strong> — shop name, contact info, time zone, currency</li>
          <li><strong>Booking</strong> — booking mode, drop-off methods, advance booking window, minimum notice</li>
          <li><strong>Payments</strong> — Stripe and PayPal toggles for accepting customer payments</li>
          <li><strong>Billing</strong> — your subscription plan, payment method, and Stripe billing portal access</li>
          <li><strong>Domain</strong> — custom domain setup (Branded and Scale tiers)</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Booking mode</div>
      <div class="help-text">
        <p>Two modes:</p>
        <ul>
          <li><strong>Drop-off</strong> — customers book a day, not a time. The shop processes jobs in whatever order makes sense. Bike shops, tailors, and repair-style businesses usually want this.</li>
          <li><strong>Time-slots</strong> — customers book a specific time. Salons, massage therapists, and any appointment-based business usually want this.</li>
        </ul>
        <p>You can switch modes, but it&rsquo;s a meaningful change — your existing appointments may need their times adjusted, and the calendar UI looks different. The switch wizard walks you through it.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Drop-off methods</div>
      <div class="help-text">
        <p>If you&rsquo;re in drop-off mode, this is where you define how customers can drop off — &ldquo;In person,&rdquo; &ldquo;Mail-in,&rdquo; &ldquo;After hours drop-box,&rdquo; whatever fits your shop.</p>
        <p>Each method has two toggles:</p>
        <ul>
          <li><strong>Time</strong> — show a time field on the booking form when this method is selected. Useful for &ldquo;In person&rdquo; (you want to know when they&rsquo;re coming) but pointless for &ldquo;Mail-in.&rdquo;</li>
          <li><strong>Tracking</strong> — show a tracking number field. Useful for &ldquo;Mail-in&rdquo; but pointless for &ldquo;In person.&rdquo;</li>
        </ul>
        <p>Use the toggle on the right of each row to activate or deactivate a method. Inactive methods are kept (so past bookings keep their snapshot) but hidden from new customers.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Payments</div>
      <div class="help-text">
        <p>Toggle Stripe and PayPal independently. Customers will see whichever you have on at checkout. You can have both, one, or neither (cash on arrival only).</p>
        <p>Connecting Stripe is a one-time OAuth step — you&rsquo;ll be redirected to Stripe to authorize, then back. PayPal is similar.</p>
        <div class="help-warning">
          <div class="help-warning-label">Heads up</div>
          Customer payments to your Stripe/PayPal accounts are separate from your <em>Intake subscription</em> billing. Subscription billing is on the Billing tab. Customer payments flow to whatever Stripe/PayPal account you connect here.
        </div>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Billing</div>
      <div class="help-text">
        <p>Your Intake subscription. Shows your current plan, next renewal date, and payment method. <strong>Manage in Stripe</strong> opens the Stripe billing portal where you can update card, change plan, view invoices, or cancel.</p>
        <p>Plan changes (upgrades and downgrades) take effect immediately, with prorated billing handled by Stripe.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Domain</div>
      <div class="help-text">
        <p>By default your shop lives at <code>your-shop.intake.works</code>. On Branded and Scale tiers you can connect a custom domain like <code>book.your-shop.com</code>.</p>
        <p>Setup is a CNAME record at your DNS provider — the page walks you through the exact value to use. Once verified, your booking form, public site, and customer emails all use the custom domain.</p>
      </div>
    </div>
  </div>

  {{-- ============================================================ --}}
  {{-- ADD-ONS --}}
  {{-- ============================================================ --}}
  <div class="help-section" id="addons">
    <div class="help-section-head">
      <span class="help-section-icon">🧩</span>
      <span class="help-section-title">Add-ons</span>
      <span class="help-section-sub">optional features</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Add-ons are optional features you can layer onto any plan. Different from service add-ons (which are paid extras for customer bookings) — these are <strong>tenant-level</strong> features that change what your Intake account can do.</p>
        <p>Examples of feature add-ons:</p>
        <ul>
          <li><strong>SMS</strong> — Twilio integration for appointment reminders, waitlist alerts, and campaign sends. Pass-through pricing.</li>
          <li><strong>Offline sync</strong> — keeps the admin working when your shop&rsquo;s internet drops, syncs on reconnect.</li>
          <li><strong>Class bookings</strong> (coming) — yoga, fitness, group session support.</li>
        </ul>
        <p>Toggle any add-on to enable it. Billing changes take effect on your next renewal.</p>
      </div>
    </div>
  </div>

  {{-- Back to top --}}
  <div class="help-back-top">
    <a href="#dashboard">↑ Back to top</a>
  </div>

</div>
@endsection
