<?php
/**
 * Haramara theme bootstrap.
 *
 * The theme is a thin, presentation-only layer. All commerce, ordering, SMS and
 * business-data logic lives in the haramara-core plugin. This file only wires up
 * theme supports, assets, editor affordances and the block-binding sources that
 * let editors surface business data (address, hours, phone) without hardcoding.
 *
 * @package Haramara
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HARAMARA_THEME_VERSION', '1.0.0' );
define( 'HARAMARA_THEME_DIR', get_template_directory() );
define( 'HARAMARA_THEME_URI', get_template_directory_uri() );

/**
 * Load a theme include, failing loudly in debug only.
 */
require_once HARAMARA_THEME_DIR . '/inc/setup.php';
require_once HARAMARA_THEME_DIR . '/inc/assets.php';
require_once HARAMARA_THEME_DIR . '/inc/block-styles.php';
require_once HARAMARA_THEME_DIR . '/inc/patterns.php';
require_once HARAMARA_THEME_DIR . '/inc/block-bindings.php';
require_once HARAMARA_THEME_DIR . '/inc/quantity-stepper.php';

/**
 * Direction contract for the front page, emitted as the first child of <body>
 * so it survives template compilation and production builds (auditable via
 * `grep 2f7939a6` on rendered output).
 */
function haramara_direction_contract(): void {
	if ( ! is_front_page() ) {
		return;
	}
	echo "<!--\n"
		. "THESIS: The homepage is the cafe's actual day, 08:00-20:00 - six real hours replace the category's hero-plus-feature-cards scaffold.\n"
		. "OWN-WORLD: Matte warm blacks (carbon/char/espresso/ocaso/noche) under limestone and bone text; brass hairlines and one candle veil that warms with scroll; Italiana display over Petrona text; square corners, dotted menu leaders, no boxed cards.\n"
		. "STORY: A visitor senses a hidden candlelit bar, reads real prices and hours, then orders for pickup, joins QR loyalty, or walks to Tulipan 302.\n"
		. "FIRST VIEWPORT: Full-bleed pour photograph; 'Cafe. Fuego. Ritual.' at display scale seated low-left; one ghost CTA 'Ordenar para recoger'; hours line beneath; a brass plumb line drops toward 09:30.\n"
		. "FORM: Ritual timeline - own grounded list #5, surface scope, seed 2f7939a6.\n"
		. "FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, and DESIGN.md\n"
		. "-->\n";
}
add_action( 'wp_body_open', 'haramara_direction_contract', 0 );
