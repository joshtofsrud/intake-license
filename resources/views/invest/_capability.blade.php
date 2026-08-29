{{-- MARKER-INVEST-CAPABILITY — the evidence for the "one core, packs on top"
     claim made just above this. Core cards are hand-written because no
     registry of core modules exists; the pack counts are read live. --}}
@php
  // Grouped and counted from the live catalogue so the page cannot drift from
  // what is actually shipping. Category order is fixed rather than
  // alphabetical, so the list reads in the order a shop would meet them.
  $packs = \App\Models\Addon::query()
      ->where('status', 'active')
      ->get()
      ->groupBy('category');

  $order = ['retail', 'operations', 'feature', 'communication', 'team', 'onboarding'];
  $label = [
      'retail'        => 'Retail',
      'operations'    => 'Operations',
      'feature'       => 'Features',
      'communication' => 'Communication',
      'team'          => 'Team',
      'onboarding'    => 'Onboarding',
  ];

  $categories = collect($order)
      ->merge($packs->keys())          // anything new appears even if unlisted
      ->unique()
      ->filter(fn ($c) => isset($packs[$c]))
      ->values();

  $packTotal = $packs->flatten()->count();
@endphp

<section><div class="wrap">
  <p class="sub">What the platform does</p>
  <h2>One core. Everything else is a pack.</h2>
  <p class="lede">The core is what every service business runs on, and it is the same code for all of
    them. Anything a particular trade needs sits on top as a pack, switched on for one business without
    touching another.</p>

  <div class="cap-core">
    <div class="cap-grp"><h3>Booking</h3><ul>
      <li>Calendar and capacity</li><li>Services and resources</li><li>Waitlist</li>
      <li>Pickup and delivery windows</li><li>Public booking pages</li></ul></div>

    <div class="cap-grp"><h3>Service</h3><ul>
      <li>Work orders</li><li>Custom work-order fields</li><li>Status and handoff</li>
      <li>Parts against the job</li><li>Deliveries</li></ul></div>

    <div class="cap-grp"><h3>Retail</h3><ul>
      <li>Register and tender</li><li>Discounts and promo codes</li><li>Gift cards</li>
      <li>Receipts</li><li>Pending payments</li></ul></div>

    <div class="cap-grp"><h3>Inventory</h3><ul>
      <li>Categories and vendors</li><li>Receiving</li><li>Special orders</li>
      <li>Transfer requests</li><li>Distributor catalog sync</li></ul></div>

    <div class="cap-grp"><h3>Customers</h3><ul>
      <li>Profiles and history</li><li>Consent tracking</li><li>Lapsed-customer signals</li>
      <li>Segmentation</li><li>Customer portal</li></ul></div>

    <div class="cap-grp"><h3>Staff</h3><ul>
      <li>Team and roles</li><li>Scheduling</li><li>Time clock</li>
      <li>PIN access</li><li>Security settings</li></ul></div>

    <div class="cap-grp"><h3>Communication</h3><ul>
      <li>Unified inbox</li><li>Email templates</li><li>Campaigns</li>
      <li>Suppressions and consent</li><li>Staff alerts</li></ul></div>

    <div class="cap-grp"><h3>Website</h3><ul>
      <li>Page builder</li><li>Media library</li><li>Custom domains</li>
      <li>Storefront and orders</li><li>Design tokens</li></ul></div>

    <div class="cap-grp"><h3>Data</h3><ul>
      <li>CSV import with merge review</li><li>Saved column mappings</li><li>Traffic and search reports</li>
      <li>Sell-through</li><li>Exports</li></ul></div>
  </div>

  @if($packTotal)
    {{-- Collapsed by default: the count is the argument, the names are detail. --}}
    <details class="m">
      <summary>Every pack, by category — {{ $packTotal }} in the catalogue</summary>
      <div class="inner">
        <div class="stack" style="margin-top:4px">
          @foreach($categories as $cat)
            @php $rows = $packs[$cat]; @endphp
            <div class="srow">
              <b>{{ $label[$cat] ?? ucfirst($cat) }}</b>
              <span class="note">{{ $rows->take(4)->pluck('name')->implode(' · ') }}{{ $rows->count() > 4 ? ' …' : '' }}</span>
              <span class="amt">{{ $rows->count() }}</span>
            </div>
          @endforeach
          <div class="srow tot">
            <b>In the catalogue today</b>
            <span class="note">Each one grantable per business, independently</span>
            <span class="amt">{{ $packTotal }}</span>
          </div>
        </div>

        <p class="fine">The number matters more than the names: every one of these is switched on or off
          for a single business without a branch, a build or a migration. That is the claim — not that
          the list is long, but that adding to it costs one pack rather than one fork. Counts are read
          from the live catalogue, not maintained by hand on this page.</p>
      </div>
    </details>
  @endif
</div></section>
