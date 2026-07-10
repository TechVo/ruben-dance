<?php
/**
 * Transient cache for the `[rd_calendar]` REST feed, invalidated on any
 * lesson/term save (spec M10: "cached per query in a transient, invalidated
 * on lesson/term save").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Calendar_Cache.
 *
 * A version-stamped transient cache rather than tracking individual keys to
 * delete: every cached response's transient key embeds the current
 * `self::version()`, so bumping the version on any write instantly
 * "forgets" every previously cached query without needing to enumerate or
 * pattern-delete transients. Hooked to `Term_Service::HOOK_SAVED` and
 * `Lesson_Service::HOOK_SAVED` in `register()` — every path that writes
 * `wp_rd_course_term`/`wp_rd_lesson` (admin screens, `wp rd seed`, future
 * milestones) already goes through one of those two services, so this class
 * never needs updating when a new write path is added elsewhere.
 */
class Calendar_Cache {

	/**
	 * Option name storing the current cache version (an integer, bumped on
	 * every invalidation).
	 *
	 * @var string
	 */
	const OPTION_VERSION = 'rd_calendar_cache_version';

	/**
	 * Safety-net expiry: even if a write path somehow bypassed both save
	 * hooks, a cached response is never stale for longer than this.
	 *
	 * @var int
	 */
	const TTL_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( Term_Service::HOOK_SAVED, array( self::class, 'bump_version' ) );
		add_action( Lesson_Service::HOOK_SAVED, array( self::class, 'bump_version' ) );
	}

	/**
	 * Fetch a cached value for the given (already-validated) query
	 * parameters, or null on a cache miss.
	 *
	 * @param array<string, int|string> $query Normalized query parameters.
	 * @return mixed|null
	 */
	public static function get( array $query ) {
		$value = get_transient( self::key( $query ) );

		return false === $value ? null : $value;
	}

	/**
	 * Store a value for the given query parameters.
	 *
	 * @param array<string, int|string> $query Normalized query parameters.
	 * @param mixed                     $value Value to cache (must survive `maybe_serialize()`, e.g. a plain array).
	 */
	public static function set( array $query, $value ): void {
		set_transient( self::key( $query ), $value, self::TTL_SECONDS );
	}

	/**
	 * Invalidate every cached query at once by advancing the cache version.
	 * Wired to the term/lesson save hooks in `register()`; harmless to call
	 * with the extra hook arguments those actions pass (unused here, so this
	 * intentionally declares no parameters).
	 */
	public static function bump_version(): void {
		update_option( self::OPTION_VERSION, self::version() + 1 );
	}

	/**
	 * Current cache version, defaulting to 1 the first time this ever runs.
	 *
	 * @return int
	 */
	private static function version(): int {
		return (int) get_option( self::OPTION_VERSION, 1 );
	}

	/**
	 * Build the version-stamped transient key for a query.
	 *
	 * @param array<string, int|string> $query Normalized query parameters.
	 * @return string
	 */
	private static function key( array $query ): string {
		ksort( $query );

		return 'rd_cal_' . self::version() . '_' . md5( (string) wp_json_encode( $query ) );
	}
}
