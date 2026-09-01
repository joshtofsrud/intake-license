{{-- MARKER-SCHED-SECTION — Book a call. Content: booking_type, layout (calendar|slots|button),
     eyebrow, heading, subheading, button_label, slot_count, show_host, accent_color,
     text_color_body, anchor_id, hide_on_mobile, hide_on_desktop --}}
@php
    $slug   = $c['booking_type'] ?? 'demo';
    $type   = \App\Models\PlatformBookingType::where('slug', $slug)->first();
    $layout = in_array($c['layout'] ?? 'calendar', ['calendar', 'slots', 'button'], true) ? ($c['layout'] ?? 'calendar') : 'calendar';
    $label  = trim((string) ($c['button_label'] ?? '')) ?: 'Book a call';
    $preview = ! empty($builderPreview);

    $vars = '';
    if (! empty($c['accent_color']))    $vars .= "--mk-accent:{$c['accent_color']};";
    if (! empty($c['text_color_body'])) $vars .= "--mk-muted:{$c['text_color_body']};";
    $style = trim(($inlineStyle ?? '') . $vars);

    // No shared hide classes exist in the marketing shell (custom_html does
    // this per-instance too), so emit a scoped rule.
    $instId = 'mk-bc-sec-' . ($section->id ?? uniqid());
    $hideCss = '';
    if (! empty($c['hide_on_mobile']))  $hideCss .= "@media (max-width:768px){#{$instId}{display:none!important}}";
    if (! empty($c['hide_on_desktop'])) $hideCss .= "@media (min-width:769px){#{$instId}{display:none!important}}";
    $sectionId = ! empty($c['anchor_id']) ? $c['anchor_id'] : $instId;

    $heading = trim((string) ($c['heading'] ?? '')) ?: ($type?->name ?? 'Book a call');
    $sub     = trim((string) ($c['subheading'] ?? '')) ?: (string) ($type?->description ?? '');
    $showHost = ! isset($c['show_host']) || ! empty($c['show_host']);
@endphp
@if($hideCss !== '')<style>{!! str_replace('#' . $instId, '#' . $sectionId, $hideCss) !!}</style>@endif
<section class="{{ $padding }}" id="{{ $sectionId }}" @if($style !== '') style="{{ $style }}" @endif>
    <div class="mk-container">
        @if(! $type || ! $type->isPublic())
            @if($preview)
                <div style="border:0.5px dashed rgba(255,255,255,.25);border-radius:12px;padding:20px;font-size:14px;opacity:.7">
                    Book a call: no public booking type called <code>{{ $slug }}</code>. Pick one in the panel, or add it under Scheduling → Booking types.
                </div>
            @endif
        @else
            @if(!empty($c['eyebrow']))<div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>@endif
            @if($layout !== 'calendar' || ! $showHost)
                <h2 class="mk-section-title">{{ $heading }}</h2>
                @if($sub !== '')<p class="mk-section-sub">{{ $sub }}</p>@endif
            @endif

            @if(! $type->is_active)
                <div style="background:var(--mk-bg2);border:.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:20px;max-width:520px">
                    <div style="font-weight:600;margin-bottom:4px">Not taking bookings right now</div>
                    <div style="color:var(--mk-muted);font-size:14.5px;margin-bottom:12px">This calendar is paused for the moment. Drop us a line and we'll find a time.</div>
                    <a href="{{ route('marketing.contact') }}" class="mk-btn mk-btn--ghost mk-btn--sm">Contact us</a>
                    @if($preview)<div style="font-size:12px;opacity:.6;margin-top:10px">Visitors see this because the type is off. Turn it on under Scheduling → Booking types.</div>@endif
                </div>

            @elseif($layout === 'calendar')
                @include('marketing._book-widget', [
                    'type'     => $type,
                    'booking'  => null,
                    'showHost' => $showHost,
                    'heading'  => trim((string) ($c['heading'] ?? '')) ?: null,
                    'intro'    => trim((string) ($c['subheading'] ?? '')) ?: null,
                ])

            @elseif($layout === 'slots')
                @php
                    $n     = max(2, min(12, (int) ($c['slot_count'] ?? 5)));
                    $avail = app(\App\Services\Platform\BookingAvailabilityService::class);
                    $tz    = $avail->timezone();
                    $next  = $preview ? [] : $avail->nextSlots($type, $n);
                    $tzLabel = \Carbon\Carbon::now($tz)->format('T');
                @endphp
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <div style="font-size:13.5px;color:var(--mk-muted);width:100%;margin-bottom:2px">Next open times, shown in {{ $tzLabel }} — you can switch timezone on the next step.</div>
                    @forelse($next as $s)
                        @php $l = $s->setTimezone($tz); @endphp
                        <a href="{{ $type->publicUrl() }}?start={{ rawurlencode($s->toIso8601String()) }}"
                           style="padding:10px 14px;border:.5px solid var(--mk-border2);border-radius:var(--mk-r);color:var(--mk-text);text-decoration:none;font-size:14px;font-weight:500;line-height:1.2">
                            {{ $l->format('D M j') }}<small style="display:block;font-size:11.5px;color:var(--mk-muted);font-weight:400">{{ $l->format('g:i a') }}</small>
                        </a>
                    @empty
                        @if($preview)
                            @for($i = 0; $i < $n; $i++)
                                <span style="padding:10px 14px;border:.5px dashed var(--mk-border2);border-radius:var(--mk-r);color:var(--mk-muted);font-size:14px;line-height:1.2">Day {{ $i + 1 }}<small style="display:block;font-size:11.5px;font-weight:400">time</small></span>
                            @endfor
                        @else
                            <span style="font-size:14px;color:var(--mk-muted)">Nothing open in the next few weeks — pick a time further out.</span>
                        @endif
                    @endforelse
                    <a href="{{ $type->publicUrl() }}" class="mk-btn mk-btn--ghost mk-btn--sm">See all times</a>
                </div>
                <div style="font-size:12.5px;color:var(--mk-dim);margin-top:14px">{{ $type->length_min }} minutes · picking a time takes you to the booking form.</div>

            @else
                <a href="{{ $type->publicUrl() }}" class="mk-btn mk-btn--primary">{{ $label }}</a>
                <div style="font-size:12.5px;color:var(--mk-dim);margin-top:12px">{{ $type->length_min }} minutes · pick a time on the next page.</div>
            @endif
        @endif
    </div>
</section>
