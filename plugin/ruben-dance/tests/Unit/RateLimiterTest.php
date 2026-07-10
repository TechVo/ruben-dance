<?php
/**
 * Tests for the transient-backed attempt counter.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Rate_Limiter;

/**
 * Class RateLimiterTest.
 *
 * `Rate_Limiter` is deliberately WordPress-agnostic (transient read/write are
 * injected callables, mirroring `Location_Service`), so the counting/
 * threshold logic is exercised here with plain PHPUnit and an in-memory
 * fake, no WordPress bootstrap needed.
 */
class RateLimiterTest extends TestCase {

	/**
	 * Build a limiter backed by a simple in-memory array (ignores TTL —
	 * expiry behaviour is WordPress' own transient API, not this class's
	 * concern).
	 *
	 * @return Rate_Limiter
	 */
	private function make_limiter(): Rate_Limiter {
		$store = array();

		return new Rate_Limiter(
			static function ( string $key ) use ( &$store ): int {
				return $store[ $key ] ?? 0;
			},
			static function ( string $key, int $count, int $ttl_seconds ) use ( &$store ): void {
				unset( $ttl_seconds );
				$store[ $key ] = $count;
			}
		);
	}

	/**
	 * The first attempt for a fresh key is never blocked.
	 */
	public function test_first_attempt_is_allowed(): void {
		$limiter = $this->make_limiter();

		$this->assertFalse( $limiter->too_many_attempts( 'register', '1.2.3.4', 5, 900 ) );
	}

	/**
	 * Attempts up to the max are allowed (the Nth attempt, not the N+1th).
	 */
	public function test_attempts_up_to_max_are_allowed(): void {
		$limiter = $this->make_limiter();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( $limiter->too_many_attempts( 'register', '1.2.3.4', 3, 900 ) );
		}
	}

	/**
	 * The attempt after the max is reached gets blocked.
	 */
	public function test_attempt_beyond_max_is_blocked(): void {
		$limiter = $this->make_limiter();

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->too_many_attempts( 'register', '1.2.3.4', 3, 900 );
		}

		$this->assertTrue( $limiter->too_many_attempts( 'register', '1.2.3.4', 3, 900 ) );
	}

	/**
	 * Different actions and different identifiers get independent counters.
	 */
	public function test_counters_are_scoped_by_action_and_identifier(): void {
		$limiter = $this->make_limiter();

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->too_many_attempts( 'register', '1.2.3.4', 3, 900 );
		}

		// Same IP, different action: independent counter.
		$this->assertFalse( $limiter->too_many_attempts( 'login', '1.2.3.4', 3, 900 ) );

		// Same action, different IP: independent counter.
		$this->assertFalse( $limiter->too_many_attempts( 'register', '5.6.7.8', 3, 900 ) );
	}
}
