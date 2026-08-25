<?php
/**
 * Configuration authority.
 *
 * Single source of truth for every editable setting. All modules read config
 * through the static accessors here — never `get_option()` directly — so the
 * schema, defaults, and secret-resolution rules live in one place.
 *
 * Secrets (Twilio auth token, Stripe keys) may be supplied via PHP constants /
 * environment for production hygiene; constants always win over stored values.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Setup;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Options implements Bootable {

	public const BUSINESS  = 'haramara_business_info';
	public const PICKUP    = 'haramara_pickup';
	public const SMS       = 'haramara_sms';
	public const SEO       = 'haramara_seo';
	public const EMPLOYEES = 'haramara_employees';
	public const POS       = 'haramara_pos';

	/** Hard ceiling for the employee-name list (POS picker stays scannable). */
	private const MAX_EMPLOYEES = 50;

	public function boot(): void {
		add_action( 'init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings so they are sanitised and exposed to the REST/Site Editor.
	 */
	public function register_settings(): void {
		$groups = array(
			self::BUSINESS  => array( $this, 'sanitize_business' ),
			self::PICKUP    => array( $this, 'sanitize_pickup' ),
			self::SMS       => array( $this, 'sanitize_sms' ),
			self::SEO       => array( $this, 'sanitize_seo' ),
			self::EMPLOYEES => array( $this, 'sanitize_employees' ),
			self::POS       => array( $this, 'sanitize_pos' ),
		);
		foreach ( $groups as $name => $sanitizer ) {
			register_setting(
				'haramara',
				$name,
				array(
					'type'              => 'object',
					'sanitize_callback' => $sanitizer,
					'show_in_rest'      => false, // Contains operational config; not public.
					'default'           => array(),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Defaults */
	/* ---------------------------------------------------------------------- */

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			// Real business facts (verified Aug 2026 — Google listing + Instagram bio).
			// Keys are the schema — patterns and SEO read them via Options::business().
			self::BUSINESS  => array(
				'name'             => 'Haramara',
				'tagline'          => 'Café de especialidad y pan de masa madre, hechos con procesos manuales.',
				'phone'            => '777 136 2228',
				'phone_link'       => 'tel:+527771362228',
				'whatsapp'         => 'https://wa.me/527771362228',
				'email'            => '',
				'address'          => 'Tulipán 302, esq. Hule, Col. Delicias, 62330 Cuernavaca, Mor.',
				'address_short'    => 'Tulipán 302 esq. Hule, Delicias',
				'street'           => 'Tulipán 302, esq. Hule',
				'locality'         => 'Cuernavaca',
				'region'           => 'Morelos',
				'postal_code'      => '62330',
				'country'          => 'MX',
				'hours_summary'    => 'Miércoles a lunes · 8:00–20:00',
				'hours_closed'     => 'Martes descansamos.',
				'instagram'        => 'https://www.instagram.com/haramara.cafe/',
				'instagram_handle' => '@haramara.cafe',
				'maps_url'         => 'https://www.google.com/maps/search/?api=1&query=Haramara+cafe+Tulipan+302+Cuernavaca',
				'latitude'         => '18.9460606',
				'longitude'        => '-99.2053051',
			),
			self::PICKUP    => array(
				'open_days'       => array( 0, 1, 3, 4, 5, 6 ), // Wed–Mon; closed Tuesdays (0=Sun … 6=Sat).
				'open_time'       => '08:00',
				'close_time'      => '20:00',
				'last_pickup'     => '19:30',
				'lead_time_hours' => 2,
				'slot_minutes'    => 30,
				'slot_capacity'   => 8,
				'max_days_ahead'  => 14,
				'blackout_dates'  => array(),
				'timezone'        => 'America/Mexico_City',
				'instructions'    => 'Recoge tu pedido en barra y menciona tu número de pedido.',
			),
			self::SMS       => array(
				'enabled'               => false,
				'provider'              => 'twilio',
				'channel'               => 'sms',
				'dry_run'               => false,
				'twilio_sid'            => '',
				'twilio_account_sid'    => '',
				'twilio_token'          => '',
				'twilio_from'           => '',
				'messaging_service_sid' => '',
				'notify_customer'       => true,
			),
			self::SEO       => array(
				'default_og_image'  => 0,
				'twitter_handle'    => '',
				'organization_logo' => 0,
				'price_range'       => '$$',
			),
			// `names` is the legacy shape and stays the mirror the salidas
			// person picker reads; `people` is the operator roster added for
			// PIN sign-in (see Staff\Operators). Writers keep the two in sync.
			self::EMPLOYEES => array(
				'names'  => array(),
				'people' => array(),
			),
			// POS policy knobs. Thresholds are policy the OWNER tunes; the
			// enforcement lives in the domain classes, never the app.
			self::POS       => array(
				// A sale-time discount above this % of the subtotal needs a
				// supervisor authorization.
				'discount_supervisor_pct' => 15,
				// Cuentas abiertas (open a ticket, add rounds, close later).
				// Ships OFF everywhere; the owner flips it where table service
				// is real. The shift close refuses to close over open tabs.
				'open_tabs'               => false,
			),
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Accessors */
	/* ---------------------------------------------------------------------- */

	/** @return array<string,mixed> */
	public static function group( string $name ): array {
		$defaults = self::defaults()[ $name ] ?? array();
		$stored   = get_option( $name, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		return array_merge( $defaults, $stored );
	}

	/** @return array<string,mixed> */
	public static function business(): array {
		return self::group( self::BUSINESS );
	}

	/** @return array<string,mixed> */
	public static function pickup(): array {
		return self::group( self::PICKUP );
	}

	/** @return array<string,mixed> */
	public static function seo(): array {
		return self::group( self::SEO );
	}

	/**
	 * Employee names shown in the POS "¿Quién lo lleva?" picker.
	 *
	 * @return string[]
	 */
	public static function employees(): array {
		$names = self::get( self::EMPLOYEES, 'names', array() );
		return array_values( array_map( 'strval', is_array( $names ) ? $names : array() ) );
	}

	/**
	 * Append a name to the employee list.
	 *
	 * Duplicates (case-insensitive) are a success no-op. When the list is at
	 * MAX_EMPLOYEES the name is not added — callers detect that by its absence
	 * from the returned list.
	 *
	 * @param string $name Raw name as received.
	 * @return string[] The list after the attempt.
	 */
	public static function add_employee( string $name ): array {
		$name  = mb_substr( sanitize_text_field( $name ), 0, 80 );
		$names = self::employees();

		if ( '' === $name ) {
			return $names;
		}
		foreach ( $names as $existing ) {
			if ( 0 === strcasecmp( $existing, $name ) ) {
				return $names;
			}
		}
		if ( count( $names ) >= self::MAX_EMPLOYEES ) {
			return $names;
		}

		$names[] = $name;

		// Keep the operator roster in step: the new person is active with no
		// PIN, so they can be the subject of a salida immediately but cannot
		// sign in until the owner sets a NIP in Ajustes → Empleados.
		$stored = get_option( self::EMPLOYEES, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['names']  = $names;
		$stored['people'] = \Haramara\Core\Staff\Operators::reconcile( $names, (array) ( $stored['people'] ?? array() ) );

		update_option( self::EMPLOYEES, $stored );

		return $names;
	}

	/**
	 * SMS config with constant/env overrides applied to secrets.
	 *
	 * @return array<string,mixed>
	 */
	public static function sms(): array {
		$sms = self::group( self::SMS );

		$const_map = array(
			'twilio_sid'            => 'HARAMARA_TWILIO_SID',
			'twilio_account_sid'    => 'HARAMARA_TWILIO_ACCOUNT_SID',
			'twilio_token'          => 'HARAMARA_TWILIO_AUTH_TOKEN',
			'twilio_from'           => 'HARAMARA_TWILIO_FROM',
			'channel'               => 'HARAMARA_TWILIO_CHANNEL',
			'dry_run'               => 'HARAMARA_SMS_DRY_RUN',
			'messaging_service_sid' => 'HARAMARA_TWILIO_MESSAGING_SID',
		);
		foreach ( $const_map as $key => $const ) {
			if ( defined( $const ) && '' !== (string) constant( $const ) ) {
				$sms[ $key ] = (string) constant( $const );
			}
		}
		return $sms;
	}

	/** @return array<string,mixed> */
	public static function pos(): array {
		return self::group( self::POS );
	}

	/** Convenience single-value getter. */
	public static function get( string $group, string $key, mixed $fallback = null ): mixed {
		$data = self::group( $group );
		return $data[ $key ] ?? $fallback;
	}

	/** The site's configured pickup timezone as a DateTimeZone. */
	public static function timezone(): \DateTimeZone {
		$tz = (string) self::get( self::PICKUP, 'timezone', 'America/Mexico_City' );
		try {
			return new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'America/Mexico_City' );
		}
	}

	/**
	 * Install any missing defaults without clobbering existing values.
	 */
	public static function install_defaults(): void {
		foreach ( self::defaults() as $name => $fallback ) {
			$existing = get_option( $name, null );
			if ( null === $existing ) {
				add_option( $name, $fallback );
			} elseif ( is_array( $existing ) ) {
				update_option( $name, array_merge( $fallback, $existing ) );
			}
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Sanitizers */
	/* ---------------------------------------------------------------------- */

	/**
	 * @param mixed $value
	 * @return array<string,string>
	 */
	public function sanitize_business( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = array();
		foreach ( self::defaults()[ self::BUSINESS ] as $key => $fallback ) {
			if ( ! isset( $value[ $key ] ) ) {
				continue;
			}
			$raw           = (string) $value[ $key ];
			$clean[ $key ] = in_array( $key, array( 'instagram', 'whatsapp', 'maps_url' ), true )
				? esc_url_raw( $raw )
				: ( 'email' === $key ? sanitize_email( $raw ) : sanitize_text_field( $raw ) );
		}
		return $clean;
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function sanitize_pickup( mixed $value ): array {
		$value                  = is_array( $value ) ? $value : array();
		$out                    = array();
		$out['open_days']       = array_values( array_unique( array_map( 'intval', (array) ( $value['open_days'] ?? array() ) ) ) );
		$out['open_time']       = preg_match( '/^\d{2}:\d{2}$/', (string) ( $value['open_time'] ?? '' ) ) ? $value['open_time'] : '09:00';
		$out['close_time']      = preg_match( '/^\d{2}:\d{2}$/', (string) ( $value['close_time'] ?? '' ) ) ? $value['close_time'] : '15:00';
		$out['last_pickup']     = preg_match( '/^\d{2}:\d{2}$/', (string) ( $value['last_pickup'] ?? '' ) ) ? $value['last_pickup'] : '14:30';
		$out['lead_time_hours'] = max( 0, (int) ( $value['lead_time_hours'] ?? 24 ) );
		$out['slot_minutes']    = max( 5, (int) ( $value['slot_minutes'] ?? 30 ) );
		$out['slot_capacity']   = max( 1, (int) ( $value['slot_capacity'] ?? 8 ) );
		$out['max_days_ahead']  = max( 1, (int) ( $value['max_days_ahead'] ?? 21 ) );
		$out['timezone']        = sanitize_text_field( (string) ( $value['timezone'] ?? 'America/Mexico_City' ) );
		$out['instructions']    = sanitize_textarea_field( (string) ( $value['instructions'] ?? '' ) );
		$dates                  = array_filter( array_map( 'sanitize_text_field', (array) ( $value['blackout_dates'] ?? array() ) ), static fn( $d ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) );
		$out['blackout_dates']  = array_values( $dates );
		return $out;
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function sanitize_sms( mixed $value ): array {
		$value                        = is_array( $value ) ? $value : array();
		$out                          = array();
		$out['enabled']               = ! empty( $value['enabled'] );
		$out['provider']              = 'twilio';
		$out['twilio_sid']            = sanitize_text_field( (string) ( $value['twilio_sid'] ?? '' ) );
		$out['twilio_account_sid']    = sanitize_text_field( (string) ( $value['twilio_account_sid'] ?? '' ) );
		$out['twilio_token']          = sanitize_text_field( (string) ( $value['twilio_token'] ?? '' ) );
		$out['twilio_from']           = sanitize_text_field( (string) ( $value['twilio_from'] ?? '' ) );
		$out['dry_run']               = ! empty( $value['dry_run'] );
		$out['channel']               = in_array( (string) ( $value['channel'] ?? 'sms' ), array( 'sms', 'whatsapp' ), true ) ? (string) $value['channel'] : 'sms';
		$out['messaging_service_sid'] = sanitize_text_field( (string) ( $value['messaging_service_sid'] ?? '' ) );
		$out['notify_customer']       = ! empty( $value['notify_customer'] );
		return $out;
	}

	/**
	 * Sanitize the employee list saved from the admin Empleados tab.
	 *
	 * Two shapes travel together: `names` (the flat list the salidas picker
	 * reads) and `people` (the operator roster with PIN hashes and roles).
	 * The names are the editable field; people are *reconciled* against them so
	 * a rename or reorder never destroys a PIN — and, critically, so saving the
	 * tab does not silently wipe the roster.
	 *
	 * Neither roles nor PIN hashes are posted by this form — both are managed
	 * by the roster tool in Settings (own nonce, admin_post) so that a
	 * JS-cloned repeatable row can never misalign a role with a person.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string,mixed>
	 */
	public function sanitize_employees( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$names = array();
		foreach ( (array) ( $value['names'] ?? array() ) as $name ) {
			$name = mb_substr( sanitize_text_field( (string) $name ), 0, 80 );
			if ( '' === $name ) {
				continue;
			}
			foreach ( $names as $existing ) {
				if ( 0 === strcasecmp( $existing, $name ) ) {
					continue 2;
				}
			}
			$names[] = $name;
		}
		$names = array_slice( $names, 0, self::MAX_EMPLOYEES );

		// Pick the reconciliation baseline by who is writing.
		//
		// register_setting() means this sanitizer runs on EVERY update_option()
		// for this group, not just on the settings form — including
		// Staff\Operators::persist(). Always reading the baseline from storage
		// would therefore discard whatever the caller is trying to save: a PIN
		// hash could never be written, because reconcile() would rebuild the
		// roster from the pre-write copy that does not have it.
		//
		// So: a payload that carries `people` is a programmatic write and is its
		// own baseline; a payload without it is the admin form (which never
		// posts pin_hash) and reconciles against storage, preserving hashes
		// across renames and reorders.
		if ( isset( $value['people'] ) && is_array( $value['people'] ) ) {
			$baseline = $value['people'];
		} else {
			$stored   = get_option( self::EMPLOYEES, array() );
			$stored   = is_array( $stored ) ? $stored : array();
			$baseline = (array) ( $stored['people'] ?? array() );
		}

		return array(
			'names'  => $names,
			'people' => \Haramara\Core\Staff\Operators::reconcile( $names, $baseline ),
		);
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function sanitize_pos( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'discount_supervisor_pct' => max( 0, min( 100, (int) ( $value['discount_supervisor_pct'] ?? 15 ) ) ),
			'open_tabs'               => ! empty( $value['open_tabs'] ),
		);
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function sanitize_seo( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'default_og_image'  => (int) ( $value['default_og_image'] ?? 0 ),
			'twitter_handle'    => sanitize_text_field( (string) ( $value['twitter_handle'] ?? '' ) ),
			'organization_logo' => (int) ( $value['organization_logo'] ?? 0 ),
			'price_range'       => sanitize_text_field( (string) ( $value['price_range'] ?? '$$' ) ),
		);
	}
}
