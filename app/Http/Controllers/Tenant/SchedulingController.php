<?php
// MARKER-PATCH-623 — staff scheduling phase 1: builder, time off, my schedule.
// Gates: scheduling.build (builder + shift writes), scheduling.timeoff
// (approve/deny). Any staff can view their own schedule and request time off.
// TIMEZONE: shift wall times are entered tenant-local, stored UTC, shown tlocal().

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantShift;
use App\Models\Tenant\TenantTimeOffRequest;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SchedulingController extends Controller
{
    /* ---------------------------------------------------------- builder */

    public function index(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        [$weekStart, $days, $fromUtc, $toUtc] = $this->week($request);

        $staff = TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']);

        $shifts = TenantShift::where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $fromUtc)->where('starts_at', '<', $toUtc)
            ->orderBy('starts_at')->get()->groupBy('tenant_user_id');

        $timeOff = TenantTimeOffRequest::where('tenant_id', $tenant->id)
            ->where('status', 'approved')
            ->where('starts_at', '<', $toUtc)->where('ends_at', '>', $fromUtc)
            ->get()->groupBy('tenant_user_id');

        // grid[userId][dayIdx] = ['shifts'=>[], 'off'=>bool]
        $grid = [];
        foreach ($staff as $m) {
            $grid[$m->id] = array_fill(0, 7, ['shifts' => [], 'off' => false]);
            foreach ($shifts->get($m->id, collect()) as $sh) {
                $idx = $this->dayIdx($sh->starts_at, $weekStart);
                $grid[$m->id][$idx]['shifts'][] = $sh;
            }
            foreach ($timeOff->get($m->id, collect()) as $to) {
                for ($i = 0; $i < 7; $i++) {
                    $day = $weekStart->copy()->addDays($i);
                    if ($to->starts_at->lt($day->copy()->endOfDay()->utc()) && $to->ends_at->gt($day->copy()->startOfDay()->utc())) {
                        $grid[$m->id][$i]['off'] = true;
                    }
                }
            }
        }

        $draftCount = TenantShift::where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $fromUtc)->where('starts_at', '<', $toUtc)
            ->whereNull('published_at')->count();

        $pendingTimeOff = TenantTimeOffRequest::where('tenant_id', $tenant->id)
            ->where('status', 'pending')->count();

        return view('tenant.scheduling.index', compact('staff', 'grid', 'days', 'weekStart', 'draftCount', 'pendingTimeOff'));
    }

    public function storeShift(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        $data = $request->validate([
            'tenant_user_id' => ['required', 'uuid'],
            'date'           => ['required', 'date'],
            'start_time'     => ['required', 'date_format:H:i'],
            'end_time'       => ['required', 'date_format:H:i'],
            'label'          => ['nullable', 'string', 'max:80'],
        ]);

        $tz    = $tenant->timezone();
        $start = \Carbon\Carbon::parse($data['date'] . ' ' . $data['start_time'], $tz);
        $end   = \Carbon\Carbon::parse($data['date'] . ' ' . $data['end_time'], $tz);
        if ($end->lte($start)) $end->addDay(); // overnight shift

        // block scheduling over approved time off
        $off = TenantTimeOffRequest::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $data['tenant_user_id'])
            ->where('status', 'approved')
            ->where('starts_at', '<', $end->copy()->utc())
            ->where('ends_at', '>', $start->copy()->utc())
            ->exists();
        if ($off) {
            return back()->with('error', 'That person has approved time off then — pick another day or person.');
        }

        TenantShift::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $data['tenant_user_id'],
            'location_id'    => session('current_location_id'),
            'starts_at'      => $start->utc(),
            'ends_at'        => $end->utc(),
            'label'          => $data['label'] ?? null,
            'created_by'     => $user->id,
        ]);

        return back()->with('success', 'Shift added (draft — publish when the week is ready).');
    }

    public function deleteShift(Request $request, string $shiftId)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        TenantShift::where('tenant_id', $tenant->id)->where('id', $shiftId)->delete();
        return back()->with('success', 'Shift removed.');
    }

    public function copyLastWeek(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        [$weekStart, , $fromUtc, $toUtc] = $this->week($request);
        $prevFrom = $weekStart->copy()->subWeek()->utc();
        $prevTo   = $weekStart->copy()->utc();

        $prev = TenantShift::where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $prevFrom)->where('starts_at', '<', $prevTo)->get();

        $copied = 0;
        foreach ($prev as $sh) {
            $newStart = $sh->starts_at->copy()->addWeek();
            $newEnd   = $sh->ends_at->copy()->addWeek();

            $dupe = TenantShift::where('tenant_id', $tenant->id)
                ->where('tenant_user_id', $sh->tenant_user_id)
                ->where('starts_at', $newStart)->exists();
            $off = TenantTimeOffRequest::where('tenant_id', $tenant->id)
                ->where('tenant_user_id', $sh->tenant_user_id)
                ->where('status', 'approved')
                ->where('starts_at', '<', $newEnd)->where('ends_at', '>', $newStart)->exists();
            if ($dupe || $off) continue;

            TenantShift::create([
                'tenant_id'      => $tenant->id,
                'tenant_user_id' => $sh->tenant_user_id,
                'location_id'    => $sh->location_id,
                'starts_at'      => $newStart,
                'ends_at'        => $newEnd,
                'label'          => $sh->label,
                'color'          => $sh->color,
                'created_by'     => $user->id,
            ]);
            $copied++;
        }

        return back()->with('success', "Copied {$copied} shift(s) from last week (skipped conflicts).");
    }

    public function publish(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        [, , $fromUtc, $toUtc] = $this->week($request);

        $drafts = TenantShift::with('user:id,name,email')
            ->where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $fromUtc)->where('starts_at', '<', $toUtc)
            ->whereNull('published_at')->get();

        if ($drafts->isEmpty()) {
            return back()->with('error', 'Nothing to publish — this week has no draft shifts.');
        }

        TenantShift::whereIn('id', $drafts->pluck('id'))->update(['published_at' => now()]);

        // Notify each affected person over the branded rail (best-effort).
        $emailer = new \App\Services\EmailService($tenant);
        foreach ($drafts->groupBy('tenant_user_id') as $userShifts) {
            $member = $userShifts->first()->user;
            if (!$member || !$member->email) continue;
            $lines = $userShifts->sortBy('starts_at')->map(function ($sh) {
                return '<tr><td style="padding:6px 12px 6px 0">' . tlocal_date($sh->starts_at, 'D M j') . '</td>'
                     . '<td style="padding:6px 0">' . tlocal($sh->starts_at) . ' – ' . tlocal($sh->ends_at)
                     . ($sh->label ? ' · ' . e($sh->label) : '') . '</td></tr>';
            })->implode('');
            $html = '<p>Your schedule has been published:</p><table style="font-size:14px">' . $lines . '</table>';
            try {
                $emailer->sendRendered('schedule_published', $member->email, 'Your schedule — ' . $tenant->name, $html);
            } catch (\Throwable $e) {
                logger()->warning('schedule publish notify failed', ['user' => $member->id, 'err' => $e->getMessage()]);
            }
        }

        return back()->with('success', 'Week published — ' . $drafts->count() . ' shift(s) visible to staff, notifications sent.');
    }

    /* ---------------------------------------------------------- time off */

    public function timeOff(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.timeoff'), 403);

        $pending  = TenantTimeOffRequest::with('user:id,name')->where('tenant_id', $tenant->id)
            ->where('status', 'pending')->orderBy('starts_at')->get();
        $upcoming = TenantTimeOffRequest::with('user:id,name')->where('tenant_id', $tenant->id)
            ->where('status', 'approved')->where('ends_at', '>=', now())
            ->orderBy('starts_at')->limit(20)->get();

        return view('tenant.scheduling.timeoff', compact('pending', 'upcoming'));
    }

    public function timeOffStore(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on'   => ['required', 'date', 'after_or_equal:starts_on'],
            'type'      => ['required', 'in:vacation,personal,sick,unavailable'],
            'reason'    => ['nullable', 'string', 'max:500'],
        ]);

        $tz = $tenant->timezone();
        TenantTimeOffRequest::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $user->id,
            'starts_at'      => \Carbon\Carbon::parse($data['starts_on'], $tz)->startOfDay()->utc(),
            'ends_at'        => \Carbon\Carbon::parse($data['ends_on'], $tz)->endOfDay()->utc(),
            'all_day'        => true,
            'type'           => $data['type'],
            'reason'         => $data['reason'] ?? null,
        ]);

        return back()->with('success', 'Time-off request submitted.');
    }

    public function timeOffReview(Request $request, string $requestId)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.timeoff'), 403);

        $data = $request->validate([
            'decision'    => ['required', 'in:approved,denied'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $req = TenantTimeOffRequest::where('tenant_id', $tenant->id)->where('id', $requestId)->firstOrFail();
        $req->update([
            'status'      => $data['decision'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        return back()->with('success', 'Request ' . $data['decision'] . '.');
    }

    /* ---------------------------------------------------------- my schedule */

    public function mine(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        [$weekStart, $days, $fromUtc, $toUtc] = $this->week($request);

        $shifts = TenantShift::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->whereNotNull('published_at')
            ->where('starts_at', '>=', $fromUtc)->where('starts_at', '<', $toUtc)
            ->orderBy('starts_at')->get();

        $requests = TenantTimeOffRequest::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->orderByDesc('starts_at')->limit(10)->get();

        $weekMinutes = $shifts->sum(fn ($s) => $s->minutes());

        return view('tenant.scheduling.mine', compact('shifts', 'requests', 'days', 'weekStart', 'weekMinutes'));
    }

    /* ---------------------------------------------------------- helpers */

    /** Week window from ?week=YYYY-MM-DD (tenant-local). [$weekStart, $days, $fromUtc, $toUtc] */
    private function week(Request $request): array
    {
        $tenant = tenant();
        $anchor = $request->filled('week')
            ? \Carbon\Carbon::parse($request->input('week'), $tenant->timezone())
            : tnow();
        $weekStart = $anchor->copy()->startOfWeek();
        $days = [];
        for ($i = 0; $i < 7; $i++) $days[] = $weekStart->copy()->addDays($i);
        return [$weekStart, $days, $weekStart->copy()->utc(), $weekStart->copy()->addWeek()->utc()];
    }

    private function dayIdx($utcInstant, $weekStart): int
    {
        $local = tlocal_carbon($utcInstant)->startOfDay();
        return max(0, min(6, (int) abs($local->diffInDays($weekStart->copy()->startOfDay()))));
    }
}

