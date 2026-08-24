<?php
// MARKER-ADMIN-ROLES — per-area enforcement for the admin panel and the
// bridge routes. With no explicit :area parameter, the area is derived from
// the URL; unmapped admin paths require owner/admin (safe-closed). Livewire's
// panel endpoint passes through — its components are only reachable from
// pages this middleware already allowed to load.

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAdminArea
{
    public function handle(Request $request, Closure $next, ?string $area = null): Response
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'roleName')) {
            abort(403);
        }
        if (($user->suspended_at ?? null) !== null) {
            Auth::guard('web')->logout();
            abort(403, 'This account is suspended.');
        }

        if ($area === null) {
            $segs  = explode('/', trim($request->path(), '/'));
            $first = ($segs[0] ?? '') === 'admin' ? ($segs[1] ?? '') : ($segs[0] ?? '');
            if ($first === 'livewire') {
                return $next($request);
            }
            $area = AdminAccess::areaForAdminPath($first);
        }

        if ($area === null) {
            if (! in_array($user->roleName(), ['owner', 'admin'], true)) {
                abort(403);
            }
            return $next($request);
        }

        if (! AdminAccess::allows($user, $area)) {
            abort(403);
        }

        return $next($request);
    }
}
