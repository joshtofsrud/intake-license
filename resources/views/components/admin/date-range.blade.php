@props([
    'fromName'     => 'from',
    'toName'       => 'to',
    'fromValue'    => '',
    'toValue'      => '',
    'placeholder'  => 'Date range',
    // a Livewire method to call with (from, to) on Apply / Clear. Without it
    // the picker submits its parent <form>, like the tenant picker.
    'wire'         => null,
    'triggerClass' => '',
    // render the trigger hidden; the page opens the picker itself
    'hideTrigger'  => false,
])

{{--
  Master-admin copy of components/tenant/date-range: one calendar, presets,
  click a start and an end. Styles: public/css/admin/date-range.css. Script:
  public/js/admin/date-range.js. Both load on every admin page
  (AdminPanelProvider, MARKER-ADMIN-DATE-RANGE).
--}}
@php
    $drF = $fromValue ? \Carbon\Carbon::parse($fromValue) : null;
    $drT = $toValue ? \Carbon\Carbon::parse($toValue) : null;
    $drLabel = $drF && $drT
        ? ($drF->isSameDay($drT) ? $drF->format('M j, Y') : $drF->format('M j, Y') . ' — ' . $drT->format('M j, Y'))
        : $placeholder;
@endphp
<div {{ $attributes->merge(['class' => 'adr']) }} data-dr @if($wire) data-dr-wire="{{ $wire }}" @endif>
    <input type="hidden" name="{{ $fromName }}" value="{{ $fromValue }}" data-dr-from>
    <input type="hidden" name="{{ $toName }}"   value="{{ $toValue }}"   data-dr-to>

    <button type="button" class="adr-trigger {{ $triggerClass }} {{ $drF ? 'is-on' : '' }}" data-dr-trigger @if($hideTrigger) hidden @endif>
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <rect x="1.75" y="3" width="10.5" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
            <path d="M4.5 1.75v2.5M9.5 1.75v2.5M1.75 6h10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        <span data-dr-label data-placeholder="{{ $placeholder }}">{{ $drLabel }}</span>
    </button>

    <div class="adr-pop" data-dr-pop hidden>
        <div class="adr-presets">
            <button type="button" class="adr-preset" data-dr-preset="today">Today</button>
            <button type="button" class="adr-preset" data-dr-preset="last_7">Last 7 days</button>
            <button type="button" class="adr-preset" data-dr-preset="this_month">This month</button>
            <button type="button" class="adr-preset" data-dr-preset="last_30">Last 30 days</button>
            <button type="button" class="adr-preset" data-dr-preset="this_year">This year</button>
        </div>

        <div class="adr-cal">
            <div class="adr-head">
                <button type="button" class="adr-nav" data-dr-prev aria-label="Previous month">‹</button>
                <div class="adr-title" data-dr-title></div>
                <button type="button" class="adr-nav" data-dr-next aria-label="Next month">›</button>
            </div>
            <div class="adr-dows">
                <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
            </div>
            <div class="adr-grid" data-dr-grid></div>
        </div>

        <div class="adr-summary">
            <span data-dr-summary-text>Click a date to start</span>
        </div>

        <div class="adr-actions">
            <button type="button" class="adr-btn adr-btn--ghost adr-clear" data-dr-clear>Clear</button>
            <button type="button" class="adr-btn" data-dr-cancel>Cancel</button>
            <button type="button" class="adr-btn adr-btn--primary" data-dr-apply>Apply</button>
        </div>
    </div>
</div>
