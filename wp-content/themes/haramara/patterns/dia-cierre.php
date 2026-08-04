<?php
/**
 * Title: 20:00 — Cerramos
 * Slug: haramara/dia-cierre
 * Categories: haramara-dia
 * Description: El cierre del día: visita con dirección y horario reales, WhatsApp, lealtad y el sello de la casa sobre fondo noche.
 * Keywords: cierre, visita, dirección, horario, whatsapp, lealtad
 * Viewport Width: 1400
 */
?>
<!-- wp:group {"align":"full","backgroundColor":"noche","className":"hm-day hm-day--cierre","layout":{"type":"constrained","wideSize":"1240px"}} -->
<div class="wp-block-group alignfull hm-day hm-day--cierre has-noche-background-color has-background"><!-- wp:group {"className":"hm-station","layout":{"type":"default"}} -->
<div class="wp-block-group hm-station"><!-- wp:paragraph {"className":"hm-station__hour"} -->
<p class="hm-station__hour">20:00</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"hm-station__label"} -->
<p class="hm-station__label">Cerramos</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"hm-reveal-group","layout":{"type":"constrained","contentSize":"700px"}} -->
<div class="wp-block-group hm-reveal-group"><!-- wp:paragraph {"align":"center","className":"hm-voz hm-reveal","metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"hours_closed"}}}}} -->
<p class="has-text-align-center hm-voz hm-reveal">Martes descansamos.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","anchor":"visitanos","className":"hm-visita hm-reveal-group","style":{"spacing":{"margin":{"top":"var:preset|spacing|xl"}}},"layout":{"type":"constrained","contentSize":"920px"}} -->
<div id="visitanos" class="wp-block-group alignwide hm-visita hm-reveal-group" style="margin-top:var(--wp--preset--spacing--xl)"><!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|xl"}}}} -->
<div class="wp-block-columns"><!-- wp:column {"width":"50%","className":"hm-reveal"} -->
<div class="wp-block-column hm-reveal" style="flex-basis:50%"><!-- wp:heading {"level":2,"fontSize":"xl"} -->
<h2 class="wp-block-heading has-xl-font-size">Visítanos</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"address"}}}}} -->
<p>Tulipán 302, esq. Hule, Col. Delicias, 62330 Cuernavaca, Mor.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"hours_summary"}}}}} -->
<p>Miércoles a lunes · 8:00–20:00</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"blockGap":"var:preset|spacing|md","margin":{"top":"var:preset|spacing|md"}}}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--md)"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://wa.me/527771362228" rel="noopener">Escríbenos por WhatsApp</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-link-underline"} -->
<div class="wp-block-button is-style-link-underline"><a class="wp-block-button__link wp-element-button" href="https://www.google.com/maps/search/?api=1&amp;query=Haramara+cafe+Tulipan+302+Cuernavaca" rel="noopener">Abrir en Maps</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"50%","className":"hm-reveal"} -->
<div class="wp-block-column hm-reveal" style="flex-basis:50%"><!-- wp:heading {"level":2,"fontSize":"xl"} -->
<h2 class="wp-block-heading has-xl-font-size">Cada visita deja huella</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Lealtad Haramara vive en un código QR: cada visita suma y los canjes se aplican en barra. Sin tarjetas que se pierden.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"var:preset|spacing|md"}}}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--md)"><!-- wp:button {"className":"is-style-link-underline"} -->
<div class="wp-block-button is-style-link-underline"><a class="wp-block-button__link wp-element-button" href="/lealtad">Conocer Lealtad Haramara</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:group {"className":"hm-sello hm-reveal","style":{"spacing":{"margin":{"top":"var:preset|spacing|xl"}}},"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-group hm-sello hm-reveal" style="margin-top:var(--wp--preset--spacing--xl)"><!-- wp:image {"width":"96px","height":"96px","scale":"cover","sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full is-resized"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/logo-haramara.webp" alt="Sello de Haramara: una flama dorada dibujada a mano dentro de un anillo de latón" style="object-fit:cover;width:96px;height:96px"/></figure>
<!-- /wp:image --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
