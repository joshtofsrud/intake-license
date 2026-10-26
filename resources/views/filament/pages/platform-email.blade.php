<x-filament-panels::page>

    @php
        $effective    = \App\Models\PlatformSettings::fromAddress();
        $effectiveNm  = \App\Models\PlatformSettings::fromName();
        $isPlaceholder = \App\Models\PlatformSettings::isPlaceholder();
    @endphp

    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
                background: {{ $isPlaceholder ? '#FAEEDA' : '#E1F5EE' }};
                border: 1px solid {{ $isPlaceholder ? '#FAC775' : '#9FE1CB' }};
                color: {{ $isPlaceholder ? '#633806' : '#085041' }};">
        <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;">
            @if($isPlaceholder)
                ⚠ No platform sender configured — mail is going out as hello@example.com
            @else
                ● Sending as {{ $effectiveNm ? $effectiveNm . ' <' . $effective . '>' : $effective }}
            @endif
        </div>
        <div style="font-size: 12px;">
            @if($isPlaceholder)
                Laravel falls back to its framework default when no sender is set here or in the environment.
            @else
                Applies to platform mail that does not set its own sender. Tenant email is unaffected.
            @endif
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px; display: flex; gap: 8px;">
            <x-filament::button type="submit">
                Save sender
            </x-filament::button>

            <x-filament::button
                wire:click="sendTest"
                color="gray"
                icon="heroicon-o-paper-airplane">
                Save and send test
            </x-filament::button>
        </div>
    </form>

    {{-- MARKER-POSTMARK-INACTIVE --}}
    @php
        $pmStreams = \App\Services\PostmarkInactive::streams();
        $pmStatus  = \App\Services\PostmarkInactive::status();
    @endphp
    <div style="margin-top:28px;padding-top:18px;border-top:1px solid rgba(127,127,127,.2)">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div style="font-weight:600;font-size:15px">Postmark's inactive list</div>
            <x-filament::button size="sm" color="gray" wire:click="syncInactive" icon="heroicon-o-arrow-path">Sync now</x-filament::button>
        </div>
        <div style="font-size:12.5px;opacity:.7;margin:6px 0 10px;line-height:1.55">
            Postmark stops delivering to an address after a hard bounce, a spam complaint or an unsubscribe on its side, and when Intake sends to one anyway it refuses it <b>without telling Intake</b>.
            So Intake copies the list every hour and before each campaign starts sending: shop campaigns, Intake campaigns and Email outreach skip those addresses and say why.
            Receipts and other transactional mail are not affected. An address Postmark reactivates comes off the list at the next sync.
        </div>
        @forelse ($pmStreams as $sid => $slabel)
            @php $ps = $pmStatus[$sid] ?? []; @endphp
            <div style="font-size:13px;padding:6px 0;display:flex;gap:10px;flex-wrap:wrap">
                <span style="min-width:200px">{{ $slabel }} <span style="opacity:.6">({{ $sid }})</span></span>
                @if (! empty($ps['at']))
                    <span><b>{{ number_format($ps['count'] ?? 0) }}</b> inactive · synced {{ \Carbon\Carbon::parse($ps['at'])->diffForHumans() }}</span>
                @else
                    <span style="opacity:.7">Not synced yet</span>
                @endif
                @if (! empty($ps['error']))
                    <span style="color:#dc2626">Last try failed: {{ $ps['error'] }}</span>
                @endif
            </div>
        @empty
            <div style="font-size:13px;opacity:.7">No marketing stream is set above, so there's nothing to sync.</div>
        @endforelse
    </div>

</x-filament-panels::page>
