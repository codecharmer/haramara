<?php
/**
 * Admin asset loader.
 *
 * Enqueues the Haramara admin stylesheet + script ONLY on Haramara operations
 * screens and the WooCommerce order screens where pickup meta is surfaced. Keeps
 * the rest of wp-admin untouched.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Support;

use Haramara\Core\Admin\Dashboard;
use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets implements Bootable {

	private const HANDLE = 'haramara-admin';

	public function boot(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Resolve the shared parent menu slug without hard-coupling to the Admin module.
	 */
	private function parent_slug(): string {
		if ( class_exists( Dashboard::class ) && defined( Dashboard::class . '::SLUG' ) ) {
			return (string) Dashboard::SLUG;
		}
		return 'haramara';
	}

	/**
	 * Whether the current admin screen is one we should style.
	 */
	private function is_haramara_screen( string $hook ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : $hook;

		// Any Haramara menu/submenu page (all slugs are prefixed with the parent slug).
		if ( false !== strpos( $id, $this->parent_slug() ) || false !== strpos( $hook, $this->parent_slug() ) ) {
			return true;
		}

		// WooCommerce order screens (HPOS list/edit + legacy post-type screens).
		$woo_screens = array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' );
		return in_array( $id, $woo_screens, true );
	}

	/**
	 * Whether the current screen is the Ajustes settings page (needs the media picker).
	 */
	private function is_settings_screen( string $hook ): bool {
		return false !== strpos( $hook, $this->parent_slug() . '-ajustes' );
	}

	public function enqueue( string $hook ): void {
		if ( ! $this->is_haramara_screen( $hook ) ) {
			return;
		}

		$ver = defined( 'HARAMARA_CORE_VERSION' ) ? HARAMARA_CORE_VERSION : '1.0.0';
		$url = defined( 'HARAMARA_CORE_URL' ) ? HARAMARA_CORE_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/haramara-core.php' );

		wp_enqueue_style( self::HANDLE, $url . 'assets/css/admin.css', array(), $ver );
		wp_enqueue_script( self::HANDLE, $url . 'assets/js/admin.js', array(), $ver, true );

		// The Settings SEO tab uses the WP media modal for image pickers.
		if ( $this->is_settings_screen( $hook ) && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_localize_script(
			self::HANDLE,
			'haramaraAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'transition' => wp_create_nonce( 'haramara_order_transition' ),
					'testSms'    => wp_create_nonce( 'haramara_test_sms' ),
				),
				'caps'    => array(
					'manage' => current_user_can( Activator::CAP ),
				),
				'strings' => array(
					'confirmTransition' => __( '¿Actualizar el estado de este pedido?', 'haramara-core' ),
					'working'           => __( 'Actualizando…', 'haramara-core' ),
					'sending'           => __( 'Enviando…', 'haramara-core' ),
					'sent'              => __( 'Mensaje de prueba enviado.', 'haramara-core' ),
					'error'             => __( 'Ocurrió un error. Inténtalo de nuevo.', 'haramara-core' ),
					'confirmInstall'    => __( '¿Instalar el contenido de demostración? Esto puede crear productos y páginas.', 'haramara-core' ),
					'selectImage'       => __( 'Seleccionar imagen', 'haramara-core' ),
					'useImage'          => __( 'Usar esta imagen', 'haramara-core' ),
					'remove'            => __( 'Quitar', 'haramara-core' ),
				),
			)
		);
	}
}
