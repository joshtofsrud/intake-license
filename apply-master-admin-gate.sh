#!/usr/bin/env bash
# MARKER-ADMIN-GATE — close the privilege hole: /admin/impersonate/* and the
# marketing-page bridge were middleware(['auth']) only, and reps are real
# `users` rows (is_admin=false) on the same web guard. Adds:
#   1. User::isMasterAdmin() — the same three-layer check canAccessPanel uses
#   2. EnsureMasterAdmin middleware (403 otherwise)
#   3. Applies it to both route groups
set -e

if grep -q "MARKER-ADMIN-GATE" app/Models/User.php; then
  echo "ok: already applied"
  exit 0
fi

# ---------- 1. User::isMasterAdmin() ----------
python3 - <<'EOF'
import io
p = "app/Models/User.php"
s = io.open(p, encoding="utf-8").read()
old = """    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'rep') {
            return $this->salesRep()->where('status', 'active')->exists();
        }

"""
new = """    // MARKER-ADMIN-GATE — the same admin test canAccessPanel applies, callable
    // from route middleware. Bridge routes (impersonation, marketing pages)
    // MUST use this: 'auth' alone also admits rep accounts.
    public function isMasterAdmin(): bool
    {
        $bootstrap = strtolower((string) config('intake.admin_email', ''));
        if ($bootstrap !== '' && strtolower((string) $this->email) === $bootstrap) {
            return true;
        }
        if (array_key_exists('is_admin', $this->getAttributes())) {
            return (bool) $this->is_admin;
        }
        return true; // column not migrated yet — same safety valve as canAccessPanel
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'rep') {
            return $this->salesRep()->where('status', 'active')->exists();
        }

"""
assert s.count(old) == 1, "canAccessPanel anchor"
io.open(p, "w", encoding="utf-8").write(s.replace(old, new))
print("ok: User::isMasterAdmin")
EOF

# ---------- 2. middleware ----------
cat > app/Http/Middleware/EnsureMasterAdmin.php <<'EOF'
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
EOF
echo "ok: middleware written"

# ---------- 3. route groups ----------
python3 - <<'EOF'
import io
p = "routes/web.php"
s = io.open(p, encoding="utf-8").read()

old = """    // --- Impersonation (admin only) ---
    Route::middleware(['auth'])->group(function () {
"""
new = """    // --- Impersonation (admin only) ---
    // MARKER-ADMIN-GATE — 'auth' alone also admits rep accounts.
    Route::middleware(['auth', \\App\\Http\\Middleware\\EnsureMasterAdmin::class])->group(function () {
"""
assert s.count(old) == 1, "impersonation group anchor"
s = s.replace(old, new)

old = """    // POST handles auto-save (section content, nav, page meta).
    Route::middleware(['auth'])->group(function () {
"""
new = """    // POST handles auto-save (section content, nav, page meta).
    // MARKER-ADMIN-GATE — 'auth' alone also admits rep accounts.
    Route::middleware(['auth', \\App\\Http\\Middleware\\EnsureMasterAdmin::class])->group(function () {
"""
assert s.count(old) == 1, "marketing bridge group anchor"
s = s.replace(old, new)

io.open(p, "w", encoding="utf-8").write(s)
print("ok: route groups gated")
EOF

echo "ok: done — 3 steps"
