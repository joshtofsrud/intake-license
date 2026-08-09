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
            {{-- MARKER-PAGE-FOLLOWS-CODE --}}
            {{ $ok ? '● ' . $conn->distributor_code . ' connected' : '○ ' . $conn->distributor_code . ' not verified' }}
        </div>
        <div style="font-size:12px;opacity:.8">
            @if($tested)
                Last tested {{ $tested->diffForHumans() }} — {{ $conn->last_test_message }}
            @else
                Enter {{ $conn->distributor_code }}'s credentials and click “Test connection”.
            @endif
        </div>
    </div>

    {{-- connection form --}}
    {{ $this->form }}

    {{-- sync status --}}
    <x-filament::section heading="Catalog sync">
        <x-slot name="description">Tier-1: pulls the shared catalog through the field map. Cost is nulled here (per-tenant). Use the header buttons to queue a run.
            @if (strtoupper($conn->distributor_code) === 'BTI')
                <br><b>{{ $conn->distributor_code }} is a bulk file feed</b> — every run downloads the whole
                catalog, so there is no delta.
            @endif
        </x-slot>
        @php
            $running = ($state->last_status ?? '') === 'running'
                && $state->last_run_at
                && \Illuminate\Support\Carbon::parse($state->last_run_at)->gt(now()->subMinutes(30));
        @endphp
        <div @if($running) wire:poll.2000ms @endif>
            @if($running)
                <div style="display:flex;align-items:center;gap:10px;padding:11px 15px;border-radius:8px;margin-bottom:14px;background:rgba(190,242,100,.12);border:1px solid rgba(190,242,100,.3)">
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#BEF264;animation:dcpulse 1s infinite"></span>
                    <span style="font-size:14px;font-weight:600">Syncing… {{ number_format($state->last_count ?? 0) }} written</span>
                    <span style="font-size:12px;opacity:.55">updating live</span>
                </div>
                <style>@keyframes dcpulse{0%,100%{opacity:1}50%{opacity:.25}}</style>
            @endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
                <div><div style="font-size:11px;text-transform:uppercase;opacity:.6">Last run</div>
                    <div style="font-size:18px;font-weight:700">{{ $state?->last_run_at ? \Illuminate\Support\Carbon::parse($state->last_run_at)->diffForHumans() : '—' }}</div></div>
                <div><div style="font-size:11px;text-transform:uppercase;opacity:.6">Written</div>
                    <div style="font-size:18px;font-weight:700">{{ number_format($state?->last_count ?? 0) }}</div></div>
                <div><div style="font-size:11px;text-transform:uppercase;opacity:.6">Status</div>
                    <div style="font-size:18px;font-weight:700">{{ $state?->last_status ?? '—' }}</div></div>
            </div>

            @if(!empty($brandStatuses) && count($brandStatuses))
                <div style="margin-top:18px;font-size:11px;text-transform:uppercase;opacity:.6;margin-bottom:6px">Per-brand progress</div>
                <div style="max-height:340px;overflow:auto;border:1px solid rgba(255,255,255,.1);border-radius:8px">
                    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
                        @foreach($brandStatuses as $b)
                            @php /* MARKER-BRAND-TOTALS — skipped-unchanged is part of the truth */ $done = $b->status === 'done'; $sync = $b->status === 'syncing'; $bSkipped = (int) ($b->skipped ?? 0); @endphp
                            <tr style="border-bottom:.5px solid rgba(255,255,255,.07)">
                                <td style="padding:7px 12px;font-weight:600">{{ $b->brand_name }}</td>
                                <td style="padding:7px 12px;font-family:ui-monospace,monospace;opacity:.85">{{ number_format($b->written) }} / {{ number_format($b->total) }} @if($bSkipped > 0)<span style="opacity:.55">&middot; {{ number_format($bSkipped) }} unchanged</span> @endif</td>
                                <td style="padding:7px 12px;text-align:right">
                                    @if($done)
                                        <span style="color:#BEF264">✓ done</span>
                                    @elseif($sync)
                                        <span style="color:#BEF264">● syncing</span>
                                    @elseif($b->status === 'fresh')
                                        <span style="color:#BEF264;opacity:.8">✓ up to date</span>
                                    @elseif($b->status === 'failed')
                                        <span style="color:#E24B4A">✕ failed</span>
                                    @elseif($b->status === 'empty')
                                        <span style="opacity:.4">&mdash; empty</span>
                                    @else
                                        <span style="opacity:.5">pending</span>
                                    @endif
                                </td>
                                {{-- MARKER-BRAND-SYNC — inline single-brand refresh --}}
                                <td style="padding:7px 12px;text-align:right">
                                    <button type="button" wire:click="syncBrand(@js($b->brand_name))" wire:loading.attr="disabled"
                                            style="font-size:11px;padding:3px 10px;border:.5px solid rgba(255,255,255,.2);border-radius:6px;background:transparent;color:#BEF264;cursor:pointer">Sync</button>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>
        @if($state?->last_error)
<div style="margin-top:10px;font-size:12px;color:#E24B4A">{{ \Illuminate\Support\Str::limit($state->last_error, 160) }}</div>
        @endif
    </x-filament::section>

    {{-- MARKER-PAGE-FOLLOWS-CODE — the mapping test runs hardcoded HLC variant
         shapes through the resolver, so it can say nothing about another
         distributor. Hidden rather than shown with data that cannot apply. --}}
    @if (strtoupper($conn->distributor_code) === 'HLC')
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

    @endif

</x-filament-panels::page>
