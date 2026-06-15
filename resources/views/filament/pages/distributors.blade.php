{{-- MARKER-PATCH-HLC6 --}}
<x-filament-panels::page>

    @php
        $tested = $conn->last_tested_at;
        $ok = $conn->last_test_status === 'ok';
    @endphp

    {{-- status banner --}}
    <div style="padding:12px 16px;border-radius:8px;
                background:{{ $ok ? 'rgba(99,153,34,.15)' : 'rgba(186,117,23,.15)' }};
                border:1px solid {{ $ok ? 'rgba(99,153,34,.4)' : 'rgba(186,117,23,.4)' }};">
        <div style="font-weight:600;font-size:14px">
            {{ $ok ? '● HLC connected' : '○ HLC not verified' }}
        </div>
        <div style="font-size:12px;opacity:.8">
            @if($tested)
                Last tested {{ $tested->diffForHumans() }} — {{ $conn->last_test_message }}
            @else
                Enter the platform key and click “Test connection”.
            @endif
        </div>
    </div>

    {{-- connection form --}}
    {{ $this->form }}

    {{-- sync status --}}
    <x-filament::section heading="Catalog sync">
        <x-slot name="description">Tier-1: pulls the shared catalog through the field map. Cost is nulled here (per-tenant). Use the header buttons to queue a run.</x-slot>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
            <div><div style="font-size:11px;text-transform:uppercase;opacity:.6">Last run</div>
                <div style="font-size:18px;font-weight:700">{{ $state?->last_run_at ? \Illuminate\Support\Carbon::parse($state->last_run_at)->diffForHumans() : '—' }}</div></div>
            <div><div style="font-size:11px;text-transform:uppercase;opacity:.6">Written</div>
                <div style="font-size:18px;font-weight:700">{{ $state?->last_count ?? '—' }}</div></div>
            <div><div style="font-size:11px;text-transform:uppercase;opacity:.6">Status</div>
                <div style="font-size:18px;font-weight:700">{{ $state?->last_status ?? '—' }}</div></div>
        </div>
        @if($state?->last_error)
            <div style="margin-top:10px;font-size:12px;color:#E24B4A">{{ \Illuminate\Support\Str::limit($state->last_error, 160) }}</div>
        @endif
    </x-filament::section>

    {{-- live mapping test --}}
    <x-filament::section heading="Test mapping">
        <x-slot name="description">Run a real HLC variant through the current field map. Edit a map row, come back, and the output follows.</x-slot>

        <select wire:model.live="testSample"
                style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:8px 11px;color:inherit;font-size:13px;margin-bottom:14px;min-width:340px">
            @foreach($sampleOptions as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>

        @php $r = $this->resolvedSample(); @endphp
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div>
                <div style="font-size:11px;text-transform:uppercase;opacity:.6;margin-bottom:6px">Source variant (HLC)</div>
                <pre style="background:#0a0a0a;border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:12px;font-size:11.5px;line-height:1.6;overflow:auto;max-height:420px">{{ json_encode($r['sample']['variant'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div>
                <div style="font-size:11px;text-transform:uppercase;opacity:.6;margin-bottom:6px">Canonical Intake row (resolved)</div>
                <pre style="background:#0a0a0a;border:1px solid rgba(190,242,100,.25);border-radius:8px;padding:12px;font-size:11.5px;line-height:1.6;overflow:auto;max-height:420px;color:#cfe6ab">{{ json_encode($r['canonical'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    </x-filament::section>

</x-filament-panels::page>
