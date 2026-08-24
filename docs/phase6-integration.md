# Phase 6 (autofactura CFDI 4.0) — integration seams

What Phase 6 shipped, and the exact hook-up points left for the engineer
driving `Plugin.php` / `Activator.php` / the theme / `translations.php` (all
deliberately untouched by Phase 6 — they were being edited in parallel).

New files (everything Phase 6 owns):

```
wp-content/plugins/haramara-core/src/Fiscal/PacClient.php            interface
wp-content/plugins/haramara-core/src/Fiscal/FacturamaClient.php      Facturama implementation
wp-content/plugins/haramara-core/src/Fiscal/FolioResolver.php        interface (Phase 2 seam)
wp-content/plugins/haramara-core/src/Fiscal/PendingFolioResolver.php 503 stub
wp-content/plugins/haramara-core/src/Fiscal/Invoices.php             table owner + document store
wp-content/plugins/haramara-core/src/Rest/FiscalRoutes.php           Bootable REST surface
wp-content/plugins/haramara-core/src/Fiscal/README.md                ops runbook (credentials, catalogs)
```

## 1. Plugin.php — service registration

One line in the REST group of `service_classes()` (FiscalRoutes is a normal
zero-arg Bootable; its constructor defaults wire the resolver and PAC client):

```php
// REST + CLI.
Rest\Idempotency::class,
Rest\Routes::class,
Rest\AccessGate::class,
Rest\AppRoutes::class,
Rest\PosRoutes::class,
Rest\FiscalRoutes::class, // Autofactura CFDI 4.0 (Phase 6).
Cli\Commands::class,
```

## 2. Activator — invoices table + DB_VERSION

`Fiscal\Invoices` owns the schema but never runs it; `Activator` is the only
table creator. In `create_tables()`, after the idempotency table:

```php
// Issued autofacturas (CFDI 4.0): one row per invoiced order, UNIQUE on
// order_id. Business records — no prune. Used by Fiscal\Invoices.
dbDelta( \Haramara\Core\Fiscal\Invoices::schema( $wpdb->prefix, $charset_collate ) );
```

Then bump the generation:

```php
public const DB_VERSION = 5; // gen 5: adds the invoices table.
```

Note the standing comment in `maybe_upgrade()`: when bumping to gen 5 you must
also add the real generation gate it asks for, i.e. wrap the roster migration
in `if ( $installed < 4 ) { self::migrate_employee_roster(); }`.

(If you keep the table-basename mirror pattern, add
`public const INVOICES_TABLE = 'haramara_invoices';` — but `Invoices::TABLE`
is the source of truth and nothing else needs the mirror.)

## 3. The FolioResolver swap (when Phase 2 lands)

`Rest\FiscalRoutes` codes against `Fiscal\FolioResolver`:

```php
public function resolve( string $folio, float $total ): int|\WP_Error; // → order ID
```

It ships holding `Fiscal\PendingFolioResolver`, which always answers
`haramara_factura_unavailable` (503) — the /factura page shows its
"aún no disponible" state and nothing else is reachable.

Phase 2's resolver must:

- look the order up by its `_pos_folio` meta (scope to `created_via =
  'haramara-pos'` walk-ins, or whatever Phase 2 decides is invoiceable);
- treat the total as the possession proof: compare against
  `round( $order->get_total(), 2 )` and return the **same** generic
  `haramara_factura_not_found` (404) for unknown folio and for total mismatch
  — the public endpoint must not reveal which half failed;
- optionally enforce an invoicing window (e.g. same fiscal month) — product
  call, not Phase 6's.

The swap is the one-line default change in `FiscalRoutes::__construct()`:

```php
$this->resolver = $resolver ?? new PendingFolioResolver();
// becomes, e.g.:
$this->resolver = $resolver ?? new \Haramara\Core\Ordering\PosFolioResolver();
```

Everything else (409 already-invoiced, RFC validation, PAC, storage, email,
tokens) is already live behind it.

## 4. Ticket QR (Phase 2's printer template)

The printed ticket should carry the folio in clear text plus a QR encoding:

```
https://<site>/factura?f=<folio>
```

The page skeleton below prefills the folio input from `?f=`. The EN mirror is
automatic (`/en/factura?f=<folio>`); the QR always points at the ES canonical
URL.

