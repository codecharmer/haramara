<?php
/**
 * Plugin container & bootstrapper.
 *
 * Instantiates and boots each Bootable service. Services are declared in one
 * ordered list; missing classes are skipped (and surfaced in debug logs) so the
 * plugin degrades gracefully rather than fataling if a module is absent.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string,object> Resolved service instances, keyed by class name. */
	private array $services = array();

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * The ordered service map. Order matters: Options first (config), then domain
	 * services, then admin/presentation.
	 *
	 * @return class-string[]
	 */
	private function service_classes(): array {
		return array(
			// Configuration & settings.
			Setup\Options::class,
			Setup\Settings::class,
			Setup\Rewrites::class,
			Support\Assets::class,

			// Bilingual front end (es default · en under /en/). Must run
			// before WordPress parses the request.
			I18n\SiteLanguage::class,

			// WooCommerce foundation.
			Woo\Support::class,
			Woo\Inventory::class,
			Woo\ModifierFrontend::class,

			// Catálogo: grupos de modificadores. ModifierCart owns the
			// source-agnostic cart lifecycle (classic + Store API);
			// ModifierFrontend only renders the classic product-page fields.
			Catalog\ModifierGroups::class,
			Woo\ModifierCart::class,

			// Reserve & pickup ordering.
			Ordering\PickupScheduler::class,
			Ordering\OrderMeta::class,

			// Twilio SMS workflow (customer-facing only).
			Sms\Logger::class,
			Sms\OrderNotifications::class,

			// Push notifications (new orders → POS devices, status updates → customer devices).
			Push\NewOrderNotifier::class,
			Push\OrderStatusNotifier::class,

			// Lealtad Haramara (QR members, stamps).
			Loyalty\Members::class,
			Loyalty\WalletPass::class,
			Loyalty\WalletWebService::class,

			// SEO.
			Seo\MetaTags::class,
			Seo\SchemaGraph::class,

			// REST + CLI.
			Rest\Idempotency::class,
			Rest\Routes::class,
			Rest\AccessGate::class,
			Rest\AppRoutes::class,
			Rest\PosRoutes::class,
			Rest\CatalogRoutes::class,
			Rest\FiscalRoutes::class,
			Cli\Commands::class,

			// Admin operations.
			Admin\Dashboard::class,
			Admin\ProductionCalendar::class,
			Admin\Reports::class,
			Admin\Payments::class,
		);
	}

	/**
	 * Boot all available services exactly once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Setup\Activator::maybe_upgrade();

		load_plugin_textdomain( 'haramara-core', false, dirname( plugin_basename( HARAMARA_CORE_FILE ) ) . '/languages' );

		foreach ( $this->service_classes() as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( '[haramara-core] Service not found (skipped): %s', $class_name ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional operational logging.
				}
				continue;
			}
			$service = new $class_name();
			if ( $service instanceof Bootable ) {
				$service->boot();
			}
			$this->services[ $class_name ] = $service;
		}

		/**
		 * Fires after all core services have booted.
		 *
		 * @param Plugin $plugin The plugin container.
		 */
		do_action( 'haramara_core_booted', $this );
	}

	/**
	 * Fetch a booted service instance.
	 *
	 * @template T of object
	 * @param class-string<T> $class_name
	 * @return T|null
	 */
	public function get( string $class_name ): ?object {
		/** @var T|null */
		return $this->services[ $class_name ] ?? null;
	}
}
