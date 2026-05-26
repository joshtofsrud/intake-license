<?php
// MARKER-PATCH-147

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailSuppression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-facing suppression list.
 *
 * Shows addresses that won't receive this tenant's mail, scoped to
 * tenant-specific rows AND platform-wide rows that would affect them.
 *
 * Manager+ permission required (same gate as Settings).
 */
class SuppressionController extends Controller
{
    /**
     * GET /admin/email/suppressions
     */
    public function index(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return redirect()->route('tenant.dashboard');
        }

        $tenant = tenant();
        $tab = $request->query('tab', 'all');

        // Pull both tenant-scoped and platform-wide suppressions that would
        // block this tenant's outbound mail.
        $query = TenantEmailSuppression::where(function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)
              ->orWhereNull('tenant_id');
        });

        switch ($tab) {
            case 'bounced':
                $query->where('reason', 'bounce');
                break;
            case 'complained':
                $query->where('reason', 'complaint');
                break;
            case 'other':
                $query->whereIn('reason', ['unsubscribe', 'manual']);
                break;
            // 'all' — no extra filter
        }

        $rows = $query->orderByDesc('suppressed_at')->paginate(50);

        // Tab counts (always over the full set, not filtered)
        $base = TenantEmailSuppression::where(function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id');
        });
        $counts = [
            'all'        => (clone $base)->count(),
            'bounced'    => (clone $base)->where('reason', 'bounce')->count(),
            'complained' => (clone $base)->where('reason', 'complaint')->count(),
            'other'      => (clone $base)->whereIn('reason', ['unsubscribe', 'manual'])->count(),
        ];

        return view('tenant.email.suppressions', [
            'rows'   => $rows,
            'counts' => $counts,
            'tab'    => $tab,
        ]);
    }

    /**
     * DELETE /admin/email/suppressions/{id}
     *
     * Removes a tenant-scoped suppression. Platform-wide rows and
     * complaint-reason rows are not removable here.
     */
    public function destroy(int $id)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $tenant = tenant();
        $row = TenantEmailSuppression::where('id', $id)
            ->where('tenant_id', $tenant->id)   // only tenant-scoped rows
            ->first();

        if (! $row) {
            return back()->with('error', 'Suppression not found or not yours to remove.');
        }
        if ($row->reason === 'complaint') {
            return back()->with('error', 'Complaints cannot be removed — the recipient marked your mail as spam.');
        }

        $email = $row->email;
        $row->delete();

        Log::info('Suppression removed', [
            'tenant_id' => $tenant->id,
            'email'     => $email,
            'by'        => $me->email,
        ]);

        return back()->with('success', "Removed {$email} from your suppression list.");
    }

    /**
     * POST /admin/email/suppressions
     *
     * Manually suppress an address for this tenant only.
     */
    public function store(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant = tenant();
        $email = strtolower(trim($data['email']));

        TenantEmailSuppression::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'reason'                => 'manual',
                'notes'                 => $data['notes'] ?? null,
                'suppressed_by_user_id' => $me->id,
                'suppressed_at'         => now(),
            ]
        );

        Log::info('Suppression added (manual)', [
            'tenant_id' => $tenant->id,
            'email'     => $email,
            'by'        => $me->email,
        ]);

        return back()->with('success', "{$email} will no longer receive mail.");
    }
}
