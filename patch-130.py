#!/usr/bin/env python3
"""
Patch 130 — remove per-user device features (broken on actual schema).

Patch 129 assumed tenant_trusted_devices had a tenant_user_id column.
It doesn't — devices are tenant-scoped, not user-scoped. Any browser
cookie trusted on the tenant lets any user sign in. So a bunch of
patch-129 surfaces were architecturally wrong:

  - Device count per user on the team list
  - "Trusted devices" card on the person detail page
  - "Your devices" card on the self-service page
  - "Sign out everywhere" (user-scoped) actions

This patch removes all of the above. What remains is correct:

  - Tenant-wide "All devices" audit page (owner-only) — unchanged
  - Per-user PIN, password, role, locations management — unchanged
  - DeviceTrustService::revoke() and revokeAllForTenant() — unchanged

Also drops the unused revokeAllForUser method added by patch 129.

The "Last seen" column on the team list is also removed per user
preference (display they didn't like).

Usage:
    python3 patch-130.py /path/to/intake-license             # dry-run
    python3 patch-130.py /path/to/intake-license --apply

Idempotent.
"""

import argparse
import pathlib
import sys

MARKER = 'MARKER-PATCH-130'

# ====================================================================
# 1. TeamController::index — strip device count fetch
# ====================================================================

OLD_INDEX = """    public function index()
    {
        $tenant = tenant();
        $members = TenantUser::where('tenant_id', $tenant->id)
            ->orderByRaw(\"FIELD(role,'owner','manager','staff')\")
            ->orderBy('name')
            ->get();

        // Per-member device count, attached for the table cell.
        $deviceCounts = TenantTrustedDevice::activeForTenant($tenant->id)
            ->selectRaw('tenant_user_id, COUNT(*) as c')
            ->groupBy('tenant_user_id')
            ->pluck('c', 'tenant_user_id');

        foreach ($members as $m) {
            $m->setAttribute('device_count', (int) ($deviceCounts[$m->id] ?? 0));
        }

        return view('tenant.team.index', compact('members'));
    }"""

NEW_INDEX = """    // MARKER-PATCH-130 — devices are tenant-scoped, no per-user counts.
    public function index()
    {
        $tenant = tenant();
        $members = TenantUser::where('tenant_id', $tenant->id)
            ->orderByRaw(\"FIELD(role,'owner','manager','staff')\")
            ->orderBy('name')
            ->get();

        return view('tenant.team.index', compact('members'));
    }"""


# ====================================================================
# 2. TeamController::show — strip per-user device fetch
# ====================================================================

OLD_SHOW = """        $this->requireManager();

        $devices = TenantTrustedDevice::activeForTenant($tenant->id)
            ->where('tenant_user_id', $member->id)
            ->orderBy('last_used_at', 'desc')
            ->get();

        $allLocations = $tenant->locations()->orderBy('sort_order')->get();
        $memberLocationIds = $member->locations()->pluck('tenant_locations.id')->all();

        return view('tenant.team.show', [
            'member'            => $member,
            'devices'           => $devices,
            'allLocations'      => $allLocations,
            'memberLocationIds' => $memberLocationIds,
        ]);
    }"""

NEW_SHOW = """        $this->requireManager();

        // MARKER-PATCH-130 — devices are tenant-scoped, not per-user.
        $allLocations = $tenant->locations()->orderBy('sort_order')->get();
        $memberLocationIds = $member->locations()->pluck('tenant_locations.id')->all();

        return view('tenant.team.show', [
            'member'            => $member,
            'allLocations'      => $allLocations,
            'memberLocationIds' => $memberLocationIds,
        ]);
    }"""


# ====================================================================
# 3. TeamController — drop sign_out_everywhere case from update()
# ====================================================================

OLD_SIGN_OUT_CASE = """            case 'sign_out_everywhere': {
                $this->devicesSvc->revokeAllForUser($member, $me);
                return back()->with('success', $member->name . ' has been signed out from every browser.');
            }

"""

NEW_SIGN_OUT_CASE = ""   # remove entire case block


# ====================================================================
# 4. AccountController — drop signOutEverywhere + revokeDevice methods
# ====================================================================

