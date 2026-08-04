<?php
/**
 * Reserve-&-pickup scheduler — the core ordering feature.
 *
 * Collects and validates a pickup DATE and TIME SLOT at checkout (classic and
 * block), enforcing open days, lead time, look-ahead window, blackout dates, and
 * per-slot capacity. All scheduling config comes from Options::pickup(); nothing
 * is hardcoded.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PickupScheduler implements Bootable {

	/** Canonical order meta keys (shared with OrderMeta, REST, SMS). */
	public const META_DATE  = '_haramara_pickup_date';
	public const META_SLOT  = '_haramara_pickup_slot';
	public const META_LABEL = '_haramara_pickup_label';

	/** Classic checkout POST field names. */
	private const FIELD_DATE = 'haramara_pickup_date';
	private const FIELD_SLOT = 'haramara_pickup_slot';

	/** Block checkout additional-field ids (namespace haramara/pickup). */
	private const BLOCK_DATE = 'haramara/pickup-date';
	private const BLOCK_SLOT = 'haramara/pickup-slot';

	/** Transient prefix for per-date slot-count caches (+ Y-m-d). */
	private const COUNTS_TRANSIENT = 'haramara_slot_counts_';

	/**
	 * In-request memo of slot counts per date. valid_dates() walks the whole
	 * look-ahead window, so without this each request re-queries per day.
	 *
	 * @var array<string,array<string,int>>
	 */
	private static array $counts_memo = array();

	/** Order statuses that occupy slot capacity. */
	private const OCCUPYING_STATUSES = array(
		'wc-pending',
		'wc-processing',
		'wc-on-hold',
		'wc-preparing',
		'wc-ready',
		'wc-completed',
	);

	public function boot(): void {
		// Classic checkout.
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_classic_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_fields' ), 10, 2 );

		// Block checkout (WooCommerce Blocks additional checkout fields API).
		// NB: not `woocommerce_init` — that fires at init:0, BEFORE WooCommerce
		// registers its order types (init:5). Field option building queries
		// orders (slot capacity), and wc_get_orders() before type registration
		// makes WC_Order_Factory throw "classname not found" — a site-wide 500
		// as soon as the first order with pickup meta exists.
		add_action( 'init', array( $this, 'register_block_fields' ), 20 );
		add_action( 'woocommerce_blocks_validate_location_order_fields', array( $this, 'validate_block_fields' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'normalize_block_fields' ) );

		// Slot-capacity cache invalidation: any order lifecycle event flushes
		// the cached counts for that order's pickup date, so the transient can
		// be long-lived without ever serving a stale capacity decision.
		add_action( 'woocommerce_new_order', array( $this, 'flush_slot_counts' ) );
		add_action( 'woocommerce_update_order', array( $this, 'flush_slot_counts' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'flush_slot_counts' ) );
	}

	/**
	 * Drop the cached slot counts for the date an order occupies.
	 *
	 * @param int|mixed $order_id Order ID from any of the lifecycle hooks.
	 */
	public function flush_slot_counts( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$date = (string) $order->get_meta( self::META_DATE );
		if ( '' === $date ) {
			return;
		}
		unset( self::$counts_memo[ $date ] );
		delete_transient( self::COUNTS_TRANSIENT . $date );
	}

	/*
	====================================================================== */
	/* Public static helpers (reused by REST + admin) */
	/* ====================================================================== */

	/**
	 * Valid pickup dates within the look-ahead window that still have at least one
	 * bookable slot.
	 *
	 * @return array<int,array{date:string,weekday:int,label:string}>
	 */
	public static function valid_dates(): array {
		$cfg = Options::pickup();
		$tz  = Options::timezone();
		$max = max( 1, (int) ( $cfg['max_days_ahead'] ?? 21 ) );

		$today = new \DateTimeImmutable( 'today', $tz );
		$dates = array();

		for ( $i = 0; $i <= $max; $i++ ) {
			$day = $today->modify( "+{$i} days" );
			$ymd = $day->format( 'Y-m-d' );

			if ( ! self::is_open_date( $ymd ) ) {
				continue;
			}
			// Only surface dates that still have availability after lead-time /
			// capacity filtering.
			if ( empty( self::available_slots( $ymd ) ) ) {
				continue;
			}

			$dates[] = array(
				'date'    => $ymd,
				'weekday' => (int) $day->format( 'w' ),
				'label'   => self::format_date_label( $day ),
			);
		}

		return $dates;
	}

	/**
	 * Bookable slots for a given date: within business hours, past the lead time,
	 * and with remaining capacity.
	 *
	 * @return array<int,array{slot:string,label:string,remaining:int}>
	 */
	public static function available_slots( string $date ): array {
		$date = self::sanitize_date( $date );
		if ( '' === $date || ! self::is_open_date( $date ) ) {
			return array();
		}

		$cfg      = Options::pickup();
		$tz       = Options::timezone();
		$lead     = max( 0, (int) ( $cfg['lead_time_hours'] ?? 24 ) );
		$capacity = max( 1, (int) ( $cfg['slot_capacity'] ?? 8 ) );

		$now      = new \DateTimeImmutable( 'now', $tz );
		$earliest = $now->modify( "+{$lead} hours" );
		$counts   = self::slot_counts( $date );

		$out = array();
		foreach ( self::all_slots() as $slot ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $slot, $tz );
			if ( false === $dt ) {
				continue;
			}
			if ( $dt < $earliest ) {
				continue; // Past or inside the lead-time window.
			}
			$remaining = $capacity - ( $counts[ $slot ] ?? 0 );
			if ( $remaining <= 0 ) {
				continue; // Full.
			}
			$out[] = array(
				'slot'      => $slot,
				'label'     => $slot,
				'remaining' => $remaining,
			);
		}

		return $out;
	}

	/*
	====================================================================== */
	/* Internal computation */
	/* ====================================================================== */

	/**
	 * All configured slot start times (open_time .. last_pickup, stepped by
	 * slot_minutes; last_pickup inclusive).
	 *
	 * @return string[] H:i strings.
	 */
	private static function all_slots(): array {
		$cfg  = Options::pickup();
		$tz   = Options::timezone();
		$step = max( 5, (int) ( $cfg['slot_minutes'] ?? 30 ) );

		$start = \DateTimeImmutable::createFromFormat( '!H:i', (string) ( $cfg['open_time'] ?? '09:00' ), $tz );
		$last  = \DateTimeImmutable::createFromFormat( '!H:i', (string) ( $cfg['last_pickup'] ?? '14:30' ), $tz );
		if ( false === $start || false === $last || $last < $start ) {
			return array();
		}

		$slots = array();
		$guard = 0;
		for ( $t = $start; $t <= $last && $guard < 500; $t = $t->modify( "+{$step} minutes" ), $guard++ ) {
			$slots[] = $t->format( 'H:i' );
		}

		return $slots;
	}

	/**
	 * Whether a date is an open, non-blackout day inside the look-ahead window.
	 */
	private static function is_open_date( string $date ): bool {
		$date = self::sanitize_date( $date );
		if ( '' === $date ) {
			return false;
		}

		$cfg = Options::pickup();
		$tz  = Options::timezone();
		$dt  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( false === $dt ) {
			return false;
		}

		$open_days = array_map( 'intval', (array) ( $cfg['open_days'] ?? array() ) );
		if ( ! in_array( (int) $dt->format( 'w' ), $open_days, true ) ) {
			return false;
		}
		if ( in_array( $date, (array) ( $cfg['blackout_dates'] ?? array() ), true ) ) {
			return false;
		}

		$today = new \DateTimeImmutable( 'today', $tz );
		$max   = max( 1, (int) ( $cfg['max_days_ahead'] ?? 21 ) );
		$limit = $today->modify( "+{$max} days" );
		if ( $dt < $today || $dt > $limit ) {
			return false;
		}

		return true;
	}

	/**
	 * Count occupying orders per slot for a date. HPOS-safe (wc_get_orders + CRUD).
	 *
	 * @return array<string,int> slot => count.
	 */
	private static function slot_counts( string $date ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		if ( isset( self::$counts_memo[ $date ] ) ) {
			return self::$counts_memo[ $date ];
		}

		$cached = get_transient( self::COUNTS_TRANSIENT . $date );
		if ( is_array( $cached ) ) {
			self::$counts_memo[ $date ] = $cached;
			return $cached;
		}

		try {
			$orders = self::orders_for_date( $date );
		} catch ( \Throwable $e ) {
			// Capacity math must never fatal the site (e.g. queries fired
			// before order types are registered). Fail open: no slots counted.
			return array();
		}

		$counts = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$slot = (string) $order->get_meta( self::META_SLOT );
			if ( '' === $slot ) {
				continue;
			}
			$counts[ $slot ] = ( $counts[ $slot ] ?? 0 ) + 1;
		}

		// Lifecycle hooks flush this the moment any order on the date changes;
		// the expiry is only a safety net against a missed event.
		set_transient( self::COUNTS_TRANSIENT . $date, $counts, 600 );
		self::$counts_memo[ $date ] = $counts;

		return $counts;
	}

	/**
	 * Occupying orders for one pickup date, on either order datastore.
	 *
	 * `wc_get_orders( meta_query )` is only honoured by the HPOS table store;
	 * the legacy CPT store drops the argument with a doing-it-wrong (silently
	 * counting EVERY order against EVERY date), so the CPT path goes through
	 * WP_Query where postmeta filtering is native.
	 *
	 * @return array<int,\WC_Order|false>
	 */
	private static function orders_for_date( string $date ): array {
		$meta_query = array(
			array(
				'key'   => self::META_DATE,
				'value' => $date,
			),
		);

		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			return wc_get_orders(
				array(
					'type'       => 'shop_order',
					'limit'      => -1,
					'status'     => self::OCCUPYING_STATUSES,
					'return'     => 'objects',
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- capacity lookup is keyed and cached.
				)
			);
		}

		$ids = get_posts(
			array(
				'post_type'     => 'shop_order',
				'post_status'   => self::OCCUPYING_STATUSES,
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- capacity lookup is keyed and cached.
			)
		);

		return array_map( 'wc_get_order', array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Human-readable combined label, e.g. "miércoles 16 de julio · 09:30".
	 */
	public static function build_label( string $date, string $slot ): string {
		$date = self::sanitize_date( $date );
		$slot = self::sanitize_slot( $slot );
		if ( '' === $date || '' === $slot ) {
			return trim( $date . ' ' . $slot );
		}
		$tz = Options::timezone();
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( false === $dt ) {
			return trim( $date . ' ' . $slot );
		}
		return self::format_date_label( $dt ) . ' · ' . $slot;
	}

	/**
	 * Localised, capitalised date label using the site locale.
	 */
	private static function format_date_label( \DateTimeInterface $dt ): string {
		$label = wp_date( 'l j \d\e F', $dt->getTimestamp(), Options::timezone() );
		if ( ! is_string( $label ) || '' === $label ) {
			return $dt->format( 'Y-m-d' );
		}
		return function_exists( 'mb_convert_case' )
			? mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' )
			: ucwords( $label );
	}

	private static function sanitize_date( string $date ): string {
		$date = trim( $date );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	private static function sanitize_slot( string $slot ): string {
		$slot = trim( $slot );
		return preg_match( '/^\d{2}:\d{2}$/', $slot ) ? $slot : '';
	}

	/*
	====================================================================== */
	/* Classic checkout */
	/* ====================================================================== */

	/**
	 * Render the pickup date + slot selects after the order notes.
	 */
	public function render_classic_fields(): void {
		$dates = self::valid_dates();

		echo '<div id="haramara-pickup" class="haramara-pickup-fields">';
		echo '<h3>' . esc_html__( 'Recolección del pedido', 'haramara-core' ) . '</h3>';
		echo '<p class="haramara-pickup-help">' . esc_html__( 'Elige el día y el horario para recoger tu pedido en tienda.', 'haramara-core' ) . '</p>';

		if ( empty( $dates ) ) {
			echo '<p class="haramara-pickup-empty">' . esc_html__( 'Por el momento no hay fechas de recolección disponibles. Contáctanos para coordinar tu pedido.', 'haramara-core' ) . '</p>';
			echo '</div>';
			return;
		}

		// Date select.
		echo '<p class="form-row form-row-first validate-required">';
		echo '<label for="' . esc_attr( self::FIELD_DATE ) . '">' . esc_html__( 'Fecha de recolección', 'haramara-core' ) . ' <abbr class="required" title="' . esc_attr__( 'obligatorio', 'haramara-core' ) . '">*</abbr></label>';
		echo '<select name="' . esc_attr( self::FIELD_DATE ) . '" id="' . esc_attr( self::FIELD_DATE ) . '" class="select" required>';
		echo '<option value="">' . esc_html__( 'Selecciona una fecha…', 'haramara-core' ) . '</option>';
		foreach ( $dates as $d ) {
			echo '<option value="' . esc_attr( $d['date'] ) . '">' . esc_html( $d['label'] ) . '</option>';
		}
		echo '</select></p>';

		// Slot select (populated by JS from the map below; falls back to first date's slots).
		$first_slots = self::available_slots( $dates[0]['date'] );
		echo '<p class="form-row form-row-last validate-required">';
		echo '<label for="' . esc_attr( self::FIELD_SLOT ) . '">' . esc_html__( 'Horario', 'haramara-core' ) . ' <abbr class="required" title="' . esc_attr__( 'obligatorio', 'haramara-core' ) . '">*</abbr></label>';
		echo '<select name="' . esc_attr( self::FIELD_SLOT ) . '" id="' . esc_attr( self::FIELD_SLOT ) . '" class="select" required>';
		echo '<option value="">' . esc_html__( 'Selecciona un horario…', 'haramara-core' ) . '</option>';
		foreach ( $first_slots as $s ) {
			echo '<option value="' . esc_attr( $s['slot'] ) . '">' . esc_html( $s['label'] ) . '</option>';
		}
		echo '</select></p>';

		echo '<div class="clear"></div></div>';

		$this->print_classic_script( $dates );
	}

	/**
	 * Inline script that repopulates the slot select from a date→slots map, so the
	 * field works without a network round-trip. The server re-validates regardless.
	 *
	 * @param array<int,array{date:string,weekday:int,label:string}> $dates
	 */
	private function print_classic_script( array $dates ): void {
		$map = array();
		foreach ( $dates as $d ) {
			$map[ $d['date'] ] = array_map(
				static fn( array $s ): string => $s['slot'],
				self::available_slots( $d['date'] )
			);
		}

		$json    = wp_json_encode( $map );
		$empty   = esc_js( __( 'Selecciona un horario…', 'haramara-core' ) );
		$date_id = esc_js( self::FIELD_DATE );
		$slot_id = esc_js( self::FIELD_SLOT );

		echo '<script>(function(){';
		echo 'var slots=' . $json . ';'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output.
		echo 'var d=document.getElementById("' . $date_id . '"),s=document.getElementById("' . $slot_id . '");'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js() above.
		echo 'if(!d||!s)return;';
		echo 'function fill(){var v=d.value,list=slots[v]||[];s.innerHTML="";';
		echo 'var o=document.createElement("option");o.value="";o.textContent="' . $empty . '";s.appendChild(o);'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js() above.
		echo 'for(var i=0;i<list.length;i++){var op=document.createElement("option");op.value=list[i];op.textContent=list[i];s.appendChild(op);}}';
		echo 'd.addEventListener("change",fill);';
		echo '})();</script>';
	}

	/**
	 * Validate the classic checkout submission. WooCommerce verifies its own
	 * checkout nonce before this runs.
	 */
	public function validate_classic_fields(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies the checkout nonce upstream.
		$date = isset( $_POST[ self::FIELD_DATE ] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_DATE ] ) ) ) : '';
		$slot = isset( $_POST[ self::FIELD_SLOT ] ) ? self::sanitize_slot( sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_SLOT ] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$error = self::validation_error( $date, $slot );
		if ( null !== $error && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * Persist the pickup selection to canonical order meta (HPOS-safe CRUD).
	 *
	 * @param \WC_Order           $order
	 * @param array<string,mixed> $data
	 */
	public function save_classic_fields( $order, $data ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies the checkout nonce upstream.
		$date = isset( $_POST[ self::FIELD_DATE ] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_DATE ] ) ) ) : '';
		$slot = isset( $_POST[ self::FIELD_SLOT ] ) ? self::sanitize_slot( sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_SLOT ] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $date || '' === $slot ) {
			return;
		}
		$this->store_selection( $order, $date, $slot );
	}

	/*
	====================================================================== */
	/* Block checkout */
	/* ====================================================================== */

	/**
	 * Register the pickup date + slot as additional checkout fields under the
	 * `haramara/pickup` namespace, when the Blocks API is available.
	 */
	public function register_block_fields(): void {
		// NB: the WooCommerce API is singular — `..._checkout_field`. Guarding on
		// the plural name silently disabled pickup on block checkout entirely.
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // Block API unavailable — classic hooks handle scheduling.
		}

		$dates = self::valid_dates();
		$slots = self::all_slots();
		if ( empty( $dates ) || empty( $slots ) ) {
			return; // Nothing bookable; avoid registering empty selects.
		}

		$date_options = array();
		foreach ( $dates as $d ) {
			$date_options[] = array(
				'value' => $d['date'],
				'label' => $d['label'],
			);
		}
		$slot_options = array();
		foreach ( $slots as $slot ) {
			$slot_options[] = array(
				'value' => $slot,
				'label' => $slot,
			);
		}

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_DATE,
					'label'    => __( 'Fecha de recolección', 'haramara-core' ),
					'location' => 'order',
					'type'     => 'select',
					'required' => true,
					'options'  => $date_options,
				)
			);
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_SLOT,
					'label'    => __( 'Horario de recolección', 'haramara-core' ),
					'location' => 'order',
					'type'     => 'select',
					'required' => true,
					'options'  => $slot_options,
				)
			);
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[haramara-core] Block pickup fields not registered: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional operational logging.
			}
		}
	}

	/**
	 * Validate the combined date + slot for block checkout.
	 *
	 * @param \WP_Error           $errors
	 * @param array<string,mixed> $fields Keyed by field id.
	 */
	public function validate_block_fields( $errors, $fields ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}
		$date = self::sanitize_date( (string) ( $fields[ self::BLOCK_DATE ] ?? '' ) );
		$slot = self::sanitize_slot( (string) ( $fields[ self::BLOCK_SLOT ] ?? '' ) );

		$error = self::validation_error( $date, $slot );
		if ( null !== $error ) {
			$errors->add( 'haramara_pickup_invalid', $error );
		}
	}

	/**
	 * Copy block additional-field values into the canonical meta keys + build the
	 * label, so every downstream consumer reads the same keys regardless of the
	 * checkout flavour.
	 *
	 * @param \WC_Order $order
	 */
	public function normalize_block_fields( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_DATE ) ) {
			return; // Already stored (classic path).
		}

		// Blocks store additional fields under the `_wc_other/{id}` meta key.
		$date = self::sanitize_date( (string) $order->get_meta( '_wc_other/' . self::BLOCK_DATE ) );
		$slot = self::sanitize_slot( (string) $order->get_meta( '_wc_other/' . self::BLOCK_SLOT ) );
		if ( '' === $date || '' === $slot ) {
			return;
		}

		$this->store_selection( $order, $date, $slot );
		$order->save();
	}

	/*
	====================================================================== */
	/* Shared validation + persistence */
	/* ====================================================================== */

	/**
	 * Returns a Spanish error string when the selection is invalid, or null when OK.
	 */
	private static function validation_error( string $date, string $slot ): ?string {
		if ( '' === $date || '' === $slot ) {
			return esc_html__( 'Selecciona una fecha y un horario de recolección.', 'haramara-core' );
		}
		if ( ! self::is_open_date( $date ) ) {
			return esc_html__( 'El día seleccionado no está disponible para recolección. Elige otra fecha.', 'haramara-core' );
		}
		if ( ! in_array( $slot, self::all_slots(), true ) ) {
			return esc_html__( 'El horario seleccionado no es válido. Elige otro.', 'haramara-core' );
		}

		$available = array_column( self::available_slots( $date ), 'slot' );
		if ( ! in_array( $slot, $available, true ) ) {
			return esc_html__( 'Ese horario ya no está disponible (cupo lleno o fuera del tiempo de anticipación). Elige otro.', 'haramara-core' );
		}

		return null;
	}

	/**
	 * Write date, slot and label to the order (no save() — caller decides).
	 *
	 * @param \WC_Order $order
	 */
	private function store_selection( \WC_Order $order, string $date, string $slot ): void {
		$order->update_meta_data( self::META_DATE, $date );
		$order->update_meta_data( self::META_SLOT, $slot );
		$order->update_meta_data( self::META_LABEL, self::build_label( $date, $slot ) );
	}
}
