# Fiscal — autofactura CFDI 4.0 (ops runbook)

How the /factura flow is configured and operated. Architecture and code seams
live in `docs/phase6-integration.md`; this file is for whoever sets up or
babysits the PAC account.

## What this module does

A customer buys at the counter; the printed ticket carries a folio and a QR to
the public `/factura` page (`https://<site>/factura?f=<folio>`). There they
enter the folio + total (possession proof), then their fiscal data, and the
PAC — **Facturama** — stamps a CFDI 4.0. The PDF + XML are emailed to the
address they typed and offered as tokened downloads. The CSD certificates live
**at the PAC, never in this repo or on this server**.

## Current status: partially stubbed

| Piece | Status |
|---|---|
| PAC client (Facturama, sandbox + production) | live |
| Invoice records table + protected document store | live (table creation wired by the Phase 2/Activator seam) |
| REST surface `/factura/{validate,issue,download}` | live |
| Folio → order resolution | **stubbed** — `PendingFolioResolver` answers 503 until Phase 2 lands `_pos_folio` |

While the stub is in place the whole flow answers `503` and the /factura page
shows its "aún no disponible" state. Nothing needs to be unconfigured to keep
the feature hidden — that is the gating model (same as the Wallet pass).

## Facturama credentials

1. Create an account at facturama.mx (production) — for testing, the sandbox
   at `apisandbox.facturama.mx` has its own, separate registration; sandbox
   credentials do NOT work in production nor vice versa.
2. The API uses HTTP Basic auth with the account's user + password. There is
   no separate API key.
3. In the Facturama dashboard complete the fiscal profile of the café (RFC,
   razón social, régimen) — the **emisor** data comes from the PAC account,
   not from this codebase.

## Uploading the CSD to the PAC

The Certificado de Sello Digital (`.cer` + `.key` + password, issued by SAT)
is uploaded **in the Facturama dashboard** (Configuración → Certificados). In
the sandbox you can use SAT's publicly documented test CSDs. The CSD never
touches this server: deploys rsync this tree and its file permissions are
world-readable — secrets must not live here (same rule as the Wallet pass
certificates).

## wp-config constants

Configuration is read exclusively from wp-config constants — never from
options, never from the database:

```php
define( 'HARAMARA_FACTURAMA_USER', '…' );       // Facturama account user.
define( 'HARAMARA_FACTURAMA_PASS', '…' );       // Facturama account password.
define( 'HARAMARA_FACTURAMA_SANDBOX', true );   // true → apisandbox.facturama.mx. Use a real boolean.
define( 'HARAMARA_FISCAL_CP', '62330' );        // ExpeditionPlace: CP of the café (Tulipán 302, Delicias).
```

With any of USER / PASS / `HARAMARA_FISCAL_CP` missing, every fiscal endpoint
answers `503` and the page hides the feature. Remove (or set `false`) the
SANDBOX constant to go to production. Credentials are never logged.

## What gets stamped

- Serie `HARA` (filter `haramara_fiscal_serie`), Folio = the POS ticket folio.
- `CfdiType` I (ingreso), `PaymentMethod` PUE, Currency MXN, Exportación 01.
- PaymentForm from how the sale was actually paid: 01 efectivo, 04 tarjeta
  (filter `haramara_fiscal_payment_form`).
- Items from the order's line items: ProductCode `01010101` ("no existe en el
  catálogo") by default — per-line override via the
  `haramara_fiscal_product_code` filter — UnitCode `ACT`.
- WooCommerce prices include IVA; each line is desglosado at 16%
  (Base = total / 1.16), rounding remainder absorbed in the largest line so
  the CFDI total equals the amount charged, centavo-exact.

## Catalog values offered by the /factura page

The API accepts the full SAT lists; the page's selects offer the values a café
customer actually needs.

Uso de CFDI (`uso_cfdi`):

| Clave | Descripción (ES, as shown) |
|---|---|
| G03 | Gastos en general |
| G01 | Adquisición de mercancías |
| S01 | Sin efectos fiscales |
| D01 | Honorarios médicos, dentales y gastos hospitalarios |
| CP01 | Pagos |

Régimen fiscal (`regimen_fiscal`):

| Clave | Descripción (ES, as shown) |
|---|---|
| 601 | General de Ley Personas Morales |
| 603 | Personas Morales con Fines no Lucrativos |
| 605 | Sueldos y Salarios e Ingresos Asimilados a Salarios |
| 606 | Arrendamiento |
| 612 | Personas Físicas con Actividades Empresariales y Profesionales |
| 616 | Sin obligaciones fiscales |
| 621 | Incorporación Fiscal |
| 626 | Régimen Simplificado de Confianza (RESICO) |

(The REST layer whitelists the complete SAT catalogs, so adding an option to
the page needs no PHP change.)

## Stored documents

PDF + XML land in `wp-content/uploads/haramara-facturas/` with random
filenames, an `.htaccess` deny, and an empty `index.html`. They are served
only through `GET /haramara/v1/factura/download?token=…` (HMAC token, 7-day
expiry; staff with the `manage_haramara` capability can also fetch by invoice
id). **nginx installs**: `.htaccess` is ignored — add the equivalent deny:

```nginx
location ~* /wp-content/uploads/haramara-facturas/ { deny all; }
```

Invoice rows live in `{prefix}haramara_invoices` (one per order, UNIQUE on
`order_id`). The customer email is stored there **only** — by design it is
never written to the WooCommerce order, so order notifications cannot fire
for walk-ins.

## Sandbox smoke test (once Phase 2 lands)

1. Set the constants with sandbox credentials + `HARAMARA_FACTURAMA_SANDBOX`.
2. Ring a walk-in sale in the POS, note folio + total from the ticket.
3. `POST /wp-json/haramara/v1/factura/validate` with `{folio, total}` → 200
   with the order summary (404 if either is wrong, 409 if already invoiced).
4. `POST /wp-json/haramara/v1/factura/issue` with the fiscal fields — the SAT
   sandbox test RFCs work here (e.g. `EKU9003173C9`) with the matching name
   and régimen from SAT's test pack.
5. Confirm the email arrives with both attachments and that both download
   tokens stream the files.
6. Rate limit: the 11th validate/issue from one IP inside an hour answers 429.

## Behavior notes (not legal advice)

This module records what was charged and passes it to the PAC as-is; the PAC
performs the SAT-side validation and stamping. Cancellations
(`PacClient::cancel()`, motive 02 by default) are wired but have no UI — run
them deliberately, and mirror any cancellation in the Facturama dashboard
records.
