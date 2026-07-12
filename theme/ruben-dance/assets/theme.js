/**
 * Mobile menu progressive enhancement.
 *
 * The menu itself (open/close) is a native <details>/<summary> disclosure
 * in header.php — that already works with zero JavaScript, keyboard and
 * all, which is the whole point: nothing here is required for the menu to
 * be usable. This file only adds the polish a plain <details> doesn't give
 * you for free: closing when a visitor taps outside it or presses Escape,
 * and syncing aria-expanded on the trigger for assistive tech that doesn't
 * infer state from the open attribute.
 *
 * Vanilla JS, no dependencies, no build step — matches every other
 * plugin/theme asset in this repo (calendar.js, enroll-price.js, …).
 */
( function () {
	'use strict';

	var menu = document.querySelector( '.rd-mobile-menu' );

	if ( ! menu ) {
		return;
	}

	var trigger = menu.querySelector( '.rd-mobile-menu__trigger' );

	function close() {
		menu.open = false;
	}

	menu.addEventListener( 'toggle', function () {
		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', menu.open ? 'true' : 'false' );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( menu.open && ! menu.contains( event.target ) ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( menu.open && 'Escape' === event.key ) {
			close();

			if ( trigger ) {
				trigger.focus();
			}
		}
	} );
}() );
