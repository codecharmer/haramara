<?php
/**
 * Block binding source: haramara/business.
 *
 * Lets any bindable block (paragraph, heading, button, image) surface live
 * business data — address, hours, phone, social — without hardcoding it in
 * templates or patterns. The data is owned by the haramara-core plugin
 * (option `haramara_business_info`); the theme reads it defensively with
 * sensible fallbacks so patterns still render before the plugin seeds data.
 *
 * Usage in block markup:
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{
 *     "source":"haramara/business","args":{"key":"address"}}}}} -->
 *
 * @package Haramara
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default business info. The plugin overrides these via the option; kept here so
 * the theme is never blank if the plugin is briefly inactive during setup.
 *
 * @return array<string,string>
 */
function haramara_business_defaults(): array {
	// Fallback for when the plugin option is briefly empty. The live values are
	// owned by haramara-core Options (haramara_business_info). Keep in sync.
	return array(
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
	);
}

/**
 * Resolve a single business-info value.
 */
function haramara_business_value( string $key ): string {
	$stored = get_option( 'haramara_business_info', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$data   = array_merge( haramara_business_defaults(), array_filter( $stored, 'is_scalar' ) );
	$value  = $data[ $key ] ?? '';
	return (string) $value;
}

/**
 * Register the binding source.
 */
function haramara_register_business_binding(): void {
	if ( ! function_exists( 'register_block_bindings_source' ) ) {
		return; // WordPress < 6.5.
	}

	register_block_bindings_source(
		'haramara/business',
		array(
			'label'              => __( 'Haramara — Datos del negocio', 'haramara' ),
			'get_value_callback' => static function ( array $source_args ): string {
				$key = isset( $source_args['key'] ) ? sanitize_key( (string) $source_args['key'] ) : '';
				if ( '' === $key ) {
					return '';
				}
				return haramara_business_value( $key );
			},
			'uses_context'       => array(),
		)
	);
}
add_action( 'init', 'haramara_register_business_binding' );
