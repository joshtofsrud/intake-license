@extends('layouts.tenant')

@section('title', 'Add-ons')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/addons.css') }}?v={{ filemtime(public_path('css/tenant/addons.css')) }}">
@endpush

@section('content')
<div class="addons-page" id="addonsPage"
     data-stripe-live="{{ $stripeLive ? '1' : '0' }}"
     data-csrf="{{ csrf_token() }}"
     data-activate-url="{{ route('tenant.feature_addons.activate') }}"
     data-cancel-url="{{ route('tenant.feature_addons.cancel') }}">

    <header class="addons-header">
        <div class="addons-header__title">
            <h1>Add-ons</h1>
            <p class="addons-header__sub">Pay for what you need, nothing you don't.</p>
        </div>
        @unless($stripeLive)
            <div class="addons-header__banner">
                <strong>Preview mode:</strong> Payment processing is not yet live. Purchases made now will activate immediately and be billed in your next invoice.
            </div>
        @endunless
    </header>

    @php
        $categoryLabels = [
            'communication' => 'Communication',
            'operations' => 'Operations',
            'feature' => 'Tier features',
            'onboarding' => 'One-time services',
        ];
    @endphp

    @foreach($categoryLabels as $catKey => $catLabel)
        @if(isset($grouped[$catKey]) && $grouped[$catKey]->count())
            <section class="addons-section" data-category="{{ $catKey }}">
                <h2 class="addons-section__title">{{ $catLabel }}</h2>

                <div class="addons-grid">
                    @foreach($grouped[$catKey] as $feature)
                        @php
                            $isActive = $feature->has_access;
                            $isIncluded = $feature->source === 'plan_tier' && ! $feature->is_suppressed;
                            $isCanceling = $feature->tenant_addon_status === 'canceling';
                            $isFailed = $feature->tenant_addon_status === 'failed_payment';
                            $isSelfServeActive = in_array($feature->source, ['self_serve'], true);
                        @endphp

                        <article class="addon-card
                            @if($isActive) addon-card--active @endif
                            @if($isIncluded) addon-card--included @endif
                            @if($isCanceling) addon-card--canceling @endif
                            @if($isFailed) addon-card--failed @endif
                            @if($feature->is_new) addon-card--new @endif"
                            data-addon-code="{{ $feature->code }}"
                            data-addon-name="{{ $feature->name }}"
                            data-addon-price="{{ $feature->price_cents }}"
                            data-addon-cadence="{{ $feature->billing_cadence }}">

                            <div class="addon-card__head">
                                <div class="addon-card__title-group">
                                    <h3 class="addon-card__title">{{ $feature->name }}</h3>
                                    @if($feature->is_new)
                                        <span class="addon-card__badge addon-card__badge--new">NEW</span>
                                    @endif
                                </div>
                                <div class="addon-card__price">
                                    @if($feature->price_display_override)
                                        {{ $feature->price_display_override }}
                                    @elseif($feature->billing_cadence === 'one_time')
                                        ${{ number_format($feature->price_cents / 100, 0) }} <span class="addon-card__price-unit">once</span>
                                    @elseif($feature->price_cents > 0)
                                        ${{ number_format($feature->price_cents / 100, 0) }}<span class="addon-card__price-unit">/mo</span>
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>

                            <p class="addon-card__desc">{{ $feature->description }}</p>

                            <div class="addon-card__footer">
                                @if($isIncluded)
                                    <span class="addon-card__state addon-card__state--included">
                                        ✓ Included in your plan
                                    </span>
                                @elseif($isSelfServeActive && $isCanceling)
                                    <span class="addon-card__state addon-card__state--canceling">
                                        Canceling @if($feature->current_period_end) — access until {{ \Carbon\Carbon::parse($feature->current_period_end)->format('M j, Y') }}@endif
                                    </span>
                                    <button type="button" class="addon-card__btn addon-card__btn--reactivate" data-action="reactivate">
                                        Reactivate
                                    </button>
                                @elseif($isSelfServeActive && $isFailed)
                                    <span class="addon-card__state addon-card__state--failed">
                                        Payment failed — please update billing
                                    </span>
                                @elseif($isSelfServeActive)
                                    <span class="addon-card__state addon-card__state--added">
                                        ✓ Added
                                    </span>
                                    <button type="button" class="addon-card__btn addon-card__btn--manage" data-action="manage">
                                        Manage
                                    </button>
                                @elseif($feature->source === 'staff_push' || $feature->source === 'beta_comp')
                                    <span class="addon-card__state addon-card__state--comped">
                                        ✓ Activated by Intake
                                    </span>
                                @elseif($feature->is_suppressed)
                                    <span class="addon-card__state addon-card__state--suppressed">
                                        Contact support to enable
                                    </span>
                                @else
                                    <button type="button" class="addon-card__btn addon-card__btn--add" data-action="add">
                                        Add now
                                    </button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>

<div class="addon-modal" id="addonModal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="addon-modal__backdrop" data-close></div>
    <div class="addon-modal__panel" role="document">
        <button type="button" class="addon-modal__close" data-close aria-label="Close">&times;</button>
        <div class="addon-modal__body" id="addonModalBody"></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/tenant/addons.js') }}?v={{ filemtime(public_path('js/tenant/addons.js')) }}"></script>
@endpush
