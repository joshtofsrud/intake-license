#!/usr/bin/env python3
"""Time-off alerts: tell the people who need to act.

Gap found alongside the lifecycle patch: a submitted request notified
NOBODY. No alert, no email — the only signal was a count badge on the
schedule builder's Time off tab, which you see only if you're already
there. A request could sit unreviewed indefinitely.

Adds three staff-alert events wired through the existing
StaffAlertService (bell, per-user prefs, optional SMS):
  timeoff.requested  -> only users who can review (never all staff)
  timeoff.decided    -> the requester, pairing with the decision email
  timeoff.withdrawn  -> reviewers, so a vanished queue item is explained
Approvers also get an email on a new request, because in-app alerts are
addon-gated and a non-addon tenant would otherwise stay blind.
Run from repo root: python3 apply-timeoff-alerts.py
"""
import sys

def read(p):
    with open(p) as f: return f.read()
def write(p, s):
    with open(p, 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

CTRL  = 'app/Http/Controllers/Tenant/SchedulingController.php'
SVC   = 'app/Services/Tenant/StaffAlertService.php'
PREFS = 'app/Http/Controllers/Tenant/StaffAlertController.php'
NOTIF = 'resources/views/tenant/notifications.blade.php'

# ============================================================
# 1) Service — register the three events' channel defaults
# ============================================================
sub(SVC,
    """        'announcement'           => ['in_app' => true,  'sms' => false],
    ];""",
    """        'announcement'           => ['in_app' => true,  'sms' => false],
        // MARKER-TOFF-ALERTS
        'timeoff.requested'      => ['in_app' => true,  'sms' => false],
        'timeoff.decided'        => ['in_app' => true,  'sms' => false],
        'timeoff.withdrawn'      => ['in_app' => true,  'sms' => false],
    ];""",
    "service: defaults")

# ============================================================
# 2) Prefs page — so each person can mute or add SMS
# ============================================================
sub(PREFS,
    """        'lease.created'          => 'New lease',
    ];""",
    """        'lease.created'          => 'New lease',
        // MARKER-TOFF-ALERTS
        'timeoff.requested'      => 'New time-off request (reviewers)',
        'timeoff.decided'        => 'Your time-off request was decided',
        'timeoff.withdrawn'      => 'Time-off request withdrawn (reviewers)',
    ];""",
    "prefs: events")

sub(NOTIF,
    """    'announcement'            => ['Announcement', '📣'],""",
    """    'announcement'            => ['Announcement', '📣'],
    'timeoff.requested'       => ['Time off', '🌴'],
    'timeoff.decided'         => ['Time off', '🌴'],
    'timeoff.withdrawn'       => ['Time off', '🌴'],""",
    "notifications page: labels")

# ============================================================
# 3) Controller — reviewer fan-out helper
# ============================================================
sub(CTRL,
    """    /** MARKER-TOFF — a decision that never reaches the person isn't one. */""",
    """    /**
     * MARKER-TOFF-ALERTS — alert the people who can actually act on this.
     * emit() fans out to ALL active staff by default, so target the
     * reviewers one at a time via only_user_id instead.
     */
    private function timeOffAlertReviewers(string $event, string $title, string $body, ?string $exceptUserId = null): void
    {
        $tenant = tenant();
        $alerts = app(\\App\\Services\\Tenant\\StaffAlertService::class);

        $reviewers = TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->get()
            ->filter(fn ($u) => $u->can('scheduling.timeoff'));

        foreach ($reviewers as $reviewer) {
            $alerts->emit($tenant, $event, [
                'title'        => $title,
                'body'         => $body,
                'link'         => route('tenant.scheduling.timeoff'),
                'only_user_id' => $reviewer->id,
            ]);
        }
    }

    /** MARKER-TOFF — a decision that never reaches the person isn't one. */""",
    "controller: reviewer fan-out")

# ============================================================
# 4) Controller — notify on submit (alert + email to reviewers)
# ============================================================
sub(CTRL,
    """        TenantTimeOffRequest::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $user->id,
            'starts_at'      => \\Carbon\\Carbon::parse($data['starts_on'], $tz)->startOfDay()->utc(),
            'ends_at'        => \\Carbon\\Carbon::parse($data['ends_on'], $tz)->endOfDay()->utc(),
            'all_day'        => true,
            'type'           => $data['type'],
            'reason'         => $data['reason'] ?? null,
        ]);

        return back()->with('success', 'Time-off request submitted.');""",
    """        $req = TenantTimeOffRequest::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $user->id,
            'starts_at'      => \\Carbon\\Carbon::parse($data['starts_on'], $tz)->startOfDay()->utc(),
            'ends_at'        => \\Carbon\\Carbon::parse($data['ends_on'], $tz)->endOfDay()->utc(),
            'all_day'        => true,
            'type'           => $data['type'],
            'reason'         => $data['reason'] ?? null,
        ]);

        // MARKER-TOFF-ALERTS — a request used to reach nobody: no alert, no
        // email, just a count badge on a page you had to already be on.
        $span = tlocal_date($req->starts_at, 'D M j');
        if (tlocal_date($req->starts_at) !== tlocal_date($req->ends_at)) {
            $span .= ' – ' . tlocal_date($req->ends_at, 'D M j');
        }
        $this->timeOffAlertReviewers(
            'timeoff.requested',
            $user->name . ' requested time off',
            $span . ' · ' . $data['type'],
            $user->id,
        );
        $this->timeOffEmailReviewers($user, $span, $data['type'], $data['reason'] ?? null);

        return back()->with('success', 'Time-off request submitted — your manager has been notified.');""",
    "controller: notify on submit")

# ============================================================
# 5) Controller — reviewer email (in-app is addon-gated; email isn't)
# ============================================================
sub(CTRL,
    """    /** MARKER-TOFF — staff pull back their own request while it's pending. */""",
    """    /**
     * MARKER-TOFF-ALERTS — in-app alerts need the staff_alerts addon, so a
     * tenant without it would still never hear about a pending request.
     */
    private function timeOffEmailReviewers($requester, string $span, string $type, ?string $reason): void
    {
        $tenant = tenant();

        $reviewers = TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('id', '!=', $requester->id)
            ->get()
            ->filter(fn ($u) => $u->can('scheduling.timeoff') && $u->email);

        if ($reviewers->isEmpty()) return;

        $html = '<p><b>' . e($requester->name) . '</b> requested time off.</p>'
              . '<p style="font-size:15px"><b>' . $span . '</b> · ' . e($type) . '</p>'
              . ($reason ? '<p style="background:#f6f6f4;border-radius:7px;padding:9px 11px">' . e($reason) . '</p>' : '')
              . '<p style="color:#666;font-size:13px">Review it under Scheduling → Time off.</p>';

        $emailer = new \\App\\Services\\EmailService($tenant);
        foreach ($reviewers as $reviewer) {
            try {
                $emailer->sendRendered(
                    'timeoff_requested',
                    $reviewer->email,
                    'Time-off request from ' . $requester->name . ' — ' . $tenant->name,
                    $html,
                );
            } catch (\\Throwable $e) {
                logger()->warning('timeoff request notify failed', ['to' => $reviewer->id, 'err' => $e->getMessage()]);
            }
        }
    }

    /** MARKER-TOFF — staff pull back their own request while it's pending. */""",
    "controller: reviewer email")

# ============================================================
# 6) Controller — in-app alert to the requester on every decision
# ============================================================
sub(CTRL,
    """        try {
            (new \\App\\Services\\EmailService($tenant))
                ->sendRendered('timeoff_' . $kind, $member->email, $subject, $html);
        } catch (\\Throwable $e) {
            logger()->warning('timeoff notify failed', ['request' => $req->id, 'err' => $e->getMessage()]);
        }""",
    """        try {
            (new \\App\\Services\\EmailService($tenant))
                ->sendRendered('timeoff_' . $kind, $member->email, $subject, $html);
        } catch (\\Throwable $e) {
            logger()->warning('timeoff notify failed', ['request' => $req->id, 'err' => $e->getMessage()]);
        }

        // MARKER-TOFF-ALERTS — same news in the bell, for whoever lives in the app.
        app(\\App\\Services\\Tenant\\StaffAlertService::class)->emit($tenant, 'timeoff.decided', [
            'title'        => match ($kind) {
                'approved' => 'Time off approved',
                'denied'   => "Time off wasn't approved",
                default    => 'Time-off approval withdrawn',
            },
            'body'         => $span . ($note ? ' — ' . $note : ''),
            'link'         => route('tenant.scheduling.mine'),
            'only_user_id' => $member->id,
        ]);""",
    "controller: decision alert")

# ============================================================
# 7) Controller — withdrawal tells the reviewers
# ============================================================
sub(CTRL,
    """        $req->update(['status' => 'withdrawn', 'reviewed_at' => now()]);

        return back()->with('success', 'Request withdrawn.');""",
    """        $req->update(['status' => 'withdrawn', 'reviewed_at' => now()]);

        // MARKER-TOFF-ALERTS — it just left their queue; say why.
        $span = tlocal_date($req->starts_at, 'D M j');
        if (tlocal_date($req->starts_at) !== tlocal_date($req->ends_at)) {
            $span .= ' – ' . tlocal_date($req->ends_at, 'D M j');
        }
        $this->timeOffAlertReviewers(
            'timeoff.withdrawn',
            $user->name . ' withdrew a time-off request',
            $span . ' · no longer needs review',
            $user->id,
        );

        return back()->with('success', 'Request withdrawn.');""",
    "controller: withdrawal alert")

print("\\nDone. No migration needed. view:clear after deploy.")
