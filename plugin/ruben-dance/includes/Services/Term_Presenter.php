<?php
/**
 * Pure display-decision logic for a course term: full/badge and early-bird
 * price, shared by the catalog, course-detail listing and the enrollment
 * form's advisory price display.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Term_Presenter.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Pricing_Service` is: these are read-only display decisions (spec F1: the
 * "full" badge, early-bird price + deadline), not the actual server-side
 * price computation `Pricing_Service::compute()` still owns and
 * `Enrollment_Service::create()` still recomputes from scratch — this class
 * never writes anything or is trusted for the final charge.
 */
class Term_Presenter {

	/**
	 * Whether a term should show the "full" badge (spec F1/F3.1: a full term
	 * is still enrollable, it just gets a badge and, at enrollment time, the
	 * `over_capacity` flag — that flag is `Enrollment_Service`'s job, not
	 * this one).
	 *
	 * @param array{capacity?: mixed} $term         Term fields.
	 * @param int                     $active_count Count of active (non-cancelled) enrollments for the term.
	 * @return bool
	 */
	public function is_full( array $term, int $active_count ): bool {
		$capacity = $term['capacity'] ?? null;

		if ( null === $capacity || '' === (string) $capacity ) {
			return false; // NULL capacity = unlimited (spec §3.2).
		}

		return $active_count >= (int) $capacity;
	}

	/**
	 * The early-bird price and deadline to display, or null when no
	 * early-bird discount is configured or its deadline has already passed
	 * (spec F1: "early-bird price and deadline when configured").
	 *
	 * @param array{price?: mixed, discount_early?: mixed, early_until?: mixed} $term  Term fields.
	 * @param string                                                            $today `Y-m-d` date to compare the deadline against.
	 * @return array{price: string, until: string}|null
	 */
	public function early_bird( array $term, string $today ): ?array {
		$discount = $term['discount_early'] ?? null;
		$until    = $term['early_until'] ?? null;

		if ( null === $discount || '' === $discount || null === $until || '' === $until ) {
			return null;
		}

		if ( $today > (string) $until ) {
			return null; // Deadline already passed.
		}

		$price = max( 0.0, (float) ( $term['price'] ?? 0 ) - (float) $discount );

		return array(
			'price' => number_format( $price, 2, '.', '' ),
			'until' => (string) $until,
		);
	}
}
