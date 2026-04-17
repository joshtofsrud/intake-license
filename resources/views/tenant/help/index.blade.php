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
  margin-bottom: 32px;
  text-align: center;
}
.help-hero-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
.help-hero-sub { font-size: 15px; opacity: .6; max-width: 500px; margin: 0 auto; }
.help-toc {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 12px;
  margin-bottom: 40px;
}
.help-toc-card {
  padding: 20px;
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  cursor: pointer;
  transition: border-color .12s, transform .12s;
  text-decoration: none;
  color: inherit;
}
.help-toc-card:hover { border-color: var(--ia-accent); transform: translateY(-2px); }
.help-toc-icon { font-size: 28px; margin-bottom: 10px; }
.help-toc-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
.help-toc-desc { font-size: 12px; opacity: .5; }
.help-section {
  margin-bottom: 48px;
  scroll-margin-top: 20px;
}
.help-section-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  padding-bottom: 12px;
  border-bottom: 0.5px solid var(--ia-border);
}
.help-section-icon { font-size: 28px; }
.help-section-title { font-size: 20px; font-weight: 700; }
.help-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 24px;
  margin-bottom: 16px;
}
.help-card-title { font-size: 15px; font-weight: 600; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.help-card-num {
  background: var(--ia-accent);
  color: var(--ia-accent-text);
  width: 24px; height: 24px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; flex-shrink: 0;
}
.help-text { font-size: 14px; line-height: 1.7; opacity: .75; }
.help-text p { margin-bottom: 10px; }
.help-text ul { padding-left: 20px; margin-bottom: 10px; }
.help-text li { margin-bottom: 6px; }
.help-tip {
  background: var(--ia-accent-soft);
  border-left: 3px solid var(--ia-accent);
  padding: 14px 18px;
  border-radius: 0 var(--ia-r-md) var(--ia-r-md) 0;
  font-size: 13px;
  margin: 16px 0;
}
.help-tip-label { font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; opacity: .7; }
.help-shortcut {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  background: rgba(255,255,255,.06);
  border: 0.5px solid var(--ia-border);
  border-radius: 6px;
  font-size: 12px;
  font-family: 'JetBrains Mono', monospace;
}
.help-flow {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin: 12px 0;
}
.help-flow-step {
  background: rgba(255,255,255,.06);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 8px 14px;
  font-size: 12px;
  font-weight: 500;
}
.help-flow-arrow { opacity: .3; font-size: 14px; }
.help-badge-grid {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin: 10px 0;
}
.help-back-top {
  text-align: center;
  padding: 20px;
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
    <div class="help-hero-title">Help & Guides</div>
    <div class="help-hero-sub">Everything you need to know to run your shop on Intake. Click a topic below to jump to it.</div>
  </div>

  {{-- Table of contents --}}
  <div class="help-toc">
    <a href="#getting-started" class="help-toc-card">
      <div class="help-toc-icon">🚀</div>
      <div class="help-toc-title">Getting Started</div>
      <div class="help-toc-desc">Set up your shop in 5 minutes</div>
    </a>
    <a href="#dashboard" class="help-toc-card">
      <div class="help-toc-icon">📊</div>
      <div class="help-toc-title">Dashboard</div>
      <div class="help-toc-desc">Stats, overview, quick actions</div>
    </a>
    <a href="#appointments" class="help-toc-card">
      <div class="help-toc-icon">📅</div>
      <div class="help-toc-title">Appointments</div>
      <div class="help-toc-desc">Create, manage, track work orders</div>
    </a>
    <a href="#customers" class="help-toc-card">
      <div class="help-toc-icon">👥</div>
      <div class="help-toc-title">Customers</div>
      <div class="help-toc-desc">Customer profiles and history</div>
    </a>
    <a href="#services" class="help-toc-card">
      <div class="help-toc-icon">🛠</div>
      <div class="help-toc-title">Services & Pricing</div>
      <div class="help-toc-desc">Categories, tiers, and add-ons</div>
    </a>
    <a href="#booking-form" class="help-toc-card">
      <div class="help-toc-icon">📝</div>
      <div class="help-toc-title">Booking Form</div>
      <div class="help-toc-desc">Customize your online intake form</div>
    </a>
    <a href="#pages" class="help-toc-card">
      <div class="help-toc-icon">🌐</div>
      <div class="help-toc-title">Page Builder</div>
      <div class="help-toc-desc">Build your public website</div>
    </a>
    <a href="#branding" class="help-toc-card">
      <div class="help-toc-icon">🎨</div>
      <div class="help-toc-title">Branding</div>
      <div class="help-toc-desc">Logo, colors, fonts, identity</div>
    </a>
    <a href="#capacity" class="help-toc-card">
      <div class="help-toc-icon">🕐</div>
      <div class="help-toc-title">Capacity & Hours</div>
      <div class="help-toc-desc">Availability and scheduling rules</div>
    </a>
    <a href="#team" class="help-toc-card">
      <div class="help-toc-icon">🤝</div>
      <div class="help-toc-title">Team</div>
      <div class="help-toc-desc">Add staff, manage roles</div>
    </a>
  </div>

  {{-- ================================================================ --}}
  {{-- GETTING STARTED --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="getting-started">
    <div class="help-section-head">
      <span class="help-section-icon">🚀</span>
      <span class="help-section-title">Getting Started</span>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">1</span> Add your branding</div>
      <div class="help-text">
        <p>Head to <strong>Branding</strong> in the sidebar. Upload your logo, pick your accent color, and set your shop name and tagline. These appear on your public site and booking form.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">2</span> Set up your services</div>
      <div class="help-text">
        <p>Go to <strong>Services</strong> and create your service catalog. Start by adding categories (e.g., "Repairs", "Maintenance"), then add items within each category. Set pricing for each service tier.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">3</span> Configure your hours</div>
      <div class="help-text">
        <p>Visit <strong>Capacity</strong> to set your weekly schedule. Define which days you're open, your hours, and how many jobs you can take per day. This controls what dates customers can book.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">4</span> Customize your website</div>
      <div class="help-text">
        <p>Use the <strong>Pages</strong> section to edit your home page. The live preview editor lets you see changes in real-time. Add a hero image, update your headline, and publish when ready.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title"><span class="help-card-num">5</span> Invite your team</div>
      <div class="help-text">
        <p>Go to <strong>Team</strong> to add staff members. They'll get their own login to manage appointments. Assign roles: <strong>Owner</strong> (full access), <strong>Manager</strong> (most access), or <strong>Staff</strong> (appointments and customers only).</p>
      </div>
    </div>

    <div class="help-tip">
      <div class="help-tip-label">💡 Tip</div>
      Your dashboard shows a setup checklist that tracks your progress. Complete all five steps to get the most out of Intake.
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- DASHBOARD --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="dashboard">
    <div class="help-section-head">
      <span class="help-section-icon">📊</span>
      <span class="help-section-title">Dashboard</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Your dashboard gives you a real-time overview of your shop. At a glance you can see:</p>
        <ul>
          <li><strong>Today's jobs</strong> — how many appointments are scheduled today and how many are still open</li>
          <li><strong>This week</strong> — total appointments this week vs last week</li>
          <li><strong>Revenue (MTD)</strong> — month-to-date revenue from paid appointments</li>
          <li><strong>Open jobs</strong> — all active work orders that haven't been completed or closed</li>
        </ul>
        <p>Below the stats you'll see your <strong>recent appointments</strong> — click any row to open the detail modal where you can update status, add notes, and manage charges.</p>
      </div>
    </div>

    <div class="help-tip">
      <div class="help-tip-label">💡 Tip</div>
      Use the "+ New appointment" button in the top right to quickly create a walk-in or phone booking without going through the online form.
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- APPOINTMENTS --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="appointments">
    <div class="help-section-head">
      <span class="help-section-icon">📅</span>
      <span class="help-section-title">Appointments</span>
    </div>

    <div class="help-card">
      <div class="help-card-title">Creating an appointment</div>
      <div class="help-text">
        <p>Click <strong>"+ New appointment"</strong> from the appointments page or dashboard. Fill in the customer's name, email, appointment date, and any staff notes. An ITO number is generated automatically.</p>
        <p>If the customer already exists in your system, their record will be linked. If not, a new customer profile is created automatically.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Status workflow</div>
      <div class="help-text">
        <p>Every appointment moves through a status workflow. Click on any appointment to open the detail modal, where you'll see the available status transitions:</p>
      </div>
      <div class="help-flow">
        <div class="help-flow-step">Pending</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">Confirmed</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">In Progress</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">Completed</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">Closed</div>
      </div>
      <div class="help-text">
        <p>You can also mark appointments as <strong>Shipped</strong> (if you ship items back), <strong>Cancelled</strong>, or <strong>Refunded</strong>. Cancellation and refund are destructive actions and will ask for confirmation.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">ITO numbers</div>
      <div class="help-text">
        <p>Every appointment gets a unique <strong>ITO number</strong> (Intake Order). The format is <span class="help-shortcut">ITO-0001-7X3K</span> — a sequential number plus a random suffix. Use this to reference orders on the phone or in emails.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Notes & charges</div>
      <div class="help-text">
        <p>Inside the appointment detail modal, switch to the <strong>Notes</strong> tab to add internal notes visible only to your team. Use the <strong>Charges</strong> tab to add additional line items (parts, materials, rush fees) beyond the original service price.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Filtering & sorting</div>
      <div class="help-text">
        <p>Use the toolbar at the top of the appointments list to filter by status, payment, date range, or search by ITO number, name, or email. The sort dropdown lets you order by date, customer name, status, or total amount.</p>
      </div>
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- CUSTOMERS --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="customers">
    <div class="help-section-head">
      <span class="help-section-icon">👥</span>
      <span class="help-section-title">Customers</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Customer profiles are created automatically when someone books an appointment (either online or manually). You can also add customers manually using the <strong>"+ New customer"</strong> button.</p>
        <p>Click any customer to open their profile modal, which shows:</p>
        <ul>
          <li><strong>Info</strong> — name, email, phone, address</li>
          <li><strong>History</strong> — all their appointments with status and totals</li>
          <li><strong>Notes</strong> — internal notes about this customer</li>
          <li><strong>Stats</strong> — total spend, last service date, appointment count</li>
        </ul>
        <p>From the customer modal, you can click any appointment in their history to jump directly to that appointment's detail modal.</p>
      </div>
    </div>

    <div class="help-tip">
      <div class="help-tip-label">💡 Tip</div>
      Sort customers by "Top spenders" to quickly see your most valuable clients.
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- SERVICES --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="services">
    <div class="help-section-head">
      <span class="help-section-icon">🛠</span>
      <span class="help-section-title">Services & Pricing</span>
    </div>

    <div class="help-card">
      <div class="help-card-title">How pricing works</div>
      <div class="help-text">
        <p>Intake uses a <strong>tier-based pricing</strong> system. This means you define price levels (tiers) and then set a price for each service at each tier.</p>
        <p>For example, a bike shop might have tiers like:</p>
        <ul>
          <li><strong>Level 1</strong> — Basic service ($30)</li>
          <li><strong>Level 2</strong> — Standard service ($60)</li>
          <li><strong>Level 3</strong> — Full service ($120)</li>
        </ul>
        <p>Each service item (like "Tune-up" or "Brake adjustment") gets a price at each tier. Customers pick the tier when they book.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Setting up your catalog</div>
      <div class="help-text">
        <p>The services page has three things to configure:</p>
        <ul>
          <li><strong>Tiers</strong> — your price levels (at least one required)</li>
          <li><strong>Categories</strong> — groups of related services (e.g., "Repairs", "Accessories")</li>
          <li><strong>Items</strong> — individual services within each category, with a price per tier</li>
        </ul>
        <p>You can also create <strong>Add-ons</strong> — optional extras customers can add to any service (like "Rush fee" or "Gift wrap").</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Drag to reorder</div>
      <div class="help-text">
        <p>Categories and items can be reordered by dragging. The order you set here is the order customers see on the booking form.</p>
      </div>
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- BOOKING FORM --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="booking-form">
    <div class="help-section-head">
      <span class="help-section-icon">📝</span>
      <span class="help-section-title">Booking Form</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Your booking form is the public-facing page where customers book appointments. It's a multi-step wizard:</p>
      </div>
      <div class="help-flow">
        <div class="help-flow-step">1. Services</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">2. Schedule</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">3. Details</div>
        <div class="help-flow-arrow">→</div>
        <div class="help-flow-step">4. Review & Pay</div>
      </div>
      <div class="help-text">
        <p>Customers select services, pick a date, enter their information, review, and optionally pay online.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Customizing the form</div>
      <div class="help-text">
        <p>Go to <strong>Intake Form Editor</strong> in the sidebar. The three-column editor lets you:</p>
        <ul>
          <li><strong>Left panel</strong> — change theme (light/dark), accent color, background tint, progress bar colors, and text colors</li>
          <li><strong>Center</strong> — live preview that updates as you make changes</li>
          <li><strong>Right panel</strong> — customize step labels and section headings</li>
        </ul>
        <p>Changes auto-save as you type. Use the desktop/mobile toggle to preview how the form looks on different devices.</p>
      </div>
    </div>

    <div class="help-tip">
      <div class="help-tip-label">💡 Tip</div>
      Use the "Reset to defaults" button at the bottom of the appearance panel to start fresh if you've made too many changes.
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- PAGE BUILDER --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="pages">
    <div class="help-section-head">
      <span class="help-section-icon">🌐</span>
      <span class="help-section-title">Page Builder</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>The page builder lets you create and edit your public website. Your site lives at <strong>{{ $currentTenant->subdomain }}.intake.works</strong> and is fully customizable.</p>
        <p>Every page is made up of <strong>sections</strong> that you can add, edit, reorder, and delete:</p>
        <ul>
          <li><strong>Hero</strong> — large banner with headline, subheading, background image, and call-to-action buttons. Supports left, center, or right text alignment.</li>
          <li><strong>Services</strong> — automatically pulls from your service catalog</li>
          <li><strong>Text + Image</strong> — split layout with text on one side and an image on the other</li>
          <li><strong>CTA Banner</strong> — colored banner with a headline and button</li>
          <li><strong>Image Gallery</strong> — grid of images</li>
          <li><strong>Contact Form</strong> — simple name/email/message form</li>
          <li><strong>Booking Form</strong> — embeds your booking wizard</li>
        </ul>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Live preview editor</div>
      <div class="help-text">
        <p>When you click <strong>Edit</strong> on a page, you get a three-column editor:</p>
        <ul>
          <li><strong>Left</strong> — collapsible section editors with all the fields</li>
          <li><strong>Center</strong> — live preview of your page that updates as you type</li>
          <li><strong>Right</strong> — page settings (title, SEO, publish status) and navigation editor</li>
        </ul>
        <p>Sections auto-save as you edit — look for the "Saved ✓" toast in the bottom right. You can also drag sections to reorder them.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Uploading images</div>
      <div class="help-text">
        <p>Image fields (like the hero background) have an <strong>Upload</strong> button next to the URL input. Click it to upload an image from your computer. The image is stored on the server and the URL is automatically filled in.</p>
        <p>Supported formats: JPG, PNG, GIF, WebP, SVG. Maximum file size: 5MB.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Publishing</div>
      <div class="help-text">
        <p>Pages start as <strong>drafts</strong>. Check the "Published" box in page settings and click "Save changes" to make a page live. You can also control whether a page appears in the navigation bar.</p>
      </div>
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- BRANDING --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="branding">
    <div class="help-section-head">
      <span class="help-section-icon">🎨</span>
      <span class="help-section-title">Branding</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Your branding settings control how your shop looks across the entire platform — your public site, booking form, and admin panel.</p>
        <ul>
          <li><strong>Logo</strong> — appears in the navigation bar, footer, and booking form header</li>
          <li><strong>Favicon</strong> — the small icon in the browser tab</li>
          <li><strong>Accent color</strong> — used for buttons, links, highlights, and the CTA banner</li>
          <li><strong>Shop name & tagline</strong> — displayed on your public site and in emails</li>
          <li><strong>Fonts</strong> — heading and body fonts used throughout your site</li>
        </ul>
      </div>
    </div>

    <div class="help-tip">
      <div class="help-tip-label">💡 Tip</div>
      Pick an accent color that contrasts well with both light and dark backgrounds. The system automatically calculates the best text color for buttons based on your accent color.
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- CAPACITY --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="capacity">
    <div class="help-section-head">
      <span class="help-section-icon">🕐</span>
      <span class="help-section-title">Capacity & Hours</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Capacity settings control when customers can book and how many appointments you can handle per day.</p>
        <ul>
          <li><strong>Weekly schedule</strong> — set which days you're open and your hours for each day</li>
          <li><strong>Daily capacity</strong> — how many appointment slots are available per day</li>
          <li><strong>Booking window</strong> — how far in advance customers can book (e.g., 60 days)</li>
          <li><strong>Minimum notice</strong> — how much notice you need before an appointment (e.g., 24 hours)</li>
        </ul>
        <p>The calendar on your booking form automatically shows only available dates based on these settings.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Slot weights</div>
      <div class="help-text">
        <p>Some jobs take more capacity than others. When viewing an appointment, you can override the <strong>slot weight</strong> — a bigger job can count as 2, 3, or 4 slots. This reduces availability for that day accordingly.</p>
      </div>
    </div>
  </div>

  {{-- ================================================================ --}}
  {{-- TEAM --}}
  {{-- ================================================================ --}}
  <div class="help-section" id="team">
    <div class="help-section-head">
      <span class="help-section-icon">🤝</span>
      <span class="help-section-title">Team</span>
    </div>

    <div class="help-card">
      <div class="help-text">
        <p>Add team members so your staff can help manage appointments and customers. Each member gets their own login credentials.</p>
        <p>There are three roles:</p>
        <ul>
          <li><strong>Owner</strong> — full access to everything, including billing and team management</li>
          <li><strong>Manager</strong> — can manage appointments, customers, services, and team members</li>
          <li><strong>Staff</strong> — can view and manage appointments and customers only</li>
        </ul>
        <p>When you add a team member, a temporary password is generated. Share it with them so they can log in, then they should change it from their profile.</p>
      </div>
    </div>

    <div class="help-card">
      <div class="help-card-title">Managing members</div>
      <div class="help-text">
        <p>From the Team page you can:</p>
        <ul>
          <li><strong>Change role</strong> — promote or demote a team member</li>
          <li><strong>Reset password</strong> — generate a new temporary password</li>
          <li><strong>Deactivate</strong> — disable a member's access without deleting them</li>
          <li><strong>Remove</strong> — permanently delete a team member (you can't remove yourself or the last owner)</li>
        </ul>
      </div>
    </div>
  </div>

  {{-- Back to top --}}
  <div class="help-back-top">
    <a href="#getting-started">↑ Back to top</a>
  </div>

</div>
@endsection
