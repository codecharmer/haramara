<?php
/**
 * Modifier groups (grupos de modificadores).
 *
 * A modifier group is a named, ordered set of options a barista walks through
 * when ringing a product — "Leche: entera / avena (+$15) / deslactosada",
 * "Extras: shot extra (+$20)". Groups are stored in a private CPT
 * (`haramara_modgroup`) with the option list, selection rules (min/max/
 * required) and the assignment (product IDs and/or product_cat term IDs) in
 * post meta. Deliberately NOT WooCommerce variations and NOT separate
 * products: a selection is applied at sale time as visible order-item meta
 * with the line subtotal adjusted, so stock stays on the base product.
 *
 * Resolution order and the API shape live in Catalog\ModifierResolver;
 * sale-time validation/application in Catalog\ModifierApplication.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Catalog;

use Haramara\Core\Admin\Dashboard;
use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CPT registration + the wp-admin editing UI for modifier groups.
 */
final class ModifierGroups implements Bootable {

	/** Storage post type. Ordered with menu_order (the "Orden" attribute box). */
	public const POST_TYPE = 'haramara_modgroup';

	/** Minimum selections (int >= 0). */
	public const META_MIN = '_haramara_mod_min';

	/** Maximum selections (int; 0 = no limit). */
	public const META_MAX = '_haramara_mod_max';

	/** Required flag ('1' when the group cannot be skipped). */
	public const META_REQUIRED = '_haramara_mod_required';

	/** Ordered option list: array<int, array{name:string, price_delta:float}>. */
	public const META_OPTIONS = '_haramara_mod_options';

	/** Directly-assigned product IDs: int[]. */
	public const META_PRODUCTS = '_haramara_mod_products';

	/** Assigned product_cat term IDs (category defaults): int[]. */
	public const META_CATS = '_haramara_mod_cats';

	/** Nonce for the meta box save. */
	private const NONCE_ACTION = 'haramara_modgroup_save';
	private const NONCE_FIELD  = 'haramara_modgroup_nonce';

	/** Sanity ceilings for the selection rules. */
	private const MAX_RULE = 20;

	/**
	 * Hook registration.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Private-but-editable CPT under the Haramara admin menu.
	 *
	 * All primitive capabilities collapse onto the operations capability
	 * (`manage_haramara`), so exactly the people who run the café — admins and
	 * shop managers — can edit groups.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => Dashboard::SLUG,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'page-attributes' ),
				'labels'              => array(
					'name'               => __( 'Modificadores', 'haramara-core' ),
					'singular_name'      => __( 'Grupo de modificadores', 'haramara-core' ),
					'add_new'            => __( 'Agregar grupo', 'haramara-core' ),
					'add_new_item'       => __( 'Agregar grupo de modificadores', 'haramara-core' ),
					'edit_item'          => __( 'Editar grupo de modificadores', 'haramara-core' ),
					'new_item'           => __( 'Nuevo grupo de modificadores', 'haramara-core' ),
					'view_item'          => __( 'Ver grupo de modificadores', 'haramara-core' ),
					'search_items'       => __( 'Buscar grupos', 'haramara-core' ),
					'not_found'          => __( 'No hay grupos de modificadores.', 'haramara-core' ),
					'not_found_in_trash' => __( 'No hay grupos en la papelera.', 'haramara-core' ),
					'menu_name'          => __( 'Modificadores', 'haramara-core' ),
				),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => Activator::CAP,
					'read_post'              => Activator::CAP,
					'delete_post'            => Activator::CAP,
					'edit_posts'             => Activator::CAP,
					'edit_others_posts'      => Activator::CAP,
					'edit_published_posts'   => Activator::CAP,
					'edit_private_posts'     => Activator::CAP,
					'delete_posts'           => Activator::CAP,
					'delete_others_posts'    => Activator::CAP,
					'delete_published_posts' => Activator::CAP,
					'delete_private_posts'   => Activator::CAP,
					'publish_posts'          => Activator::CAP,
					'read_private_posts'     => Activator::CAP,
					'create_posts'           => Activator::CAP,
				),
			)
		);
	}

	/**
	 * The two editing boxes: rules + options, and the assignment.
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'haramara-mod-options',
			__( 'Reglas y opciones', 'haramara-core' ),
			array( $this, 'render_options_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'haramara-mod-assignment',
			__( 'Se aplica a', 'haramara-core' ),
			array( $this, 'render_assignment_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Meta box: reglas y opciones */
	/* ---------------------------------------------------------------------- */

