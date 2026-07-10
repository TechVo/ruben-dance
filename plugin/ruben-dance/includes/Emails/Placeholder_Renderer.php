<?php
/**
 * Pure `{placeholder}` substitution for email templates (F14).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Placeholder_Renderer.
 *
 * Deliberately zero WordPress touchpoints (no `esc_html()`/`sprintf()`
 * translation calls) — mirrors `Pricing_Service`/`Due_Date_Calculator`'s
 * "pure PHP, plain-PHPUnit-testable" reasoning, since the milestone
 * acceptance criteria explicitly calls for unit tests of this exact class
 * ("placeholder renderer (missing values, HTML escaping)"). A `{token}` with
 * no corresponding key (missing value, or a token the caller never
 * populated) is replaced with an empty string rather than left as literal
 * `{token}` text — spec/M13 acceptance criterion "no `{...}` leftovers".
 */
class Placeholder_Renderer {

	/**
	 * Replace every `{token}` in `$template` with its value from `$values`.
	 *
	 * @param string                     $template Template text containing `{token}` placeholders.
	 * @param array<string, string|null> $values   Token (without braces) => replacement value.
	 * @param bool                       $escape_html Whether to HTML-escape each substituted value
	 *                                                 (true for an HTML email body; false for a plain
	 *                                                 subject line, where HTML entities would be wrong).
	 * @return string
	 */
	public static function render( string $template, array $values, bool $escape_html = true ): string {
		$rendered = preg_replace_callback(
			'/\{([a-zA-Z0-9_]+)\}/',
			static function ( array $matches ) use ( $values, $escape_html ): string {
				$key = $matches[1];

				if ( ! array_key_exists( $key, $values ) || null === $values[ $key ] ) {
					return '';
				}

				$value = (string) $values[ $key ];

				return $escape_html ? htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) : $value;
			},
			$template
		);

		return null === $rendered ? $template : $rendered;
	}
}
