<?php
/**
 * Seed catalogue — WooCommerce products.
 *
 * The real Haramara carta, transcribed from the physical menu (Aug 2026;
 * photos in assets/reference/menu at the repo root). Prices in MXN.
 * This file is the source of truth — do NOT reorganise products only in the
 * DB, or a fresh deploy ships the wrong catalogue (docs/METHODOLOGY.md §8).
 *
 *  - `category` must be a slug declared in Installer::CATEGORIES
 *    (cafes | salados | especiales).
 *  - Drinks are made to order: manage_stock false.
 *  - `image_key` resolves to data/media/source/{image_key}.{jpg,png,webp}; when
 *    no file exists a branded placeholder is generated. Only items whose real
 *    photo we have carry an image_key — never attach a photo of a different dish.
 *
 * @package Haramara\Core
 * @return array<int,array<string,mixed>>
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	/* ---------------------------------------------------------------- Cafés */

	array(
		'name'              => 'Espresso',
		'slug'              => 'espresso',
		'category'          => 'cafes',
		'regular_price'     => 60.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-CAFE-001',
		'short_description' => 'Extracción del día, en barra.',
		'description'       => 'Nuestro espresso del día, extraído al momento.',
		'tags'              => array( 'café', 'barra' ),
		'attributes'        => array( 'vegano' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'espresso',
		'image_alt'         => 'Espresso de Haramara servido en barra.',
		'seo_short'         => 'Espresso de especialidad en Haramara, Cuernavaca.',
		'meta_description'  => 'Espresso de especialidad extraído al momento en la barra de Haramara, Cuernavaca. Ordena para recoger.',
	),

	array(
		'name'              => 'Cold brew',
		'slug'              => 'cold-brew',
		'category'          => 'cafes',
		'regular_price'     => 70.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-CAFE-002',
		'short_description' => 'Extracción en frío, servido al tiempo o con hielo.',
		'description'       => 'Café extraído en frío durante horas, no minutos.',
		'tags'              => array( 'café', 'frío' ),
		'attributes'        => array( 'vegano' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'cold-brew',
		'image_alt'         => 'Cold brew de Haramara.',
		'seo_short'         => 'Cold brew de especialidad en Haramara, Cuernavaca.',
		'meta_description'  => 'Cold brew de extracción lenta en Haramara, Cuernavaca. Ordena para recoger en barra.',
	),

	array(
		'name'              => 'Filtrado · Chill',
		'slug'              => 'filtrado-chill',
		'category'          => 'cafes',
		'regular_price'     => 70.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-CAFE-003',
		'short_description' => 'El punto de partida de la escalera de filtrados.',
		'description'       => 'La selección de la barra cambia con frecuencia; pregunta qué se está filtrando hoy.',
		'tags'              => array( 'café', 'filtrado' ),
		'attributes'        => array( 'vegano' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'filtrado-chill',
		'image_alt'         => 'Café filtrado servido en Haramara.',
		'seo_short'         => 'Filtrado Chill — café de especialidad en Haramara.',
		'meta_description'  => 'Filtrado Chill: el punto de partida de la escalera de filtrados de Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Filtrado · Groove',
		'slug'              => 'filtrado-groove',
		'category'          => 'cafes',
		'regular_price'     => 85.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-CAFE-004',
		'short_description' => 'Un paso más profundo en la escalera de filtrados.',
		'description'       => 'La selección de la barra cambia con frecuencia; pregunta qué se está filtrando hoy.',
		'tags'              => array( 'café', 'filtrado' ),
		'attributes'        => array( 'vegano' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'filtrado-groove',
		'image_alt'         => 'Café filtrado servido en Haramara.',
		'seo_short'         => 'Filtrado Groove — café de especialidad en Haramara.',
		'meta_description'  => 'Filtrado Groove: un paso más profundo en la escalera de filtrados de Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Filtrado · Funky',
		'slug'              => 'filtrado-funky',
		'category'          => 'cafes',
		'regular_price'     => 120.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-CAFE-005',
		'short_description' => 'Para los curiosos. Lo más expresivo de la barra.',
		'description'       => 'La selección de la barra cambia con frecuencia; pregunta qué se está filtrando hoy.',
		'tags'              => array( 'café', 'filtrado' ),
		'attributes'        => array( 'vegano' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'filtrado-funky',
		'image_alt'         => 'Café filtrado servido en Haramara.',
		'seo_short'         => 'Filtrado Funky — café de especialidad en Haramara.',
		'meta_description'  => 'Filtrado Funky: lo más expresivo de la barra de Haramara, Cuernavaca.',
	),

	/* -------------------------------------------------------------- Salados */

	array(
		'name'              => 'Pudding chía',
		'slug'              => 'pudding-chia',
		'category'          => 'salados',
		'regular_price'     => 160.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-001',
		'short_description' => 'Chía, fruta de temporada y granola de la casa.',
		'description'       => 'Pudding de chía con fruta fresca y granola de la casa.',
		'tags'              => array( 'desayuno' ),
		'attributes'        => array(
			'vegano'     => 'no',
			'sin-nueces' => 'no',
		),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'pudding-chia',
		'image_alt'         => 'Bowl de pudding de chía con mango, fresa, kiwi y granola sobre mesa de piedra negra.',
		'seo_short'         => 'Pudding de chía con fruta y granola en Haramara.',
		'meta_description'  => 'Pudding de chía con fruta de temporada y granola de la casa, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Croissant de ventresca de atún',
		'slug'              => 'croissant-ventresca',
		'category'          => 'salados',
		'regular_price'     => 230.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-002',
		'short_description' => 'Laminado de la casa con ventresca de atún.',
		'description'       => 'Croissant laminado a mano, relleno de ventresca de atún.',
		'tags'              => array( 'croissant' ),
		'attributes'        => array( 'masa-madre' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'croissant-ventresca',
		'image_alt'         => 'Croissant de ventresca de atún de Haramara.',
		'seo_short'         => 'Croissant de ventresca de atún — Haramara.',
		'meta_description'  => 'Croissant laminado a mano con ventresca de atún, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Croissant de burrata y jamón serrano',
		'slug'              => 'croissant-burrata-serrano',
		'category'          => 'salados',
		'regular_price'     => 280.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-003',
		'short_description' => 'Laminado de la casa, burrata y jamón serrano.',
		'description'       => 'Croissant laminado a mano con burrata y jamón serrano.',
		'tags'              => array( 'croissant' ),
		'attributes'        => array( 'masa-madre' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'croissant-burrata',
		'image_alt'         => 'Croissant de burrata y jamón serrano de Haramara.',
		'seo_short'         => 'Croissant de burrata y jamón serrano — Haramara.',
		'meta_description'  => 'Croissant laminado a mano con burrata y jamón serrano, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Bagel de trucha ahumada y alcaparra crocante',
		'slug'              => 'bagel-trucha-ahumada',
		'category'          => 'salados',
		'regular_price'     => 220.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-004',
		'short_description' => 'Bagel de la casa, trucha ahumada, alcaparra crocante.',
		'description'       => 'Bagel de la casa con trucha ahumada y alcaparras crocantes.',
		'tags'              => array( 'bagel' ),
		'attributes'        => array( 'masa-madre' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'bagel-trucha',
		'image_alt'         => 'Bagel de trucha ahumada de Haramara.',
		'seo_short'         => 'Bagel de trucha ahumada — Haramara.',
		'meta_description'  => 'Bagel de la casa con trucha ahumada y alcaparra crocante, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Pretzel con encurtidos y selva negra',
		'slug'              => 'pretzel-selva-negra',
		'category'          => 'salados',
		'regular_price'     => 250.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-005',
		'short_description' => 'Pretzel de la casa, encurtidos y jamón selva negra.',
		'description'       => 'Pretzel de la casa servido con encurtidos, mantequilla y jamón selva negra — la herencia de la casa suiza de la familia.',
		'tags'              => array( 'pretzel' ),
		'attributes'        => array( 'masa-madre' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'pretzel-selva-negra',
		'image_alt'         => 'Pretzel con encurtidos y jamón selva negra servido en plato de acero.',
		'seo_short'         => 'Pretzel con encurtidos y selva negra — Haramara.',
		'meta_description'  => 'Pretzel de la casa con encurtidos y jamón selva negra, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Naan cuatro quesos con piñones y hot honey trufada',
		'slug'              => 'naan-cuatro-quesos',
		'category'          => 'salados',
		'regular_price'     => 240.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-006',
		'short_description' => 'Naan de la casa, cuatro quesos, piñones y miel picante trufada.',
		'description'       => 'Naan de la casa con cuatro quesos, piñones y hot honey trufada.',
		'tags'              => array( 'naan' ),
		'attributes'        => array( 'sin-nueces' => 'no' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'naan-cuatro-quesos',
		'image_alt'         => 'Naan cuatro quesos con piñones y hot honey trufada de Haramara.',
		'seo_short'         => 'Naan cuatro quesos con hot honey trufada — Haramara.',
		'meta_description'  => 'Naan de la casa con cuatro quesos, piñones y hot honey trufada, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Laminado de queso gouda y jamón de pavo',
		'slug'              => 'laminado-gouda-pavo',
		'category'          => 'salados',
		'regular_price'     => 55.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-SAL-007',
		'short_description' => 'Laminado de la casa con gouda y jamón de pavo.',
		'description'       => 'Laminado de la casa con queso gouda y jamón de pavo.',
		'tags'              => array( 'laminado' ),
		'attributes'        => array( 'masa-madre' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'laminado-gouda',
		'image_alt'         => 'Laminado de queso gouda y jamón de pavo de Haramara.',
		'seo_short'         => 'Laminado de gouda y jamón de pavo — Haramara.',
		'meta_description'  => 'Laminado de la casa con queso gouda y jamón de pavo, en Haramara, Cuernavaca.',
	),

	/* ----------------------------------------------------------- Especiales */

	array(
		'name'              => 'Crème brûlée',
		'slug'              => 'creme-brulee',
		'category'          => 'especiales',
		'regular_price'     => 85.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-ESP-001',
		'short_description' => 'Nuestra pieza más pedida: crema quemada al momento.',
		'description'       => 'Crème brûlée sobre laminado de la casa, quemada al momento.',
		'tags'              => array( 'dulce' ),
		'attributes'        => array( 'de-temporada' => 'no' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'creme-brulee',
		'image_alt'         => 'Crème brûlée sobre laminado, servida en plato de acero y rota con cuchara.',
		'seo_short'         => 'Crème brûlée sobre laminado — Haramara.',
		'meta_description'  => 'Crème brûlée sobre laminado de la casa, quemada al momento, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Pie matcha',
		'slug'              => 'pie-matcha',
		'category'          => 'especiales',
		'regular_price'     => 85.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-ESP-002',
		'short_description' => 'Pie de la casa con matcha.',
		'description'       => 'Pie de la casa con matcha.',
		'tags'              => array( 'dulce' ),
		'attributes'        => array( 'de-temporada' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'pie-matcha',
		'image_alt'         => 'Pie matcha de Haramara.',
		'seo_short'         => 'Pie matcha — Haramara.',
		'meta_description'  => 'Pie de la casa con matcha, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Guayabito',
		'slug'              => 'guayabito',
		'category'          => 'especiales',
		'regular_price'     => 65.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-ESP-003',
		'short_description' => 'La pieza de guayaba de la casa.',
		'description'       => 'El guayabito de la casa: pieza dulce de guayaba.',
		'tags'              => array( 'dulce' ),
		'attributes'        => array( 'de-temporada' => 'yes' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'guayabito',
		'image_alt'         => 'Guayabito de Haramara.',
		'seo_short'         => 'Guayabito — Haramara.',
		'meta_description'  => 'El guayabito de la casa, pieza dulce de guayaba, en Haramara, Cuernavaca.',
	),

	array(
		'name'              => 'Galletas de macadamia con toffee',
		'slug'              => 'galletas-macadamia-toffee',
		'category'          => 'especiales',
		'regular_price'     => 60.00,
		'price_is_estimate' => false,
		'sku'               => 'HARAMARA-ESP-004',
		'short_description' => 'Galleta de macadamia con toffee de la casa.',
		'description'       => 'Galletas de macadamia con toffee.',
		'tags'              => array( 'galleta' ),
		'attributes'        => array( 'sin-nueces' => 'no' ),
		'stock'             => 0,
		'manage_stock'      => false,
		'image_key'         => 'galletas-macadamia',
		'image_alt'         => 'Galletas de macadamia con toffee de Haramara.',
		'seo_short'         => 'Galletas de macadamia con toffee — Haramara.',
		'meta_description'  => 'Galletas de macadamia con toffee, en Haramara, Cuernavaca.',
	),

);
