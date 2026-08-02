#!/usr/bin/env bash
# intake-cutover.sh — ONE TIME. Converts /var/www/intake from a live git
# checkout into a symlink pointing at a releases directory.
#
# RUN THIS AT A QUIET MOMENT. There is a sub-second window where
# /var/www/intake does not exist. Everything before that window is
# preparation and is safe to abort; the swap itself is a single mv.
#
# Target layout:
#   /var/www/intake-shared/.env         <- moved, never copied again
#   /var/www/intake-shared/storage/     <- moved; signatures, PDFs, logs
#   /var/www/intake-shared/repo/        <- the git checkout, never served
#   /var/www/intake-releases/<ts>/      <- one directory per deploy
#   /var/www/intake -> releases/<ts>    <- the symlink nginx serves
#
# Why shared: storage/ holds rental signature PNGs and agreement PDFs. If
# it lived inside a release, every deploy would orphan them. .env likewise.
#
# nginx already passes $realpath_root for SCRIPT_FILENAME, so PHP resolves
# through the symlink to the real release path. Nothing to change there.
#
# Supervisor keeps pointing at /var/www/intake/artisan (the symlink), which
# is correct — workers should follow the swap when they restart.
#
# The previous checkout is preserved at /var/www/intake-preflip until you
# delete it yourself. Rollback is: rm the symlink, mv it back.
set -euo pipefail

APP=/var/www/intake
SHARED=/var/www/intake-shared
RELEASES=/var/www/intake-releases
TS="$(date +%Y%m%d%H%M%S)"

echo "==> preflight"
[ -d "$APP/.git" ] || { echo "FATAL: $APP is not a git checkout — already cut over?"; exit 1; }
[ -L "$APP" ]      && { echo "FATAL: $APP is already a symlink."; exit 1; }
[ -f "$APP/.env" ] || { echo "FATAL: no .env at $APP"; exit 1; }
[ -d "$APP/storage" ] || { echo "FATAL: no storage/ at $APP"; exit 1; }
command -v composer >/dev/null || { echo "FATAL: composer not on PATH"; exit 1; }

BRANCH="$(git -C "$APP" rev-parse --abbrev-ref HEAD)"
echo "    branch: $BRANCH"
if [ -n "$(git -C "$APP" status --porcelain)" ]; then
  echo "FATAL: uncommitted changes in $APP. Commit or stash first —"
  echo "       the cutover rebuilds from the remote and they would be lost."
  git -C "$APP" status --short
  exit 1
fi

echo "==> creating $SHARED and $RELEASES"
mkdir -p "$SHARED" "$RELEASES"

echo "==> moving .env and storage/ to shared (move, not copy — one copy only)"
[ -e "$SHARED/.env" ]    || mv "$APP/.env" "$SHARED/.env"
[ -e "$SHARED/storage" ] || mv "$APP/storage" "$SHARED/storage"
chown -R www-data:www-data "$SHARED"
chmod 640 "$SHARED/.env"

echo "==> seeding the bare-ish repo copy"
if [ ! -d "$SHARED/repo/.git" ]; then
  cp -a "$APP" "$SHARED/repo"
  # The serving copy is gone from here; repo is only ever a source.
  rm -f  "$SHARED/repo/.env"
  rm -rf "$SHARED/repo/storage"
fi
git -C "$SHARED/repo" fetch --prune origin
git -C "$SHARED/repo" reset --hard "origin/$BRANCH"

REL="$RELEASES/$TS"
echo "==> building first release at $REL"
mkdir -p "$REL"
# --delete keeps a release clean; excludes are the things that must be
# shared or are rebuilt per release.
rsync -a --delete \
  --exclude '.git' --exclude '.env' --exclude 'storage' \
  --exclude 'node_modules' \
  "$SHARED/repo/" "$REL/"

ln -sfn "$SHARED/.env"    "$REL/.env"
ln -sfn "$SHARED/storage" "$REL/storage"
# public/storage must point at SHARED storage, not at a release that will
# be pruned. This is where signed agreements and signatures are served from.
rm -rf "$REL/public/storage"
ln -sfn "$SHARED/storage/app/public" "$REL/public/storage"

mkdir -p "$REL/bootstrap/cache"
chown -R www-data:www-data "$REL"
chmod -R ug+rwX "$REL/bootstrap/cache"

echo "==> composer + caches (still not live)"
cd "$REL"
sudo -u www-data composer install --no-interaction --no-dev --optimize-autoloader --no-scripts
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize

echo "==> smoke test against the new release BEFORE swapping"
sudo -u www-data php artisan about --only=environment >/dev/null \
  || { echo "FATAL: artisan failed inside the new release. Nothing swapped."; exit 1; }

echo "==> THE SWAP (sub-second)"
mv "$APP" /var/www/intake-preflip
ln -sfn "$REL" "$APP"

echo "==> reloading services"
php "$APP/artisan" queue:restart || true
supervisorctl restart intake-scheduler || true
systemctl reload php8.3-fpm

cat <<EOF

Done. Layout is now:
  $APP -> $REL
  shared: $SHARED (.env, storage, repo)

The old checkout is at /var/www/intake-preflip. Leave it a few days.

VERIFY NOW, in this order:
  1. load the admin in a browser
  2. open a rental with a signed agreement and click through to the PDF
     (that proves public/storage resolves to shared storage)
  3. tail -f $SHARED/storage/logs/laravel-\$(date +%Y-%m-%d).log

ROLLBACK if anything is wrong:
  rm $APP && mv /var/www/intake-preflip $APP
  mv $SHARED/.env $APP/.env && mv $SHARED/storage $APP/storage
  systemctl reload php8.3-fpm
EOF
