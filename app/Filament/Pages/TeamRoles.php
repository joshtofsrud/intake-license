<?php
// MARKER-TEAM-ROLES

namespace App\Filament\Pages;

use App\Models\SalesAgency;
use App\Models\SalesRep;
use App\Models\User;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamRoles extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Team & roles';
    protected static ?string $navigationGroup = 'Team';
    protected static ?string $title           = 'Team & roles';
    protected static ?string $slug            = 'team-roles';
    protected static ?int    $navigationSort  = 95;

    protected static string $view = 'filament.pages.team-roles';

    // invite form
    public string $inviteName  = '';
    public string $inviteEmail = '';
    public string $inviteRole  = 'support';

    // one-time password reveal (never persisted)
    public string $revealEmail    = '';
    public string $revealPassword = '';

    // MARKER-TEAM-ROLES-V2 — open user record
    public ?int $selectedId = null;

    public function selectUser(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closeUser(): void
    {
        $this->selectedId = null;
    }

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'team');
    }

    public function canManage(): bool
    {
        return Auth::guard('web')->user()?->roleName() === 'owner';
    }

    protected function getViewData(): array
    {
        $staff = User::query()
            ->where(function ($q) {
                $q->whereIn('role', AdminAccess::STAFF_ROLES)->orWhere('is_admin', true);
            })
            ->orderBy('name')
            ->get()
            ->sortBy(fn (User $u) => array_search($u->roleName(), AdminAccess::STAFF_ROLES, true) ?? 9)
            ->values();

        // MARKER-TEAM-ROLES-V2 — open record + its audit trail
        $selected = $this->selectedId ? $staff->firstWhere('id', $this->selectedId) : null;
        $activity = collect();
        if ($selected) {
            $activity = \App\Models\DebugLog::query()
                ->where('category', 'audit')
                ->where('subject_type', User::class)
                ->where('subject_id', $selected->id)
                ->latest()
                ->limit(10)
                ->get();
        }

        return [
            'staff'     => $staff,
            'selected'  => $selected,
            'activity'  => $activity,
            'agencies'  => class_exists(SalesAgency::class) ? SalesAgency::with('reps')->get() : collect(),
            'canManage' => $this->canManage(),
            'meId'      => Auth::guard('web')->id(),
        ];
    }

    private function guardManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    /** Target must exist, must not be the owner, must not be me. */
    private function editableTarget(int $id): User
    {
        $target = User::findOrFail($id);
        if ($target->roleName() === 'owner' || $target->id === Auth::guard('web')->id()) {
            abort(403);
        }
        return $target;
    }

    public function invite(): void
    {
        $this->guardManage();
        $data = $this->validate([
            'inviteName'  => ['required', 'string', 'max:120'],
            'inviteEmail' => ['required', 'email', 'max:190', 'unique:users,email'],
            'inviteRole'  => ['required', 'in:admin,support,sales'],
        ]);

        $password = Str::random(16);
        $user = User::create([
            'name'     => $data['inviteName'],
            'email'    => $data['inviteEmail'],
            'password' => Hash::make($password),
            'role'     => $data['inviteRole'],
            'is_admin' => $data['inviteRole'] === 'admin',
        ]);

        debug_log()->audit('team.invite', "Invited {$user->email} as {$user->role}", $user);

        // Shown ONCE for the owner to hand over securely; never stored.
        $this->revealEmail    = $user->email;
        $this->revealPassword = $password;
        $this->inviteName = $this->inviteEmail = '';
        $this->inviteRole = 'support';

        Notification::make()->title('User created — copy the one-time password below')->success()->send();
    }

    public function changeRole(int $id, string $role): void
    {
        $this->guardManage();
        if (! in_array($role, ['admin', 'support', 'sales'], true)) {
            abort(422);
        }
        $target = $this->editableTarget($id);
        $old = $target->roleName();
        $target->role     = $role;
        $target->is_admin = $role === 'admin';
        $target->save();
        $this->selectedId = $target->id; // MARKER-TEAM-ROLES-V2 — keep the record open

        debug_log()->audit('team.role_changed', "{$target->email}: {$old} → {$role}", $target);
        Notification::make()->title("Role changed to {$role}")->success()->send();
    }

    public function suspend(int $id): void
    {
        $this->guardManage();
        $target = $this->editableTarget($id);
        $target->suspended_at = now();
        $target->save();
        debug_log()->audit('team.suspended', "Suspended {$target->email}", $target);
        Notification::make()->title('Suspended')->success()->send();
    }

    public function restore(int $id): void
    {
        $this->guardManage();
        $target = $this->editableTarget($id);
        $target->suspended_at = null;
        $target->save();
        debug_log()->audit('team.restored', "Restored {$target->email}", $target);
        Notification::make()->title('Restored')->success()->send();
    }

    public function remove(int $id): void
    {
        $this->guardManage();
        $target = $this->editableTarget($id);
        debug_log()->audit('team.removed', "Removed {$target->email} (was {$target->roleName()})", $target);
        $target->delete();
        $this->selectedId = null; // MARKER-TEAM-ROLES-V2
        Notification::make()->title('User removed')->success()->send();
    }

    // ---- Reps & agencies -------------------------------------------------

    public function promoteRep(int $repId): void
    {
        $this->guardManage();
        $rep = SalesRep::findOrFail($repId);
        $rep->role = 'principal';
        $rep->save();
        debug_log()->audit('team.rep_promoted', "Rep {$rep->name} is now agency owner", $rep);
        Notification::make()->title("{$rep->name} is now agency owner")->success()->send();
    }

    public function demoteRep(int $repId): void
    {
        $this->guardManage();
        $rep = SalesRep::findOrFail($repId);
        $rep->role = 'rep';
        $rep->save();
        debug_log()->audit('team.rep_demoted', "Rep {$rep->name} demoted to rep", $rep);
        Notification::make()->title("{$rep->name} demoted to rep")->success()->send();
    }

    public function toggleRepActive(int $repId): void
    {
        $this->guardManage();
        $rep = SalesRep::findOrFail($repId);
        $rep->status = $rep->status === 'active' ? 'inactive' : 'active';
        $rep->save();
        debug_log()->audit('team.rep_status', "Rep {$rep->name} set {$rep->status}", $rep);
        Notification::make()->title("Rep {$rep->status}")->success()->send();
    }
}
