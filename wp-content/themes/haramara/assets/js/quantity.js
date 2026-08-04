/**
 * Haramara — quantity stepper for product-grid add-to-cart buttons.
 *
 * The steppers mutate the `quantityToAdd` value in WooCommerce's own
 * `woocommerce/product-button` Interactivity API context, so the native
 * add-to-cart action, button animation and mini-cart badge all keep
 * working untouched.
 */
import { store, getContext } from '@wordpress/interactivity';

const MAX_QTY = 99;

store( 'haramara/qty', {
	actions: {
		inc: () => {
			const ctx = getContext( 'woocommerce/product-button' );
			if ( ctx ) {
				ctx.quantityToAdd = Math.min( ( ctx.quantityToAdd || 1 ) + 1, MAX_QTY );
			}
		},
		dec: () => {
			const ctx = getContext( 'woocommerce/product-button' );
			if ( ctx ) {
				ctx.quantityToAdd = Math.max( ( ctx.quantityToAdd || 1 ) - 1, 1 );
			}
		},
	},
} );
