# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

The digital platform for **Haramara Café** (Cuernavaca, MX): a bilingual (ES/EN) WordPress FSE site with live WooCommerce ordering + QR loyalty, a `haramara-core` plugin holding all business logic, and (later phase) an Expo customer app. Read `PRODUCT.md` first — it is the product source of truth (scope, launch requirements, brand constraints, what must never be fabricated). `DESIGN.md` (+ `.impeccable/design.json`) records the built visual system — the warm-black ground ladder, brass-is-light rule, Italiana/Petrona type ramp, and component grammar; consult it before any UI change. All design work routes through the `/impeccable` skill.

Build sequence agreed with the owner: planning docs → website → mobile app.

Hard scope rules from the owner: **no reservations feature, ever**; ordering + payments (Stripe/Apple Pay/Google Pay) and QR loyalty must really work at launch; bilingual ES/EN at launch (mechanism not yet chosen — decide before the content build); currency MXN; WhatsApp is the customer contact channel.

## Reference repos (read, never modify)

- `~/pacifica` — the working sibling-brand implementation (Pacífica Panadería, same restaurant family). The authoritative reference for every engine question: plugin service container, ordering/pickup flow, Twilio/WhatsApp notifications, Expo push, REST routes, POS app. Key docs: `~/pacifica/docs/METHODOLOGY.md`, `docs/MOBILE.md`, `docs/architecture.md`, `docs/setup.md`.
- `~/acme-starter` — the de-branded extraction of pacifica this project derives from. **It ships with known defects; fix them during derivation instead of tripping over them:**
  - `wp-content/themes/acme/package.json` and `src/` are missing → the root `build/start/lint:js/lint:css/format` scripts are broken until the theme package is restored (copy the shape from `~/pacifica/wp-content/themes/pacifica/package.json`).
  - The theme has no `functions.php`, so all six `inc/*.php` modules are dead code as shipped (pacifica's `functions.php` is the file that requires them).
  - `phpcs.xml.dist` and `phpstan.neon.dist` are missing at the repo root → all four composer scripts fail (copy from pacifica root).
  - `theme.json` still contains Pacífica's exact palette/slugs and Bodoni Moda `fontFace` entries while `assets/fonts/` is empty — replace wholesale with Haramara tokens, don't patch.
  - `docs/setup.md` is referenced by `postinstall` and `.wp-env.json` but doesn't exist.
- `~/setup-vps-deploy.sh` — the deployment script. Explain each command before executing anything server-side.

## Monorepo layout (derived from the lineage)

npm workspaces: `wp-content/themes/haramara`, `apps/*`, `packages/*`.

- `wp-content/plugins/haramara-core` — ALL business logic. PSR-4 `Haramara\Core\` → `src/`, boot-ordered service container in `src/Plugin.php` over a `Contracts/Bootable` interface, with domains: `Setup/`, `Woo/`, `Ordering/`, `Sms/`, `Push/`, `Rest/` (namespace `haramara/v1`), `Seo/`, `Admin/`, `Cli/`. Brand seed data in `data/{products,pages,navigation}.php`, installed via `wp haramara install`.
- `wp-content/themes/haramara` — presentation only. FSE block theme, `theme.json` v3, `templates/*.html`, `parts/*.html`, `patterns/*.php`, style variations in `styles/`. No custom blocks exist in this lineage — everything is core blocks + patterns + `block-styles.php` + `block-bindings.php`; add a `block.json` custom block only when a pattern genuinely can't do it.
- `apps/customer`, `apps/pos` — Expo (managed/CNG, no `ios`/`android` dirs), expo-router file-based routes under `src/app/(tabs)/`, TanStack Query for server state, hand-rolled React Context for client state (cart in AsyncStorage; POS auth in expo-secure-store). EAS config per app (`eas init` per project).
- `packages/api-client` — the only shared package. Unbuilt TypeScript (`main` points at `src/index.ts`); exports `PosApi`, `AppApi`, `StoreApi` and the shared types.

Third-party plugins are Composer/wpackagist-managed and version-pinned exactly (WooCommerce, Stripe gateway, restricted-site-access). PHP >= 8.3. No unnecessary plugins — favor native WordPress.

## Commands (once scaffolded; command surface inherited from the lineage)

```
npm run env:start / env:stop / env:clean      # wp-env local WordPress (PHP 8.3)
npm run env:cli -- <wp args>                  # WP-CLI inside wp-env
npm run env:install-content                   # wp haramara install (seed brand data)
npm run build / start                         # theme wp-scripts build/watch (src/js + src/scss → assets/build)
npm run lint / lint:js / lint:css / format    # theme JS/SCSS lint+format
composer run check                            # phpcs + phpstan — THE PHP quality gate (CI)
composer run lint:fix                         # phpcbf autofix
npm run typecheck:apps                        # api-client + both apps tsc --noEmit — THE TS gate
npm run app:customer / app:pos                # expo start per app
```

**There are no test suites anywhere in this lineage** (no jest, no phpunit). The quality gates are `composer run check` and `npm run typecheck:apps`; run both before considering PHP/TS work done. API verification is manual (pacifica's `bin/verify-api.sh` pattern).

## Bilingual layer (ES canonical · EN at /en/)

`haramara-core/src/I18n/SiteLanguage.php` implements bilingual natively — no multilingual plugin (Polylang free is weakest on FSE templates/parts/patterns, where this site lives). **Known, deliberate divergence from the family pattern:** the sibling repo `~/panymiel` uses real `/en/` mirror pages (`_panymiel_lang` + `_panymiel_translation_of`, chrome-only render filter); Haramara instead serves a *virtual* `/en/` mirror (request-prefix strip + locale flip + one full-page dictionary). The owner chose to keep the virtual mirror (Aug 2026) for zero content duplication and full EN commerce coverage — do not migrate it to the panymiel model without an explicit request. Consequences to respect: EN URLs are not in the sitemap, `redirect_canonical` is disabled on EN requests, and EN copy lives in the dictionary, not Gutenberg. How it works: requests under `/en/` are detected at plugin boot, the prefix is stripped before WordPress routes, the locale is forced to `en_US` (flips all WooCommerce/core strings via language packs), the rendered page passes through one `strtr()` dictionary, and `home_url` output is re-prefixed. hreflang alternates are emitted on both languages; the ES·EN switcher is `patterns/lang-switcher.php`.

Rules when editing content:
- **Every user-facing ES string added to a pattern, template, or seed must get its EN pair in `wp-content/plugins/haramara-core/data/translations.php`.** Keys must match rendered HTML exactly (accents, punctuation, entities — `<title>` uses `&#8211;`, hence the `<title>…` keys). strtr prefers the longest key, so whole sentences must be their own keys even when they contain shorter keys; wrap ambiguous single words as `>Palabra<`.
- Hardcoded relative hrefs (e.g. `href="/carta"`) need an `/en/` rewrite entry in the same file; WP-generated permalinks are prefixed automatically.
- Site locale is `es_MX` (packs installed for core + WooCommerce); the installer normalizes Woo page titles/slugs to ES and ensures pretty permalinks (`Installer::localize_woocommerce_pages()` / `ensure_permalinks()`) — pretty permalinks are load-bearing for `/en/`.

## Architectural facts that span files

- **Design tokens have four hand-synced copies**: `theme.json` (web source of truth) → `assets/css/theme.css` → `apps/customer/src/lib/theme.ts` → `apps/pos/src/lib/theme.ts`. Changing a color/status means touching all of them; there is no codegen.
- **API types are hand-mirrored**: `packages/api-client/src/types.ts` mirrors the PHP serializers in `haramara-core` by convention, not codegen. A change to a PHP response shape requires a matching manual edit there.
- One WooCommerce order record backs all surfaces (web storefront, customer app, POS queue); order lifecycle/status semantics live in `haramara-core/src/Ordering/` and are consumed by the POS board.
- **Loyalty (Lealtad Haramara)**: `haramara-core/src/Loyalty/Members.php` — anonymous device-registered members in a hidden CPT, HMAC-signed card tokens, routes `POST app/loyalty/register`, `GET app/loyalty/card`, `POST pos/loyalty/{stamp,redeem}` (staff cap). The customer app's Lealtad tab (`apps/customer/src/app/(tabs)/lealtad.tsx` + `lib/loyalty.tsx`) renders the QR; the POS's Lealtad tab (`apps/pos/src/app/(tabs)/lealtad.tsx`) scans it via expo-camera (QR barcode scanning, brass reticle) with a manual token-entry fallback that doubles as the web/test path, then stamps/redeems through `PosApi.loyaltyStamp/loyaltyRedeem`. Token shape is validated client-side (`key.hmac`, 64-hex signature) before any API call. Reward mechanics are an undecided product fact: the API counts stamps/redemptions and must never promise a reward tier. Web (Woo-account) loyalty and identity merge are a documented follow-up.
- **App web preview**: `cd apps/customer && EXPO_PUBLIC_API_URL=http://localhost:8892 npx expo start --web --port 8083` (8083 is Haramara's slot; 8081/8082 belong to pacifica). The port must be in `Rest/AccessGate::DEV_ORIGINS` or every `wc/store` call fails CORS while `haramara/v1` works — the classic symptom is a menu that never loads. `api-client` fetches with `cache: 'no-store'` because the Store API sends no Cache-Control and heuristic caching serves stale stock.
- App theme files (`apps/*/src/lib/theme.ts`) now carry the dark Haramara world (carbon/bone/brass, square corners, Italiana/Petrona in the customer app); token names are `bg/surface/text/textSoft/accent…` — the pacifica-era `porcelain/paper/ink` names are gone.
- Push notifications: Expo push tokens stored server-side (`Push/` domain); customer-facing notifications go over WhatsApp/Twilio (`Sms/` domain).

## Brand assets

The 8 files at the repo root (7 café photos/video + `PHOTO-2026-08-02-18-45-34.jpg`, the gold-flame logo) are the real brand assets, inventoried in `PRODUCT.md`. Move/rename them into an organized `assets/` structure during scaffolding; never delete them, and never substitute stock photography — the low-light cinematic look is the brand.
