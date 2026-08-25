# Phase 7 — Venta offline (outbox + sincronización)

The offline module lives at `apps/pos/src/lib/offline/` and is deliberately
inert: nothing imports it yet and no dependency was added. It is a persisted
outbox (`types.ts`, `store.ts`), a serial drain engine (`sync.ts`), online
detection (`connectivity.ts`), a React surface (`outbox.tsx` —
`OutboxProvider` + `useOutbox()`), three DELETE-ME ambient shims
(`sqlite.d.ts`, `netinfo.d.ts`, `async-storage.d.ts`), and a hardware-free
self-test (`verify.ts`). This document is the map for the engineer who wires
it in.

## 0. Scope guard — what works offline and what never will

Offline covers exactly two operations, and the `QueuedOperation` union in
`offline/types.ts` is written so a third cannot be smuggled in:

| Offline (queued + synced) | Online only — by design |
| --- | --- |
| Ventas de mostrador (with tips, discounts, modifiers) | Voids and refunds |
| Salidas internas (withdrawals) | Shift open/close (turno) |
| | Cash drops (retiros a caja fuerte) |
| | Loyalty stamp/redeem |
| | Cuentas abiertas (tabs) |

The right column is the anti-fraud surface: voids/refunds/turnos are the
controls that make the corte trustworthy, and **a control that works offline
is not a control** — an offline void is indistinguishable from a pocketed
sale. If the register has no connectivity, those actions wait; the UI should
say so plainly ("Requiere conexión") rather than queueing them. This is a
product decision made with the owner; adding a kind to the union is a product
conversation, not a refactor.

## 1. Dependencies + install steps (wiring PR)

No package was added in this phase — `expo-sqlite`,
`@react-native-community/netinfo`, and `@react-native-async-storage/async-storage`
all got the printing-lib treatment (lazy `require` + ambient shim + graceful
fallback). Note AsyncStorage is **not** in `apps/pos/package.json` today
(only the customer app has it), which is why it is shimmed too.

When wiring:

```sh
cd apps/pos
npx expo install expo-sqlite @react-native-community/netinfo @react-native-async-storage/async-storage
```

Then **delete the three shims** — `src/lib/offline/sqlite.d.ts`,
`netinfo.d.ts`, `async-storage.d.ts` — the real packages ship their own
declarations and the copies would collide.

Metro caveat (same one phase 5 documented): Metro resolves `require` calls at
bundle time even inside try/catch, so the first screen that *imports* the
offline module makes all three packages a bundle-time requirement. **Land the
`expo install` in the same PR as the first import.** The lazy requires are
not for "package absent from node_modules" at runtime — they are for
`npx tsc` passing today, for Expo Go / web where a native side may be
missing, and for the node self-test.

Build implications: all three are config-plugin-free under CNG; Expo Go
supports every one of them, so unlike printing this phase does **not** force
the dev build — but the dev build is where it ships (same
`eas build --profile development` flow as phase 5).

Storage fallback ladder (`store.ts`, decided at runtime, exposed as
`useOutbox().backend`): sqlite (WAL, table `haramara_pos_outbox_v1`) →
AsyncStorage (key `haramara_pos_outbox_v1`) → `localStorage` (web preview) →
memory. If `backend === 'memory'`, a force-quit loses the queue — worth a
quiet warning row in the corte, and it should never happen once the packages
are installed.

## 2. Provider wiring

`OutboxProvider` binds the drain engine to the signed-in `PosApi`, so it goes
**inside** `AuthProvider` in the root layout (`src/app/_layout.tsx`), wrapping
the tab navigator alongside the operator/adjust providers. It self-manages:
it idles while signed out, builds the store + engine when a session appears,
and wires all four drain triggers (connectivity regained via
`connectivity.ts`, app foreground via `AppState`, a 30 s coarse interval, and
manual `drainNow()`).

Everything a screen needs comes from `useOutbox()`: `online`, `snapshot`
(counts + oldest pending age), `failed`, `enqueueWalkIn`, `enqueueWithdrawal`,
`retry`, `discard`, `drainNow`.

