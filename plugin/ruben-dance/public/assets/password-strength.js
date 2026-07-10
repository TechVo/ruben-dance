/**
 * Wires WordPress core's password-strength meter (`wp.passwordStrength`,
 * shipped by the `password-strength-meter` script handle this file
 * declares as a dependency) onto the `[rd_register]` front-end form.
 *
 * @package RubenDance
 */
/* global jQuery, wp, rdPasswordStrengthL10n */
( function ( $ ) {
	'use strict';

	$( function () {
		var $password = $( '#rd-register-password' );
		var $result   = $( '#rd-register-password-strength' );

		if ( 0 === $password.length || 0 === $result.length || 'undefined' === typeof wp || ! wp.passwordStrength ) {
			return;
		}

		var blacklist = wp.passwordStrength.userInputBlacklist();

		$password.on( 'keyup', function () {
			var value = $password.val();

			$result.removeClass( 'short bad good strong' );

			if ( '' === value ) {
				$result.text( '' );
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

			$result.addClass( label.css ).text( label.text );
		} );
	} );
} )( jQuery );
