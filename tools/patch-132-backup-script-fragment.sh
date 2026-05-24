# MARKER-PATCH-132 — append to /usr/local/bin/intake-backup.sh
# just before exit 0. Writes the most recent run to system_health so
# the dashboard tile can read it without shelling out.
#
# Required env (from /etc/intake-backup.env once you do the credential
# rotation that is still pending):
#   MYSQL_USER, MYSQL_PASS  (db = intake)
#
# Already set elsewhere in the script:
#   BACKUP_FILE  = path to the gzipped dump just uploaded
#   START_TS     = epoch seconds when the script started

if [ -f "$BACKUP_FILE" ]; then
  SIZE_BYTES=$(stat -c "%s" "$BACKUP_FILE")
  END_TS=$(date +%s)
  DURATION=$((END_TS - START_TS))
  AT=$(date --iso-8601=seconds)
  JSON_VALUE=$(printf '{"at":"%s","bytes":%s,"duration_sec":%s}' "$AT" "$SIZE_BYTES" "$DURATION")

  mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" intake <<SQL
    INSERT INTO system_health (`key`, value, updated_at)
    VALUES ("last_backup", '$JSON_VALUE', NOW())
    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW();
SQL
fi
