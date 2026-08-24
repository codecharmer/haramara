<?php
/**
 * Counter-operator identity (PIN over the shared device login).
 *
 * The POS tablet authenticates as a *device* with one WordPress Application
 * Password (see Rest\PosRoutes::permission()). That answers "is this our
 * tablet?" but never "who is standing at the counter?" — so no sale, salida,
 * cancelación, or descuento could be attributed to a person. This class adds
 * the missing layer: each employee gets a PIN, and every mutation carries the
 * operator that performed it.
 *
 * Deliberate design choices:
 *
 * - **People live in the existing `Options::EMPLOYEES` group**, not in WP users.
 *   Café staff rotate; the owner should not be managing WordPress accounts.
 *   `Options::employees()` keeps returning plain names so the salidas person
 *   picker in `inventario.tsx` works unchanged.
 * - **Tokens are signed with wp_salt() *and* the person's own pin_hash**, so
 *   changing or clearing a PIN instantly invalidates every outstanding token
 *   for that person — no revocation list to maintain.
 * - **Migrated employees are active with no PIN.** They can still be picked as
 *   the *subject* of a salida; they simply cannot *authenticate* until a PIN is
 *   set. Migrating them inactive would strand the salidas picker on deploy day.
 * - Failed attempts throttle per person, not per device — one employee
 *   fat-fingering a PIN must not lock the counter for everyone.
 *
 * Token shape is `key.expiry.hmac`, mirroring the `key.hmac` card token in
 * Loyalty\Members so the apps validate both with the same client-side rules.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Staff;

use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PIN-authenticated counter operators.
 */
final class Operators {

	/** Roles, least privileged first. `supervisor` may authorize voids/refunds. */
	public const ROLES = array( 'cajero', 'supervisor' );

	/** Default role for a migrated or newly added employee. */
	public const DEFAULT_ROLE = 'cajero';

	/** PIN length bounds. Four digits is the counter norm; six is allowed. */
	private const PIN_MIN = 4;
	private const PIN_MAX = 6;

	/**
	 * How long an operator session lasts — one long shift, then re-PIN.
	 * Seconds as a literal: WordPress time constants are not available to the
	 * static analyser in a const expression.
	 */
	private const TOKEN_TTL = 43200;

	/** Supervisor step-up authorizations are single-action and short-lived. */
	private const AUTH_TTL = 120;

	/** Failed PIN attempts tolerated inside LOCKOUT_WINDOW before lockout. */
	private const MAX_ATTEMPTS = 5;

	/** Attempt-counting window, and the lockout duration once it trips (15 min). */
	private const LOCKOUT_WINDOW = 900;

	/* ---------------------------------------------------------------------- */
	/* Reading people */
	/* ---------------------------------------------------------------------- */

	/**
	 * Every configured person, in admin order.
	 *
	 * @return array<int,array{key:string,name:string,pin_hash:string,role:string,active:bool}>
	 */
	public static function people(): array {
		$people = Options::get( Options::EMPLOYEES, 'people', array() );
		if ( ! is_array( $people ) ) {
			return array();
		}

		$out = array();
		foreach ( $people as $person ) {
			if ( is_array( $person ) && '' !== (string) ( $person['key'] ?? '' ) ) {
				$out[] = self::normalize( $person );
			}
		}
		return $out;
	}

	/**
	 * One person by key.
	 *
	 * @param string $key Person key.
	 * @return array{key:string,name:string,pin_hash:string,role:string,active:bool}|null
	 */
	public static function find( string $key ): ?array {
		$key = sanitize_key( $key );
		foreach ( self::people() as $person ) {
			if ( $person['key'] === $key ) {
				return $person;
			}
		}
		return null;
	}

