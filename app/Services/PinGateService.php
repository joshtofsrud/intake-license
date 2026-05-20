<?php

namespace App\Services;

use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PinGateService
 *
 * The mechanic for Layer 4 of the auth refactor (sensitive-action gates).
 * See auth-refactor-spec-v2.md §6.
 *
 * Each gated action is identified by a string key (e.g. 'switch_location',
 * 'refund', 'void'). Calling code asks:
 *
 *   - requirePin($request, $action): does this request need a PIN re-prompt
 *     for this action? Returns false if the tenant is not pin_tier_active,
 *     OR if a recent PIN confirmation for THIS action exists in the session
 *     within the configured sticky window.
 *
 *   - confirm($request, $action, $pin, $user): verify the PIN against the
 *     current user and, on success, record the confirmation for this action
 *     in the session. Returns true on success, false on bad PIN.
 *
 * Sticky window per action lives in config/intake.php auth.pin_action_sticky_sec.
 * A value of 0 means "always prompt" (chunk 7's launch behavior for
 * switch_location).
 */
class PinGateService
{
    public function __construct(protected PinService $pins) {}

    /**
     * Does this request need a PIN re-prompt for $action?
     *
     * Returns true if the gate should fire (UI must show PIN entry).
     */
    public function requirePin(Request $request, string $action): bool
    {
        $tenant = app('tenant') ?? null;
        if (! $tenant || ! $tenant->pin_tier_active) {
            return false;
        }

        $stickyConfig = config('intake.auth.pin_action_sticky_sec', []);
        $stickySec = (int) ($stickyConfig[$action] ?? 0);

        if ($stickySec === 0) {
            // No sticky window — every action requires PIN.
            return true;
        }

        // Sticky window > 0 — check if there's a recent confirmation in session.
        $confirmations = $request->session()->get('pin_confirmed_actions', []);
        $confirmedAtIso = $confirmations[$action] ?? null;

        if (! $confirmedAtIso) {
            return true; // never confirmed → require
        }

        try {
            $confirmedAt = \Illuminate\Support\Carbon::parse($confirmedAtIso);
        } catch (\Throwable $e) {
            return true;
        }

        // If within sticky window, no PIN needed.
        return $confirmedAt->lt(now()->subSeconds($stickySec));
    }

    /**
     * Verify a PIN attempt for an action. On success, record the confirmation
     * timestamp in the session so subsequent sticky-window checks pass.
     *
     * Returns true on success, false on bad PIN.
     */
    public function confirm(Request $request, string $action, string $pin, TenantUser $user): bool
    {
        if (! $user->pin_hash) {
            return false;
        }

        if ($this->pins->isLocked($user)) {
            return false;
        }

        try {
            $ok = $this->pins->verifyPin($user, $pin);
        } catch (\DomainException $e) {
            return false;
        }

        if (! $ok) {
            return false;
        }

        // Success — record confirmation timestamp for this action.
        $confirmations = $request->session()->get('pin_confirmed_actions', []);
        $confirmations[$action] = now()->toIso8601String();
        $request->session()->put('pin_confirmed_actions', $confirmations);

        return true;
    }
}
