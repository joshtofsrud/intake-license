{{-- MARKER-DEMO-ENTRY · MARKER-DEMO-WEEKCARDS · MARKER-DEMO-PAGEALIGN
     One card shape throughout: same padding, same heading, body grows, actions
     pinned to the bottom so paired cards line up whatever their text length. --}}
@php
    $s       = $this->state();
    $weeks   = $this->weekOptions();
    $sup     = $this->suppressed();
    $tz      = config('app.timezone');
    $busiest = collect($weeks)->keys()->first();

    $card   = 'display:flex;flex-direction:column;height:100%;border-radius:12px;padding:16px 18px;border:1px solid rgba(127,127,127,.22)';
    $label  = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:8px';
    $body   = 'font-size:13.5px;line-height:1.6;opacity:.75;margin:0';
    $actions = 'margin-top:auto;padding-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center';
@endphp

<x-filament-panels::page>

    <div style="{{ $card }}">
        <div style="{{ $label }}">What this is</div>
        <p style="{{ $body }}">
            <b>Intake Bike Works</b> is a copy of a real shop with every person anonymised, wearing the Intake wordmark.
            Anyone can walk in at <a href="{{ $s['entry_url'] }}" class="text-primary-600 underline">{{ $s['entry_url'] }}</a>
            with no account. Emails and texts never actually send there, and the whole thing goes back to its frozen state
            every hour. Rebuilding the template — a fresh copy from the live shop — runs on the server, not here:
            <code style="font-size:12.5px">sudo -u www-data php artisan demo:build-template --from=grndctrl</code>
        </p>
    </div>

    {{-- three stats, equal height, same baseline --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:16px">
        <div style="{{ $card }}">
            <div style="{{ $label }}">Template</div>
            @if($s['built_at'])
                <div style="font-size:15px;font-weight:600">Built {{ \Carbon\Carbon::parse($s['built_at'])->diffForHumans() }}</div>
                <div style="{{ $body }};margin-top:2px">{{ number_format($s['rows']) }} rows · {{ $s['tables'] }} tables</div>
                @if(\Carbon\Carbon::parse($s['built_at'])->lt(now()->subDays(60)))
                    <div style="font-size:12px;margin-top:6px" class="text-warning-600">Over two months old — a rebuild would pick up newer work.</div>
                @endif
            @else
                <div style="font-size:15px;font-weight:600" class="text-danger-600">No frozen template</div>
                <div style="{{ $body }};margin-top:2px">Build one on the server first.</div>
            @endif
        </div>

        <div style="{{ $card }}">
            <div style="{{ $label }}">Resets</div>
            @if($s['last_reset'])
                <div style="font-size:15px;font-weight:600">Last {{ \Carbon\Carbon::parse($s['last_reset'])->diffForHumans() }}</div>
                <div style="{{ $body }};margin-top:2px">Next at the top of the hour · dates shifted {{ (int) $s['shift_days'] }} days</div>
            @else
                <div style="font-size:15px;font-weight:600">Never reset yet</div>
                <div style="{{ $body }};margin-top:2px">The hourly job takes over once the template is in place.</div>
            @endif
            @if($s['paused_until'])
                <div style="font-size:12px;margin-top:6px" class="text-warning-600">
                    Paused until {{ \Carbon\Carbon::parse($s['paused_until'])->setTimezone($tz)->format('g:i a') }}
                </div>
            @endif
        </div>

        <div style="{{ $card }}">
            <div style="{{ $label }}">Visitors</div>
            {{-- MARKER-DEMO-COUNTS — people first; raw hits are the footnote. --}}
            <div style="font-size:15px;font-weight:600">
                {{ number_format($s['people']) }} {{ \Illuminate\Support\Str::plural('person', $s['people']) }}
            </div>
            <div style="font-size:12px;opacity:.6;margin-top:2px;line-height:1.5">
                {{ number_format($s['entries']) }} {{ \Illuminate\Support\Str::plural('entry', $s['entries']) }} since launch, repeats included
                @if($s['bot_entries'] > 0)
                    · {{ number_format($s['bot_entries']) }} from crawlers
                @endif
            </div>
            <div style="{{ $body }};margin-top:2px">
                @if($s['last_entry']) Last {{ \Carbon\Carbon::parse($s['last_entry'])->diffForHumans() }} @else Nobody has walked in yet @endif
            </div>
            <p style="font-size:11.5px;opacity:.5;line-height:1.5;margin-top:8px">
                Marketing Traffic counts the same entries over a rolling window and drops crawlers, so its
                figure is smaller. Entries from before conversion tracking was fixed raised this counter
                without leaving a row there, and that gap will not close.
            </p>
        </div>
    </div>

    {{-- week picker --}}
    <div style="{{ $card }};margin-top:16px">
        <div style="{{ $label }}">Which week the demo sits in</div>
        <p style="{{ $body }};margin-bottom:12px">
            Every reset moves the whole timeline by a whole number of weeks so the week you pick lands on this week —
            a busy Tuesday stays a busy Tuesday. Everything else moves with it, so you are choosing the shape of the
            whole demo, not just one week.
        </p>
        @if($weeks)
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px">
                @foreach($weeks as $monday => $count)
                    @php $on = $anchorWeek === $monday; @endphp
                    <label style="cursor:pointer;display:block;border-radius:10px;padding:10px 12px;
                        border:1px solid {{ $on ? 'rgb(var(--primary-500))' : 'rgba(127,127,127,.22)' }};
                        background:{{ $on ? 'rgba(var(--primary-500),.08)' : 'transparent' }}">
                        <input type="radio" wire:model.live="anchorWeek" value="{{ $monday }}" class="sr-only">
                        <div style="display:flex;align-items:baseline;gap:6px">
                            <span style="font-weight:600;font-size:13.5px">{{ \Carbon\Carbon::parse($monday)->format('M j') }}</span>
                            <span style="font-size:11px;opacity:.5">{{ \Carbon\Carbon::parse($monday)->format('Y') }}</span>
                            @if($monday === $busiest)
                                <span style="margin-left:auto;font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;opacity:.55">Busiest</span>
                            @elseif($on)
                                <span style="margin-left:auto;font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase" class="text-primary-600">Anchor</span>
                            @endif
                        </div>
                        <div style="font-size:12px;opacity:.6;margin-top:3px">{{ $count }} appointments, sales &amp; deliveries</div>
                    </label>
                @endforeach
            </div>
            <div style="{{ $actions }}">
                <x-filament::button wire:click="saveAnchor" size="sm">Save anchor week</x-filament::button>
                <x-filament::button wire:click="resetNow" color="gray" size="sm">Reset now</x-filament::button>
                @if($anchorWeek)
                    <span style="font-size:12px;opacity:.6">
                        Week of {{ \Carbon\Carbon::parse($anchorWeek)->format('M j, Y') }} becomes this week
                        ({{ (int) \Carbon\Carbon::parse($anchorWeek)->startOfWeek()->diffInDays(now()->startOfWeek(), false) }} days forward).
                    </span>
                @endif
            </div>
        @else
            <div style="{{ $body }}">No week data — rebuild the template to record it.</div>
        @endif
    </div>

    {{-- paired cards: equal height, buttons on the same line --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px">
        <div style="{{ $card }}">
            <div style="{{ $label }}">While you are showing it</div>
            <p style="{{ $body }}">
                Pausing stops the top-of-hour wipe for sixty minutes, so a walkthrough at :58 does not vanish mid-sentence.
                Resets pick up again on their own afterwards.
            </p>
            <div style="{{ $actions }}">
                @if($s['paused_until'])
                    <x-filament::button wire:click="resumeResets" color="gray" size="sm">Resume hourly resets</x-filament::button>
                    <span style="font-size:12px;opacity:.6">Paused until {{ \Carbon\Carbon::parse($s['paused_until'])->setTimezone($tz)->format('g:i a') }}</span>
                @else
                    <x-filament::button wire:click="pauseHour" color="gray" size="sm">Pause resets for an hour</x-filament::button>
                @endif
            </div>
        </div>

        <div style="{{ $card }}{{ $s['offline'] ? ';border-color:rgb(var(--danger-500))' : '' }}">
            <div style="{{ $label }}">Switch</div>
            <p style="{{ $body }}">
                @if($s['offline'])
                    The demo is <b>off</b>. Anyone following the link sees a plain page saying it is unavailable.
                @else
                    The demo is <b>live</b>. Turn it off the moment anything looks wrong in there.
                @endif
            </p>
            <input type="text" wire:model="offlineNote" placeholder="What visitors are told (optional)"
                   class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"
                   style="width:100%;font-size:13px;margin-top:12px">
            <div style="{{ $actions }}">
                <x-filament::button wire:click="toggleOffline" :color="$s['offline'] ? 'success' : 'danger'" size="sm">
                    {{ $s['offline'] ? 'Put the demo back online' : 'Switch the demo off' }}
                </x-filament::button>
            </div>
        </div>
    </div>

    <div style="{{ $card }};margin-top:16px">
        <div style="{{ $label }}">Messages the demo did not send</div>
        @if($sup)
            <table style="width:100%;font-size:13.5px;border-collapse:collapse">
                <tbody>
                @foreach($sup as $m)
                    <tr style="border-bottom:1px solid rgba(127,127,127,.14)">
                        <td style="padding:6px 12px 6px 0;opacity:.55;width:64px;vertical-align:top">{{ $m['channel'] }}</td>
                        <td style="padding:6px 12px 6px 0;vertical-align:top">{{ $m['body'] }}</td>
                        <td style="padding:6px 0;opacity:.55;font-size:12px;white-space:nowrap;text-align:right;vertical-align:top">
                            {{ \Carbon\Carbon::parse($m['at'])->diffForHumans() }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <p style="{{ $body }}">Nothing yet. Anything a visitor "sends" lands here instead of a real inbox.</p>
        @endif
    </div>

</x-filament-panels::page>