	/**
	 * The roster the POS shows on its PIN screen.
	 *
	 * Only active people who have actually set a PIN can sign in, so anyone
	 * else is omitted — a name that cannot be tapped is worse than absent.
	 * pin_hash is never exposed.
	 *
	 * @return array<int,array{key:string,name:string,role:string}>
	 */
	public static function roster(): array {
		$out = array();
		foreach ( self::people() as $person ) {
			if ( $person['active'] && '' !== $person['pin_hash'] ) {
				$out[] = array(
					'key'  => $person['key'],
					'name' => $person['name'],
					'role' => $person['role'],
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a person may authorize a supervisor step-up.
	 *
	 * @param array<string,mixed> $person Person record.
	 */
	public static function is_supervisor( array $person ): bool {
		return 'supervisor' === ( $person['role'] ?? self::DEFAULT_ROLE );
	}

	/* ---------------------------------------------------------------------- */
	/* Authentication */
	/* ---------------------------------------------------------------------- */

	/**
	 * Exchange a PIN for an operator session token.
	 *
	 * @param string $key Person key.
	 * @param string $pin Raw PIN as typed.
	 * @return array{operator:array<string,mixed>,token:string,expires:int}|\WP_Error
	 */
	public static function login( string $key, string $pin ) {
		$person = self::find( $key );
		if ( null === $person || ! $person['active'] ) {
			// Same error for unknown and inactive: never confirm who exists.
			return self::bad_pin_error();
		}

		if ( self::is_locked( $person['key'] ) ) {
			return new \WP_Error(
				'haramara_operator_locked',
				__( 'Demasiados intentos. Espera unos minutos o pide ayuda a un supervisor.', 'haramara-core' ),
				array( 'status' => 429 )
			);
		}

		if ( '' === $person['pin_hash'] ) {
			return new \WP_Error(
				'haramara_operator_no_pin',
				__( 'Esta persona todavía no tiene NIP. Configúralo en Ajustes → Empleados.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		if ( ! self::check_pin( $pin, $person['pin_hash'] ) ) {
			self::note_failure( $person['key'] );
			return self::bad_pin_error();
		}

		self::clear_failures( $person['key'] );

		$expires = time() + self::TOKEN_TTL;

		return array(
			'operator' => self::public_shape( $person ),
			'token'    => self::issue_token( $person, $expires ),
			'expires'  => $expires,
		);
	}

	/**
	 * Resolve an operator session token back to the person.
	 *
	 * @param string $token Signed token (`key.expiry.hmac`).
	 * @return array{key:string,name:string,pin_hash:string,role:string,active:bool}|\WP_Error
	 */
	public static function resolve_token( string $token ) {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return self::bad_token_error();
		}

		list( $key, $expiry, $signature ) = $parts;

		$person = self::find( $key );
		if ( null === $person || ! $person['active'] || '' === $person['pin_hash'] ) {
			return self::bad_token_error();
		}

		if ( ! hash_equals( self::sign( $person, (int) $expiry ), $signature ) ) {
			return self::bad_token_error();
		}

		if ( (int) $expiry < time() ) {
			return new \WP_Error(
				'haramara_operator_token_expired',
				__( 'La sesión del operador expiró. Vuelve a ingresar tu NIP.', 'haramara-core' ),
				array( 'status' => 401 )
			);
		}

		return $person;
	}

	/**
	 * Verify a supervisor's PIN for a single privileged action.
	 *
	 * Returns a short-lived authorization bound to `$action`, so a supervisor
	 * approving one void cannot have that approval replayed against another.
	 *
	 * @param string $key    Supervisor person key.
	 * @param string $pin    Raw PIN as typed.
	 * @param string $action Action being authorized (e.g. `void`, `refund`).
	 * @return array{authorization:string,authorized_by:string,expires:int}|\WP_Error
	 */
	public static function authorize( string $key, string $pin, string $action ) {
		$person = self::find( $key );
		if ( null === $person || ! $person['active'] || ! self::is_supervisor( $person ) ) {
			// Non-supervisors get the generic PIN error — the roster already
			// tells the app who is a supervisor; this path must not confirm it.
			return self::bad_pin_error();
		}

		$result = self::login( $key, $pin );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$action  = sanitize_key( $action );
		$expires = time() + self::AUTH_TTL;

		return array(
			// Same `key.expiry.hmac` shape as a session token — check_authorization()
			// needs the key and expiry to re-derive the signature.
			'authorization' => $person['key'] . '.' . $expires . '.' . self::sign_authorization( $person, $action, $expires ),
			'authorized_by' => $person['name'],
			'expires'       => $expires,
		);
	}

	/**
	 * Validate a step-up authorization produced by authorize().
	 *
	 * @param string $authorization Signed authorization (`key.expiry.hmac`).
	 * @param string $action        Action it must be bound to.
	 * @return array{key:string,name:string,pin_hash:string,role:string,active:bool}|\WP_Error
	 */
	public static function check_authorization( string $authorization, string $action ) {
		$parts = explode( '.', $authorization );
		if ( 3 !== count( $parts ) ) {
			return self::bad_authorization_error();
		}

		list( $key, $expiry, $signature ) = $parts;

		$person = self::find( $key );
		if ( null === $person || ! self::is_supervisor( $person ) ) {
			return self::bad_authorization_error();
		}

		if ( ! hash_equals( self::sign_authorization( $person, sanitize_key( $action ), (int) $expiry ), $signature ) ) {
			return self::bad_authorization_error();
		}

		if ( (int) $expiry < time() ) {
			return new \WP_Error(
				'haramara_authorization_expired',
				__( 'La autorización expiró. Pide al supervisor que la repita.', 'haramara-core' ),
				array( 'status' => 403 )
			);
		}

		return $person;
	}

	/* ---------------------------------------------------------------------- */
	/* Writing people */
	/* ---------------------------------------------------------------------- */

	/**
	 * Set or clear a person's PIN.
	 *
	 * Passing an empty PIN clears it, which also invalidates every outstanding
	 * token for that person (the pin_hash is part of the signing key).
	 *
	 * @param string $key Person key.
	 * @param string $pin Raw PIN, or '' to clear.
	 * @return true|\WP_Error
	 */
	public static function set_pin( string $key, string $pin ) {
		$key    = sanitize_key( $key );
		$person = self::find( $key );
		if ( null === $person ) {
			return new \WP_Error(
				'haramara_operator_unknown',
				__( 'Empleado no encontrado.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		$pin = trim( $pin );
		if ( '' !== $pin && ! self::is_valid_pin( $pin ) ) {
			return new \WP_Error(
				'haramara_operator_bad_pin_format',
				sprintf(
					/* translators: 1: minimum digits, 2: maximum digits. */
					__( 'El NIP debe tener entre %1$d y %2$d dígitos.', 'haramara-core' ),
					self::PIN_MIN,
					self::PIN_MAX
				),
				array( 'status' => 400 )
			);
		}

		$hash   = '' === $pin ? '' : wp_hash_password( $pin );
		$people = array();
		foreach ( self::people() as $existing ) {
			if ( $existing['key'] === $key ) {
				$existing['pin_hash'] = $hash;
			}
			$people[] = $existing;
		}

		self::persist( $people );
		self::clear_failures( $key );

		return true;
	}

	/**
	 * Whether a raw PIN is well formed (digits only, inside the length bounds).
	 *
	 * @param string $pin Raw PIN.
	 */
	public static function is_valid_pin( string $pin ): bool {
		return (bool) preg_match( '/^\d{' . self::PIN_MIN . ',' . self::PIN_MAX . '}$/', $pin );
	}

	/**
	 * Build the `people` list for a set of names, preserving existing records.
	 *
	 * This is the bridge from the legacy `names[]` shape: a name already known
	 * keeps its key, PIN, and role; a new name is appended as an active person
	 * with no PIN. Names absent from `$names` are dropped, matching how the
	 * admin repeatable field already behaves.
	 *
	 * @param string[]                  $names    Names in admin order.
	 * @param array<int,array<string,mixed>> $existing Current people records.
	 * @return array<int,array{key:string,name:string,pin_hash:string,role:string,active:bool}>
	 */
	public static function reconcile( array $names, array $existing ): array {
		$by_name = array();
		foreach ( $existing as $person ) {
			if ( is_array( $person ) && '' !== (string) ( $person['name'] ?? '' ) ) {
				$by_name[ mb_strtolower( (string) $person['name'] ) ] = self::normalize( $person );
			}
		}

		$used   = array();
		$people = array();
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}

			$lookup = mb_strtolower( $name );
			if ( isset( $by_name[ $lookup ] ) ) {
				$person         = $by_name[ $lookup ];
				$person['name'] = $name;
			} else {
				$person = array(
					'key'      => self::mint_key( $used ),
					'name'     => $name,
					'pin_hash' => '',
					'role'     => self::DEFAULT_ROLE,
					'active'   => true,
				);
			}

			$used[]   = $person['key'];
			$people[] = $person;
		}

		return $people;
	}

	/* ---------------------------------------------------------------------- */
	/* Internals */
	/* ---------------------------------------------------------------------- */

	/**
	 * Public-facing shape of a person — pin_hash must never leave the server.
	 *
	 * @param array<string,mixed> $person Normalized person record.
	 * @return array{key:string,name:string,role:string}
	 */
	public static function public_shape( array $person ): array {
		return array(
			'key'  => (string) $person['key'],
			'name' => (string) $person['name'],
			'role' => (string) $person['role'],
		);
	}

	/**
	 * Coerce a stored record into the canonical shape.
	 *
	 * @param array<string,mixed> $person Raw stored record.
	 * @return array{key:string,name:string,pin_hash:string,role:string,active:bool}
	 */
	private static function normalize( array $person ): array {
		$role = (string) ( $person['role'] ?? self::DEFAULT_ROLE );

		return array(
			'key'      => sanitize_key( (string) ( $person['key'] ?? '' ) ),
			'name'     => (string) ( $person['name'] ?? '' ),
			'pin_hash' => (string) ( $person['pin_hash'] ?? '' ),
			'role'     => in_array( $role, self::ROLES, true ) ? $role : self::DEFAULT_ROLE,
			'active'   => ! isset( $person['active'] ) || (bool) $person['active'],
		);
	}

	/**
	 * Write the people list back, keeping the legacy `names` mirror in sync.
	 *
	 * `names` stays authoritative for nothing, but Options::employees() and the
	 * salidas picker still read it, so it must never drift.
	 *
	 * @param array<int,array<string,mixed>> $people People records.
	 */
	private static function persist( array $people ): void {
		$stored = get_option( Options::EMPLOYEES, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['people'] = array_values( $people );
		$stored['names']  = array_values( array_map( static fn( array $p ): string => (string) $p['name'], $people ) );

		update_option( Options::EMPLOYEES, $stored );
	}

	/**
	 * A short, collision-checked person key.
	 *
	 * @param string[] $used Keys already taken in this batch.
	 */
	private static function mint_key( array $used ): string {
		$existing = array_map( static fn( array $p ): string => $p['key'], self::people() );
		$taken    = array_merge( $existing, $used );

		do {
			$key = strtolower( wp_generate_password( 8, false, false ) );
		} while ( in_array( $key, $taken, true ) );

		return $key;
	}

	/**
	 * Constant-time-ish PIN check against the stored hash.
	 *
	 * @param string $pin  Raw PIN as typed.
	 * @param string $hash Stored hash.
	 */
	private static function check_pin( string $pin, string $hash ): bool {
		return wp_check_password( $pin, $hash );
	}

	/**
	 * Sign a session token. The pin_hash in the key material is what makes a
	 * PIN change revoke outstanding sessions.
	 *
	 * @param array<string,mixed> $person Normalized person record.
	 * @param int                 $expiry Unix expiry.
	 */
	private static function sign( array $person, int $expiry ): string {
		return hash_hmac(
			'sha256',
			$person['key'] . '|' . $expiry,
			wp_salt( 'auth' ) . $person['pin_hash']
		);
	}

	/**
	 * Sign a single-action supervisor authorization.
	 *
	 * @param array<string,mixed> $person Normalized supervisor record.
	 * @param string              $action Action key.
	 * @param int                 $expiry Unix expiry.
	 */
	private static function sign_authorization( array $person, string $action, int $expiry ): string {
		return hash_hmac(
			'sha256',
			$person['key'] . '|' . $action . '|' . $expiry,
			wp_salt( 'auth' ) . $person['pin_hash'] . '|authz'
		);
	}

	/**
	 * Issue a session token for a verified person.
	 *
	 * @param array<string,mixed> $person  Normalized person record.
	 * @param int                 $expires Unix expiry.
	 */
	private static function issue_token( array $person, int $expires ): string {
		return $person['key'] . '.' . $expires . '.' . self::sign( $person, $expires );
	}

	/** Transient key holding the recent-failure count for a person. */
	private static function throttle_key( string $person_key ): string {
		return 'haramara_pin_fail_' . $person_key;
	}

	/**
	 * Whether a person is currently locked out.
	 *
	 * @param string $person_key Person key.
	 */
	private static function is_locked( string $person_key ): bool {
		return (int) get_transient( self::throttle_key( $person_key ) ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * Record a failed attempt, restarting the window on the first failure.
	 *
	 * @param string $person_key Person key.
	 */
	private static function note_failure( string $person_key ): void {
		$key   = self::throttle_key( $person_key );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::LOCKOUT_WINDOW );
	}

	/**
	 * Clear the failure counter after a success or a PIN change.
	 *
	 * @param string $person_key Person key.
	 */
	private static function clear_failures( string $person_key ): void {
		delete_transient( self::throttle_key( $person_key ) );
	}

	/** The single error returned for every failed-credential path. */
	private static function bad_pin_error(): \WP_Error {
		return new \WP_Error(
			'haramara_operator_bad_pin',
			__( 'NIP incorrecto.', 'haramara-core' ),
			array( 'status' => 401 )
		);
	}

	/** The single error returned for every malformed/unknown token. */
	private static function bad_token_error(): \WP_Error {
		return new \WP_Error(
			'haramara_operator_bad_token',
			__( 'Sesión de operador no válida. Vuelve a ingresar tu NIP.', 'haramara-core' ),
			array( 'status' => 401 )
		);
	}

	/** The single error returned for every malformed/unknown authorization. */
	private static function bad_authorization_error(): \WP_Error {
		return new \WP_Error(
			'haramara_authorization_invalid',
			__( 'Autorización no válida.', 'haramara-core' ),
			array( 'status' => 403 )
		);
	}
}
