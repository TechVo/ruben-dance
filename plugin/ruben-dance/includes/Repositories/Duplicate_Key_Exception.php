<?php
/**
 * Thrown when an insert violates a table's unique key.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Duplicate_Key_Exception.
 *
 * Raw signal from the repository layer that `$wpdb->insert()` failed because
 * of a `UNIQUE KEY` violation (MySQL error 1062), as opposed to any other
 * insert failure. Deliberately generic — it names the failure mode, not its
 * business meaning — so a service can catch it and translate it into a
 * domain-specific, user-facing error (see
 * `Services\Enrollment_Service::create()` and the
 * `term_id`/`user_id`/`participant_name` unique key on `wp_rd_enrollment`).
 */
class Duplicate_Key_Exception extends \RuntimeException {

}
