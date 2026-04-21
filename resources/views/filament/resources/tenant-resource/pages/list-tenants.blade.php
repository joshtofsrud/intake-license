<x-filament-panels::page>

    <link rel="stylesheet" href="{{ asset('css/admin/tenants-grid.css') }}?v={{ filemtime(public_path('css/admin/tenants-grid.css')) }}">

    <div class="tg-page">

        {{-- Sticky control bar: search + lifecycle pills + plan/sub/sort selects --}}
        <div class="tg-controls">

            <div class="tg-controls__top">
                <div class="tg-header__stats">
                    {{ $counts['all'] }} tenants
                    @if($counts['active']) · {{ $counts['active'] }} active @endif
                    @if($counts['trial']) · {{ $counts['trial'] }} trial @endif
                    @if($counts['suspended']) · {{ $counts['suspended'] }} suspended @endif
                    · ${{ number_format($totalMrr / 100, 0) }}/mo MRR
                </div>
                <div class="tg-search-wrap">
                    <svg class="tg-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="search" wire:model.live.debounce.300ms="search"
                        placeholder="Search subdomain, name, owner..."
                        class="tg-search">
                </div>
            </div>

            <div class="tg-controls__bottom">
                <div class="tg-pills">
                    <button type="button" wire:click="$set('filterStatus', 'all')"
                        class="tg-pill @if($filterStatus === 'all') tg-pill--active @endif">
                        All · {{ $counts['all'] }}
                    </button>
                    <button type="button" wire:click="$set('filterStatus', 'active')"
                        class="tg-pill @if($filterStatus === 'active') tg-pill--active @endif">
                        Active · {{ $counts['active'] }}
                    </button>
                    <button type="button" wire:click="$set('filterStatus', 'trial')"
                        class="tg-pill @if($filterStatus === 'trial') tg-pill--active @endif">
                        Trial · {{ $counts['trial'] }}
                    </button>
                    <button type="button" wire:click="$set('filterStatus', 'suspended')"
                        class="tg-pill @if($filterStatus === 'suspended') tg-pill--active @endif">
                        Suspended · {{ $counts['suspended'] }}
                    </button>
                </div>

                <div class="tg-selects">
                    <select wire:model.live="filterPlan" class="tg-select" aria-label="Plan tier filter">
                        <option value="all">All plans</option>
                        <option value="starter">Starter</option>
                        <option value="branded">Branded</option>
                        <option value="scale">Scale</option>
                        <option value="custom">Custom</option>
                    </select>

                    <select wire:model.live="filterSubscription" class="tg-select" aria-label="Subscription status filter">
                        <option value="all">Any subscription</option>
                        <option value="trialing">Trialing</option>
                        <option value="active">Active</option>
                        <option value="past_due">Past due</option>
                        <option value="canceled">Canceled</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="none">No subscription</option>
                    </select>

                    <select wire:model.live="sort" class="tg-select" aria-label="Sort order">
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="alpha">Name A–Z</option>
                        <option value="alpha_desc">Name Z–A</option>
                        <option value="mrr_desc">MRR high → low</option>
                        <option value="mrr_asc">MRR low → high</option>
                    </select>
                </div>
            </div>
        </div>

        @if($tenants->isEmpty())
            <div class="tg-empty">
                <p>No tenants match your filters.</p>
                @if($search || $filterStatus !== 'all' || $filterPlan !== 'all' || $filterSubscription !== 'all')
                    <button type="button" class="tg-reset-btn"
                        wire:click="$set('search', ''); $set('filterStatus', 'all'); $set('filterPlan', 'all'); $set('filterSubscription', 'all')">
                        Clear filters
                    </button>
                @endif
            </div>
        @else
            <div class="tg-grid">
                @foreach($tenants as $t)
                    @php
                        [$avatarBg, $avatarFg] = explode('|', $t->avatar_color);
                        $editUrl = \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => $t->id]);
                        $siteUrl = "https://{$t->subdomain}.{$domain}";

                        $cardClass = 'tg-card';
                        if ($t->lifecycle === 'trial') $cardClass .= ' tg-card--trial';
                        if ($t->lifecycle === 'suspended') $cardClass .= ' tg-card--suspended';
                        if ($t->is_platform) $cardClass .= ' tg-card--platform';

                        $planColors = [
                            'starter' => ['#F1EFE8', '#5F5E5A'],
                            'branded' => ['#E1F5EE', '#085041'],
                            'scale'   => ['#EEEDFE', '#26215C'],
                            'custom'  => ['#FAEEDA', '#633806'],
                        ];
                        [$planBg, $planFg] = $planColors[$t->plan_tier] ?? ['#F1EFE8', '#5F5E5A'];

                        $lifecycleLabel = match($t->lifecycle) {
                            'active' => '● Active',
                            'trial' => '⚠ Trial',
                            'suspended' => '● Suspended',
                            default => $t->lifecycle,
                        };
                        $lifecycleColors = [
                            'active' => ['#E1F5EE', '#085041'],
                            'trial' => ['#FAC775', '#412402'],
                            'suspended' => ['#FCEBEB', '#791F1F'],
                        ];
                        [$lifeBg, $lifeFg] = $lifecycleColors[$t->lifecycle] ?? ['#F1EFE8', '#5F5E5A'];
                    @endphp

                    <div class="{{ $cardClass }}" data-tenant-id="{{ $t->id }}">

                        <a href="{{ $editUrl }}" class="tg-card__overlay" aria-label="Manage {{ $t->name }}"></a>

                        <div class="tg-card__head">
                            <div class="tg-avatar" style="background: {{ $avatarBg }}; color: {{ $avatarFg }};">
                                {{ $t->initial }}
                            </div>
                            <div class="tg-card__name-col">
                                <div class="tg-card__name">{{ $t->name }}</div>
                                <div class="tg-card__sub">{{ $t->subdomain }}.{{ $domain }}</div>
                            </div>
                            @unless($t->is_protected)
                            <div class="tg-card__menu"
                                 x-data="{
                                    open: false,
                                    popStyle: '',
                                    toggle(ev) {
                                        this.open = !this.open;
                                        if (this.open) {
                                            const r = ev.currentTarget.getBoundingClientRect();
                                            this.popStyle = `top: ${r.bottom + 4}px; left: ${r.right - 180}px;`;
                                        }
                                    }
                                 }"
                                 @click.outside="open = false">
                                <button type="button" class="tg-card__menu-btn" @click.stop.prevent="toggle($event)" aria-label="Menu">⋮</button>
                                <template x-teleport="body">
                                    <div class="tg-card__menu-pop" x-show="open" x-cloak @click.stop :style="popStyle" style="display:none;">
                                    <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="tg-card__menu-item">View site ↗</a>
                                    <a href="#"
                                       class="tg-card__menu-item"
                                       @click.stop.prevent="
                                           const f=document.createElement('form');
                                           f.method='POST';
                                           f.action='{{ route('admin.impersonate', $t->id) }}';
                                           const tok=document.createElement('input');
                                           tok.type='hidden';
                                           tok.name='_token';
                                           tok.value='{{ csrf_token() }}';
                                           f.appendChild(tok);
                                           document.body.appendChild(f);
                                           f.submit();
                                       ">Impersonate</a>
                                    <a href="{{ $editUrl }}" class="tg-card__menu-item">Edit</a>
                                    <button type="button" class="tg-card__menu-item tg-card__menu-item--danger"
                                        @click.stop.prevent="open = false; $wire.askDelete('{{ $t->id }}')">
                                        Delete
                                    </button>
                                </div>
                                </template>
                            </div>
                            @endunless
                        </div>

                        <div class="tg-card__badges">
                            @if($t->is_platform)
                                <span class="tg-badge" style="background: #1a2a05; color: #BEF264;">Platform</span>
                            @else
                                <span class="tg-badge" style="background: {{ $planBg }}; color: {{ $planFg }};">
                                    {{ ucfirst($t->plan_tier ?? 'starter') }}
                                </span>
                            @endif
                            <span class="tg-badge" style="background: {{ $lifeBg }}; color: {{ $lifeFg }};">
                                {{ $lifecycleLabel }}
                            </span>
                        </div>

                        <div class="tg-card__stats">
                            <div class="tg-stat">
                                <div class="tg-stat__label">MRR</div>
                                <div class="tg-stat__value">
                                    @if($t->mrr_cents > 0)
                                        ${{ number_format($t->mrr_cents / 100, 0) }}
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>
                            <div class="tg-stat">
                                <div class="tg-stat__label">Addons</div>
                                <div class="tg-stat__value">{{ $t->addon_count }}</div>
                            </div>
                            <div class="tg-stat">
                                <div class="tg-stat__label">Bookings</div>
                                <div class="tg-stat__value">{{ $t->bookings_30d }}</div>
                            </div>
                        </div>

                        <div class="tg-card__footer">
                            <span class="tg-card__footer-text">
                                @if($t->lifecycle === 'suspended')
                                    Suspended
                                @elseif($t->lifecycle === 'trial')
                                    Trial · joined {{ $t->created_at?->format('M j') }}
                                @else
                                    Joined {{ $t->created_at?->format('M j, Y') }}
                                @endif
                                @if($t->owner_name)
                                    · {{ $t->owner_name }}
                                @endif
                            </span>
                            <span class="tg-card__footer-cta">Manage →</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    </div>

    {{-- Delete confirmation modal (teleport to body to escape stacking context) --}}
    @if($pendingDelete)
        <template x-teleport="body">
            <div class="tg-modal-backdrop" wire:click="cancelDelete">
                <div class="tg-modal" @click.stop>
                    <div class="tg-modal__head">
                        <div class="tg-modal__icon">⚠</div>
                        <div>
                            <div class="tg-modal__title">Delete {{ $pendingDelete->name }}?</div>
                            <div class="tg-modal__sub">{{ $pendingDelete->subdomain }}.{{ $domain }}</div>
                        </div>
                    </div>

                    <div class="tg-modal__body">
                        <p>This will soft-delete the tenant. Their subdomain will stop serving immediately, and the tenant will be hidden from this list. <strong>Data is preserved</strong> — a soft-deleted tenant can be restored via database intervention.</p>
                        <p>If this tenant has an active Stripe subscription, you'll need to cancel it separately in the Stripe dashboard.</p>
                    </div>

                    <div class="tg-modal__field">
                        <label>To confirm, type the subdomain: <code>{{ $pendingDelete->subdomain }}</code></label>
                        <input type="text" wire:model.live="deleteConfirmText"
                            placeholder="{{ $pendingDelete->subdomain }}"
                            autofocus autocomplete="off" spellcheck="false"
                            class="tg-modal__input">
                    </div>

                    <div class="tg-modal__actions">
                        <button type="button" wire:click="cancelDelete" class="tg-modal__btn tg-modal__btn--ghost">
                            Cancel
                        </button>
                        <button type="button" wire:click="confirmDelete"
                            class="tg-modal__btn tg-modal__btn--danger"
                            @if($deleteConfirmText !== $pendingDelete->subdomain) disabled @endif>
                            Delete tenant
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif

</x-filament-panels::page>
