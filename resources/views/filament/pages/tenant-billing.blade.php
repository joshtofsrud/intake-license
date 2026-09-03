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

    @if($tenant && $statement)
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
            <table style="width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums">
                <tbody>
                    <tr>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14)">
                            {{ $statement['plan']['label'] }}
                            <span style="opacity:.55">· {{ $statement['plan']['locations'] }} {{ \Illuminate\Support\Str::plural('location', $statement['plan']['locations']) }}</span>
                        </td>
                        <td style="padding:7px 0;border-top:1px solid rgba(127,127,127,.14);text-align:right">{{ $money($statement['plan']['cents']) }}</td>
                    </tr>
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

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-top:16px">
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
