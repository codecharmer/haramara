# Haramara Café — Digital Platform

The complete digital platform for **Haramara Café**, a specialty-coffee and
sourdough café in Cuernavaca, Morelos, México: a bilingual (ES · EN) WordPress
storefront with reserve-&-pickup ordering, a staff POS app, a customer app,
and a QR loyalty program with a live-updating Apple Wallet pass. Live at
[haramara.cafe](https://haramara.cafe).

Built as a monorepo with a strict split: a presentation-only **FSE block
theme**, a **custom plugin** that owns every line of business logic, and two
**Expo apps** that consume the plugin's REST surface.

---

## Monorepo layout

```
haramara/
├── wp-content/
│   ├── themes/haramara/            # FSE block theme — presentation ONLY
│   │   ├── theme.json              # Design-token source of truth (dark brand)
│   │   ├── templates/ parts/       # Block templates + parts (.html)
│   │   └── patterns/               # Registered block patterns (.php)
│   └── plugins/haramara-core/      # ALL business logic — Haramara\Core\ (PSR-4)
│       ├── src/                    # Setup/ Woo/ Ordering/ Sms/ Push/ Loyalty/
│       │                           #   I18n/ Rest/ Seo/ Admin/ Cli/
│       ├── data/                   # Seed catalog, pages, EN translation dictionary
│       └── assets/wallet/          # Apple Wallet pass artwork
├── apps/
│   ├── customer/                   # Expo app — menu, checkout, order status, loyalty
│   └── pos/                        # Expo app — staff tablet (orders, sales, inventory)
├── packages/api-client/            # Shared TypeScript client (PosApi / AppApi / StoreApi)
├── bin/verify-api.sh               # End-to-end API verification harness
├── docs/apple-wallet.md            # Wallet pass setup + operations runbook
└── .github/workflows/deploy.yml    # Push to master → production deploy
```

The plugin boots an ordered service container (`src/Plugin.php`); missing
modules are skipped gracefully. A versioned DB migration (`Activator::
maybe_upgrade()`, schema gen 3) runs on deploy, so new tables never need a
manual activation step.

## Features

### Storefront (WordPress + WooCommerce)

- **Reserve & pickup ordering** — pickup date + capacity-limited time slots,
  2-hour lead time, open days Wed–Mon, prices in MXN. Pickup-only, no
  shipping. Checkout via the WooCommerce cart/checkout blocks + Store API.
- **Payments** — "Paga al recoger" (pay at pickup) is live; the WooCommerce
  Stripe gateway is installed and ready (cards go live when API keys are
  added in wp-admin).
- **Bilingual ES · EN** — Spanish is canonical; `/en/` is a *virtual* mirror
  (no duplicated content): the URL prefix is stripped before routing, the
  locale flips to `en_US`, and rendered pages pass through a full-page
  translation dictionary. hreflang alternates on both languages; ES·EN
  switcher in the site chrome.
- **Stock-managed catalog** — sold-out products render a disabled "Agotado"
  state on the shop and product pages; low-stock events re-broadcast on a
  plugin hook for admin pings. New products default to managed stock.
- **SEO** — meta tags plus a `LocalBusiness`/product/breadcrumb schema graph.
- **HPOS-ready** — WooCommerce High-Performance Order Storage + checkout
  blocks compatibility declared.

### POS app (`apps/pos` — Expo, staff tablet)

- **Entrantes** — incoming online orders with push alerts and slide-to-accept.
- **Pedidos** — the live pickup board by time slot, with status transitions
  (En fila → Preparando → Listo → Entregado).
- **Mostrador** — walk-in counter sales (cash / external card terminal);
  completed orders decrement stock and roll into the day's totals.
- **Inventario** — three modes: *Existencias* (absolute stock recounts with
  steppers), *Nueva salida* (salidas internas: product leaving inventory
  without a sale — destinations Malva · Empleado · Merma · Otro, with an
  employee picker and notes; decrements stock, never counts as revenue), and
  *Historial* (per-day log with per-destination totals).
- **Lealtad** — camera QR scanner (with manual token fallback) to stamp and
  redeem customer loyalty cards.
- **Corte del día** — daily close: revenue by channel and payment method, top
  sellers, and salidas internas valued at price snapshots.
- Auth is a WordPress Application Password (Basic over HTTPS) resolving to
  the `manage_haramara` capability. OTA updates via EAS Update.

### Customer app (`apps/customer` — Expo)

- Menu + cart + Store API checkout (same pickup slots as the web).
- Order status screen with **push notifications** on every status change
  (Expo push; the device registers its token against the order at checkout).
- **Lealtad** — an anonymous, device-registered loyalty card rendered as a QR
  (no accounts, no sign-up), plus the **"Agregar a Apple Wallet"** button.
- Checkout remembers name/phone/email on the device. OTA updates via EAS.

### Loyalty + Apple Wallet

- **Members** — anonymous device-registered members in a hidden CPT with
  HMAC-signed card tokens. The API counts stamps and redemptions only —
  reward mechanics are deliberately not encoded anywhere.
- **Wallet pass (Phase 1)** — a signed `.pkpass` store card served by the
  plugin (`GET /app/loyalty/wallet-pass?token=`), gated by four
  `HARAMARA_WALLET_*` wp-config constants. Unconfigured installs answer 503
  and the app hides the button. The pass QR is the same signed token the POS
  scanner reads.
- **Live updates (Phase 2)** — the full Apple PassKit Web Service: passes
  carry `webServiceURL` + a per-pass `authenticationToken`, devices register
  in `{prefix}haramara_wallet_devices`, and every stamp/redeem fires an
  HTTP/2 APNs push authenticated with the same pass certificate — installed
  passes refresh themselves. Runbook: [`docs/apple-wallet.md`](docs/apple-wallet.md).

### Notifications

- **Customer** — WhatsApp/SMS via Twilio on status changes (all messages
  logged to a custom table with an admin screen and a test-send tool), plus
  Expo push to the app.
- **Staff** — new paid orders push to every registered POS device.

### Admin (wp-admin)

- Operations dashboard, production calendar, reports, and payments screens.
- **Ajustes** — tabbed settings: Negocio, Recolección (pickup windows/slots/
  blackouts), SMS (Twilio, constants-aware secret fields), SEO, and Empleados
  (the shared employee list behind the POS salida picker).
- WP-CLI: `wp haramara install` seeds the full catalog, pages, and
  navigation; `wp haramara seed-products` repairs the catalog idempotently.

## REST surface (`haramara/v1`)

| Area | Routes |
| --- | --- |
| Public app | `/app/config` · `/app/orders/{id}` (+push-token) · `/pickup-dates` · `/availability` |
| Loyalty | `/app/loyalty/{register,card,wallet-pass}` · `/pos/loyalty/{stamp,redeem}` |
| POS (staff) | `/pos/{board,queue,summary,products,walk-in,push-token}` · `/pos/orders/{id}/{accept,transition}` · `/pos/products/{id}/stock` · `/pos/withdrawals` · `/pos/employees` |
| PassKit Web Service | `/wallet/v1/devices/…/registrations/…` · `/wallet/v1/passes/…` · `/wallet/v1/log` |

Checkout itself rides the WooCommerce Store API (`wc/store/v1`) with
`haramara/pickup-date` + `haramara/pickup-slot` additional fields.

## Requirements

| Component | Version |
| --- | --- |
| WordPress | 6.8+ |
| PHP | 8.3+ |
| WooCommerce | pinned via Composer (wpackagist) |
| Node.js | LTS |
| Composer | 2.x |
| Expo | SDK 57 (managed / CNG — no `ios`/`android` dirs) |

## Quick start (local)

```bash
composer install                # phpcs, phpstan, WP/Woo stubs
npm install                     # workspaces: theme + apps + api-client

npm run env:start               # local WordPress at :8892 (Docker)
npm run env:install-content     # wp haramara install (seed catalog + pages)

npm run app:customer            # expo start — customer app
npm run app:pos                 # expo start — POS app
```

Web preview of the customer app:
`cd apps/customer && EXPO_PUBLIC_API_URL=http://localhost:8892 npx expo start --web --port 8083`.

## Quality gates & verification

```bash
composer run check              # phpcs + phpstan level 6 — THE PHP gate
npm run typecheck:apps          # api-client + both apps tsc --noEmit — THE TS gate

# End-to-end API matrix against wp-env (33 checks: ordering, walk-ins,
# inventory guards, employees, loyalty, wallet dark-state, web service):
ADMIN_APP_PASS=<application password> bin/verify-api.sh
```

There are no unit-test suites; the two gates plus the verification harness
are the contract.

## Deployment

Every push to `master` deploys to production via GitHub Actions (rsync of the
theme + pinned plugins + `haramara-core`, idempotent activation, ES language
packs). DB migrations run on the first request after deploy. The `apps/` and
`packages/` trees never deploy — apps ship through EAS builds and EAS Update.

## Product ground rules

- **No reservations feature — ever.** Ordering is reserve-&-pickup only.
- Loyalty counts stamps and redemptions; **no surface may promise a reward
  tier** (mechanics are an open product decision).
- WhatsApp is the customer contact channel; currency is MXN.
- The low-light photography at the repo root is the brand — never substitute
  stock imagery.

## License

GPL-2.0-or-later.
