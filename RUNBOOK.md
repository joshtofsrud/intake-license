# RUNBOOK

Operator manual for Intake. Read top-to-bottom on first contact. Reference by section after that.

If you're joining this codebase fresh, the **30-minute onboarding** at the bottom is the fast path to "I can ship a patch safely." Everything above it is reference.

---

## What this is

Intake is a multi-tenant SaaS for service shops — bike shops, salons, fitness studios, pet groomers, etc. Each tenant runs at a subdomain (`thebikehub.intake.works`, `mountainviewfitness.intake.works`). Marketing site at `intake.works`.

Stack: Laravel 11 + MySQL + PHP 8.3 on a single DigitalOcean droplet. Stripe for subscription billing + Stripe Connect (configured, not yet integrated) for tenant payment processing. PayPal as alternative payment processor. Resend for transactional email.

Single-developer codebase. Y1 target: 500 tenants. 5yr target: large-SaaS-competitive. Every architectural decision is sized for 10K+ tenants, 50K rows each.

---

## Deploy

### Standard deploy (any change involving PHP)

On Mac (source of truth):
```bash
git add <changed-files>
git commit -m "<message>"
git push
```

On server (`root@intake-production:/var/www/intake`):
```bash
cd /var/www/intake
git pull
composer install --no-interaction --no-scripts
php artisan optimize:clear
sudo systemctl stop php8.3-fpm
sleep 2
sudo systemctl start php8.3-fpm
```

**Always stop/sleep/start** — never `restart`. Restart sometimes leaves stale opcache state on PHP 8.3 + FPM with our config. Stop + sleep + start gives a clean process tree.

### View-only deploy (Blade or CSS or JS-asset only)

```bash
cd /var/www/intake
git pull
php artisan view:clear
sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
```

`view:clear` is faster than `optimize:clear` and sufficient when only views/assets changed.

### Seeder run (after seeder changes)

```bash
php artisan db:seed --class=<SeederName> --force
```

Most seeders are idempotent (`updateOrCreate` + delete-and-reinsert children). Safe to re-run. `PlatformMarketingSeeder` specifically rewrites all sections inside seeded pages but keeps page rows.

### Emergency rollback

```bash
cd /var/www/intake
git log --oneline -10           # find last-known-good commit
git reset --hard <commit-hash>
php artisan optimize:clear
sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
```

Database migrations don't auto-roll-back. If a migration ran that needs reverting, write a new migration that undoes it — don't `migrate:rollback` on production unless the migration is truly trivial.

---

## Local development

Working dir on Mac: the iCloud-synced `intake-license` folder. Auto-commits run periodically — never write scratch files, test scripts, or `.bak` files inside the project directory. iCloud will sync them, auto-commit will add them, and the repo becomes a mess.

Scratch work goes in `/tmp` or a sibling directory outside the project.

---

## The patch-file convention

Every meaningful change ships as a numbered shell script in the repo root: `patch-NN-short-description.sh`. Conventions:

- **Idempotent.** Every modification has a `if X in s: SKIP` check that detects whether the change has already been applied. Running twice should produce all SKIPs, no errors.
- **Anchored.** Each `str.replace` uses a specific multi-line anchor from the existing file. If the anchor isn't found, the patch aborts with a clear message — never silently does nothing.
- **Documented at the top.** Header comment explains what the patch does, which files it touches, and what verification looks like.
- **Reversible-ish.** Patches don't ship destructive operations (file deletions, drops, truncates) without explicit guard. Reverting usually means writing a new patch.

This pattern means every change has a script that documents *what* changed, not just *that* it changed. Six months later you can read the patch and know what you were thinking.

### File-edit pattern (non-negotiable)

For modifying existing files:

```bash
python3 <<'PYEOF'
from pathlib import Path
p = Path("path/to/file.php")
s = p.read_text()

old = "exact existing string to match"
new = "replacement string"

# Idempotency check — verify the END state, not the start state.
if "<unique marker from new>" in s:
    print("    SKIP <thing> — already applied")
elif old not in s:
    raise SystemExit("ABORT <thing>: anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED <thing>")
PYEOF
```

**Critical:** the idempotency check matches against something unique to the *new* state, not just the function name. If the patch adds both a call site and a definition for the same function, checking for the function name matches the call site (which exists after the patch starts running) and skips the definition. False SKIP = ship a broken patch. Anchor on `private function <name>(` not just `<name>`.

