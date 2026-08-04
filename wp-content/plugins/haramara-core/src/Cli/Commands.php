<?php
/**
 * WP-CLI commands + admin one-shot for content installation.
 *
 * Registers the `wp haramara` command family and wires the Settings-screen
 * action (`haramara_run_content_install`) to the installer. Nothing is seeded
 * automatically on a normal request: activation only raises the
 * `haramara_needs_content_install` flag, and this class surfaces an admin notice
 * with a one-click button so the operator decides when to run the install.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Cli;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Installer;
use Haramara\Core\Setup\MediaImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Commands implements Bootable {

	/** admin-post action name for the notice button. */
	private const ADMIN_ACTION = 'haramara_install_content';

	public function boot(): void {
		// Settings screen (and the notice button) fire this action.
		add_action( 'haramara_run_content_install', array( $this, 'handle_run_content_install' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'render_install_notice' ) );
			add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_admin_post' ) );
		}

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'haramara install', array( $this, 'install' ) );
			\WP_CLI::add_command( 'haramara seed-products', array( $this, 'seed_products' ) );
			\WP_CLI::add_command( 'haramara install-pages', array( $this, 'install_pages' ) );
			\WP_CLI::add_command( 'haramara import-media', array( $this, 'import_media' ) );
			\WP_CLI::add_command( 'haramara reset', array( $this, 'reset' ) );
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Action + admin one-shot */
	/* ---------------------------------------------------------------------- */

	/**
	 * Hooked to `haramara_run_content_install`. Runs the full install.
	 *
	 * @param array<string,mixed> $args Optional installer args.
	 */
	public function handle_run_content_install( array $args = array() ): void {
		Installer::install_all( is_array( $args ) ? $args : array() );
	}

	/**
	 * Admin notice prompting a one-click content install while the flag is set.
	 */
	public function render_install_notice(): void {
		if ( ! get_option( 'haramara_needs_content_install' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg( 'action', self::ADMIN_ACTION, admin_url( 'admin-post.php' ) ),
			self::ADMIN_ACTION
		);

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Haramara está lista para instalar el contenido inicial (productos, páginas y menús).', 'haramara-core' );
		echo ' <a class="button button-primary" href="' . esc_url( $url ) . '">';
		echo esc_html__( 'Instalar contenido de Haramara', 'haramara-core' );
		echo '</a></p></div>';
	}

	/**
	 * Handle the notice button: verify nonce + capability, then run the install.
	 */
	public function handle_admin_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'haramara-core' ) );
		}
		check_admin_referer( self::ADMIN_ACTION );

		$report = Installer::install_all();

		$referer  = wp_get_referer();
		$redirect = add_query_arg(
			'haramara_installed',
			! empty( $report['ok'] ) ? '1' : '0',
			$referer ? $referer : admin_url()
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ---------------------------------------------------------------------- */
	/* WP-CLI commands */
	/* ---------------------------------------------------------------------- */

	/**
	 * Install all Haramara seed content: options, categories, products, pages,
	 * navigation, and WooCommerce core pages. Idempotent.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite existing products and pages instead of skipping them.
	 *
	 * [--skip-media]
	 * : Do not import featured images or generate placeholders.
	 *
	 * ## EXAMPLES
	 *
	 *     # First-time install
	 *     wp haramara install
	 *
	 *     # Re-seed, overwriting existing content
	 *     wp haramara install --force
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function install( array $args, array $assoc_args ): void {
		$report = Installer::install_all(
			array(
				'force'      => isset( $assoc_args['force'] ),
				'skip_media' => isset( $assoc_args['skip-media'] ),
			)
		);

		$this->print_report( $report );
		\WP_CLI::success( 'Instalación de contenido completada.' );
	}

	/**
	 * Seed only the product categories and products.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite existing products instead of skipping them.
	 *
	 * [--skip-media]
	 * : Do not import featured images or generate placeholders.
	 *
	 * ## EXAMPLES
	 *
	 *     wp haramara seed-products
	 *     wp haramara seed-products --force
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function seed_products( array $args, array $assoc_args ): void {
		if ( ! Installer::has_woocommerce() ) {
			\WP_CLI::error( 'WooCommerce no está activo; no se pueden sembrar productos.' );
			return;
		}

		$cats     = Installer::install_categories();
		$products = Installer::install_products(
			$cats['map'],
			isset( $assoc_args['force'] ),
			isset( $assoc_args['skip-media'] )
		);

		\WP_CLI::line( sprintf( 'Categorías: %d creadas, %d existentes.', (int) $cats['report']['created'], (int) $cats['report']['existing'] ) );
		\WP_CLI::line(
			sprintf(
				'Productos: %d creados, %d actualizados, %d omitidos, %d fallidos (de %d).',
				(int) $products['created'],
				(int) $products['updated'],
				(int) $products['skipped'],
				(int) $products['failed'],
				(int) ( $products['total'] ?? 0 )
			)
		);
		foreach ( (array) ( $products['by_category'] ?? array() ) as $slug => $count ) {
			\WP_CLI::line( sprintf( '  · %s: %d', $slug, (int) $count ) );
		}
		\WP_CLI::success( 'Productos sembrados.' );
	}

	/**
	 * Install only the site pages and set the static front page.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite existing pages instead of skipping them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp haramara install-pages
	 *     wp haramara install-pages --force
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function install_pages( array $args, array $assoc_args ): void {
		$report = Installer::install_pages( isset( $assoc_args['force'] ) );
		\WP_CLI::line(
			sprintf(
				'Páginas: %d creadas, %d actualizadas, %d omitidas, %d fallidas.',
				(int) $report['created'],
				(int) $report['updated'],
				(int) $report['skipped'],
				(int) $report['failed']
			)
		);
		if ( (int) $report['front_page'] > 0 ) {
			\WP_CLI::line( sprintf( 'Página de inicio: #%d', (int) $report['front_page'] ) );
		}
		\WP_CLI::success( 'Páginas instaladas.' );
	}

	/**
	 * Import (or re-import) every product image, regenerating featured images
	 * and responsive siblings. Drops in real photos from data/media/source/.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Clear the cached image map and re-import every key from scratch.
	 *
	 * ## EXAMPLES
	 *
	 *     # After dropping real photos into data/media/source/
	 *     wp haramara import-media
	 *
	 *     # Force a full re-import
	 *     wp haramara import-media --force
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function import_media( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['force'] ) ) {
			delete_option( MediaImporter::CACHE_OPTION );
		}

		$keys = Installer::media_keys();
		if ( ! $keys ) {
			\WP_CLI::warning( 'No se encontraron claves de imagen en data/products.php.' );
			return;
		}

		$result   = MediaImporter::ensure_all( $keys );
		$imported = count( array_filter( $result ) );
		$failed   = count( $result ) - $imported;

		// Re-bind featured images to their products in case IDs changed.
		if ( Installer::has_woocommerce() ) {
			Installer::install_products( Installer::install_categories()['map'], true, false );
		}

		\WP_CLI::line( sprintf( 'Imágenes procesadas: %d importadas, %d fallidas.', $imported, $failed ) );
		\WP_CLI::success( 'Importación de medios completada.' );
	}

	/**
	 * Remove all Haramara-seeded content (products, pages, navigation, media,
	 * categories). Destructive and irreversible.
	 *
	 * ## OPTIONS
	 *
	 * --yes
	 * : Required confirmation. Without it the command aborts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp haramara reset --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function reset( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Esta acción es destructiva. Vuelve a ejecutar con --yes para confirmar.' );
			return;
		}

		$report = Installer::reset();
		\WP_CLI::line(
			sprintf(
				'Eliminados — productos: %d, páginas: %d, navegación: %d, medios: %d, categorías: %d.',
				(int) $report['products'],
				(int) $report['pages'],
				(int) $report['navigation'],
				(int) $report['media'],
				(int) $report['categories']
			)
		);
		\WP_CLI::success( 'Contenido de Haramara eliminado.' );
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers */
	/* ---------------------------------------------------------------------- */

	/**
	 * @param array<string,mixed> $report
	 */
	private function print_report( array $report ): void {
		if ( ! ( defined( 'WP_CLI' ) && \WP_CLI ) ) {
			return;
		}

		\WP_CLI::line(
			sprintf(
				'WooCommerce: %s · Tema de bloques: %s',
				! empty( $report['woocommerce'] ) ? 'sí' : 'no',
				! empty( $report['block_theme'] ) ? 'sí' : 'no'
			)
		);

		if ( isset( $report['categories']['created'] ) ) {
			\WP_CLI::line(
				sprintf(
					'Categorías: %d creadas, %d existentes.',
					(int) $report['categories']['created'],
					(int) $report['categories']['existing']
				)
			);
		}

		if ( isset( $report['products']['created'] ) ) {
			$p = $report['products'];
			\WP_CLI::line(
				sprintf(
					'Productos: %d creados, %d actualizados, %d omitidos, %d fallidos (de %d). Imágenes: %d.',
					(int) $p['created'],
					(int) $p['updated'],
					(int) $p['skipped'],
					(int) $p['failed'],
					(int) ( $p['total'] ?? 0 ),
					(int) ( $p['media'] ?? 0 )
				)
			);
		}

		if ( isset( $report['pages']['created'] ) ) {
			$pg = $report['pages'];
			\WP_CLI::line(
				sprintf(
					'Páginas: %d creadas, %d actualizadas, %d omitidas. Inicio: #%d.',
					(int) $pg['created'],
					(int) $pg['updated'],
					(int) $pg['skipped'],
					(int) ( $pg['front_page'] ?? 0 )
				)
			);
		}

		if ( isset( $report['navigation']['primary_id'] ) ) {
			\WP_CLI::line(
				sprintf(
					'Navegación: principal #%d, pie #%d.',
					(int) $report['navigation']['primary_id'],
					(int) $report['navigation']['footer_id']
				)
			);
		}

		\WP_CLI::line( sprintf( 'Páginas de WooCommerce: %s.', ! empty( $report['wc_pages'] ) ? 'configuradas' : 'omitidas' ) );
	}
}
