<x-filament-panels::page>
<!-- MARKER-TEAM-ROLES -->
<!-- MARKER-TEAM-ROLES-V2 -->

@php
    $badge = function (?string $r): string {
        return match ($r) {
            'owner'        => 'bg-lime-400/10 text-lime-600 dark:text-lime-300 ring-lime-400/30',
            'admin'        => 'bg-sky-400/10 text-sky-600 dark:text-sky-300 ring-sky-400/30',
            'support'      => 'bg-teal-400/10 text-teal-600 dark:text-teal-300 ring-teal-400/30',
            'sales'        => 'bg-amber-400/10 text-amber-600 dark:text-amber-300 ring-amber-400/30',
            default        => 'bg-gray-400/10 text-gray-500 dark:text-gray-300 ring-gray-400/30',
        };
    };
@endphp

@if($revealPassword !== '')
  <div class="rounded-xl border border-lime-500/40 bg-lime-400/5 p-4">
    <div class="text-sm font-semibold">One-time password for {{ $revealEmail }}</div>
    <div class="mt-1 font-mono text-lg tracking-wide select-all">{{ $revealPassword }}</div>
    <div class="mt-1 text-xs text-gray-500">Shown once and never stored — hand it over securely. They sign in at intake.works/admin and should change it from the Password Editor.</div>
  </div>
@endif

<x-filament::section>
  <x-slot name="heading">Intake staff</x-slot>
  <x-slot name="description">People with master-admin access — click a name to open their record. Reps live in the section below and never see this panel.</x-slot>

  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
        <th class="py-2 pr-3">User</th><th class="py-2 pr-3">Role</th><th class="py-2 pr-3">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach($staff as $u)
        @php $role = $u->roleName(); @endphp
        <tr class="border-t border-gray-200 dark:border-white/10 {{ $selected && $selected->id === $u->id ? 'bg-primary-500/5' : '' }}">
          <td class="py-3 pr-3">
            <button type="button" class="text-left" wire:click="selectUser({{ $u->id }})">
              <span class="font-medium text-primary-600 dark:text-primary-400 hover:underline">{{ $u->name }}</span>
              <span class="block text-xs text-gray-500">{{ $u->email }}</span>
            </button>
          </td>
          <td class="py-3 pr-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $badge($role) }}">{{ ucfirst($role ?? '—') }}</span></td>
          <td class="py-3 pr-3 text-xs">
            @if($u->suspended_at)
              <span class="text-gray-500">Suspended {{ $u->suspended_at->format('M j') }}</span>
            @else
              <span class="text-lime-600 dark:text-lime-300">Active</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <x-slot name="footerActions"></x-slot>
</x-filament::section>

@if($selected)
  @php $selRole = $selected->roleName(); $selIsOwner = $selRole === 'owner'; $selIsMe = $selected->id === $meId; $locked = $selIsOwner || $selIsMe || ! $canManage; @endphp
  <x-filament::section>
    <x-slot name="heading">{{ $selected->name }}</x-slot>
    <x-slot name="description">{{ $selected->email }} · joined {{ $selected->created_at?->format('M j, Y') }}</x-slot>
    <x-slot name="headerEnd">
      <x-filament::button size="sm" color="gray" wire:click="closeUser">Close</x-filament::button>
    </x-slot>

    <div class="grid gap-6 md:grid-cols-2">
      <div class="space-y-4">
        <div>
          <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Role</div>
          @if($locked)
            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $badge($selRole) }}">{{ ucfirst($selRole ?? '—') }}</span>
            <div class="mt-1 text-xs text-gray-500">
              {{ $selIsOwner ? "The owner can't be edited or removed." : ($selIsMe ? "This is you — another owner-level change must come from the database." : 'View access — only the owner can make changes.') }}
            </div>
          @else
            <x-filament::input.wrapper class="max-w-xs">
              <x-filament::input.select wire:change="changeRole({{ $selected->id }}, $event.target.value)">
                @foreach(['admin' => 'Admin — everything except the raise', 'support' => 'Support — tenants, features, domains, logs', 'sales' => 'Sales — CRM, reps, commissions'] as $rv => $rl)
                  <option value="{{ $rv }}" @selected($selRole === $rv)>{{ $rl }}</option>
                @endforeach
              </x-filament::input.select>
            </x-filament::input.wrapper>
            <div class="mt-1 text-xs text-gray-500">Takes effect on their next request. Written to the audit log.</div>
          @endif
        </div>

        <div>
          <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Status</div>
          @if($selected->suspended_at)
            <div class="text-sm">Suspended {{ $selected->suspended_at->format('M j, g:ia') }}</div>
            @if(! $locked)
              <x-filament::button class="mt-2" size="sm" color="gray" wire:click="restore({{ $selected->id }})">Restore access</x-filament::button>
            @endif
          @else
            <div class="text-sm text-lime-600 dark:text-lime-300">Active</div>
            @if(! $locked)
              <x-filament::button class="mt-2" size="sm" color="gray"
                wire:click="suspend({{ $selected->id }})"
                wire:confirm="Suspend {{ $selected->email }}? They are signed out on their next request and blocked until restored.">Suspend</x-filament::button>
            @endif
          @endif
        </div>

        @if(! $locked)
          <div class="rounded-xl border border-danger-500/30 p-3">
            <div class="text-xs font-semibold text-danger-500">Remove user</div>
            <div class="mt-1 text-xs text-gray-500">Deletes the account and revokes access immediately. Their name stays on past audit entries.</div>
            <x-filament::button class="mt-2" size="sm" color="danger"
              wire:click="remove({{ $selected->id }})"
              wire:confirm="Remove {{ $selected->email }}? This can't be undone.">Remove {{ $selected->name }}…</x-filament::button>
          </div>
        @endif
      </div>

      <div>
        <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Recent activity</div>
        @forelse($activity as $entry)
          <div class="border-b border-gray-200 py-2 text-sm dark:border-white/10">
            <span class="text-xs text-gray-500">{{ $entry->created_at->format('M j, g:ia') }}</span>
            <div>{{ $entry->message }}</div>
          </div>
        @empty
          <div class="text-xs text-gray-500">No audit entries about this user yet. Actions they take elsewhere appear in Debug logs.</div>
        @endforelse
      </div>
    </div>
  </x-filament::section>
