<?php
/**
 * Seed pages.
 *
 * Real Haramara launch IA. Consumed by
 * {@see \Haramara\Core\Setup\Installer::install_pages()}. `content` is block
 * markup; pattern references render the theme's registered patterns and can be
 * detached in the editor for page-level edits. Exactly one page sets
 * 'is_front' => true.
 *
 * @package Haramara\Core
 * @return array<int,array<string,mixed>>
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	array(
		'title'            => 'Inicio',
		'slug'             => 'inicio',
		'template'         => '',
		'status'           => 'publish',
		'is_front'         => true,
		'content'          => '', // front-page.html composes the day's patterns.
		'seo_short'        => 'Haramara — café de especialidad y masa madre en Cuernavaca.',
		'meta_description' => 'Una barra de café de especialidad escondida en Cuernavaca: filtrados Chill, Groove y Funky, pan de masa madre y procesos manuales. Miércoles a lunes, 8:00–20:00.',
	),

	array(
		'title'            => 'La carta',
		'slug'             => 'carta',
		'template'         => 'page-no-title',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:group {"align":"full","backgroundColor":"carbon","className":"hm-day","layout":{"type":"constrained","wideSize":"1240px"}} -->
<div class="wp-block-group alignfull hm-day has-carbon-background-color has-background"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">La carta</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hm-lede"} -->
<p class="hm-lede">Lo que se sirve en barra, tal cual, con sus precios. Ordena en línea y recoge cuando esté listo.</p>
<!-- /wp:paragraph -->

<!-- wp:pattern {"slug":"haramara/carta-cafes"} /-->
<!-- wp:pattern {"slug":"haramara/carta-salados"} /-->
<!-- wp:pattern {"slug":"haramara/carta-especiales"} /--></div>
<!-- /wp:group -->

<!-- wp:pattern {"slug":"haramara/product-grid"} /-->

<!-- wp:pattern {"slug":"haramara/order-cta"} /-->',
		'seo_short'        => 'La carta de Haramara: cafés, salados y especiales con precios.',
		'meta_description' => 'La carta de Haramara, Cuernavaca: espresso, cold brew, filtrados Chill/Groove/Funky, croissants, bagels, pretzel y especiales. Ordena para recoger.',
	),

	array(
		'title'            => 'Historia',
		'slug'             => 'historia',
		'template'         => 'page-no-title',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:pattern {"slug":"haramara/historia"} /-->

<!-- wp:pattern {"slug":"haramara/order-cta"} /-->',
		'seo_short'        => 'La historia de Haramara y su casa gastronómica.',
		'meta_description' => 'Haramara nace de la casa gastronómica de Cocina Suiza, Pacífica y Malva: más de treinta años de oficio en Cuernavaca, ahora concentrados en café y masa madre.',
	),

	array(
		'title'            => 'Eventos',
		'slug'             => 'eventos',
		'template'         => '',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:pattern {"slug":"haramara/eventos-lista"} /-->',
		'seo_short'        => 'Eventos del mes en la barra de Haramara.',
		'meta_description' => 'Lo que pasa este mes en la barra de Haramara, Cuernavaca. Cupos limitados; aparta por WhatsApp.',
	),

	array(
		'title'            => 'Lealtad',
		'slug'             => 'lealtad',
		'template'         => '',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:pattern {"slug":"haramara/lealtad"} /-->',
		'seo_short'        => 'Lealtad Haramara: tu QR, tus sellos, tus canjes.',
		'meta_description' => 'Lealtad Haramara vive en un código QR: cada visita suma y los canjes se aplican en barra. Crea tu cuenta en un minuto.',
	),

	array(
		'title'            => 'Visítanos',
		'slug'             => 'visitanos',
		'template'         => 'page-no-title',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:pattern {"slug":"haramara/visita"} /-->',
		'seo_short'        => 'Dónde está Haramara y cuándo abre.',
		'meta_description' => 'Haramara: Tulipán 302, esq. Hule, Col. Delicias, Cuernavaca. Miércoles a lunes de 8:00 a 20:00; martes descansamos. WhatsApp 777 136 2228.',
	),

	array(
		'title'            => 'Factura',
		'slug'             => 'factura',
		'template'         => 'page-no-title',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:pattern {"slug":"haramara/factura"} /-->',
		'seo_short'        => 'Facturación Haramara: genera tu CFDI con el folio del ticket.',
		'meta_description' => 'Genera tu factura (CFDI 4.0) de Haramara Café con el folio y el total impresos en tu ticket. Recibe el PDF y el XML en tu correo.',
	),

	array(
		'title'            => 'Aviso de privacidad',
		'slug'             => 'aviso-de-privacidad',
		'template'         => '',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:paragraph --><p>Documento en preparación. El aviso de privacidad definitivo será publicado por Haramara antes del lanzamiento.</p><!-- /wp:paragraph -->',
		'seo_short'        => '',
		'meta_description' => '',
	),

	array(
		'title'            => 'Términos',
		'slug'             => 'terminos',
		'template'         => '',
		'status'           => 'publish',
		'is_front'         => false,
		'content'          => '<!-- wp:paragraph --><p>Documento en preparación. Los términos de servicio definitivos serán publicados por Haramara antes del lanzamiento.</p><!-- /wp:paragraph -->',
		'seo_short'        => '',
		'meta_description' => '',
	),

);
