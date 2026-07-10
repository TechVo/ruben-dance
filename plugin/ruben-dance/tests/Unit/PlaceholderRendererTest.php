<?php
/**
 * Tests for the email template `{placeholder}` renderer.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Emails\Placeholder_Renderer;

/**
 * Class PlaceholderRendererTest.
 *
 * `Placeholder_Renderer` is deliberately pure PHP (no WordPress
 * touchpoints), so the M13 acceptance-criteria cases — missing values and
 * HTML escaping — are exercised here with plain PHPUnit, no WordPress
 * bootstrap needed.
 */
class PlaceholderRendererTest extends TestCase {

	/**
	 * The basic substitution: every known token is replaced with its value.
	 */
	public function test_replaces_known_tokens(): void {
		$this->assertSame(
			'Hi Jana, welcome to Salsa.',
			Placeholder_Renderer::render(
				'Hi {first_name}, welcome to {course}.',
				array(
					'first_name' => 'Jana',
					'course'     => 'Salsa',
				)
			)
		);
	}

	/**
	 * A token with no corresponding value is replaced with an empty string —
	 * never left as literal `{token}` text (M13 acceptance criteria: "no
	 * `{...}` leftovers").
	 */
	public function test_missing_value_renders_empty_not_literal(): void {
		$rendered = Placeholder_Renderer::render( 'Course: {course}; VS: {variable_symbol}.', array( 'course' => 'Salsa' ) );

		$this->assertSame( 'Course: Salsa; VS: .', $rendered );
		$this->assertStringNotContainsString( '{', $rendered );
	}

	/**
	 * An explicit null value behaves exactly like a missing one.
	 */
	public function test_null_value_renders_empty(): void {
		$this->assertSame(
			'Note: ',
			Placeholder_Renderer::render( 'Note: {note}', array( 'note' => null ) )
		);
	}

	/**
	 * Values are HTML-escaped by default — a participant named
	 * `<script>...` must never become live markup in an HTML email body.
	 */
	public function test_escapes_html_in_values_by_default(): void {
		$this->assertSame(
			'Hi &lt;script&gt;alert(1)&lt;/script&gt; &amp; co.',
			Placeholder_Renderer::render(
				'Hi {first_name}',
				array( 'first_name' => '<script>alert(1)</script> & co.' )
			)
		);
	}

	/**
	 * Quotes are escaped too (`ENT_QUOTES`), so a value can safely land
	 * inside an attribute like `href="{link}"`.
	 */
	public function test_escapes_quotes_for_attribute_contexts(): void {
		$this->assertSame(
			'<a href="x&quot; onmouseover=&quot;evil()">link</a>',
			Placeholder_Renderer::render(
				'<a href="{link}">link</a>',
				array( 'link' => 'x" onmouseover="evil()' )
			)
		);
	}

	/**
	 * The template's own markup is preserved — only substituted values are
	 * escaped, never the surrounding template text.
	 */
	public function test_template_markup_is_not_escaped(): void {
		$this->assertSame(
			'<p><strong>Jana</strong></p>',
			Placeholder_Renderer::render( '<p><strong>{first_name}</strong></p>', array( 'first_name' => 'Jana' ) )
		);
	}

	/**
	 * With escaping off (subject lines), values pass through verbatim —
	 * HTML entities in a plain-text subject would be wrong.
	 */
	public function test_no_escaping_when_disabled(): void {
		$this->assertSame(
			'Enrollment: Rock & Roll',
			Placeholder_Renderer::render( 'Enrollment: {course}', array( 'course' => 'Rock & Roll' ), false )
		);
	}

	/**
	 * A token the caller never heard of (typo, removed placeholder) is
	 * removed rather than delivered as `{typo}` to a customer.
	 */
	public function test_unknown_token_is_removed(): void {
		$this->assertSame(
			'Hello !',
			Placeholder_Renderer::render( 'Hello {no_such_token}!', array( 'first_name' => 'Jana' ) )
		);
	}

	/**
	 * Literal braces that don't form a `{token}` (e.g. `{ price }` with
	 * spaces, or a lone `{`) are left untouched — only well-formed tokens
	 * are placeholder syntax.
	 */
	public function test_malformed_braces_left_alone(): void {
		$this->assertSame(
			'{ price } { and a lone {',
			Placeholder_Renderer::render( '{ price } { and a lone {', array( 'price' => '100' ) )
		);
	}

	/**
	 * The same token may appear multiple times; every occurrence is replaced.
	 */
	public function test_repeated_token_replaced_everywhere(): void {
		$this->assertSame(
			'a a a',
			Placeholder_Renderer::render( '{x} {x} {x}', array( 'x' => 'a' ) )
		);
	}

	/**
	 * Non-ASCII values (Czech names/labels) survive escaping unharmed —
	 * `htmlspecialchars()` must run in UTF-8 mode.
	 */
	public function test_utf8_values_survive(): void {
		$this->assertSame(
			'Ahoj Růžena, kurz Čača!',
			Placeholder_Renderer::render(
				'Ahoj {first_name}, kurz {course}!',
				array(
					'first_name' => 'Růžena',
					'course'     => 'Čača',
				)
			)
		);
	}
}