@endif

@if($canManage)
  {{-- MARKER-TEAM-INVITE-MODAL — mockup screen 2: modal with role cards --}}
  <div>
    <x-filament::modal id="invite-user" width="lg">
      <x-slot name="trigger">
        <x-filament::button>Invite user</x-filament::button>
      </x-slot>
      <x-slot name="heading">Invite user</x-slot>
      <x-slot name="description">They set their own password after first sign-in — hand over the one-time password securely.</x-slot>

      <div class="grid gap-3 md:grid-cols-2">
        <x-filament::input.wrapper>
          <x-filament::input type="text" placeholder="Name" wire:model="inviteName" />
        </x-filament::input.wrapper>
        <x-filament::input.wrapper>
          <x-filament::input type="email" placeholder="Email" wire:model="inviteEmail" />
        </x-filament::input.wrapper>
      </div>

      {{-- MARKER-INVITE-CARDS-ALPINE — client-side selection, entangled --}}
      <div class="mt-4 space-y-2" x-data="{ role: @entangle('inviteRole') }">
        @foreach([
          'admin'   => ['Admin', 'Everything except the raise and the owner controls on Team. For a future right hand, not day-one hires.'],
          'support' => ['Support', 'Runs tenant support: accounts, features, domains, impersonation, logs. No sales, marketing, catalog changes, billing keys or raise.'],
          'sales'   => ['Sales', 'Runs the pipeline: prospects, campaigns, quotes, reps and commissions, analytics. Tenants read-only; can\'t impersonate or touch settings.'],
        ] as $rv => [$rl, $rd])
          <button type="button"
                  class="flex w-full items-start gap-3 rounded-xl border p-3 text-left transition"
                  x-on:click="role = '{{ $rv }}'"
                  x-bind:class="role === '{{ $rv }}' ? 'border-primary-500 bg-primary-500/5' : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20'">
            <span class="mt-0.5 inline-block h-4 w-4 flex-shrink-0 rounded-full border-2"
                  x-bind:class="role === '{{ $rv }}' ? 'border-primary-500 bg-primary-500' : 'border-gray-400'"></span>
            <span>
              <span class="block text-sm font-semibold">{{ $rl }}</span>
              <span class="block text-xs text-gray-500">{{ $rd }}</span>
            </span>
          </button>
        @endforeach
      </div>

      <div class="mt-2 text-xs text-gray-500">Owner isn't invitable — there is exactly one. Reps come through the rep invite flow, never from here.</div>

      <x-slot name="footerActions">
        <x-filament::button wire:click="invite" x-on:click="$dispatch('close-modal', { id: 'invite-user' })">Create &amp; show password</x-filament::button>
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'invite-user' })">Cancel</x-filament::button>
      </x-slot>
    </x-filament::modal>
  </div>
@else
  <x-filament::section>
    <div class="text-xs text-gray-500">You have view access. Only the owner can invite, change roles, suspend or remove staff.</div>
  </x-filament::section>
@endif

<x-filament::section>
  <x-slot name="heading">Reps &amp; agencies</x-slot>
  <x-slot name="description">Reps and agency owners live in the /rep panel only — this manages who's in it and who leads each agency.</x-slot>

  @forelse($agencies as $agency)
    <div class="pb-2 pt-1 text-sm font-medium">{{ $agency->name }}
      <span class="text-xs font-normal text-gray-500">· {{ $agency->reps->count() }} {{ Str::plural('rep', $agency->reps->count()) }}</span>
    </div>
    <table class="w-full text-sm">
      <tbody>
        @foreach($agency->reps as $rep)
          <tr class="border-t border-gray-200 dark:border-white/10">
            <td class="py-3 pr-3">
              <div class="font-medium">{{ $rep->name }}</div>
              <div class="text-xs text-gray-500">{{ $rep->email }}</div>
            </td>
            <td class="py-3 pr-3">
              <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $rep->role === 'principal' ? $badge('sales') : $badge(null) }}">
                {{ $rep->role === 'principal' ? 'Agency owner' : 'Rep' }}
              </span>
            </td>
            <td class="py-3 pr-3 text-xs {{ $rep->status === 'active' ? 'text-lime-600 dark:text-lime-300' : 'text-gray-500' }}">{{ ucfirst($rep->status) }}</td>
            @if($canManage)
              <td class="py-3 text-right">
                @if($rep->role === 'principal')
                  <x-filament::button size="xs" color="gray" wire:click="demoteRep({{ $rep->id }})">Demote to rep</x-filament::button>
                @else
                  <x-filament::button size="xs" color="gray" wire:click="promoteRep({{ $rep->id }})">Make agency owner</x-filament::button>
                @endif
                <x-filament::button size="xs" color="gray"
                  wire:click="toggleRepActive({{ $rep->id }})"
                  wire:confirm="{{ $rep->status === 'active' ? 'Deactivate' : 'Activate' }} {{ $rep->name }}?">
                  {{ $rep->status === 'active' ? 'Deactivate' : 'Activate' }}
                </x-filament::button>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
  @empty
    <div class="text-xs text-gray-500">No agencies yet.</div>
  @endforelse
</x-filament::section>

</x-filament-panels::page>
