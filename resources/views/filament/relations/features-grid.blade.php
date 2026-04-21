<div>

    <link rel="stylesheet" href="{{ asset('css/admin/features-grid.css') }}?v={{ filemtime(public_path('css/admin/features-grid.css')) }}">

    <div class="fg-page" x-data="{
        promptActivate(code, name) {
            const reason = prompt(`Reason for activating ${name}? (optional)`);
            if (reason === null) return;
            $wire.activateFeature(code, reason || null);
        },
        promptDeactivate(code, name) {
            const reason = prompt(`Reason for deactivating ${name}? (optional)`);
            if (reason === null) return;
            $wire.deactivateFeature(code, reason || null);
        },
        promptSuppress(code, name) {
            const reason = prompt(`Reason for revoking plan access to ${name}? (required)`);
            if (!reason) return;
            $wire.suppressFeature(code, reason);
        },
        restoreSuppression(code) {
            if (!confirm('Restore plan access?')) return;
            $wire.liftSuppressionFeature(code);
        },
    }">

        <div class="fg-header">
            <p class="fg-header__stats">
                {{ $counts['all'] }} features · {{ $counts['active'] }} active · {{ $counts['included'] }} included in plan
            </p>
        </div>

        <div class="fg-pills">
            <button type="button" wire:click="$set('filterCategory', 'all')"
                class="fg-pill @if($filterCategory === 'all') fg-pill--active @endif">
                All · {{ $counts['all'] }}
            </button>
            <button type="button" wire:click="$set('filterCategory', 'communication')"
                class="fg-pill @if($filterCategory === 'communication') fg-pill--active @endif">
                Communication · {{ $counts['communication'] }}
            </button>
            <button type="button" wire:click="$set('filterCategory', 'operations')"
                class="fg-pill @if($filterCategory === 'operations') fg-pill--active @endif">
                Operations · {{ $counts['operations'] }}
            </button>
            <button type="button" wire:click="$set('filterCategory', 'feature')"
                class="fg-pill @if($filterCategory === 'feature') fg-pill--active @endif">
                Tier features · {{ $counts['feature'] }}
            </button>
            <button type="button" wire:click="$set('filterCategory', 'onboarding')"
                class="fg-pill @if($filterCategory === 'onboarding') fg-pill--active @endif">
                Onboarding · {{ $counts['onboarding'] }}
            </button>
        </div>

        @php
            $categoryLabels = [
                'communication' => 'Communication',
                'operations'    => 'Operations',
                'feature'       => 'Tier features',
                'onboarding'    => 'Onboarding',
            ];
        @endphp

        @foreach($categoryLabels as $catKey => $catLabel)
            @if(isset($grouped[$catKey]) && $grouped[$catKey]->count())
                <h3 class="fg-section-title">{{ $catLabel }}</h3>

                <div class="fg-grid">
                    @foreach($grouped[$catKey] as $f)
                        @php
                            // Card state resolution
                            $state = 'neutral';
                            if ($f->is_suppressed) {
                                $state = 'suppressed';
                            } elseif ($f->source === 'self_serve') {
                                $state = 'paid';
                            } elseif ($f->source === 'staff_push' || $f->source === 'beta_comp') {
                                $state = 'comped';
                            } elseif ($f->source === 'plan_tier') {
                                $state = 'included';
                            }

                            // Price string
                            if ($f->price_display_override) {
                                $priceStr = $f->price_display_override;
                            } elseif ($f->billing_cadence === 'one_time') {
                                $priceStr = '$' . number_format($f->price_cents / 100, 0) . ' once';
                            } elseif ($f->price_cents > 0) {
                                $priceStr = '$' . number_format($f->price_cents / 100, 0) . '/mo';
                            } else {
                                $priceStr = '';
                            }

                            // Status line
                            $statusLine = match($state) {
                                'paid'       => 'Paid addon' . ($f->current_period_end ? ' · next bill ' . \Carbon\Carbon::parse($f->current_period_end)->format('M j') : ''),
                                'comped'     => 'Comped · no charge',
                                'included'   => 'Included in plan',
                                'suppressed' => 'Staff revoked',
                                default      => 'Not active',
                            };

                            if ($f->tenant_addon_status === 'canceling') {
                                $statusLine = 'Canceling · access until ' . ($f->current_period_end ? \Carbon\Carbon::parse($f->current_period_end)->format('M j') : 'period end');
                            } elseif ($f->tenant_addon_status === 'failed_payment') {
                                $statusLine = 'Payment failed · grace period';
                            }
                        @endphp

                        <div class="fg-card fg-card--{{ $state }}" data-code="{{ $f->code }}">

                            <div class="fg-card__head">
                                <div class="fg-card__title-group">
                                    <div class="fg-card__title">{{ $f->name }}</div>
                                    @if($f->is_new)
                                        <span class="fg-badge fg-badge--new">NEW</span>
                                    @endif
                                </div>
                                <div class="fg-card__right">
                                    @if($state === 'paid' || $state === 'comped' || $state === 'included' || $state === 'suppressed')
                                        @if($state === 'included')
                                            <span class="fg-badge fg-badge--plan">{{ ucfirst($tenant->plan_tier ?? 'starter') }}</span>
                                        @elseif($state === 'comped')
                                            <span class="fg-badge fg-badge--comp">Staff comp</span>
                                        @elseif($state === 'paid')
                                            @if($priceStr)<span class="fg-card__price">{{ $priceStr }}</span>@endif
                                        @elseif($state === 'suppressed')
                                            <span class="fg-badge fg-badge--sup">Suppressed</span>
                                        @endif
                                    @else
                                        @if($priceStr)<span class="fg-card__price">{{ $priceStr }}</span>@endif
                                    @endif
                                </div>
                            </div>

                            <div class="fg-card__desc">{{ $f->description }}</div>

                            <div class="fg-card__foot">
                                <span class="fg-card__status">{{ $statusLine }}</span>

                                <div class="fg-card__actions">
                                    @if($state === 'neutral')
                                        <button type="button" class="fg-btn fg-btn--primary"
                                                @click="promptActivate('{{ $f->code }}', @js($f->name))">
                                            Activate
                                        </button>

                                    @elseif($state === 'paid' || $state === 'comped')
                                        <button type="button" class="fg-btn fg-btn--danger"
                                                @click="promptDeactivate('{{ $f->code }}', @js($f->name))">
                                            Deactivate
                                        </button>

                                    @elseif($state === 'included')
                                        <div class="fg-overflow"
                                             x-data="{ open: false }"
                                             @click.outside="open = false">
                                            <button type="button" class="fg-overflow__btn" @click="open = !open" aria-label="More">⋮</button>
                                            <div class="fg-overflow__pop" x-show="open" x-cloak style="display:none;">
                                                <button type="button" class="fg-overflow__item fg-overflow__item--danger"
                                                        @click.stop="open = false; promptSuppress('{{ $f->code }}', @js($f->name))">
                                                    Revoke plan access
                                                </button>
                                            </div>
                                        </div>

                                    @elseif($state === 'suppressed')
                                        <button type="button" class="fg-btn fg-btn--restore"
                                                @click="restoreSuppression('{{ $f->code }}')">
                                            Restore
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach

    </div>
</div>
