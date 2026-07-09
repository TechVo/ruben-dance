<?php
/**
 * PHPUnit bootstrap.
 *
 * Plain-PHPUnit bootstrap for pure PHP services (per the implementation
 * plan, M01 §5). It only satisfies the "no direct access" ABSPATH guard
 * that every plugin file carries; it does not load WordPress itself.
 *
 * A milestone that needs real WordPress functions in its tests (DB-backed
 * repositories, i18n, etc.) should switch this file to boot the
 * wp-phpunit/wp-phpunit test suite instead (already a dev dependency).
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
