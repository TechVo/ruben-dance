<?php
/**
 * E5 preview + confirm screen: a cancelled/moved lesson's "notify enrollees"
 * request lands here before anything is sent (spec F10/F14 E5:
 * "admin-confirmed send").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Emails\Email_Sender;
use RubenDance\Emails\Email_Templates;
use RubenDance\Emails\Enrollment_Email_Data;
use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Lesson_Repository;
use RubenDance\Roles;
use RubenDance\Services\Lesson_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lesson_Notify_Page.
 *
 * This is what actually wires the M05 stub: `Lesson_Service::NOTIFY_HOOK`
 * fires during `Term_Lessons_Page`'s `load-{hook}` save handling (before any
 * output), and the listener here redirects the admin to this hidden page
 * (null-parent registration, the `Term_Lessons_Page` pattern) instead of the
 * plain "lesson saved" notice. Nothing is sent by the hook itself — the
 * admin sees the recipient list and a rendered preview first, and only the
 * explicit, nonce-checked "Send" POST triggers `Emails\Email_Sender` (spec
 * E5: "admin confirms before sending"). Recipients are re-derived from the
 * database at send time, never trusted from the form: active (`confirmed`/
 * `paid`) enrollments of *this lesson's term only*, one email per account
 * holder, in each holder's stored locale — cancelled enrollments are never
 * included (M13 acceptance criteria).
 */
class Lesson_Notify_Page {

	const SLUG = 'ruben-dance-lesson-notify';

	/**
	 * Nonce action prefix; suffixed with the lesson ID so a confirm minted
	 * for one lesson can't be replayed against another.
	 *
	 * @var string
	 */
	const SEND_NONCE_PREFIX = 'rd_lesson_notify_';