## 3. Mostrador — ring the sale into the outbox

In `src/app/(tabs)/mostrador.tsx`, the `sale` mutation currently calls
`api.createWalkIn(...)` with the screen's `saleKey` and rotates the key on
success. Two changes:

- **Proactively offline** (`useOutbox().online === false`): skip the network
  entirely — `enqueueWalkIn(input)` with the same input the mutation would
  have sent (items+modifiers, payment, note, discount, tip, **and the current
  `saleKey` as `idempotency_key`**). Then behave exactly like a successful
  sale: clear the ticket, rotate `saleKey`, and toast
  **"Sin conexión — venta guardada"** (`notify` from `src/lib/dialog.ts`;
  mention it will be cobrada al reconectar). The cashier's flow must not
  change because the Wi-Fi died.
- **Reactively offline** (the direct call throws `ApiError` with
  `status === 0` / `code === 'network_error'`): the server may or may not
  have received the sale — this is precisely the case the idempotency guard
  exists for. Enqueue with the **same `saleKey` the failed call used** (never
  a fresh key — the outbox keeps whatever key the input carries), then clear,
  rotate, toast. The server settles the replayed key exactly once
  (`X-Pos-Idempotent-Replay`).

All other `ApiError`s (validation, auth, out of stock) keep today's behavior
— the server answered, so the existing error toast is correct.

**Stock policy (product note, surface it in the UI):** offline sales skip the
client-side stock ceiling — the tablet's product cache is stale by
definition, and refusing a real sale over a stale number is worse than
reconciling later. Stock reconciles when the queue drains; if the server then
rejects a line (`haramara_out_of_stock` / `haramara_insufficient_stock`) the
operation lands in `failed` — money that did NOT happen — and must be
surfaced in the corte's review list (section 6), never silently dropped.

Salida rápida (the quick-withdrawal sheet in mostrador, if wired this round)
follows the inventario path below.

## 4. Inventario — salidas internas

Same pattern for the withdrawal mutation in `src/app/(tabs)/inventario.tsx`
(`api.createWithdrawal` with `salidaKey`): offline or `network_error` →
`enqueueWithdrawal(input)` carrying the current `salidaKey`, rotate the key,
toast "Sin conexión — salida guardada". Withdrawals queue behind any pending
sales (single FIFO — order matters because both mutate stock).

Stock recounts (`setStock`) are **not** queued: an absolute quantity computed
against a stale on-hand number is wrong by construction once queued
operations land. Recuentos require connectivity.

## 5. "Pendientes" badge

`useOutbox().snapshot` gives `{ queued, syncing, failed, pending,
oldestPendingAgeMs }`. Suggested placement (either or both):

- **Corte** (`src/app/(tabs)/corte.tsx`): a "Pendientes de sincronizar" row —
  pending count + oldest age ("3 ventas · la más antigua hace 12 min") with a
  "Sincronizar" button calling `drainNow()`. The corte is also where a
  pending queue matters most: **closing a turno with pending > 0 should warn**
  — those sales are not in `expected_cash` yet (shift close itself stays
  online-only, so the server is reachable at that moment; drain first).
- **Header/tab chrome**: a small brass badge with `pending` on the mostrador
  header plus an offline pill ("Sin conexión") bound to `online` — the
  cashier should always know the register is queueing.

## 6. Failed operations — the review UI

`useOutbox().failed` is the list of operations the server rejected
permanently (validation/authorization — see `classifyError` in
`offline/types.ts`; transient failures never land here, they retry with
backoff forever). Each carries `last_error` (the server's own Spanish
message), `created_at`, `attempts`, and the full `input` for rendering what
was sold. Natural home: a section in the corte, red-accented — these are
sales/salidas that did NOT happen.

Per row, two actions:

- **Reintentar** — `retry(id)`: re-queues it (original key, original FIFO
  position). For out-of-stock rejections this only helps after a recount.
