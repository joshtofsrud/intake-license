<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDelivery;
use App\Models\Tenant\TenantRouteClosure;
use App\Models\Tenant\TenantRouteWindow;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Pickup & delivery: route windows (weekly and one-date), closed dates,
 * and the behaviour settings.
 *
 * Windows are edited from Booking Mode › Pickup & delivery (weekly grid) and
 * from Schedule › Deliveries (week and day views). Every change that would
 * strand a booked stop is refused and the stops are listed, so nothing
 * silently drops out of a window.
 */
class RouteWindowsController extends Controller
{
    /** how far ahead a weekly change is checked against booked stops */
    private const LOOKAHEAD_DAYS = 120;

    public function store(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);
        $data = $this->validated($request);
        $today = Carbon::now($tenant->timezone())->toDateString();

        if ($data['scope'] === 'date') {
            if ($data['date'] < $today) return $this->refuse($request, 'That date has passed.');
            if (TenantRouteWindow::dayClosed($tenant->id, $data['date'])) {
                return $this->refuse($request, 'That day is closed. Reopen it first.');
            }
        }

        TenantRouteWindow::create($this->attrs($data) + [
            'tenant_id'  => $tenant->id,
            'sort_order' => (int) TenantRouteWindow::where('tenant_id', $tenant->id)->max('sort_order') + 1,
        ]);

