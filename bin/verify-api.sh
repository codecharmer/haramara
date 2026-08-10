#!/usr/bin/env bash
#
# Phase A API verification matrix — run against wp-env (default) or any
# WordPress install with haramara-core active.
#
#   BASE=http://localhost:8892 ADMIN_USER=admin ADMIN_APP_PASS=xxxx bin/verify-api.sh
#
# ADMIN_APP_PASS is a WordPress Application Password (Users → Profile →
# Application Passwords), NOT the login password. On wp-env create one with:
#   npm run env:cli -- user application-password create admin pos-test --porcelain
#
# The script:
#   1. reads /app/config and /pickup-dates (public reads)
#   2. asserts /pos/* rejects anonymous calls (401/403)
#   3. asserts /pos/board and /pos/products respond for the staff user
#   4. places a Store API guest checkout (Cart-Token + pickup additional_fields)
#   5. asserts the created order carries canonical _haramara_pickup_* meta
#   6. creates a POS walk-in and asserts: status completed, stock decremented,
#      slot capacity untouched
#   7. transitions the checkout order processing → preparing via /pos
#   8. records salidas internas and checks totals, stock, and input guards
#   9. exercises the shared employee list (add, case-insensitive dedupe, blank)
#  10. loyalty wallet pass: forged token 403; valid token without certs 503
#  11. wallet pass web service: log 200; unknown device 204; forged auth 401
set -euo pipefail

BASE="${BASE:-http://localhost:8892}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_APP_PASS="${ADMIN_APP_PASS:?set ADMIN_APP_PASS to an application password}"
JQ=${JQ:-jq}

