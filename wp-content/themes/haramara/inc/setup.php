<?php
/**
 * Theme setup: supports, image sizes, nav, i18n.
 *
 * @package Haramara
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register theme supports. Block themes get most defaults from theme.json;
 * this covers the runtime supports theme.json cannot express.
 */
function haramara_setup(): void {
	load_theme_textdomain( 'haramara', HARAMARA_THEME_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 96,
			'width'       => 320,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	// Editor stylesheet so the canvas matches the front end.
	add_editor_style( array( 'assets/css/theme.css', 'assets/css/editor.css' ) );

	// Purposeful, art-directed crops for the bakery imagery pipeline.
	add_image_size( 'haramara-hero', 2000, 1200, true );
	add_image_size( 'haramara-card', 800, 800, true );      // square product/pattern cards
	add_image_size( 'haramara-card-tall', 800, 1040, true ); // 3:4 editorial
	add_image_size( 'haramara-wide', 1600, 900, true );      // 16:9 feature strips
	add_image_size( 'haramara-thumb', 300, 300, true );
}
add_action( 'after_setup_theme', 'haramara_setup' );

/**
 * Human-readable labels for the custom image sizes in the media UI.
 *
 * @param array<string,string> $sizes Registered sizes.
 * @return array<string,string>
 */
function haramara_image_size_names( array $sizes ): array {
	return array_merge(
		$sizes,
		array(
			'haramara-hero'      => __( 'Haramara — Hero', 'haramara' ),
			'haramara-card'      => __( 'Haramara — Card (1:1)', 'haramara' ),
			'haramara-card-tall' => __( 'Haramara — Card (3:4)', 'haramara' ),
			'haramara-wide'      => __( 'Haramara — Wide (16:9)', 'haramara' ),
		)
	);
}
add_filter( 'image_size_names_choose', 'haramara_image_size_names' );

/**
 * Add a modest set of body classes used by runtime CSS hooks.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function haramara_body_classes( array $classes ): array {
	if ( is_front_page() ) {
		$classes[] = 'is-front-page';
	}
	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() ) ) {
		$classes[] = 'is-commerce';
	}
	return $classes;
}
add_filter( 'body_class', 'haramara_body_classes' );
