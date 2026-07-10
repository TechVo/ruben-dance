<?php
/**
 * Keeps customers out of `wp-admin` entirely and blocks login before email
 * verification (spec F4, §5 layer 2).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Roles;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Access_Restrictions.
 *
 * Three independent hooks: hide the admin bar for anyone without the
 * plugin's own `rd_manage` capability (spec: "Customers get the `subscriber`
 * role and are blocked from `wp-admin` entirely"), redirect such a user away
 * the moment they hit any `wp-admin` screen, and reject login for an account
 * whose email hasn't been verified yet. `rd_manage` (not the `subscriber`
 * role specifically) is the dividing line, matching `Roles`: it correctly
 * also lets `rd_manager`/`administrator` accounts keep full access.
 */
class Access_Restrictions {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar_for_customers' ) );
		add_action( 'admin_init', array( self::class, 'redirect_customers_from_wp_admin' ) );
		add_filter( 'wp_authenticate_user', array( self::class, 'block_unverified_login' ), 10, 2 );
	}

	/**
	 * Hide the front-end admin toolbar for anyone who isn't back-office staff.
	 *
	 * @param bool $show Current value.
	 * @return bool
	 */
	public static function hide_admin_bar_for_customers( bool $show ): bool {
		if ( ! is_user_logged_in() || current_user_can( Roles::CAPABILITY ) ) {
			return $show;
		}

		return false;
	}

	/**
	 * Redirect a logged-in customer who navigates to any `wp-admin` screen
	 * back to the front end. Skips AJAX requests (`admin-ajax.php` is a
	 * legitimate front-end touchpoint for logged-in users) and anyone with
	 * the plugin's manage capability.
	 */
	public static function redirect_customers_from_wp_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() || current_user_can( Roles::CAPABILITY ) ) {
			return;
		}

		/**
		 * Where a customer is sent instead of `wp-admin`. M09 ("My account")
		 * hooks this to point at the seeded `[rd_account]` page once it
		 * exists; until then it defaults to the front page.
		 *
		 * @param string $url Redirect target, defaults to `home_url( '/' )`.
		 */
		$redirect_url = apply_filters( 'ruben_dance_subscriber_redirect_url', home_url( '/' ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Reject `wp_signon()`/`wp_authenticate()` for an account whose email
	 * hasn't been verified yet — runs only once the password has already
	 * been confirmed correct (this filter never fires for a nonexistent
	 * user or a wrong password), so it cannot be used to enumerate accounts.
	 *
	 * @param \WP_User|\WP_Error $user     Authenticated user, or an existing
	 *                                     error from an earlier check.
	 * @param string             $password Submitted password (unused; already checked).
	 * @return \WP_User|\WP_Error
	 */
	public static function block_unverified_login( $user, string $password ) {
		unset( $password );

		if ( ! ( $user instanceof \WP_User ) ) {
			return $user;
		}

		// Only accounts that went through `Registration_Service::create_account()`
		// carry this meta key at all (it is always written, to '0' or '1' —
		// see that method's doc comment); anything else, e.g. an
		// admin-created account, is never blocked here.
		if ( ! metadata_exists( 'user', $user->ID, Registration_Service::META_EMAIL_VERIFIED ) ) {
			return $user;
		}

		if ( '1' === get_user_meta( $user->ID, Registration_Service::META_EMAIL_VERIFIED, true ) ) {
			return $user;
		}

		return new \WP_Error(
			'rd_account_unverified',
			__( 'Please verify your email address before logging in. Check your inbox for the verification link.', 'ruben-dance' )
		);
	}
}