pass() { printf '\033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '\033[31mFAIL\033[0m %s\n' "$1"; exit 1; }

api() { curl -sS "$@"; }
code() { curl -sS -o /dev/null -w '%{http_code}' "$@"; }

echo "== Base: $BASE"

# 1. Public reads ------------------------------------------------------------
CONFIG=$(api "$BASE/wp-json/haramara/v1/app/config")
echo "$CONFIG" | $JQ -e '.business.name and .payments' >/dev/null || fail "/app/config shape"
pass "/app/config"

DATES=$(api "$BASE/wp-json/haramara/v1/pickup-dates")
DATE=$(echo "$DATES" | $JQ -r '.dates[0].date // empty')
[ -n "$DATE" ] || fail "no bookable pickup dates — seed content first (wp haramara install)"
SLOT=$(api "$BASE/wp-json/haramara/v1/availability?date=$DATE" | $JQ -r '.slots[0].slot // empty')
[ -n "$SLOT" ] || fail "no slots for $DATE"
pass "pickup-dates/availability ($DATE $SLOT)"

# 2. Anonymous /pos rejected -------------------------------------------------
C=$(code "$BASE/wp-json/haramara/v1/pos/board")
[ "$C" = "401" ] || [ "$C" = "403" ] || fail "anonymous /pos/board returned $C (want 401/403)"
pass "anonymous /pos/board -> $C"

# 3. Staff auth --------------------------------------------------------------
AUTH=(-u "$ADMIN_USER:$ADMIN_APP_PASS")
api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/board" | $JQ -e '.date and (.slots|type=="array")' >/dev/null || fail "authed /pos/board"
pass "authed /pos/board"

PRODUCTS=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products")
PID=$(echo "$PRODUCTS" | $JQ -r '[.products[] | select(.in_stock and .manage_stock and (.stock_quantity != null) and .stock_quantity > 1)][0].id // empty')
STOCK_BEFORE=$(echo "$PRODUCTS" | $JQ -r "[.products[] | select(.id == ${PID:-0})][0].stock_quantity // empty")
[ -n "$PID" ] || fail "no stock-managed product found for the walk-in test"
pass "/pos/products (product $PID, stock $STOCK_BEFORE)"

# 4. Store API guest checkout with pickup fields -----------------------------
HDRS=$(mktemp)
api -D "$HDRS" "$BASE/wp-json/wc/store/v1/cart" >/dev/null
CART_TOKEN=$(awk 'tolower($1) ~ /^cart-token:/ {print $2}' "$HDRS" | tr -d '\r')
[ -n "$CART_TOKEN" ] || fail "no Cart-Token header from Store API"
api -X POST -H "Cart-Token: $CART_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"id\": $PID, \"quantity\": 1}" "$BASE/wp-json/wc/store/v1/cart/add-item" >/dev/null
CHECKOUT=$(api -X POST -H "Cart-Token: $CART_TOKEN" -H 'Content-Type: application/json' -d @- "$BASE/wp-json/wc/store/v1/checkout" <<EOF
{
  "billing_address": {"first_name": "Prueba", "last_name": "API", "email": "prueba@example.com", "phone": "+527770000000", "country": "MX"},
  "payment_method": "cod",
  "additional_fields": {"haramara/pickup-date": "$DATE", "haramara/pickup-slot": "$SLOT"}
}
EOF
)
ORDER_ID=$(echo "$CHECKOUT" | $JQ -r '.order_id // empty')
ORDER_KEY=$(echo "$CHECKOUT" | $JQ -r '.order_key // empty')
[ -n "$ORDER_ID" ] || { echo "$CHECKOUT" | $JQ .; fail "Store API checkout failed"; }
pass "Store API checkout -> order #$ORDER_ID"

# 5. Canonical pickup meta via the app status endpoint -----------------------
ORDER=$(api "$BASE/wp-json/haramara/v1/app/orders/$ORDER_ID?key=$ORDER_KEY")
echo "$ORDER" | $JQ -e ".pickup.date == \"$DATE\" and .pickup.slot == \"$SLOT\" and (.pickup.label|length) > 0" >/dev/null \
  || { echo "$ORDER" | $JQ .; fail "order $ORDER_ID missing canonical pickup meta"; }
pass "order carries _haramara_pickup_* meta (label: $(echo "$ORDER" | $JQ -r .pickup.label))"
C=$(code "$BASE/wp-json/haramara/v1/app/orders/$ORDER_ID?key=wrong")
[ "$C" = "404" ] || fail "wrong order key returned $C (want 404)"
pass "wrong order key -> 404"

# 6. Walk-in sale ------------------------------------------------------------
REMAIN_BEFORE=$(api "$BASE/wp-json/haramara/v1/availability?date=$DATE" | $JQ "[.slots[] | select(.slot == \"$SLOT\")][0].remaining")
WALKIN=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d "{\"items\": [{\"product_id\": $PID, \"quantity\": 1}], \"payment\": \"cash\"}" \
  "$BASE/wp-json/haramara/v1/pos/walk-in")
echo "$WALKIN" | $JQ -e '.status == "completed" and .created_via == "haramara-pos" and .pickup.date == ""' >/dev/null \
  || { echo "$WALKIN" | $JQ .; fail "walk-in shape"; }
pass "walk-in created #$(echo "$WALKIN" | $JQ -r .number) (completed, no pickup meta)"

STOCK_AFTER=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
[ "$STOCK_AFTER" -lt "$STOCK_BEFORE" ] || fail "walk-in did not decrement stock ($STOCK_BEFORE -> $STOCK_AFTER)"
pass "stock decremented ($STOCK_BEFORE -> $STOCK_AFTER)"

REMAIN_AFTER=$(api "$BASE/wp-json/haramara/v1/availability?date=$DATE" | $JQ "[.slots[] | select(.slot == \"$SLOT\")][0].remaining")
[ "$REMAIN_AFTER" = "$REMAIN_BEFORE" ] || fail "walk-in consumed slot capacity ($REMAIN_BEFORE -> $REMAIN_AFTER)"
pass "slot capacity untouched by walk-in ($REMAIN_AFTER remaining)"

# 7. Staff transition --------------------------------------------------------
T=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -d '{"status": "preparing"}' \
  "$BASE/wp-json/haramara/v1/pos/orders/$ORDER_ID/transition")
echo "$T" | $JQ -e '.status == "preparing"' >/dev/null || { echo "$T" | $JQ .; fail "transition"; }
pass "transition processing -> preparing"
C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -d '{"status": "pending"}' \
  "$BASE/wp-json/haramara/v1/pos/orders/$ORDER_ID/transition")
