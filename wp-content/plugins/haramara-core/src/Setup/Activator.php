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

	/**
	 * Schema generation. Bump when create_tables() changes so maybe_upgrade()
	 * re-runs dbDelta on already-active installs (deploys never fire the
	 * activation hook). Version 1 is the implicit sms-log-only schema of
	 * plugin 1.0.0; version 2 adds the withdrawals table; version 3 adds the
	 * wallet device registrations table.
	 */
	public const DB_VERSION = 3;

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

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY destination_created (destination, created_at),
			KEY group_key (group_key)
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