	/**
	 * Selection rules + the ordered, repeatable option list.
	 *
	 * @param \WP_Post $post Group being edited.
	 */
	public function render_options_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$min      = absint( (string) get_post_meta( $post->ID, self::META_MIN, true ) );
		$max      = absint( (string) get_post_meta( $post->ID, self::META_MAX, true ) );
		$required = '1' === (string) get_post_meta( $post->ID, self::META_REQUIRED, true );

		$options = get_post_meta( $post->ID, self::META_OPTIONS, true );
		$options = is_array( $options ) ? array_values( $options ) : array();
		if ( array() === $options ) {
			$options = array(
				array(
					'name'        => '',
					'price_delta' => 0.0,
				),
			);
		}
		?>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><label for="haramara-mod-min"><?php esc_html_e( 'Selecciones mínimas', 'haramara-core' ); ?></label></th>
				<td>
					<input type="number" id="haramara-mod-min" name="haramara_mod_min" value="<?php echo esc_attr( (string) $min ); ?>" min="0" max="<?php echo esc_attr( (string) self::MAX_RULE ); ?>" step="1" class="small-text">
					<p class="description"><?php esc_html_e( 'Mínimo de opciones al elegir este grupo. Si el grupo no es obligatorio, puede omitirse por completo.', 'haramara-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="haramara-mod-max"><?php esc_html_e( 'Selecciones máximas', 'haramara-core' ); ?></label></th>
				<td>
					<input type="number" id="haramara-mod-max" name="haramara_mod_max" value="<?php echo esc_attr( (string) $max ); ?>" min="0" max="<?php echo esc_attr( (string) self::MAX_RULE ); ?>" step="1" class="small-text">
					<p class="description"><?php esc_html_e( '0 = sin límite. Con 1, las opciones funcionan como selección única.', 'haramara-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Obligatorio', 'haramara-core' ); ?></th>
				<td>
					<label for="haramara-mod-required">
						<input type="checkbox" id="haramara-mod-required" name="haramara_mod_required" value="1" <?php checked( $required ); ?>>
						<?php esc_html_e( 'El grupo no puede omitirse al vender el producto.', 'haramara-core' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Opciones', 'haramara-core' ); ?></th>
				<td>
					<div id="haramara-mod-options-list">
						<?php foreach ( $options as $option ) : ?>
							<?php
							$option = (array) $option;
							$name   = (string) ( $option['name'] ?? '' );
							$delta  = (float) ( $option['price_delta'] ?? 0 );
							?>
							<div class="haramara-mod-option" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
								<input type="text" name="haramara_mod_option_name[]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Nombre (ej. Avena)', 'haramara-core' ); ?>">
								<span aria-hidden="true">$</span>
								<input type="number" name="haramara_mod_option_price[]" value="<?php echo esc_attr( 0.0 === $delta ? '0' : (string) $delta ); ?>" step="0.01" class="small-text" style="width:90px" placeholder="0.00" title="<?php esc_attr_e( 'Ajuste de precio en MXN (puede ser 0 o negativo).', 'haramara-core' ); ?>">
								<button type="button" class="button-link haramara-mod-remove" aria-label="<?php esc_attr_e( 'Quitar opción', 'haramara-core' ); ?>">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>
					<button type="button" class="button" id="haramara-mod-add"><?php esc_html_e( 'Agregar opción', 'haramara-core' ); ?></button>
					<p class="description"><?php esc_html_e( 'El orden de esta lista es el orden en el POS y la tienda. El ajuste de precio es por unidad, en MXN; puede ser 0 o negativo. Las filas sin nombre se descartan al guardar.', 'haramara-core' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<script>
		( function () {
			var list = document.getElementById( 'haramara-mod-options-list' );
			var add  = document.getElementById( 'haramara-mod-add' );
			if ( ! list || ! add ) {
				return;
			}
			add.addEventListener( 'click', function () {
				var row  = list.querySelector( '.haramara-mod-option' );
				var copy = row.cloneNode( true );
				copy.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
				list.appendChild( copy );
			} );
			list.addEventListener( 'click', function ( event ) {
				var target = event.target;
				if ( ! target || ! target.classList || ! target.classList.contains( 'haramara-mod-remove' ) ) {
					return;
				}
				var row = target.closest( '.haramara-mod-option' );
				if ( list.querySelectorAll( '.haramara-mod-option' ).length > 1 ) {
					row.remove();
				} else {
					row.querySelectorAll( 'input' ).forEach( function ( input ) {
						input.value = '';
					} );
				}
			} );
		} )();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------- */
	/* Meta box: asignación */
	/* ---------------------------------------------------------------------- */

	/**
	 * Which products (directly) and which categories (as defaults) get this
	 * group. Assignment lives on the group, not on the product, so one group
	 * edit updates every product it covers.
	 *
	 * @param \WP_Post $post Group being edited.
	 */
	public function render_assignment_box( \WP_Post $post ): void {
		$product_ids = get_post_meta( $post->ID, self::META_PRODUCTS, true );
		$product_ids = is_array( $product_ids ) ? array_map( 'intval', $product_ids ) : array();

		$cat_ids = get_post_meta( $post->ID, self::META_CATS, true );
		$cat_ids = is_array( $cat_ids ) ? array_map( 'intval', $cat_ids ) : array();

		echo '<p class="description">' . esc_html__( 'Un producto recibe primero sus grupos asignados directamente y después los de sus categorías, sin duplicados.', 'haramara-core' ) . '</p>';

		echo '<h4>' . esc_html__( 'Productos (asignación directa)', 'haramara-core' ) . '</h4>';
		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'limit'   => 200,
					'status'  => 'publish',
					'orderby' => 'title',
					'order'   => 'ASC',
				)
			);
			$products = is_array( $products ) ? $products : array();

			echo '<div style="max-height:220px;overflow-y:auto;border:1px solid #dcdcde;padding:8px 12px;">';
			foreach ( $products as $product ) {
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				printf(
					'<label style="display:block;margin:2px 0;"><input type="checkbox" name="haramara_mod_products[]" value="%1$d" %2$s> %3$s</label>',
					(int) $product->get_id(),
					checked( in_array( (int) $product->get_id(), $product_ids, true ), true, false ),
					esc_html( $product->get_name() )
				);
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'WooCommerce no está disponible; no se pueden listar los productos.', 'haramara-core' ) . '</p>';
		}

		echo '<h4>' . esc_html__( 'Categorías (aplica por defecto)', 'haramara-core' ) . '</h4>';
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_array( $terms ) && array() !== $terms ) {
			echo '<div style="max-height:160px;overflow-y:auto;border:1px solid #dcdcde;padding:8px 12px;">';
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				printf(
					'<label style="display:block;margin:2px 0;"><input type="checkbox" name="haramara_mod_cats[]" value="%1$d" %2$s> %3$s</label>',
					(int) $term->term_id,
					checked( in_array( (int) $term->term_id, $cat_ids, true ), true, false ),
					esc_html( $term->name )
				);
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'No hay categorías de producto.', 'haramara-core' ) . '</p>';
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Save */
	/* ---------------------------------------------------------------------- */

