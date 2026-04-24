<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    /**
     * Admin calendar landing page. Renders the day view.
     * Week and month views arrive in later sessions; their toolbar
     * buttons exist but are disabled.
     */
    public function index(Request $request)
    {
        // Resolve the target date from the URL. Default to today.
        // Invalid or missing dates silently fall back to today rather than
        // throwing — keeps the page robust against malformed bookmarks or
        // link-building by humans.
        $dateParam = $request->query('date');
        try {
            $date = $dateParam ? Carbon::parse($dateParam)->startOfDay() : Carbon::today();
        } catch (\Throwable $e) {
            $date = Carbon::today();
        }

        // Pre-compute prev/next for use in the toolbar hrefs.
        $prevDate = $date->copy()->subDay()->toDateString();
        $nextDate = $date->copy()->addDay()->toDateString();
        $todayStr = Carbon::today()->toDateString();
        $isToday  = $date->isToday();

        // The current "view mode" — locked to day for now, but the toolbar
        // renders Week / Month buttons as disabled placeholders so the
        // transition is a one-line unlock later.
        $viewMode = 'day';

        return view('tenant.calendar.index', [
            'date'     => $date,
            'prevDate' => $prevDate,
            'nextDate' => $nextDate,
            'todayStr' => $todayStr,
            'isToday'  => $isToday,
            'viewMode' => $viewMode,
        ]);
    }
}
