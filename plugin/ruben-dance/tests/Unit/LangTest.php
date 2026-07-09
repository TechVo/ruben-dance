<?php
/**
 * Tests for the `Lang` helper: the `_cs`/`_en` column pick and the
 * Polylang-absent graceful degradation.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Lang;

/**
 * Class LangTest.
 *
 * `Lang` is deliberately WordPress/Polylang-agnostic (every touchpoint is an
 * injected callable, mirroring `Schema_Upgrader` and `Location_Service`), so
 * both the `_cs`/`_en` pick and the fallback-to-Czech behaviour are exercised
 * here with plain PHPUnit and in-memory fakes — no WordPress bootstrap needed.
 */
class LangTest extends TestCase {

	/**
	 * Build a helper wired to simple in-memory fakes.
	 *
	 * @param string|null $current_language     Value returned by the current-language callable.
	 * @param array<int, int|null> $translations Post ID => translated post ID (or null), by requested post ID.
	 * @return Lang
	 */
	private function make_lang( ?string $current_language, array $translations = array() ): Lang {
		return new Lang(
			static function () use ( $current_language ): ?string {
				return $current_language;
			},
			static function ( int $post_id, string $lang ) use ( $translations ): ?int {
				unset( $lang );

				return $translations[ $post_id ] ?? null;
			}
		);
	}

	/**
	 * `current()` returns whatever the injected callable reports.
	 */
	public function test_current_returns_injected_language(): void {
		$lang = $this->make_lang( 'en' );

		$this->assertSame( 'en', $lang->current() );
	}

	/**
	 * `current()` falls back to Czech when the injected callable can't say
	 * (Polylang absent, inactive, or simply hasn't set one yet) — the
	 * scenario the milestone calls out explicitly: no fatal, just CS.
	 */
	public function test_current_falls_back_to_czech_when_unknown(): void {
		$lang = $this->make_lang( null );

		$this->assertSame( Lang::CS, $lang->current() );
	}

	/**
	 * `create_default()` — the real Polylang wiring — must itself fall back
	 * to Czech when Polylang is not loaded at all, since the plain-PHPUnit
	 * bootstrap (per tests/bootstrap.php) never defines any `pll_*()`
	 * function. This is the exact "plugin may outlive the multilingual
	 * choice" scenario from the acceptance criteria, exercised without any
	 * fakes.
	 */
	public function test_create_default_falls_back_to_czech_without_polylang(): void {
		$lang = Lang::create_default();

		$this->assertSame( Lang::CS, $lang->current() );
		$this->assertSame( 42, $lang->resolve_post( 42 ) );
	}

	/**
	 * `resolve_post()` returns the translation when one exists.
	 */
	public function test_resolve_post_returns_translation_when_available(): void {
		$lang = $this->make_lang( 'en', array( 10 => 20 ) );

		$this->assertSame( 20, $lang->resolve_post( 10 ) );
	}

	/**
	 * `resolve_post()` falls back to the original post ID when there is no
	 * translation for the requested language (or Polylang is absent) — the
	 * caller always gets a usable ID, never null.
	 */
	public function test_resolve_post_falls_back_to_original_when_no_translation(): void {
		$lang = $this->make_lang( 'en', array() );

		$this->assertSame( 10, $lang->resolve_post( 10 ) );
	}

	/**
	 * `pick()` returns the `_en` column when the current language is English
	 * and the value is non-blank.
	 */
	public function test_pick_returns_en_column_for_english(): void {
		$lang = $this->make_lang( 'en' );
		$row  = array(
			'season_label_cs' => 'Podzim 2026',
			'season_label_en' => 'Autumn 2026',
		);

		$this->assertSame( 'Autumn 2026', $lang->pick( $row, 'season_label' ) );
	}

	/**
	 * `pick()` returns the `_cs` column for any non-English language,
	 * including the default fallback.
	 */
	public function test_pick_returns_cs_column_by_default(): void {
		$lang = $this->make_lang( null );
		$row  = array(
			'season_label_cs' => 'Podzim 2026',
			'season_label_en' => 'Autumn 2026',
		);

		$this->assertSame( 'Podzim 2026', $lang->pick( $row, 'season_label' ) );
	}

	/**
	 * `pick()` falls back to `_cs` when the `_en` column is missing or blank
	 * — English content is content work for the owners (spec §5) and may
	 * lag behind, this must never surface an empty string.
	 */
	public function test_pick_falls_back_to_cs_when_en_column_blank(): void {
		$lang = $this->make_lang( 'en' );
		$row  = array(
			'note_public_cs' => 'Vezměte si taneční obuv.',
			'note_public_en' => '',
		);

		$this->assertSame( 'Vezměte si taneční obuv.', $lang->pick( $row, 'note_public' ) );
	}

	/**
	 * `pick()` treats a missing `_en` key the same as blank (defensive:
	 * repositories that select specific columns may omit it entirely).
	 */
	public function test_pick_falls_back_to_cs_when_en_column_missing(): void {
		$lang = $this->make_lang( 'en' );
		$row  = array( 'note_public_cs' => 'Vezměte si taneční obuv.' );

		$this->assertSame( 'Vezměte si taneční obuv.', $lang->pick( $row, 'note_public' ) );
	}

	/**
	 * An explicit `$lang` argument overrides the current language, without
	 * needing the injected current-language callable at all.
	 */
	public function test_explicit_lang_argument_overrides_current(): void {
		$lang = $this->make_lang( 'cs' );
		$row  = array(
			'season_label_cs' => 'Podzim 2026',
			'season_label_en' => 'Autumn 2026',
		);

		$this->assertSame( 'Autumn 2026', $lang->pick( $row, 'season_label', Lang::EN ) );
	}
}
