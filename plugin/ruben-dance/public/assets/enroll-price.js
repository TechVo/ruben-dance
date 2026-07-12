/**
 * [rd_enroll] form progressive enhancement — both pieces are optional
 * polish, not required for a working submission (the form posts and
 * validates identically with JavaScript disabled):
 *
 * 1. Live advisory price display: recomputes when the partner-name field is
 *    filled/cleared (pair discount). Advisory only — the server always
 *    recomputes the real price from scratch on submit
 *    (`Enrollment_Service::create()` never trusts anything client-supplied).
 * 2. Shows/hides the "participant name" field depending on whether "Me" or
 *    "Someone else" is selected — the field itself stays in the form either
 *    way, so a screen reader/no-JS visitor simply always sees it.
 *
 * @package RubenDance
 */
/* global rdEnrollPriceL10n */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var partnerField = document.getElementById( 'rd-enroll-partner-name' );
		var partnerRow = document.getElementById( 'rd-enroll-price-partner-row' );
		var totalDisplay = document.getElementById( 'rd-enroll-price-display' );
		var currencyDisplay = document.getElementById( 'rd-enroll-price-currency' );

		if ( totalDisplay && 'undefined' !== typeof rdEnrollPriceL10n ) {
			if ( currencyDisplay && rdEnrollPriceL10n.currency ) {
				currencyDisplay.textContent = rdEnrollPriceL10n.currency;
			}

			if ( partnerField ) {
				var basePriceEl = document.getElementById( 'rd-enroll-price-base' );
				var basePrice = parseFloat( rdEnrollPriceL10n.earlyBirdPrice || rdEnrollPriceL10n.price ) || 0;
				var pairDiscount = parseFloat( rdEnrollPriceL10n.pairDiscount ) || 0;
				var hasEarlyBird = '' !== ( rdEnrollPriceL10n.earlyBirdPrice || '' );
				// The server already rendered a nicely-formatted total ("2 100",
				// via Term_Presenter::format_price()) — kept and restored
				// whenever the live partner discount isn't in play, rather than
				// replaced by toFixed()'s plain "2100.00" for the common case
				// where nothing has changed.
				var defaultTotalText = totalDisplay.textContent;

				var recomputePrice = function () {
					var hasPartner = '' !== partnerField.value.trim() && pairDiscount > 0;

					if ( partnerRow ) {
						partnerRow.style.display = hasPartner ? '' : 'none';
					}

					if ( basePriceEl ) {
						basePriceEl.classList.toggle( 'rd-price__base-strike', hasEarlyBird || hasPartner );
					}

					if ( hasPartner ) {
						var price = Math.max( 0, basePrice - pairDiscount );

						totalDisplay.textContent = price.toFixed( 2 );
					} else {
						totalDisplay.textContent = defaultTotalText;
					}
				};

				partnerField.addEventListener( 'input', recomputePrice );
				recomputePrice();
			}
		}

		var typeRadios = document.querySelectorAll( 'input[name="participant_type"]' );
		var nameRow = document.getElementById( 'rd-enroll-participant-name-row' );

		if ( typeRadios.length && nameRow ) {
			var toggleNameRow = function () {
				var checked = document.querySelector( 'input[name="participant_type"]:checked' );
				nameRow.style.display = checked && 'other' === checked.value ? '' : 'none';
			};

			typeRadios.forEach( function ( radio ) {
				radio.addEventListener( 'change', toggleNameRow );
			} );
			toggleNameRow();
		}
	} );
} )();
