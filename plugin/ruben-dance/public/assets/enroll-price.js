/**
 * Live advisory price display on the [rd_enroll] form: recomputes when the
 * partner-name field is filled/cleared (pair discount). Advisory only — the
 * server always recomputes the real price from scratch on submit
 * (`Enrollment_Service::create()` never trusts anything client-supplied).
 *
 * @package RubenDance
 */
/* global rdEnrollPriceL10n */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var partnerField = document.getElementById( 'rd-enroll-partner-name' );
		var display = document.getElementById( 'rd-enroll-price-display' );
		var currencyDisplay = document.getElementById( 'rd-enroll-price-currency' );

		if ( ! display || 'undefined' === typeof rdEnrollPriceL10n ) {
			return;
		}

		if ( currencyDisplay && rdEnrollPriceL10n.currency ) {
			currencyDisplay.textContent = rdEnrollPriceL10n.currency;
		}

		if ( ! partnerField ) {
			return;
		}

		var basePrice = parseFloat( rdEnrollPriceL10n.earlyBirdPrice || rdEnrollPriceL10n.price ) || 0;
		var pairDiscount = parseFloat( rdEnrollPriceL10n.pairDiscount ) || 0;

		function recompute() {
			var hasPartner = '' !== partnerField.value.trim();
			var price = hasPartner ? Math.max( 0, basePrice - pairDiscount ) : basePrice;

			display.textContent = price.toFixed( 2 );
		}

		partnerField.addEventListener( 'input', recompute );
		recompute();
	} );
} )();
