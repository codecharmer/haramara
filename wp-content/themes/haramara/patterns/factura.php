<?php
/**
 * Title: Factura (CFDI)
 * Slug: haramara/factura
 * Categories: haramara-page
 * Description: Autofactura pública: valida el folio y el total del ticket, captura los datos fiscales y genera el CFDI 4.0 con descargas y envío por correo.
 * Keywords: factura, cfdi, folio, ticket, sat, autofactura
 * Viewport Width: 900
 * Inserter: no
 */

?>
<!-- wp:group {"align":"full","backgroundColor":"carbon","className":"hm-factura","layout":{"type":"constrained","contentSize":"700px"}} -->
<div class="wp-block-group alignfull hm-factura has-carbon-background-color has-background"><!-- wp:group {"className":"hm-page-head","style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"default"}} -->
<div class="wp-block-group hm-page-head"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Facturación</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hm-lede"} -->
<p class="hm-lede">Genera la factura (CFDI) de tu consumo con el folio impreso en tu ticket.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:html -->
<div class="hm-factura__app" data-api="<?php echo esc_url( untrailingslashit( rest_url( 'haramara/v1' ) ) ); ?>">

	<form id="hf-validate" class="hm-factura__form">
		<p class="hm-factura__field">
			<label for="hf-folio">Folio del ticket</label>
			<input id="hf-folio" name="folio" type="text" required autocomplete="off" autocapitalize="characters" spellcheck="false">
		</p>
		<p class="hm-factura__field">
			<label for="hf-total">Total del ticket (MXN)</label>
			<input id="hf-total" name="total" type="number" inputmode="decimal" step="0.01" min="0" required>
		</p>
		<p class="hm-factura__actions">
			<button type="submit" class="wp-element-button">Validar ticket</button>
		</p>
	</form>

	<section id="hf-summary" class="hm-factura__summary" hidden>
		<h2>Tu consumo</h2>
		<p id="hf-date" class="hm-factura__fecha"></p>
		<ul id="hf-items" class="hm-factura__items"></ul>
		<p class="hm-factura__total"><span>Total</span> <strong id="hf-total-out"></strong></p>
	</section>

	<form id="hf-issue" class="hm-factura__form" hidden>
		<h2>Tus datos fiscales</h2>
		<p class="hm-factura__field">
			<label for="hf-rfc">RFC</label>
			<input id="hf-rfc" name="rfc" type="text" required maxlength="13" autocomplete="off" autocapitalize="characters" spellcheck="false">
		</p>
		<p class="hm-factura__field">
			<label for="hf-rs">Razón social (sin régimen societario)</label>
			<input id="hf-rs" name="razon_social" type="text" required>
		</p>
		<p class="hm-factura__field">
			<label for="hf-reg">Régimen fiscal</label>
			<select id="hf-reg" name="regimen_fiscal">
				<option value="601">601 — General de Ley Personas Morales</option>
				<option value="603">603 — Personas Morales con Fines no Lucrativos</option>
				<option value="605">605 — Sueldos y Salarios e Ingresos Asimilados a Salarios</option>
				<option value="606">606 — Arrendamiento</option>
				<option value="612" selected>612 — Personas Físicas con Actividades Empresariales y Profesionales</option>
				<option value="616">616 — Sin obligaciones fiscales</option>
				<option value="621">621 — Incorporación Fiscal</option>
				<option value="626">626 — Régimen Simplificado de Confianza (RESICO)</option>
			</select>
		</p>
		<p class="hm-factura__field">
			<label for="hf-uso">Uso de CFDI</label>
			<select id="hf-uso" name="uso_cfdi">
				<option value="G03" selected>G03 — Gastos en general</option>
				<option value="G01">G01 — Adquisición de mercancías</option>
				<option value="S01">S01 — Sin efectos fiscales</option>
				<option value="D01">D01 — Honorarios médicos, dentales y gastos hospitalarios</option>
				<option value="CP01">CP01 — Pagos</option>
			</select>
		</p>
		<p class="hm-factura__field">
			<label for="hf-cp">Código postal fiscal</label>
			<input id="hf-cp" name="cp" type="text" required pattern="[0-9]{5}" inputmode="numeric" maxlength="5" autocomplete="postal-code">
		</p>
		<p class="hm-factura__field">
			<label for="hf-email">Correo electrónico</label>
			<input id="hf-email" name="email" type="email" required autocomplete="email">
		</p>
		<p class="hm-factura__actions">
			<button type="submit" class="wp-element-button">Generar factura</button>
		</p>
	</form>

	<section id="hf-done" class="hm-factura__done" hidden>
		<h2>Factura generada</h2>
		<p>Te enviamos el PDF y el XML a tu correo.</p>
		<p class="hm-factura__downloads">
			<a id="hf-pdf" class="hm-enlace" href="#" hidden>Descargar PDF</a>
			<a id="hf-xml" class="hm-enlace" href="#" hidden>Descargar XML</a>
		</p>
	</section>

	<p id="hf-unavailable" class="hm-factura__unavailable" hidden>La facturación en línea aún no está disponible. Guarda tu ticket e inténtalo más tarde.</p>
	<p id="hf-error" class="hm-factura__error" role="alert" data-network="No pudimos conectar. Revisa tu conexión e inténtalo de nuevo." hidden></p>

</div>

<script>
( function () {
	var root = document.querySelector( '.hm-factura__app' );
	if ( ! root ) {
		return;
	}

	var api = root.dataset.api.replace( /\/$/, '' );
	var en  = location.pathname.indexOf( '/en/' ) === 0;
	var $   = function ( id ) {
		return document.getElementById( id );
	};

	// API messages arrive in Spanish via fetch, AFTER the /en/ dictionary
	// pass; map the stable error codes for EN visitors, fall back to the
	// verbatim ES message.
	var EN_ERRORS = {
		haramara_factura_unavailable: 'Online invoicing is not available yet. Keep your ticket and try again later.',
		haramara_fiscal_not_configured: 'Online invoicing is not available yet. Keep your ticket and try again later.',
		haramara_factura_not_found: 'We could not find a ticket with that folio and total. Check the printed data.',
		haramara_folio_invalid: 'We could not find a ticket with that folio and total. Check the printed data.',
		haramara_factura_exists: 'This ticket has already been invoiced. Check your email or message us on WhatsApp.',
		haramara_fiscal_rate_limited: 'Too many attempts. Wait an hour and try again.',
		haramara_fiscal_invalid: 'Check the fiscal data you entered and try again.',
		haramara_pac_rejected: 'The invoicing service rejected the data. Check your RFC and registered name.',
		haramara_fiscal_totals: 'We could not reconcile this ticket automatically. Message us on WhatsApp to get your invoice.',
		haramara_pac_network: 'The invoicing service is unreachable. Try again in a few minutes.',
		haramara_pac_auth: 'The invoicing service is unavailable. Message us on WhatsApp.',
		haramara_pac_error: 'The invoicing service is unavailable. Try again in a few minutes.',
		haramara_invoice_store_failed: 'Your invoice was issued but the download failed. Check your email or message us on WhatsApp.',
		network: 'We could not connect. Check your connection and try again.'
	};

	var showError = function ( body ) {
		var code = body && body.code;
		if ( 'haramara_factura_unavailable' === code || 'haramara_fiscal_not_configured' === code ) {
			$( 'hf-unavailable' ).hidden = false;
			$( 'hf-validate' ).hidden    = true;
			$( 'hf-summary' ).hidden     = true;
			$( 'hf-issue' ).hidden       = true;
			return;
		}
		var fallback = 'network' === code ? $( 'hf-error' ).dataset.network : ( body && body.message );
		$( 'hf-error' ).textContent = ( en && EN_ERRORS[ code ] ) || fallback || '…';
		$( 'hf-error' ).hidden      = false;
	};

	var post = function ( path, data ) {
		return fetch( api + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( data )
		} ).then( function ( r ) {
			return r.json().then( function ( body ) {
				return { ok: r.ok, body: body };
			} );
		} );
	};

	var money = function ( value ) {
		return '$' + Number( value ).toFixed( 2 ) + ' MXN';
	};

	var busy = function ( form, on ) {
		var button = form.querySelector( 'button[type="submit"]' );
		if ( button ) {
			button.disabled = on;
		}
	};

	// Prefill from the ticket QR: /factura?f=<folio>.
	var qr = new URLSearchParams( location.search ).get( 'f' );
	if ( qr ) {
		$( 'hf-folio' ).value = qr;
	}

	var proof = null;

	$( 'hf-validate' ).addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		$( 'hf-error' ).hidden = true;
		proof = { folio: $( 'hf-folio' ).value.trim(), total: parseFloat( $( 'hf-total' ).value ) };
		busy( e.target, true );
		post( '/factura/validate', proof ).then( function ( res ) {
			if ( ! res.ok ) {
				return showError( res.body );
			}
			$( 'hf-date' ).textContent = res.body.date || '';
			var list = $( 'hf-items' );
			list.textContent = '';
			( res.body.items || [] ).forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.textContent = item.name + ' × ' + item.quantity;
				list.appendChild( li );
			} );
			$( 'hf-total-out' ).textContent = money( res.body.total );
			$( 'hf-summary' ).hidden = false;
			$( 'hf-issue' ).hidden   = false;
		} ).catch( function () {
			showError( { code: 'network' } );
		} ).then( function () {
			busy( $( 'hf-validate' ), false );
		} );
	} );

	$( 'hf-issue' ).addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		$( 'hf-error' ).hidden = true;
		var data = Object.fromEntries( new FormData( e.target ) );
		busy( e.target, true );
		post( '/factura/issue', Object.assign( {}, proof, data ) ).then( function ( res ) {
			if ( ! res.ok ) {
				return showError( res.body );
			}
			$( 'hf-issue' ).hidden = true;
			$( 'hf-done' ).hidden  = false;
			if ( res.body.downloads ) {
				$( 'hf-pdf' ).href   = api + '/factura/download?token=' + encodeURIComponent( res.body.downloads.pdf );
				$( 'hf-xml' ).href   = api + '/factura/download?token=' + encodeURIComponent( res.body.downloads.xml );
				$( 'hf-pdf' ).hidden = false;
				$( 'hf-xml' ).hidden = false;
			}
			$( 'hf-done' ).scrollIntoView( { block: 'nearest' } );
		} ).catch( function () {
			showError( { code: 'network' } );
		} ).then( function () {
			busy( $( 'hf-issue' ), false );
		} );
	} );
}() );
</script>
<!-- /wp:html --></div>
<!-- /wp:group -->