OLD_ACCOUNT_TAIL = """    public function revokeDevice(Request $request, string $deviceId)
    {
        $me = Auth::guard('tenant')->user();
        $device = TenantTrustedDevice::where('tenant_id', $me->tenant_id)
            ->where('tenant_user_id', $me->id)
            ->where('id', $deviceId)
            ->first();
        if (! $device) {
            return back()->with('error', 'Device not found.');
        }
        $this->devices->revoke($device, $me);
        return back()->with('success', 'Device revoked.');
    }

    public function signOutEverywhere(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $this->devices->revokeAllForUser($me, $me);
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('tenant.login')
            ->with('success', 'Signed out from every browser.');
    }
}"""

NEW_ACCOUNT_TAIL = """    // MARKER-PATCH-130 — per-user device methods removed; devices are tenant-scoped.
}"""


# ====================================================================
# 5. AccountController constructor — drop DeviceTrustService dep
# ====================================================================

OLD_ACCOUNT_CTOR = """use App\\Services\\PinService;
use App\\Services\\DeviceTrustService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;"""

NEW_ACCOUNT_CTOR = """use App\\Services\\PinService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;"""

OLD_ACCOUNT_CTOR_2 = """    public function __construct(
        protected PinService $pins,
        protected DeviceTrustService $devices,
    ) {}"""

NEW_ACCOUNT_CTOR_2 = """    public function __construct(
        protected PinService $pins,
    ) {}"""

OLD_ACCOUNT_INDEX = """    public function index(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $devices = TenantTrustedDevice::activeForTenant($me->tenant_id)
            ->where('tenant_user_id', $me->id)
            ->orderBy('last_used_at', 'desc')
            ->get();
        return view('tenant.account.index', [
            'me'      => $me,
            'devices' => $devices,
        ]);
    }"""

NEW_ACCOUNT_INDEX = """    public function index(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        return view('tenant.account.index', ['me' => $me]);
    }"""

OLD_ACCOUNT_USE = "use App\\Models\\Tenant\\TenantTrustedDevice;\n"
NEW_ACCOUNT_USE = ""


# ====================================================================
# 6. routes/web.php — drop self device + sign-out-everywhere routes
# ====================================================================

OLD_ACCOUNT_ROUTES = """            Route::post('/account/device/{id}/revoke',     [TenantControllers\\AccountController::class, 'revokeDevice'])->name('account.device.revoke');
            Route::post('/account/sign-out-everywhere',    [TenantControllers\\AccountController::class, 'signOutEverywhere'])->name('account.sign-out-everywhere');"""

NEW_ACCOUNT_ROUTES = "            // MARKER-PATCH-130 — per-user device + sign-out-everywhere routes removed"


# ====================================================================
# 7. DeviceTrustService — drop revokeAllForUser method
# ====================================================================

OLD_DTS_METHOD = """
    /**
     * MARKER-PATCH-129 — revoke every active device belonging to one user.
     * Powers "Sign out everywhere" actions in self-service + admin.
     */
    public function revokeAllForUser(TenantUser $user, ?TenantUser $byUser = null): int
    {
        $count = TenantTrustedDevice::activeForTenant($user->tenant_id)
            ->where('tenant_user_id', $user->id)
            ->update([
                'revoked_at'         => now(),
                'revoked_by_user_id' => $byUser?->id,
                'updated_at'         => now(),
            ]);

        Log::info('DeviceTrust.revokeAllForUser', [
            'tenant_id'  => $user->tenant_id,
            'user_id'    => $user->id,
            'count'      => $count,
            'by_user'    => $byUser?->id,
        ]);

        return $count;
    }
}
"""

NEW_DTS_METHOD = "}\n"   # plain closing brace


# ====================================================================
# 8. team/index.blade.php — drop Devices + Last seen columns
# ====================================================================

OLD_INDEX_HEAD = """        <th>Status</th>
        @if($pinModeOn)<th>PIN</th>@endif
        <th>Devices</th>
        <th>Last seen</th>
        <th></th>"""

NEW_INDEX_HEAD = """        <th>Status</th>
        @if($pinModeOn)<th>PIN</th>@endif
        {{-- MARKER-PATCH-130 — devices + last-seen columns removed --}}
        <th></th>"""

OLD_INDEX_CELLS = """        <td style=\"font-size:12px;color:var(--ia-text-dim)\">
          @if($member->device_count > 0)
            {{ $member->device_count }} {{ Str::plural('device', $member->device_count) }}
          @else
            —
          @endif
        </td>
        <td style=\"font-size:12px;color:var(--ia-text-dim)\">
          {{ $member->last_login_at?->diffForHumans() ?? 'never' }}
        </td>
        <td style=\"text-align:right;color:var(--ia-text-dim);font-family:var(--ia-font-mono);font-size:14px\">›</td>"""

