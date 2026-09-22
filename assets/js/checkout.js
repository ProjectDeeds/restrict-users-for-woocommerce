/* global jQuery */
jQuery( function( $ ) {
	'use strict';

	function disableCheckout() {
		// Remove entire order review section.
		var review = $( 'div#order_review, .woocommerce-info, form.checkout' );
		review.css( {
			'visibility': 'hidden',
			'opacity': '0',
			'display': 'none'
		} );
	}

	disableCheckout();
	$( document.body ).on( 'updated_checkout', disableCheckout );
} );
