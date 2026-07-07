<?php
// MARKER-REPPANEL-SETUP — public tokenized setup page (Team & access pattern).
// The raw token lives only in the email link; DB stores its sha256.

namespace App\Http\Controllers;

use App\Models\SalesRep;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RepSetupController extends Controller
{
    private function repForToken(string $token): ?SalesRep
    {
        $rep = SalesRep::where('invite_token', hash('sha256', $token))
            ->whereNull('user_id')
            ->first();

        if (! $rep || ! $rep->invited_at || $rep->invited_at->lt(now()->subDays(7))) {
            return null;
        }
        return $rep;
    }

    public function show(string $token)
    {
        $rep = $this->repForToken($token);
        abort_unless($rep, 404);

        return view('rep.setup', ['rep' => $rep, 'token' => $token]);
    }

    public function store(Request $request, string $token)
    {
        $rep = $this->repForToken($token);
        abort_unless($rep, 404);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (User::where('email', $rep->email)->exists()) {
            return back()->withErrors(['password' => 'An account with this email already exists. Contact Intake support.']);
        }

        $user = User::create([
            'name'     => $rep->name,
            'email'    => $rep->email,
            'password' => $data['password'],   // 'hashed' cast on the model
            'is_admin' => false,               // never the master admin panel
        ]);

        $rep->forceFill([
            'user_id'      => $user->id,
            'invite_token' => null,
            'invited_at'   => null,
        ])->save();

        return redirect('/rep')->with('status', 'Account created — sign in below.');
    }
}
