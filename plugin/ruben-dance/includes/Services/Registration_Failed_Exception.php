<?php
/**
 * Thrown when `wp_insert_user()` rejects a row that `Registration_Service::validate()`
 * had already accepted (e.g. a race-condition duplicate email).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Registration_Failed_Exception.
 *
 * Mirrors `Duplicate_Enrollment_Exception`: a developer/caller-facing
 * exception for a case `validate()` cannot fully rule out ahead of time.
 */
class Registration_Failed_Exception extends \RuntimeException {

}
