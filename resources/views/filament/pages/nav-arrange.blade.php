{{-- MARKER-NAV-ARRANGE --}}
@php
    $a       = $this->arrangement();
    $counts  = $this->counts();
    $groups  = $this->groupNames();

    $card  = 'border-radius:12px;padding:14px 18px;border:1px solid rgba(127,127,127,.22)';
    $label = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55';
    $body  = 'font-size:13px;line-height:1.55;opacity:.75;margin:0';
@endphp

<x-filament-panels::page>

    <div style="{{ $card }}">
        <div style="{{ $label }};margin-bottom:8px">What this is</div>
        <p style="{{ $body }}">
            Arrange your own sidebar: move an item to another group, rename it, change the order, or hide what
            you never use. Anything you don't touch keeps following its page, so a new page still appears on
            its own.
        </p>
        <p style="{{ $body }};margin-top:8px;opacity:.55">
            Hiding is tidying, not access control — a hidden page keeps its address and still works for anyone
            who has the link. Who may open it is decided by roles.
        </p>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:12.5px">
            <span><b>{{ $counts['visible'] }}</b> in the sidebar</span>
            <span><b>{{ $counts['hidden'] }}</b> hidden</span>
            <span style="opacity:.55">{{ $counts['registered'] }} pages registered</span>
            @if($counts['visible'] + $counts['hidden'] !== $counts['registered'])
                <span style="color:#F0C46A">counts disagree — tell whoever maintains this</span>
            @endif
        </div>
    </div>

    @foreach($a['groups'] as $groupName => $items)
        <div style="{{ $card }};margin-top:14px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                <span style="{{ $label }}">{{ $groupName }}</span>
                <span style="font-size:11px;opacity:.4">{{ count($items) }}</span>
                <span style="margin-left:auto;display:flex;gap:4px">
                    <x-filament::button size="xs" color="gray" wire:click="moveGroupUp(@js($groupName))">↑</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="moveGroupDown(@js($groupName))">↓</x-filament::button>
                </span>
            </div>

            @foreach($items as $i => $item)
                <div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-top:1px solid rgba(127,127,127,.14);flex-wrap:wrap">
                    <span style="display:flex;gap:3px">
                        <x-filament::button size="xs" color="gray" :disabled="$i === 0"
                            wire:click="moveUp(@js($item['class']), @js($groupName))">↑</x-filament::button>
                        <x-filament::button size="xs" color="gray" :disabled="$i === count($items) - 1"
                            wire:click="moveDown(@js($item['class']), @js($groupName))">↓</x-filament::button>
                    </span>

                    {{-- MARKER-NAV-ARRANGE — the label edits in place. House rule:
                         no native browser dialogs, and a prompt() here would be
                         one. Enter or clicking away saves; empty restores the
                         page's own name. --}}
                    <input value="{{ $item['label'] }}"
                           wire:change="rename(@js($item['class']), $event.target.value)"
                           class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"
                           style="min-width:190px;font-size:13.5px;padding:3px 8px"
                           title="{{ $item['renamed'] ? 'Originally ' . $item['declared'] . ' — clear to restore' : 'Type a new name, or leave blank to keep this one' }}">
                    @if($item['renamed'])
                        <span style="opacity:.45;font-size:11.5px">was {{ $item['declared'] }}</span>
                    @endif

                    <span style="opacity:.35;font-size:11.5px;min-width:150px">{{ $item['short'] }}</span>

                    <select wire:change="setGroup(@js($item['class']), $event.target.value)"
                            class="rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm"
                            style="font-size:12px;padding:3px 6px">
                        @foreach(array_unique(array_merge($groups, ['(top level)'])) as $g)
                            <option value="{{ $g }}" @selected($g === $groupName)>{{ $g }}</option>
                        @endforeach
                    </select>

                    <span style="margin-left:auto;display:flex;gap:6px">
                        <x-filament::button size="xs" color="gray" wire:click="hide(@js($item['class']))">
                            Hide
                        </x-filament::button>
                    </span>
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- hidden items are LISTED, never simply absent: an absence is exactly how
         a nav item gets lost without anyone noticing. --}}
    <div style="{{ $card }};margin-top:14px">
        <div style="{{ $label }};margin-bottom:8px">Hidden from the sidebar</div>
        @forelse($a['hidden'] as $item)
            <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid rgba(127,127,127,.14);font-size:13.5px">
                <span style="min-width:190px">{{ $item['label'] }}</span>
                <span style="opacity:.35;font-size:11.5px">{{ $item['short'] }}</span>
                <span style="opacity:.45;font-size:11.5px">would sit in {{ $item['group'] }}</span>
                <span style="margin-left:auto">
                    <x-filament::button size="xs" wire:click="unhide(@js($item['class']))">Show again</x-filament::button>
                </span>
            </div>
        @empty
            <p style="{{ $body }}">Nothing is hidden. Everything the panel registers is in the sidebar.</p>
        @endforelse
    </div>

    <div style="{{ $card }};margin-top:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div>
            <div style="{{ $label }};margin-bottom:4px">Start over</div>
            <p style="{{ $body }}">Clears every change here and puts each item back where its page says it belongs.</p>
        </div>
        <span style="margin-left:auto">
            <x-filament::button color="danger" wire:click="resetAll"
                wire:confirm="Put every item back where its page declares it belongs? Your arrangement is discarded.">
                Reset the sidebar
            </x-filament::button>
        </span>
    </div>

</x-filament-panels::page>