## 5. Theme page — proposed pattern skeleton (NOT created by Phase 6)

Create the WP page `Factura` (slug `factura`) and a theme pattern like the
following. This is a **proposal inline** — the theme is being edited elsewhere,
so Phase 6 created no theme files. Two rules it already respects: every
visitor-facing string is server-rendered in Spanish (so the `/en/` strtr
dictionary can translate it — JS only toggles visibility and fills data), and
API error messages, which arrive via fetch AFTER the strtr pass, are mapped
client-side by error code when the page is under `/en/`.

```php
<?php
/**
 * Title: Factura
 * Slug: haramara/factura
 * Inserter: no
 */
?>
<section class="haramara-factura" data-api="<?php echo esc_url( rest_url( 'haramara/v1' ) ); ?>">

	<h1>Facturación</h1>
	<p>Genera la factura (CFDI) de tu consumo con el folio impreso en tu ticket.</p>

	<form id="hf-validate">
		<label for="hf-folio">Folio del ticket</label>
		<input id="hf-folio" name="folio" required>
		<label for="hf-total">Total del ticket (MXN)</label>
		<input id="hf-total" name="total" type="number" inputmode="decimal" step="0.01" min="0" required>
		<button type="submit">Validar ticket</button>
	</form>

	<div id="hf-summary" hidden>
		<h2>Tu consumo</h2>
		<dl></dl><!-- JS: date, items (name × qty), total -->
	</div>

	<form id="hf-issue" hidden>
		<label for="hf-rfc">RFC</label>
		<input id="hf-rfc" name="rfc" required maxlength="13">
		<label for="hf-rs">Razón social (sin régimen societario)</label>
		<input id="hf-rs" name="razon_social" required>
		<label for="hf-reg">Régimen fiscal</label>
		<select id="hf-reg" name="regimen_fiscal">
			<option value="601">601 — General de Ley Personas Morales</option>
			<option value="603">603 — Personas Morales con Fines no Lucrativos</option>
			<option value="605">605 — Sueldos y Salarios</option>
			<option value="606">606 — Arrendamiento</option>
			<option value="612" selected>612 — Personas Físicas con Actividades Empresariales y Profesionales</option>
			<option value="616">616 — Sin obligaciones fiscales</option>
			<option value="621">621 — Incorporación Fiscal</option>
			<option value="626">626 — Régimen Simplificado de Confianza</option>
		</select>
		<label for="hf-uso">Uso de CFDI</label>
		<select id="hf-uso" name="uso_cfdi">
			<option value="G03" selected>G03 — Gastos en general</option>
			<option value="G01">G01 — Adquisición de mercancías</option>
			<option value="S01">S01 — Sin efectos fiscales</option>
			<option value="D01">D01 — Honorarios médicos y gastos hospitalarios</option>
			<option value="CP01">CP01 — Pagos</option>
		</select>
		<label for="hf-cp">Código postal fiscal</label>
		<input id="hf-cp" name="cp" required pattern="[0-9]{5}" inputmode="numeric" maxlength="5">
		<label for="hf-email">Correo electrónico</label>
		<input id="hf-email" name="email" type="email" required>
		<button type="submit">Generar factura</button>
	</form>

	<div id="hf-done" hidden>
		<h2>Factura generada</h2>
		<p>Te enviamos el PDF y el XML a tu correo.</p>
		<p><a id="hf-pdf" href="#" hidden>Descargar PDF</a> <a id="hf-xml" href="#" hidden>Descargar XML</a></p>
	</div>

	<p id="hf-unavailable" hidden>La facturación en línea aún no está disponible. Guarda tu ticket e inténtalo más tarde.</p>
	<p id="hf-error" role="alert" hidden></p>
</section>

<script>
( function () {
	const root = document.querySelector( '.haramara-factura' );
	const api  = root.dataset.api.replace( /\/$/, '' );
	const en   = location.pathname.startsWith( '/en/' );
	const $    = ( id ) => document.getElementById( id );

	// API messages arrive in Spanish AFTER the /en/ dictionary pass; map the
	// stable error codes for EN visitors, fall back to the ES message.
	const EN_ERRORS = {
		haramara_factura_unavailable: 'Online invoicing is not available yet. Keep your ticket and try again later.',
		haramara_fiscal_not_configured: 'Online invoicing is not available yet. Keep your ticket and try again later.',
		haramara_factura_not_found: 'We could not find a ticket with that folio and total. Check the printed data.',
		haramara_factura_exists: 'This ticket has already been invoiced. Check your email or message us on WhatsApp.',
		haramara_fiscal_rate_limited: 'Too many attempts. Wait an hour and try again.',
		haramara_fiscal_invalid: 'Check the highlighted fiscal data and try again.',
		haramara_pac_rejected: 'The invoicing service rejected the data. Check your RFC and registered name.',
		haramara_fiscal_totals: 'We could not reconcile this ticket automatically. Message us on WhatsApp to get your invoice.',
		haramara_pac_network: 'The invoicing service is unreachable. Try again in a few minutes.',
		haramara_pac_auth: 'The invoicing service is unavailable. Message us on WhatsApp.',
		haramara_pac_error: 'The invoicing service is unavailable. Try again in a few minutes.',
		haramara_invoice_store_failed: 'Your invoice was issued but the download failed. Check your email or message us on WhatsApp.'
	};

	const showError = ( body ) => {
		const code = body && body.code;
		if ( 'haramara_factura_unavailable' === code || 'haramara_fiscal_not_configured' === code ) {
			$( 'hf-unavailable' ).hidden = false;
			$( 'hf-validate' ).hidden    = true;
			return;
		}
		$( 'hf-error' ).textContent = ( en && EN_ERRORS[ code ] ) || ( body && body.message ) || '…';
		$( 'hf-error' ).hidden      = false;
	};

	const post = ( path, data ) => fetch( api + path, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( data ),
	} ).then( ( r ) => r.json().then( ( body ) => ( { ok: r.ok, body } ) ) );

	// Prefill from the ticket QR: /factura?f=<folio>.
	const qr = new URLSearchParams( location.search ).get( 'f' );
	if ( qr ) {
		$( 'hf-folio' ).value = qr;
	}

	let proof = null;

	$( 'hf-validate' ).addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		$( 'hf-error' ).hidden = true;
		proof = { folio: $( 'hf-folio' ).value.trim(), total: parseFloat( $( 'hf-total' ).value ) };
		const { ok, body } = await post( '/factura/validate', proof );
		if ( ! ok ) {
			return showError( body );
		}
		// Render body.date, body.items[{name, quantity}], body.total into #hf-summary dl.
		$( 'hf-summary' ).hidden = false;
		$( 'hf-issue' ).hidden   = false;
	} );

	$( 'hf-issue' ).addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		$( 'hf-error' ).hidden = true;
		const form            = new FormData( e.target );
		const { ok, body }    = await post( '/factura/issue', Object.assign( {}, proof, Object.fromEntries( form ) ) );
		if ( ! ok ) {
			return showError( body );
		}
		$( 'hf-issue' ).hidden = true;
		$( 'hf-done' ).hidden  = false;
		if ( body.downloads ) {
			$( 'hf-pdf' ).href   = api + '/factura/download?token=' + encodeURIComponent( body.downloads.pdf );
			$( 'hf-xml' ).href   = api + '/factura/download?token=' + encodeURIComponent( body.downloads.xml );
			$( 'hf-pdf' ).hidden = false;
			$( 'hf-xml' ).hidden = false;
		}
	} );
}() );
</script>
```