For Blade content with `$variables` or single quotes, use shell heredoc instead of Python (Python's `str.replace` over-escapes):

```bash
cat > resources/views/path/file.blade.php <<'EOF'
@php $foo = 'bar'; @endphp
<div>{{ $foo }}</div>
EOF
```

For brand-new files, use the `create_file` Claude tool, not heredoc — cleaner attribution in history.

---

## Architectural conventions

### Tenant URL pattern

Tenant routes use `Route::domain('{subdomain}.intake.works')`. The **first parameter** of every tenant controller method is `string $subdomain`. Forgetting this is a silent bug class — Laravel will pass the subdomain into the wrong parameter and you'll get unrelated errors.

```php
public function index(Request $request, string $subdomain)
{ ... }

public function show(Request $request, string $subdomain, $id)
{ ... }
```

### The `/admin/` prefix gotcha (banked the hard way)

All tenant SPA fetches **must** include the `/admin/` prefix. Routes like `/customers/search` and `/appointments/week-times` silently 404 because Laravel falls through to a generic route fallback that returns the wrong thing rather than failing loudly.

Correct: `/admin/customers/search`, `/admin/appointments/week-times`
Wrong: `/customers/search`, `/appointments/week-times`

When wiring a new SPA endpoint, run `php artisan route:list | grep <feature>` to confirm the actual route exists at the prefix you're calling.

### Work orders: EAV + promoted identifier

Custom work-order fields are stored EAV-style (entity-attribute-value) with a promoted "identifier" field per tenant configuration. Field labels are **snapshotted on write** — when a work order is saved, the field label is copied into the work order row. Later renaming the field doesn't retroactively change historical records. Industry packs (config-driven) seed Pattern C work-order field defaults.

### Calendar status pipeline

Locked status sequence: `pending → confirmed → in_progress → completed`. Terminals: `cancelled`, `refunded`. Two optional per-tenant extensions, off by default:
- "Shipping enabled" → adds `shipped`
- "Use closed state" → adds `closed` after `completed`

Calendar visually reflects status: pending = dashed/light, confirmed = solid, in_progress = accent border, completed = muted + check, cancelled = hidden.

### Calendar color system

Two-tier:
1. **Tenant-controlled** resource colors (15 swatches, lime-adjacent excluded). Used for border-left + tinted background on appointments.
2. **System-locked** state tokens — booked, bookend, break, now, walk-in hold. These are not editable by tenants.

Lime `#BEF264` is reserved for: now-line, walk-in hold, hot-day indicator. Don't repurpose it.

### Tenant pivot conventions

For service ↔ resource eligibility: **empty pivot = all resources eligible**. This is a deliberate choice for the common case (single-resource shops, or shops where any resource can do any service). When restricting, the pivot is populated with explicit rows.

For service ↔ staff eligibility: same convention. Empty = anyone.

The admin UI must communicate this clearly — see patch 59 (chip dimming/dashed treatment) for the current pattern.

### Booking concurrency

`BookingService::createAppointment()` wraps the critical section in MySQL advisory locks via `MySQLLock` service. Locks are scoped per-tenant + per-resource + per-slot (time-slot mode) or per-tenant + per-day (drop-off mode). Two concurrent bookings of the same slot: one succeeds (200), the other gets `409 slot_taken`. Lock timeout returns `409 lock_timeout`.

Test with `scripts/test-booking-race.sh`.

### "Tenant brand top, ours footer"

Tenant chrome (sidebar, mobile header, branded marketing pages) shows the tenant's logo, colors, and name. Intake branding only appears in the footer ("Powered by Intake") and in admin-only system pages. The favicon is technically shared across all tenant subdomains — known limitation, requires custom-domain feature to fully resolve.

### "Snapshot on write"

Anywhere a label, name, or display value could change over time, snapshot it at write time so historical records preserve their original meaning. Examples: work-order field labels, service names on appointment rows, prices on sale lines. Don't render historical data through current configuration — render through the snapshot.

---

## Key directories and files

```
app/
  Http/Controllers/
    Platform/MarketingController.php       Public marketing site routing
    Tenant/                                Tenant-scoped controllers
      AppointmentController.php            show=view, drawer+jsonDetail=JSON
      DashboardController.php              index + dayJson (AJAX day-swap)
      PageBuilderController.php            CMS section types in DEFAULTS const
      RegisterController.php               POS register
      ServiceController.php                Services CRUD
  Models/
    Tenant/
      TenantAppointment.php
      TenantCapacityRule.php
      TenantPageSection.php                content casts to array
      TenantResource.php
      TenantServiceItem.php
  Services/
    BookingService.php                     Race-safe booking with advisory locks
    IndustryPackService.php                Reads config/industry_packs.php
    MySQLLock.php                          Advisory lock service
    StripeBillingService.php               Subscription billing
    StripeWebhookController.php            P3 handlers, idempotency table
    Tenant/DashboardDataService.php        zoneToday + dayData + strip builder

config/
  industry_packs.php                       12 pre-configured industry packs
  
database/
  migrations/                              Stripe webhook idempotency table
  seeders/
    PlatformMarketingSeeder.php            14 marketing pages (incl. legal)
    PlatformTenantSeeder.php
    AddonCatalogSeeder.php
    ChangelogAndRoadmapSeeder.php

resources/views/
  marketing/
    layout.blade.php                       Marketing site shell
    sections/                              CMS section partials
      hero.blade.php
      feature_grid.blade.php
      pricing_table.blade.php
      legal_doc.blade.php                  Long-form prose (added patch 58)
      screen_showcase.blade.php            Step + desktop/mobile mockup
      ...
  tenant/
    dashboard.blade.php
    dashboard/_zone_today.blade.php        Day strip (load indicator)
    services/index.blade.php               Services admin + chip CSS
    walkin/index.blade.php                 Walk-in flow
    calendar/_week-grid.blade.php

public/
  js/tenant/
    services.js                            Service chip rendering (line ~374)
    walkin.js
  css/tenant/
    dashboard.css                          Day-card CSS
```

---

## Tenants

### Dogfood: `thebikehub.intake.works`

Owner email: `josh@thebikehub.com`. **Never delete.** Use for:
- Pre-deploy QA
- Real Stripe webhook flows (subscription only — Connect not wired yet)
- Production smoke-tests

### Demo: `blueridge.intake.works`

Drop-off mode (bike shop with day-based capacity rather than time slots). Used for demos requiring drop-off workflow.

### Test: `mountainviewfitness.intake.works` (fitnesstest)

Time-slot mode (fitness studio with per-resource slot capacity). Used for testing time-slot booking flows.

---

## Stripe

### Subscription billing (live)

`StripeBillingService` handles trialing, full subscriptions, and billing portal. `StripeWebhookController` has 5 handlers with idempotency via `stripe_webhook_events` table.

### Connect (approved, not yet integrated)

Connect application is approved and configured but the tenant-facing wiring isn't done. Pending work:
- Tenant onboarding flow (OAuth or Express account creation)
- Connect-account ID storage on tenant model
- Webhook handlers for Connect events (`account.updated`, `payout.paid`)
- Payment routing via `application_fee_amount` or `transfer_data[destination]`
- Connected-account dashboard surface in tenant admin

Estimated work: ~4 hours.

### Test card: `4242 4242 4242 4242`, any future expiry, any CVC.

---

## Common gotchas (banked from real bugs)

### Mobile-nav z-index

`.mobile-nav` is `z-index: 100`. Any fixed-positioned mobile element must be `> 100` to be clickable (z-index 110 is the convention). The walk-in `.wi-bottom` bar at z-index 80 was invisible on mobile until patch 52 raised it.

### View cache after Blade changes

Blade compiled views cache aggressively. After any Blade edit, `php artisan view:clear` on server. After a *config* change, `php artisan optimize:clear` (clears more).

### Browser JS cache

Static assets (`public/js/*`, `public/css/*`) deploy with the codebase but the browser caches them. After a JS or CSS deploy, instruct the user to hard-refresh (Cmd+Shift+R on Mac, Ctrl+Shift+R elsewhere). Or — better — implement asset versioning. Currently we don't.

### Tenant SPA route 404 silent failure

Already covered above. The single biggest bug class in this codebase. When a tenant SPA fetch returns weirdly, first check: does the route have the `/admin/` prefix?

### Defensive fallbacks hide bugs

A pattern caught in patch 50: "if eligible-resources returns empty, use all active resources." That fallback masked a 404 on the eligible-resources endpoint for hours. Fallbacks should log when they fire, so a bug doesn't sit hidden under a "graceful degradation."

### iCloud auto-commit

The Mac source repo lives in iCloud and auto-commits periodically. **Never write scratch files in the project directory.** Test scripts, temp outputs, `.bak` files — all get auto-committed and pollute history. Scratch work goes elsewhere.

### Idempotency check matches `<function name>` falsely

Patch 56 hit this: idempotency check `if "loadLevelForDay" in s` matched the call site that the patch had just added, so the function definition got skipped on first run. Always anchor on something unique to the new state — `private function loadLevelForDay(` for new functions, full multi-line markers for blocks.

### Python escape sequences in patch idempotency checks

Patch 56a hit this: `if "build7DayStripCenteredOn(\$this" in s` was a Python non-raw string where `\$` meant literal backslash+dollar, which didn't match the actual content (just `$`). Either use raw strings (`r"..."`) or skip the backslash on `$`.

---

## Operational

### Backups

Nightly automated backups via `/usr/local/bin/intake-backup.sh`. Backs up MySQL dump + storage to DigitalOcean Spaces.

**Pending security fix:** the backup script has hardcoded DB password and DO Spaces keys. Day-17 pending #1. Need to:
1. Rotate DO Spaces keys
2. Rotate MySQL `intake` user password
3. Refactor script to read from `/etc/intake-backup.env` (mode 600)

Until done, treat the script's current credentials as compromised.

### Monitoring

UptimeRobot pings `intake.works` and `thebikehub.intake.works` every 5 minutes. Slack notification on failure.

### Logs

```bash
tail -f /var/www/intake/storage/logs/laravel.log    # Application
tail -f /var/log/nginx/error.log                    # Nginx
tail -f /var/log/php8.3-fpm.log                     # PHP-FPM
journalctl -u php8.3-fpm -f                         # Systemd PHP
```

For long-running issues, check `storage/logs/laravel-YYYY-MM-DD.log` (daily rotation).

### `APP_DEBUG`

Currently `true` on production. **Must be set to `false` before public launch** — exposes stack traces with file paths and env values.

---

## 15 design principles

These guide every product and architectural decision. When unsure, walk the list:

1. **Dashboard = jobs-to-be-done.** Not metrics for metrics' sake — surface what the operator needs to act on.
2. **Progressive unlocking.** Don't expose every feature on day 1. Onboarding reveals as the tenant configures.
3. **Contextual disclosure via packs.** Industry packs surface industry-specific defaults and vocabulary.
4. **Every metric clickable.** A number on the dashboard should drill into the records it summarizes.
5. **Migrations staged + resumable + nondestructive.** Never a "one-shot" migration that can't restart.
6. **Self-serve via signed URLs.** Tenant onboarding and recovery flows use signed URLs, not raw IDs.
7. **Stripe = billing truth.** Don't shadow-track subscription state in our DB beyond cache. Stripe is the source.
8. **Opinionated defaults > settings.** Every setting we add is a tax. Defaults should work for 80%.
9. **Advanced = folder, not mode.** Power features live in a separate area, not as a "mode" overlay on the main UI.
10. **Tenant brand top, ours footer.** Already covered above.
11. **One render pipeline (preview + prod).** No "preview-only" rendering paths that diverge from production.
12. **Mobile ≠ shrunk desktop.** Mobile views are designed for mobile, not auto-collapsed desktop.
13. **Snapshot on write.** Already covered above.
14. **Hunt bug class, not bug.** Fix the root cause and audit for siblings. Don't patch one instance.
15. **Fail open on 3rd-party retries.** Retry external API calls; if they ultimately fail, degrade gracefully rather than hard-erroring.

---

## 30-minute onboarding (for a new dev)

Goal: clone the repo, read enough to ship a small patch safely.

1. **Clone the repo.** Read `README.md` and this `RUNBOOK.md`. (~10 min)
2. **Open `database/seeders/PlatformMarketingSeeder.php`.** Skim it. It tells you the marketing-page architecture: pages are CMS rows, sections are typed children, each section type has a Blade partial. (~5 min)
3. **Open `app/Http/Controllers/Tenant/PageBuilderController.php`.** Find the `DEFAULTS` const. Every section type is registered here. (~3 min)
4. **Open `routes/web.php`.** Trace one tenant route — say `/admin/appointments/{id}`. See the `Route::domain` declaration and the controller method. Note the `$subdomain` first parameter. (~5 min)
5. **Find a recent patch script.** Read it top-to-bottom. Notice the structure: docstring → anchors → idempotency checks → final summary. (~5 min)
6. **Ask whoever onboarded you** what the current top-of-mind work is. Read the relevant memory or kickoff doc. (~2 min)

Now you can write your first patch. Keep it small. Anchor carefully. Test idempotency before committing.

---

## Out-of-scope (planned, not yet built)

- Lead assignment for multi-staff shops — leaning "no" for v1.
- Auto-archive leads — leaning "yes, 90-day default, shop-configurable."
- Anonymous visitor tracking — leaning "always on."
- Stripe Connect tenant integration (see above).
- Custom domains per tenant — affects favicon and email-sending domain.
- HTML purifier swap (currently using hand-rolled regex sanitization in `BlockRenderer`).
- Automated test suite (no Pest tests currently — highest-leverage debt item).
- Staging environment (currently testing on dogfood).

---

## When in doubt

- **Architectural decision?** Walk the 15 principles. If still stuck, ask "does this work at 10K tenants?"
- **Database change?** Stage it. Make it resumable. Make it nondestructive.
- **New endpoint?** Make sure it has the `/admin/` prefix if it's a tenant SPA fetch.
- **Editing a file with `$variables` or single quotes?** Use shell heredoc, not Python `str.replace`.
- **About to delete something?** Don't. Mark it inactive instead. Deletion is forever.
- **About to skip writing the idempotency check on a patch?** Don't. Write it. Future you will re-run the patch and the SKIP is what saves you from corrupting state.

---

*Last updated: May 12, 2026*
