<?php
/**
 * Haramara operations dashboard.
 *
 * Registers the top-level "Haramara" admin menu and renders the at-a-glance
 * operations landing screen (today's pickups, orders needing prep, ready count,
 * low stock, recent SMS). Owns the shared parent slug that the other admin
 * modules hook their submenus onto, plus the quick-action AJAX endpoint used to
 * transition an order's status straight from the dashboard.
 *
 * Every cross-module call is guarded so the screen never fatals while a sibling
 * module is still being built.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Admin;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Ordering\OrderBoard;
use Haramara\Core\Ordering\StatusTransitions;
use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;
use Haramara\Core\Sms\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dashboard implements Bootable {

	/** Shared parent menu slug — sibling admin modules hang their submenus off this. */
	public const SLUG = 'haramara';

	/** Order statuses that still require baker attention. */
	private const PREP_STATUSES = array( 'processing', 'queued', 'preparing' );

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
		add_action( 'wp_ajax_haramara_order_transition', array( $this, 'ajax_transition' ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Menu */
	/* ---------------------------------------------------------------------- */

	public function register_menu(): void {
		add_menu_page(
			__( 'Haramara — Operaciones', 'haramara-core' ),
			__( 'Haramara', 'haramara-core' ),
			Activator::CAP,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-store',
			25
		);

		// Rename the auto-generated first submenu from the menu title to "Resumen".
		add_submenu_page(
			self::SLUG,
			__( 'Resumen de operaciones', 'haramara-core' ),
			__( 'Resumen', 'haramara-core' ),
			Activator::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Landing screen */
	/* ---------------------------------------------------------------------- */

	public function render(): void {
		if ( ! current_user_can( Activator::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'haramara-core' ) );
		}

		$wc_ready      = function_exists( 'wc_get_orders' );
		$today         = ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d' );
		$today_pickups = $wc_ready ? OrderBoard::orders_for_date( $today ) : array();
		$needs_prep    = $wc_ready ? $this->count_orders( self::PREP_STATUSES ) : 0;
		$ready_count   = $wc_ready ? $this->count_orders( array( 'ready' ) ) : 0;
		$low_stock     = $wc_ready ? $this->low_stock_products() : array();
		$recent_sms    = $this->recent_sms();
		$today_display = wp_date( 'l, j \d\e F', ( new \DateTimeImmutable( $today, Options::timezone() ) )->getTimestamp() );
		?>
		<div class="wrap haramara-wrap haramara-dashboard">
			<h1 class="haramara-title">
				<span class="dashicons dashicons-store" aria-hidden="true"></span>
				<?php esc_html_e( 'Panel de operaciones', 'haramara-core' ); ?>
			</h1>
			<p class="haramara-subtitle"><?php echo esc_html( ucfirst( (string) $today_display ) ); ?></p>

			<?php if ( ! $wc_ready ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'WooCommerce aún no está disponible; algunos indicadores aparecen vacíos.', 'haramara-core' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="haramara-cards">
				<?php
				$this->stat_card( (string) count( $today_pickups ), __( 'Recolecciones hoy', 'haramara-core' ), 'dashicons-clock' );
				$this->stat_card( (string) $needs_prep, __( 'Pedidos por preparar', 'haramara-core' ), 'dashicons-hammer', 'is-attention' );
				$this->stat_card( (string) $ready_count, __( 'Listos para recoger', 'haramara-core' ), 'dashicons-yes-alt', 'is-good' );
				$this->stat_card( (string) count( $low_stock ), __( 'Productos con bajo stock', 'haramara-core' ), 'dashicons-warning', $low_stock ? 'is-attention' : '' );
				?>
			</div>

			<div class="haramara-grid">
				<section class="haramara-panel haramara-panel--wide">
					<h2 class="haramara-panel__title"><?php esc_html_e( 'Recolecciones de hoy', 'haramara-core' ); ?></h2>
					<?php $this->render_pickups_table( $today_pickups ); ?>
				</section>

				<section class="haramara-panel">
					<h2 class="haramara-panel__title"><?php esc_html_e( 'Bajo stock', 'haramara-core' ); ?></h2>
					<?php $this->render_low_stock( $low_stock ); ?>
				</section>

				<section class="haramara-panel haramara-panel--wide">
					<h2 class="haramara-panel__title"><?php esc_html_e( 'SMS recientes', 'haramara-core' ); ?></h2>
					<?php $this->render_recent_sms( $recent_sms ); ?>
				</section>
			</div>
		</div>
		<?php
	}

	private function stat_card( string $value, string $label, string $icon, string $modifier = '' ): void {
		printf(
			'<div class="haramara-card %1$s"><span class="dashicons %2$s" aria-hidden="true"></span><span class="haramara-card__value">%3$s</span><span class="haramara-card__label">%4$s</span></div>',
			esc_attr( $modifier ),
			esc_attr( $icon ),
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private function render_pickups_table( array $orders ): void {
		if ( empty( $orders ) ) {
			echo '<p class="haramara-empty">' . esc_html__( 'No hay recolecciones programadas para hoy.', 'haramara-core' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped haramara-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Hora', 'haramara-core' ); ?></th>
					<th><?php esc_html_e( 'Pedido', 'haramara-core' ); ?></th>
					<th><?php esc_html_e( 'Cliente', 'haramara-core' ); ?></th>
					<th><?php esc_html_e( 'Artículos', 'haramara-core' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'haramara-core' ); ?></th>
					<th class="haramara-col-actions"><?php esc_html_e( 'Acciones', 'haramara-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) : ?>
				<?php
				$order_id = $order->get_id();
				$slot     = (string) $order->get_meta( '_haramara_pickup_slot' );
				$status   = $order->get_status();
				$items    = array();
				foreach ( $order->get_items() as $item ) {
					$items[] = $item->get_quantity() . '× ' . $item->get_name();
				}
				$customer = trim( $order->get_formatted_billing_full_name() );
				?>
				<tr id="haramara-order-<?php echo esc_attr( (string) $order_id ); ?>">
					<td><?php echo esc_html( '' !== $slot ? $slot : '—' ); ?></td>
					<td><a href="<?php echo esc_url( (string) get_edit_post_link( $order_id ) ); ?>">#<?php echo esc_html( (string) $order_id ); ?></a></td>
					<td><?php echo esc_html( '' !== $customer ? $customer : __( 'Invitado', 'haramara-core' ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $items ) ); ?></td>
					<td><span class="haramara-status haramara-status--<?php echo esc_attr( $status ); ?>" data-status-label><?php echo esc_html( $this->status_label( $status ) ); ?></span></td>
					<td class="haramara-col-actions">
						<?php $this->quick_action_button( $order_id, 'preparing', __( 'Preparando', 'haramara-core' ) ); ?>
						<?php $this->quick_action_button( $order_id, 'ready', __( 'Listo', 'haramara-core' ) ); ?>
						<?php $this->quick_action_button( $order_id, 'completed', __( 'Entregado', 'haramara-core' ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function quick_action_button( int $order_id, string $status, string $label ): void {
		printf(
			'<button type="button" class="button button-small haramara-quick-action" data-order="%1$s" data-status="%2$s">%3$s</button> ',
			esc_attr( (string) $order_id ),
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	private function render_low_stock( array $products ): void {
		if ( empty( $products ) ) {
			echo '<p class="haramara-empty">' . esc_html__( 'Todo el inventario está en buen nivel.', 'haramara-core' ) . '</p>';
			return;
		}
		echo '<ul class="haramara-list">';
		foreach ( $products as $product ) {
			printf(
				'<li><a href="%1$s">%2$s</a><span class="haramara-badge">%3$s</span></li>',
				esc_url( (string) get_edit_post_link( $product->get_id() ) ),
				esc_html( $product->get_name() ),
				esc_html( sprintf( /* translators: %d: stock quantity */ __( '%d en stock', 'haramara-core' ), (int) $product->get_stock_quantity() ) )
			);
		}
		echo '</ul>';
	}

	private function render_recent_sms( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p class="haramara-empty">' . esc_html__( 'Sin mensajes registrados todavía.', 'haramara-core' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped haramara-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Fecha', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Dirección', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Número', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Mensaje', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'haramara-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row       = (array) $row;
			$direction = (string) ( $row['direction'] ?? '' );
			$number    = 'inbound' === $direction ? ( $row['sender'] ?? '' ) : ( $row['recipient'] ?? '' );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) ( $row['created_at'] ?? '' ) ),
				esc_html( 'inbound' === $direction ? __( 'Entrante', 'haramara-core' ) : __( 'Saliente', 'haramara-core' ) ),
				esc_html( (string) $number ),
				esc_html( wp_trim_words( (string) ( $row['body'] ?? '' ), 14 ) ),
				esc_html( (string) ( $row['status'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------- */
	/* Data helpers (HPOS-safe via wc_get_orders / wc_get_products) */
	/* ---------------------------------------------------------------------- */

	/**
	 * @param string[] $statuses
	 */
	private function count_orders( array $statuses ): int {
		$ids = wc_get_orders(
			array(
				'limit'  => -1,
				'status' => $statuses,
				'return' => 'ids',
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * @return array<int,\WC_Product>
	 */
	private function low_stock_products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$products  = wc_get_products(
			array(
				'limit'        => 100,
				'status'       => 'publish',
				'manage_stock' => true,
			)
		);
		$products  = is_array( $products ) ? $products : array();
		$low       = array_filter(
			$products,
			static function ( $product ) use ( $threshold ): bool {
				$qty = $product->get_stock_quantity();
				return null !== $qty && (int) $qty <= $threshold;
			}
		);
		usort( $low, static fn( $a, $b ) => (int) $a->get_stock_quantity() <=> (int) $b->get_stock_quantity() );
		return array_slice( array_values( $low ), 0, 12 );
	}

	/**
	 * @return array<int,mixed>
	 */
	private function recent_sms(): array {
		if ( class_exists( Logger::class ) && method_exists( Logger::class, 'recent' ) ) {
			$rows = Logger::recent( 6 );
			return is_array( $rows ) ? $rows : array();
		}
		return array();
	}

	private function status_label( string $status ): string {
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return (string) wc_get_order_status_name( $status );
		}
		return ucfirst( str_replace( '-', ' ', $status ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Quick-action AJAX */
	/* ---------------------------------------------------------------------- */

	/**
	 * Transition an order to preparing/ready/completed.
	 *
	 * Nonce action: `haramara_order_transition` (field `nonce`).
	 */
	public function ajax_transition(): void {
		check_ajax_referer( 'haramara_order_transition', 'nonce' );

		if ( ! current_user_can( Activator::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'haramara-core' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$target   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! function_exists( 'wc_get_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce no está disponible.', 'haramara-core' ) ), 500 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Pedido no encontrado.', 'haramara-core' ) ), 404 );
		}

		$result = StatusTransitions::apply( $order, $target, __( 'Estado actualizado desde el panel de Haramara.', 'haramara-core' ) );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $data['status'] ?? 400 ) );
		}

		wp_send_json_success(
			array(
				'order_id' => $order_id,
				'status'   => $target,
				'label'    => $this->status_label( $target ),
			)
		);
	}
}