[ "$C" = "400" ] || fail "invalid target returned $C (want 400)"
pass "invalid transition target -> 400"

# 8. Inventory: recount + salidas internas -----------------------------------
C=$(code -X POST -H 'Content-Type: application/json' \
  -d "{\"items\": [{\"product_id\": $PID, \"quantity\": 1}], \"destination\": \"malva\"}" \
  "$BASE/wp-json/haramara/v1/pos/withdrawals")
[ "$C" = "401" ] || [ "$C" = "403" ] || fail "anonymous /pos/withdrawals returned $C (want 401/403)"
pass "anonymous /pos/withdrawals -> $C"

RECOUNT=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -d '{"quantity": 25}' \
  "$BASE/wp-json/haramara/v1/pos/products/$PID/stock")
echo "$RECOUNT" | $JQ -e '.stock_quantity == 25 and .in_stock == true' >/dev/null \
  || { echo "$RECOUNT" | $JQ .; fail "set-stock recount"; }
pass "set stock (recount) -> 25"

REVENUE_BEFORE=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/summary" | $JQ -r '.revenue')
WITHDRAWAL=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d "{\"items\": [{\"product_id\": $PID, \"quantity\": 3}], \"destination\": \"malva\", \"person\": \"Prueba API\"}" \
  "$BASE/wp-json/haramara/v1/pos/withdrawals")
echo "$WITHDRAWAL" | $JQ -e '.destination == "malva" and .total_quantity == 3 and (.key|length) > 0' >/dev/null \
  || { echo "$WITHDRAWAL" | $JQ .; fail "withdrawal shape"; }
pass "withdrawal 3x product $PID -> malva"

STOCK_NOW=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
[ "$STOCK_NOW" = "22" ] || fail "withdrawal did not decrement stock (25 -> $STOCK_NOW, want 22)"
pass "withdrawal decremented stock (25 -> 22)"

HISTORY=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/withdrawals")
echo "$HISTORY" | $JQ -e '.totals.by_destination.malva.pieces >= 3' >/dev/null \
  || { echo "$HISTORY" | $JQ .; fail "withdrawals history totals"; }
pass "/pos/withdrawals history + totals"

SUMMARY=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/summary")
echo "$SUMMARY" | $JQ -e ".revenue == $REVENUE_BEFORE and .withdrawals.pieces >= 3" >/dev/null \
  || { echo "$SUMMARY" | $JQ .; fail "summary: withdrawals must not move revenue"; }
pass "summary shows salidas, revenue unchanged"

C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d "{\"items\": [{\"product_id\": $PID, \"quantity\": 1}], \"destination\": \"cafeteria\"}" \
  "$BASE/wp-json/haramara/v1/pos/withdrawals")
[ "$C" = "400" ] || fail "bad destination returned $C (want 400)"
pass "invalid destination -> 400"

C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d "{\"items\": [{\"product_id\": $PID, \"quantity\": 99}], \"destination\": \"merma\"}" \
  "$BASE/wp-json/haramara/v1/pos/withdrawals")
[ "$C" = "409" ] || fail "over-stock withdrawal returned $C (want 409)"
pass "over-stock withdrawal -> 409"

# 9. Employees list (withdrawal person picker) --------------------------------
C=$(code "$BASE/wp-json/haramara/v1/pos/employees")
[ "$C" = "401" ] || [ "$C" = "403" ] || fail "anonymous /pos/employees returned $C (want 401/403)"
pass "anonymous /pos/employees -> $C"