Notes for whoever builds the real page:

- Style per DESIGN.md through `/impeccable`; the skeleton is behavior only.
- `validate` responds `{folio, date, items: [{name, quantity}], total}`;
  `issue` responds `{uuid, series, folio_fiscal, email_sent, downloads:
  {pdf, xml} | null}` (tokens, not URLs — compose the download URL as shown;
  tokens expire in 7 days).
- Keep RFC/email out of URLs — both endpoints are POST-only by design.
- The download links point at the REST route; they stream with
  `Content-Disposition: attachment`.

## 6. translations.php — required EN pairs

Every visitor-facing ES string in the skeleton above, keyed per the CLAUDE.md
rules (exact rendered text; whole sentences as their own keys; ambiguous
single words wrapped as `>Palabra<`). If the page copy is edited, re-derive
this list from the rendered HTML:

```php
/* --------------------------------------------------------- /factura page */
'>Facturación<'                                        => '>Invoicing<',
'Genera la factura (CFDI) de tu consumo con el folio impreso en tu ticket.' => 'Generate the CFDI invoice for your purchase with the folio printed on your ticket.',
'Folio del ticket'                                     => 'Ticket folio',
'Total del ticket (MXN)'                               => 'Ticket total (MXN)',
'Validar ticket'                                       => 'Validate ticket',
'Tu consumo'                                           => 'Your purchase',
'Razón social (sin régimen societario)'                => 'Registered name (without the legal-form suffix)',
'Régimen fiscal'                                       => 'Tax regime',
'601 — General de Ley Personas Morales'                => '601 — General regime (companies)',
'603 — Personas Morales con Fines no Lucrativos'       => '603 — Non-profit entities',
'605 — Sueldos y Salarios'                             => '605 — Wages and salaries',
'>Arrendamiento<'                                      => '>Leasing<',
'612 — Personas Físicas con Actividades Empresariales y Profesionales' => '612 — Individuals with business and professional activity',
'616 — Sin obligaciones fiscales'                      => '616 — No tax obligations',
'621 — Incorporación Fiscal'                           => '621 — Fiscal incorporation',
'626 — Régimen Simplificado de Confianza'              => '626 — Simplified trust regime (RESICO)',
'Uso de CFDI'                                          => 'CFDI use',
'G03 — Gastos en general'                              => 'G03 — General expenses',
'G01 — Adquisición de mercancías'                      => 'G01 — Purchase of goods',
'S01 — Sin efectos fiscales'                           => 'S01 — No fiscal effects',
'D01 — Honorarios médicos y gastos hospitalarios'      => 'D01 — Medical and hospital expenses',
'CP01 — Pagos'                                         => 'CP01 — Payments',
'Código postal fiscal'                                 => 'Tax ZIP code',
'Correo electrónico'                                   => 'Email address',
'Generar factura'                                      => 'Generate invoice',
'Factura generada'                                     => 'Invoice generated',
'Te enviamos el PDF y el XML a tu correo.'             => 'We sent the PDF and XML to your email.',
'Descargar PDF'                                        => 'Download PDF',
'Descargar XML'                                        => 'Download XML',
'La facturación en línea aún no está disponible. Guarda tu ticket e inténtalo más tarde.' => 'Online invoicing is not available yet. Keep your ticket and try again later.',
```

