<?php
/**
 * Database schema: table definitions and versioned migrations.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Schema.
 *
 * Creates and upgrades all `wp_rd_*` tables via `dbDelta()`. Bump
 * `SCHEMA_VERSION` whenever a table definition below changes; `maybe_upgrade()`
 * (hooked to `plugins_loaded`) re-runs `dbDelta()` for every table when the
 * stored option differs from the constant, so upgrades are idempotent.
 */
class Schema {

	/**
	 * Current schema version. Bump this when any CREATE TABLE below changes.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option name storing the last schema version that was installed.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'rd_schema_version';

	/**
	 * Run on plugin activation: always (re)applies the current schema.
	 */
	public static function install(): void {
		self::upgrader()->apply();
	}

	/**
	 * Run on every load (e.g. `plugins_loaded`): applies the schema only if
	 * the stored version differs from `SCHEMA_VERSION`.
	 */
	public static function maybe_upgrade(): void {
		self::upgrader()->upgrade_if_needed();
	}

	/**
	 * Build the upgrader, wiring the real WordPress functions into the
	 * WordPress-agnostic decision logic in `Schema_Upgrader`.
	 *
	 * @return Schema_Upgrader
	 */
	private static function upgrader(): Schema_Upgrader {
		return new Schema_Upgrader(
			self::SCHEMA_VERSION,
			static function (): ?string {
				$stored = get_option( self::OPTION_NAME );

				return false === $stored ? null : (string) $stored;
			},
			static function ( string $version ): void {
				update_option( self::OPTION_NAME, $version );
			},
			static function ( array $statements ): void {
				if ( ! function_exists( 'dbDelta' ) ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}

				foreach ( $statements as $sql ) {
					dbDelta( $sql );
				}
			},
			array( self::class, 'table_sql' )
		);
	}

	/**
	 * Charset/collation clause used for every table, forced to utf8mb4 per spec.
	 *
	 * @return string
	 */
	private static function charset_collate(): string {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( false === stripos( $charset_collate, 'utf8mb4' ) ) {
			$charset_collate = 'DEFAULT CHARACTER SET utf8mb4';
		}

		return $charset_collate;
	}

	/**
	 * Build the `CREATE TABLE` statements for every plugin table.
	 *
	 * Public because it is passed as a callable to `Schema_Upgrader`, which
	 * invokes it from outside this class's scope.
	 *
	 * @return string[]
	 */
	public static function table_sql(): array {
		global $wpdb;

		$charset_collate = self::charset_collate();
		$location        = $wpdb->prefix . 'rd_location';
		$course_term     = $wpdb->prefix . 'rd_course_term';
		$lesson          = $wpdb->prefix . 'rd_lesson';
		$enrollment      = $wpdb->prefix . 'rd_enrollment';
		$email_log       = $wpdb->prefix . 'rd_email_log';

		return array(
			"CREATE TABLE {$location} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				address VARCHAR(255) NOT NULL,
				map_url VARCHAR(255) NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id)
			) {$charset_collate};",

			"CREATE TABLE {$course_term} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				course_id BIGINT UNSIGNED NOT NULL,
				location_id BIGINT UNSIGNED NOT NULL,
				type ENUM('course','workshop') NOT NULL DEFAULT 'course',
				season_label_cs VARCHAR(100) NOT NULL,
				season_label_en VARCHAR(100) NOT NULL,
				weekday TINYINT NOT NULL,
				start_time TIME NOT NULL,
				end_time TIME NOT NULL,
				date_from DATE NOT NULL,
				date_to DATE NOT NULL,
				instructor VARCHAR(190) NOT NULL,
				capacity SMALLINT NULL,
				price DECIMAL(10,2) NOT NULL,
				discount_early DECIMAL(10,2) NULL,
				early_until DATE NULL,
				discount_pair DECIMAL(10,2) NULL,
				status ENUM('draft','open','closed','cancelled') NOT NULL DEFAULT 'draft',
				note_public_cs TEXT NULL,
				note_public_en TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY course_id (course_id),
				KEY location_id (location_id),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$lesson} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				term_id BIGINT UNSIGNED NOT NULL,
				lesson_date DATE NOT NULL,
				start_time TIME NOT NULL,
				end_time TIME NOT NULL,
				status ENUM('scheduled','cancelled','moved') NOT NULL DEFAULT 'scheduled',
				note VARCHAR(255) NULL,
				PRIMARY KEY  (id),
				KEY term_id (term_id),
				KEY lesson_date (lesson_date)
			) {$charset_collate};",

			"CREATE TABLE {$enrollment} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				term_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				participant_name VARCHAR(190) NOT NULL DEFAULT '',
				role ENUM('solo','leader','follower') NOT NULL DEFAULT 'solo',
				partner_name VARCHAR(190) NULL,
				status ENUM('confirmed','paid','cancelled') NOT NULL DEFAULT 'confirmed',
				over_capacity TINYINT(1) NOT NULL DEFAULT 0,
				price DECIMAL(10,2) NOT NULL,
				discount_note VARCHAR(190) NULL,
				due_date DATE NOT NULL,
				variable_symbol VARCHAR(10) NOT NULL,
				payment_method ENUM('bank_transfer','cash') NOT NULL DEFAULT 'bank_transfer',
				paid_at DATETIME NULL,
				paid_marked_by BIGINT UNSIGNED NULL,
				customer_note TEXT NULL,
				admin_note TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY term_user_participant (term_id,user_id,participant_name),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$email_log} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				enrollment_id BIGINT UNSIGNED NULL,
				user_id BIGINT UNSIGNED NULL,
				type VARCHAR(50) NOT NULL,
				recipient VARCHAR(190) NOT NULL,
				subject VARCHAR(255) NOT NULL,
				sent_at DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'sent',
				PRIMARY KEY  (id),
				KEY enrollment_id (enrollment_id),
				KEY user_id (user_id)
			) {$charset_collate};",
		);
	}
}
