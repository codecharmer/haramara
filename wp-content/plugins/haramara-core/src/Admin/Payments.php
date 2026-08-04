<?php
/**
 * Pagos — payment methods admin screen.
 *
 * One place to see and control how the bakery gets paid. COD ("Paga al
 * recoger") can be toggled here; the Stripe key fields WRITE THROUGH to the
 * Stripe gateway's own option (`woocommerce_stripe_settings`) so there is
 * exactly one source of truth and the gateway behaves as if configured from
 * its native screen. Mercado Pago's credentials live in its own plugin
 * (onboarding there is device-bound), so this screen surfaces status and
 * deep-links to it rather than duplicating fields.
 *
 * The mobile app reads gateway availability live from
 * `GET /haramara/v1/app/config` (Rest\AppRoutes::payment_flags), so flipping a
 * method here reaches the app without a release.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Admin;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Pagos screen + write-through persistence.
 */
final class Payments implements Bootable {

	private const SLUG = 'haramara-pagos';

	/** Stripe gateway option (owned by woocommerce-gateway-stripe). */
	public const STRIPE_OPTION = 'woocommerce_stripe_settings';

	/** COD gateway option (owned by WooCommerce core). */
	public const COD_OPTION = 'woocommerce_cod_settings';

	/**
	 * Register the menu + form handler.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
		add_action( 'admin_post_haramara_save_payments', array( $this, 'handle_save' ) );
	}

	/**
	 * Submenu under the Haramara operations menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Dashboard::SLUG,
			__( 'Pagos', 'haramara-core' ),
			__( 'Pagos', 'haramara-core' ),
			Activator::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	// ------------------------------------------------------------------ Screen.

	/**
	 * Render the three payment-method cards.
	 */
	public function render(): void {
		if ( ! current_user_can( Activator::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'haramara-core' ) );
		}

		$cod         = self::cod_settings();
		$stripe      = self::stripe_settings();
		$cod_on      = 'yes' === ( $cod['enabled'] ?? 'no' );
		$stripe_on   = 'yes' === ( $stripe['enabled'] ?? 'no' );
		$stripe_test = 'yes' === ( $stripe['testmode'] ?? 'yes' );
		$saved       = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		?>
		<div class="wrap haramara-wrap haramara-payments">
			<h1 class="haramara-title">
				<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Pagos', 'haramara-core' ); ?>
			</h1>
			<p class="haramara-subtitle"><?php esc_html_e( 'La app y la tienda en línea muestran automáticamente los métodos que estén activos aquí.', 'haramara-core' ); ?></p>

