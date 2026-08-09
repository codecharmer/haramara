<?php
/**
 * Plugin Name:       Haramara Core
 * Plugin URI:        https://haramara.mx/
 * Description:        Commerce, reserve-&-pickup ordering, Stripe, Twilio SMS workflow, SEO schema, and the admin operations dashboard for Haramara Café. All business logic lives here — the theme stays presentation-only.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * Author:            Haramara Café — Agency Build
 * Author URI:        https://haramara.mx/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       haramara-core
 * Domain Path:       /languages
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HARAMARA_CORE_VERSION', '1.1.0' );
define( 'HARAMARA_CORE_FILE', __FILE__ );
define( 'HARAMARA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'HARAMARA_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'HARAMARA_CORE_MIN_PHP', '8.3' );

/**
 * Prefer Composer's autoloader; fall back to a lightweight PSR-4 loader so the
 * plugin runs on a plain deploy without a `composer install` step.
 */
if ( is_readable( HARAMARA_CORE_DIR . 'vendor/autoload.php' ) ) {
	require_once HARAMARA_CORE_DIR . 'vendor/autoload.php';
} else {
	require_once HARAMARA_CORE_DIR . 'src/Support/Autoloader.php';
	Support\Autoloader::register( 'Haramara\\Core\\', HARAMARA_CORE_DIR . 'src/' );
}

/**
 * Hard requirement guard: bail with an admin notice on unsupported PHP.
 */
if ( version_compare( PHP_VERSION, HARAMARA_CORE_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			printf(
			/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'Haramara Core requiere PHP %1$s o superior. Este servidor ejecuta %2$s.', 'haramara-core' ),
				esc_html( HARAMARA_CORE_MIN_PHP ),
				esc_html( PHP_VERSION )
			);
			echo '</p></div>';
		}
	);
	return;
}

// Activation / deactivation lifecycle.
register_activation_hook( __FILE__, array( Setup\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Setup\Activator::class, 'deactivate' ) );

/**
 * Boot the plugin once WooCommerce and the rest of the plugin stack are ready.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	20
);

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
