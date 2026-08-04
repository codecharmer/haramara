<?php
/**
 * Rewrite-rule self-healing.
 *
 * Pretty permalinks are load-bearing (every theme link and the /en/ language
 * tree assume them), but neither `wp rewrite flush --hard` under wp-cli nor
 * `flush_rewrite_rules( false )` in the installer can create .htaccess: CLI
 * contexts fail the `got_mod_rewrite()` sniff, so the file is silently never
 * written and every non-root URL 404s. This service repairs that from the one
 * context that is always right — a real web request, running as the site's
 * PHP user, where WordPress can write its own canonical rules with correct
 * file ownership.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Setup;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewrites implements Bootable {

	public function boot(): void {
		add_action( 'init', array( $this, 'ensure_htaccess' ), 99 );
	}

	/**
	 * Write .htaccess via WordPress's own hard flush when it is missing.
	 *
	 * After the first successful write the guard is a single stat call per
	 * request. Skips CLI (wrong file owner) and multisite (rules live in the
	 * network config there).
	 */
	public function ensure_htaccess(): void {
		if ( 'cli' === PHP_SAPI || is_multisite() ) {
			return;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		if ( file_exists( ABSPATH . '.htaccess' ) || ! wp_is_writable( ABSPATH ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// FPM/proxy setups fail the mod_rewrite sniff even though Apache
		// honours .htaccess; force the write for this flush only.
		add_filter( 'got_rewrite', '__return_true' );
		flush_rewrite_rules( true );
		remove_filter( 'got_rewrite', '__return_true' );
	}
}
