<?php
/**
 * Block pattern categories.
 *
 * Patterns themselves are auto-registered from /patterns/*.php by WordPress.
 * Here we only declare the categories they slot into.
 *
 * @package Haramara
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Haramara pattern categories.
 */
function haramara_register_pattern_categories(): void {
	$categories = array(
		'haramara-hero'     => __( 'Haramara — Portadas', 'haramara' ),
		'haramara-dia'      => __( 'Haramara — El día (portada)', 'haramara' ),
		'haramara-page'     => __( 'Haramara — Secciones de página', 'haramara' ),
		'haramara-commerce' => __( 'Haramara — Tienda', 'haramara' ),
		'haramara-cta'      => __( 'Haramara — Llamados a la acción', 'haramara' ),
		'haramara-parts'    => __( 'Haramara — Encabezado y pie', 'haramara' ),
	);

	foreach ( $categories as $slug => $label ) {
		register_block_pattern_category( $slug, array( 'label' => $label ) );
	}
}
add_action( 'init', 'haramara_register_pattern_categories' );