NEW_INDEX_CELLS = """        {{-- MARKER-PATCH-130 --}}
        <td style=\"text-align:right;color:var(--ia-text-dim);font-family:var(--ia-font-mono);font-size:14px\">›</td>"""


# ====================================================================
# 9. team/show.blade.php — drop devices card + sign-out-everywhere row
# ====================================================================

OLD_SHOW_SIGN_OUT_FORM = """  <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
    @csrf @method('PATCH')
    <input type=\"hidden\" name=\"op\" value=\"sign_out_everywhere\">
    <div class=\"pd-field\">
      <div class=\"pd-field-label\">Active sessions</div>
      <div class=\"pd-field-value\">
        <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
                data-confirm=\"Sign {{ $member->name }} out from every browser?\">Sign out everywhere</button>
        <span class=\"pd-field-hint\">Revokes every trusted device. They will sign in fresh.</span>
      </div>
    </div>
  </form>
</div>"""

NEW_SHOW_SIGN_OUT_FORM = """  {{-- MARKER-PATCH-130 — per-user sign-out-everywhere removed (devices are tenant-scoped) --}}
</div>"""

OLD_SHOW_DEVICES_CARD = """{{-- Devices --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Trusted devices</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Browsers they signed in from with \"Trust this device\" checked.</p>

  @if($devices->isEmpty())
    <div class=\"pd-empty\">No trusted devices. They sign in with email + password every visit.</div>
  @else
    @foreach($devices as $d)
      <div class=\"pd-device\">
        <div>
          <div class=\"pd-device-label\">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class=\"pd-device-meta\">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
          </div>
        </div>
      </div>
    @endforeach
  @endif
</div>"""

NEW_SHOW_DEVICES_CARD = "{{-- MARKER-PATCH-130 — per-user devices card removed (devices are tenant-scoped; see /admin/team/devices for full list) --}}"


# ====================================================================
# 10. account/index.blade.php — drop sign-out-everywhere + devices
# ====================================================================

OLD_ACCOUNT_HEADER = """  <div class=\"ac-actions\">
    <form method=\"POST\" action=\"{{ route('tenant.account.sign-out-everywhere') }}\">
      @csrf
      <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
              data-confirm=\"Sign you out of every browser including this one?\">Sign out everywhere</button>
    </form>
  </div>"""

NEW_ACCOUNT_HEADER = """  {{-- MARKER-PATCH-130 — sign-out-everywhere removed (devices are tenant-scoped) --}}"""

OLD_ACCOUNT_DEVICES = """{{-- Your devices --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Your devices</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Browsers you've trusted. Revoke any you don't recognise.</p>

  @if($devices->isEmpty())
    <div class=\"ac-empty\">No trusted devices.</div>
  @else
    @foreach($devices as $d)
      <div class=\"ac-device\">
        <div>
          <div class=\"ac-device-label\">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class=\"ac-device-meta\">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
          </div>
        </div>
        <form method=\"POST\" action=\"{{ route('tenant.account.device.revoke', $d->id) }}\">
          @csrf
          <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
                  data-confirm=\"Revoke this device?\">Revoke</button>
        </form>
      </div>
    @endforeach
  @endif
</div>

@endsection"""

NEW_ACCOUNT_DEVICES = """{{-- MARKER-PATCH-130 — \"Your devices\" removed; devices are tenant-scoped --}}

@endsection"""


# ====================================================================
# Driver
# ====================================================================

