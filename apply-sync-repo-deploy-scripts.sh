#!/usr/bin/env bash
# apply-sync-repo-deploy-scripts.sh
# MARKER-DEPLOY-SCRIPTS-SYNC — make the committed copies match what's live.
#
# The scripts that actually run live in /root and have both fixes applied.
# The copies in the repo do not, and a repo copy that looks authoritative
# but isn't is a trap: the obvious recovery move six months from now is
# "copy the one from the repo", which would reintroduce both faults.
#
# Fault 1 — `composer install --no-dev`. The live app installs dev
# dependencies; whoops is what produces the readable error pages. A release
# should match what's known to work.
#
# Fault 2 — missing `--exclude 'bootstrap/cache/*.php'`. $SHARED/repo was
# seeded by `cp -a` of the live tree, which carried untracked compiled
# manifests along. .gitignore excludes them so `git reset --hard` never
# clears them, rsync copies them into each release, and a stale manifest
# fatals artisan. Excluding them at the rsync is the durable fix.
#
# Also removes the FIRST cutover script if it's still committed. It moved
# .env and storage before doing anything risky, so an abort left Laravel
# unable to boot — which is exactly what happened. It should not be
# somewhere it can be run by accident; intake-cutover-v2.sh is the one that
# worked, and the cutover is done regardless.
#
# Finds the files wherever they were committed (repo root or deploy/).
set -e

python3 <<'PY'
import glob, io, os

def find(name):
    hits = [p for p in (name, os.path.join('deploy', name)) if os.path.isfile(p)]
    hits += [p for p in glob.glob(os.path.join('**', name), recursive=True)
             if os.path.isfile(p) and p not in hits and 'vendor' not in p]
    return hits

changed = False

# ---------------------------------------------------------------- deploy script
targets = find('intake-deploy.sh')
if not targets:
    print('  intake-deploy.sh not committed anywhere — nothing to sync')
for p in targets:
    s = io.open(p, encoding='utf-8').read()
    before = s

    # Fault 1
    s = s.replace(' --no-dev', '')

    # Fault 2 — only if not already excluded
    if "--exclude 'bootstrap/cache/*.php'" not in s:
        s = s.replace(
            "--exclude 'node_modules'",
            "--exclude 'node_modules' --exclude 'bootstrap/cache/*.php'"
        )

    if s != before:
        io.open(p, 'w', encoding='utf-8').write(s)
        print('  fixed', p)
        changed = True
    else:
        print('  already clean', p)

# ---------------------------------------------------------------- v1 cutover
for p in find('intake-cutover.sh'):
    os.remove(p)
    print('  removed', p, '(v1 — unsafe ordering, superseded by v2)')
    changed = True

for p in find('intake-cutover-v2.sh'):
    print('  kept', p, '(the version that worked)')

print('changed' if changed else 'nothing to do')
PY

echo
echo "--- verify: no --no-dev, exclude present ---"
for f in $(find . -name 'intake-deploy.sh' -not -path './vendor/*'); do
  echo "$f"
  grep -n "no-dev" "$f" || echo "   no --no-dev  OK"
  grep -n "bootstrap/cache" "$f" || echo "   MISSING exclude"
done

echo
echo "--- syntax ---"
for f in $(find . -name 'intake-*.sh' -not -path './vendor/*'); do
  bash -n "$f" && echo "$f OK"
done

echo
echo "apply-sync-repo-deploy-scripts: OK"
