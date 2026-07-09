<?php
/**
 * The single place the rest of the plugin asks language questions.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lang.
 *
 * Wraps Polylang so every other class asks *this* class "what language?"
 * instead of calling `pll_*()` functions directly. Kept WordPress/Polylang
 * -agnostic the same way `Schema_Upgrader` and `Location_Service` are: every
 * touchpoint is an injected callable (see `create_default()` for the real
 * wiring), so the fallback behaviour is unit-testable with plain PHPUnit.
 *
 * Per the milestone acceptance criteria: the plugin may outlive the
 * multilingual choice, so every method here must degrade gracefully to
 * Czech (the site's default language, per spec §5 Multilingual) when
 * Polylang is absent or inactive — never a fatal error.
 */
class Lang {

	const CS = 'cs';
	const EN = 'en';

	/**
	 * Language the site falls back to when Polylang can't answer (absent,
	 * inactive, or not yet configured).
	 *
	 * @var string
	 */
	const DEFAULT_LANGUAGE = self::CS;

	/**
	 * Returns the current front-end language slug, or null if unknown:
	 * function(): ?string.
	 *
	 * @var callable
	 */
	private $current_language;

	/**
	 * Resolves a post to its translation in a given language, or null if
	 * there is no such translation: function( int $post_id, string $lang ): ?int.
	 *
	 * @var callable
	 */
	private $translated_post_id;

	/**
	 * Constructor.
	 *
	 * @param callable $current_language   function(): ?string.
	 * @param callable $translated_post_id function( int $post_id, string $lang ): ?int.
	 */
	public function __construct( callable $current_language, callable $translated_post_id ) {
		$this->current_language   = $current_language;
		$this->translated_post_id = $translated_post_id;
	}

	/**
	 * Wire the helper to the real Polylang API, guarding every call so a
	 * site with Polylang deactivated never fatals.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self(
			static function (): ?string {
				if ( ! function_exists( 'pll_current_language' ) ) {
					return null;
				}

				$lang = pll_current_language();

				return false === $lang || '' === $lang ? null : (string) $lang;
			},
			static function ( int $post_id, string $lang ): ?int {
				if ( ! function_exists( 'pll_get_post' ) ) {
					return null;
				}

				$translated = pll_get_post( $post_id, $lang );

				return false === $translated || 0 === $translated ? null : (int) $translated;
			}
		);
	}

	/**
	 * The current language, falling back to `self::DEFAULT_LANGUAGE` when
	 * Polylang is absent, inactive, or simply hasn't set one yet (e.g. CLI
	 * context, REST requests without a `lang` param).
	 *
	 * @return string
	 */
	public function current(): string {
		$lang = ( $this->current_language )();

		return null === $lang || '' === $lang ? self::DEFAULT_LANGUAGE : $lang;
	}

	/**
	 * Resolve a course post to its translation for the given (or current)
	 * language. Falls back to the original post ID when there is no
	 * translation, or when Polylang is absent — the caller always gets a
	 * usable post ID, never null.
	 *
	 * @param int         $post_id Canonical (Czech) post ID, per spec §5:
	 *                             structured data always points at the Czech post.
	 * @param string|null $lang    Language slug; defaults to the current language.
	 * @return int
	 */
	public function resolve_post( int $post_id, ?string $lang = null ): int {
		$lang     = $lang ?? $this->current();
		$resolved = ( $this->translated_post_id )( $post_id, $lang );

		return null === $resolved ? $post_id : $resolved;
	}

	/**
	 * Pick the `{$field}_cs` or `{$field}_en` variant from a row of a
	 * custom table (e.g. `wp_rd_course_term.season_label_cs/_en`, per spec
	 * §5 Multilingual: "paired _cs/_en columns"). Falls back to `_cs` when
	 * the language-specific column is missing or blank, and always for any
	 * language other than `self::EN`.
	 *
	 * @param array<string, mixed> $row   Table row, e.g. from a repository.
	 * @param string               $field Column base name, e.g. `season_label`.
	 * @param string|null          $lang  Language slug; defaults to the current language.
	 * @return string
	 */
	public function pick( array $row, string $field, ?string $lang = null ): string {
		$lang = $lang ?? $this->current();

		if ( self::EN === $lang ) {
			$en_value = $row[ $field . '_en' ] ?? '';

			if ( '' !== trim( (string) $en_value ) ) {
				return (string) $en_value;
			}
		}

		return (string) ( $row[ $field . '_cs' ] ?? '' );
	}
}
