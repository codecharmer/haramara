<?php
/**
 * Media importer.
 *
 * Resolves a logical `image_key` (e.g. "pan-de-masa-madre") to a real
 * attachment in the media library. If the client has dropped an authorized
 * Haramara photograph at `data/media/source/{image_key}.{jpg,png,webp}` we
 * sideload it; otherwise the product shares the one brand-seal placeholder
 * (the gold flame on carbon, an owner decision) until its photo lands —
 * swapping in real photography later is a drop-in operation with no code
 * change.
 *
 * The key → attachment-id map is cached in an option so imports are idempotent
 * and safe to re-run.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaImporter {

	/** Option holding the key → { id, source } map. */
	public const CACHE_OPTION = 'haramara_media_map';

	/** Marker meta stamped on every attachment we create (used by reset). */
	public const MARKER_META = '_haramara_seeded_media';

	/** Raster source extensions checked, in priority order. */
	private const RASTER_EXT = array( 'jpg', 'jpeg', 'png', 'webp' );

	/** Shared brand-seal placeholder for products awaiting photography. */
	private const PLACEHOLDER_FILE = 'placeholder-logo.webp';

	/** Map key under which the shared placeholder attachment is cached. */
	private const PLACEHOLDER_KEY = '_placeholder';

	/**
	 * Absolute path to the media source directory, ensuring it exists.
	 */
	public static function source_dir(): string {
		$dir = trailingslashit( HARAMARA_CORE_DIR ) . 'data/media/source';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return trailingslashit( $dir );
	}

	/**
	 * Ensure an attachment exists for the given image key and return its ID.
	 *
	 * @param string $image_key Logical media name (sanitised to a slug).
	 * @param string $alt       Alt text (Spanish) applied to the attachment.
	 * @return int Attachment ID, or 0 on failure.
	 */
	public static function ensure( string $image_key, string $alt = '' ): int {
		$key = sanitize_key( $image_key );
		if ( '' === $key ) {
			return 0;
		}

		$map        = self::map();
		$raster     = self::find_raster( $key );
		$cached     = $map[ $key ] ?? null;
		$cached_id  = is_array( $cached ) ? (int) ( $cached['id'] ?? 0 ) : 0;
		$cached_src = is_array( $cached ) ? (string) ( $cached['source'] ?? '' ) : '';

		// Return the cached attachment when it still exists and is up to date:
		// either it already came from a real photo, or no real photo is present.
		if ( $cached_id > 0 && get_post( $cached_id ) instanceof \WP_Post ) {
			$stale = ( null !== $raster && 'photo' !== $cached_src );
			if ( ! $stale ) {
				if ( '' !== $alt ) {
					update_post_meta( $cached_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
				}
				return $cached_id;
			}
		}

		if ( null !== $raster ) {
			$id     = self::sideload_file( $raster, $key . '.' . pathinfo( $raster, PATHINFO_EXTENSION ), $alt );
			$source = 'photo';
			if ( $id > 0 ) {
				self::generate_responsive( $id );
			}
		} else {
			// No photo yet: share the one brand-seal placeholder attachment
			// until the real photograph lands in data/media/source/.
			$id     = self::shared_placeholder_id();
			$source = 'placeholder';
		}

		if ( $id > 0 ) {
			// Re-read: shared_placeholder_id() may have written its own entry.
			$map         = self::map();
			$map[ $key ] = array(
				'id'     => $id,
				'source' => $source,
			);
			update_option( self::CACHE_OPTION, $map, false );
		}

		return $id;
	}

	/**
	 * Attachment ID of the shared brand-seal placeholder, sideloading it once.
	 */
	private static function shared_placeholder_id(): int {
		$map    = self::map();
		$cached = $map[ self::PLACEHOLDER_KEY ] ?? null;
		$id     = is_array( $cached ) ? (int) ( $cached['id'] ?? 0 ) : 0;
		if ( $id > 0 && get_post( $id ) instanceof \WP_Post ) {
			return $id;
		}

		$id = self::sideload_file(
			self::source_dir() . self::PLACEHOLDER_FILE,
			self::PLACEHOLDER_FILE,
			__( 'Sello de Haramara — fotografía en camino.', 'haramara-core' )
		);
		if ( $id > 0 ) {
			$map[ self::PLACEHOLDER_KEY ] = array(
				'id'     => $id,
				'source' => 'placeholder',
			);
			update_option( self::CACHE_OPTION, $map, false );
			self::generate_responsive( $id );
		}

		return $id;
	}

	/**
	 * Import (or re-import) every known image key. Returns key => attachment ID.
	 *
	 * @param array<string,string> $keys Map of image_key => alt text.
	 * @return array<string,int>
	 */
	public static function ensure_all( array $keys ): array {
		$out = array();
		foreach ( $keys as $key => $alt ) {
			$out[ sanitize_key( $key ) ] = self::ensure( (string) $key, (string) $alt );
		}
		return $out;
	}

	/**
	 * Generate WebP (and AVIF where Imagick supports it) siblings for every
	 * registered size of a raster attachment. No-op for SVG or when the image
	 * editor lacks support. Robust to a missing Imagick extension.
	 *
	 * @return array<int,string> Absolute paths of the sibling files created.
	 */
	public static function generate_responsive( int $attachment_id ): array {
		$generated = array();
		$file      = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return $generated;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 'image/svg+xml' === $mime || ! wp_attachment_is_image( $attachment_id ) ) {
			return $generated; // Vector or non-image: nothing to rasterise.
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Ensure the WP-registered sub-sizes exist before making siblings.
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		$dir     = trailingslashit( dirname( $file ) );
		$targets = array( $file );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$targets[] = $dir . $size['file'];
				}
			}
		}

		$formats = array();
		if ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$formats['image/webp'] = 'webp';
		}
		if ( wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$formats['image/avif'] = 'avif';
		}

		foreach ( $targets as $target ) {
			if ( ! is_readable( $target ) ) {
				continue;
			}
			foreach ( $formats as $format_mime => $ext ) {
				$sibling = preg_replace( '/\.[^.]+$/', '.' . $ext, $target );
				if ( ! is_string( $sibling ) || $sibling === $target || file_exists( $sibling ) ) {
					continue;
				}
				$editor = wp_get_image_editor( $target );
				if ( is_wp_error( $editor ) ) {
					continue;
				}
				$saved = $editor->save( $sibling, $format_mime );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
					$generated[] = (string) $saved['path'];
				}
			}
		}

		/**
		 * Fires after next-gen image siblings are generated for an attachment.
		 *
		 * @param int               $attachment_id The attachment.
		 * @param array<int,string> $generated     Paths of files created.
		 */
		do_action( 'haramara_media_responsive_generated', $attachment_id, $generated );

		return $generated;
	}

	/* ---------------------------------------------------------------------- */
	/* Internals */
	/* ---------------------------------------------------------------------- */

	/** @return array<string,array{id:int,source:string}> */
	private static function map(): array {
		$map = get_option( self::CACHE_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/** Locate a real raster photo for the key, or null. */
	private static function find_raster( string $key ): ?string {
		$base = self::source_dir() . $key . '.';
		foreach ( self::RASTER_EXT as $ext ) {
			if ( is_readable( $base . $ext ) ) {
				return $base . $ext;
			}
		}
		return null;
	}

	/**
	 * Copy a local file into the uploads directory and register it as an
	 * attachment. Works for both raster images and SVG (which the standard
	 * sideload flow would reject on MIME grounds).
	 */
	private static function sideload_file( string $path, string $filename, string $alt ): int {
		if ( ! is_readable( $path ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents ) {
			return 0;
		}

		$filename = sanitize_file_name( $filename );

		// wp_upload_bits() validates the name against get_allowed_mime_types(),
		// which omits SVG — so without this the placeholder branch fails
		// silently and the product ends up with no image at all. The file is
		// generated by placeholder_svg() from a slug, never user input, so the
		// type is permitted for this single write and the filter is removed
		// immediately afterwards rather than left on the site.
		$is_svg = 'svg' === strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$allow  = static function ( $mimes ) {
			$mimes['svg'] = 'image/svg+xml';
			return $mimes;
		};

		if ( $is_svg ) {
			add_filter( 'upload_mimes', $allow );
		}

		$upload = wp_upload_bits( $filename, null, $contents );

		if ( $is_svg ) {
			remove_filter( 'upload_mimes', $allow );
		}

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$ext  = strtolower( (string) pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
		$type = (string) wp_check_filetype( $upload['file'] )['type'];
		$mime = 'svg' === $ext ? 'image/svg+xml' : ( '' !== $type ? $type : 'application/octet-stream' );

		$attachment = array(
			'guid'           => $upload['url'],
			'post_mime_type' => $mime,
			'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $id ) || 0 === $id ) {
			return 0;
		}

		if ( 'image/svg+xml' !== $mime ) {
			$meta = wp_generate_attachment_metadata( $id, $upload['file'] );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( $id, $meta );
			}
		}

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		update_post_meta( $id, self::MARKER_META, 1 );

		return (int) $id;
	}

	/**
	 * Render a branded SVG placeholder for the key and write it into the source
	 * directory. Returns the file path, or '' on failure.
	 */
}
