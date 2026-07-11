<?php
/**
 * WP core personal-data export/erasure integration (spec §6.1:
 * `wp_privacy_personal_data_exporters` / `_erasers`).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Compliance;

use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Personal_Data.
 *
 * Registers exactly one exporter and one eraser, covering every piece of
 * personal data this plugin stores outside WordPress core's own tables: the
 * `rd_*` user meta (phone, locale, T&C/marketing consent) and every
 * `wp_rd_enrollment`/`wp_rd_email_log` row belonging to the requesting
 * account. `anonymize_user()` is the shared "erase this person" building
 * block: both the on-demand eraser callback below (`erase()`) and
 * `Services\Retention_Service` (for customers inactive past the retention
 * window) call it, so a request-driven erasure and a cron-driven one can
 * never anonymize an account differently.
 *
 * Every exporter/eraser callback here returns its *entire* result on page 1
 * and `done => true` — WP core's batching loop still gets a page number on
 * every call, but there is no realistic per-customer volume (one profile,
 * a personal enrollment history, an email log scoped to them) that needs
 * true multi-page pagination; returning early on `page > 1` still honors the
 * paging contract so the loop terminates correctly regardless.
 */
class Personal_Data {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * `wp_privacy_personal_data_exporters` filter callback.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['ruben-dance'] = array(
			'exporter_friendly_name' => __( 'Ruben Dance course enrollments', 'ruben-dance' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * `wp_privacy_personal_data_erasers` filter callback.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['ruben-dance'] = array(
			'eraser_friendly_name' => __( 'Ruben Dance course enrollments', 'ruben-dance' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export callback: profile meta, every enrollment, every email-log row —
	 * everything this plugin knows about the account behind `$email_address`.
	 *
	 * @param string $email_address Requester's email address.
	 * @param int    $page          1-based page number (see class doc comment).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user = get_user_by( 'email', $email_address );

		if ( false === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items = array();

		$items[] = array(
			'group_id'    => 'rd-profile',
			'group_label' => __( 'Ruben Dance profile', 'ruben-dance' ),
			'item_id'     => 'rd-profile-' . $user->ID,
			'data'        => self::profile_export_data( $user ),
		);

		foreach ( ( new Enrollment_Repository() )->for_user( $user->ID ) as $enrollment ) {
			$items[] = array(
				'group_id'    => 'rd-enrollments',
				'group_label' => __( 'Course enrollments', 'ruben-dance' ),
				'item_id'     => 'rd-enrollment-' . $enrollment['id'],
				'data'        => self::enrollment_export_data( $enrollment ),
			);
		}

		foreach ( ( new Email_Log_Repository() )->for_user_id( $user->ID ) as $log_row ) {
			$items[] = array(
				'group_id'    => 'rd-email-log',
				'group_label' => __( 'Email history', 'ruben-dance' ),
				'item_id'     => 'rd-email-log-' . $log_row['id'],
				'data'        => self::email_log_export_data( $log_row ),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase callback: anonymizes the account via `anonymize_user()` (enrollments
	 * are anonymized, not deleted — spec §6.1: accounting records must
	 * survive; the email log is purged outright).
	 *
	 * @param string $email_address Requester's email address.
	 * @param int    $page          Unused: see class doc comment (single-pass eraser).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email_address, int $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', $email_address );

		if ( false === $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		self::anonymize_user( (int) $user->ID );

		return array(
			'items_removed'  => true,
			'items_retained' => true, // Enrollments are anonymized, not deleted (kept for accounting).
			'messages'       => array(
				__( 'Enrollment records were anonymized rather than deleted, to preserve accounting records, as described in the privacy policy.', 'ruben-dance' ),
			),
			'done'           => true,
		);
	}

	/**
	 * Anonymize one account: scrubs the WP user record and the `rd_*` user
	 * meta that identifies them, anonymizes every enrollment (name → the
	 * shared `Anonymizer::LABEL`, `user_id` severed — see
	 * `Enrollment_Repository::anonymize_for_user()`), and purges their email
	 * log. Idempotent (guarded by `Registration_Service::META_ANONYMIZED_AT`)
	 * so calling it twice — a manual erasure request landing the same month
	 * the retention cron would have picked the same account — is a no-op the
	 * second time, rather than re-randomizing an already-anonymous email.
	 *
	 * @param int $user_id WP user ID.
	 * @return array{enrollments: int, email_log: int}
	 */
	public static function anonymize_user( int $user_id ): array {
		$already_anonymized = get_user_meta( $user_id, Registration_Service::META_ANONYMIZED_AT, true );

		if ( is_string( $already_anonymized ) && '' !== $already_anonymized ) {
			return array(
				'enrollments' => 0,
				'email_log'   => 0,
			);
		}

		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return array(
				'enrollments' => 0,
				'email_log'   => 0,
			);
		}

		$anonymized_local_part = 'anonymized-' . $user_id . '-' . wp_generate_password( 12, false, false );

		wp_update_user(
			array(
				'ID'           => $user_id,
				// Guaranteed unique (WP requires a unique `user_email`)
				// without leaking anything about the original address.
				'user_email'   => $anonymized_local_part . '@anonymized.invalid',
				'first_name'   => '',
				'last_name'    => '',
				'display_name' => __( 'Anonymized customer', 'ruben-dance' ),
				'user_pass'    => wp_generate_password( 32, true, true ), // Disables login; nobody is told this password.
			)
		);

		// `wp_update_user()` deliberately never changes `user_login` — but
		// `Registration_Service::create_account()` sets it to the customer's
		// email address at registration, so leaving it alone would let the
		// original email leak straight back out of an "anonymized" account
		// (e.g. via `WP_User::$user_login`, `wp_list_users()`,
		// `get_userdata()`). A direct `$wpdb->update()` is the documented way
		// around core's refusal to touch this column.
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_login' => $anonymized_local_part ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_user_cache( $user_id );

		delete_user_meta( $user_id, Registration_Service::META_PHONE );
		delete_user_meta( $user_id, Registration_Service::META_LOCALE );
		delete_user_meta( $user_id, Registration_Service::META_TC_ACCEPTED_AT );
		delete_user_meta( $user_id, Registration_Service::META_TC_VERSION );
		delete_user_meta( $user_id, Registration_Service::META_MARKETING_CONSENT );
		delete_user_meta( $user_id, Registration_Service::META_MARKETING_CONSENT_AT );
		update_user_meta( $user_id, Registration_Service::META_ANONYMIZED_AT, current_time( 'mysql' ) );

		$enrollments_updated = ( new Enrollment_Repository() )->anonymize_for_user( $user_id );
		$email_log_deleted   = ( new Email_Log_Repository() )->delete_for_user( $user_id );

		return array(
			'enrollments' => $enrollments_updated,
			'email_log'   => $email_log_deleted,
		);
	}

	/**
	 * Export "group" data for the profile/account item.
	 *
	 * @param \WP_User $user Account holder.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function profile_export_data( \WP_User $user ): array {
		return array(
			array(
				'name'  => __( 'First name', 'ruben-dance' ),
				'value' => $user->first_name,
			),
			array(
				'name'  => __( 'Last name', 'ruben-dance' ),
				'value' => $user->last_name,
			),
			array(
				'name'  => __( 'Email', 'ruben-dance' ),
				'value' => $user->user_email,
			),
			array(
				'name'  => __( 'Phone', 'ruben-dance' ),
				'value' => (string) get_user_meta( $user->ID, Registration_Service::META_PHONE, true ),
			),
			array(
				'name'  => __( 'Preferred language', 'ruben-dance' ),
				'value' => (string) get_user_meta( $user->ID, Registration_Service::META_LOCALE, true ),
			),
			array(
				'name'  => __( 'Terms & Conditions accepted at', 'ruben-dance' ),
				'value' => (string) get_user_meta( $user->ID, Registration_Service::META_TC_ACCEPTED_AT, true ),
			),
			array(
				'name'  => __( 'Terms & Conditions version accepted', 'ruben-dance' ),
				'value' => (string) get_user_meta( $user->ID, Registration_Service::META_TC_VERSION, true ),
			),
			array(
				'name'  => __( 'Marketing consent', 'ruben-dance' ),
				'value' => '1' === get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT, true )
					? __( 'Yes', 'ruben-dance' )
					: __( 'No', 'ruben-dance' ),
			),
			array(
				'name'  => __( 'Marketing consent given at', 'ruben-dance' ),
				'value' => (string) get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT_AT, true ),
			),
		);
	}

	/**
	 * Export "group" data for one enrollment item.
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function enrollment_export_data( array $enrollment ): array {
		return array(
			array(
				'name'  => __( 'Participant', 'ruben-dance' ),
				'value' => (string) $enrollment['participant_name'],
			),
			array(
				'name'  => __( 'Role', 'ruben-dance' ),
				'value' => (string) $enrollment['role'],
			),
			array(
				'name'  => __( 'Partner name', 'ruben-dance' ),
				'value' => (string) ( $enrollment['partner_name'] ?? '' ),
			),
			array(
				'name'  => __( 'Status', 'ruben-dance' ),
				'value' => (string) $enrollment['status'],
			),
			array(
				'name'  => __( 'Price', 'ruben-dance' ),
				'value' => (string) $enrollment['price'],
			),
			array(
				'name'  => __( 'Due date', 'ruben-dance' ),
				'value' => (string) $enrollment['due_date'],
			),
			array(
				'name'  => __( 'Paid at', 'ruben-dance' ),
				'value' => (string) ( $enrollment['paid_at'] ?? '' ),
			),
			array(
				'name'  => __( 'Terms & Conditions version accepted', 'ruben-dance' ),
				'value' => (string) ( $enrollment['tc_version'] ?? '' ),
			),
			array(
				'name'  => __( 'Terms & Conditions accepted at', 'ruben-dance' ),
				'value' => (string) ( $enrollment['tc_accepted_at'] ?? '' ),
			),
			array(
				'name'  => __( 'Enrolled at', 'ruben-dance' ),
				'value' => (string) $enrollment['created_at'],
			),
		);
	}

	/**
	 * Export "group" data for one email-log item — metadata only, matching
	 * the table's own "never store full bodies" rule (spec §6.1).
	 *
	 * @param array<string, mixed> $log_row Email log row.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function email_log_export_data( array $log_row ): array {
		return array(
			array(
				'name'  => __( 'Type', 'ruben-dance' ),
				'value' => (string) $log_row['type'],
			),
			array(
				'name'  => __( 'Subject', 'ruben-dance' ),
				'value' => (string) $log_row['subject'],
			),
			array(
				'name'  => __( 'Sent at', 'ruben-dance' ),
				'value' => (string) $log_row['sent_at'],
			),
			array(
				'name'  => __( 'Status', 'ruben-dance' ),
				'value' => (string) $log_row['status'],
			),
		);
	}
}
