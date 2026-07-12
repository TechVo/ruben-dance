<?php
/**
 * `[rd_enroll]` template partial: missing/invalid `term_id`.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-enroll">
	<div class="rd-notice rd-notice--error">
		<p><?php esc_html_e( 'We could not find that course term. It may have been removed.', 'ruben-dance' ); ?></p>
	</div>
</div>
