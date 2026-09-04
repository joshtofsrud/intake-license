{{-- MARKER-TASK-HEALTH --}}
@php
    $tasks   = $this->tasks();
    $summary = $this->summary();

    $card  = 'border-radius:12px;padding:14px 18px;border:1px solid rgba(127,127,127,.22)';
    $label = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55';
    $body  = 'font-size:13px;line-height:1.55;opacity:.75;margin:0';
@endphp

<x-filament-panels::page>

    <div style="{{ $card }}">
        <div style="{{ $label }};margin-bottom:8px">What this is</div>
        <p style="{{ $body }}">
            Every scheduled job records when it ran and whether it worked. Anything failing or overdue is at
            the top. A task counts as overdue when it has not run in about three times its usual gap, so a
            busy minute does not read as a failure.
        </p>
        @if($summary['total'] === 0)
            <p style="{{ $body }};margin-top:8px;color:#F0C46A">
                Nothing recorded yet. Runs are written as they happen, so this fills in over the first hour —
                there is no history to backfill.
            </p>
        @endif
    </div>

    @if($summary['total'] > 0)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:16px">
            <div style="{{ $card }}">
                <div style="{{ $label }}">Tasks seen</div>
                <div style="font-size:24px;font-weight:700">{{ $summary['total'] }}</div>
            </div>
            <div style="{{ $card }}">
                <div style="{{ $label }}">Failing</div>
                <div style="font-size:24px;font-weight:700;{{ $summary['failing'] ? 'color:#F08A8A' : '' }}">
                    {{ $summary['failing'] }}
                </div>
            </div>
            <div style="{{ $card }}">
                <div style="{{ $label }}">Overdue</div>
                <div style="font-size:24px;font-weight:700;{{ $summary['overdue'] ? 'color:#F0C46A' : '' }}">
                    {{ $summary['overdue'] }}
                </div>
            </div>
        </div>

        <div style="{{ $card }};margin-top:16px">
            <div style="{{ $label }};margin-bottom:10px">Every task</div>

            @foreach($tasks as $t)
                <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;padding:9px 0;border-top:1px solid rgba(127,127,127,.14)">
                    <span style="min-width:16px">
                        @if($t['running'])
                            <span title="Running now" style="color:#8b7cf6">●</span>
                        @elseif(! $t['ok'])
                            <span title="Failed" style="color:#F08A8A">●</span>
                        @elseif($t['overdue'])
                            <span title="Overdue" style="color:#F0C46A">●</span>
                        @else
                            <span title="Healthy" style="color:#7FD98F">●</span>
                        @endif
                    </span>

                    <code style="min-width:230px;font-size:12.5px">{{ $t['command'] }}</code>

                    <span style="font-size:12.5px;opacity:.6;min-width:150px">
                        @if($t['last'])
                            {{ $t['last']->diffForHumans() }}
                            @if($t['manual']) <span style="opacity:.7">· by hand</span> @endif
                        @else
                            never
                        @endif
                    </span>

                    <span style="font-size:12.5px;opacity:.5;min-width:60px">{{ $t['duration'] }}</span>

                    @if($t['failure'])
                        <span style="font-size:12px;color:#F08A8A;flex:1;min-width:200px">
                            {{ \Illuminate\Support\Str::limit($t['failure'], 90) }}
                        </span>
                    @elseif($t['note'])
                        <span style="font-size:12px;opacity:.45;flex:1;min-width:200px">{{ $t['note'] }}</span>
                    @endif

                    <span style="margin-left:auto">
                        @if($t['risky'])
                            <x-filament::button size="xs" color="gray"
                                wire:click="runNow('{{ $t['command'] }}')"
                                wire:confirm="{{ $t['note'] }} Run it now?">
                                Run now
                            </x-filament::button>
                        @else
                            <x-filament::button size="xs" color="gray" wire:click="runNow('{{ $t['command'] }}')">
                                Run now
                            </x-filament::button>
                        @endif
                    </span>
                </div>
            @endforeach

            <p style="{{ $body }};margin-top:12px;opacity:.5">
                Running by hand is recorded as such. Anything that sends, charges or deletes asks first and
                says what it will do.
            </p>
        </div>
    @endif

</x-filament-panels::page>
