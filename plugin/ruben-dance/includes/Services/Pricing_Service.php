<?php
/**
 * Pure discount-computation logic for an enrollment's final price.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Pricing_Service.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Lesson_Generator` is: this is the highest-risk logic in the project (spec
 * §3.2, F3 — money the school actually collects), so it needs to be
 * exercisable with plain PHPUnit, no database or clock involved. Callers pass
 * in whatever term row and enrollment facts they already have; this class
 * never reaches out to fetch anything itself.
 */
class Pricing_Service {

	/**
	 * Compute an enrollment's final price and the human-readable breakdown
	 * that gets stored alongside it (spec §3.2: `discount_note`, "keeps the
	 * price auditable").
	 *
	 * `final = price − discount_early (if enrollment date ≤ early_until) −
	 * discount_pair (if a partner is stated)`, never negative. Both discounts
	 * combine (spec §3.2: "auto-applied and combinable"). The note lists the
	 * nominal, configured discount amounts (e.g. "early-bird −300"), even in
	 * the edge case where they exceed the list price and the final price
	 * saturates at 0 — the note is an audit trail of which discounts applied
	 * and for how much, not a running total.
	 *
	 * @param array{price: mixed, discount_early?: mixed, early_until?: mixed, discount_pair?: mixed} $term Term fields: `price` (required),
	 *              `discount_early`/`early_until` (both present or both absent — see `Term_Service`'s validation, which enforces this upstream),
	 *              `discount_pair` (optional). Null/absent values mean "no discount configured".
	 * @param string                                                                                  $enrollment_date `Y-m-d` date the enrollment is being created on.
	 * @param string|null                                                                             $partner_name Free-text partner name from the enrollment form; a non-blank value means "enrolling with a partner" (spec §3.2: `partner_name` "filled ⇒ pair discount applies").
	 * @return array{price: string, discount_note: string|null} `price` formatted as a fixed two-decimal string (DECIMAL(10,2)-ready); `discount_note` is null when no discount applied.
	 */
	public function compute( array $term, string $enrollment_date, ?string $partner_name ): array {
		$price          = (float) ( $term['price'] ?? 0 );
		$notes          = array();
		$total_discount = 0.0;

		$discount_early = $term['discount_early'] ?? null;
		$early_until    = $term['early_until'] ?? null;

		if ( $this->is_present( $discount_early ) && $this->is_present( $early_until ) && $enrollment_date <= (string) $early_until ) {
			$amount          = (float) $discount_early;
			$total_discount += $amount;
			$notes[]         = 'early-bird ' . $this->minus_sign() . $this->format_note_amount( $amount );
		}

		$has_partner   = null !== $partner_name && '' !== trim( $partner_name );
		$discount_pair = $term['discount_pair'] ?? null;

		if ( $has_partner && $this->is_present( $discount_pair ) ) {
			$amount          = (float) $discount_pair;
			$total_discount += $amount;
			$notes[]         = 'partner ' . $this->minus_sign() . $this->format_note_amount( $amount );
		}

		$final = max( 0.0, $price - $total_discount );

		return array(
			'price'         => number_format( $final, 2, '.', '' ),
			'discount_note' => array() === $notes ? null : implode( ', ', $notes ),
		);
	}

	/**
	 * Whether a term field value represents "a discount/date is configured",
	 * as opposed to null or an empty string (both of which mean "not set"
	 * coming from a nullable DECIMAL/DATE column via `$wpdb`).
	 *
	 * @param mixed $value Raw term field value.
	 * @return bool
	 */
	private function is_present( $value ): bool {
		return null !== $value && '' !== $value;
	}

	/**
	 * The minus sign used in `discount_note`, matching the spec's example
	 * verbatim ("early-bird −300, partner −200" — U+2212 MINUS SIGN, not a
	 * hyphen-minus or en dash).
	 *
	 * @return string
	 */
	private function minus_sign(): string {
		return "\xE2\x88\x92";
	}

	/**
	 * Format a discount amount for the note without a redundant ".00" (e.g.
	 * `300.00` → `"300"`, `199.50` → `"199.5"`), matching the spec's plain
	 * integer example.
	 *
	 * @param float $amount Discount amount.
	 * @return string
	 */
	private function format_note_amount( float $amount ): string {
		$formatted = number_format( $amount, 2, '.', '' );
		$formatted = rtrim( $formatted, '0' );
		$formatted = rtrim( $formatted, '.' );

		return '' === $formatted ? '0' : $formatted;
	}
}
