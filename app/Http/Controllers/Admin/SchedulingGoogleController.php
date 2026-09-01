<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// MARKER-SCHED-GOOGLE — the OAuth round trip. Gated to the scheduling area in routes.
class SchedulingGoogleController extends Controller
{
    public function connect(Request $request, GoogleCalendarService $google)
    {
        if (! $google->configured()) {
            return redirect('/admin/scheduling-availability')->with('google_error', 'GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are not set in the server .env.');
        }
        $state = Str::random(32);
        $request->session()->put('google_oauth_state', $state);
        return redirect()->away($google->authUrl($state));
    }

    public function callback(Request $request, GoogleCalendarService $google)
    {
        $expected = $request->session()->pull('google_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/admin/scheduling-availability')->with('google_error', 'Connection attempt didn\'t match — try again.');
        }
        if ($request->query('error')) {
            return redirect('/admin/scheduling-availability')->with('google_error', 'Google said: ' . $request->query('error'));
        }
        $err = $google->handleCallback((string) $request->query('code'));
        return redirect('/admin/scheduling-availability')->with($err ? 'google_error' : 'google_ok', $err ?: 'Google Calendar connected.');
    }

    public function disconnect(GoogleCalendarService $google)
    {
        $google->disconnect();
        return redirect('/admin/scheduling-availability')->with('google_ok', 'Google Calendar disconnected.');
    }
}
