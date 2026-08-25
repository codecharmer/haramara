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
#  12. operator identity: roster, PIN login, bad PIN 401, forged token 401,
#      supervisor step-up, cajero refused, operator stamped on a walk-in
#  13. idempotency: same key replays the original order without a second charge
#  14. loyalty stamp is guarded — a double-tap must not stamp twice
#  15. turno de caja: open (fondo), summary redaction while open, cash drop,
#      blind close computes expected+variance server-side, double-close 409
#  16. ajustes: folio on every walk-in; same-shift void by cajero (restocks,
#      ledger row); discount over threshold 403 without supervisor; comp with
#      authorization rings \$0 and lands in the adjustments buckets
#  17. propinas: a cash tip never touches revenue or the order total, but DOES
#      raise the blind close's expected cash; card tips touch neither
#  18. cuentas abiertas (skipped when the open_tabs flag is off): stock moves
#      at serve-time, removing a line restocks + writes a tab_id ledger row,
#      the shift refuses to close over an open tab, and settling the tab
#      creates the order WITHOUT double-decrementing stock
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

# 12. Operator identity (Staff\Operators) -----------------------------------
#
# Requires at least one person with a NIP. Seed on wp-env with:
#   npm run env:cli -- eval 'Haramara\Core\Staff\Operators::set_pin( Haramara\Core\Staff\Operators::people()[0]["key"], "4321" );'
# Skipped (not failed) when the roster is empty — the PIN layer is optional
# until the owner configures it, and this script must stay green before then.
ROSTER=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/operators")
OP_KEY=$(echo "$ROSTER" | $JQ -r '.operators[0].key // empty')

if [ -z "$OP_KEY" ]; then
  printf '\033[33mSKIP\033[0m operator checks — no one has a NIP set yet\n'
