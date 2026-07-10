<?php
/**
 * Tests for the catalog's "full" badge and early-bird display decisions.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Term_Presenter;

/**
 * Class TermPresenterTest.
 *
 * `Term_Presenter` is deliberately WordPress-agnostic (no `get_option()`/
 * `current_time()`/etc. touchpoints), the same way `Pricing_Service` is, so
 * it is exercised here with plain PHPUnit, no WordPress bootstrap needed.
 */
class TermPresenterTest extends TestCase {

	/**
	 * NULL capacity means unlimited: never full, regardless of headcount.
	 */
	public function test_is_full_false_for_unlimited_capacity(): void {
		$presenter = new Term_Presenter();

		$this->assertFalse( $presenter->is_full( array( 'capacity' => null ), 1000 ) );
	}

	/**
	 * Below capacity is not full.
	 */
	public function test_is_full_false_when_below_capacity(): void {
		$presenter = new Term_Presenter();

		$this->assertFalse( $presenter->is_full( array( 'capacity' => 20 ), 19 ) );
	}

	/**
	 * At capacity is full (soft limit, spec §3.2: "when reached, the term
	 * shows as full").
	 */
	public function test_is_full_true_when_at_capacity(): void {
		$presenter = new Term_Presenter();

		$this->assertTrue( $presenter->is_full( array( 'capacity' => 20 ), 20 ) );
	}

	/**
	 * Past capacity is still full (a term can already be over capacity from
	 * earlier over_capacity enrollments).
	 */
	public function test_is_full_true_when_over_capacity(): void {
		$presenter = new Term_Presenter();

		$this->assertTrue( $presenter->is_full( array( 'capacity' => 20 ), 25 ) );
	}

	/**
	 * No early-bird fields configured: null.
	 */
	public function test_early_bird_null_when_not_configured(): void {
		$presenter = new Term_Presenter();

		$term = array(
			'price'          => '2400.00',
			'discount_early' => null,
			'early_until'    => null,
		);

		$this->assertNull( $presenter->early_bird( $term, '2025-08-01' ) );
	}

	/**
	 * Deadline in the future: shows the discounted price and the deadline.
	 */
	public function test_early_bird_active_before_deadline(): void {
		$presenter = new Term_Presenter();

		$term = array(
			'price'          => '2400.00',
			'discount_early' => '300.00',
			'early_until'    => '2025-08-15',
		);

		$result = $presenter->early_bird( $term, '2025-08-01' );

		$this->assertSame( '2100.00', $result['price'] );
		$this->assertSame( '2025-08-15', $result['until'] );
	}

	/**
	 * Exactly on the deadline day still counts (spec §3.2: "on/before
	 * early_until").
	 */
	public function test_early_bird_active_on_deadline_day(): void {
		$presenter = new Term_Presenter();

		$term = array(
			'price'          => '2400.00',
			'discount_early' => '300.00',
			'early_until'    => '2025-08-15',
		);

		$this->assertNotNull( $presenter->early_bird( $term, '2025-08-15' ) );
	}

	/**
	 * Past the deadline: null, even though the discount fields are set.
	 */
	public function test_early_bird_null_after_deadline(): void {
		$presenter = new Term_Presenter();

		$term = array(
			'price'          => '2400.00',
			'discount_early' => '300.00',
			'early_until'    => '2025-08-15',
		);

		$this->assertNull( $presenter->early_bird( $term, '2025-08-16' ) );
	}

	/**
	 * A discount larger than the price clamps to zero rather than going
	 * negative.
	 */
	public function test_early_bird_clamps_to_zero(): void {
		$presenter = new Term_Presenter();

		$term = array(
			'price'          => '200.00',
			'discount_early' => '300.00',
			'early_until'    => '2025-08-15',
		);

		$result = $presenter->early_bird( $term, '2025-08-01' );

		$this->assertSame( '0.00', $result['price'] );
	}
}
