<?php
/**
 * `GET /rd/v1/lessons`: the public calendar feed (spec F2, §5).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Rest;

use RubenDance\Lang;
use RubenDance\Services\Calendar_Cache;
use RubenDance\Services\Calendar_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lessons_Controller.
 *
 * Public, read-only (`permission_callback: __return_true` — spec M10:
 * "public read-only"). Every parameter is validated with `Lessons_Query`'s
 * pure rules *before* `Calendar_Service` ever runs a query: a malformed
 * request (garbage dates, an inverted or huge range, a non-numeric ID) is
 * rejected by WordPress core itself as a clean `400` — see
 * `WP_REST_Request::has_valid_params()`, whose own `rest_invalid_param`/
 * `rest_missing_callback_param` errors both carry `status => 400` — before
 * `get_lessons()` ever runs. The response never touches `wp_rd_enrollment`
 * or any user table; see `Calendar_Service::lessons_for_feed()`'s return
 * shape for the exact (and only) fields exposed.
 */
class Lessons_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'rd/v1';

	/**
	 * Route path, under `self::REST_NAMESPACE`.
	 *
	 * @var string
	 */
	const ROUTE = '/lessons';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Register the route.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_lessons' ),
				'permission_callback' => '__return_true',
				'args'                => self::args(),
			)
		);
	}

	/**
	 * Argument schema: every field is validated by `Lessons_Query` before
	 * `get_lessons()` ever runs (see class docblock).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function args(): array {
		return array(
			'from'     => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return Lessons_Query::is_valid_date( (string) $value );
				},
			),
			'to'       => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value, \WP_REST_Request $request ): bool {
					return Lessons_Query::is_valid_range( (string) $request->get_param( 'from' ), (string) $value );
				},
			),
			'style'    => array(
				'required'          => false,
				'default'           => '',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return Lessons_Query::is_valid_optional_id( (string) $value );
				},
			),
			'location' => array(
				'required'          => false,
				'default'           => '',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return Lessons_Query::is_valid_optional_id( (string) $value );
				},
			),
			'lang'     => array(
				'required'          => false,
				'default'           => Lang::DEFAULT_LANGUAGE,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return Lessons_Query::is_valid_lang( (string) $value );
				},
			),
		);
	}

	/**
	 * Route callback: build the (cached) response. `$request`'s params are
	 * already fully validated by `args()` — nothing here re-checks them.
	 *
	 * @param \WP_REST_Request $request Validated request.
	 * @return \WP_REST_Response
	 */
	public static function get_lessons( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = array(
			'from'     => (string) $request->get_param( 'from' ),
			'to'       => (string) $request->get_param( 'to' ),
			'style'    => absint( $request->get_param( 'style' ) ),
			'location' => absint( $request->get_param( 'location' ) ),
			'lang'     => (string) $request->get_param( 'lang' ),
		);

		$cached = Calendar_Cache::get( $filters );

		if ( is_array( $cached ) ) {
			return new \WP_REST_Response( $cached );
		}

		$lessons = Calendar_Service::create_default()->lessons_for_feed( $filters );

		Calendar_Cache::set( $filters, $lessons );

		return new \WP_REST_Response( $lessons );
	}
}
