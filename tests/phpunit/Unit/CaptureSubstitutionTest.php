<?php
/**
 * Path-capture substitution and per-capture encoding (G3).
 *
 * Covers four capture cases: a permissive capture round-trips raw, a numeric
 * capture is a no-op, a traversal attempt is refused as no-route (fail closed),
 * and the balanced-paren scan survives nested groups. Exercises the route-pattern
 * module through its public substitution and capture-inspection interface.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Unit;

use GalatanOvidiu\AbilitiesRestAdapter\Route_Pattern;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Route_Pattern
 */
final class CaptureSubstitutionTest extends WP_UnitTestCase {

	/** The real /wp/v2/templates id pattern: permits `/` and `%`, nests a `(?:…)` group. */
	private const TMPL = '/wp/v2/templates/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)';

	/** A numeric capture. */
	private const NUM = '/wp/v2/posts/(?P<id>[\d]+)';

	/**
	 * Substitutes captures through the route-pattern interface.
	 *
	 * @param string               $route The route regex.
	 * @param array<string, mixed> $input The ability input.
	 * @return array{0: string, 1: string[]}|\WP_Error
	 */
	private function substitute( string $route, array $input ) {
		return ( new Route_Pattern( $route ) )->substitute( $input );
	}

	/**
	 * Returns the derived ability type for one capture sub-pattern.
	 */
	private function capture_type( string $subpattern ): string {
		$captures = ( new Route_Pattern( '/probe/(?P<value>' . $subpattern . ')' ) )->captures();
		return $captures['value']['type'];
	}

	public function test_permissive_capture_round_trips_raw(): void {
		list( $path ) = $this->substitute( self::TMPL, array( 'id' => 'twentytwentyfour//home' ) );
		$this->assertSame( '/wp/v2/templates/twentytwentyfour//home', $path, 'no %2F corruption' );
	}

	public function test_numeric_capture_is_a_no_op(): void {
		list( $path ) = $this->substitute( self::NUM, array( 'id' => '123' ) );
		$this->assertSame( '/wp/v2/posts/123', $path );
	}

	public function test_traversal_on_numeric_capture_is_refused_as_no_route(): void {
		$result = $this->substitute( self::NUM, array( 'id' => '12/3' ) );
		$this->assertTrue( is_wp_error( $result ), 'a value the capture forbids does not route' );
		$this->assertSame( 'rest_no_route', $result->get_error_code(), 'fail closed: no route matches an escaped path' );
		$this->assertSame( 404, (int) $result->get_error_data()['status'] );
	}

	public function test_nested_paren_scan_keeps_trailing_segment(): void {
		list( $path ) = $this->substitute( self::TMPL, array( 'id' => 'twentytwentyfour//home' ) );
		$this->assertStringContainsString( 'home', $path, 'balanced-paren scan did not close early on the nested group' );
	}

	/**
	 * A char class with a leading `]` (where `]` is a literal member) must not end
	 * the scan early on the `)` embedded in the class.
	 */
	public function test_char_class_with_leading_bracket_does_not_close_early(): void {
		list( $path ) = $this->substitute( '/wp/v2/x/(?P<id>[])a-z]+)', array( 'id' => 'abc' ) );
		$this->assertSame( '/wp/v2/x/abc', $path, 'the leading ] and embedded ) stay inside the class' );
	}

	public function test_capture_types_distinguish_numeric_patterns(): void {
		$this->assertSame( 'integer', $this->capture_type( '[\d]+' ) );
		$this->assertSame( 'integer', $this->capture_type( '\d' ) );
		$this->assertSame( 'integer', $this->capture_type( '[0-9]' ) );
		$this->assertSame( 'string', $this->capture_type( '[a-z]+' ) );
		$this->assertSame( 'string', $this->capture_type( '[^/]+' ) );
	}

	public function test_capture_types_recognize_numeric_brace_quantifiers(): void {
		$this->assertSame( 'integer', $this->capture_type( '\d{1,}' ) );
		$this->assertSame( 'integer', $this->capture_type( '[\d]{1,6}' ) );
		$this->assertSame( 'integer', $this->capture_type( '[0-9]{4}' ) );
		$this->assertSame( 'string', $this->capture_type( '[a-z]{1,6}' ), 'a brace quantifier does not make a non-numeric class numeric' );
	}
}
