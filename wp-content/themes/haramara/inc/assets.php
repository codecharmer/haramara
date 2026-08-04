<?php
/**
 * Front-end & editor asset loading.
 *
 * @package Haramara
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache-busting version: file mtime in debug, theme version in production.
 */
function haramara_asset_version( string $relative_path ): string {
	$file = HARAMARA_THEME_DIR . '/' . ltrim( $relative_path, '/' );
	if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && file_exists( $file ) ) {
		return (string) filemtime( $file );
	}
	return HARAMARA_THEME_VERSION;
}

/**
 * Enqueue front-end styles and the tiny progressive-enhancement script.
 */
function haramara_enqueue_assets(): void {
	wp_enqueue_style(
		'haramara-theme',
		HARAMARA_THEME_URI . '/assets/css/theme.css',
		array(),
		haramara_asset_version( 'assets/css/theme.css' )
	);

	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
		wp_enqueue_style(
			'haramara-woo',
			HARAMARA_THEME_URI . '/assets/css/woocommerce.css',
			array( 'haramara-theme' ),
			haramara_asset_version( 'assets/css/woocommerce.css' )
		);
	}

	// Deferred, dependency-free enhancement module (scroll reveal, header state).
	wp_enqueue_script(
		'haramara-enhance',
		HARAMARA_THEME_URI . '/assets/js/enhance.js',
		array(),
		haramara_asset_version( 'assets/js/enhance.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'haramara_enqueue_assets' );

/**
 * Editor-only styles so the block editor mirrors the front end.
 */
function haramara_enqueue_editor_assets(): void {
	wp_enqueue_style(
		'haramara-editor',
		HARAMARA_THEME_URI . '/assets/css/editor.css',
		array(),
		haramara_asset_version( 'assets/css/editor.css' )
	);
}
add_action( 'enqueue_block_assets', 'haramara_enqueue_editor_assets' );

/**
 * Preload the above-the-fold fonts to avoid FOUT on the hero.
 *
 * Italiana renders the h1; Petrona Roman renders the lede and controls. The
 * Petrona italic face is first needed further down (notes, quotes) and would
 * otherwise compete with the hero image for bandwidth, so it is not preloaded.
 */
function haramara_preload_fonts(): void {
	$fonts = array(
		'/assets/fonts/Italiana-Regular.woff2',
		'/assets/fonts/Petrona-Roman.woff2',
	);
	foreach ( $fonts as $font ) {
		if ( file_exists( HARAMARA_THEME_DIR . $font ) ) {
			printf(
				'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
				esc_url( HARAMARA_THEME_URI . $font )
			);
		}
	}
}
add_action( 'wp_head', 'haramara_preload_fonts', 1 );
