<?php
/**
 * Tests for discount computation and the discount_note breakdown.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Pricing_Service;

/**
 * Class PricingServiceTest.
 *
 * `Pricing_Service` is deliberately pure PHP (mirroring `Lesson_Generator`),
 * so the full discount matrix the milestone calls out — no discounts / early
 * only / pair only / both / boundary date / discounts exceeding price — is
 * exercised here with plain PHPUnit, no WordPress bootstrap needed.
 */
class PricingServiceTest extends TestCase {

	/**
	 * A term with both discounts configured, as a baseline every test tweaks from.
	 *
	 * @return array<string, mixed>
	 */
	private function term(): array {
		return array(
			'price'          => '2400.00',
			'discount_early' => '300.00',
			'early_until'    => '2025-08-15',
			'discount_pair'  => '200.00',
		);
	}

	/**
	 * No discounts configured on the term at all: final price is the list price, no note.
	 */
	public function test_no_discounts_configured(): void {
		$service = new Pricing_Service();

		$term = array(
			'price'          => '2400.00',
			'discount_early' => null,
			'early_until'    => null,
			'discount_pair'  => null,
		);

		$result = $service->compute( $term, '2025-09-01', null );

		$this->assertSame( '2400.00', $result['price'] );
		$this->assertNull( $result['discount_note'] );
	}

	/**
	 * Enrolling before early_until with no partner: only the early-bird discount applies.
	 */
	public function test_early_bird_only(): void {
		$service = new Pricing_Service();

		$result = $service->compute( $this->term(), '2025-08-01', null );

		$this->assertSame( '2100.00', $result['price'] );
		$this->assertSame( "early-bird \xE2\x88\x92300", $result['discount_note'] );
	}

	/**
	 * Enrolling after early_until with a partner stated: only the pair discount applies.
	 */
	public function test_pair_only(): void {
		$service = new Pricing_Service();

		$result = $service->compute( $this->term(), '2025-09-01', 'Jana Nováková' );

		$this->assertSame( '2200.00', $result['price'] );
		$this->assertSame( "partner \xE2\x88\x92200", $result['discount_note'] );
	}

	/**
	 * Enrolling before early_until with a partner stated: both discounts combine.
	 */
	public function test_both_discounts_combine(): void {
		$service = new Pricing_Service();

		$result = $service->compute( $this->term(), '2025-08-01', 'Jana Nováková' );

		$this->assertSame( '1900.00', $result['price'] );
		$this->assertSame( "early-bird \xE2\x88\x92300, partner \xE2\x88\x92200", $result['discount_note'] );
	}

	/**
	 * The boundary date (enrollment date exactly equal to early_until) still
	 * counts as early (spec §3.2: "on/before early_until").
	 */
	public function test_boundary_date_equal_to_early_until_counts_as_early(): void {
		$service = new Pricing_Service();

		$result = $service->compute( $this->term(), '2025-08-15', null );

		$this->assertSame( '2100.00', $result['price'] );
		$this->assertSame( "early-bird \xE2\x88\x92300", $result['discount_note'] );
	}

	/**
	 * The day right after early_until no longer qualifies.
	 */
	public function test_day_after_early_until_does_not_qualify(): void {
		$service = new Pricing_Service();

		$result = $service->compute( $this->term(), '2025-08-16', null );

		$this->assertSame( '2400.00', $result['price'] );
		$this->assertNull( $result['discount_note'] );
	}

	/**
	 * Discounts exceeding the list price never push the final price below 0,
	 * but the note still lists the nominal configured amounts.
	 */
	public function test_discounts_exceeding_price_floor_at_zero(): void {
		$service = new Pricing_Service();

		$term = array(
			'price'          => '400.00',
			'discount_early' => '300.00',
			'early_until'    => '2025-08-15',
			'discount_pair'  => '200.00',
		);

		$result = $service->compute( $term, '2025-08-01', 'Partner Name' );

		$this->assertSame( '0.00', $result['price'] );
		$this->assertSame( "early-bird \xE2\x88\x92300, partner \xE2\x88\x92200", $result['discount_note'] );
	}

	/**
	 * A blank partner_name (empty string, as a real form submits "no partner"
	 * with) is treated the same as null — no pair discount.
	 */
	public function test_blank_partner_name_does_not_apply_pair_discount(): void {
		$service = new Pricing_Service();

		$result = $service->compute( $this->term(), '2025-09-01', '   ' );

		$this->assertSame( '2400.00', $result['price'] );
		$this->assertNull( $result['discount_note'] );
	}

	/**
	 * discount_early configured without early_until (should never happen —
	 * Term_Service::validate() enforces both-or-neither upstream — but this
	 * class must not blow up or wrongly apply the discount if it does).
	 */
	public function test_discount_early_without_early_until_is_ignored(): void {
		$service = new Pricing_Service();

		$term = array(
			'price'          => '2400.00',
			'discount_early' => '300.00',
			'early_until'    => null,
			'discount_pair'  => null,
		);

		$result = $service->compute( $term, '2025-08-01', null );

		$this->assertSame( '2400.00', $result['price'] );
		$this->assertNull( $result['discount_note'] );
	}
}