The `606` option renders as `606 — Arrendamiento`; `Arrendamiento` is a single
word inside a longer option label, so give the full label its own key instead
of the wrapped form if the `>Arrendamiento<` pattern cannot match your final
markup (`>…<` only matches when the word is the entire text node):

```php
'606 — Arrendamiento'                                  => '606 — Leasing',
```

(Prefer this full-label key; it is listed both ways above so the ambiguity is
visible in review.)

Also add the page's `<title>` key following the existing `<title>…` idiom in
`translations.php` (match the rendered `<title>` exactly, including the
`&#8211;` separator), e.g.:

```php
'<title>Factura &#8211;'                               => '<title>Invoice &#8211;',
```

No hardcoded relative hrefs exist in the skeleton, so no `/en/` href rewrite
entries are needed. If the final page links to `/factura` from elsewhere
(footer, carta), that link needs the standard href rewrite entry.

Strings delivered over the API (validation errors, PAC rejections) are NOT
covered by the dictionary — they arrive after the strtr pass. The skeleton's
`EN_ERRORS` map handles them client-side by stable error code; the invoice
email is Spanish-only by design (es-MX is the café's voice).

## 7. api-client

No TypeScript changes: /factura is a web-only surface. If the POS app later
grows an invoices screen, mirror the response shapes into
`packages/api-client/src/types.ts` by hand (CLAUDE.md: types are hand-mirrored,
no codegen).

## 8. What is stubbed vs live

Live now (once Activator + Plugin.php wiring lands): the whole REST surface,
Facturama sandbox/production stamping, IVA desglose, invoice records, protected
document store, download tokens, email delivery, rate limiting.

Stubbed: folio → order resolution (`PendingFolioResolver`, 503) until Phase 2
lands `_pos_folio`. With the stub in place the page publicly shows only the
"aún no disponible" state — safe to ship dark.

Operational setup (Facturama credentials, CSD upload at the PAC, wp-config
constants, catalog tables, nginx deny): `src/Fiscal/README.md`.
