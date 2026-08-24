<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	/** Shared table basename (prefix added at runtime). Mirrored by Sms\Logger. */
	public const SMS_TABLE = 'haramara_sms_log';

	/** Internal-withdrawals log basename (prefix added at runtime). Mirrored by Woo\Withdrawals. */
	public const WITHDRAWALS_TABLE = 'haramara_withdrawals';

	/** Wallet-pass device registrations basename (prefix added at runtime). Mirrored by Loyalty\WalletWebService. */
	public const WALLET_DEVICES_TABLE = 'haramara_wallet_devices';

	/** POS request-replay guard basename (prefix added at runtime). Mirrored by Rest\Idempotency. */
	public const IDEMPOTENCY_TABLE = 'haramara_pos_idempotency';

	/** Cash-shift (turno) records basename (prefix added at runtime). Mirrored by Ordering\Shifts. */
	public const SHIFTS_TABLE = 'haramara_pos_shifts';

	/** Append-only POS audit-event ledger basename (prefix added at runtime). Mirrored by Ordering\PosEvents. */
	public const EVENTS_TABLE = 'haramara_pos_events';

	/**
	 * Schema generation. Bump when create_tables() changes so maybe_upgrade()
	 * re-runs dbDelta on already-active installs (deploys never fire the
	 * activation hook). Version 1 is the implicit sms-log-only schema of
	 * plugin 1.0.0; version 2 adds the withdrawals table; version 3 adds the
	 * wallet device registrations table; version 4 adds the POS idempotency
	 * table and migrates the employee name list into the operator roster;
	 * version 5 adds the cash-shift (turno) table and the append-only POS
	 * event ledger (created together: cash drops are events that the shift's
	 * expected-cash math subtracts, so the two tables are one feature);
	 * version 6 adds the CFDI invoices table (Fiscal\Invoices owns its schema).
	 */
	public const DB_VERSION = 6;

	/** Option that records the schema generation currently installed. */
	private const DB_VERSION_OPTION = 'haramara_db_version';

	/** Capability that gates the Haramara operations dashboard. */
	public const CAP = 'manage_haramara';

	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		Options::install_defaults();
		self::grant_caps();

		// Defer heavy content seeding to an admin-time one-shot so activation stays fast.
		if ( false === get_option( 'haramara_content_installed' ) ) {
			add_option( 'haramara_needs_content_install', 1 );
		}

		if ( ! wp_next_scheduled( 'haramara_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'haramara_daily_maintenance' );
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'haramara_daily_maintenance' );
		flush_rewrite_rules();
	}

	/**
	 * Bring an already-active install up to the current schema.
	 *
	 * The activation hook only fires on activate — never on a code deploy — so
	 * new tables would otherwise silently not exist in production. Called from
	 * Plugin::boot(); costs one autoloaded-option read when up to date.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 1 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		self::create_tables();

		// Runs on every generation bump rather than behind a `< 4` gate: the
		// gate is provably always-true while 4 is the newest generation, and
		// reconcile() is idempotent — re-running preserves every key, PIN
		// hash, and role. Add a real generation gate here when gen 5 lands.
		self::migrate_employee_roster();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Seed the operator roster from the legacy employee-name list.
	 *
	 * Gen-4 one-shot. Every existing name becomes an **active person with no
	 * PIN**: they remain selectable as the subject of a salida the moment the
	 * deploy lands, and only the new PIN-authenticated routes are gated on
	 * having a NIP set. Migrating them inactive instead would strand the
	 * salidas picker in `inventario.tsx` until the owner visited wp-admin.
	 *
	 * Idempotent: re-running against an already-populated roster preserves
	 * every key, PIN hash, and role via Operators::reconcile().
	 */
	private static function migrate_employee_roster(): void {
		$stored = get_option( Options::EMPLOYEES, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$names = array_values( array_map( 'strval', (array) ( $stored['names'] ?? array() ) ) );
		if ( array() === $names ) {
			return;
		}

		$stored['people'] = \Haramara\Core\Staff\Operators::reconcile( $names, (array) ( $stored['people'] ?? array() ) );

		update_option( Options::EMPLOYEES, $stored );
	}

	/**
	 * SMS message log (inbound + outbound), used by Sms\Logger and the admin SMS screen.
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . self::SMS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			direction VARCHAR(10) NOT NULL DEFAULT 'outbound',
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			recipient VARCHAR(32) NOT NULL DEFAULT '',
			sender VARCHAR(32) NOT NULL DEFAULT '',
			body TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			provider_sid VARCHAR(64) NULL DEFAULT NULL,
			error TEXT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY direction (direction),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// Internal withdrawals (salidas internas): one row per product line;
		// lines of one multi-product withdrawal share a group_key. Business
		// records — no prune, unlike the SMS log. Used by Woo\Withdrawals.
		$withdrawals = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		$sql = "CREATE TABLE {$withdrawals} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			group_key VARCHAR(16) NOT NULL,
			destination VARCHAR(20) NOT NULL,
			person VARCHAR(80) NOT NULL DEFAULT '',
			note VARCHAR(200) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL,
			product_name VARCHAR(200) NOT NULL,
			quantity SMALLINT UNSIGNED NOT NULL,
			unit_price DECIMAL(10,2) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operator_key VARCHAR(32) NOT NULL DEFAULT '',
			operator_name VARCHAR(80) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY destination_created (destination, created_at),
			KEY group_key (group_key),
			KEY operator_key (operator_key)
		) {$charset_collate};";

		dbDelta( $sql );

		// Wallet-pass device registrations (Apple PassKit web service): which
		// devices show which pass, and the APNs token to nudge on changes.
		// Rows come and go with the pass itself. Used by Loyalty\WalletWebService.
		$wallet_devices = $wpdb->prefix . self::WALLET_DEVICES_TABLE;

		$sql = "CREATE TABLE {$wallet_devices} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			device_id VARCHAR(64) NOT NULL,
			push_token VARCHAR(200) NOT NULL,
			serial VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY device_serial (device_id, serial),
			KEY serial (serial)
		) {$charset_collate};";

		dbDelta( $sql );

		// POS request-replay guard: maps a client-generated idempotency key to
		// the response that key already produced, so a retried charge — a
		// flaky tablet connection now, the offline outbox later — settles once
		// instead of ringing twice. Pruned by the daily maintenance job; the
		// UNIQUE key on request_key is what makes the guard a guard.
		$idempotency = $wpdb->prefix . self::IDEMPOTENCY_TABLE;

		$sql = "CREATE TABLE {$idempotency} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_key VARCHAR(64) NOT NULL,
			route VARCHAR(100) NOT NULL DEFAULT '',
			operator_key VARCHAR(32) NOT NULL DEFAULT '',
			status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
			response LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_key (request_key),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// Cash shifts (turnos): one row per drawer session. expected_cash and
		// variance are computed server-side at close and never exposed while
		// the shift is open — the blind count is the anti-fraud mechanic.
		// Timestamps are café-local (Options::timezone()), like withdrawals.
		$shifts = $wpdb->prefix . self::SHIFTS_TABLE;

		$sql = "CREATE TABLE {$shifts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			status VARCHAR(10) NOT NULL DEFAULT 'open',
			opened_at DATETIME NOT NULL,
			opened_by_key VARCHAR(32) NOT NULL DEFAULT '',
			opened_by_name VARCHAR(80) NOT NULL DEFAULT '',
			opening_float DECIMAL(10,2) NOT NULL DEFAULT 0,
			closed_at DATETIME NULL DEFAULT NULL,
			closed_by_key VARCHAR(32) NOT NULL DEFAULT '',
			closed_by_name VARCHAR(80) NOT NULL DEFAULT '',
			declared_cash DECIMAL(10,2) NULL DEFAULT NULL,
			expected_cash DECIMAL(10,2) NULL DEFAULT NULL,
			variance DECIMAL(10,2) NULL DEFAULT NULL,
			note VARCHAR(200) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY opened_at (opened_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// Append-only POS audit events: who moved money outside a plain sale,
		// and why. Business records — never pruned, and deliberately not
		// WooCommerce orders or order meta (meta is editable from wp-admin;
		// this ledger is the artifact that proves an adjustment happened).
		// order_id is nullable and paired with tab_id so open-tab line voids
		// (a later phase; tabs are not WC orders until close) land in the
		// same ledger. Used by Ordering\PosEvents.
		$events = $wpdb->prefix . self::EVENTS_TABLE;

		$sql = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			shift_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operator_key VARCHAR(32) NOT NULL DEFAULT '',
			operator_name VARCHAR(80) NOT NULL DEFAULT '',
			authorized_by VARCHAR(80) NOT NULL DEFAULT '',
			type VARCHAR(20) NOT NULL,
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			tab_id BIGINT UNSIGNED NULL DEFAULT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			reason_code VARCHAR(30) NOT NULL DEFAULT '',
			reason_note VARCHAR(200) NOT NULL DEFAULT '',
			items_json LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY shift_type (shift_id, type),
			KEY order_id (order_id),
			KEY type_created (type, created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// CFDI invoices (autofactura): schema lives with its owner so the
		// fiscal domain stays self-contained; the Activator only executes it.
		dbDelta( \Haramara\Core\Fiscal\Invoices::schema( $wpdb->prefix, $charset_collate ) );
	}

	/**
	 * Grant the operations capability to admins and shop managers.
	 */
	private static function grant_caps(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}
}
