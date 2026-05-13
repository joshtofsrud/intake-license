<x-filament-panels::page>

    @php
        $dirty = $this->dirtyCount;
        $audits = $this->recentAudits;
    @endphp

    {{-- STICKY_BANNER_V1 · status + Publish/Revert always visible --}}
    <div style="position: sticky; top: 0; z-index: 30;
                background: {{ $dirty > 0 ? 'rgba(244,184,96,.10)' : 'rgba(90,168,224,.08)' }};
                border: 1px solid {{ $dirty > 0 ? 'rgba(244,184,96,.40)' : 'rgba(90,168,224,.25)' }};
                border-radius: 10px;
                padding: 12px 18px;
                margin-bottom: 18px;
                backdrop-filter: blur(8px);
                display: flex; align-items: center; justify-content: space-between;
                gap: 16px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 240px;">
            @if($dirty > 0)
                <div style="font-weight: 600; font-size: 14px;">
                    ⚠ Draft mode · {{ $dirty }} unpublished {{ $dirty === 1 ? 'change' : 'changes' }}
                </div>
                <div style="font-size: 12.5px; opacity: .7; margin-top: 2px;">
                    Tenants still see published values until you click Publish.
                </div>
            @else
                <div style="font-size: 13px; font-weight: 500;">
                    ● All changes published.
                    <span style="opacity: .65; font-weight: 400;">
                        Edit any value below to start a new draft.
                    </span>
                </div>
            @endif
        </div>
        <div style="display: flex; gap: 8px; flex-shrink: 0;">
            <x-filament::button
                wire:click="revert"
                color="gray"
                size="sm"
                :disabled="$dirty === 0">
                Revert
            </x-filament::button>
            <x-filament::button
                wire:click="publish"
                size="sm"
                :disabled="$dirty === 0">
                @if($dirty > 0)
                    Publish {{ $dirty }} {{ $dirty === 1 ? 'change' : 'changes' }}
                @else
                    Publish
                @endif
            </x-filament::button>
        </div>
    </div>

    {{-- Split: editor (left) + live preview (right) --}}
    <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 24px; align-items: start;">

        {{-- ─── Editor side ─── --}}
        <div>
            <form>{{ $this->form }}</form>


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