			<?php if ( '1' === $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración de pagos guardada.', 'haramara-core' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="haramara_save_payments">
				<?php wp_nonce_field( 'haramara_save_payments' ); ?>

				<h2><?php esc_html_e( 'Paga al recoger (efectivo o tarjeta en mostrador)', 'haramara-core' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Estado', 'haramara-core' ); ?></th>
						<td>
							<label for="pf-cod-enabled">
								<input type="checkbox" id="pf-cod-enabled" name="cod_enabled" value="1" <?php checked( $cod_on ); ?>>
								<?php esc_html_e( 'Aceptar pedidos que se pagan al recoger en el obrador.', 'haramara-core' ); ?>
							</label>
						</td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Stripe (tarjeta en línea)', 'haramara-core' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Pega aquí tus llaves de Stripe cuando las tengas. Con las llaves guardadas y el método activado, la tarjeta aparece sola en la tienda y en la app.', 'haramara-core' ); ?>
				</p>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Estado', 'haramara-core' ); ?></th>
						<td>
							<label for="pf-stripe-enabled">
								<input type="checkbox" id="pf-stripe-enabled" name="stripe_enabled" value="1" <?php checked( $stripe_on ); ?>>
								<?php esc_html_e( 'Activar pago con tarjeta vía Stripe.', 'haramara-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Sin llaves guardadas, el método no se muestra aunque esté activado.', 'haramara-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Modo', 'haramara-core' ); ?></th>
						<td>
							<label for="pf-stripe-testmode">
								<input type="checkbox" id="pf-stripe-testmode" name="stripe_testmode" value="1" <?php checked( $stripe_test ); ?>>
								<?php esc_html_e( 'Modo de prueba (usa las llaves de prueba; no se hacen cargos reales).', 'haramara-core' ); ?>
							</label>
						</td>
					</tr>
					<?php
					$this->key_row( 'stripe_test_publishable_key', __( 'Llave publicable de prueba', 'haramara-core' ), (string) ( $stripe['test_publishable_key'] ?? '' ), 'pk_test_…', false );
					$this->key_row( 'stripe_test_secret_key', __( 'Llave secreta de prueba', 'haramara-core' ), (string) ( $stripe['test_secret_key'] ?? '' ), 'sk_test_…', true );
					$this->key_row( 'stripe_publishable_key', __( 'Llave publicable (producción)', 'haramara-core' ), (string) ( $stripe['publishable_key'] ?? '' ), 'pk_live_…', false );
					$this->key_row( 'stripe_secret_key', __( 'Llave secreta (producción)', 'haramara-core' ), (string) ( $stripe['secret_key'] ?? '' ), 'sk_live_…', true );
					?>
				</tbody></table>

				<h2><?php esc_html_e( 'Mercado Pago', 'haramara-core' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Estado', 'haramara-core' ); ?></th>
						<td><?php $this->mercado_pago_status(); ?></td>
					</tr>
				</tbody></table>

				<?php submit_button( __( 'Guardar pagos', 'haramara-core' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One Stripe key field. Secrets render as password inputs; all values are
	 * shown from the gateway's own stored option.
	 *
	 * @param string $name        POST field name.
	 * @param string $label       Row label.
	 * @param string $value       Current stored value.
	 * @param string $placeholder Expected key-prefix hint.
	 * @param bool   $secret      Whether to mask the input.
	 */
	private function key_row( string $name, string $label, string $value, string $placeholder, bool $secret ): void {
		$id = 'pf-' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" placeholder="%5$s" autocomplete="off" spellcheck="false">',
			$secret ? 'password' : 'text',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
		echo '</td></tr>';
	}

	/**
	 * Mercado Pago card body: plugin present → deep-link to its onboarding
	 * (credentials are configured there); absent → say what to install.
	 */
	private function mercado_pago_status(): void {
		if ( defined( 'MP_VERSION' ) || class_exists( 'MercadoPago\Woocommerce\WoocommerceMercadoPago' ) ) {
			echo '<p>' . esc_html__( 'El plugin de Mercado Pago está instalado. Sus credenciales se configuran en su propia pantalla (requiere tu cuenta de vendedor).', 'haramara-core' ) . '</p>';
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=mercadopago-settings' ) ),
				esc_html__( 'Configurar credenciales de Mercado Pago', 'haramara-core' )
			);
			return;
		}
		echo '<p>' . esc_html__( 'El plugin de Mercado Pago aún no está activo en este sitio. Una vez activado, aquí aparecerá el enlace para pegar tus credenciales.', 'haramara-core' ) . '</p>';
	}

	// ------------------ Persistence (write-through to the gateways' options).

	/**
	 * Save handler. Nonce action: `haramara_save_payments`.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Activator::CAP ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'haramara-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'haramara_save_payments' );

		self::update_cod_enabled( ! empty( $_POST['cod_enabled'] ) );

		self::update_stripe_settings(
			array(
				'enabled'              => ! empty( $_POST['stripe_enabled'] ),
				'testmode'             => ! empty( $_POST['stripe_testmode'] ),
				'test_publishable_key' => isset( $_POST['stripe_test_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_test_publishable_key'] ) ) : '',
				'test_secret_key'      => isset( $_POST['stripe_test_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_test_secret_key'] ) ) : '',
				'publishable_key'      => isset( $_POST['stripe_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_publishable_key'] ) ) : '',
				'secret_key'           => isset( $_POST['stripe_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_secret_key'] ) ) : '',
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::SLUG,
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Current COD gateway settings, with sane defaults for a fresh install.
	 *
	 * @return array<string,mixed>
	 */
	public static function cod_settings(): array {
		$stored = get_option( self::COD_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge(
			array(
				'enabled'      => 'no',
				'title'        => __( 'Paga al recoger en el obrador', 'haramara-core' ),
				'description'  => __( 'Aparta tu pedido y paga en efectivo o con tarjeta al recogerlo.', 'haramara-core' ),
				'instructions' => __( 'Menciona tu número de pedido en el mostrador.', 'haramara-core' ),
			),
			$stored
		);
	}

	/**
	 * Current Stripe gateway settings (empty array when never saved).
	 *
	 * @return array<string,mixed>
	 */
	public static function stripe_settings(): array {
		$stored = get_option( self::STRIPE_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Toggle COD, seeding the branded defaults on first write.
	 *
	 * @param bool $enabled Whether "Paga al recoger" accepts orders.
	 */
	public static function update_cod_enabled( bool $enabled ): void {
		$settings            = self::cod_settings();
		$settings['enabled'] = $enabled ? 'yes' : 'no';
		update_option( self::COD_OPTION, $settings );
	}

	/**
	 * Merge Stripe fields into the gateway's own option. Only the keys managed
	 * by the Pagos screen are touched; everything else the gateway stores
	 * (titles, webhook secrets, UPE flags) is preserved.
	 *
	 * @param array{enabled:bool,testmode:bool,test_publishable_key:string,test_secret_key:string,publishable_key:string,secret_key:string} $input Sanitized form input.
	 */
	public static function update_stripe_settings( array $input ): void {
		$settings = self::stripe_settings();

		$settings['enabled']              = $input['enabled'] ? 'yes' : 'no';
		$settings['testmode']             = $input['testmode'] ? 'yes' : 'no';
		$settings['test_publishable_key'] = trim( $input['test_publishable_key'] );
		$settings['test_secret_key']      = trim( $input['test_secret_key'] );
		$settings['publishable_key']      = trim( $input['publishable_key'] );
		$settings['secret_key']           = trim( $input['secret_key'] );

		update_option( self::STRIPE_OPTION, $settings );
	}
}
