<?php
/**
 * Title: Portada — 08:00, la primera barra
 * Slug: haramara/hero-home
 * Categories: haramara-hero
 * Description: Portada a pantalla completa sobre fotografía real de la barra, titular display, una sola acción y el horario como dato funcional. Pensada para encabezado transparente.
 * Keywords: portada, hero, inicio, café, ritual, barra
 * Viewport Width: 1400
 */
?>
<!-- wp:cover {"url":"<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/pour-dark-2000.webp","gradient":"scrim-lateral","dimRatio":100,"isUserOverlayColor":true,"minHeight":100,"minHeightUnit":"svh","align":"full","className":"hm-hero"} -->
<div class="wp-block-cover alignfull hm-hero" style="min-height:100svh"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim has-background-gradient has-scrim-lateral-gradient-background"></span><img class="wp-block-cover__image-background" alt="Barista de Haramara vertiendo leche sobre un café en la penumbra de la barra" src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/pour-dark-2000.webp" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:group {"className":"hm-reveal-group","layout":{"type":"default"}} -->
<div class="wp-block-group hm-reveal-group"><!-- wp:heading {"level":1,"fontSize":"display","className":"hm-reveal"} -->
<h1 class="wp-block-heading hm-reveal has-display-font-size">Café. Fuego. Ritual.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hm-lede hm-reveal"} -->
<p class="hm-lede hm-reveal">Una barra de especialidad escondida en Cuernavaca. Creamos a través de procesos manuales: café de origen, masa madre viva y el tiempo que cada cosa pide.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"className":"hm-reveal","style":{"spacing":{"blockGap":"var:preset|spacing|md"}}} -->
<div class="wp-block-buttons hm-reveal"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/carta">Ordenar para recoger</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-link-underline"} -->
<div class="wp-block-button is-style-link-underline"><a class="wp-block-button__link wp-element-button" href="#visitanos">Cómo llegar</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"className":"hm-hero__horario hm-reveal","fontSize":"sm","metadata":{"bindings":{"content":{"source":"haramara/business","args":{"key":"hours_summary"}}}}} -->
<p class="hm-hero__horario hm-reveal has-sm-font-size">Miércoles a lunes · 8:00–20:00</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div></div>
<!-- /wp:cover -->
