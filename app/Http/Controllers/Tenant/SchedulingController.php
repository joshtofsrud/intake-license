<?php
// staff scheduling phase 1: builder, time off, my schedule.
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

        // feature settings + demand overlay + templates
        $set = $this->settings($tenant);

        $demand = [];
        if ($set['demand_overlay']) {
            // Booking density per day/band from the appointment calendar.
            // appointment_date/time are naive tenant-local wall-clock — perfect here.
            $rows = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
                ->whereBetween('appointment_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
                ->whereNotIn('status', ['cancelled'])
                ->get(['appointment_date', 'appointment_time']);
            $demand = array_fill(0, 7, [0, 0, 0, 0]); // bands: <10, 10-13, 13-16, 16+
            $max = 1;
            foreach ($rows as $a) {
                $idx  = $this->dayIdx(\Carbon\Carbon::parse($a->appointment_date->format('Y-m-d'), $tenant->timezone())->utc(), $weekStart);
                $hour = (int) substr((string) $a->appointment_time, 0, 2);
                $band = $hour < 10 ? 0 : ($hour < 13 ? 1 : ($hour < 16 ? 2 : 3));
                $demand[$idx][$band]++;
                $max = max($max, $demand[$idx][$band]);
            }
            $demand = ['bands' => $demand, 'max' => $max];
        }

        // the pattern comes along so each row can say
        // what it holds; you can't choose which of two to delete otherwise.
        $templates = \App\Models\Tenant\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->orderBy('name')->get(['id', 'name', 'pattern', 'last_applied_at'])
            ->map(function ($t) {
                $pattern = (array) $t->pattern;
                $t->shift_count  = count($pattern);
                $t->people_count = count(array_unique(array_filter(array_column($pattern, 'user_id'))));
                return $t;
            });

        // Availability conflicts: mark each shift chip that overlaps a band the
        // person marked unavailable (inline ! with tooltip).
        // defined either way: compact() below names it, and
        // with Staff availability switched off it was undefined, so the builder
        // page threw instead of loading.
        $availability = [];
        if ($set['availability']) {
            $availability = \App\Models\Tenant\TenantAvailability::where('tenant_id', $tenant->id)
                ->where('preference', 'unavailable')
                ->get()->groupBy('tenant_user_id')
                ->map(fn ($rows) => $rows->map(fn ($r) => $r->day_of_week . ':' . $r->band)->all())
                ->all();

            foreach ($grid as $uid => &$cells) {
                if (empty($availability[$uid])) continue;
                foreach ($cells as &$cell) {
                    foreach ($cell['shifts'] as $sh) {
                        $localStart = tlocal_carbon($sh->starts_at);
                        $localEnd   = tlocal_carbon($sh->ends_at);
                        $dow = (int) $localStart->dayOfWeek;
                        $conflict = false;
                        foreach ($this->spannedBands($localStart, $localEnd) as $band) {
                            if (in_array($dow . ':' . $band, $availability[$uid], true)) { $conflict = true; break; }
                        }
                        $sh->avail_conflict = $conflict; // transient, view-only
                    }
                }
                unset($cell);
            }
            unset($cells);
        }

        // MARKER-SCHED-COVERAGE: Grid or Coverage
        // MARKER-SCHED-COVERAGE-DEFAULT: Coverage unless ?view=grid
        $view     = $request->query('view') === 'grid' ? 'grid' : 'coverage';
        $coverage = $view === 'coverage' ? $this->coverage($tenant, $staff, $grid, $days, $set) : null;

        return view('tenant.scheduling.index', compact('staff', 'grid', 'days', 'weekStart', 'draftCount', 'pendingTimeOff', 'set', 'demand', 'templates', 'availability', 'view', 'coverage'));
    }

    /** scheduling feature settings with defaults. */
    private function settings($tenant): array
    {
        $s = $tenant->settings ?? [];
        return [
            'demand_overlay'      => (bool) ($s['scheduling_demand_overlay'] ?? true),
            'availability'        => (bool) ($s['scheduling_availability'] ?? true),
            'notify_publish'      => (bool) ($s['scheduling_notify_publish'] ?? true),
            'timeoff_notice_days' => (int) ($s['scheduling_timeoff_notice_days'] ?? 0),
            // staffing guide; 0 = off.
            'bookings_per_staff'  => (int) ($s['scheduling_bookings_per_staff'] ?? 0),
            // Coverage view: fewer than this on while open shows amber.
            'min_on'              => max(1, (int) ($s['scheduling_min_on'] ?? 1)),
        ];
    }

    /**
     * MARKER-SCHED-COVERAGE: data for the Coverage view. Minutes are
     * tenant-local wall clock; a shift running past midnight is drawn to the
     * end of its first day. Open hours come from the default capacity rules,
     * with a dated override (or closure) winning for its day.
     */
    private function coverage($tenant, $staff, array $grid, array $days, array $set): array
    {
        $rules = \App\Models\Tenant\TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')->get()->keyBy('day_of_week');
        $overrides = \App\Models\Tenant\TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$days[0]->toDateString(), $days[6]->toDateString()])
            ->get()->keyBy(fn ($r) => $r->specific_date->toDateString());
        $toMin = fn ($t) => $t ? ((int) substr((string) $t, 0, 2)) * 60 + (int) substr((string) $t, 3, 2) : null;

        $hours = [];
        foreach ($days as $i => $d) {
            $rule = $rules->get((int) $d->dayOfWeek);
            $ov   = $overrides->get($d->toDateString());
            if ($ov && ($ov->is_closed || $ov->open_time)) {
                $rule = $ov;
            }
            $open  = ($rule && ! $rule->is_closed) ? $toMin($rule->open_time) : null;
            $close = ($rule && ! $rule->is_closed) ? $toMin($rule->close_time) : null;
            $hours[$i] = ($open !== null && $close !== null && $close > $open) ? [$open, $close] : null;
        }

        $palette = ['#BEF264', '#60A5FA', '#F472B6', '#FBBF24', '#A78BFA', '#34D399', '#FB923C', '#22D3EE', '#F87171', '#E879F9'];
        $people = [];
        $shifts = [];
        $off    = [];
        foreach ($staff->values() as $k => $m) {
            $people[] = ['id' => $m->id, 'name' => $m->name, 'color' => $palette[$k % count($palette)]];
            for ($i = 0; $i < 7; $i++) {
                if (! empty($grid[$m->id][$i]['off'])) {
                    $off[] = ['u' => $m->id, 'd' => $i];
                }
                foreach ($grid[$m->id][$i]['shifts'] as $sh) {
                    $ls = tlocal_carbon($sh->starts_at);
                    $le = tlocal_carbon($sh->ends_at);
                    $s  = $ls->hour * 60 + $ls->minute;
                    $e  = $le->isSameDay($ls) ? $le->hour * 60 + $le->minute : 1440;
                    $shifts[] = [
                        'id'    => $sh->id,
                        'u'     => $m->id,
                        'd'     => $i,
                        's'     => $s,
                        'e'     => max($e, $s + 15),
                        'draft' => ! $sh->published_at,
                        'label' => $sh->label,
                        'warn'  => ! empty($sh->avail_conflict),
                    ];
                }
            }
        }

        // draw from the earliest open/shift hour to the latest, whole hours
        $lo = 8 * 60;
        $hi = 18 * 60;
        foreach ($hours as $h) {
            if ($h) { $lo = min($lo, $h[0]); $hi = max($hi, $h[1]); }
        }
        foreach ($shifts as $s) {
            $lo = min($lo, $s['s']);
            $hi = max($hi, $s['e']);
        }
        $lo = max(0, (int) floor($lo / 60) * 60);
        $hi = min(1440, (int) ceil($hi / 60) * 60);

        $now   = tnow();
        $today = null;
        foreach ($days as $i => $d) {
            if ($d->isSameDay($now)) { $today = $i; }
        }

        return [
            'days'   => array_map(fn ($d) => ['w' => $d->format('D'), 'n' => $d->format('j'), 'date' => $d->toDateString(), 'label' => $d->format('D M j')], $days),
            'hours'  => $hours,
            'people' => $people,
            'shifts' => $shifts,
            'off'    => $off,
            'min_on' => $set['min_on'],
            'lo'     => $lo,
            'hi'     => $hi,
            'now'    => $today === null ? null : ['d' => $today, 'm' => $now->hour * 60 + $now->minute],
            'sel'    => $today ?? 0,
            'multi_location' => \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)->count() > 1,
        ];
    }

    /** settings page. */
    public function settingsPage(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);
        return view('tenant.scheduling.settings', ['set' => $this->settings($tenant)]);
    }

    /** save scheduling settings (Settings tab). */
    public function saveSettings(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        $data = $request->validate([
            'scheduling_timeoff_notice_days' => ['required', 'integer', 'min:0', 'max:60'],
            'scheduling_bookings_per_staff'  => ['nullable', 'integer', 'min:0', 'max:50'],
            'scheduling_min_on'              => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['scheduling_demand_overlay'] = (bool) $request->input('scheduling_demand_overlay');
        $settings['scheduling_availability']   = (bool) $request->input('scheduling_availability');
        $settings['scheduling_notify_publish'] = (bool) $request->input('scheduling_notify_publish');
        $settings['scheduling_timeoff_notice_days'] = $data['scheduling_timeoff_notice_days'];
        $settings['scheduling_bookings_per_staff']  = (int) ($data['scheduling_bookings_per_staff'] ?? 0);
        $settings['scheduling_min_on']              = max(1, (int) ($data['scheduling_min_on'] ?? 1));
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Scheduling settings saved.');
    }

    /** save this week's shifts as a named template. */
    public function saveTemplate(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:80'],
            'confirm_overwrite' => ['nullable', 'boolean'],
        ]);
        [$weekStart, , $fromUtc, $toUtc] = $this->week($request);

        // updateOrCreate on name used to replace an
        // existing template silently. Make the trade explicit instead.
        $existing = \App\Models\Tenant\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->where('name', trim($data['name']))->first();
        if ($existing && ! $request->boolean('confirm_overwrite')) {
            return back()->with('tpl_overwrite', [
                'name'  => $existing->name,
                'has'   => count((array) $existing->pattern),
                'week'  => $weekStart->toDateString(),
            ]);
        }

        $shifts = TenantShift::where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $fromUtc)->where('starts_at', '<', $toUtc)->get();
        if ($shifts->isEmpty()) {
            return back()->with('error', 'Nothing to save — this week has no shifts.');
        }

        $tz = $tenant->timezone();
        $pattern = $shifts->map(function ($sh) use ($weekStart, $tz) {
            return [
                'day_of_week' => (int) tlocal_carbon($sh->starts_at)->dayOfWeek,
                'day_offset'  => $this->dayIdx($sh->starts_at, $weekStart),
                'start'       => tlocal($sh->starts_at, 'H:i'),
                'end'         => tlocal($sh->ends_at, 'H:i'),
                'user_id'     => $sh->tenant_user_id,
                'label'       => $sh->label,
            ];
        })->values()->all();

        \App\Models\Tenant\TenantShiftTemplate::updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => trim($data['name'])],
            ['pattern' => $pattern, 'created_by' => $user->id]
        );

        return back()->with('success', 'Template "' . $data['name'] . '" saved (' . count($pattern) . ' shifts).');
    }

    /** remove a saved pattern. Shifts already on the
     *  calendar are untouched; only the template row goes. */
    public function deleteTemplate(Request $request, string $templateId)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        $tpl = \App\Models\Tenant\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->where('id', $templateId)->firstOrFail();
        $name = $tpl->name;
        $tpl->delete();

        return back()->with('success', 'Template "' . $name . '" deleted. Shifts on the calendar were not changed.');
    }

    /** apply a template onto the current week (skips conflicts). */
    public function applyTemplate(Request $request, string $templateId)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        $tpl = \App\Models\Tenant\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->where('id', $templateId)->firstOrFail();
        [$weekStart] = $this->week($request);
        $tz = $tenant->timezone();

        $added = 0;
        foreach ((array) $tpl->pattern as $p) {
            $day   = $weekStart->copy()->addDays((int) ($p['day_offset'] ?? 0));
            $start = \Carbon\Carbon::parse($day->toDateString() . ' ' . $p['start'], $tz);
            $end   = \Carbon\Carbon::parse($day->toDateString() . ' ' . $p['end'], $tz);
            if ($end->lte($start)) $end->addDay();

            $dupe = TenantShift::where('tenant_id', $tenant->id)
                ->where('tenant_user_id', $p['user_id'])
                ->where('starts_at', $start->copy()->utc())->exists();
            $off = TenantTimeOffRequest::where('tenant_id', $tenant->id)
                ->where('tenant_user_id', $p['user_id'])
                ->where('status', 'approved')
                ->where('starts_at', '<', $end->copy()->utc())->where('ends_at', '>', $start->copy()->utc())->exists();
            $exists = TenantUser::where('tenant_id', $tenant->id)->where('id', $p['user_id'])->where('is_active', true)->exists();
            if ($dupe || $off || !$exists) continue;

            TenantShift::create([
                'tenant_id'      => $tenant->id,
                'tenant_user_id' => $p['user_id'],
                'starts_at'      => $start->utc(),
                'ends_at'        => $end->utc(),
                'label'          => $p['label'] ?? null,
                'created_by'     => $user->id,
            ]);
            $added++;
        }

        $tpl->update(['last_applied_at' => now()]);

        return back()->with('success', 'Applied "' . $tpl->name . '" — ' . $added . ' shift(s) added (conflicts skipped).');
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
            // the phone sheet edits a shift by replacing it,
            // and can pick the location when a shop has more than one.
            'replace_shift_id' => ['nullable', 'uuid'],
            'location_id'      => ['nullable', 'uuid'],
        ]);

        // MARKER-SHIFT-REASSIGN: the person on an edited shift can change, so
        // the old shift is found by id alone (it may belong to someone else).
        abort_unless(TenantUser::where('tenant_id', $tenant->id)->where('id', $data['tenant_user_id'])->exists(), 422);
        $old = ! empty($data['replace_shift_id'])
            ? TenantShift::where('tenant_id', $tenant->id)->where('id', $data['replace_shift_id'])->first()
            : null;

        $tz    = $tenant->timezone();
        $start = \Carbon\Carbon::parse($data['date'] . ' ' . $data['start_time'], $tz);
        $end   = \Carbon\Carbon::parse($data['date'] . ' ' . $data['end_time'], $tz);
        if ($end->lte($start)) $end->addDay(); // overnight shift

        // block scheduling over approved time off; the rest are warnings
        $conf = $this->shiftConflicts($tenant, $data['tenant_user_id'], $start, $end, $old?->id);
        if ($conf['approved']) {
            return back()->with('error', $conf['name'] . ' has approved time off then — pick another day or person.');
        }

        TenantShift::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $data['tenant_user_id'],
            'location_id'    => (! empty($data['location_id'])
                                    && \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)->where('id', $data['location_id'])->exists())
                                ? $data['location_id'] : ($old?->location_id ?: session('current_location_id')),
            'starts_at'      => $start->utc(),
            'ends_at'        => $end->utc(),
            'label'          => $data['label'] ?? null,
            'created_by'     => $user->id,
        ]);

        // an edit replaces the old shift, only once the new
        // one is saved (a refused save leaves the original untouched).
        if ($old) {
            $moved = $old->tenant_user_id !== $data['tenant_user_id'];
            $old->delete();
            $note = $this->conflictNote($conf);
            return back()->with('success', ($moved ? 'Shift moved to ' . $conf['name'] : 'Shift updated') . ' (draft — publish when the week is ready).' . $note);
        }

        // conflicts now surface as an inline ! marker on the
        // shift chip in the grid (see index()), not a flash.
        return back()->with('success', 'Shift added (draft — publish when the week is ready).' . $this->conflictNote($conf));
    }

    /**
     * MARKER-SHIFT-REASSIGN: what stands in the way of putting this person on
     * this shift. Approved time off blocks; a pending request, a recurring
     * "unavailable" band and an overlapping shift are warnings.
     */
    private function shiftConflicts($tenant, string $userId, $start, $end, ?string $ignoreShiftId = null): array
    {
        $name = (string) TenantUser::where('tenant_id', $tenant->id)->where('id', $userId)->value('name');
        $first = explode(' ', trim($name))[0] ?: 'That person';

        $timeOff = fn (string $status) => TenantTimeOffRequest::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $userId)->where('status', $status)
            ->where('starts_at', '<', $end->copy()->utc())
            ->where('ends_at', '>', $start->copy()->utc())
            ->exists();

        $unavailable = [];
        if ($this->settings($tenant)['availability']) {
            $localStart = $start->copy()->setTimezone($tenant->timezone());
            $localEnd   = $end->copy()->setTimezone($tenant->timezone());
            $dow   = (int) $localStart->dayOfWeek;
            $words = ['morning' => 'mornings', 'afternoon' => 'afternoons', 'evening' => 'evenings'];
            $bad   = \App\Models\Tenant\TenantAvailability::where('tenant_id', $tenant->id)
                ->where('tenant_user_id', $userId)->where('preference', 'unavailable')
                ->where('day_of_week', $dow)->pluck('band')->all();
            foreach ($this->spannedBands($localStart, $localEnd) as $band) {
                if (in_array($band, $bad, true)) $unavailable[] = $words[$band] ?? $band;
            }
        }

        $overlap = TenantShift::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $userId)
            ->when($ignoreShiftId, fn ($q) => $q->where('id', '!=', $ignoreShiftId))
            ->where('starts_at', '<', $end->copy()->utc())
            ->where('ends_at', '>', $start->copy()->utc())
            ->exists();

        return [
            'name'        => $first,
            'approved'    => $timeOff('approved'),
            'pending'     => $timeOff('pending'),
            'unavailable' => $unavailable,
            'overlap'     => $overlap,
        ];
    }

    /** One plain sentence per warning, for the dialog and the flash. */
    private function conflictLines(array $c): array
    {
        $out = [];
        if ($c['approved'])           $out[] = $c['name'] . ' has approved time off then.';
        if ($c['pending'])            $out[] = $c['name'] . ' has a time-off request waiting for review that covers this.';
        if ($c['unavailable'])        $out[] = $c['name'] . ' marked ' . implode(' and ', $c['unavailable']) . ' unavailable on this day.';
        if ($c['overlap'])            $out[] = $c['name'] . ' already has a shift at that time.';
        return $out;
    }

    private function conflictNote(array $c): string
    {
        $lines = $this->conflictLines($c);
        return $lines ? ' Heads up: ' . implode(' ', $lines) : '';
    }

    /** MARKER-SHIFT-REASSIGN: live check behind the shift dialog. */
    public function shiftCheck(Request $request)
    {
        $tenant = tenant();
        abort_unless(Auth::guard('tenant')->user()->can('scheduling.build'), 403);

        $d = $request->validate([
            'user'    => ['required', 'uuid'],
            'date'    => ['required', 'date'],
            'start'   => ['required', 'date_format:H:i'],
            'end'     => ['required', 'date_format:H:i'],
            'replace' => ['nullable', 'uuid'],
        ]);
        abort_unless(TenantUser::where('tenant_id', $tenant->id)->where('id', $d['user'])->exists(), 404);

        $tz    = $tenant->timezone();
        $start = \Carbon\Carbon::parse($d['date'] . ' ' . $d['start'], $tz);
        $end   = \Carbon\Carbon::parse($d['date'] . ' ' . $d['end'], $tz);
        if ($end->lte($start)) $end->addDay();

        $c = $this->shiftConflicts($tenant, $d['user'], $start, $end, $d['replace'] ?? null);
        return response()->json([
            'blocked' => $c['approved'],
            'lines'   => $this->conflictLines($c),
        ]);
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

        // Notify each affected person over the branded rail (best-effort),
        // unless publishing notifications are turned off in settings.
        if (! $this->settings($tenant)['notify_publish']) {
            return back()->with('success', 'Week published — ' . $drafts->count() . ' shift(s) visible to staff.');
        }
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

        // minimum-notice policy (0 = off).
        $notice = $this->settings($tenant)['timeoff_notice_days'];
        if ($notice > 0 && \Carbon\Carbon::parse($data['starts_on'], $tz)->lt(tnow()->addDays($notice)->startOfDay())) {
            return back()->with('error', "Time-off requests need at least {$notice} days' notice.");
        }

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

    /* ---------------------------------------------------------- availability */

    /** staff paint their recurring day/band availability. */
    public function availability(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($this->settings($tenant)['availability'], 404);

        $rows = \App\Models\Tenant\TenantAvailability::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)->get();

        // map["{day}:{band}"] = preference (default 'available')
        $map = [];
        foreach ($rows as $r) $map[$r->day_of_week . ':' . $r->band] = $r->preference;

        return view('tenant.scheduling.availability', ['map' => $map, 'set' => $this->settings($tenant)]);
    }

    public function availabilityStore(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($this->settings($tenant)['availability'], 404);

        $data = $request->validate(['cells' => ['nullable', 'string', 'max:4000']]);
        // cells = comma list of "day:band:preference"
        $seen = [];
        foreach (array_filter(explode(',', (string) ($data['cells'] ?? ''))) as $cell) {
            [$day, $band, $pref] = array_pad(explode(':', $cell), 3, null);
            if (!in_array((int) $day, range(0, 6), true)) continue;
            if (!in_array($band, ['morning', 'afternoon', 'evening'], true)) continue;
            if (!in_array($pref, ['available', 'prefer', 'unavailable'], true)) continue;
            \App\Models\Tenant\TenantAvailability::updateOrCreate(
                ['tenant_id' => $tenant->id, 'tenant_user_id' => $user->id, 'day_of_week' => (int) $day, 'band' => $band],
                ['preference' => $pref]
            );
            $seen[] = (int) $day . ':' . $band;
        }
        // anything not sent reverts to default available
        \App\Models\Tenant\TenantAvailability::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->get()
            ->each(function ($r) use ($seen) {
                if (!in_array($r->day_of_week . ':' . $r->band, $seen, true)) $r->delete();
            });

        return back()->with('success', 'Availability saved — the builder will flag conflicts.');
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

    /** Bands (morning 0-12 / afternoon 12-17 / evening 17-24) a local shift spans. */
    private function spannedBands($localStart, $localEnd): array
    {
        $startH = (int) $localStart->format('G');
        $endH   = (int) $localEnd->format('G') + ((int) $localEnd->format('i') > 0 ? 1 : 0);
        if (! $localEnd->isSameDay($localStart)) $endH = 24; // overnight: rest of day
        $bands = [];
        if ($startH < 12 && $endH > 0)  $bands[] = 'morning';
        if ($startH < 17 && $endH > 12) $bands[] = 'afternoon';
        if ($endH > 17)                 $bands[] = 'evening';
        return $bands ?: ['morning'];
    }

    private function dayIdx($utcInstant, $weekStart): int
    {
        $local = tlocal_carbon($utcInstant)->startOfDay();
        return max(0, min(6, (int) abs($local->diffInDays($weekStart->copy()->startOfDay()))));
    }
}

