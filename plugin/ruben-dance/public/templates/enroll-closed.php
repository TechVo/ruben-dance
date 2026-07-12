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
<div class="rd-app rd-enroll">
	<div class="rd-alert rd-alert--error">
		<strong class="rd-alert__icon">✕</strong>
		<span>
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
		</span>
	</div>
</div>
