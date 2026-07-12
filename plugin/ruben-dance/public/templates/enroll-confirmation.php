<?php
/**
 * `[rd_enroll]` template partial: post-submit confirmation (spec F3 step 4).
 *
 * Variables available: array<string,mixed>|null $enrollment, string $course_title,
 * string $season, string $bank_account.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-enroll rd-enroll-confirmation">
	<div class="rd-notice rd-notice--success">
		<p><?php esc_html_e( 'Thank you — your enrollment has been received.', 'ruben-dance' ); ?></p>
	</div>

	<?php if ( null !== $enrollment ) : ?>
		<h2><?php echo esc_html( $course_title ); ?> — <?php echo esc_html( $season ); ?></h2>

		<?php if ( ! empty( $enrollment['over_capacity'] ) ) : ?>
			<div class="rd-notice rd-notice--warning">
				<p><?php esc_html_e( 'This term is currently at capacity — we will contact you to confirm your spot.', 'ruben-dance' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="rd-enroll-summary">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Amount', 'ruben-dance' ); ?></th>
					<td>
						<?php
						echo esc_html( (string) $enrollment['price'] ) . ' Kč';
						if ( ! empty( $enrollment['discount_note'] ) ) {
							echo ' (' . esc_html( (string) $enrollment['discount_note'] ) . ')';
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bank account', 'ruben-dance' ); ?></th>
					<td><?php echo esc_html( '' === $bank_account ? __( '(to be confirmed)', 'ruben-dance' ) : $bank_account ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Variable symbol', 'ruben-dance' ); ?></th>
					<td><?php echo esc_html( (string) $enrollment['variable_symbol'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Due date', 'ruben-dance' ); ?></th>
					<td><?php echo esc_html( (string) $enrollment['due_date'] ); ?></td>
				</tr>
			</tbody>
		</table>

		<p><?php esc_html_e( 'We have also emailed you these payment instructions.', 'ruben-dance' ); ?></p>
	<?php endif; ?>
</div>
