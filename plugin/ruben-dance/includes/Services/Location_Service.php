<?php
/**
 * Business rules for `wp_rd_location`: field validation and the
 * delete-vs-deactivate decision.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Location_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Location_Service.
 *
 * Kept WordPress-agnostic the same way `Schema_Upgrader` is: every
 * touchpoint with the database is injected as a callable (see
 * `create_default()` for the real wiring), so `validate()` and
 * `delete_or_deactivate()` — the two rules the milestone calls out — are
 * unit-testable with plain PHPUnit and fakes, no WordPress bootstrap needed.
 */
class Location_Service {

	const ERROR_NAME_REQUIRED    = 'name_required';
	const ERROR_NAME_TOO_LONG    = 'name_too_long';
	const ERROR_ADDRESS_REQUIRED = 'address_required';
	const ERROR_ADDRESS_TOO_LONG = 'address_too_long';
	const ERROR_MAP_URL_INVALID  = 'map_url_invalid';

	const ACTION_DELETED     = 'deleted';
	const ACTION_DEACTIVATED = 'deactivated';

	/**
	 * Counts course terms referencing a location: function( int $location_id ): int.
	 *
	 * @var callable
	 */
	private $count_terms_for_location;

	/**
	 * Inserts a new row, returns its ID: function( array<string, mixed> $data ): int.
	 *
	 * @var callable
	 */
	private $insert_row;

	/**
	 * Updates a row by ID: function( int $id, array<string, mixed> $data ): bool.
	 *
	 * @var callable
	 */
	private $update_row;

	/**
	 * Deletes a row by ID: function( int $id ): bool.
	 *
	 * @var callable
	 */
	private $delete_row;

	/**
	 * Constructor.
	 *
	 * @param callable $count_terms_for_location function( int $location_id ): int.
	 * @param callable $insert_row               function( array $data ): int.
	 * @param callable $update_row                function( int $id, array $data ): bool.
	 * @param callable $delete_row                function( int $id ): bool.
	 */
	public function __construct(
		callable $count_terms_for_location,
		callable $insert_row,
		callable $update_row,
		callable $delete_row
	) {
		$this->count_terms_for_location = $count_terms_for_location;
		$this->insert_row               = $insert_row;
		$this->update_row               = $update_row;
		$this->delete_row               = $delete_row;
	}

	/**
	 * Wire the service to the real repositories.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$locations    = new Location_Repository();
		$course_terms = new Course_Term_Repository();

		return new self(
			static function ( int $location_id ) use ( $course_terms ): int {
				return $course_terms->count_for_location( $location_id );
			},
			static function ( array $data ) use ( $locations ): int {
				return $locations->insert( $data );
			},
			static function ( int $id, array $data ) use ( $locations ): bool {
				return $locations->update( $id, $data );
			},
			static function ( int $id ) use ( $locations ): bool {
				return $locations->delete( $id );
			}
		);
	}

	/**
	 * Validate submitted field values.
	 *
	 * @param array<string, string> $data Raw (unslashed) field values: name, address, map_url.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate( array $data ): array {
		$errors = array();

		$name    = trim( (string) ( $data['name'] ?? '' ) );
		$address = trim( (string) ( $data['address'] ?? '' ) );
		$map_url = trim( (string) ( $data['map_url'] ?? '' ) );

		if ( '' === $name ) {
			$errors['name'] = self::ERROR_NAME_REQUIRED;
		} elseif ( strlen( $name ) > 190 ) {
			$errors['name'] = self::ERROR_NAME_TOO_LONG;
		}

		if ( '' === $address ) {
			$errors['address'] = self::ERROR_ADDRESS_REQUIRED;
		} elseif ( strlen( $address ) > 255 ) {
			$errors['address'] = self::ERROR_ADDRESS_TOO_LONG;
		}

		if ( '' !== $map_url && ( strlen( $map_url ) > 255 || false === filter_var( $map_url, FILTER_VALIDATE_URL ) ) ) {
			$errors['map_url'] = self::ERROR_MAP_URL_INVALID;
		}

		return $errors;
	}

	/**
	 * Create a new location. Caller must call `validate()` first and only
	 * proceed when it returns an empty array.
	 *
	 * @param array<string, string> $data Field values: name, address, map_url.
	 * @return int New location ID (0 on failure).
	 */
	public function create( array $data ): int {
		$row              = $this->row( $data );
		$row['is_active'] = 1;

		return ( $this->insert_row )( $row );
	}

	/**
	 * Update an existing location's editable fields. Caller must call
	 * `validate()` first and only proceed when it returns an empty array.
	 *
	 * @param int                   $id   Location ID.
	 * @param array<string, string> $data Field values: name, address, map_url.
	 * @return bool
	 */
	public function update_details( int $id, array $data ): bool {
		return ( $this->update_row )( $id, $this->row( $data ) );
	}

	/**
	 * Hide a location from future term dropdowns without losing history.
	 *
	 * @param int $id Location ID.
	 * @return bool
	 */
	public function deactivate( int $id ): bool {
		return ( $this->update_row )( $id, array( 'is_active' => 0 ) );
	}

	/**
	 * Re-show a previously deactivated location.
	 *
	 * @param int $id Location ID.
	 * @return bool
	 */
	public function activate( int $id ): bool {
		return ( $this->update_row )( $id, array( 'is_active' => 1 ) );
	}

	/**
	 * Delete a location, unless a course term still references it — in that
	 * case deactivate instead, since removing the row would orphan
	 * `wp_rd_course_term.location_id` (no FK constraint enforces this at the
	 * database layer, see Schema).
	 *
	 * @param int $id Location ID.
	 * @return string self::ACTION_DELETED or self::ACTION_DEACTIVATED.
	 */
	public function delete_or_deactivate( int $id ): string {
		if ( ( $this->count_terms_for_location )( $id ) > 0 ) {
			$this->deactivate( $id );

			return self::ACTION_DEACTIVATED;
		}

		( $this->delete_row )( $id );

		return self::ACTION_DELETED;
	}

	/**
	 * Map validated field values to storage-ready column values.
	 *
	 * @param array<string, string> $data Field values: name, address, map_url.
	 * @return array<string, mixed>
	 */
	private function row( array $data ): array {
		$map_url = trim( (string) ( $data['map_url'] ?? '' ) );

		return array(
			'name'    => trim( (string) ( $data['name'] ?? '' ) ),
			'address' => trim( (string) ( $data['address'] ?? '' ) ),
			'map_url' => '' === $map_url ? null : $map_url,
		);
	}
}
