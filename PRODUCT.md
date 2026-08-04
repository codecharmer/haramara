# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

> First surface is the WordPress site (docs → website → app sequencing confirmed by the owner). An Expo customer app for iOS/Android follows on the same product truth; when app work begins, load the impeccable ios/android references.

## Users

Café guests of Haramara in Cuernavaca, Morelos, MX — locals and CDMX weekenders who find the place through word of mouth and Instagram rather than advertising. The audience is bilingual: Spanish is the primary language, and English ships at launch with full parity (confirmed by owner).

Their jobs: see the menu and decide to come; order ahead for pickup and pay (Stripe / Apple Pay / Google Pay); earn and redeem QR-based loyalty rewards; find the café and contact it over WhatsApp; and sense from the digital experience whether this is their kind of place before they ever visit.

Secondary users: café staff, who will operate a POS app in a later phase (mirroring the Pacífica staff-app pattern).

## Product Purpose

The digital presence of a hidden destination that happens to serve exceptional coffee — an editorial site plus working ordering and loyalty that feel like hospitality, not like a transaction funnel. Success: a visitor who has never been to Cuernavaca understands the place's atmosphere in one viewport, and a regular can order and collect rewards without friction.

## Positioning

Specialty coffee and sourdough / laminated pastry made through slow manual processes — controlled fermentation, fire, ritual. Haramara is part of a family of gastronomic projects (Cocina Suiza, Pacífica, Malva) with 30+ years of combined experience in Cuernavaca; it is the member of that family built around the coffee-and-fire identity. *(Inferred from VOM Noticias press coverage: opened around June 20, 2025 per the Instagram opening announcement.)*

## Operating Context

- Physical café at **Tulipán 302 esq. Hule, Col. Delicias, 62330 Cuernavaca, Mor.** (locals also say "Hule 302, Jardines las Delicias"). Coordinates ≈ 18.94606, -99.20531. Phone **777 136 2228**. Confirmed via Google Maps listing, Aug 2026.
- Hours: **Wednesday–Monday 8:00–20:00, closed Tuesdays** ("Descansamos Martes" — their own Instagram bio wording, keep it).
- Google Maps: 5.0★ (29 reviews), $100–200 MXN per person. Guests describe it as a hidden specialty-coffee bar "adentro de una cueva" (inside a cave) — the space itself is part of the legend.
- Order-ahead-for-pickup commerce model. *(Assumption inherited from the sibling Pacífica implementation, which is reserve-&-pickup and never delivery — confirm before building any delivery capability.)*
- WhatsApp is the customer contact and notification channel (Twilio-backed in the Pacífica lineage).
- Currency: MXN. Locale: es_MX primary, en_US secondary.
- Discovery is Instagram-driven (@haramara.cafe, 2.8K+ followers). Monthly promotions are part of how they operate.

## Capabilities and Constraints

**Live at launch (owner-confirmed):**
- Editorial site: menu, story, photography, the space, contact/WhatsApp, visit info.
- Mobile ordering with real payments: WooCommerce + Stripe, Apple Pay, Google Pay.
- Loyalty: QR-based rewards, really accruing/redeeming at launch. Mechanics not yet designed — an open product decision, not a fact.

**Explicitly out of scope (owner decision, not deferred):**
- Reservations. Haramara is not doing reservations. Do not build or design a reservations feature; ignore the original brief's mentions of it.

**Decided (Aug 2026): app loyalty identity.** The app's loyalty card is an anonymous, device-registered member (no account, no login — the card "lives in the device"); the QR encodes an HMAC-signed member token that staff stamp/redeem at the bar. Reward mechanics remain undecided — no surface may promise a reward tier until the café defines one. Linking app members with web (Woo-account) loyalty is a recorded follow-up.

**Decided (Aug 2026): bilingual mechanism.** Native language layer in haramara-core (`I18n\SiteLanguage`): Spanish is canonical at `/`, English is served from the same content under `/en/` (locale switched to en_US for WooCommerce/core strings, editorial copy translated through the single dictionary at `wp-content/plugins/haramara-core/data/translations.php`, hreflang alternates on both sides, ES·EN switcher in the header). Polylang was deliberately rejected: its free tier is weakest exactly where this site lives (FSE templates, parts, patterns), and the project standard is native capability over plugins. Consequence: EN copy is maintained in the dictionary file, not in Gutenberg — every new ES editorial string needs its EN pair added there.

