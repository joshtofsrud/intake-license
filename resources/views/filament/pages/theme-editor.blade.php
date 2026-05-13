<x-filament-panels::page>

    @php
        $dirty = $this->dirtyCount;
        $audits = $this->recentAudits;
    @endphp

    {{-- Top status banner --}}
    @if($dirty > 0)
        <div style="padding: 14px 18px; border-radius: 10px; margin-bottom: 18px;
                    background: rgba(244,184,96,.10); border: 1px solid rgba(244,184,96,.35);">
            <div style="font-weight: 600; font-size: 14px; margin-bottom: 2px;">
                ⚠ Draft mode · {{ $dirty }} unpublished {{ $dirty === 1 ? 'change' : 'changes' }}
            </div>
            <div style="font-size: 12.5px; opacity: .7;">
                Tenants still see the previously published values until you click <strong>Publish</strong>.
            </div>
        </div>
    @else
        <div style="padding: 12px 18px; border-radius: 10px; margin-bottom: 18px;
                    background: rgba(90,168,224,.08); border: 1px solid rgba(90,168,224,.25);
                    font-size: 13px;">
            ● All changes published. Edit any value below to start a new draft.
        </div>
    @endif

    {{-- Split: editor (left) + live preview (right) --}}
    <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 24px; align-items: start;">

        {{-- ─── Editor side ─── --}}
        <div>
            <form>{{ $this->form }}</form>

            <div style="margin-top: 18px; display: flex; gap: 8px;">
                <x-filament::button wire:click="publish" :disabled="$dirty === 0">
                    @if($dirty > 0)
                        Publish {{ $dirty }} {{ $dirty === 1 ? 'change' : 'changes' }}
                    @else
                        Publish
                    @endif
                </x-filament::button>

                <x-filament::button wire:click="revert" color="gray" :disabled="$dirty === 0">
                    Revert
                </x-filament::button>
            </div>
        </div>

        {{-- ─── Live preview ─── --}}
        <div style="position: sticky; top: 24px;">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .14em;
                        font-weight: 600; opacity: .6; margin-bottom: 10px;
                        display: flex; justify-content: space-between;">
                <span>Live preview · Light theme</span>
                @if($dirty > 0)<span style="color: #B45309;">Showing draft</span>@endif
            </div>

            {{-- Light theme preview --}}
            @include('filament.pages._theme-preview', ['theme' => 'b', 'tokens' => $this->data['b'] ?? []])

            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .14em;
                        font-weight: 600; opacity: .6; margin: 18px 0 10px;
                        display: flex; justify-content: space-between;">
                <span>Live preview · Dark theme</span>
                @if($dirty > 0)<span style="color: #B45309;">Showing draft</span>@endif
            </div>

            {{-- Dark theme preview --}}
            @include('filament.pages._theme-preview', ['theme' => 'c', 'tokens' => $this->data['c'] ?? []])
        </div>
    </div>

    {{-- Audit log --}}
    @if($audits->isNotEmpty())
        <div style="margin-top: 36px;">
            <div style="font-size: 13px; font-weight: 600; margin-bottom: 10px;">
                Recent changes
            </div>
            <div style="border: 1px solid rgba(0,0,0,.08); border-radius: 10px; overflow: hidden;">
                @foreach($audits as $audit)
                    <div style="display: grid; grid-template-columns: 110px 1fr auto;
                                gap: 14px; padding: 10px 16px;
                                border-bottom: 1px solid rgba(0,0,0,.06);
                                font-size: 12.5px; align-items: center;">
                        <div style="opacity: .55; font-variant-numeric: tabular-nums;">
                            {{ $audit->created_at->diffForHumans() }}
                        </div>
                        <div>
                            Theme {{ strtoupper($audit->theme) }} ·
                            <code style="font-size: 11px; padding: 1px 5px; background: rgba(0,0,0,.05); border-radius: 3px;">--{{ $audit->token_key }}</code>
                            @if($audit->old_value !== null)
                                <span style="opacity: .6;">from</span>
                                <code style="font-size: 11px; padding: 1px 5px; background: rgba(0,0,0,.05); border-radius: 3px;">{{ $audit->old_value }}</code>
                                <span style="opacity: .6;">to</span>
                            @endif
                            <code style="font-size: 11px; padding: 1px 5px; background: rgba(0,0,0,.05); border-radius: 3px;">{{ $audit->new_value }}</code>
                        </div>
                        <div style="opacity: .55; font-size: 11.5px;">
                            User #{{ $audit->user_id ?? '?' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</x-filament-panels::page>
