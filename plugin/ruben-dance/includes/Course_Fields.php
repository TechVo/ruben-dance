<?php
/**
 * Per-course "dance role is relevant" flag on `rd_course` (spec F3 step 3:
 * "dance role (solo/leader/follower — only if relevant for the course)").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Course_Fields.
 *
 * A single post meta checkbox on the `rd_course` edit screen: some courses
 * (couples' dances) care whether a participant leads or follows; others
 * (kids dance, ladies styling) don't. Stored on the post itself rather than
 * `wp_rd_course_term` because it's a property of the course content, not of
 * any one term/season (spec §3.1: level/style/etc. live on the CPT).
 *
 * Deliberately keyed off the **Czech (canonical)** course post only — spec §5
 * Multilingual: "the term's `course_id` always points to the Czech course
 * post" — so callers must always pass that ID, never a translation's, the
 * same rule `Term_Service`/`Terms_Page::course_options()` already follow.
 */
class Course_Fields {

	/**
	 * Post meta key storing the flag ('1'/'0').
	 *
	 * @var string
	 */
	const META_ROLES_RELEVANT = 'rd_roles_relevant';

	/**
	 * Nonce action for the meta box save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'rd_course_fields_save';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'rd_course_fields_nonce';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_' . Post_Types::COURSE, array( self::class, 'save' ) );
	}

	/**
	 * Register the meta box on the `rd_course` edit screen.
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			'rd_course_fields',
			__( 'Ruben Dance enrollment settings', 'ruben-dance' ),
			array( self::class, 'render' ),
			Post_Types::COURSE,
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label></p>',
			esc_attr( self::META_ROLES_RELEVANT ),
			checked( self::is_roles_relevant( (int) $post->ID ), true, false ),
			esc_html__( 'Dance role (solo / leader / follower) is relevant for this course', 'ruben-dance' )
		);

		echo '<p class="description">' . esc_html__( 'Enable for partner dances where enrollees pick a role. Leave off for courses (e.g. kids dance, solo styling) where it makes no sense.', 'ruben-dance' ) . '</p>';
	}

	/**
	 * Save the meta box value.
	 *
	 * @param int $post_id Course post ID.
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
		$value = ! empty( $_POST[ self::META_ROLES_RELEVANT ] ) ? '1' : '0';

		update_post_meta( $post_id, self::META_ROLES_RELEVANT, $value );
	}

	/**
	 * Whether dance role is relevant for a course. Always pass the Czech
	 * (canonical) course post ID — see class docblock.
	 *
	 * @param int $course_id Czech `rd_course` post ID.
	 * @return bool
	 */
	public static function is_roles_relevant( int $course_id ): bool {
		return '1' === get_post_meta( $course_id, self::META_ROLES_RELEVANT, true );
	}
}
