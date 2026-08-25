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
                    @if($counts['past_due'] ?? 0) · {{ $counts['past_due'] }} past due @endif
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
                    <button type="button" wire:click="$set('filterStatus', 'past_due')"
                        class="tg-pill @if($filterStatus === 'past_due') tg-pill--active @endif">
                        Past due · {{ $counts['past_due'] ?? 0 }}
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
                        if ($t->lifecycle === 'past_due') $cardClass .= ' tg-card--suspended';
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
                            'past_due' => '● Past due',
                            default => $t->lifecycle,
                        };
                        $lifecycleColors = [
                            'active' => ['#E1F5EE', '#085041'],
                            'trial' => ['#FAC775', '#412402'],
                            'suspended' => ['#FCEBEB', '#791F1F'],
                            'past_due' => ['#FCE3CF', '#7A3D02'],
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
                                    {{-- MARKER-TENANT-STANDING-ADMIN --}}
                                    @if($t->suspended_at)
                                      <button type="button" class="tg-card__menu-item"
                                        @click.stop.prevent="open = false; $wire.unsuspend('{{ $t->id }}')">
                                        Unsuspend
                                      </button>
                                    @elseif($t->subdomain !== '__platform')
                                      <button type="button" class="tg-card__menu-item"
                                        @click.stop.prevent="open = false; $wire.askSuspend('{{ $t->id }}')">
                                        Suspend…
                                      </button>
                                    @endif
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


{{-- MARKER-TENANT-STANDING-ADMIN — suspend confirm --}}
@if($pendingSuspendId)
  @php $suspendTarget = \App\Models\Tenant::find($pendingSuspendId); @endphp
  <div style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:900;display:flex;
              align-items:center;justify-content:center;padding:24px" wire:click.self="cancelSuspend">
    <div style="max-width:480px;width:100%;background:rgb(24 24 27);border:1px solid rgb(63 63 70);
                border-radius:14px;padding:20px">
      <div style="font-size:15px;font-weight:600;margin-bottom:6px">
        Suspend {{ $suspendTarget?->name ?? 'this shop' }}?
      </div>
      <p style="font-size:13px;color:rgb(161 161 170);margin:0 0 12px;line-height:1.6">
        Staff are locked out of Intake on their next request. Their booking page, customer accounts
        and gift card balance checks keep working, and no data is touched. Billing is not changed —
        cancel or pause that in Stripe separately if you mean to.
      </p>
      <input type="text" wire:model="suspendReason" placeholder="Reason — the shop sees this"
             style="width:100%;background:rgb(39 39 42);border:1px solid rgb(63 63 70);border-radius:9px;
                    color:#e7e7ea;padding:9px 11px;font-size:13.5px;margin-bottom:14px">
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <x-filament::button color="gray" wire:click="cancelSuspend">Cancel</x-filament::button>
        <x-filament::button color="danger" wire:click="confirmSuspend">Suspend shop</x-filament::button>
      </div>
    </div>
  </div>
@endif

{{-- MARKER-TENANT-STANDING-ADMIN — LEGEND. These badges stopped being
     decorative the moment enforcement shipped; say what each one now does. --}}
<div style="margin-top:18px;border:1px solid rgb(63 63 70 / .6);border-radius:11px;padding:13px 15px">
  <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:rgb(113 113 122);margin-bottom:9px">
    What these states enforce
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;font-size:12.5px;color:rgb(161 161 170);line-height:1.55">
    <div><b style="color:#86efac">● Active</b> — full access.</div>
    <div><b style="color:#fcd34d">⚠ Trial</b> — full access until the trial ends.</div>
    <div><b style="color:#fdba74">● Past due</b> — full access during the grace period, then locked automatically. Grace is set in Billing configuration.</div>
    <div><b style="color:#fca5a5">● Suspended</b> — staff locked out now, by us. Billing keeps running unless you stop it in Stripe.</div>
  </div>
  <div style="margin-top:9px;font-size:12px;color:rgb(113 113 122);line-height:1.55">
    In every state the shop's <b style="color:#a1a1aa">booking page, customer accounts and gift card balance checks stay live</b>.
    Deleting a shop cancels its Stripe subscription first, and refuses if that call fails.
  </div>
</div>

</x-filament-panels::page>
