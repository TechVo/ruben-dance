<?php
/**
 * Tests for the location validation and delete-vs-deactivate decision logic.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Location_Service;

/**
 * Class LocationServiceTest.
 *
 * `Location_Service` is deliberately WordPress-agnostic (every database
 * touchpoint is an injected callable, mirroring `Schema_Upgrader`), so both
 * rules the milestone calls out — field validation and "deactivate instead
 * of delete when referenced by a term" — are exercised here with plain
 * PHPUnit and in-memory fakes, no WordPress bootstrap needed.
 */
class LocationServiceTest extends TestCase {

	/**
	 * Build a service wired to simple in-memory fakes.
	 *
	 * @param int $terms_referencing Value returned by the injected term-count callable.
	 * @return array{0: Location_Service, 1: ArrayObject} [service, calls] where
	 *               `calls` holds the 'insert'/'update'/'delete' arrays the
	 *               fakes recorded.
	 */
	private function make_service( int $terms_referencing ): array {
		$calls = new ArrayObject(
			array(
				'insert' => array(),
				'update' => array(),
				'delete' => array(),
			)
		);

		$service = new Location_Service(
			static function ( int $location_id ) use ( $terms_referencing ): int {
				unset( $location_id );

				return $terms_referencing;
			},
			static function ( array $data ) use ( $calls ): int {
				$calls['insert'] = array_merge( $calls['insert'], array( $data ) );

				return 42;
			},
			static function ( int $id, array $data ) use ( $calls ): bool {
				$calls['update'] = array_merge( $calls['update'], array( array( $id, $data ) ) );

				return true;
			},
			static function ( int $id ) use ( $calls ): bool {
				$calls['delete'] = array_merge( $calls['delete'], array( $id ) );

				return true;
			}
		);

		return array( $service, $calls );
	}

	/**
	 * A completely valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		list( $service ) = $this->make_service( 0 );

		$errors = $service->validate(
			array(
				'name'    => 'Terasa Smíchov',
				'address' => 'Plzeňská 8, 150 00 Praha 5',
				'map_url' => 'https://maps.google.com/?q=Terasa+Smichov',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * An empty name and address are both required.
	 */
	public function test_validate_rejects_missing_name_and_address(): void {
		list( $service ) = $this->make_service( 0 );

		$errors = $service->validate(
			array(
				'name'    => '   ',
				'address' => '',
				'map_url' => '',
			)
		);

		$this->assertSame( Location_Service::ERROR_NAME_REQUIRED, $errors['name'] );
		$this->assertSame( Location_Service::ERROR_ADDRESS_REQUIRED, $errors['address'] );
	}

	/**
	 * An overlong name is rejected distinctly from a missing one.
	 */
	public function test_validate_rejects_overlong_name(): void {
		list( $service ) = $this->make_service( 0 );

		$errors = $service->validate(
			array(
				'name'    => str_repeat( 'a', 191 ),
				'address' => 'Valid address',
				'map_url' => '',
			)
		);

		$this->assertSame( Location_Service::ERROR_NAME_TOO_LONG, $errors['name'] );
	}

	/**
	 * The optional map URL, when present, must be a well-formed URL.
	 */
	public function test_validate_rejects_malformed_map_url(): void {
		list( $service ) = $this->make_service( 0 );

		$errors = $service->validate(
			array(
				'name'    => 'Valid name',
				'address' => 'Valid address',
				'map_url' => 'not a url',
			)
		);

		$this->assertSame( Location_Service::ERROR_MAP_URL_INVALID, $errors['map_url'] );
	}

	/**
	 * An empty map URL is fine — it's optional.
	 */
	public function test_validate_allows_empty_map_url(): void {
		list( $service ) = $this->make_service( 0 );

		$errors = $service->validate(
			array(
				'name'    => 'Valid name',
				'address' => 'Valid address',
				'map_url' => '',
			)
		);

		$this->assertArrayNotHasKey( 'map_url', $errors );
	}

	/**
	 * create() stores a NULL map_url (not an empty string) and always
	 * activates the new row.
	 */
	public function test_create_stores_null_map_url_when_blank_and_activates(): void {
		list( $service, $calls ) = $this->make_service( 0 );

		$id = $service->create(
			array(
				'name'    => 'New Studio',
				'address' => 'Some Street 1',
				'map_url' => '',
			)
		);

		$this->assertSame( 42, $id );
		$this->assertCount( 1, $calls['insert'] );
		$this->assertSame(
			array(
				'name'      => 'New Studio',
				'address'   => 'Some Street 1',
				'map_url'   => null,
				'is_active' => 1,
			),
			$calls['insert'][0]
		);
	}

	/**
	 * deactivate() only ever touches `is_active`.
	 */
	public function test_deactivate_sets_is_active_to_zero(): void {
		list( $service, $calls ) = $this->make_service( 0 );

		$this->assertTrue( $service->deactivate( 7 ) );
		$this->assertSame( array( 7, array( 'is_active' => 0 ) ), $calls['update'][0] );
	}

	/**
	 * activate() is the inverse of deactivate().
	 */
	public function test_activate_sets_is_active_to_one(): void {
		list( $service, $calls ) = $this->make_service( 0 );

		$this->assertTrue( $service->activate( 7 ) );
		$this->assertSame( array( 7, array( 'is_active' => 1 ) ), $calls['update'][0] );
	}

	/**
	 * The core referential-integrity rule: a location with zero course terms
	 * pointing at it is hard-deleted.
	 */
	public function test_delete_or_deactivate_deletes_when_unreferenced(): void {
		list( $service, $calls ) = $this->make_service( 0 );

		$result = $service->delete_or_deactivate( 9 );

		$this->assertSame( Location_Service::ACTION_DELETED, $result );
		$this->assertSame( array( 9 ), $calls['delete'] );
		$this->assertSame( array(), $calls['update'] );
	}

	/**
	 * The core referential-integrity rule: a location still referenced by at
	 * least one course term is deactivated instead of deleted, since the
	 * table has no FK constraint to fall back on (see Schema).
	 */
	public function test_delete_or_deactivate_deactivates_when_referenced_by_a_term(): void {
		list( $service, $calls ) = $this->make_service( 1 );

		$result = $service->delete_or_deactivate( 9 );

		$this->assertSame( Location_Service::ACTION_DEACTIVATED, $result );
		$this->assertSame( array(), $calls['delete'] );
		$this->assertSame( array( 9, array( 'is_active' => 0 ) ), $calls['update'][0] );
	}
}
