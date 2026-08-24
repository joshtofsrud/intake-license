<?php
// MARKER-ADMIN-GATE — admits only master-admin `users` rows. Reps authenticate
// on the same web guard with is_admin=false, so 'auth' alone is not a gate.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMasterAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'isMasterAdmin') || ! $user->isMasterAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
