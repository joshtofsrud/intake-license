{{-- MARKER-TENANT-BILLING --}}
@php
    $tenant    = $this->tenant();
    $statement = $this->statement();
    $balance   = $this->balance();
    $cap       = $this->capState();
    $discounts = $this->discounts();

    $card  = 'border-radius:12px;padding:14px 18px;border:1px solid rgba(127,127,127,.22)';
    $label = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:8px';
    $body  = 'font-size:13px;line-height:1.55;opacity:.75;margin:0';
    $money = fn ($c) => '$' . number_format($c / 100, 2);
@endphp

<x-filament-panels::page>

    <div style="{{ $card }}">
        <div style="{{ $label }}">What this is</div>
        <p style="{{ $body }}">
            One shop's billing, computed by the same code the shop sees on its own Account tab — so a support
            call never has the two of you reading different totals. Nothing here charges anything: the card
            and the charge threshold arrive with the charging work.
        </p>
    </div>

    <div style="display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <div style="{{ $label }}">Shop</div>
            <select wire:model.live="tenantId" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm" style="min-width:320px">
                <option value="">Choose a shop…</option>
                @foreach($this->tenants() as $t)
                    <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->subdomain }}){{ $t->is_demo ? ' — demo' : '' }}</option>
                @endforeach
            </select>
        </div>
        @if($tenant)
            <div>
                <div style="{{ $label }}">Month</div>
                <select wire:model.live="month" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm">
                    @foreach($this->monthOptions() as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    {{-- MARKER-STATEMENT-HISTORY --}}
    @if($tenant && $statement && ! ($statement['exists'] ?? true))
        <div style="{{ $card }};margin-top:16px">
            <div style="{{ $label }}">{{ $statement['period']['label'] }}</div>
            <p style="{{ $body }}">
                This shop did not exist yet — it was created
                {{ \Carbon\Carbon::parse($statement['created_at'])->format('F j, Y') }}.
            </p>
        </div>
    @elseif($tenant && $statement)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:16px">
            <div style="{{ $card }}">
                <div style="{{ $label }}">Unbilled balance</div>
                <div style="font-size:24px;font-weight:700">{{ $money($balance['cents']) }}</div>
                <div style="font-size:12px;opacity:.6;margin-top:2px">{{ number_format($balance['messages']) }} messages metered to date</div>
            </div>
            <div style="{{ $card }}">
                <div style="{{ $label }}">{{ $statement['period']['label'] }}</div>
                <div style="font-size:24px;font-weight:700">{{ $money($statement['total_cents']) }}</div>
                <div style="font-size:12px;opacity:.6;margin-top:2px">
                    {{ $money($statement['before_cents']) }} before discounts
                </div>
            </div>
            <div style="{{ $card }}">
                <div style="{{ $label }}">Their spend limit</div>
                @if($cap && $cap['capped'])
                    <div style="font-size:24px;font-weight:700">${{ number_format($cap['cap'], 2) }}</div>
                    <div style="font-size:12px;opacity:.6;margin-top:2px">
                        ${{ number_format($cap['spent'], 2) }} used{{ $cap['reached'] ? ' — reached, campaigns paused' : '' }}
                    </div>
                @else
                    <div style="font-size:24px;font-weight:700">None</div>
                    <div style="font-size:12px;opacity:.6;margin-top:2px">campaigns are not capped</div>
                @endif
            </div>
            <div style="{{ $card }}">
                <div style="{{ $label }}">Included each month</div>
                <div style="font-size:24px;font-weight:700">{{ number_format($this->allowance()) }}</div>
                <div style="font-size:12px;opacity:.6;margin-top:2px">free emails before anything meters</div>
            </div>
        </div>

        {{-- the statement itself --}}
        <div style="{{ $card }};margin-top:16px">
            <div style="{{ $label }}">Statement · {{ $statement['period']['label'] }}</div>
            @if($statement['usage_only'] ?? false)
                {{-- MARKER-STATEMENT-HISTORY --}}
                <p style="{{ $body }};margin-bottom:10px;opacity:.6">
                    Usage only. Plan and add-ons describe today's arrangement, and there is no record of what
                    this shop had that month, so showing them here would be a guess. Usage is exact — every
                    row carries the rate it was sent at.
                </p>
            @endif
            <table style="width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums">
                <tbody>
                    @unless($statement['usage_only'] ?? false)
                    <tr>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                            {{ $statement['plan']['label'] }}
                            <span style="opacity:.55">· {{ $statement['plan']['locations'] }} {{ \Illuminate\Support\Str::plural('location', $statement['plan']['locations']) }}</span>
                        </td>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">{{ $money($statement['plan']['cents']) }}</td>
                    </tr>
                    @endunless
                    @foreach($statement['addons'] as $a)
                        <tr>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                                {{ $a['name'] }}
                                @if($a['note'])<span style="opacity:.55">· {{ $a['note'] }}</span>@endif
                                @if($a['canceling'])<span style="opacity:.55">· cancels {{ \Carbon\Carbon::parse($a['canceling'])->format('M j') }}</span>@endif
                            </td>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">{{ $money($a['cents']) }}</td>
                        </tr>
                    @endforeach

                    @php $u = $statement['usage']; @endphp
                    @if(($u['email']['free']['count'] ?? 0) > 0)
                        <tr>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                                Included emails <span style="opacity:.55">· {{ number_format($u['email']['free']['count']) }} of {{ number_format($u['email']['free']['allowance']) }} used</span>
                            </td>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">$0.00</td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                            Campaigns <span style="opacity:.55">· {{ number_format($u['email']['marketing']['count']) }} at {{ $u['email']['marketing']['rate'] ?? '—' }}</span>
                        </td>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">{{ $money($u['email']['marketing']['cents']) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                            Receipts &amp; reminders <span style="opacity:.55">· {{ number_format($u['email']['transactional']['count']) }} at {{ $u['email']['transactional']['rate'] ?? '—' }}</span>
                        </td>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">{{ $money($u['email']['transactional']['cents']) }}</td>
                    </tr>
                    @if($u['sms']['count'] > 0 || $u['sms']['byo'])
                        <tr>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                                Text messages <span style="opacity:.55">· {{ number_format($u['sms']['segments']) }} segments</span>
                            </td>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">{{ $money($u['sms']['cents']) }}</td>
                        </tr>
                    @endif

                    @foreach($statement['discounts'] as $d)
                        <tr style="color:#7FD98F">
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                                {{ $d['discount']->reason }}
                                <span style="opacity:.7">· {{ $d['discount']->describeAmount() }}</span>
                            </td>
                            <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">−{{ $money($d['cents']) }}</td>
                        </tr>
                    @endforeach

                    <tr>
                        <td style="padding:10px 0 0;border-top:1px solid rgba(127,127,127,.35);font-weight:600">Total</td>
                        <td style="padding:10px 0 0;border-top:1px solid rgba(127,127,127,.35);text-align:right;font-weight:700;font-size:16px">{{ $money($statement['total_cents']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- MARKER-BILLING-CONTROLS --}}
        @php $charging = $this->chargingState(); @endphp

        <div style="{{ $card }};margin-top:16px">
            <div style="{{ $label }}">Charging</div>

            @unless($charging['master'])
                <div style="background:rgba(240,196,106,.08);border:1px solid rgba(240,196,106,.3);border-radius:8px;padding:10px 12px;font-size:12.5px;line-height:1.55;margin-bottom:12px">
                    Charging is switched off platform-wide, so nothing is charged for any shop whatever this
                    page says. The master switch is on Platform email.
                </div>
            @endunless

            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
                <div style="font-size:13px">
                    This shop: <b>{{ $charging['tenant'] ? 'charging enabled' : 'not charging' }}</b>
                    @if(! $charging['has_card'])
                        <span style="opacity:.6">· no card on file</span>
                    @endif
                </div>
                <x-filament::button size="sm" :color="$charging['tenant'] ? 'gray' : 'primary'" wire:click="toggleCharging">
                    {{ $charging['tenant'] ? 'Stop charging this shop' : 'Enable charging' }}
                </x-filament::button>
            </div>

            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:14px">
                <div>
                    <div style="{{ $label }};margin-bottom:4px">Charge when the balance reaches</div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <span style="opacity:.6">$</span>
                        <input wire:model="thresholdDollars" placeholder="{{ number_format($charging['default'] / 100, 2) }}"
                               class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm" style="width:120px">
                        <x-filament::button size="sm" color="gray" wire:click="saveThreshold">Save</x-filament::button>
                    </div>
                </div>
                <div style="font-size:12px;opacity:.6;padding-bottom:8px">
                    Blank uses the platform default (${{ number_format($charging['default'] / 100, 2) }}).
                    Unbilled now: <b>{{ \App\Filament\Pages\TenantBilling::money($charging['unbilled']) }}</b>.
                </div>
                @if($charging['can_charge'] && $charging['unbilled'] > 0)
                    <x-filament::button size="sm" wire:click="chargeNow"
                        wire:confirm="Charge {{ \App\Filament\Pages\TenantBilling::money($charging['unbilled']) }} to this shop's card now?">
                        Charge now
                    </x-filament::button>
                @endif
            </div>

            @if($charging['paused'])
                <p style="{{ $body }};color:#F0C46A;margin-top:12px">
                    Campaigns are paused for this shop after a failed charge. Receipts and reminders are still
                    sending. Refunding or writing off the failed run, or a successful charge, releases them.
                </p>
            @endif
        </div>

        {{-- MARKER-BILLING-NOTICES — what the shop was told, and what happened after --}}
        <div style="{{ $card }};margin-top:16px">
            <div style="{{ $label }}">Billing notices sent</div>
            @php $notices = $this->notices(); @endphp
            @forelse($notices as $n)
                <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;padding:8px 0;border-top:1px solid rgba(127,127,127,.14);font-size:13px">
                    <span style="min-width:96px;opacity:.6">{{ $n->created_at->format('M j, H:i') }}</span>
                    <span style="font-weight:600;min-width:140px">{{ \App\Models\BillingNoticeTemplate::find($n->event)?->label ?? $n->event }}</span>
                    <span style="opacity:.6">
                        @if($n->alerted) alert @endif
                        @if($n->alerted && $n->emailed) + @endif
                        @if($n->emailed) email to {{ $n->email_to }} @endif
                        @if(! $n->alerted && ! $n->emailed) not delivered @endif
                    </span>
                    <span style="margin-left:auto;opacity:.7">{{ $n->describeOutcome() }}</span>
                </div>
            @empty
                <p style="{{ $body }}">Nothing sent yet. Notices go out when a balance builds with no card, when a charge fails, and as a receipt when one succeeds.</p>
            @endforelse
            <p style="{{ $body }};margin-top:10px;opacity:.55">
                The wording, the channels and how often each one may repeat are on Platform → Billing notices.
            </p>
        </div>

        {{-- MARKER-BILLING-CONTROLS — the runs, and what can be done about them --}}
        <div style="{{ $card }};margin-top:16px">
            <div style="{{ $label }}">Charge runs</div>
            @php $runs = $this->runs(); @endphp

            @forelse($runs as $run)
                <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;padding:9px 0;border-top:1px solid rgba(127,127,127,.14);font-size:13px">
                    <span style="min-width:96px;opacity:.6">{{ $run->created_at->format('M j, H:i') }}</span>
                    <span style="font-weight:600;min-width:78px">{{ \App\Filament\Pages\TenantBilling::money($run->amount_cents) }}</span>
                    <span style="opacity:.6">{{ number_format($run->message_count) }} messages</span>
                    <span style="padding:2px 9px;border-radius:999px;border:1px solid rgba(127,127,127,.3);font-size:11px;text-transform:uppercase;letter-spacing:.04em">
                        {{ $run->describeStatus() }}
                    </span>
                    @if($run->failure_message)
                        <span style="opacity:.6;font-size:12px">{{ \Illuminate\Support\Str::limit($run->failure_message, 60) }}</span>
                    @endif
                    @if($run->resolution_reason)
                        <span style="opacity:.6;font-size:12px">{{ $run->resolution_reason }}</span>
                    @endif

                    <span style="margin-left:auto;display:flex;gap:6px">
                        @if($run->status === 'charged')
                            <x-filament::button size="xs" color="gray" wire:click="startResolve('{{ $run->id }}')">Refund</x-filament::button>
                        @elseif(in_array($run->status, ['pending', 'failed', 'charging']))
                            <x-filament::button size="xs" color="gray" wire:click="startResolve('{{ $run->id }}')">Write off</x-filament::button>
                        @endif
                    </span>
                </div>

                @if($resolvingRunId === $run->id)
                    <div style="padding:12px;border:1px solid rgba(127,127,127,.25);border-radius:8px;margin:8px 0 14px">
                        <div style="{{ $label }};margin-bottom:6px">Why?</div>
                        <input wire:model="resolveReason" placeholder="Comped after the bad send on Sep 2"
                               class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm" style="width:100%;max-width:460px">
                        <p style="{{ $body }};margin-top:6px;font-size:12px">
                            This is recorded against the run and shown on the shop's statement, so a comped
                            month reads as comped rather than as a gap.
                        </p>
                        <div style="display:flex;gap:8px;margin-top:10px">
                            @if($run->status === 'charged')
                                <x-filament::button size="sm" color="danger" wire:click="refundRun">Refund {{ \App\Filament\Pages\TenantBilling::money($run->amount_cents) }}</x-filament::button>
                            @else
                                <x-filament::button size="sm" color="danger" wire:click="writeOffRun">Write off {{ \App\Filament\Pages\TenantBilling::money($run->amount_cents) }}</x-filament::button>
                            @endif
                            <x-filament::button size="sm" color="gray" wire:click="cancelResolve">Cancel</x-filament::button>
                        </div>
                    </div>
                @endif
            @empty
                <p style="{{ $body }}">No charge runs yet.</p>
            @endforelse
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-top:16px">
            <div style="{{ $card }}">
                {{-- MARKER-BILLING-CARD --}}
                <div style="{{ $label }}">Card on file</div>
                @php $cardState = app(\App\Services\Billing\BillingCardService::class)->cardState($tenant); @endphp
                @if($cardState['has_card'])
                    <p style="{{ $body }}">
                        {{ strtoupper($cardState['brand'] ?? 'Card') }} ···· {{ $cardState['last4'] }}
                        @if($cardState['expires']) · expires {{ $cardState['expires'] }} @endif
                    </p>
                    @if($cardState['expiring'])
                        <p style="{{ $body }};color:#F0C46A;margin-top:6px">Expires soon — a charge would fail.</p>
                    @endif
                @else
                    <p style="{{ $body }}">No card saved. Usage accrues but cannot be settled.</p>
                @endif
            </div>

            <div style="{{ $card }}">
                <div style="{{ $label }}">Text messages</div>
                <p style="{{ $body }}">{{ $this->smsOwnership() }}</p>
                <p style="{{ $body }};margin-top:8px;opacity:.55">
                    A shop on its own Twilio pays the carrier directly, so their segments meter at zero and
                    show on their statement as such rather than being left off it.
                </p>
            </div>

            <div style="{{ $card }}">
                <div style="{{ $label }}">Discounts</div>
                @forelse($discounts as $d)
                    <div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.12);font-size:13px;flex-wrap:wrap">
                        <span>{{ $d->reason }}</span>
                        <span style="opacity:.55">{{ $d->describeAmount() }}</span>
                        <span style="margin-left:auto;opacity:.55;font-size:12px">{{ $d->describeWindow() }}</span>
                    </div>
                @empty
                    <p style="{{ $body }}">No discounts — this shop pays list price.</p>
                @endforelse
                <a href="{{ \App\Filament\Resources\TenantBillingDiscountResource::getUrl('index') }}"
                   style="display:inline-block;margin-top:10px;font-size:12.5px;text-decoration:underline"
                   class="text-primary-600">Manage discounts</a>
            </div>
        </div>
    @elseif($tenant)
        <div style="{{ $card }};margin-top:16px">
            <p style="{{ $body }}">No statement could be built for that month.</p>
        </div>
    @endif

</x-filament-panels::page>
