<?php
/**
 * Thrown when an enrollment would duplicate an existing one.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Duplicate_Enrollment_Exception.
 *
 * The friendly, domain-level translation of a
 * `Repositories\Duplicate_Key_Exception` on `wp_rd_enrollment`'s
 * `(term_id, user_id, participant_name)` unique key (spec §3.3): raised by
 * `Enrollment_Service::create()` when the same account tries to enroll the
 * same participant in the same term twice. Callers (future public enrollment
 * form in M08) are expected to catch this and show a message like "you are
 * already enrolled in this term" rather than a raw database error.
 */
class Duplicate_Enrollment_Exception extends \RuntimeException {

}
