<?php
/**
 * Title: Cuadrícula de productos
 * Slug: haramara/product-grid
 * Categories: haramara-commerce
 * Description: Colección de productos de WooCommerce con encabezado editorial, tarjetas en display y consulta configurable.
 * Keywords: productos, tienda, woocommerce, cuadrícula, menú, collection
 * Viewport Width: 1400
 */
?>
<!-- wp:group {"align":"full","backgroundColor":"char","className":"hm-band","style":{"spacing":{"padding":{"top":"var:preset|spacing|xxl","bottom":"var:preset|spacing|xxl"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull hm-band has-char-background-color has-background" style="padding-top:var(--wp--preset--spacing--xxl);padding-bottom:var(--wp--preset--spacing--xxl)"><!-- wp:group {"className":"hm-head","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"default"}} -->
<div class="wp-block-group hm-head"><!-- wp:heading {"fontSize":"xxl"} -->
<h2 class="wp-block-heading has-xxl-font-size">Ordena para recoger</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hm-lede"} -->
<p class="hm-lede">Elige tus piezas y paga desde el teléfono. Todo se recoge en barra, en Tulipán 302.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:woocommerce/product-collection {"queryId":0,"query":{"perPage":9,"pages":0,"offset":0,"postType":"product","order":"asc","orderBy":"title","search":"","exclude":[],"inherit":false,"taxQuery":{},"isProductCollectionBlock":true,"woocommerceOnSale":false,"woocommerceStockStatus":["instock","onbackorder"],"woocommerceAttributes":[],"woocommerceHandPickedProducts":[]},"tagName":"div","align":"wide","className":"hm-product-grid","displayLayout":{"type":"flex","columns":2},"style":{"spacing":{"margin":{"top":"var:preset|spacing|xl"}}}} -->
<div class="wp-block-woocommerce-product-collection alignwide hm-product-grid" style="margin-top:var(--wp--preset--spacing--xl)"><!-- wp:woocommerce/product-template -->
<!-- wp:group {"className":"hm-product-card","style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"default"}} -->
<div class="wp-block-group hm-product-card"><!-- wp:woocommerce/product-image {"imageSizing":"thumbnail","aspectRatio":"4/5","scale":"cover","isDescendentOfQueryLoop":true} /-->

<!-- wp:post-title {"textAlign":"center","level":3,"isLink":true,"__woocommerceNamespace":"woocommerce/product-collection/product-title","fontSize":"lg"} /-->

<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true,"textAlign":"center","fontSize":"base"} /-->

<!-- wp:woocommerce/product-button {"textAlign":"center","isDescendentOfQueryLoop":true,"fontSize":"sm"} /--></div>
<!-- /wp:group -->
<!-- /wp:woocommerce/product-template -->

<!-- wp:query-pagination {"className":"hm-pagination","layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|xl"}}}} -->
<!-- wp:query-pagination-previous {"label":"Anterior"} /-->

<!-- wp:query-pagination-numbers /-->

<!-- wp:query-pagination-next {"label":"Siguiente"} /-->
<!-- /wp:query-pagination -->

<!-- wp:woocommerce/product-collection-no-results -->
<!-- wp:paragraph {"align":"center","textColor":"limestone"} -->
<p class="has-text-align-center has-limestone-color has-text-color">Por ahora no hay nada disponible en línea. La barra abre de miércoles a lunes, de 8:00 a 20:00.</p>
<!-- /wp:paragraph -->
<!-- /wp:woocommerce/product-collection-no-results --></div>
<!-- /wp:woocommerce/product-collection --></div>
<!-- /wp:group -->
