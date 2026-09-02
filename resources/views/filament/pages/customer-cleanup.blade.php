{{-- MARKER-CUST-CLEANUP --}}
@php
    $tenant  = $this->tenant();
    $summary = $this->summary();
    $rows    = $this->rows();
    $open    = $this->windowOpen();

    $card  = 'border-radius:12px;padding:14px 16px;border:1px solid rgba(127,127,127,.22)';
    $label = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:8px';
    $body  = 'font-size:13px;line-height:1.55;opacity:.75;margin:0';
@endphp

<x-filament-panels::page>

    <div style="{{ $card }}">
        <div style="{{ $label }}">What this is</div>
        <p style="{{ $body }}">
            A sweep of one shop's customer list for rows that are not real people — bad addresses, department
            mailboxes, throwaway inboxes, test rows, duplicates. Every group below is a judgement, not a fact,
            so nothing is removed automatically. <b>Removing marketing permission</b> is the safe action: it stops
            the junk being emailed and billed without touching any history. <b>Removal</b> follows the shop's own
            rules — it deletes only when nothing references the customer, otherwise it erases the personal
            details and keeps the row so sales and bookings survive.
        </p>
    </div>

    <div style="{{ $card }};margin-top:16px">
        <div style="{{ $label }}">Shop</div>
        <select wire:model.live="tenantId" class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm" style="width:100%;max-width:420px">
            <option value="">Choose a shop…</option>
            @foreach($this->tenants() as $t)
                <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->subdomain }})</option>
            @endforeach
        </select>

        @if($tenant)
            <div style="margin-top:12px;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                @if($open)
                    <span class="text-success-600" style="font-weight:600">Customer admin mode is open</span>
                    <span style="opacity:.6">— removal is available.</span>
                @else
                    <span style="opacity:.75">Customer admin mode is closed, so removal is unavailable.</span>
                    <x-filament::button size="sm" color="gray" wire:click="openWindow">Open a 10-day window</x-filament::button>
                @endif
            </div>
        @endif
    </div>

    @if($tenant)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px">
            @foreach($summary as $key => $g)
                <button type="button" wire:click="selectGroup('{{ $key }}')"
                        style="{{ $card }};text-align:left;cursor:pointer;
                        {{ $group === $key ? 'border-color:rgb(var(--primary-500));background:rgba(var(--primary-500),.06)' : '' }}">
                    <div style="display:flex;align-items:baseline;gap:8px">
                        <span style="font-size:22px;font-weight:700">{{ number_format($g['count']) }}</span>
                        <span style="font-size:13px;font-weight:600">{{ $g['label'] }}</span>
                    </div>
                    <div style="font-size:11.5px;opacity:.6;margin-top:4px;line-height:1.45">{{ $g['why'] }}</div>
                    @unless($g['confident'])
                        <div style="font-size:10.5px;opacity:.5;margin-top:6px;text-transform:uppercase;letter-spacing:.05em">Check before acting</div>
                    @endunless
                </button>
            @endforeach
        </div>

        <div style="{{ $card }};margin-top:16px">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                <div style="{{ $label }};margin:0">{{ $summary[$group]['label'] ?? 'Rows' }}</div>
                <div style="margin-left:auto;display:flex;gap:8px">
                    <x-filament::button size="sm" color="gray" wire:click="optOutGroup"
                        wire:confirm="Remove marketing permission from everyone in this group? Their records are untouched.">
                        Remove marketing permission from all
                    </x-filament::button>
                </div>
            </div>

            @if($rows)
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="text-align:left;opacity:.5;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
                            <th style="padding:6px 8px 6px 0">Name</th>
                            <th style="padding:6px 8px">Email</th>
                            <th style="padding:6px 8px">Added</th>
                            <th style="padding:6px 8px">Mailable</th>
                            <th style="padding:6px 8px">Removal would</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $r)
                        <tr style="border-top:1px solid rgba(127,127,127,.15)">
                            <td style="padding:7px 8px 7px 0">{{ $r['name'] }}</td>
                            <td style="padding:7px 8px;font-family:ui-monospace,monospace;font-size:12px">{{ $r['email'] ?: '—' }}</td>
                            <td style="padding:7px 8px;opacity:.6">{{ $r['added'] ? \Carbon\Carbon::parse($r['added'])->format('M j, Y') : '—' }}</td>
                            <td style="padding:7px 8px">{{ $r['mailable'] ? 'yes' : 'no' }}</td>
                            <td style="padding:7px 8px">
                                @if($r['mode'] === 'delete')
                                    <span style="opacity:.75">delete the row</span>
                                @else
                                    <span style="opacity:.75">erase details, keep row</span>
                                    <span style="opacity:.5;font-size:11.5px">({{ collect($r['links'])->map(fn($n,$k) => $n . ' ' . $k)->implode(', ') }})</span>
                                @endif
                            </td>
                            <td style="padding:7px 0;text-align:right">
                                @if($open)
                                    <x-filament::button size="xs" color="danger" wire:click="removeOne('{{ $r['id'] }}')"
                                        wire:confirm="{{ $r['mode'] === 'delete' ? 'Delete this customer? Nothing references them.' : 'Erase this customer\'s details? Their sales and bookings stay.' }}">
                                        Remove
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if(count($rows) >= 200)
                    <div style="font-size:12px;opacity:.6;margin-top:10px">Showing the first 200. Act on these and re-run.</div>
                @endif
            @else
                <p style="{{ $body }}">Nothing in this group — good news.</p>
            @endif
        </div>
    @endif

</x-filament-panels::page>
