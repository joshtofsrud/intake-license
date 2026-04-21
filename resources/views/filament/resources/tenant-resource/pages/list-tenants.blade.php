<x-filament-panels::page>

    <link rel="stylesheet" href="{{ asset('css/admin/tenants-grid.css') }}?v={{ filemtime(public_path('css/admin/tenants-grid.css')) }}">

    <div class="tg-page">

        <div class="tg-header">
            <p class="tg-header__stats">
                {{ $counts['all'] }} tenants
                @if($counts['active']) · {{ $counts['active'] }} active @endif
                @if($counts['trial']) · {{ $counts['trial'] }} trial @endif
                @if($counts['suspended']) · {{ $counts['suspended'] }} suspended @endif
                · ${{ number_format($totalMrr / 100, 0) }}/mo MRR
            </p>
        </div>

        <div class="tg-filters">
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

            <div class="tg-filter-right">
                <select wire:model.live="filterPlan" class="tg-select">
                    <option value="all">All plans</option>
                    <option value="starter">Starter</option>
                    <option value="branded">Branded</option>
                    <option value="scale">Scale</option>
                    <option value="custom">Custom</option>
                </select>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search..." class="tg-search">
            </div>
        </div>

        @if($tenants->isEmpty())
            <div class="tg-empty">
                <p>No tenants match your filters.</p>
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

                    {{-- Card is a div, NOT an anchor. Clickable via stretched-link overlay. --}}
                    <div class="{{ $cardClass }}" data-tenant-id="{{ $t->id }}">

                        {{-- Full-card click target — absolute overlay, z-index below interactive elements --}}
                        <a href="{{ $editUrl }}" class="tg-card__overlay" aria-label="Manage {{ $t->name }}"></a>

                        <div class="tg-card__head">
                            <div class="tg-avatar" style="background: {{ $avatarBg }}; color: {{ $avatarFg }};">
                                {{ $t->initial }}
                            </div>
                            <div class="tg-card__name-col">
                                <div class="tg-card__name">{{ $t->name }}</div>
                                <div class="tg-card__sub">{{ $t->subdomain }}.{{ $domain }}</div>
                            </div>
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
                                </div>
                            </div>
                        </div>

                        <div class="tg-card__badges">
                            <span class="tg-badge" style="background: {{ $planBg }}; color: {{ $planFg }};">
                                {{ ucfirst($t->plan_tier ?? 'starter') }}
                            </span>
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

</x-filament-panels::page>