	/**
	 * Hook registration: the hidden admin page plus the listener that turns
	 * `Lesson_Service::NOTIFY_HOOK` (stubbed since M05) into a redirect to
	 * this screen.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( Lesson_Service::NOTIFY_HOOK, array( self::class, 'handle_notify' ), 10, 2 );
	}

	/**
	 * Add the hidden (no-sidebar-entry) preview page.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			null, // A null parent is the documented way to register an admin page hook without adding a sidebar menu entry (see Term_Lessons_Page::add_menu()).
			__( 'Notify enrollees', 'ruben-dance' ),
			__( 'Notify enrollees', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * URL to the preview screen for one lesson.
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return string
	 */
	public static function url( int $lesson_id ): string {
		return add_query_arg(
			array(
				'page'      => self::SLUG,
				'lesson_id' => $lesson_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * `Lesson_Service::NOTIFY_HOOK` listener: divert the admin to the
	 * preview instead of the plain post-save redirect. Fires inside
	 * `Term_Lessons_Page`'s `load-{hook}` processing — the request is
	 * already capability- and nonce-checked there, and no output has been
	 * sent yet, so a redirect is still possible; the guard here is
	 * defense-in-depth for any future caller of the hook.
	 *
	 * @param int    $lesson_id Lesson ID.
	 * @param string $status    New lesson status ('cancelled'|'moved').
	 */
	public static function handle_notify( int $lesson_id, string $status ): void {
		unset( $status ); // The preview re-reads the fresh row itself rather than trusting a passed value.

		if ( ! is_admin() || ! current_user_can( Roles::CAPABILITY ) ) {
			return;
		}

		wp_safe_redirect( self::url( $lesson_id ) );
		exit;
	}

	/**
	 * Process the confirm-send POST, before any output is sent. Hooked to
	 * `load-{$hook_suffix}` (see `add_menu()`).
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to send notifications.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the send branch; check_admin_referer() immediately below performs the real verification before anything is read or sent.
		if ( ! isset( $_POST['rd_lesson_notify_action'] ) || 'send' !== $_POST['rd_lesson_notify_action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only reads the target ID to build the nonce action string; check_admin_referer() immediately below performs the real verification.
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;

		check_admin_referer( self::SEND_NONCE_PREFIX . $lesson_id );

		$context = self::load_context( $lesson_id );

		if ( null === $context ) {
			wp_safe_redirect( self::url( $lesson_id ) );
			exit;
		}

		$sender = Email_Sender::create_default();
		$sent   = 0;
		$failed = 0;

		foreach ( $context['recipients'] as $recipient ) {
			$ok = $sender->send(
				Email_Templates::TYPE_E5,
				$recipient['lang'],
				$recipient['email'],
				self::placeholders( $context, $recipient['user'], $recipient['lang'] ),
				$recipient['enrollment_id'],
				$recipient['user_id']
			);

			if ( $ok ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'rd_notice' => 'done',
					'sent'      => $sent,
					'failed'    => $failed,
				),
				self::url( $lesson_id )
			)
		);
		exit;
	}

	/**
	 * Main entry point, wired as the submenu page callback. Runs after
	 * `handle_request()`; output only, no state changes.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to send notifications.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which lesson to preview, no state change.
		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( $_GET['lesson_id'] ) : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Notify enrollees', 'ruben-dance' ) . '</h1>';

		$context = self::load_context( $lesson_id );

		if ( null === $context ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Lesson not found, or its term no longer exists.', 'ruben-dance' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$back_url = Term_Lessons_Page::url( (int) $context['term']['id'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice text to show after a redirect), no state change.
		if ( isset( $_GET['rd_notice'] ) && 'done' === $_GET['rd_notice'] ) {
			self::render_result( $back_url );
			echo '</div>';
			return;
		}

		self::render_change_summary( $context );

		if ( array() === $context['recipients'] ) {
			echo '<p>' . esc_html__( 'This term has no active enrollees — there is nobody to notify.', 'ruben-dance' ) . '</p>';
			echo '<p><a href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to lessons', 'ruben-dance' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::render_recipients( $context['recipients'] );
		self::render_previews( $context );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';
		wp_nonce_field( self::SEND_NONCE_PREFIX . $lesson_id );
		echo '<input type="hidden" name="rd_lesson_notify_action" value="send">';
		echo '<input type="hidden" name="lesson_id" value="' . esc_attr( (string) $lesson_id ) . '">';
		submit_button(
			sprintf(
				/* translators: %d: number of recipients. */
				_n( 'Send to %d enrollee', 'Send to %d enrollees', count( $context['recipients'] ), 'ruben-dance' ),
				count( $context['recipients'] )
			)
		);
		echo '</form>';

		echo '<p><a href="' . esc_url( $back_url ) . '">' . esc_html__( 'Cancel — back to lessons (nothing sent)', 'ruben-dance' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Everything a preview or a send needs, loaded fresh from the database:
	 * the lesson, its term, and the deduplicated active-enrollee recipient
	 * list. Null when the lesson/term no longer exists or the lesson's
	 * status isn't one worth notifying about.
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return array{lesson: array<string, mixed>, term: array<string, mixed>, recipients: array<int, array{user: \WP_User, user_id: int, email: string, lang: string, enrollment_id: int}>}|null
	 */
	private static function load_context( int $lesson_id ): ?array {
		$lesson = ( new Lesson_Repository() )->find( $lesson_id );

		if ( null === $lesson || ! in_array( (string) $lesson['status'], Lesson_Service::NOTIFIABLE_STATUSES, true ) ) {
			return null;
		}

		$term = ( new Course_Term_Repository() )->find( (int) $lesson['term_id'] );

		if ( null === $term ) {
			return null;
		}

		$recipients = array();
		$seen_users = array();

		foreach ( ( new Enrollment_Repository() )->for_term( (int) $term['id'] ) as $enrollment ) {
			// Only that term's *active* enrollees — a cancelled enrollment
			// never receives E5 (M13 acceptance criteria).
			if ( ! in_array( (string) $enrollment['status'], Enrollment_Repository::ACTIVE_STATUSES, true ) ) {
				continue;
			}

			$user_id = (int) $enrollment['user_id'];

			// One email per account holder, even when they enrolled several
			// participants (e.g. themselves + a child) in the same term.
			if ( isset( $seen_users[ $user_id ] ) ) {
				continue;
			}

			$user = get_userdata( $user_id );

			if ( false === $user ) {
				continue;
			}

			$seen_users[ $user_id ] = true;

			$recipients[] = array(
				'user'          => $user,
				'user_id'       => $user_id,
				'email'         => (string) $user->user_email,
				'lang'          => Enrollment_Email_Data::user_lang( $user_id ),
				'enrollment_id' => (int) $enrollment['id'],
			);
		}

		return array(
			'lesson'     => $lesson,
			'term'       => $term,
			'recipients' => $recipients,
		);
	}

	/**
	 * E5 placeholder values for one recipient.
	 *
	 * @param array{lesson: array<string, mixed>, term: array<string, mixed>} $context Loaded lesson/term context.
	 * @param \WP_User                                                        $user    Recipient.
	 * @param string                                                          $lang    Recipient language.
	 * @return array<string, string>
	 */
	private static function placeholders( array $context, \WP_User $user, string $lang ): array {
		return array(
			'first_name'  => (string) $user->first_name,
			'course'      => Enrollment_Email_Data::course_title( $context['term'], $lang ),
			'lesson_date' => Enrollment_Email_Data::format_date( (string) $context['lesson']['lesson_date'], $lang ),
			'status'      => self::status_label( (string) $context['lesson']['status'], $lang ),
			'note'        => trim( (string) ( $context['lesson']['note'] ?? '' ) ),
		);
	}

	/**
	 * The `{status}` value in the recipient's language. Literal per-language
	 * strings, not `__()` — the email must come out in the recipient's
	 * language regardless of the (Czech) admin locale triggering the send,
	 * the same reasoning `Emails\Enrollment_Email_Data` documents.
	 *
	 * @param string $status `Lesson_Service::STATUS_CANCELLED`|`STATUS_MOVED`.
	 * @param string $lang   Recipient language.
	 * @return string
	 */
	private static function status_label( string $status, string $lang ): string {
		if ( Lesson_Service::STATUS_MOVED === $status ) {
			return Lang::EN === $lang ? 'moved' : 'přesunuta';
		}

		return Lang::EN === $lang ? 'cancelled' : 'zrušena';
	}

	/**
	 * The "what changed" summary block above the recipient list.
	 *
	 * @param array{lesson: array<string, mixed>, term: array<string, mixed>} $context Loaded lesson/term context.
	 */
	private static function render_change_summary( array $context ): void {
		$lesson = $context['lesson'];
		$term   = $context['term'];

		$status_label = Lesson_Service::STATUS_MOVED === (string) $lesson['status']
			? __( 'Moved', 'ruben-dance' )
			: __( 'Cancelled', 'ruben-dance' );

		echo '<table class="widefat striped" style="max-width:700px;"><tbody>';
		echo '<tr><th scope="row" style="width:180px;">' . esc_html__( 'Term', 'ruben-dance' ) . '</th><td>' . esc_html( (string) $term['season_label_cs'] . ' — ' . get_the_title( (int) $term['course_id'] ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Lesson date', 'ruben-dance' ) . '</th><td>' . esc_html( mysql2date( 'j M Y', (string) $lesson['lesson_date'] ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Change', 'ruben-dance' ) . '</th><td>' . esc_html( $status_label ) . '</td></tr>';

		$note = trim( (string) ( $lesson['note'] ?? '' ) );

		if ( '' !== $note ) {
			echo '<tr><th scope="row">' . esc_html__( 'Note (included in the email)', 'ruben-dance' ) . '</th><td>' . esc_html( $note ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The recipient table: who will receive the email, and in which language.
	 *
	 * @param array<int, array{user: \WP_User, email: string, lang: string}> $recipients Recipient list.
	 */
	private static function render_recipients( array $recipients ): void {
		echo '<h2>' . esc_html(
			sprintf(
			/* translators: %d: number of recipients. */
				_n( '%d recipient', '%d recipients', count( $recipients ), 'ruben-dance' ),
				count( $recipients )
			)
		) . '</h2>';
		echo '<p>' . esc_html__( 'Active (unpaid or paid) enrollments of this term only; cancelled enrollments are excluded. One email per account holder.', 'ruben-dance' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:700px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'ruben-dance' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $recipients as $recipient ) {
			echo '<tr>';
			echo '<td>' . esc_html( $recipient['user']->display_name ) . '</td>';
			echo '<td>' . esc_html( $recipient['email'] ) . '</td>';
			echo '<td>' . esc_html( Lang::EN === $recipient['lang'] ? __( 'English', 'ruben-dance' ) : __( 'Czech', 'ruben-dance' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Rendered subject/body preview per language actually present in the
	 * recipient list, personalised with that language's first recipient
	 * (each real send re-renders per recipient).
	 *
	 * @param array{lesson: array<string, mixed>, term: array<string, mixed>, recipients: array<int, array{user: \WP_User, lang: string}>} $context Loaded context.
	 */
	private static function render_previews( array $context ): void {
		echo '<h2>' . esc_html__( 'Preview', 'ruben-dance' ) . '</h2>';

		$langs = array();

		foreach ( $context['recipients'] as $recipient ) {
			if ( ! isset( $langs[ $recipient['lang'] ] ) ) {
				$langs[ $recipient['lang'] ] = $recipient['user'];
			}
		}

		foreach ( $langs as $lang => $sample_user ) {
			$content = Email_Templates::compose(
				Email_Templates::TYPE_E5,
				(string) $lang,
				self::placeholders( $context, $sample_user, (string) $lang )
			);

			echo '<h3>' . esc_html( Lang::EN === $lang ? __( 'English recipients', 'ruben-dance' ) : __( 'Czech recipients', 'ruben-dance' ) ) . '</h3>';
			echo '<p><strong>' . esc_html__( 'Subject:', 'ruben-dance' ) . '</strong> ' . esc_html( $content['subject'] ) . '</p>';
			echo '<div style="border:1px solid #c3c4c7;background:#fff;padding:1em;max-width:700px;">' . wp_kses_post( $content['body'] ) . '</div>';
		}

		echo '<p class="description">' . esc_html__( 'Each email is personalised per recipient ({first_name}); the preview shows the first recipient of each language.', 'ruben-dance' ) . '</p>';
	}

	/**
	 * The post-send result view (reached via redirect, so a reload never
	 * re-sends).
	 *
	 * @param string $back_url URL back to the term's lessons list.
	 */
	private static function render_result( string $back_url ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic counts for the result notice, no state change.
		$sent = isset( $_GET['sent'] ) ? absint( $_GET['sent'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic counts for the result notice, no state change.
		$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;

		if ( $failed > 0 ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number sent, 2: number failed. */
						__( 'Sent %1$d notification(s); %2$d failed (logged with status "failed" — see the Email Log).', 'ruben-dance' ),
						$sent,
						$failed
					)
				)
			);
		} else {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number sent. */
						__( 'Sent %d notification(s).', 'ruben-dance' ),
						$sent
					)
				)
			);
		}

		echo '<p><a href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to lessons', 'ruben-dance' ) . '</a></p>';
	}
}