else
  echo "$ROSTER" | $JQ -e '[.operators[] | select(has("pin_hash"))] | length == 0' >/dev/null \
    || fail "roster leaked pin_hash"
  pass "/pos/operators (no pin_hash exposed)"

  C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
    -d "{\"operator\":\"$OP_KEY\",\"pin\":\"000000\"}" "$BASE/wp-json/haramara/v1/pos/operator/login")
  [ "$C" = "401" ] || [ "$C" = "429" ] || fail "wrong PIN returned $C (want 401, or 429 if throttled)"
  pass "wrong PIN -> $C"

  # OPERATOR_PIN defaults to the seed value above; override for a real install.
  SESSION=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
    -d "{\"operator\":\"$OP_KEY\",\"pin\":\"${OPERATOR_PIN:-4321}\"}" "$BASE/wp-json/haramara/v1/pos/operator/login")
  OP_TOKEN=$(echo "$SESSION" | $JQ -r '.token // empty')
  [ -n "$OP_TOKEN" ] || { echo "$SESSION" | $JQ .; fail "PIN login failed (set OPERATOR_PIN if not 4321)"; }
  echo "$SESSION" | $JQ -e '.operator | has("pin_hash") | not' >/dev/null || fail "session leaked pin_hash"
  pass "PIN login -> session token"

  C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
    -H "X-Pos-Operator: $OP_KEY.9999999999.deadbeef" \
    -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\"}" \
    "$BASE/wp-json/haramara/v1/pos/walk-in")
  [ "$C" = "401" ] || fail "forged operator token returned $C (want 401)"
  pass "forged operator token -> 401"

  # A cajero must not be able to authorize; a supervisor must.
  SUP_KEY=$(echo "$ROSTER" | $JQ -r '[.operators[] | select(.role == "supervisor")][0].key // empty')
  if [ -n "$SUP_KEY" ]; then
    AUTHZ=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
      -d "{\"operator\":\"$SUP_KEY\",\"pin\":\"${SUPERVISOR_PIN:-5555}\",\"action\":\"void\"}" \
      "$BASE/wp-json/haramara/v1/pos/operator/authorize")
    echo "$AUTHZ" | $JQ -e '(.authorization | split(".") | length == 3) and (.authorized_by | length > 0)' >/dev/null \
      || { echo "$AUTHZ" | $JQ .; fail "supervisor step-up shape (set SUPERVISOR_PIN if not 5555)"; }
    pass "supervisor step-up -> key.expiry.hmac"
  else
    printf '\033[33mSKIP\033[0m supervisor step-up — no supervisor on the roster\n'
  fi

  # 13. Operator stamping + idempotency replay -------------------------------
  IDK="verify-$(date +%s)-$RANDOM"
  BODY="{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\",\"idempotency_key\":\"$IDK\"}"

  FIRST=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
    -d "$BODY" "$BASE/wp-json/haramara/v1/pos/walk-in")
  FIRST_ID=$(echo "$FIRST" | $JQ -r '.id // empty')
  [ -n "$FIRST_ID" ] || { echo "$FIRST" | $JQ .; fail "attributed walk-in failed"; }
  pass "attributed walk-in -> order #$FIRST_ID"

  STOCK_MID=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" \
    | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")

  RH=$(mktemp)
  SECOND=$(api -D "$RH" "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
    -d "$BODY" "$BASE/wp-json/haramara/v1/pos/walk-in")
  SECOND_ID=$(echo "$SECOND" | $JQ -r '.id // empty')
  [ "$SECOND_ID" = "$FIRST_ID" ] || fail "replay created order #$SECOND_ID instead of returning #$FIRST_ID — DOUBLE CHARGE"
  grep -qi '^x-pos-idempotent-replay: 1' "$RH" || fail "replay missing X-Pos-Idempotent-Replay header"
  pass "idempotent replay -> same order #$SECOND_ID"

  STOCK_AFTER=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" \
    | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
  [ "$STOCK_AFTER" = "$STOCK_MID" ] || fail "replay moved stock $STOCK_MID -> $STOCK_AFTER (must not decrement twice)"
  pass "replay did not decrement stock (stayed $STOCK_AFTER)"

  # 14. Loyalty stamp is guarded — a redeem is free product, a stamp is a
  # discount toward one, and a double-tap at the bar must not count twice.
  LCARD=$(api -X POST -H 'Content-Type: application/json' \
    -d '{"device":"verify-api-guard-device"}' "$BASE/wp-json/haramara/v1/app/loyalty/register")
  LTOKEN=$(echo "$LCARD" | $JQ -r '.token // empty')
  [ -n "$LTOKEN" ] || { echo "$LCARD" | $JQ .; fail "loyalty register failed"; }

  LIDK="verify-stamp-$(date +%s)-$RANDOM"
  LBODY="{\"token\":\"$LTOKEN\",\"idempotency_key\":\"$LIDK\"}"
  S1=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
    -d "$LBODY" "$BASE/wp-json/haramara/v1/pos/loyalty/stamp" | $JQ -r '.stamps')
  S2=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
    -d "$LBODY" "$BASE/wp-json/haramara/v1/pos/loyalty/stamp" | $JQ -r '.stamps')
  [ "$S1" = "$S2" ] || fail "replayed stamp incremented $S1 -> $S2 (must not stamp twice)"
  pass "loyalty stamp replay did not double-count (stayed $S2)"

  # 15. Turno de caja — only when no shift is already open (a stray open shift
  # from manual testing must not make CI ring against it).
  CURRENT=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/shift/current" | $JQ -r '.shift // empty')
  if [ -n "$CURRENT" ]; then
    printf '\033[33mSKIP\033[0m shift checks — a shift is already open\n'
  else
    SIDK="verify-shift-$(date +%s)-$RANDOM"
    OPENED=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"opening_float\": 500, \"idempotency_key\": \"$SIDK-open\"}" "$BASE/wp-json/haramara/v1/pos/shift/open")
    echo "$OPENED" | $JQ -e '.shift.status == "open" and (.shift | has("expected_cash") | not)' >/dev/null \
      || { echo "$OPENED" | $JQ .; fail "shift open (expected_cash must be absent)"; }
    pass "shift open, no expected_cash exposed"

    # While open, a non-supervisor summary must redact everything cash derives from.
    RED=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/summary")
    # Empty PHP maps encode as [] not {}, and jq has() errors on arrays.
    echo "$RED" | $JQ -e '(.cash_visible == false) and (has("revenue") | not) and ((.by_payment_method | if type == "object" then has("cod") else false end) | not) and ((.by_channel | if type == "object" then has("walkin_cash") else false end) | not)' >/dev/null \
      || { echo "$RED" | $JQ '{cash_visible, revenue}'; fail "summary not redacted while shift open"; }
    pass "summary redacted for non-supervisor while shift open"

    api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\",\"idempotency_key\":\"$SIDK-sale\"}" \
      "$BASE/wp-json/haramara/v1/pos/walk-in" >/dev/null
    SALE_TOTAL=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" \
      | $JQ -r "[.products[] | select(.id == $PID)][0].price")

    api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"amount\": 100, \"idempotency_key\": \"$SIDK-drop\"}" "$BASE/wp-json/haramara/v1/pos/shift/cash-drop" >/dev/null

    # 16. Ajustes — inside the open shift, where the cajero void path lives.
    VSALE=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\",\"idempotency_key\":\"$SIDK-vsale\"}" \
      "$BASE/wp-json/haramara/v1/pos/walk-in")
    VID=$(echo "$VSALE" | $JQ -r '.id // empty')
    echo "$VSALE" | $JQ -e '.folio | test("^F[0-9A-Z]+-[0-9A-F]{4}$")' >/dev/null || fail "walk-in missing folio"
    pass "walk-in carries folio $(echo "$VSALE" | $JQ -r '.folio')"

    VOID=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"reason_code\":\"error_captura\",\"idempotency_key\":\"$SIDK-void\"}" \
      "$BASE/wp-json/haramara/v1/pos/orders/$VID/void")
    echo "$VOID" | $JQ -e '.event.type == "void" and (.event.operator | length > 0)' >/dev/null \
      || { echo "$VOID" | $JQ .; fail "same-shift cajero void"; }
    pass "same-shift void by cajero -> ledger row"

    C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\",\"discount\":{\"amount\": 999, \"reason_code\":\"ajuste_precio\"},\"idempotency_key\":\"$SIDK-bigdisc\"}" \
      "$BASE/wp-json/haramara/v1/pos/walk-in")
    [ "$C" = "400" ] || [ "$C" = "403" ] || fail "oversize/over-threshold discount returned $C (want 400/403)"
    pass "big discount without supervisor -> $C"

    if [ -n "$SUP_KEY" ]; then
      DAUTH=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' \
        -d "{\"operator\":\"$SUP_KEY\",\"pin\":\"${SUPERVISOR_PIN:-5555}\",\"action\":\"discount\"}" \
        "$BASE/wp-json/haramara/v1/pos/operator/authorize" | $JQ -r '.authorization // empty')
      COMP=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\",\"discount\":{\"amount\": $SALE_TOTAL, \"reason_code\":\"cortesia\",\"authorization\":\"$DAUTH\"},\"idempotency_key\":\"$SIDK-comp\"}" \
        "$BASE/wp-json/haramara/v1/pos/walk-in")
      echo "$COMP" | $JQ -e '.total == 0' >/dev/null || { echo "$COMP" | $JQ '{total}'; fail "comp did not zero the ticket"; }
      pass "cortesía with authorization -> \$0 ticket"

      ADJ=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/summary" | $JQ -r '.adjustments.by_type | keys | join(",")')
      case "$ADJ" in *void*) pass "adjustments buckets present ($ADJ)";; *) fail "no void bucket in adjustments ($ADJ)";; esac
    else
      printf '\033[33mSKIP\033[0m discount/comp authorization — no supervisor\n'
    fi

    # 17. Propinas — the asymmetry IS the check: revenue and order total must
    # not move, expected cash must (cash tip only).
    TSALE=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"cash\",\"tip\":{\"amount\": 10, \"method\":\"cash\"},\"idempotency_key\":\"$SIDK-tip\"}" \
      "$BASE/wp-json/haramara/v1/pos/walk-in")
    echo "$TSALE" | $JQ -e --argjson p "$SALE_TOTAL" '.total == $p and .tip == 10 and .tip_method == "cash"' >/dev/null \
      || { echo "$TSALE" | $JQ '{total, tip, tip_method}'; fail "cash tip leaked into the order total"; }
    pass "cash tip stored as meta, order total untouched"

    api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"payment\":\"card_external\",\"tip\":{\"amount\": 15, \"method\":\"card\"},\"idempotency_key\":\"$SIDK-tipcard\"}" \
      "$BASE/wp-json/haramara/v1/pos/walk-in" >/dev/null

    CLOSED=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"declared_cash\": 480, \"idempotency_key\": \"$SIDK-close\"}" "$BASE/wp-json/haramara/v1/pos/shift/close")
    # fondo 500 + two cash sales + cash tip 10 − drop 100. The card sale and
    # its card tip must contribute NOTHING here.
    WANT=$(echo "$SALE_TOTAL" | $JQ -r "500 + (2 * .) + 10 - 100")
    echo "$CLOSED" | $JQ -e --argjson want "$WANT" '.shift.expected_cash == $want and .shift.variance == (480 - $want)' >/dev/null \
      || { echo "$CLOSED" | $JQ .; fail "arqueo math wrong (want expected=$WANT)"; }
    pass "blind close: expected=$WANT, variance=$(echo "$CLOSED" | $JQ -r '.shift.variance')"

    C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
      -d "{\"declared_cash\": 1, \"idempotency_key\": \"$SIDK-close2\"}" "$BASE/wp-json/haramara/v1/pos/shift/close")
    [ "$C" = "409" ] || fail "second close returned $C (want 409)"
    pass "double close -> 409"

    TIPS=$(api "${AUTH[@]}" -H "X-Pos-Operator: $OP_TOKEN" "$BASE/wp-json/haramara/v1/pos/summary" \
      | $JQ -r '.tips.total // empty')
    [ -n "$TIPS" ] || fail "tips block missing from summary after close"
    pass "tips visible after close (total \$$TIPS today)"

    # 18. Cuentas abiertas — a second, self-contained shift.
    TABS_ON=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/config" | $JQ -r '.open_tabs // false')
    if [ "$TABS_ON" != "true" ]; then
      printf '\033[33mSKIP\033[0m cuentas abiertas — open_tabs flag off\n'
    else
      TBK="verify-tab-$(date +%s)-$RANDOM"
      api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"opening_float\": 600, \"idempotency_key\": \"$TBK-shift\"}" "$BASE/wp-json/haramara/v1/pos/shift/open" >/dev/null

      TABID=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"label\": \"Mesa verify\", \"idempotency_key\": \"$TBK-open\"}" "$BASE/wp-json/haramara/v1/pos/tabs" | $JQ -r '.tab.id')
      [ -n "$TABID" ] && [ "$TABID" != "null" ] || fail "tab open failed"

      S0=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
      api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 2}],\"idempotency_key\":\"$TBK-r1\"}" \
        "$BASE/wp-json/haramara/v1/pos/tabs/$TABID/lines" >/dev/null
      S1=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
      [ "$S1" = "$(( S0 - 2 ))" ] || fail "serve-time stock: $S0 -> $S1 (want -2)"
      pass "tab round took stock at serve-time ($S0 -> $S1)"

      api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"index\": 0, \"reason_code\": \"error_captura\", \"idempotency_key\": \"$TBK-rm\"}" \
        "$BASE/wp-json/haramara/v1/pos/tabs/$TABID/remove-line" >/dev/null
      S2=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
      [ "$S2" = "$S0" ] || fail "remove-line restock: $S1 -> $S2 (want $S0)"
      pass "remove-line restocked (+2) with a tab ledger row"

      api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"items\":[{\"product_id\": $PID, \"quantity\": 1}],\"idempotency_key\":\"$TBK-r2\"}" \
        "$BASE/wp-json/haramara/v1/pos/tabs/$TABID/lines" >/dev/null

      C=$(code "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"declared_cash\": 1, \"idempotency_key\": \"$TBK-blocked\"}" "$BASE/wp-json/haramara/v1/pos/shift/close")
      [ "$C" = "409" ] || fail "shift close over open tab returned $C (want 409)"
      pass "shift close blocked by open tab -> 409"

      S3=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
      TORDER=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"payment\": \"cash\", \"idempotency_key\": \"$TBK-settle\"}" "$BASE/wp-json/haramara/v1/pos/tabs/$TABID/close")
      echo "$TORDER" | $JQ -e --argjson p "$SALE_TOTAL" '.total == $p and (.folio | length > 0)' >/dev/null \
        || { echo "$TORDER" | $JQ '{total, folio}'; fail "tab settle order shape"; }
      S4=$(api "${AUTH[@]}" "$BASE/wp-json/haramara/v1/pos/products" | $JQ -r "[.products[] | select(.id == $PID)][0].stock_quantity")
      [ "$S4" = "$S3" ] || fail "tab settle moved stock $S3 -> $S4 (must not double-decrement)"
      pass "tab settled into order #$(echo "$TORDER" | $JQ -r '.id'), stock untouched at close"

      TCLOSE=$(api "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -H "X-Pos-Operator: $OP_TOKEN" \
        -d "{\"declared_cash\": $(( 600 + ${SALE_TOTAL%%.*} )), \"idempotency_key\": \"$TBK-close\"}" \
        "$BASE/wp-json/haramara/v1/pos/shift/close")
      echo "$TCLOSE" | $JQ -e '.shift.variance == 0' >/dev/null \
        || { echo "$TCLOSE" | $JQ '.shift'; fail "tab-settled cash missing from the arqueo"; }
      pass "tab-settled cash landed in the arqueo (variance 0)"
    fi
  fi
fi

# 19. Store API modifiers (customer-app path) — skipped when no product
# carries modifier groups. The extensions envelope must validate, reprice,
# label item_data, and produce distinct lines per selection.
MODP=$(api "$BASE/wp-json/wc/store/v1/products?per_page=100" \
  | $JQ -r '[.[] | select((.extensions.haramara.modifier_groups // []) | length > 0)][0]')
if [ -z "$MODP" ] || [ "$MODP" = "null" ]; then
  printf '\033[33mSKIP\033[0m store-api modifiers — no product carries groups\n'
else
  MPID=$(echo "$MODP" | $JQ -r '.id')
  MGID=$(echo "$MODP" | $JQ -r '.extensions.haramara.modifier_groups[0].id')
  MKEY=$(echo "$MODP" | $JQ -r '.extensions.haramara.modifier_groups[0].options | map(select(.price_delta != 0))[0].key // .extensions')
  MDELTA=$(echo "$MODP" | $JQ -r '.extensions.haramara.modifier_groups[0].options | map(select(.price_delta != 0))[0].price_delta // 0')
  if [ "$MKEY" = "null" ] || [ -z "$MKEY" ]; then
    printf '\033[33mSKIP\033[0m store-api modifiers — no nonzero-delta option on product %s\n' "$MPID"
  else
    MH=$(mktemp)
    api -D "$MH" "$BASE/wp-json/wc/store/v1/cart" >/dev/null
    MCT=$(awk 'tolower($1) ~ /^cart-token:/ {print $2}' "$MH" | tr -d '\r')
    MCART=$(api -X POST -H "Cart-Token: $MCT" -H 'Content-Type: application/json' \
      -d "{\"id\": $MPID, \"quantity\": 1, \"extensions\": {\"haramara\": {\"modifiers\": [{\"group_id\": $MGID, \"option_keys\": [\"$MKEY\"]}]}}}" \
      "$BASE/wp-json/wc/store/v1/cart/add-item")
    echo "$MCART" | $JQ -e '.items[0].item_data | length > 0' >/dev/null \
      || { echo "$MCART" | $JQ '.items[0].item_data? // .'; fail "store-api add carried no item_data"; }
    MBASE=$(echo "$MODP" | $JQ -r '.prices.price')
    MMU=$(echo "$MODP" | $JQ -r '.prices.currency_minor_unit')
    MWANT=$(python3 -c "print(int($MBASE) + int(round(float($MDELTA) * (10 ** $MMU))))")
    MGOT=$(echo "$MCART" | $JQ -r '.items[0].totals.line_total')
    [ "$MGOT" = "$MWANT" ] || fail "store-api reprice: line_total $MGOT (want $MWANT)"
    pass "store-api modifier add repriced ($MGOT minor units) with item_data"

    api -X POST -H "Cart-Token: $MCT" -H 'Content-Type: application/json' \
      -d "{\"id\": $MPID, \"quantity\": 1}" "$BASE/wp-json/wc/store/v1/cart/add-item" >/dev/null
    MLINES=$(api -H "Cart-Token: $MCT" "$BASE/wp-json/wc/store/v1/cart" | $JQ -r '.items | length')
    [ "$MLINES" = "2" ] || fail "distinct selections merged ($MLINES lines, want 2)"
    pass "distinct selections -> distinct cart lines"
  fi
fi

echo
echo "All Phase A checks passed. Test orders left in DB: #$ORDER_ID (pickup), walk-in above."
echo "Inventory checks left product $PID at stock 22 plus withdrawal log rows."
echo "Loyalty checks left one member registered to device 'verify-api-device'."
