<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Launch\Launch;
use App\Support\Launch\LaunchChecks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-LAUNCH-CHECKLIST: the shop's side of a launch checklist. The e-commerce
 * checklist is the first tab of the E-commerce page; rentals has its own page.
 * Every action here is the shop's own: go live, take offline, tick, skip.
 */
class LaunchChecklistController extends Controller
{
    private function guard(string $module): void
    {
        $tenant = tenant();
        abort_unless($tenant && in_array($module, ['ecom', 'rent'], true) && isset(Launch::modules($tenant)[$module]), 404);
        $me = Auth::guard('tenant')->user();
        if ($module === 'ecom') {
            abort_unless($me && $me->canAccessSection('storefront'), 403);
        }
    }

    private function who(): string
    {
        return (string) (Auth::guard('tenant')->user()?->name ?: 'Your team');
    }

    private function back(string $module, string $msg, string $kind = 'success'): RedirectResponse
    {
        return $module === 'ecom'
            ? redirect()->route('tenant.storefront.settings', ['tab' => 'launch'])->with($kind, $msg)->with('ecom_tab', 'launch')
            : redirect()->route('tenant.launch.rentals')->with($kind, $msg);
    }

    /** GET /rentals/launch */
    public function rentals()
    {
        $this->guard('rent');
        $tenant = tenant();

        return view('tenant.launch.rentals', [
            'ev' => Launch::evaluate($tenant, 'rent'),
        ]);
    }

    public function goLive(string $module): RedirectResponse
    {
        $this->guard($module);
        $why = Launch::goLive(tenant(), $module, $this->who());
        if ($why) {
            return $this->back($module, 'Not live yet: ' . $why, 'error');
        }
        return $this->back($module, 'You are live. Anyone can now see your ' . Launch::thing($module) . '.');
    }

    public function offline(string $module): RedirectResponse
    {
        $this->guard($module);
        Launch::takeOffline(tenant(), $module, $this->who());
        return $this->back($module, 'Your ' . Launch::thing($module) . ' is back in preview. Only your team can see it.');
    }

    public function mark(Request $request, string $module): RedirectResponse
    {
        $this->guard($module);
        $data = $request->validate([
            'step'   => ['required', 'string', 'max:40'],
            'kind'   => ['required', 'in:confirmed,skipped,clear'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $tenant = tenant();
        $step = collect(Launch::steps($module))->firstWhere('key', $data['step']);
        abort_unless($step, 404);

        if ($data['kind'] === 'confirmed') {
            abort_unless($step->isConfirm(), 422);
        }
        if ($data['kind'] === 'skipped') {
            abort_unless($step->level === 'opt', 422);
        }
        if ($data['kind'] === 'clear') {
            // the shop can clear its own tick or skip, never Intake's waiver
            $existing = \App\Models\Launch\TenantLaunchMark::where('tenant_id', $tenant->id)->where('module', $module)->where('step_key', $step->key)->first();
            abort_if($existing && $existing->kind === 'waived', 403);
        }

        Launch::mark($tenant, $module, $step->key, $data['kind'] === 'clear' ? null : $data['kind'], $this->who(), $data['reason'] ?? null);

        return $this->back($module, match ($data['kind']) {
            'confirmed' => '"' . $step->title . '" ticked.',
            'skipped'   => '"' . $step->title . '" skipped.',
            default     => '"' . $step->title . '" is back on your list.',
        });
    }

    public function recheck(string $module): RedirectResponse
    {
        $this->guard($module);
        LaunchChecks::forget(tenant());
        return $this->back($module, 'Checked again just now.');
    }
}
