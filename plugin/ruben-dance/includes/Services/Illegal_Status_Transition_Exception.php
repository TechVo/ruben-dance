<?php
/**
 * Thrown when an enrollment status transition is not allowed.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Illegal_Status_Transition_Exception.
 *
 * Raised by `Enrollment_Service::mark_paid()`/`unmark_paid()`/`cancel()` for
 * any move outside the state diagram in spec §3.2 (`confirmed ⇄ paid`,
 * `confirmed|paid → cancelled`; `cancelled` is terminal). Callers (future
 * admin UI in M12) are expected to catch this and show a friendly message —
 * it should never reach a real admin under normal use, since the UI only
 * offers the actions valid for an enrollment's current status; this is the
 * server-side backstop against a stale page or a replayed request.
 */
class Illegal_Status_Transition_Exception extends \RuntimeException {

}
