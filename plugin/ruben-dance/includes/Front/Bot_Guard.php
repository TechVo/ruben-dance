<?php
/**
 * Honeypot + time-trap bot baseline for public forms (spec §5 layer 1).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Bot_Guard.
 *
 * Two independent, cheap signals a scripted bot almost always trips and a
 * human almost never does: a text field hidden from sighted users (a real
 * visitor never fills it in; a bot filling every field does) and a
 * minimum-elapsed-time check between rendering the form and receiving the
 * submission (a bot posts instantly; a human takes at least a couple of
 * seconds). The timestamp is HMAC-signed with `wp_hash()` so a bot cannot
 * simply forge an old value to skip the wait.
 */
class Bot_Guard {

	const HONEYPOT_FIELD  = 'rd_hp_website';
	const TIMESTAMP_FIELD = 'rd_form_ts';

	/**
	 * Minimum seconds that must elapse between render and submission.
	 *
	 * @var int
	 */
	const MIN_SECONDS = 3;

	/**
	 * The hidden fields to embed in a form: the honeypot input plus the
	 * signed render-time marker.
	 *
	 * @return string Raw HTML, already escaped.
	 */
	public static function fields_html(): string {
		$timestamp = time();
		$signed    = $timestamp . '.' . self::sign( $timestamp );

		return sprintf(
			'<div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">'
			. '<label for="%1$s">%2$s</label>'
			. '<input type="text" id="%1$s" name="%1$s" value="" autocomplete="off" tabindex="-1"></div>'
			. '<input type="hidden" name="%3$s" value="%4$s">',
			esc_attr( self::HONEYPOT_FIELD ),
			esc_html__( 'Leave this field empty', 'ruben-dance' ),
			esc_attr( self::TIMESTAMP_FIELD ),
			esc_attr( $signed )
		);
	}

	/**
	 * Whether a submission looks like a bot: the honeypot was filled in, or
	 * the signed timestamp is missing/tampered/too recent.
	 *
	 * @param array<string, mixed> $post Unslashed `$_POST` data.
	 * @return bool
	 */
	public static function is_bot( array $post ): bool {
		$honeypot = trim( (string) ( $post[ self::HONEYPOT_FIELD ] ?? '' ) );

		if ( '' !== $honeypot ) {
			return true;
		}

		$rendered_at = self::verified_timestamp( (string) ( $post[ self::TIMESTAMP_FIELD ] ?? '' ) );

		if ( null === $rendered_at ) {
			return true;
		}

		return ( time() - $rendered_at ) < self::MIN_SECONDS;
	}

	/**
	 * HMAC a timestamp using WordPress' own secret keys, so it can be
	 * embedded in the page yet still be tamper-evident.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function sign( int $timestamp ): string {
		return wp_hash( self::TIMESTAMP_FIELD . '|' . $timestamp );
	}

	/**
	 * Validate and decode a `timestamp.signature` value.
	 *
	 * @param string $raw Raw field value.
	 * @return int|null The timestamp, or null if missing/malformed/tampered.
	 */
	private static function verified_timestamp( string $raw ): ?int {
		$parts = explode( '.', $raw, 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return null;
		}

		$timestamp = (int) $parts[0];

		if ( ! hash_equals( self::sign( $timestamp ), $parts[1] ) ) {
			return null;
		}

		return $timestamp;
	}
}
