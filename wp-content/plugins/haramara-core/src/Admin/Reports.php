<?php
/**
 * Reports.
 *
 * Lightweight, dependency-free operational reporting: revenue, order count, and
 * top products over a selectable range (hoy / 7 días / 30 días) plus a "Historial
 * SMS" table. Orders are read via wc_get_orders (HPOS-safe); the daily revenue
 * chart is inline SVG — no charting libraries.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Admin;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;
use Haramara\Core\Sms\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reports implements Bootable {

	private const SLUG = 'haramara-reportes';

	/** Statuses counted as realised revenue. Shared with Rest\PosRoutes. */
	public const PAID_STATUSES = array( 'processing', 'queued', 'preparing', 'ready', 'completed' );

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
	}

	public function register_menu(): void {
		add_submenu_page(
			Dashboard::SLUG,
			__( 'Reportes', 'haramara-core' ),
			__( 'Reportes', 'haramara-core' ),
			Activator::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( Activator::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'haramara-core' ) );
		}

		$ranges = array(
			'today' => __( 'Hoy', 'haramara-core' ),
			'7d'    => __( 'Últimos 7 días', 'haramara-core' ),
			'30d'   => __( 'Últimos 30 días', 'haramara-core' ),
		);
		$range  = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '7d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		if ( ! isset( $ranges[ $range ] ) ) {
			$range = '7d';
		}

		$report   = function_exists( 'wc_get_orders' ) ? $this->build_report( $range ) : $this->empty_report();
		$base_url = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap haramara-wrap haramara-reports">
			<h1 class="haramara-title">
				<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<?php esc_html_e( 'Reportes', 'haramara-core' ); ?>
			</h1>

			<div class="haramara-tabs" role="tablist">
				<?php foreach ( $ranges as $key => $label ) : ?>
					<a class="haramara-range-tab <?php echo $range === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $key, $base_url ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<div class="haramara-cards">
				<?php
				$this->stat_card( $this->money( $report['revenue'] ), __( 'Ingresos', 'haramara-core' ), 'dashicons-money-alt' );
				$this->stat_card( (string) $report['orders'], __( 'Pedidos', 'haramara-core' ), 'dashicons-cart' );
				$this->stat_card( $this->money( $report['avg'] ), __( 'Ticket promedio', 'haramara-core' ), 'dashicons-tag' );
				?>
			</div>

			<div class="haramara-grid">
				<section class="haramara-panel haramara-panel--wide">
					<h2 class="haramara-panel__title"><?php esc_html_e( 'Ingresos por día', 'haramara-core' ); ?></h2>
					<?php $this->render_bar_chart( $report['series'] ); ?>
				</section>

				<section class="haramara-panel">
					<h2 class="haramara-panel__title"><?php esc_html_e( 'Productos más vendidos', 'haramara-core' ); ?></h2>
					<?php $this->render_top_products( $report['top_products'] ); ?>
				</section>
			</div>

			<section class="haramara-panel haramara-panel--wide">
				<h2 class="haramara-panel__title"><?php esc_html_e( 'Historial SMS', 'haramara-core' ); ?></h2>
				<?php $this->render_sms_history(); ?>
			</section>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------- */
	/* Rendering */
	/* ---------------------------------------------------------------------- */

	private function stat_card( string $value, string $label, string $icon ): void {
		printf(
			'<div class="haramara-card"><span class="dashicons %1$s" aria-hidden="true"></span><span class="haramara-card__value">%2$s</span><span class="haramara-card__label">%3$s</span></div>',
			esc_attr( $icon ),
			wp_kses_post( $value ),
			esc_html( $label )
		);
	}

	/**
	 * Inline SVG bar chart — no dependencies.
	 *
	 * @param array<string,float> $series date => revenue
	 */
	private function render_bar_chart( array $series ): void {
		if ( empty( $series ) || array_sum( $series ) <= 0 ) {
			echo '<p class="haramara-empty">' . esc_html__( 'Sin datos para graficar en este rango.', 'haramara-core' ) . '</p>';
			return;
		}

		$max    = max( $series );
		$count  = count( $series );
		$width  = 640;
		$height = 220;
		$pad_b  = 34;
		$pad_t  = 12;
		$gap    = 8;
		$bar_w  = max( 4.0, ( $width - ( $gap * ( $count + 1 ) ) ) / $count );
		$plot_h = $height - $pad_b - $pad_t;

		echo '<div class="haramara-chart"><svg viewBox="0 0 ' . esc_attr( (string) $width ) . ' ' . esc_attr( (string) $height ) . '" role="img" preserveAspectRatio="xMidYMid meet" aria-label="' . esc_attr__( 'Ingresos por día', 'haramara-core' ) . '">';

		$i = 0;
		foreach ( $series as $date => $value ) {
			$bar_h = $max > 0 ? ( $value / $max ) * $plot_h : 0;
			$x     = $gap + $i * ( $bar_w + $gap );
			$y     = $pad_t + ( $plot_h - $bar_h );
			$label = wp_date( 'j/n', ( new \DateTimeImmutable( $date, Options::timezone() ) )->getTimestamp() );

			printf(
				'<rect class="haramara-bar" x="%1$s" y="%2$s" width="%3$s" height="%4$s" rx="3"><title>%5$s: %6$s</title></rect>',
				esc_attr( (string) round( $x, 2 ) ),
				esc_attr( (string) round( $y, 2 ) ),
				esc_attr( (string) round( $bar_w, 2 ) ),
				esc_attr( (string) round( max( 0, $bar_h ), 2 ) ),
				esc_attr( (string) $label ),
				esc_attr( $this->money_plain( $value ) )
			);
			printf(
				'<text class="haramara-bar-label" x="%1$s" y="%2$s" text-anchor="middle">%3$s</text>',
				esc_attr( (string) round( $x + $bar_w / 2, 2 ) ),
				esc_attr( (string) ( $height - 12 ) ),
				esc_html( (string) $label )
			);
			++$i;
		}
		echo '</svg></div>';
	}

	/**
	 * @param array<int,array{name:string,qty:int}> $top
	 */
	private function render_top_products( array $top ): void {
		if ( empty( $top ) ) {
			echo '<p class="haramara-empty">' . esc_html__( 'Sin ventas en este rango.', 'haramara-core' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped haramara-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Producto', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Unidades', 'haramara-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $top as $product ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td></tr>',
				esc_html( $product['name'] ),
				esc_html( (string) $product['qty'] )
			);
		}
		echo '</tbody></table>';
	}

	private function render_sms_history(): void {
		if ( ! class_exists( Logger::class ) || ! method_exists( Logger::class, 'recent' ) ) {
			echo '<p class="haramara-empty">' . esc_html__( 'El módulo de SMS aún no está disponible.', 'haramara-core' ) . '</p>';
			return;
		}
		$rows = Logger::recent( 50 );
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			echo '<p class="haramara-empty">' . esc_html__( 'Sin mensajes registrados todavía.', 'haramara-core' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped haramara-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Fecha', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Dirección', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Pedido', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Número', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Mensaje', 'haramara-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'haramara-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row       = (array) $row;
			$direction = (string) ( $row['direction'] ?? '' );
			$number    = 'inbound' === $direction ? ( $row['sender'] ?? '' ) : ( $row['recipient'] ?? '' );
			$order_id  = (int) ( $row['order_id'] ?? 0 );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_html( (string) ( $row['created_at'] ?? '' ) ),
				esc_html( 'inbound' === $direction ? __( 'Entrante', 'haramara-core' ) : __( 'Saliente', 'haramara-core' ) ),
				$order_id ? esc_html( '#' . $order_id ) : '—',
				esc_html( (string) $number ),
				esc_html( wp_trim_words( (string) ( $row['body'] ?? '' ), 18 ) ),
				esc_html( (string) ( $row['status'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------- */
	/* Data */
	/* ---------------------------------------------------------------------- */

	/**
	 * @return array{revenue:float,orders:int,avg:float,series:array<string,float>,top_products:array<int,array{name:string,qty:int}>}
	 */
	private function build_report( string $range ): array {
		$tz    = Options::timezone();
		$today = new \DateTimeImmutable( 'today', $tz );

		$days  = 'today' === $range ? 1 : ( '7d' === $range ? 7 : 30 );
		$start = $today->modify( sprintf( '-%d days', $days - 1 ) );

		// Seed the daily series with zeroes so empty days still render.
		$series = array();
		for ( $d = 0; $d < $days; $d++ ) {
			$series[ $start->modify( sprintf( '+%d days', $d ) )->format( 'Y-m-d' ) ] = 0.0;
		}

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => self::PAID_STATUSES,
				'date_created' => '>=' . $start->format( 'Y-m-d' ) . ' 00:00:00',
			)
		);
		$orders = is_array( $orders ) ? $orders : array();

		$revenue = 0.0;
		$count   = 0;
		$totals  = array();
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$key     = $created ? $created->date( 'Y-m-d' ) : $today->format( 'Y-m-d' );
			$total   = (float) $order->get_total();

			$revenue += $total;
			++$count;
			if ( isset( $series[ $key ] ) ) {
				$series[ $key ] += $total;
			}
			foreach ( $order->get_items() as $item ) {
				$name            = $item->get_name();
				$totals[ $name ] = ( $totals[ $name ] ?? 0 ) + (int) $item->get_quantity();
			}
		}

		arsort( $totals );
		$top = array();
		foreach ( array_slice( $totals, 0, 10, true ) as $name => $qty ) {
			$top[] = array(
				'name' => (string) $name,
				'qty'  => (int) $qty,
			);
		}

		return array(
			'revenue'      => $revenue,
			'orders'       => $count,
			'avg'          => $count > 0 ? $revenue / $count : 0.0,
			'series'       => $series,
			'top_products' => $top,
		);
	}

	/**
	 * @return array{revenue:float,orders:int,avg:float,series:array<string,float>,top_products:array<int,array{name:string,qty:int}>}
	 */
	private function empty_report(): array {
		return array(
			'revenue'      => 0.0,
			'orders'       => 0,
			'avg'          => 0.0,
			'series'       => array(),
			'top_products' => array(),
		);
	}

	private function money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return (string) wc_price( $amount );
		}
		return esc_html( number_format_i18n( $amount, 2 ) );
	}

	private function money_plain( float $amount ): string {
		if ( function_exists( 'wc_price' ) && function_exists( 'wp_strip_all_tags' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format_i18n( $amount, 2 );
	}
}
