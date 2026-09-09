#!/usr/bin/env bash
#
# Reverses the inventory:ean-merge run of 2026-09-06.
#
# Sources of truth:
#   intake_prefold.foldmap   loser -> survivor, from the log (6,082 pairs)
#   intake_prefold.folded    distinct folded losers (6,082)
#   intake_prefold.*         the 02:00 snapshot, pre-fold state
#   intake.*                 live
#
# What it does, and nothing else:
#   1. is_active = 1 on every folded loser
#   2. computed_stock_count restored from the snapshot where the item is in it
#   3. pivots the fold MOVED  -> inventory_item_id reverted to the loser
#   4. pivots the fold DELETED -> re-inserted from the snapshot
#
# The one hand-deactivated Ground Control item is not in the log, so it is
# never touched. Nothing writes to live unless --fix is passed.

set -euo pipefail

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1

echo "=== preflight ==="

for t in foldmap folded tenant_inventory_items tenant_inventory_item_vendors; do
  n=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='intake_prefold' AND table_name='$t'")
  [ "$n" = "1" ] || { echo "ABORT: intake_prefold.$t missing"; exit 1; }
done

# Column parity. A migration since 06 Sep would break a positional insert.
LIVE_COLS=$(mysql -N -B -e "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='intake' AND table_name='tenant_inventory_item_vendors'")
SNAP_COLS=$(mysql -N -B -e "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='intake_prefold' AND table_name='tenant_inventory_item_vendors'")
if [ "$LIVE_COLS" != "$SNAP_COLS" ]; then
  echo "ABORT: pivot table columns differ between live and snapshot."
  echo "  live: $LIVE_COLS"
  echo "  snap: $SNAP_COLS"
  exit 1
fi
COLS=$(echo "$LIVE_COLS" | sed 's/[^,]*/`&`/g')
echo "column parity OK ($(echo "$LIVE_COLS" | tr ',' '\n' | wc -l) columns)"

echo
echo "=== building action lists (writes only to intake_prefold) ==="

mysql <<SQL
DROP TABLE IF EXISTS intake_prefold.rev_items;
CREATE TABLE intake_prefold.rev_items (
  id char(36) PRIMARY KEY, in_backup tinyint, target_stock int, logged_only tinyint
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Every folded loser. Stock comes from the snapshot when we have it; a
-- negative snapshot value is a pre-existing defect, not a state to restore,
-- so it floors at zero.
INSERT INTO intake_prefold.rev_items
SELECT f.id,
       IF(b.id IS NULL, 0, 1),
       GREATEST(COALESCE(b.computed_stock_count, 0), 0),
       IF(b.id IS NULL, 1, 0)
FROM intake_prefold.folded f
LEFT JOIN intake_prefold.tenant_inventory_items b ON b.id = f.id;

DROP TABLE IF EXISTS intake_prefold.rev_move;
CREATE TABLE intake_prefold.rev_move (
  pivot_id char(36) PRIMARY KEY, loser_id char(36), survivor_id char(36)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- MOVED: loser had this vendor pre-fold, survivor did not, so the fold
-- repointed the row instead of deleting it. Same primary key, so we can
-- verify it is still sitting on the survivor before moving it back.
INSERT INTO intake_prefold.rev_move
SELECT p.id, m.loser_id, m.survivor_id
FROM intake_prefold.tenant_inventory_item_vendors p
JOIN intake_prefold.foldmap m ON m.loser_id = p.inventory_item_id
LEFT JOIN intake_prefold.tenant_inventory_item_vendors sp
       ON sp.inventory_item_id = m.survivor_id AND sp.vendor_id = p.vendor_id
WHERE sp.id IS NULL
  AND EXISTS (SELECT 1 FROM intake.tenant_inventory_item_vendors lv
              WHERE lv.id = p.id AND lv.inventory_item_id = m.survivor_id);

DROP TABLE IF EXISTS intake_prefold.rev_ins;
CREATE TABLE intake_prefold.rev_ins (
  pivot_id char(36) PRIMARY KEY
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- DELETED: survivor already had this vendor, so the fold dropped the
-- loser's row. Only re-insert what is genuinely absent from live.
INSERT INTO intake_prefold.rev_ins
SELECT p.id
FROM intake_prefold.tenant_inventory_item_vendors p
JOIN intake_prefold.foldmap m ON m.loser_id = p.inventory_item_id
JOIN intake_prefold.tenant_inventory_item_vendors sp
     ON sp.inventory_item_id = m.survivor_id AND sp.vendor_id = p.vendor_id
WHERE NOT EXISTS (SELECT 1 FROM intake.tenant_inventory_item_vendors lv
                  WHERE lv.id = p.id);
SQL

echo
echo "=== plan ==="
mysql -t <<SQL
SELECT
  (SELECT COUNT(*) FROM intake_prefold.rev_items) AS items_total,
  (SELECT COUNT(*) FROM intake_prefold.rev_items WHERE in_backup=1) AS from_snapshot,
  (SELECT COUNT(*) FROM intake_prefold.rev_items WHERE logged_only=1) AS log_only,
  (SELECT COUNT(*) FROM intake_prefold.rev_move) AS pivots_to_move,
  (SELECT COUNT(*) FROM intake_prefold.rev_ins) AS pivots_to_insert;

SELECT i.tenant_id,
       COUNT(*) AS items,
       SUM(i.is_active=0) AS currently_inactive
FROM intake.tenant_inventory_items i
JOIN intake_prefold.rev_items r ON r.id = i.id
GROUP BY i.tenant_id;

SELECT COUNT(*) AS items_missing_from_live
FROM intake_prefold.rev_items r
LEFT JOIN intake.tenant_inventory_items i ON i.id = r.id
WHERE i.id IS NULL;

SELECT COUNT(*) AS stock_to_restore_nonzero
FROM intake_prefold.rev_items WHERE in_backup=1 AND target_stock > 0;
SQL

if [ "$FIX" = "0" ]; then
  echo
  echo "DRY RUN — nothing written to live. Re-run with --fix to apply."
  exit 0
fi

echo
echo "=== applying ==="

mysql <<SQL
START TRANSACTION;

UPDATE intake.tenant_inventory_items i
JOIN intake_prefold.rev_items r ON r.id = i.id
SET i.is_active = 1,
    i.computed_stock_count = IF(r.in_backup = 1, r.target_stock, i.computed_stock_count);

UPDATE intake.tenant_inventory_item_vendors v
JOIN intake_prefold.rev_move r ON r.pivot_id = v.id
SET v.inventory_item_id = r.loser_id;

INSERT INTO intake.tenant_inventory_item_vendors ($COLS)
SELECT $COLS
FROM intake_prefold.tenant_inventory_item_vendors p
JOIN intake_prefold.rev_ins r ON r.pivot_id = p.id;

COMMIT;
SQL

echo
echo "=== after ==="
mysql -t <<SQL
SELECT i.tenant_id,
       COUNT(*) AS items,
       SUM(i.is_active=1) AS now_active
FROM intake.tenant_inventory_items i
JOIN intake_prefold.rev_items r ON r.id = i.id
GROUP BY i.tenant_id;

SELECT COUNT(*) AS pivots_on_reactivated_items
FROM intake.tenant_inventory_item_vendors v
JOIN intake_prefold.rev_items r ON r.id = v.inventory_item_id;

SELECT COUNT(*) AS still_inactive
FROM intake.tenant_inventory_items i
JOIN intake_prefold.rev_items r ON r.id = i.id
WHERE i.is_active = 0;
SQL

echo
echo "Done."
