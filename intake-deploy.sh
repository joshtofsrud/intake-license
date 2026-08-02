#!/usr/bin/env bash
# intake-deploy.sh — atomic deploy. Run on the server, after cutover.
#
#   intake-deploy.sh              deploy origin/main
#   intake-deploy.sh --rollback   point back at the previous release
#   intake-deploy.sh --list       show releases, newest first
#
# The whole point: a request that is mid-sale keeps executing against the
# tree it started in. The old flow did `git pull` into the live directory,
# so a request already running could lazily include a file that changed
# underneath it — class signatures shifting mid-request. Here the live
# symlink only moves once everything is built and tested, and it moves in
# one atomic operation.
#
# nginx passes $realpath_root, so PHP resolves through the symlink to the
# real release path. Old requests keep their old path; new ones get the new
# one. That is what makes the swap clean rather than cosmetic.
#
# MIGRATIONS RUN BEFORE THE SWAP, deliberately. For the seconds between
# migrate and swap, the OLD code is running against the NEW schema — which
# is safe only if migrations are additive. Follow expand/contract: a
# release may ADD columns; dropping or renaming waits for a later release,
# once nothing running reads them.
set -euo pipefail

APP=/var/www/intake
SHARED=/var/www/intake-shared
RELEASES=/var/www/intake-releases
BRANCH="${DEPLOY_BRANCH:-main}"
KEEP=5

die() { echo "FATAL: $*" >&2; exit 1; }

[ -L "$APP" ] || die "$APP is not a symlink — run intake-cutover.sh first."

current_release() { readlink -f "$APP"; }

case "${1:-}" in
  --list)
    ls -1dt "$RELEASES"/*/ 2>/dev/null | sed "s|$RELEASES/||;s|/$||" \
      | while read -r r; do
          [ "$RELEASES/$r" = "$(current_release)" ] && echo "$r  <- live" || echo "$r"
        done
    exit 0
    ;;
  --rollback)
    CUR="$(current_release)"
    PREV="$(ls -1dt "$RELEASES"/*/ | sed 's|/$||' | grep -v "^$CUR$" | head -1 || true)"
    [ -n "$PREV" ] || die "no previous release to roll back to."
    echo "==> rolling back to $PREV"
    ln -sfn "$PREV" "$APP"
    php "$APP/artisan" queue:restart || true
    supervisorctl restart intake-scheduler || true
    systemctl reload php8.3-fpm
    echo "Done. NOTE: migrations are NOT reversed — if this release added"
    echo "columns that is fine, since additive changes are backward safe."
    exit 0
    ;;
esac

TS="$(date +%Y%m%d%H%M%S)"
REL="$RELEASES/$TS"
PREV="$(current_release)"

echo "==> fetching origin/$BRANCH"
git -C "$SHARED/repo" fetch --prune origin
git -C "$SHARED/repo" reset --hard "origin/$BRANCH"
SHA="$(git -C "$SHARED/repo" rev-parse --short HEAD)"
echo "    $SHA  $(git -C "$SHARED/repo" log -1 --pretty=%s)"

echo "==> building $REL"
mkdir -p "$REL"
rsync -a --delete \
  --exclude '.git' --exclude '.env' --exclude 'storage' \
  --exclude 'node_modules' --exclude 'bootstrap/cache/*.php' \
  "$SHARED/repo/" "$REL/"

ln -sfn "$SHARED/.env"    "$REL/.env"
ln -sfn "$SHARED/storage" "$REL/storage"
rm -rf "$REL/public/storage"
ln -sfn "$SHARED/storage/app/public" "$REL/public/storage"

mkdir -p "$REL/bootstrap/cache"
chown -R www-data:www-data "$REL"

cd "$REL"
sudo -u www-data composer install --no-interaction --optimize-autoloader --no-scripts

echo "==> migrations (additive only — see the header)"
sudo -u www-data php artisan migrate --force

echo "==> caches"
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize

echo "==> smoke test before going live"
sudo -u www-data php artisan about --only=environment >/dev/null \
  || die "artisan failed in $REL. Live site untouched, still on $PREV."

echo "==> swap"
ln -sfn "$REL" "$APP"

echo "==> reload"
# queue:restart is graceful: workers finish the job in hand, then exit and
# supervisor brings them back on the new release. Skipping this is what
# left a worker running stale code for hours on Aug 1.
php "$APP/artisan" queue:restart || true
# schedule:work holds the schedule definition in memory; queue:restart does
# not touch it.
supervisorctl restart intake-scheduler || true
systemctl reload php8.3-fpm

echo "==> pruning old releases (keeping $KEEP, never the live one)"
ls -1dt "$RELEASES"/*/ | sed 's|/$||' | tail -n +$((KEEP+1)) | while read -r old; do
  [ "$old" = "$(current_release)" ] && continue
  echo "    rm $old"
  rm -rf "$old"
done

echo
echo "Live: $REL ($SHA)"
echo "Previous: $PREV"
echo "Roll back with: intake-deploy.sh --rollback"
