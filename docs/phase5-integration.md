# Phase 5 — Impresión de ticket y comanda (ESC/POS)

The printing module lives at `apps/pos/src/lib/printing/` and is deliberately
inert: pure renderers (`ticket.ts`, `comanda.ts`) over a dependency-free byte
builder (`escpos.ts`), a guarded TCP transport (`transport.ts`), and a
hardware-free decoder (`preview.ts`) + self-test (`verify.ts`). Nothing
imports it yet and no dependency was added — this document is the map for the
engineer who wires it in.

## 1. Dependency + development build

Printing needs raw TCP to port 9100, which Expo Go and web do not have. When
the dev-build phase starts:

```sh
cd apps/pos
npx expo install react-native-tcp-socket
```

Then **delete `src/lib/printing/tcp-socket.d.ts`** — it exists only so
`npx tsc --noEmit -p apps/pos` passes without the package; the real package
ships its own declarations and the two would collide.

Build implications (this repo is managed/CNG — no `ios`/`android` dirs, and it
should stay that way):

- `react-native-tcp-socket` is a config-plugin-free native module; CNG handles
  it, so **do not commit `npx expo prebuild` output**. EAS runs prebuild in the
  cloud: `eas build --profile development --platform android` (and/or `ios`)
  produces the dev client that replaces Expo Go on the counter tablet.
- Local iteration if ever needed: `npx expo run:android` (generates native dirs
  transiently — don't commit them).
- `transport.ts` lazy-requires the module inside a try/catch: in Expo Go / web
  it throws a typed `PrinterUnavailableError` with a Spanish message instead of
  crashing the bundle. Note Metro resolves static `require` calls at bundle
  time, so the first screen that *imports* the printing module makes the
  package a bundle-time requirement — land the `expo install` in the same PR
  as the first import.

## 2. Printer settings UI (to build)

A small settings surface (natural home: the operator screen `src/app/operador.tsx`
or a new `ajustes` route), persisting a `PrinterConfig` in AsyncStorage —
mirror the cart-storage pattern, not expo-secure-store (a printer IP is not a
secret):

- Key: `haramara_pos_printer_v1`; shape `{ host: string; port?: number; paperWidth: 58 | 80 }`
  (`PrinterConfig` in `printing/types.ts`; port defaults to 9100).
- Fields: host (IP), paper width toggle 58/80. `charsPerLine()` derives the
  columns — never store columns separately.
- A "Página de prueba" button that prints `renderTicket` over a sample payload
  (or simply runs `selfTest()` from `printing/verify.ts` and shows the lines).
- Business header data for `PrintOptions` (name/address/phone) should come from
  the server's business config (the same source the customer app's `AppConfig.business`
  uses) rather than being retyped on the tablet.

## 3. Where the print calls hook in

Both hooks build a `TicketPayload` (printing/types.ts) — **not** a `PosOrder`.
Mapping notes: `operator` comes from the operator context (`src/lib/operator.tsx`),
`paymentLabel` from the payment choice ("Efectivo" / "Tarjeta (terminal externa)"),
`note` from the order note. Today's `OrderItem` has no modifiers — `TicketLine.modifiers`
stays empty until the server serializes item meta. `folio` and `facturaUrl`
stay `undefined` until the server phase (section 4); the renderers already
handle their absence.

- **Ticket + comanda after a walk-in sale** — `src/app/(tabs)/mostrador.tsx`,
  the `sale` mutation's `onSuccess` (right after `notify('Venta registrada', …)`):
  `renderTicket(payload, opts)` then `renderComanda(payload, opts)`, each through
  `sendToPrinter`. Printing must be fire-and-forget: the sale is already rung, so
  a printer failure gets a `notify` toast (offer "Reimprimir"), never a rollback
  or a blocked ticket-clear. Catch `PrinterUnavailableError` separately (dev
  build missing) vs. connection errors (printer off / wrong IP).
- **Comanda on accepting an online order** — the accept mutation lives in
  `src/lib/queue.ts` (`useAcceptOrder`, shared by `pedidos.tsx` and the order
  modal). Hook `renderComanda` into its `onSuccess` (or the `onAccepted`
  callback) so the kitchen slip prints the moment the order enters the flow.
- **Cash drawer** — `EscPos.drawerKick()` is already wired (ESC p 0 25 250).
  When a drawer arrives, prepend the kick to cash-payment ticket jobs only.

## 4. Server work still owed (haramara-core)

- **Folio + factura token**: issue a consecutive `folio` per POS sale and an
  HMAC-signed `facturaUrl` (same signing pattern as the loyalty card tokens),
  returned in the walk-in/order response so the first print already carries the QR.
- **Stored ticket payload**: persist the exact `TicketPayload` JSON the ticket
  was rendered from (order meta is the natural home). A reprint re-renders from
  the stored payload — byte-identical output, immune to later price/menu edits.
  Remember the API types are hand-mirrored: the PHP serializer change needs a
  matching edit in `packages/api-client/src/types.ts`.
- **Reprint audit**: every reprint goes through a `POST pos/tickets/{id}/reprint`
  style route that logs an audit event (operator, timestamp, folio, count) —
  reprints are a classic money-leak vector, which is exactly the transparency
  angle this platform exists for.

## 5. How selfTest() was verified

`selfTest()` was **actually executed** (2026-08-24), not just typechecked. No
runner exists in the repo, so the six pure modules (everything except
`transport.ts`) were copied to a scratch directory, compiled with the repo's
own `tsc` (`npx tsc src/*.ts --outDir js --module commonjs --target es2020
--strict --skipLibCheck`, clean exit 0), and run under node 20:

```
PASS: CP850 de "Ñandú café ¿100%?" — a5 61 6e 64 a3 20 63 61 66 82 20 a8 31 30 30 25 3f
PASS: QR pL/pH para payload de 300 chars (303 = 0x012F)
PASS: row() a 32 columnas — "Concha de vainilla edi $1,234.50"
PASS: row() a 48 columnas — "Concha de vainilla edición de la casa  $1,234.50"
PASS: renderTicket no vacío (58/80) — 730/922 bytes
PASS: ticket trae TOTAL, QR y "Factura tu ticket"
PASS: renderComanda no vacío — 416 bytes
PASS: comanda sin precios (ni un solo "$")
PASS: comanda trae items, modificadores y nota
```

The decoded 58 mm ticket preview (`renderPreviewText`) from that same run, for
template review without hardware:

```
Haramara Café
Cuernavaca, Morelos
Tel. 777 000 0000
--------------------------------
Folio: HAR-000123          #1042
24/08/2026 09:30
--------------------------------
2 Latte de avellana      $130.00
  + Leche de avena
  + Sin azúcar
1 Chilaquiles verdes con $145.00
  + Extra queso
--------------------------------
Subtotal                 $275.00
Cortesía                 -$20.00
TOTAL                    $255.00
Pago                    Efectivo
Propina                   $25.00
--------------------------------
Le atendió: Baltazar

[QR] https://haramaracafe.mx/factura/HAR-000123?t=0f3a9c
Factura tu ticket

¡Gracias por tu visita!
[------ CORTE ------]
```

`npx tsc --noEmit -p apps/pos` passes with the module in the tree (it is
included by the app's `**/*.ts` glob even while unimported).
