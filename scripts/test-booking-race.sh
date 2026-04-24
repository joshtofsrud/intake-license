#!/usr/bin/env bash
#
# test-booking-race.sh — real-world concurrency validation for the booking engine.
#
# Fires N parallel HTTP POST requests at /book/submit with the same slot,
# same resource, but different customers. Expected result:
#   - Exactly ONE request returns HTTP 200 (the winner)
#   - The others return HTTP 409 with code=slot_taken or code=lock_timeout
#
# Run this against the dog-food tenant (thebikehub.intake.works) after
# deploying S2, and against production after go-live to confirm the lock
# survives real infrastructure.
#
# Prerequisites:
#   - curl installed (comes with macOS)
#   - jq installed (brew install jq) — used for response parsing
#   - A live dog-food tenant with at least one service and one resource
#
# Configure the constants below before first run.
# ------------------------------------------------------------------------

set -euo pipefail

# ============================================================
# CONFIGURATION — set these once per environment
# ============================================================

TENANT_URL="${TENANT_URL:-https://thebikehub.intake.works}"
SERVICE_ITEM_ID="${SERVICE_ITEM_ID:-REPLACE_WITH_REAL_SERVICE_UUID}"
RESOURCE_ID="${RESOURCE_ID:-REPLACE_WITH_REAL_RESOURCE_UUID}"
BOOKING_DATE="${BOOKING_DATE:-$(date -v+14d +%Y-%m-%d 2>/dev/null || date -d '+14 days' +%Y-%m-%d)}"
BOOKING_TIME="${BOOKING_TIME:-14:00:00}"
CONCURRENT_REQUESTS="${CONCURRENT_REQUESTS:-3}"

# ============================================================
# Sanity checks
# ============================================================

if [[ "$SERVICE_ITEM_ID" == "REPLACE_WITH_REAL_SERVICE_UUID" ]]; then
  echo "ERROR: Set SERVICE_ITEM_ID to a real service UUID from the tenant."
  echo "Find one with: SELECT id, name FROM tenant_service_items WHERE tenant_id='<tenant_uuid>' LIMIT 5"
  exit 1
fi

if [[ "$RESOURCE_ID" == "REPLACE_WITH_REAL_RESOURCE_UUID" ]]; then
  echo "ERROR: Set RESOURCE_ID to a real resource UUID from the tenant."
  echo "Find one with: SELECT id, name FROM tenant_resources WHERE tenant_id='<tenant_uuid>' LIMIT 5"
  exit 1
fi

command -v jq >/dev/null 2>&1 || {
  echo "ERROR: jq is required. Install with: brew install jq"
  exit 1
}

# ============================================================
# Fire N parallel requests
# ============================================================

echo "=========================================================="
echo "Booking race test"
echo "=========================================================="
echo "Tenant:        $TENANT_URL"
echo "Service:       $SERVICE_ITEM_ID"
echo "Resource:      $RESOURCE_ID"
echo "Slot:          $BOOKING_DATE at $BOOKING_TIME"
echo "Requests:      $CONCURRENT_REQUESTS parallel"
echo "=========================================================="

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

# Launch N requests in parallel, each with a distinct email so customer
# deduplication doesn't mask the collision.
PIDS=()
for i in $(seq 1 "$CONCURRENT_REQUESTS"); do
  EMAIL="race-test-${i}-$(date +%s)@test.local"
  FNAME="Racer${i}"

  PAYLOAD=$(cat <<JSON
{
  "first_name": "$FNAME",
  "last_name": "Test",
  "email": "$EMAIL",
  "phone": "5555555555",
  "date": "$BOOKING_DATE",
  "appointment_time": "$BOOKING_TIME",
  "resource_id": "$RESOURCE_ID",
  "items": [
    {
      "service_item_id": "$SERVICE_ITEM_ID",
      "addon_ids": []
    }
  ],
  "payment_method": "none"
}
JSON
)

  (
    curl -sS -o "$TMPDIR/resp_${i}.json" \
         -w "%{http_code}" \
         -H "Content-Type: application/json" \
         -H "Accept: application/json" \
         -X POST \
         "$TENANT_URL/book/submit" \
         -d "$PAYLOAD" > "$TMPDIR/status_${i}.txt"
  ) &
  PIDS+=($!)
done

# Wait for all to complete
for pid in "${PIDS[@]}"; do
  wait "$pid"
done

# ============================================================
# Report results
# ============================================================

echo ""
echo "Results:"
echo "--------"

WINS=0
LOSES=0
OTHER=0

for i in $(seq 1 "$CONCURRENT_REQUESTS"); do
  STATUS=$(cat "$TMPDIR/status_${i}.txt")
  BODY=$(cat "$TMPDIR/resp_${i}.json")
  CODE=$(echo "$BODY" | jq -r '.code // "n/a"' 2>/dev/null || echo "unparseable")

  case "$STATUS" in
    200|201)
      echo "  Request $i: HTTP $STATUS  winner"
      WINS=$((WINS+1))
      ;;
    409)
      echo "  Request $i: HTTP 409  (code=$CODE)  correctly rejected"
      LOSES=$((LOSES+1))
      ;;
    *)
      echo "  Request $i: HTTP $STATUS  unexpected"
      echo "             body: $(echo $BODY | head -c 200)"
      OTHER=$((OTHER+1))
      ;;
  esac
done

echo ""
echo "=========================================================="
echo "Summary: $WINS winner, $LOSES rejected, $OTHER unexpected"
echo "=========================================================="

# Exit nonzero if the race had more than one winner or any unexpected result
if [[ $WINS -eq 1 && $OTHER -eq 0 && $LOSES -eq $((CONCURRENT_REQUESTS - 1)) ]]; then
  echo "PASS: exactly one booking succeeded, others rejected cleanly."
  exit 0
else
  echo "FAIL: race did not produce the expected 1-winner pattern."
  echo "  If WINS > 1, the lock is not protecting the slot -- investigate."
  echo "  If OTHER > 0, there's an unexpected server response -- check app logs."
  exit 1
fi