        return $this->done($request, $data['scope'] === 'date'
            ? 'Window added for ' . Carbon::parse($data['date'])->format('D, M j') . '.'
            : 'Window added.');
    }

    public function update(Request $request, string $id)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);
        $window = TenantRouteWindow::where('tenant_id', $tenant->id)->findOrFail($id);
        $data   = $this->validated($request, $window);

        // a one-date window stays a one-date window
        if ($window->isOneDate()) {
            $data['scope'] = 'date';
            $data['date']  = $window->onDateString();
            $stranded = $this->stranded($window, $data, [$data['date']]);
            if ($stranded) return $this->refuse($request, $this->strandedTitle($stranded), $stranded);
            $window->update($this->attrs($data));
            return $this->done($request, 'Window saved.');
        }

        // a weekly window changed for one date: close it that date, add a one-date copy
        if ($data['scope'] === 'date') {
            $stranded = $this->stranded($window, $data, [$data['date']]);
            if ($stranded) return $this->refuse($request, $this->strandedTitle($stranded), $stranded);
            TenantRouteClosure::firstOrCreate(['tenant_id' => $tenant->id, 'date' => $data['date'], 'window_id' => $window->id]);
            TenantRouteWindow::create($this->attrs($data) + ['tenant_id' => $tenant->id, 'sort_order' => $window->sort_order]);
            TenantRouteWindow::forgetClosures();
            return $this->done($request, 'Changed for ' . Carbon::parse($data['date'])->format('D, M j') . ' only.');
        }

        $stranded = $this->stranded($window, $data, $this->upcomingDates($tenant));
        if ($stranded) return $this->refuse($request, $this->strandedTitle($stranded), $stranded);
        $window->update($this->attrs($data));
        return $this->done($request, 'Window saved.');
    }

    public function destroy(Request $request, string $id)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);
        $window = TenantRouteWindow::where('tenant_id', $tenant->id)->findOrFail($id);
        $date   = $request->input('scope') === 'date' ? $this->dateInput($request) : null;

        // weekly window, one date: close just that window on that date
        if ($date && ! $window->isOneDate()) {
            $stops = $this->stopsFor($window, [$date]);
            if ($stops) return $this->refuse($request, $this->bookedTitle(count($stops), 'removed'), $stops);
            TenantRouteClosure::firstOrCreate(['tenant_id' => $tenant->id, 'date' => $date, 'window_id' => $window->id]);
            return $this->done($request, 'Removed for ' . Carbon::parse($date)->format('D, M j') . ' only.');
        }

        $dates = $window->isOneDate() ? [$window->onDateString()] : $this->upcomingDates($tenant);
        $stops = $this->stopsFor($window, $dates);
        if ($stops) return $this->refuse($request, $this->bookedTitle(count($stops), 'removed'), $stops);

        TenantRouteClosure::where('tenant_id', $tenant->id)->where('window_id', $window->id)->delete();
        $window->delete();
        return $this->done($request, 'Window removed.');
    }

    /** close a whole date */
    public function closeDay(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);
        $date = $this->dateInput($request);
        abort_unless($date, 422);

        $tz    = $tenant->timezone();
        $start = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $stops = TenantDelivery::with('customer')->where('tenant_id', $tenant->id)->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$start, $start->copy()->addDay()->subSecond()])
            ->orderBy('scheduled_at')->get()
            ->map(fn ($d) => $this->stopRow($d, $date))->all();
        if ($stops) return $this->refuse($request, $this->bookedTitle(count($stops), 'closed', Carbon::parse($date)->format('l')), $stops);

        TenantRouteClosure::firstOrCreate(['tenant_id' => $tenant->id, 'date' => $date, 'window_id' => null]);
        return $this->done($request, Carbon::parse($date)->format('D, M j') . ' closed.');
    }

    /** reopen a closed date, or bring back one window on a date */
    public function reopen(Request $request, string $closureId)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);
        $c = TenantRouteClosure::where('tenant_id', $tenant->id)->findOrFail($closureId);
        $c->delete();
        return $this->done($request, 'Reopened ' . Carbon::parse($c->date)->format('D, M j') . '.');
    }

    /** The behavior/promise knobs — tenant settings JSON, no migration. */
    public function saveSettings(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $data = $request->validate([
            'pd_flavor'            => ['required', 'in:queue,anchored'],
            'pd_windows_offered'   => ['required', 'integer', 'min:1', 'max:6'],
            'pd_assume_first_hour' => ['required', 'integer', 'min:12', 'max:23'],
            'pd_turnaround_label'  => ['nullable', 'string', 'max:30'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $settings['pd_flavor']                = $data['pd_flavor'];
        $settings['pd_windows_offered']       = (int) $data['pd_windows_offered'];
        $settings['pd_assume_first_hour']     = (int) $data['pd_assume_first_hour'];
        $settings['pd_turnaround_label']      = $data['pd_turnaround_label'] ?: '2–3 days';
        $settings['pd_auto_propose']          = (bool) $request->input('pd_auto_propose');
        $settings['pd_pay_before_delivery']   = (bool) $request->input('pd_pay_before_delivery');
        $settings['pd_online_pay_at_booking'] = (bool) $request->input('pd_online_pay_at_booking');
        $settings['pd_need_by_enabled']       = (bool) $request->input('pd_need_by_enabled');
        // how many days before the service date pickup can happen
        $settings['pd_pickup_lead_days']      = max(0, min(7, (int) $request->input('pd_pickup_lead_days', 1)));
        $settings['pd_allow_day_of']          = (bool) $request->input('pd_allow_day_of');
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Pickup & delivery settings saved.');
    }

    // ------------------------------------------------------------------

    private function validated(Request $request, ?TenantRouteWindow $window = null): array
    {
        $data = $request->validate([
            'starts_at'          => ['required', 'date_format:H:i'],
            'ends_at'            => ['required', 'date_format:H:i', 'after:starts_at'],
            'max_stops'          => ['required', 'integer', 'min:1', 'max:50'],
            'name'               => ['nullable', 'string', 'max:40'],
            'scope'              => ['required', 'in:date,weekly'],
            'date'               => ['nullable', 'date_format:Y-m-d', 'required_if:scope,date'],
            'days'               => ['nullable', 'array'],
            'days.*'             => ['integer', 'min:1', 'max:7'],
            'customers_can_book' => ['nullable', 'boolean'],
        ], [
            'ends_at.after'   => 'The end time must be after the start time.',
            'date.required_if' => 'Pick the date this window runs on.',
        ]);

        $days = array_values(array_unique(array_map('intval', $data['days'] ?? [])));
        sort($days);
        if ($data['scope'] === 'weekly' && ! $window?->isOneDate() && ! $days) {
            throw \Illuminate\Validation\ValidationException::withMessages(['days' => 'Pick at least one day.']);
        }
        $data['days'] = $days;
        $data['name'] = trim((string) ($data['name'] ?? '')) ?: null;
        $data['customers_can_book'] = $request->boolean('customers_can_book', true);
        return $data;
    }

    private function attrs(array $data): array
    {
        $oneDate = $data['scope'] === 'date';
        return [
            'starts_at'          => $data['starts_at'],
            'ends_at'            => $data['ends_at'],
            'max_stops'          => (int) $data['max_stops'],
            'name'               => $data['name'],
            'label'              => $data['name'] ?: TenantRouteWindow::autoLabel($data['starts_at'], $data['ends_at']),
            'on_date'            => $oneDate ? $data['date'] : null,
            'days'               => $oneDate ? [Carbon::parse($data['date'])->isoWeekday()] : $data['days'],
            'customers_can_book' => $data['customers_can_book'],
            'is_active'          => true,
        ];
    }

    private function dateInput(Request $request): ?string
    {
        $d = (string) $request->input('date');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** today onward, the window lookahead */
    private function upcomingDates($tenant): array
    {
        $d = Carbon::now($tenant->timezone())->startOfDay();
        $out = [];
        for ($i = 0; $i < self::LOOKAHEAD_DAYS; $i++) $out[] = $d->copy()->addDays($i)->toDateString();
        return $out;
    }

    /** booked stops in a window over dates it currently runs on */
    private function stopsFor(TenantRouteWindow $window, array $dates): array
    {
        $out = [];
        foreach ($this->stopsByDate($window, $dates) as $date => $stops) {
            foreach ($stops as $d) $out[] = $this->stopRow($d, $date);
        }
        return $out;
    }

    /**
     * Stops the change would strand: on days the new spec no longer runs,
     * outside its new times, or over its new stop count.
     */
    private function stranded(TenantRouteWindow $window, array $new, array $dates): array
    {
        $out = [];
        foreach ($this->stopsByDate($window, $dates) as $date => $stops) {
            $runs = $new['scope'] === 'date'
                ? $new['date'] === $date
                : in_array(Carbon::parse($date)->isoWeekday(), $new['days'], true);
            $inside = [];
            foreach ($stops as $d) {
                $t = tlocal_carbon($d->scheduled_at)?->format('H:i:s') ?? '';
                if ($runs && $t >= $new['starts_at'] . ':00' && $t < $new['ends_at'] . ':00') $inside[] = $d;
                else $out[] = $this->stopRow($d, $date);
            }
            if (count($inside) > (int) $new['max_stops']) {
                foreach ($inside as $d) $out[] = $this->stopRow($d, $date) + ['over' => true];
            }
        }
        return $out;
    }

    /** [date => deliveries] for the dates this window runs on now */
    private function stopsByDate(TenantRouteWindow $window, array $dates): array
    {
        $dates = array_values(array_filter($dates, fn ($d) => $window->runsOn(Carbon::parse($d))));
        if (! $dates) return [];
        $tz    = tenant()->timezone();
        $first = Carbon::parse(min($dates) . ' 00:00:00', $tz)->utc();
        $last  = Carbon::parse(max($dates) . ' 23:59:59', $tz)->utc();
        $set   = array_flip($dates);

        $by = [];
        TenantDelivery::with('customer')->where('tenant_id', $window->tenant_id)->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$first, $last])->orderBy('scheduled_at')->get()
            ->each(function ($d) use ($window, $set, &$by) {
                $local = tlocal_carbon($d->scheduled_at);
                if (! $local) return;
                $date = $local->toDateString();
                if (isset($set[$date]) && $window->covers($local->format('H:i:s'))) $by[$date][] = $d;
            });
        return $by;
    }

    private function stopRow(TenantDelivery $d, string $date): array
    {
        $name = trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: 'Customer';
        return [
            'id'   => (string) $d->id,
            'name' => $name,
            'type' => $d->type === 'dropoff' ? 'Dropoff' : 'Pickup',
            'when' => Carbon::parse($date)->format('D, M j') . ' · ' . (tlocal_carbon($d->scheduled_at)?->format('g:i a') ?? ''),
            'url'  => route('tenant.deliveries.index', ['view' => 'day', 'date' => $date, 'open' => (string) $d->id]),
        ];
    }

    private function strandedTitle(array $stops): string
    {
        $outside = collect($stops)->where('over', '!=', true)->unique('id')->count();
        if ($outside) {
            return $outside . ' booked ' . ($outside === 1 ? 'stop falls' : 'stops fall') . ' outside that. Move ' . ($outside === 1 ? 'it' : 'them') . ' first.';
        }
        $n = collect($stops)->unique('id')->count();
        return $n . ' stops are booked here, more than that allows. Move some first.';
    }

    private function bookedTitle(int $n, string $verb, ?string $what = null): string
    {
        return ($what ?: 'This window') . ' has ' . $n . ' booked ' . ($n === 1 ? 'stop' : 'stops') . ' and can’t be ' . $verb . '. Move or cancel ' . ($n === 1 ? 'it' : 'them') . ' first.';
    }

    private function refuse(Request $request, string $title, array $stops = [])
    {
        $stops = collect($stops)->unique('id')->values()->map(fn ($s) => collect($s)->except('over')->all())->all();
        if ($request->expectsJson()) return response()->json(['refused' => $title, 'stops' => $stops], 422);
        return back()->with('error', $title);
    }

    private function done(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            // the page reloads after a panel save; the flash shows the result there
            session()->flash('success', $message);
            return response()->json(['ok' => true, 'message' => $message]);
        }
        return back()->with('success', $message);
    }
}
