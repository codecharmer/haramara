# Apple Wallet — tarjeta de lealtad (.pkpass)

The plugin serves a signed Apple Wallet store card at
`GET /wp-json/haramara/v1/app/loyalty/wallet-pass?token=<card token>` (see
`Loyalty\WalletPass`). Everything is code except the signing identity: a Pass
Type ID certificate from the Apple Developer account and Apple's WWDR
intermediate. Until those are configured the endpoint answers 503,
`/app/config` reports `"wallet_pass": false`, and the customer app hides the
button — so this setup can happen any time after deploy, in any order.

The steps below are the ONLY manual work, ~15 minutes. Steps 1–2 happen on any
machine with openssl + the Apple Developer login; steps 3–4 on the server.

Account facts (verified 2026-08-09, during the identical Pacífica setup):
Team **codeCharmer LLC**, Team ID **`5AYU2BZ3D8`** — both businesses sign
under the same team. Portal login is the owner's Apple ID.

## 1. Apple Developer portal — create the Pass Type ID + certificate

1. https://developer.apple.com/account → **Certificates, Identifiers &
   Profiles → Identifiers** → `+` → **Pass Type IDs**.
2. Description `Haramara Lealtad`, identifier **`pass.mx.haramara.lealtad`**.
   ⚠️ The Identifier field **auto-prepends `pass.`** — type only
   `mx.haramara.lealtad`, and verify the exact string BEFORE clicking
   Continue: the identifier is immutable after registration (only the
   description stays editable).
3. Generate the CSR with openssl (no Keychain, no `.p12`, no export password —
   the private key is born as a PEM):

   ```bash
   openssl req -new -newkey rsa:2048 -nodes -keyout pass-key.pem -out haramara-pass.csr \
     -subj "/CN=Haramara Lealtad Pass/O=codeCharmer LLC/C=MX"
   ```

4. Open the new Pass Type ID → **Create Certificate**. Fill the optional
   **certificate Name** field (e.g. `Haramara Lealtad`) — otherwise the
   Certificates list shows a stale/wrong display name forever. Upload
   `haramara-pass.csr`, download `pass.cer`.
   ⚠️ Chrome may hold the download in a save dialog without writing the file —
   check the file's **mtime and subject**, not just its existence:
   `openssl x509 -inform DER -in pass.cer -noout -subject`.

## 2. Convert and pair-check the certificate

```bash
openssl x509 -inform DER -in pass.cer -out pass-cert.pem

# MUST: confirm the cert/key pair matches before shipping — the two hashes
# must be identical:
openssl x509 -noout -modulus -in pass-cert.pem | shasum
openssl rsa  -noout -modulus -in pass-key.pem  | shasum

cat pass-cert.pem pass-key.pem > haramara-pass.pem && chmod 600 haramara-pass.pem
```

Download Apple's WWDR intermediate — **G4** (certs issued since 2022 chain to
G4; the wrong generation makes iOS reject passes silently):

```bash
curl -sO https://www.apple.com/certificateauthority/AppleWWDRCAG4.cer
openssl x509 -inform DER -in AppleWWDRCAG4.cer -out wwdr-g4.pem   # valid to Dec 2030
```

⚠️ With this flow **the PEM on the server is the only copy of the private
key** (there is no Keychain backup). Keep a safe copy; losing it means revoke
+ reissue in the portal (~10 min — installed passes keep working, only
re-signing stops).

## 3. Upload to the server — OUTSIDE the webroot

The deploy rsyncs `wp-content` with `--delete` and chmods files 644, so the
certificates must never live under `public_html`:

```bash
ssh root@72.167.225.151
mkdir -p /home/haramara/private/wallet
# (copy haramara-pass.pem and wwdr-g4.pem into that directory, e.g. with scp)
chown -R haramara:haramara /home/haramara/private
chmod 700 /home/haramara/private /home/haramara/private/wallet
chmod 600 /home/haramara/private/wallet/*.pem
```

## 4. wp-config constants

`sudo -u <siteuser> wp` fails on this box (`wp` not in sudo's secure_path;
PHP-CGI answers "Only CLI access"). Use the deploy workflow's own pattern:

```bash
export WP_CLI_PHP_ARGS='-d memory_limit=512M'
WP="wp --path=/home/haramara/public_html --allow-root"
$WP config set HARAMARA_WALLET_PASS_TYPE_ID 'pass.mx.haramara.lealtad' --type=constant
$WP config set HARAMARA_WALLET_TEAM_ID '5AYU2BZ3D8' --type=constant
$WP config set HARAMARA_WALLET_CERT_PATH '/home/haramara/private/wallet/haramara-pass.pem' --type=constant
$WP config set HARAMARA_WALLET_WWDR_PATH '/home/haramara/private/wallet/wwdr-g4.pem' --type=constant
# Only if the key kept a passphrase (the flow above strips it):
# $WP config set HARAMARA_WALLET_CERT_PASSWORD '<passphrase>' --type=constant
chown haramara:haramara /home/haramara/public_html/wp-config.php   # wp-cli ran as root

# Flush caches or the flag flip is invisible for up to 5 min
# (/app/config is Cache-Control: public, max-age=300):
$WP cache flush
```