	/**
	 * Persist rules, options and assignment from the meta boxes.
	 *
	 * @param int      $post_id Group post ID.
	 * @param \WP_Post $post    Group post.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( Activator::CAP ) ) {
			return;
		}

		// Rules. Max 0 means "no limit"; a positive max below min is a typo —
		// lift it to min so the group never becomes unsatisfiable.
		$min = isset( $_POST['haramara_mod_min'] ) ? min( self::MAX_RULE, absint( wp_unslash( $_POST['haramara_mod_min'] ) ) ) : 0;
		$max = isset( $_POST['haramara_mod_max'] ) ? min( self::MAX_RULE, absint( wp_unslash( $_POST['haramara_mod_max'] ) ) ) : 0;
		if ( $max > 0 && $max < $min ) {
			$max = $min;
		}
		$required = ! empty( $_POST['haramara_mod_required'] );

		update_post_meta( $post_id, self::META_MIN, $min );
		update_post_meta( $post_id, self::META_MAX, $max );
		update_post_meta( $post_id, self::META_REQUIRED, $required ? '1' : '' );

		// Options: parallel name/price arrays, paired by index, DOM order kept.
		$names  = isset( $_POST['haramara_mod_option_name'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['haramara_mod_option_name'] ) ) : array();
		$prices = isset( $_POST['haramara_mod_option_price'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['haramara_mod_option_price'] ) ) : array();

		$options = array();
		foreach ( array_values( $names ) as $index => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$raw_price = (string) ( array_values( $prices )[ $index ] ?? '0' );
			$options[] = array(
				'name'        => $name,
				'price_delta' => round( (float) str_replace( ',', '.', $raw_price ), 2 ),
			);
		}
		update_post_meta( $post_id, self::META_OPTIONS, $options );

		// Assignment.
		$product_ids = isset( $_POST['haramara_mod_products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['haramara_mod_products'] ) ) : array();
		$cat_ids     = isset( $_POST['haramara_mod_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['haramara_mod_cats'] ) ) : array();

		update_post_meta( $post_id, self::META_PRODUCTS, array_values( array_unique( array_filter( $product_ids ) ) ) );
		update_post_meta( $post_id, self::META_CATS, array_values( array_unique( array_filter( $cat_ids ) ) ) );
	}
}
