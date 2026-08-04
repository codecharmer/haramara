/**
 * Haramara theme — compiled JS entry point.
 *
 * This is the entry for the optional `@wordpress/scripts` build pipeline. It is
 * intentionally empty of runtime logic today: the shipped front-end enhancement
 * script is hand-authored, dependency-free, and enqueued directly from
 * `inc/assets.php` as `assets/js/enhance.js` (progressive scroll-reveal + header
 * state, guarded by `prefers-reduced-motion`).
 *
 * Use this entry when a future feature needs a bundled/transpiled module
 * (e.g. an interactive block view-script or a checkout enhancement). Anything
 * imported here is compiled to `assets/build/index.js` with an accompanying
 * `index.asset.php` you can pass to `wp_enqueue_script()` for dependency and
 * version metadata.
 *
 * Example (uncomment when needed):
 *   import './modules/pickup-slot-picker';
 */

// eslint-disable-next-line no-console
if ( typeof window !== 'undefined' && window.wp && window.wp.data ) {
	// Placeholder: WordPress data layer is available in the block editor context.
}

export {};
