{{-- MARKER-DEMO-ENTRY --}}
@php
    $s      = $this->state();
    $weeks  = $this->weekOptions();
    $sup    = $this->suppressed();
    $tz     = config('app.timezone');
@endphp

<x-filament-panels::page>

    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 text-sm">
        <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">What this is</div>
        <p class="text-gray-600 dark:text-gray-400">
            <b>Intake Bike Works</b> is a copy of a real shop with every person anonymised, wearing the Intake wordmark.
            Anyone can walk in at <a href="{{ $s['entry_url'] }}" class="text-primary-600 underline">{{ $s['entry_url'] }}</a>
            with no account. Emails and texts never actually send there, and the whole thing goes back to its frozen
            state every hour. Rebuilding the template — a fresh copy from the live shop — runs on the server, not here:
            <code class="text-xs">php artisan demo:build-template --from=grndctrl</code>
        </p>
    </div>

    <div class="grid gap-4 md:grid-cols-3 mt-4">
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Template</div>
            <div class="mt-1 text-sm">
                @if($s['built_at'])
                    Built {{ \Carbon\Carbon::parse($s['built_at'])->diffForHumans() }}<br>
                    <span class="text-gray-500">{{ number_format($s['rows']) }} rows · {{ $s['tables'] }} tables</span>
                    @if(\Carbon\Carbon::parse($s['built_at'])->lt(now()->subDays(60)))
                        <div class="mt-1 text-xs text-warning-600">Over two months old — a rebuild would pick up newer work.</div>
                    @endif
                @else
                    <span class="text-danger-600">No frozen template. Build one on the server first.</span>
                @endif
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Resets</div>
            <div class="mt-1 text-sm">
                @if($s['last_reset'])
                    Last {{ \Carbon\Carbon::parse($s['last_reset'])->diffForHumans() }}<br>
                    <span class="text-gray-500">Next at the top of the hour · dates shifted {{ (int) $s['shift_days'] }} days</span>
                @else
                    <span class="text-gray-500">Never reset yet.</span>
                @endif
                @if($s['paused_until'])
                    <div class="mt-1 text-xs text-warning-600">Paused until {{ \Carbon\Carbon::parse($s['paused_until'])->setTimezone($tz)->format('g:i a') }}</div>
                @endif
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Visitors</div>
            <div class="mt-1 text-sm">
                {{ number_format($s['entries']) }} total {{ \Illuminate\Support\Str::plural('entry', $s['entries']) }}<br>
                <span class="text-gray-500">
                    @if($s['last_entry']) Last {{ \Carbon\Carbon::parse($s['last_entry'])->diffForHumans() }} @else None yet @endif
                </span>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 mt-4">
        <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Which week the demo sits in</div>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
            Every reset moves the whole timeline by a whole number of weeks so the week you pick lands on this week —
            a busy Tuesday stays a busy Tuesday. Everything else moves with it, so you are choosing the shape of the
            whole demo, not just one week.
        </p>
        @if($weeks)
            {{-- MARKER-DEMO-WEEKCARDS — explicit widths: Filament's build does not
                 carry the responsive grid utilities, so class-based columns were
                 silently doing nothing. --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">
                @php $busiest = collect($weeks)->keys()->first(); @endphp
                @foreach($weeks as $monday => $count)
                    @php $on = $anchorWeek === $monday; @endphp
                    <label style="cursor:pointer;display:block;border-radius:10px;padding:10px 12px;
                        border:1px solid {{ $on ? 'rgb(var(--primary-500))' : 'rgba(127,127,127,.25)' }};
                        background:{{ $on ? 'rgba(var(--primary-500),.08)' : 'transparent' }}">
                        <input type="radio" wire:model.live="anchorWeek" value="{{ $monday }}" class="sr-only">
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="font-weight:600;font-size:13.5px">{{ \Carbon\Carbon::parse($monday)->format('M j') }}</span>
                            <span style="font-size:11px;opacity:.5">{{ \Carbon\Carbon::parse($monday)->format('Y') }}</span>
                            @if($monday === $busiest)
                                <span style="margin-left:auto;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.6">Busiest</span>
                            @elseif($on)
                                <span style="margin-left:auto;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:rgb(var(--primary-500))">Anchor</span>
                            @endif
                        </div>
                        <div style="font-size:12px;opacity:.6;margin-top:2px">{{ $count }} appointments, sales &amp; deliveries</div>
                    </label>
                @endforeach
            </div>
            @if($anchorWeek)
                <p style="font-size:12px;opacity:.6;margin-top:10px">
                    Week of {{ \Carbon\Carbon::parse($anchorWeek)->format('M j, Y') }} becomes this week
                    ({{ (int) \Carbon\Carbon::parse($anchorWeek)->startOfWeek()->diffInDays(now()->startOfWeek(), false) }} days forward)
                    at the next reset.
                </p>
            @endif
            <div class="mt-3 flex gap-2">
                <x-filament::button wire:click="saveAnchor" size="sm">Save anchor week</x-filament::button>
                <x-filament::button wire:click="resetNow" color="gray" size="sm">Reset now</x-filament::button>
            </div>
        @else
            <div class="text-sm text-gray-500">No week data — rebuild the template to record it.</div>
        @endif
    </div>

    <div class="grid gap-4 md:grid-cols-2 mt-4">
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">While you are showing it</div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                Pausing stops the top-of-hour wipe for sixty minutes, so a walkthrough at :58 does not vanish mid-sentence.
            </p>
            @if($s['paused_until'])
                <x-filament::button wire:click="resumeResets" color="gray" size="sm">Resume hourly resets</x-filament::button>
            @else
                <x-filament::button wire:click="pauseHour" color="gray" size="sm">Pause resets for an hour</x-filament::button>
            @endif
        </div>

        <div class="rounded-xl border {{ $s['offline'] ? 'border-danger-400' : 'border-gray-200 dark:border-white/10' }} p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Switch</div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                @if($s['offline'])
                    The demo is <b>off</b>. Anyone following the link sees a plain page saying it is unavailable.
                @else
                    The demo is <b>live</b>. Turn it off the moment anything looks wrong in there.
                @endif
            </p>
            <input type="text" wire:model="offlineNote" placeholder="What visitors are told (optional)"
                   class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm mb-2">
            <x-filament::button wire:click="toggleOffline" :color="$s['offline'] ? 'success' : 'danger'" size="sm">
                {{ $s['offline'] ? 'Put the demo back online' : 'Switch the demo off' }}
            </x-filament::button>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 mt-4">
        <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Messages the demo did not send</div>
        @if($sup)
            <table class="w-full text-sm">
                <tbody>
                @foreach($sup as $m)
                    <tr class="border-b border-gray-100 dark:border-white/5 last:border-0">
                        <td class="py-1.5 pr-3 text-gray-500 w-16">{{ $m['channel'] }}</td>
                        <td class="py-1.5 pr-3">{{ $m['body'] }}</td>
                        <td class="py-1.5 text-gray-500 text-xs whitespace-nowrap">{{ \Carbon\Carbon::parse($m['at'])->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <div class="text-sm text-gray-500">Nothing yet. Anything a visitor "sends" lands here instead of a real inbox.</div>
        @endif
    </div>

</x-filament-panels::page>
