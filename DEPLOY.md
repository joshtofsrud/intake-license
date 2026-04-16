# Intake — minimal dashboard reset

Complete drop-in zip. Purpose: strip the tenant dashboard down to bare
"Welcome" text so we can confirm the page loads at all, then add features
back one at a time.

---

## What this does

**Dashboard is now minimal.** No stats cards, no appointments table, no
onboarding modal, no "finish setting up" cards. Just a header + a welcome
message + the sidebar so you can navigate.

**Why:** we've been chasing a ghost where features cascade-crash each
other. Starting with known-good is faster than bisecting.

**What's still in the zip** (from the previous build, untouched):
- OnboardingModalController and onboarding modal view — exist but unwired,
  ready to add back later
- All other controllers (customers, services, pages, etc.)
- All routes
- All middleware

**What this zip does NOT delete** — extracting a zip can only ADD or
OVERWRITE files, not delete. So if your server has stale files from
before (old OnboardingController, RequireOnboarded middleware, old
onboarding views), they'll still be there after deploy.

---

## Critical: clean the server BEFORE deploying

Because the last deploy added new files without deleting old ones, the
server has two sets of onboarding code — old and new — and Laravel loads
both. We need to nuke the old ones first.

SSH into the server and run this BEFORE pushing the new code:

```bash
ssh root@142.93.50.209

cd /var/www/intake

# Delete stale files that shouldn't be here
rm -fv app/Http/Controllers/Tenant/OnboardingController.php
rm -fv app/Http/Middleware/RequireOnboarded.php
rm -rfv resources/views/tenant/onboarding

# Clear caches
sudo -u www-data php artisan optimize:clear
systemctl restart php8.3-fpm
```

Then extract this zip into your local repo, commit, push.

---

## Deploy (via GitHub)

1. Extract this zip into your local `intake-license` folder (overwrite all)
2. GitHub Desktop shows changes — mostly the dashboard view + controller
3. Commit: `Minimal dashboard — strip features for clean reset`
4. Push to main
5. Wait for GitHub Actions to go green

---

## Verify

After deploy completes:

1. Log in to a tenant admin, e.g. `https://<your-tenant>.intake.works/admin/login`
2. Should land on the dashboard
3. Expected page content:

> **Dashboard**
> <tenant name>
>
> *[card]*
> Welcome, <your name>.
> Your dashboard is loading. Features are being rebuilt one at a time...

4. Sidebar should still work — click around to Appointments, Customers, etc.
   Those sections have their own implementations and aren't affected by
   this reset.

**If any of that fails**, tell me the exact URL and what you see. Don't
guess — share the error.

---

## Next steps

Once the minimal dashboard loads, we add features back one at a time:

1. Stats row (today's jobs, this week, revenue, open jobs)
2. Recent appointments table
3. Finish-setup cards (home page / team / payment)
4. Onboarding modal (only if you want it — a checklist-in-dashboard is
   simpler and might be the better design)

Each addition gets tested independently before moving to the next.
