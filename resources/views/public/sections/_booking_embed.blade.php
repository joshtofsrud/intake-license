{{--
  MARKER-PATCH-607 — booking form pivot section.
  On the Booking page (slug "book") this renders a faithful, static, themed
  mock of what /book actually shows for this tenant (the fork, or the flow
  heading), plus a note that sections above/below it in the builder render
  above/below the live booking form. It is intentionally non-interactive:
  the live flow's JS only runs on /book itself.

  On any other page it keeps the legacy behavior: a heading + button linking
  to /book.
--}}
@php
  $__tenant  = $tenant ?? tenant();
  // Detect the Booking page without a model relation: memoized slug lookup.
  $__bookPageId = once(fn() => \App\Models\Tenant\TenantPage::where('tenant_id', $__tenant->id)->where('slug', 'book')->value('id'));
  $__onBookPage = !empty($section->page_id) && $section->page_id === $__bookPageId;
  $__s       = $__tenant->settings ?? [];
  $__theme   = $__s['booking_theme'] ?? 'light';
  $__dark    = $__theme === 'dark';
  $__accent  = ($__s['booking_accent'] ?? '') ?: ($__tenant->accent_color ?? '#BEF264');
  $__bg      = $__dark ? '#111111' : ($__tenant->bg_color ?? '#ffffff');
  $__text    = $__dark ? '#f0f0f0' : ($__tenant->text_color ?? '#111111');
  $__muted   = $__dark ? 'rgba(255,255,255,.55)' : 'rgba(0,0,0,.55)';
  $__card    = $__dark ? 'rgba(255,255,255,.04)' : 'rgba(0,0,0,.02)';
  $__border  = $__dark ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.12)';
  $__mode    = $__s['booking_flow_mode'] ?? ($__tenant->booking_flow_mode ?? 'choice');
@endphp

@if($__onBookPage)
  <section class="p-section" id="book" style="background:{{ $__bg }};color:{{ $__text }};padding:56px 0">
    <div class="p-container" style="max-width:960px;margin:0 auto;padding:0 24px">

      <div style="text-align:center;margin-bottom:28px">
        <h2 style="font-size:clamp(24px,3.4vw,34px);font-weight:700;margin:0 0 8px">How would you like to book?</h2>
        <p style="font-size:14px;color:{{ $__muted }};margin:0">Pick what fits your visit — you can switch anytime.</p>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;max-width:760px;margin:0 auto">
        <div style="border:1px solid {{ $__accent }};background:{{ $__card }};border-radius:12px;padding:24px">
          <div style="width:38px;height:38px;border-radius:9px;background:{{ $__card }};border:1px solid {{ $__border }};display:flex;align-items:center;justify-content:center;margin-bottom:14px;color:{{ $__accent }}">⚡</div>
          <div style="font-size:17px;font-weight:700;margin-bottom:6px">Quick booking</div>
          <div style="font-size:12.5px;color:{{ $__muted }};line-height:1.5">Pick from the service menu and grab a time.</div>
          <div style="margin-top:14px;font-size:13px;font-weight:600;color:{{ $__accent }}">Start quick →</div>
        </div>
        <div style="border:1px solid {{ $__border }};background:{{ $__card }};border-radius:12px;padding:24px">
          <div style="width:38px;height:38px;border-radius:9px;background:{{ $__card }};border:1px solid {{ $__border }};display:flex;align-items:center;justify-content:center;margin-bottom:14px">🔧</div>
          <div style="font-size:17px;font-weight:700;margin-bottom:6px">Full setup</div>
          <div style="font-size:12.5px;color:{{ $__muted }};line-height:1.5">Add each item, choose services, review everything.</div>
          <div style="margin-top:14px;font-size:13px;font-weight:600">Start full →</div>
        </div>
      </div>

      <div style="max-width:760px;margin:22px auto 0;border:1.5px dashed {{ $__border }};border-radius:10px;padding:12px 16px;text-align:center">
        <div style="font-size:12px;color:{{ $__muted }};line-height:1.55">
          <strong style="color:{{ $__text }}">This is your live booking flow</strong> — shown here as a preview.
          It appears interactive only on your public /book page, styled by the
          <em>Intake Form Editor</em>. Sections placed <strong style="color:{{ $__text }}">above</strong> this one
          appear above the booking flow; sections <strong style="color:{{ $__text }}">below</strong> it appear below.
        </div>
      </div>

    </div>
  </section>
@else
  <section class="p-section" id="book">
    <div class="p-container">
      @if(!empty($c['heading']))
        <div class="p-section-head-wrap" style="text-align:center">
          <h2 class="p-section-heading">{{ $c['heading'] }}</h2>
        </div>
      @endif
      <div style="text-align:center;padding:48px 0;border:1.5px dashed rgba(0,0,0,.12);border-radius:var(--p-r-lg)">
        <p style="font-size:16px;opacity:.5;margin-bottom:20px">Online booking</p>
        <a href="/book" class="p-btn p-btn--primary">{{ $c['button_text'] ?? 'Book an appointment' }}</a>
      </div>
    </div>
  </section>
@endif