api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/employees" | $JQ -e '.employees | type == "array"' >/dev/null || fail "/pos/employees shape"
pass "GET /pos/employees"

EMPLOYEES=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d '{"name": "Lupita Prueba"}' "$BASE/wp-json/haramara/v1/pos/employees")
echo "$EMPLOYEES" | $JQ -e '.employees | index("Lupita Prueba") != null' >/dev/null || fail "add employee"
COUNT=$(echo "$EMPLOYEES" | $JQ '.employees | length')
pass "POST /pos/employees (Lupita Prueba, $COUNT total)"

DUP=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d '{"name": "lupita prueba"}' "$BASE/wp-json/haramara/v1/pos/employees")
echo "$DUP" | $JQ -e ".employees | length == $COUNT" >/dev/null || fail "duplicate employee must be a no-op"
pass "duplicate employee -> no-op"

C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
  -d '{"name": "   "}' "$BASE/wp-json/haramara/v1/pos/employees")
[ "$C" = "400" ] || fail "blank employee name returned $C (want 400)"
pass "blank employee name -> 400"

# 10. Apple Wallet loyalty pass (unconfigured server) --------------------------
C=$(code "$BASE/wp-json/haramara/v1/app/loyalty/wallet-pass?token=not-a-real-token")
[ "$C" = "403" ] || fail "wallet-pass with forged token returned $C (want 403)"
pass "wallet-pass forged token -> 403"

api "$BASE/wp-json/haramara/v1/app/config" | $JQ -e '.wallet_pass == false' >/dev/null \
  || fail "config wallet_pass must be false without certificates"
pass "config wallet_pass -> false (no certificates)"

CARD_TOKEN=$(api -X POST -H 'Content-Type: application/json' -d '{"device": "verify-api-device"}' \
  "$BASE/wp-json/haramara/v1/app/loyalty/register" | $JQ -r '.token')
C=$(code "$BASE/wp-json/haramara/v1/app/loyalty/wallet-pass?token=$CARD_TOKEN")
[ "$C" = "503" ] || fail "wallet-pass on unconfigured server returned $C (want 503)"
pass "wallet-pass valid token, no certificates -> 503"

# 11. Wallet pass web service (config-independent surface) ---------------------
SERIAL=$(echo "$CARD_TOKEN" | cut -d. -f1)
C=$(code -X POST -H 'Content-Type: application/json' -d '{"logs":["verify-api"]}' \
  "$BASE/wp-json/haramara/v1/wallet/v1/log")
[ "$C" = "200" ] || fail "wallet web service log returned $C (want 200)"
pass "wallet web service log -> 200"

C=$(code "$BASE/wp-json/haramara/v1/wallet/v1/devices/verifyapidevice00/registrations/pass.mx.haramara.lealtad")
[ "$C" = "204" ] || fail "registrations for unknown device returned $C (want 204)"
pass "wallet registrations, unknown device -> 204"

C=$(code -X POST -H 'Content-Type: application/json' -H 'Authorization: ApplePass forged-token' \
  -d '{"pushToken":"ExponentPushTokenFake"}' \
  "$BASE/wp-json/haramara/v1/wallet/v1/devices/verifyapidevice00/registrations/pass.mx.haramara.lealtad/$SERIAL")
[ "$C" = "401" ] || fail "device registration with forged auth returned $C (want 401)"
pass "wallet device registration forged auth -> 401"

C=$(code -H 'Authorization: ApplePass forged-token' \
  "$BASE/wp-json/haramara/v1/wallet/v1/passes/pass.mx.haramara.lealtad/$SERIAL")
[ "$C" = "401" ] || fail "latest-pass with forged auth returned $C (want 401)"
pass "wallet latest-pass forged auth -> 401"

echo
echo "All Phase A checks passed. Test orders left in DB: #$ORDER_ID (pickup), walk-in above."
echo "Inventory checks left product $PID at stock 22 plus withdrawal log rows."
echo "Loyalty checks left one member registered to device 'verify-api-device'."
