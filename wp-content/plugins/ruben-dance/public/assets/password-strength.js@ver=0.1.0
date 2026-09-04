/**
 * Wires WordPress core's password-strength meter (`wp.passwordStrength`,
 * shipped by the `password-strength-meter` script handle this file
 * declares as a dependency) onto the `[rd_register]` front-end form.
 *
 * The strength calculation itself (score thresholds, blacklist, label
 * mapping) is unchanged from before D6's restyle — only the DOM write
 * target changed, from replacing the whole `#rd-register-password-strength`
 * wrapper's text to writing into a nested `.rd-password-strength__label`
 * span, so the (purely decorative, CSS-driven) bar markup the template now
 * renders inside that wrapper survives every keystroke instead of being
 * wiped by a `.text()` call.
 *
 * @package RubenDance
 */
/* global jQuery, wp, rdPasswordStrengthL10n */
( function ( $ ) {
	'use strict';

	$( function () {
		var $password = $( '#rd-register-password' );
		var $result   = $( '#rd-register-password-strength' );
		var $label    = $result.find( '.rd-password-strength__label' );

		if ( 0 === $password.length || 0 === $result.length || 0 === $label.length || 'undefined' === typeof wp || ! wp.passwordStrength ) {
			return;
		}

		var blacklist = wp.passwordStrength.userInputBlacklist();

		$password.on( 'keyup', function () {
			var value = $password.val();

			$result.removeClass( 'short bad good strong' );

			if ( '' === value ) {
				$label.text( '' );
				return;
			}

			var score  = wp.passwordStrength.meter( value, blacklist, value );
			var labels = {
				2: { css: 'bad', text: rdPasswordStrengthL10n.bad },
				3: { css: 'good', text: rdPasswordStrengthL10n.good },
				4: { css: 'strong', text: rdPasswordStrengthL10n.strong },
				5: { css: 'short', text: rdPasswordStrengthL10n.mismatch }
			};

			var label = labels[ score ] || { css: 'short', text: rdPasswordStrengthL10n.short };

			$result.addClass( label.css );
			$label.text( label.text );
		} );
	} );
} )( jQuery );
