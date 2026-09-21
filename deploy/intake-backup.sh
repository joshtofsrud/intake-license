#!/bin/bash
# MARKER-BACKUP-RECORD — nightly database backup to DO Spaces.
#
# Source of truth: deploy/intake-backup.sh in the repo. Installed on the
# server as /usr/local/bin/intake-backup.sh (root, mode 700); root's cron
# runs it at 02:00.
#
# No secrets in this file. They live in two root-only files, each in its
# tool's own format:
#   /etc/intake-backup.my.cnf  (600)  [client] user / password — mysqldump
#   /etc/intake-backup.s3cfg   (600)  [default] access_key / secret_key — s3cmd
#
# Every run reports to the master admin dashboard through
# `artisan backup:record`, run as www-data so it never creates a root-owned
# daily log file (a root-owned laravel-YYYY-MM-DD.log breaks www-data logging).
set -uo pipefail

DB_NAME="intake"
SPACE_NAME="intake-backup"
SPACE_REGION="nyc3"
SPACE_ENDPOINT="https://nyc3.digitaloceanspaces.com"
RETENTION_DAYS=30
BACKUP_DIR="/tmp/intake-backups"
MIN_BYTES=1048576   # a dump under 1 MB is not a backup of this database
MYCNF="/etc/intake-backup.my.cnf"
S3CFG="/etc/intake-backup.s3cfg"
ARTISAN="/var/www/intake/artisan"

START_TS=$(date +%s)
FILEPATH=""
S3=(-c "$S3CFG" --host="$SPACE_ENDPOINT" --host-bucket="https://%(bucket)s.${SPACE_REGION}.digitaloceanspaces.com")

record() {
  runuser -u www-data -- php "$ARTISAN" backup:record "$@" --started="$START_TS" \
    || echo "WARN: could not record the result on the dashboard" >&2
}

fail() {
  echo "ERROR: $1" >&2
  if [ -n "$FILEPATH" ]; then rm -f "$FILEPATH"; fi
  record failed --reason="$1"
  exit 1
}

[ -r "$MYCNF" ] || fail "missing $MYCNF"
[ -r "$S3CFG" ] || fail "missing $S3CFG"

mkdir -p "$BACKUP_DIR" || fail "cannot create $BACKUP_DIR"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
FILENAME="intake-db-${TIMESTAMP}.sql.gz"
FILEPATH="$BACKUP_DIR/$FILENAME"

# Dump. pipefail makes a mysqldump failure fail the whole pipeline — the old
# script checked only gzip's exit status, so a failed dump uploaded as a good
# backup. --single-transaction takes a consistent InnoDB snapshot instead of
# locking every table for the length of the dump.
mysqldump --defaults-extra-file="$MYCNF" --single-transaction --quick "$DB_NAME" \
  | gzip > "$FILEPATH" \
  || fail "mysqldump failed"

BYTES=$(stat -c %s "$FILEPATH")
[ "$BYTES" -ge "$MIN_BYTES" ] || fail "dump is only $BYTES bytes, refusing to upload it as a backup"

s3cmd put "$FILEPATH" "s3://${SPACE_NAME}/${FILENAME}" "${S3[@]}" >/dev/null \
  || fail "upload to s3://${SPACE_NAME} failed"

rm -f "$FILEPATH"
FILEPATH=""

# Retention: delete remote dumps older than RETENTION_DAYS.
CUTOFF=$(date -d "-${RETENTION_DAYS} days" +%s)
s3cmd ls "s3://${SPACE_NAME}/" "${S3[@]}" | awk '{print $4}' | while read -r file; do
  FILEDATE=$(echo "$file" | grep -oP '\d{4}-\d{2}-\d{2}' | head -1)
  if [[ -n "$FILEDATE" ]] && [[ $(date -d "$FILEDATE" +%s) -lt $CUTOFF ]]; then
    s3cmd del "$file" "${S3[@]}" >/dev/null || echo "WARN: could not delete $file" >&2
  fi
done

record ok --file="$FILENAME" --bytes="$BYTES"
echo "Backup complete: $FILENAME ($BYTES bytes)"
