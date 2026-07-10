<?php
/**
 * `[rd_enroll]` template partial: term is draft/closed/cancelled.
 *
 * Variables available: string $course_title.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-enroll">
	<div class="rd-notice rd-notice--error">
		<p>
			<?php if ( '' !== $course_title ) : ?>
				<?php
				printf(
					/* translators: %s: course name. */
					esc_html__( 'Enrollment for "%s" is not open right now.', 'ruben-dance' ),
					esc_html( $course_title )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Enrollment for this term is not open right now.', 'ruben-dance' ); ?>
			<?php endif; ?>
		</p>
	</div>
</div>
