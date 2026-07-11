<?php
/**
 * Shared sentinel for anonymized personal-data fields (spec §6.1).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Anonymizer.
 *
 * A single constant, shared by every place that writes or reads an
 * anonymized name (`Repositories\Enrollment_Repository::anonymize_for_user()`
 * writes it; `Admin\Roster_List_Table`/`Admin\Enrollments_List_Table` and any
 * future screen that displays `participant_name` read it back verbatim — it
 * is not translated, the same way a customer's real name is never
 * translated) — so the two never drift out of sync.
 */
class Anonymizer {

	/**
	 * The literal value `participant_name`/`partner_name` become on erasure
	 * (spec §6.1: "name → anonymized"; the milestone's own acceptance
	 * criterion spells it in Czech: "roster shows anonymizováno").
	 *
	 * @var string
	 */
	const LABEL = 'anonymizováno';
}
