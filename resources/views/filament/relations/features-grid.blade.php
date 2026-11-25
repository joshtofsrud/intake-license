<div>

    <link rel="stylesheet" href="{{ asset('css/admin/features-grid.css') }}?v={{ filemtime(public_path('css/admin/features-grid.css')) }}">

    {{-- MARKER-FEATURES-DIALOG: in-app reason / confirm dialog (was native prompt() and confirm()) --}}
    <div class="fg-page" x-data="{
        dlg: { open: false, title: '', body: '', ask: false, need: false, ok: 'Confirm', danger: false, reason: '', run: null, err: '' },
        ask(o) { this.dlg = Object.assign({ open: true, title: '', body: '', ask: false, need: false, ok: 'Confirm', danger: false, reason: '', run: null, err: '' }, o); this.$nextTick(() => this.$refs.fgReason && this.dlg.ask && this.$refs.fgReason.focus()); },
        go() {
            if (this.dlg.need && !this.dlg.reason.trim()) { this.dlg.err = 'A reason is needed.'; return; }
            const run = this.dlg.run, r = this.dlg.reason.trim();
            this.dlg.open = false;
            if (run) run(r || null);
        },
        promptActivate(code, name) {
            this.ask({ title: 'Activate ' + name + '?', body: 'It turns on for this shop now, at no charge to them.', ask: true, ok: 'Activate', run: (r) => $wire.activateFeature(code, r) });
        },
        promptDeactivate(code, name) {
            this.ask({ title: 'Deactivate ' + name + '?', body: 'It turns off for this shop now.', ask: true, ok: 'Deactivate', danger: true, run: (r) => $wire.deactivateFeature(code, r) });
        },
        promptSuppress(code, name) {
            this.ask({ title: 'Revoke plan access to ' + name + '?', body: 'The shop loses it even though their plan includes it.', ask: true, need: true, ok: 'Revoke', danger: true, run: (r) => $wire.suppressFeature(code, r) });
        },
        restoreSuppression(code) {
            this.ask({ title: 'Restore plan access?', body: 'The shop gets it back through their plan.', ok: 'Restore', run: () => $wire.liftSuppressionFeature(code) });
        },
    }" @keydown.escape.window="dlg.open = false">

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
            /* this list used to be the ONLY thing rendered, so
               any addon in another category (e.g. 'retail', which is where
               online_store lives) was invisible here and could not be granted
               or revoked at all. Curated labels and order are kept for the
               categories we know; everything else is appended rather than
               dropped, so a category added by a future migration can't
               silently disappear. */
            $knownLabels = [
                'communication' => 'Communication',
                'operations'    => 'Operations',
                'retail'        => 'Retail & ecommerce',
                'team'          => 'Team',
                'feature'       => 'Tier features',
                'onboarding'    => 'Onboarding',
            ];

            $categoryLabels = [];
            foreach ($knownLabels as $ckey => $clabel) {
                if (isset($grouped[$ckey]) && $grouped[$ckey]->count()) {
                    $categoryLabels[$ckey] = $clabel;
                }
            }
            foreach ($grouped as $ckey => $rows) {
                if (! isset($categoryLabels[$ckey]) && $rows->count()) {
                    $categoryLabels[$ckey] = ucfirst(str_replace('_', ' ', (string) $ckey));
                }
            }
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
                                    @if($state === 'neutral' && ! empty($f->tier_locked))
                                        {{-- plan floor: Activate would be refused, so say why instead --}}
                                        <span class="fg-badge" title="Change the shop's plan, or lower this add-on's minimum plan">Needs {{ ucfirst((string) $f->min_plan_tier) }} plan</span>

                                    @elseif($state === 'neutral')
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

        <template x-teleport="body">
            <div x-show="dlg.open" x-cloak x-transition.opacity class="fg-dlg-bg" @click.self="dlg.open = false">
                <div class="fg-dlg" role="dialog" aria-modal="true">
                    <h3 class="fg-dlg__t" x-text="dlg.title"></h3>
                    <p class="fg-dlg__b" x-text="dlg.body"></p>
                    <template x-if="dlg.ask">
                        <div>
                            <label class="fg-dlg__l">Reason <span x-text="dlg.need ? '' : '(optional)'"></span></label>
                            <input type="text" x-ref="fgReason" x-model="dlg.reason" maxlength="255" class="fg-dlg__in" @keydown.enter.prevent="go()" @input="dlg.err = ''">
                            <p class="fg-dlg__err" x-show="dlg.err" x-text="dlg.err"></p>
                        </div>
                    </template>
                    <div class="fg-dlg__f">
                        <button type="button" class="fg-btn" @click="dlg.open = false">Cancel</button>
                        <button type="button" class="fg-btn" :class="dlg.danger ? 'fg-btn--danger' : 'fg-btn--primary'" @click="go()" x-text="dlg.ok"></button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
