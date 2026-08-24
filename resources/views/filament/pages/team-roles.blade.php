<x-filament-panels::page>
<!-- MARKER-TEAM-ROLES -->

@php
    $badge = function (?string $r): string {
        return match ($r) {
            'owner'   => 'bg-lime-400/10 text-lime-300 ring-lime-400/30',
            'admin'   => 'bg-sky-400/10 text-sky-300 ring-sky-400/30',
            'support' => 'bg-teal-400/10 text-teal-300 ring-teal-400/30',
            'sales'   => 'bg-amber-400/10 text-amber-300 ring-amber-400/30',
            default   => 'bg-gray-400/10 text-gray-300 ring-gray-400/30',
        };
    };
@endphp

@if($revealPassword !== '')
  <div class="rounded-xl border border-lime-400/40 bg-lime-400/5 p-4">
    <div class="text-sm font-semibold">One-time password for {{ $revealEmail }}</div>
    <div class="mt-1 font-mono text-lg tracking-wide">{{ $revealPassword }}</div>
    <div class="mt-1 text-xs text-gray-400">Shown once and never stored — hand it over securely. They sign in at intake.works/admin and should change it from the Password Editor.</div>
  </div>
@endif

<div class="rounded-xl border border-gray-200 dark:border-white/10">
  <div class="flex items-center gap-3 border-b border-gray-200 dark:border-white/10 p-4">
    <div class="text-sm font-semibold">Intake staff</div>
    <div class="text-xs text-gray-500">people with master-admin access — reps live in the section below and never see this panel</div>
  </div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
        <th class="p-3">User</th><th class="p-3">Role</th><th class="p-3">Status</th>
        @if($canManage)<th class="p-3 text-right">Actions</th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach($staff as $u)
        @php $role = $u->roleName(); $isOwner = $role === 'owner'; $isMe = $u->id === $meId; @endphp
        <tr class="border-t border-gray-200 dark:border-white/10">
          <td class="p-3">
            <div class="font-medium">{{ $u->name }}</div>
            <div class="text-xs text-gray-500">{{ $u->email }}</div>
          </td>
          <td class="p-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $badge($role) }}">{{ ucfirst($role ?? '—') }}</span></td>
          <td class="p-3">
            @if($u->suspended_at)
              <span class="text-xs text-gray-500">Suspended {{ $u->suspended_at->format('M j') }}</span>
            @else
              <span class="text-xs text-lime-300">Active</span>
            @endif
          </td>
          @if($canManage)
            <td class="p-3 text-right">
              @if($isOwner || $isMe)
                <span class="text-xs text-gray-600">{{ $isOwner ? "can't be edited or removed" : 'this is you' }}</span>
              @else
                <select class="rounded-lg border-0 bg-white/5 px-2 py-1 text-xs"
                        wire:change="changeRole({{ $u->id }}, $event.target.value)">
                  @foreach(['admin','support','sales'] as $r)
                    <option value="{{ $r }}" @selected($role === $r)>{{ ucfirst($r) }}</option>
                  @endforeach
                </select>
                @if($u->suspended_at)
                  <x-filament::button size="xs" color="gray" wire:click="restore({{ $u->id }})">Restore</x-filament::button>
                  <x-filament::button size="xs" color="danger"
                    wire:click="remove({{ $u->id }})"
                    wire:confirm="Remove {{ $u->email }}? Their access ends immediately; their name stays on past audit entries.">Remove</x-filament::button>
                @else
                  <x-filament::button size="xs" color="gray"
                    wire:click="suspend({{ $u->id }})"
                    wire:confirm="Suspend {{ $u->email }}? They are blocked from signing in until restored.">Suspend</x-filament::button>
                @endif
              @endif
            </td>
          @endif
        </tr>
      @endforeach
    </tbody>
  </table>
  <div class="border-t border-gray-200 dark:border-white/10 p-3 text-xs text-gray-500">
    Every role change, suspension and removal is written to the audit log with the acting admin. Suspended users are signed out on their next request.
  </div>
</div>

@if($canManage)
  <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="mb-3 text-sm font-semibold">Invite user</div>
    <div class="grid gap-3 md:grid-cols-4">
      <input class="rounded-lg border-0 bg-white/5 px-3 py-2 text-sm" placeholder="Name" wire:model="inviteName">
      <input class="rounded-lg border-0 bg-white/5 px-3 py-2 text-sm" placeholder="Email" wire:model="inviteEmail">
      <select class="rounded-lg border-0 bg-white/5 px-3 py-2 text-sm" wire:model="inviteRole">
        <option value="admin">Admin — everything except the raise</option>
        <option value="support">Support — tenants, features, domains, logs</option>
        <option value="sales">Sales — CRM, reps, commissions</option>
      </select>
      <x-filament::button wire:click="invite">Create &amp; show password</x-filament::button>
    </div>
    <div class="mt-2 text-xs text-gray-500">Owner isn't invitable — there is exactly one. Reps come through the rep invite flow, never from here.</div>
  </div>
@else
  <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 text-xs text-gray-500">
    You have view access. Only the owner can invite, change roles, suspend or remove staff.
  </div>
@endif

<div class="rounded-xl border border-gray-200 dark:border-white/10">
  <div class="border-b border-gray-200 dark:border-white/10 p-4">
    <div class="text-sm font-semibold">Reps &amp; agencies</div>
    <div class="text-xs text-gray-500">reps and agency owners live in the /rep panel only — this manages who's in it and who leads each agency</div>
  </div>
  @forelse($agencies as $agency)
    <div class="border-b border-gray-200 dark:border-white/10 p-3 text-sm font-medium">{{ $agency->name }}
      <span class="text-xs font-normal text-gray-500">· {{ $agency->reps->count() }} {{ Str::plural('rep', $agency->reps->count()) }}</span>
    </div>
    <table class="w-full text-sm">
      <tbody>
        @foreach($agency->reps as $rep)
          <tr class="border-b border-gray-200 dark:border-white/10">
            <td class="p-3">
              <div class="font-medium">{{ $rep->name }}</div>
              <div class="text-xs text-gray-500">{{ $rep->email }}</div>
            </td>
            <td class="p-3">
              <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $rep->role === 'principal' ? $badge('sales') : $badge(null) }}">
                {{ $rep->role === 'principal' ? 'Agency owner' : 'Rep' }}
              </span>
            </td>
            <td class="p-3 text-xs {{ $rep->status === 'active' ? 'text-lime-300' : 'text-gray-500' }}">{{ ucfirst($rep->status) }}</td>
            @if($canManage)
              <td class="p-3 text-right">
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
    <div class="p-4 text-xs text-gray-500">No agencies yet.</div>
  @endforelse
</div>

</x-filament-panels::page>
