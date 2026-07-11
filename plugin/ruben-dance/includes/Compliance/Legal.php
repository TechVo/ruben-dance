<?php
/**
 * Version marker for the Terms & Conditions text, stamped onto every
 * consent capture (spec §6.1: "T&C-version + timestamp").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Legal.
 *
 * A single, deliberately trivial constant class (zero WordPress
 * touchpoints, safe to import from the WordPress-agnostic
 * `Services\Registration_Service`/`Services\Enrollment_Service` the same way
 * both already import `Lang::DEFAULT_LANGUAGE`). The M15 placeholder T&C/
 * privacy pages ship structural lorem, not the lawyer's real text (spec "Out
 * of scope: legal text content") — whoever replaces that text pre-launch
 * must also bump `TC_VERSION`, so every consent timestamp recorded from that
 * point on is unambiguously tied to the text the customer actually saw.
 */
class Legal {

	/**
	 * Current Terms & Conditions version tag, stamped onto
	 * `Registration_Service::META_TC_VERSION` (once, at registration) and
	 * every enrollment's `tc_version` column (spec §6.1 consent audit).
	 *
	 * @var string
	 */
	const TC_VERSION = '1.0-placeholder';
}
