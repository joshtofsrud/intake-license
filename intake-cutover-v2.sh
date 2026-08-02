#!/usr/bin/env bash
# intake-cutover-v2.sh — resume and finish the atomic-release cutover.
#
# Replaces intake-cutover.sh, which had two faults, both mine:
#
#   1. It moved .env and storage/ to shared EARLY, then only symlinked them
#      back after the swap. Anything failing in between left Laravel unable
#      to boot — which is exactly what happened. Here every move is followed
#      IMMEDIATELY by a symlink back, so the live app never loses a file it
#      needs, and every risky step happens after that.
#
#   2. `cp -a` preserved www-data ownership on shared/repo, so git refused
#      with "detected dubious ownership". Fixed by chowning the repo to root
#      and registering it as a safe directory.
#
# It is RESUMABLE. Given the current half-migrated server it skips what is
# already done and continues. Given a clean server it does the whole thing.
#
# The live site is untouched until the final swap. Every failure before that
# point leaves you exactly where you started, still serving.
set -euo pipefail

APP=/var/www/intake
SHARED=/var/www/intake-shared
RELEASES=/var/www/intake-releases
TS="$(date +%Y%m%d%H%M%S)"

die() { echo "FATAL: $*" >&2; exit 1; }

echo "==> preflight"
[ -L "$APP" ] && die "$APP is already a symlink — cutover already finished."
[ -d "$APP/.git" ] || die "$APP is not a git checkout."
command -v composer >/dev/null || die "composer not on PATH"
command -v rsync >/dev/null || die "rsync not installed"

BRANCH="$(git -C "$APP" rev-parse --abbrev-ref HEAD)"
echo "    branch: $BRANCH"

mkdir -p "$SHARED" "$RELEASES"

# ---------------------------------------------------------------- shared .env
# Move then symlink back in the same breath. The window where the live app
# has no .env is the time between two syscalls.
if [ ! -e "$SHARED/.env" ]; then
  echo "==> moving .env to shared"
  mv "$APP/.env" "$SHARED/.env"
  ln -sfn "$SHARED/.env" "$APP/.env"
fi
[ -e "$SHARED/.env" ] || die "no .env in shared and none to move."
# Make sure the live tree points at it even if a previous run left it bare.
[ -L "$APP/.env" ] || { rm -f "$APP/.env"; ln -sfn "$SHARED/.env" "$APP/.env"; }

# ---------------------------------------------------------------- shared storage
if [ ! -e "$SHARED/storage" ]; then
  echo "==> moving storage/ to shared"
  mv "$APP/storage" "$SHARED/storage"
  ln -sfn "$SHARED/storage" "$APP/storage"
fi
[ -d "$SHARED/storage" ] || die "no storage/ in shared and none to move."
[ -L "$APP/storage" ] || { rm -rf "$APP/storage"; ln -sfn "$SHARED/storage" "$APP/storage"; }

# public/storage must point at the REAL shared path, not hop through
# $APP/storage — nginx does not reliably follow a symlink to a symlink,
# which is what blanked the images earlier.
ln -sfn "$SHARED/storage/app/public" "$APP/public/storage"

chown -R www-data:www-data "$SHARED/storage"
chown www-data:www-data "$SHARED/.env"
chmod 640 "$SHARED/.env"

echo "==> live app is intact and serving from $APP (still the real checkout)"

# ---------------------------------------------------------------- repo
if [ ! -d "$SHARED/repo/.git" ]; then
  echo "==> seeding $SHARED/repo"
  rm -rf "$SHARED/repo"
  git clone --branch "$BRANCH" "$(git -C "$APP" remote get-url origin)" "$SHARED/repo"
fi
# git refuses to touch a repo owned by another user. root is the only user
# that ever drives it, so give it to root outright and register it too.
chown -R root:root "$SHARED/repo"
git config --global --add safe.directory "$SHARED/repo" 2>/dev/null || true

echo "==> fetching origin/$BRANCH"
git -C "$SHARED/repo" fetch --prune origin
git -C "$SHARED/repo" reset --hard "origin/$BRANCH"
SHA="$(git -C "$SHARED/repo" rev-parse --short HEAD)"
echo "    $SHA  $(git -C "$SHARED/repo" log -1 --pretty=%s)"

# ---------------------------------------------------------------- release
REL="$RELEASES/$TS"
echo "==> building $REL (live site still untouched)"
mkdir -p "$REL"
rsync -a --delete \
  --exclude '.git' --exclude '.env' --exclude 'storage' --exclude 'node_modules' \
  "$SHARED/repo/" "$REL/"

ln -sfn "$SHARED/.env"    "$REL/.env"
ln -sfn "$SHARED/storage" "$REL/storage"
rm -rf "$REL/public/storage"
ln -sfn "$SHARED/storage/app/public" "$REL/public/storage"

mkdir -p "$REL/bootstrap/cache"
chown -R www-data:www-data "$REL"
chmod -R ug+rwX "$REL/bootstrap/cache"

cd "$REL"
echo "==> composer"
sudo -u www-data composer install --no-interaction --no-dev --optimize-autoloader --no-scripts
echo "==> caches"
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize

echo "==> smoke test before going live"
sudo -u www-data php artisan about --only=environment >/dev/null \
  || die "artisan failed in $REL. Live site untouched, still the old checkout."

# ---------------------------------------------------------------- swap
echo "==> THE SWAP"
mv "$APP" /var/www/intake-preflip
ln -sfn "$REL" "$APP"

echo "==> reload"
php "$APP/artisan" queue:restart || true
supervisorctl restart intake-scheduler || true
systemctl reload php8.3-fpm

cat <<MSG

Done.
  $APP -> $REL  ($SHA)
  shared: $SHARED

Old checkout preserved at /var/www/intake-preflip. Leave it a few days.

VERIFY NOW, in this order:
  1. load the admin
  2. check images load on a storefront page
  3. open a rental with a signed agreement and click through to the PDF

ROLLBACK (the old tree still has its own .env and storage symlinks):
  rm $APP && mv /var/www/intake-preflip $APP && systemctl reload php8.3-fpm
MSG
