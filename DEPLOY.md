# Intake — onboarding modal rewrite

Complete drop-in zip. Extract over `/var/www/intake/` on the server, or into
your local repo folder and push via GitHub Desktop.

---

## What's new / changed

### Architecture changes

**Killed the multi-page onboarding wizard.** No more `/admin/onboarding/branding`,
`/admin/onboarding/services`, `/admin/onboarding/complete` — all gone. New
tenants drop straight into the dashboard.

**In-dashboard onboarding modal.** On their first visit to `/admin` (and any
visit afterward until complete), a blurred modal covers the dashboard and
walks them through three short steps:

1. **Branding** — business name, tagline, accent color
2. **Services** — tier + category + first service + price
3. **Hours** — weekly schedule OR "always available"

All three are stored in the real tables (`tenants`, `tenant_service_*`,
`tenant_capacity_rules`) — no fake "step tracker." Completion is derived
from whether the data actually exists.

**Soft block, not hard.** Users can "Skip for now." A cookie hides the modal
for the session; it reopens next login. The DB is the source of truth, so
a user who manually fills in Services from the Services page will have that
step checked off when they next see the modal.

**Deferred items become dashboard cards.** Home page customization, team
invites, and payment setup show as "Finish setting up" cards on the
dashboard until done — a gentler nudge, not a blocker.

### Files changed

**New:**
- `app/Http/Controllers/Tenant/OnboardingModalController.php` — JSON endpoints
  for each step + dismiss + complete
- `resources/views/tenant/_onboarding_modal.blade.php` — the modal itself,
  self-contained HTML/CSS/JS, no Livewire (avoids the hijacking bug from
  the old flow)

**Deleted:**
- `app/Http/Controllers/Tenant/OnboardingController.php`
- `app/Http/Middleware/RequireOnboarded.php`
- `resources/views/tenant/onboarding/` (entire directory)

**Rewritten:**
- `app/Http/Controllers/Tenant/DashboardController.php` — computes `$progress`
- `app/Http/Controllers/Platform/OnboardingController.php` — signup now
  redirects to `/admin` (not `/admin/onboarding`) and seeds a basic home
  page so the public tenant URL doesn't 404
- `app/Http/Controllers/Tenant/AuthController.php` — cleaner, with debug
  Log::info calls so we can see auth attempts in the log
- `app/Providers/Filament/AdminPanelProvider.php` — adds
  `->domain(env('APP_DOMAIN'))` scoping so Filament only serves admin at
  `intake.works`, not subdomain collisions
- `app/Models/Tenant/TenantItemTierPrice.php` — adds missing `tier()` and
  `item()` relationships
- `app/Models/Tenant/TenantCapacityRule.php` — fillable matches real schema
  (includes `open_time`, `close_time`, `slot_interval_minutes`)
- `app/Models/User.php` — safer `canAccessPanel()` with env-email fallback
- `app/Http/Middleware/ResolveTenant.php` — injects `URL::defaults(['subdomain'])`
- `resources/views/tenant/dashboard.blade.php` — includes modal + deferred-
  item cards
- `resources/views/public/sections/_cta_banner.blade.php` — null-safe defaults
  so the public home page doesn't crash on missing `bg_color`
- `routes/web.php` — explicit controller bindings throughout, modal endpoints
  wired in

**Preserved (unchanged from server):**
- `.env`, `vendor/`, `.git/`, `storage/logs/`, `storage/framework/sessions/` —
  none of these are in the zip, so your existing state stays put.

---

## Deploy

### Option A — via GitHub (preferred)

1. Extract this zip into your local `intake-license` repo folder
2. GitHub Desktop shows changes
3. Commit: `Rewrite onboarding as dashboard modal`
4. Push — GitHub Actions deploys automatically

### Option B — direct server drop

```bash
scp intake-complete.zip root@142.93.50.209:/tmp/
ssh root@142.93.50.209
cd /var/www/intake
unzip -o /tmp/intake-complete.zip
chown -R www-data:www-data /var/www/intake
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
systemctl restart php8.3-fpm
```

---

## Clean out the test tenants (optional but recommended)

The `the-bike-hub` and `velonw` tenants were created during debugging and
are in weird half-onboarded states. They'll work under the new flow if you
keep them (the modal will reappear for any missing steps), but a clean
slate is easier for testing.

See `cleanup.sql` in this zip. Run it on the server:

```bash
mysql -u intake -pdeorext1 intake < /tmp/cleanup.sql
```

Then do a fresh signup at `https://app.intake.works/signup` to test the
full flow end-to-end.

---

## Verify

1. **Site still loads:** `https://intake.works` → marketing page
2. **Master admin login:** `https://intake.works/admin` → Filament dashboard
   for `joshtofsrud@gmail.com`
3. **Fresh tenant signup:**
   - Go to `https://app.intake.works/signup`
   - Fill form with a new subdomain (e.g. `testco`)
   - Submit
   - **Expected:** Lands on `https://testco.intake.works/admin`, already
     logged in, with the onboarding modal covering the dashboard
   - Click through branding → services → hours
   - **Expected:** Modal closes with a brief "🎉 All set!" and reloads into
     the clean dashboard with the deferred-item cards visible
4. **Skip flow:**
   - New signup → in modal, click "Skip for now"
   - **Expected:** Modal closes, dashboard visible, no errors
   - Log out and back in — modal reappears
5. **Public tenant URL:** `https://testco.intake.works` should render a
   basic home page (hero + services placeholder + CTA banner + footer),
   not 404 or 500

---

## Rollback

If something catastrophic breaks, the previous state is in git history.
Either:

- In GitHub Desktop: revert the commit
- On the server: `cd /var/www/intake && git reset --hard HEAD~1` (if you
  deployed via server-side git pull)

The DB migrations added in this change are all additive — nothing drops
tables or columns.
