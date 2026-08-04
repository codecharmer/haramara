<?php
/**
 * Custom block styles & pattern-friendly variations.
 *
 * @package Haramara
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register named block styles used across patterns. CSS for each lives in
 * assets/css/theme.css keyed by the generated `is-style-{name}` class.
 */
function haramara_register_block_styles(): void {
	$styles = array(
		'core/button' => array(
			array(
				'name'  => 'solid',
				'label' => __( 'Latón sólido', 'haramara' ),
			),
			array(
				'name'  => 'ghost',
				'label' => __( 'Contorno', 'haramara' ),
			),
			array(
				'name'  => 'link-underline',
				'label' => __( 'Enlace subrayado', 'haramara' ),
			),
		),
		'core/image'  => array(
			array(
				'name'  => 'framed',
				'label' => __( 'Enmarcada', 'haramara' ),
			),
		),
		'core/group'  => array(
			array(
				'name'  => 'hairline',
				'label' => __( 'Filete', 'haramara' ),
			),
		),
	);

	foreach ( $styles as $block => $variations ) {
		foreach ( $variations as $variation ) {
			register_block_style( $block, $variation );
		}
	}
}
add_action( 'init', 'haramara_register_block_styles' );
