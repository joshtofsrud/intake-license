<!-- MARKER-REPO-CLEANUP -->
# Intake

Multi-tenant SaaS for independent service businesses — booking, register/POS,
inventory with distributor catalogs (HLC / BTI / QBP), customer management,
messaging, staff scheduling, public site builder, and online store.
`intake.works`.

## Layout

- Master admin — Filament 3 at `intake.works/admin` (pages are registered
  **explicitly** in `AdminPanelProvider->pages([])`; an unregistered page has
  no route at all)
- Tenant apps — `{subdomain}.intake.works`
- Platform / signup — `app.intake.works`

## Stack

PHP 8.3 · Laravel 11 · **MySQL** · Redis (cache / sessions / queues) ·
Nginx + PHP-FPM · Filament 3

## Deploying

One command on the server: `/root/intake-deploy.sh` (atomic symlinked
releases, `--rollback`, `--list`). See `DEPLOY.md` for the structure and the
rules that keep it safe. Day-to-day workflow and conventions: `RUNBOOK.md`.

Historical note: this repo began as the WordPress license server, which is
long gone — if a doc mentions PostgreSQL, plugin ZIPs or `license.intake.works`,
it is stale and should be deleted.