Like the Twilio credentials, these are constants-only by design — never stored
in the database. **A wp-config rebuild loses them** (same gap documented for
the Twilio constants in the recovery notes).

## 5. Verify

```bash
# Config flag flips:
curl -s https://haramara.cafe/wp-json/haramara/v1/app/config | jq .wallet_pass
# → true

# A real pass (grab a token by registering a throwaway member):
TOKEN=$(curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"device": "wallet-verify"}' \
  https://haramara.cafe/wp-json/haramara/v1/app/loyalty/register | jq -r .token)
curl -s -o /tmp/lealtad.pkpass \
  "https://haramara.cafe/wp-json/haramara/v1/app/loyalty/wallet-pass?token=$TOKEN"
file /tmp/lealtad.pkpass          # → Zip archive data
unzip -l /tmp/lealtad.pkpass      # → pass.json, manifest.json, signature, 5 PNGs — 8 files

# Identifiers must match the certificate exactly:
unzip -oq /tmp/lealtad.pkpass -d /tmp/lealtad
jq '{passTypeIdentifier,teamIdentifier}' /tmp/lealtad/pass.json

# Full-chain check — rules out ALL silent-rejection causes. Verifying with
# -CAfile of only the WWDR fails with "unable to get issuer certificate";
# that's expected — append Apple's root:
curl -s -O https://www.apple.com/appleca/AppleIncRootCertificate.cer
openssl x509 -inform DER -in AppleIncRootCertificate.cer -out apple-root.pem
cat wwdr-g4.pem apple-root.pem > chain.pem
openssl smime -verify -inform DER -in /tmp/lealtad/signature \
  -content /tmp/lealtad/manifest.json -CAfile chain.pem -purpose any
# → Verification successful
```

Real-device test: AirDrop `/tmp/lealtad.pkpass` to an iPhone, or tap
"Agregar a Apple Wallet" on the Lealtad tab in the app. Then scan the pass QR
on the POS Lealtad screen and stamp — that proves token-shape compatibility
end to end.

### Diagnosis by error code

- `haramara_wallet_not_configured` (503) — constants missing/empty; re-check §4.
- `haramara_wallet_cert_unreadable` (503) — path/permissions; also check the
  PHP-FPM pool's **`open_basedir`** (it may exclude `/home/<user>/private` —
  if so, add the path to the pool config).
- `haramara_wallet_unavailable` (503) — ZipArchive or openssl missing from the
  PHP build.
- Silent iOS rejection with a valid chain ⇒ (a) wrong WWDR generation,
  (b) `passTypeIdentifier`/`teamIdentifier` mismatch vs the certificate, or
  (c) an expired certificate.

## Phase 2 (plugin 1.2.0): automatic stamp updates

Passes now update themselves. Each pass carries `webServiceURL`
(`rest_url('haramara/v1/wallet')`) and a per-pass `authenticationToken`;
devices register with `Loyalty\WalletWebService` (registrations live in the
`{prefix}haramara_wallet_devices` table, schema gen 3), and every stamp or
redeem fires a direct APNs push authenticated with the SAME pass certificate —
no `.p8` key, no new constants, no configuration beyond the four above.
Wallet then silently re-fetches the pass (`Last-Modified`/304 aware).

Two operational notes:

- **Passes added before 1.2.0 have no `webServiceURL`** — they update after
  being re-added once ("Agregar a Apple Wallet" again; same serial, replaces
  in place).
- The push goes out inline during the POS stamp/redeem request over HTTP/2
  (`api.push.apple.com`, client cert = the combined PEM). APNs failures are
  logged under `WP_DEBUG` and never fail the stamp; a 410 response prunes the
  dead registration.

Quick prod check that the web service is alive (config-independent):

```bash
BASE=https://haramara.cafe/wp-json/haramara/v1
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/wallet/v1/log" \
  -H 'Content-Type: application/json' -d '{"logs":[]}'          # 200
curl -s -o /dev/null -w '%{http_code}\n' \
  "$BASE/wallet/v1/devices/deadbeefdeadbeef/registrations/pass.mx.haramara.lealtad"  # 204
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  "$BASE/wallet/v1/devices/deadbeefdeadbeef/registrations/pass.mx.haramara.lealtad/notaserialx" \
  -H 'Authorization: ApplePass forged' -d '{"pushToken":"x"}'   # 401
```

End-to-end without a device: download a pass (§5), read `authenticationToken`
from its `pass.json`, register a fake device with it (expect 201), fetch
`/wallet/v1/passes/…/{serial}` with `Authorization: ApplePass <token>`
(expect a `.pkpass`), then stamp via the POS and re-fetch to see the new
counters.

Two proxy-cache facts from pacifica's first prod verification (2026-08-09),
which apply verbatim here — same cPanel/NGINX stack: every web-service and
loyalty-card response is deliberately `no-store`, because the NGINX proxy
caches any GET without explicit cache headers — a stale registrations listing
made a device "miss" an update until the cache was busted. And don't expect
304s through the proxy: NGINX strips `If-Modified-Since` on its cache path,
so PHP re-serves full 200s; the conditional path still works on
direct/unproxied setups and costs nothing.
