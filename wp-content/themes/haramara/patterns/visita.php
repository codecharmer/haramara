<?php
/**
 * Title: Visítanos — Dirección y horario
 * Slug: haramara/visita
 * Categories: haramara-page
 * Description: Bloque de visita con datos reales enlazados al negocio: dirección, horario, WhatsApp y Maps, sobre fotografía de la hora dorada.
 * Keywords: visita, dirección, horario, whatsapp, maps, cómo llegar
 * Viewport Width: 1400
 */
?>
<!-- wp:cover {"url":"<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/kombucha-celosia-2000.webp","gradient":"scrim-foto","dimRatio":100,"isUserOverlayColor":true,"minHeight":72,"minHeightUnit":"vh","align":"full","anchor":"visitanos","className":"hm-visita-cover"} -->
<div id="visitanos" class="wp-block-cover alignfull hm-visita-cover" style="min-height:72vh"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim has-background-gradient has-scrim-foto-gradient-background"></span><img class="wp-block-cover__image-background" alt="La celosía de ladrillo de Haramara a contraluz durante la hora dorada" src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/kombucha-celosia-2000.webp" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Visítanos</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"address"}}}}} -->
<p>Tulipán 302, esq. Hule, Col. Delicias, 62330 Cuernavaca, Mor.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"hours_summary"}}}}} -->
<p>Miércoles a lunes · 8:00–20:00</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"hm-carta__note","metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"hours_closed"}}}}} -->
<p class="hm-carta__note">Martes descansamos.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"blockGap":"var:preset|spacing|md","margin":{"top":"var:preset|spacing|md"}}}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--md)"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://wa.me/527771362228" rel="noopener">WhatsApp · 777 136 2228</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-link-underline"} -->
<div class="wp-block-button is-style-link-underline"><a class="wp-block-button__link wp-element-button" href="https://www.google.com/maps/search/?api=1&amp;query=Haramara+cafe+Tulipan+302+Cuernavaca" rel="noopener">Abrir en Maps</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div></div>
<!-- /wp:cover -->
