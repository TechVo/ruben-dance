<?php
/**
 * Simple per-key attempt counter backed by WordPress transients (spec §5:
 * "simple per-IP rate limiting via transients").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Rate_Limiter.
 *
 * Kept WordPress-agnostic the same way `Location_Service` is: the transient
 * read/write touchpoints are injected callables (see `create_default()` for
 * the real wiring), so the counting/threshold logic is unit-testable with
 * plain PHPUnit and an in-memory fake. Callers key the counter themselves
 * (action name + hashed IP, see `Front\Form_Handler`) so one instance serves
 * every public form.
 */
class Rate_Limiter {

	/**
	 * Reads the current attempt count for a key (0 if unset):
	 * function( string $key ): int.
	 *
	 * @var callable
	 */
	private $get_count;

	/**
	 * Stores a new attempt count for a key with a TTL in seconds:
	 * function( string $key, int $count, int $ttl_seconds ): void.
	 *
	 * @var callable
	 */
	private $set_count;

	/**
	 * Constructor.
	 *
	 * @param callable $get_count function( string $key ): int.
	 * @param callable $set_count function( string $key, int $count, int $ttl_seconds ): void.
	 */
	public function __construct( callable $get_count, callable $set_count ) {
		$this->get_count = $get_count;
		$this->set_count = $set_count;
	}

	/**
	 * Wire the limiter to real WordPress transients.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self(
			static function ( string $key ): int {
				$count = get_transient( $key );

				return false === $count ? 0 : (int) $count;
			},
			static function ( string $key, int $count, int $ttl_seconds ): void {
				set_transient( $key, $count, $ttl_seconds );
			}
		);
	}

	/**
	 * Record one attempt for `$action` by `$identifier` (e.g. an IP address)
	 * and report whether the caller has now exceeded `$max_attempts` within
	 * the trailing `$window_seconds`.
	 *
	 * Once the threshold is reached, further attempts are *not* re-counted
	 * (the transient, and therefore the lockout window, is left to expire on
	 * its own schedule from the attempt that tipped it over) — a persistent
	 * attacker cannot keep the lockout alive forever by continuing to try.
	 *
	 * @param string $action         Short action name, e.g. `register`.
	 * @param string $identifier     Caller identifier, typically the request IP.
	 * @param int    $max_attempts   Attempts allowed within the window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool True if this attempt should be blocked.
	 */
	public function too_many_attempts( string $action, string $identifier, int $max_attempts, int $window_seconds ): bool {
		$key   = 'rd_rl_' . $action . '_' . md5( $identifier );
		$count = ( $this->get_count )( $key );

		if ( $count >= $max_attempts ) {
			return true;
		}

		( $this->set_count )( $key, $count + 1, $window_seconds );

		return false;
	}
}