EDITS = [
    # (relative_path, old_string, new_string, label)
    ('app/Http/Controllers/Tenant/TeamController.php',   OLD_INDEX,         NEW_INDEX,         'TeamController.index'),
    ('app/Http/Controllers/Tenant/TeamController.php',   OLD_SHOW,          NEW_SHOW,          'TeamController.show'),
    ('app/Http/Controllers/Tenant/TeamController.php',   OLD_SIGN_OUT_CASE, NEW_SIGN_OUT_CASE, 'TeamController.update sign_out_everywhere case'),
    ('app/Http/Controllers/Tenant/AccountController.php',OLD_ACCOUNT_USE,     NEW_ACCOUNT_USE,     'AccountController use'),
    ('app/Http/Controllers/Tenant/AccountController.php',OLD_ACCOUNT_CTOR,    NEW_ACCOUNT_CTOR,    'AccountController imports'),
    ('app/Http/Controllers/Tenant/AccountController.php',OLD_ACCOUNT_CTOR_2,  NEW_ACCOUNT_CTOR_2,  'AccountController constructor'),
    ('app/Http/Controllers/Tenant/AccountController.php',OLD_ACCOUNT_INDEX,   NEW_ACCOUNT_INDEX,   'AccountController.index'),
    ('app/Http/Controllers/Tenant/AccountController.php',OLD_ACCOUNT_TAIL,    NEW_ACCOUNT_TAIL,    'AccountController tail'),
    ('routes/web.php',                                   OLD_ACCOUNT_ROUTES,  NEW_ACCOUNT_ROUTES,  'routes: account device/sign-out-everywhere'),
    ('app/Services/DeviceTrustService.php',              OLD_DTS_METHOD,      NEW_DTS_METHOD,      'DeviceTrustService.revokeAllForUser'),
    ('resources/views/tenant/team/index.blade.php',      OLD_INDEX_HEAD,      NEW_INDEX_HEAD,      'team/index thead'),
    ('resources/views/tenant/team/index.blade.php',      OLD_INDEX_CELLS,     NEW_INDEX_CELLS,     'team/index cells'),
    ('resources/views/tenant/team/show.blade.php',       OLD_SHOW_SIGN_OUT_FORM, NEW_SHOW_SIGN_OUT_FORM, 'team/show sign-out form'),
    ('resources/views/tenant/team/show.blade.php',       OLD_SHOW_DEVICES_CARD,  NEW_SHOW_DEVICES_CARD,  'team/show devices card'),
    ('resources/views/tenant/account/index.blade.php',   OLD_ACCOUNT_HEADER,  NEW_ACCOUNT_HEADER,  'account/index header'),
    ('resources/views/tenant/account/index.blade.php',   OLD_ACCOUNT_DEVICES, NEW_ACCOUNT_DEVICES, 'account/index devices'),
]


def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}
    for rel, old, new, label in EDITS:
        path = root / rel
        if not path.exists():
            print(f'ERROR: {rel} not found', file=sys.stderr)
            sys.exit(2)
        text = path.read_text()
        # Already-applied detection: old not in text AND (new is empty OR new in text).
        if old not in text:
            if not new or new in text:
                summary[label] = 'already_applied'
                continue
            print(f'ERROR: anchor not found for {label} in {rel}', file=sys.stderr)
            sys.exit(2)
        if text.count(old) > 1:
            print(f'ERROR: anchor matches multiple times for {label} in {rel}', file=sys.stderr)
            sys.exit(2)
        new_text = text.replace(old, new, 1)
        if apply:
            path.write_text(new_text)
        summary[label] = 'edited'
    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []

    tc = (root / 'app' / 'Http' / 'Controllers' / 'Tenant' / 'TeamController.php').read_text()
    if 'revokeAllForUser' in tc:
        failures.append('TeamController still calls revokeAllForUser')
    if 'device_count' in tc:
        failures.append('TeamController still references device_count')
    if 'sign_out_everywhere' in tc:
        failures.append('TeamController still has sign_out_everywhere case')

    ac = (root / 'app' / 'Http' / 'Controllers' / 'Tenant' / 'AccountController.php').read_text()
    if 'revokeAllForUser' in ac or 'signOutEverywhere' in ac or 'revokeDevice' in ac:
        failures.append('AccountController still has device/sign-out methods')

    dts = (root / 'app' / 'Services' / 'DeviceTrustService.php').read_text()
    if 'revokeAllForUser' in dts:
        failures.append('DeviceTrustService still defines revokeAllForUser')

    routes = (root / 'routes' / 'web.php').read_text()
    if 'account.sign-out-everywhere' in routes or 'account.device.revoke' in routes:
        failures.append('routes still expose account device/sign-out')

    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print(f'ERROR: {root} does not look like an intake repo', file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f'=== patch-130 [{mode}] target={root} ===\n')

    summary = process(root, apply=args.apply)
    print('Summary:')
    for k, v in summary.items():
        print(f'  {k}: {v}')

    if args.apply:
        print('\nVerifying...')
        failures = verify(root)
        if failures:
            print('\nFAIL:')
            for f in failures:
                print(f'  - {f}')
            sys.exit(1)
        print('  all checks pass')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
