<?php
/**
 * The "enrollment created" email pair (spec F14 E2 + E3), shared by every
 * channel that creates an enrollment.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

use RubenDance\Lang;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Created_Emails.
 *
 * Spec F14's E2/E3 trigger is "Enrollment created" — regardless of channel,
 * so the public `[rd_enroll]` form (`Front\Enrollment_Form_Handler`) and the
 * admin phone-order screen (`Admin\Manual_Enrollment_Page`) both call this
 * one method instead of each wiring E2 and E3 separately: the customer gets
 * the summary + payment instructions in their stored locale, and the admin
 * notification address (when configured in Settings) gets E3, always in
 * Czech. Both sends go through `Email_Sender`, so both are logged with
 * `sent`/`failed` status either way.
 */
class Enrollment_Created_Emails {

	/**
	 * Send E2 (customer) and E3 (admin) for a just-created enrollment.
	 *
	 * @param array<string, mixed>      $enrollment Enrollment row.
	 * @param array<string, mixed>|null $term       Term row, or null when it no longer exists.
	 * @return bool True when every attempted send succeeded (an enrollment
	 *              whose account no longer exists has nothing to deliver and
	 *              also counts as true — there is no failed delivery to
	 *              surface, and nothing was logged as failed).
	 */
	public static function send( array $enrollment, ?array $term ): bool {
		$user = get_userdata( (int) $enrollment['user_id'] );

		if ( false === $user ) {
			return true;
		}

		$sender        = Email_Sender::create_default();
		$enrollment_id = (int) $enrollment['id'];
		$lang          = Enrollment_Email_Data::user_lang( $user->ID );

		$ok = $sender->send(
			Email_Templates::TYPE_E2,
			$lang,
			$user->user_email,
			Enrollment_Email_Data::placeholders( $enrollment, $term, $user, $lang ),
			$enrollment_id,
			$user->ID,
			Payment_Qr_Email::augmenter( $enrollment, $term, $lang )
		);

		$admin_email = Settings::admin_notification_email();

		if ( '' !== $admin_email ) {
			$admin_ok = $sender->send(
				Email_Templates::TYPE_E3,
				Lang::CS,
				$admin_email,
				Enrollment_Email_Data::placeholders( $enrollment, $term, $user, Lang::CS ),
				$enrollment_id,
				$user->ID
			);

			$ok = $ok && $admin_ok;
		}

		return $ok;
	}
}