- **Descartar** — `discard(id)`: **must go through `confirmDialog`**
  (`src/lib/dialog.ts`, `destructive: true`) — e.g. "¿Descartar venta
  pendiente? Se perderá definitivamente y no se cobrará." A discard is the
  only place in the whole flow where a rung sale can vanish; the library
  deliberately does not confirm for you, the call site must.

Never auto-expire failed rows.

## 7. Behavior reference (what the engine guarantees)

- **Serial FIFO** — one operation in flight, strict enqueue order; a `failed`
  row is skipped, it does not block the queue.
- **Immutable idempotency key** — minted at enqueue (or inherited from the
  input), stamped into the stored input, re-stamped on every send. This is
  the entire replay-safety story; nothing after enqueue may change it.
- **Backoff** — retryable errors (network `status 0`, 5xx, 429, 401): 1 s →
  2 s → 4 s … cap 60 s, with jitter. 401 is retryable on purpose: a lapsed
  session comes back; the sale is still good.
- **`haramara_request_in_flight`** — holds ≥65 s before retrying that op (the
  server takes over an abandoned claim at 60 s; 65 gives margin).
- **Crash recovery** — a row left in `syncing` (app died mid-send) is retried
  with its original key on the next drain.

## 8. How selfTest() was verified

`selfTest()` (in `apps/pos/src/lib/offline/verify.ts`) was **actually
executed** (2026-08-24), not just typechecked. No runner exists in the repo,
so the four pure modules (`types.ts`, `store.ts`, `sync.ts`, `verify.ts` —
everything except `connectivity.ts` and `outbox.tsx`) were copied to a
scratch directory together with `packages/api-client/src/` (type imports
only — the compiled JS has zero runtime dependencies), compiled with the
repo's own `tsc` (`module commonjs`, `target es2020`, `strict`,
`skipLibCheck`; clean exit 0) and run under node 20:

```
PASS: clasificación de errores (11 casos)
PASS: FIFO preservado (venta,venta,salida,venta) — A,B,C,D
PASS: kinds despachados al executor correcto
PASS: snapshot antes del drain: pending=4, oldest age
PASS: éxito elimina del outbox (cola vacía)
PASS: reintento reutiliza la MISMA idempotency key (2 llamadas) — k-retry,k-retry
PASS: primer backoff ≈ 1s — 1000 ms
PASS: tras el reintento exitoso la cola queda vacía
PASS: error terminal: exactamente 1 intento — 1
PASS: error terminal: marcado failed y CONSERVADO con last_error
PASS: la operación siguiente sí se envió (failed no bloquea)
PASS: sin backoff tras error terminal
PASS: backoff exponencial con tope 60s — 1000,2000,4000,8000,16000,32000,60000,60000
PASS: novena llamada liquida la venta
PASS: las 9 llamadas llevaron la key original
PASS: in_flight espera ≥65s (65000 ms) antes de reintentar — 65000 ms
PASS: in_flight reintenta con la MISMA key y liquida
PASS: sin conexión: 0 llamadas, todo sigue en cola
PASS: al reconectar el drain vacía la cola en orden
PASS: fila huérfana en `syncing` se reintenta y liquida
```

`npx tsc --noEmit -p apps/pos` passes with the module in the tree (it is
included by the app's `**/*.ts`/`**/*.tsx` glob even while unimported).

## 9. Server work still owed (haramara-core)

None required for this phase — the idempotency guard this design leans on is
already live and verified (replay settles once with
`X-Pos-Idempotent-Replay`; claims abandoned >60 s are taken over; concurrent
duplicates 409 with `haramara_request_in_flight`). Nice-to-have later: a
`queued_at` passthrough field on walk-in/withdrawal inputs so late-synced
operations can be reported under the hour they were rung, not the hour they
synced — that is a corte-accuracy question for the owner first, and the API
types are hand-mirrored, so it lands in `packages/api-client/src/types.ts`
together with the PHP serializer.
