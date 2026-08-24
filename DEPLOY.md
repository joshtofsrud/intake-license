<!-- MARKER-REPO-CLEANUP -->
# Deploy — atomic releases

`/var/www/intake` is a **symlink** to `/var/www/intake-releases/<timestamp>`.
Shared, never inside a release: `/var/www/intake-shared/{.env, storage, repo}`.
Each release symlinks `.env` and `storage` to shared, and points
`public/storage` **straight** at `/var/www/intake-shared/storage/app/public`
(a two-hop chain through the release's own storage symlink is not followed by
nginx and blanks every image).

## The command

```
/root/intake-deploy.sh            # fetch → build release → composer → migrate
                                  # → optimize → smoke test → atomic swap
                                  # → queue:restart + scheduler + fpm reload
                                  # → prune to 5 releases
/root/intake-deploy.sh --rollback # repoint at the previous release
/root/intake-deploy.sh --list     # show releases
```

Nothing goes live unless the smoke test passes. The committed copy of the
script is `intake-deploy.sh` at the repo root; the live copy runs from
`/root/` because it replaces `/var/www/intake` while running.

## Rules the structure depends on

- **Migrations must be additive** (expand/contract). `migrate` runs before the
  swap, so old code briefly runs against the new schema — and `--rollback`
  does not reverse migrations.
- **No `composer install --no-dev`.** The live app keeps dev dependencies;
  whoops supplies the readable error pages.
- rsync must `--exclude 'bootstrap/cache/*.php'` or a stale compiled manifest
  fatals artisan in the new release.
- `php artisan queue:restart` is essential — workers hold old code in memory
  until restarted.
- fpm uses graceful `systemctl reload php8.3-fpm` (safe while
  `opcache.validate_timestamps` is On for the FPM SAPI; if that's ever tuned
  to 0, go back to a hard cycle or add an explicit opcache reset).
- New Filament master-admin pages also need
  `php artisan filament:cache-components` after deploy.

## Patch workflow (Mac)

Run the patch script **alone** first and confirm its success lines, then:

```
git add -A && git commit -m "…" && git push
```

then `/root/intake-deploy.sh` on the server. Never chain the patch script
into the git command — a failed patch would still commit a half-changed tree.
Patch scripts are run on the Mac only, never on the server.

## Server layout notes

- Git lives at `/var/www/intake-shared/repo`
  (`git -C /var/www/intake-shared/repo log --oneline -3`); the live path has
  no `.git`. To confirm what's live: `readlink -f /var/www/intake` or grep a
  MARKER in the deployed file.
- Runtime-created storage paths (e.g. `storage/app/distributor-cache`) need
  `chown www-data:www-data` + `chmod 775` once on first use.