**Undecided (record, don't invent):**
- Gift cards — mentioned in the brief, not committed for launch.
- Mercado Pago as an additional gateway (present in the Pacífica lineage; not yet confirmed for Haramara).
- Delivery — assumed absent per lineage; unconfirmed.

**Technical constraints (inherited from the ~/acme-starter / ~/pacifica lineage this project derives from):**
- WordPress latest, FSE block theme (theme.json v3), PHP 8.3+, business logic in a PSR-4 core plugin, Composer/wpackagist-pinned third-party plugins, wp-env for local dev, @wordpress/scripts build.
- Mobile: Expo (managed/CNG), expo-router, TanStack Query, TypeScript.
- No test harness exists in this lineage; quality gates are phpcs + phpstan (`composer run check`) and TypeScript (`typecheck:apps`).
- Deployment via `~/setup-vps-deploy.sh`.

## Brand Commitments

- Name: **Haramara**. Instagram: @haramara.cafe.
- Logo asset on disk: hand-drawn gold flame/plant mark inside a gold ring on black (`PHOTO-2026-08-02-18-45-34.jpg`). Black + gold/brass is the confirmed identity core.
- The owner's brief pins a binding visual direction (recorded verbatim as constraints; the visual world itself is designed later in new-work): matte black, charred wood, burnt walnut, deep espresso, warm limestone, natural clay, soft olive accents, warm off-white typography; materials of Shou Sugi Ban wood, stone, concrete, handmade ceramics, linen, brass, smoke; golden-hour / candlelight / heavy-shadow lighting; cinematic photography; **never bright saturated colors**; elegant editorial typography; minimal layout with generous whitespace; subtle slow motion only.
- Feel words: quiet luxury, hidden destination, intimate, architectural, sophisticated, earthy, slow, intentional. Anti-references: trendy coffee shop, startup.
- Quality bar: the restraint, materiality, and craftsmanship of Aesop, Blue Bottle, Aman, Noma, Apple — matched in discipline, not copied in aesthetics.
- Voice: confident without ornamentation; one voice across both languages.

## Evidence on Hand

Real assets in this repo (all shot at the café; cinematic, low-light — usable as launch photography):

- `548a4f09-….JPG` — barista pouring latte art into a logo cup; concrete counter, heavy shadow.
- `5530de75-….JPG` — Orí Kombucha bottle held against celosía red-brick lattice, golden hour.
- `5c599409-….JPG` — interior: linen banquette, terracotta pillows, black-marble bistro tables, black chairs, stone wall; pretzel plate with ham and butter on a stainless tray.
- `92aa3500-….MP4` — video clip from the café (unreviewed).
- `a67d0710-….JPG` — chia/granola fruit bowl on charred black stone table.
- `c8812334-….JPG` — grilled croissant sandwich (avocado) on stainless plate, black marble.
- `e069abd0-….JPG` — crème-brûlée-topped laminated pastry on stainless plate.
- `PHOTO-2026-08-02-18-45-34.jpg` — the Haramara logo (gold flame mark on black).

Press: VOM Noticias — "Café de especialidad y pan de masa madre, hechos con procesos manuales" ("specialty coffee and sourdough bread, made with manual processes"); part of the Cocina Suiza / Pacífica / Malva family, 30+ years combined experience.

**Real menu** (transcribed from guest photos of the physical menu, Aug 2026; photos preserved at `assets/reference/menu/menu{1,2,3}.jpg`):

- *Nuestros cafés:* Espresso $60 · Cold brew $70 · Filtrados in three profiles: **Chill $70, Groove $85, Funky $120** (the brew ladder's names are brand voice — keep them).
- *Salados:* Pudding chía $160 · Croissant ventresca de atún $230 · Croissant de burrata y jamón serrano $280 · Bagel de trucha ahumada y alcaparra crocante $220 · Pretzel con encurtidos y selva negra $250 · Naan cuatro quesos con piñones y hot honey trufada $240.
- *Especiales:* Galletas de macadamia con toffee $60 · Pie matcha $85 · Crème brûlée $85 · Guayabito $65 · Laminado de queso gouda & jamón de pavo $55.
- Also served (seen in photos/reviews): Orí kombucha, latte/espresso-milk drinks, Chemex, siphon bar.

**Absences future work must not fabricate:** no testimonials on hand, no staff bios, no event calendar content, no seasonal-menu history. Menu above is a point-in-time snapshot — specials rotate monthly, so the system must treat menu content as editorial, not hardcoded.

## Product Principles

1. **Discovered, not advertised.** The experience earns attention through restraint; nothing shouts, nothing pop-ups, nothing pushes.
2. **The craft is the proof.** Fermentation, fire, and manual process are the story — show the making, don't claim the quality.
3. **Transactions are hospitality.** Ordering and loyalty must feel like being welcomed back, not like operating a vending machine.
4. **Two languages, one voice.** ES and EN ship together with equal care; neither reads as a translation.
5. **Nothing fabricated.** Real menu, real photos, real facts — gaps stay gaps until the client fills them.

## Accessibility & Inclusion

WCAG AA is a hard requirement (owner's brief). The committed low-light, high-contrast visual direction must still meet AA contrast for all text and interactive elements. Bilingual ES/EN parity is an inclusion requirement, not a marketing add-on.
