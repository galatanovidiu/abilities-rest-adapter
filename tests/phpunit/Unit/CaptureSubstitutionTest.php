<?php
/**
 * Path-capture substitution and per-capture encoding (G3).
 *
 * Ports `spikes/phase2-g3.php`: a permissive capture round-trips raw, a numeric
 * capture is a no-op, a traversal attempt is encoded (fail closed), and the
 * balanced-paren scan survives nested groups. Reaches the protected engine
 * methods via reflection on a bare instance (the subclass skips the
 * execute/permission-callback requirement, so minimal args construct cleanly).
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Unit;

use GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class CaptureSubstitutionTest extends WP_UnitTestCase {

	/** The real /wp/v2/templates id pattern: permits `/` and `%`, nests a `(?:…)` group. */
	private const TMPL = '/wp/v2/templates/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)';

	/** A numeric capture. */
	private const NUM = '/wp/v2/posts/(?P<id>[\d]+)';

	/**
	 * The engine instance reflection invokes against.
	 *
	 * @var WP_REST_Ability
	 */
	private $ability;

	public function set_up(): void {
		parent::set_up();
		$this->ability = new WP_REST_Ability(
			'probe/g3',
			array(
				'label'       => 'G3',
				'description' => 'G3 probe.',
				'category'    => 'rest',
			)
		);
	}

	/**
	 * Invokes the protected substitute_captures().
	 *
	 * @param string               $route The route regex.
	 * @param array<string, mixed> $input The ability input.
	 * @return array{0: string, 1: string[]}|\WP_Error
	 */
	private function substitute( string $route, array $input ) {
		$method = new ReflectionMethod( WP_REST_Ability::class, 'substitute_captures' );
		$method->setAccessible( true );
		return $method->invoke( $this->ability, $route, $input );
	}

	/**
	 * Invokes the protected encode_capture().
	 */
	private function encode( string $value, string $subpattern ): string {
		$method = new ReflectionMethod( WP_REST_Ability::class, 'encode_capture' );
		$method->setAccessible( true );
		return $method->invoke( $this->ability, $value, $subpattern );
	}

	/**
	 * Invokes the protected is_numeric_subpattern().
	 */
	private function is_numeric( string $subpattern ): bool {
		$method = new ReflectionMethod( WP_REST_Ability::class, 'is_numeric_subpattern' );
		$method->setAccessible( true );
		return $method->invoke( $this->ability, $subpattern );
	}

	public function test_permissive_capture_round_trips_raw(): void {
		list( $path ) = $this->substitute( self::TMPL, array( 'id' => 'twentytwentyfour//home' ) );
		$this->assertSame( '/wp/v2/templates/twentytwentyfour//home', $path, 'no %2F corruption' );
	}

	public function test_numeric_capture_is_a_no_op(): void {
		list( $path ) = $this->substitute( self::NUM, array( 'id' => '123' ) );
		$this->assertSame( '/wp/v2/posts/123', $path );
	}

	public function test_traversal_on_numeric_capture_is_encoded(): void {
		list( $path ) = $this->substitute( self::NUM, array( 'id' => '12/3' ) );
		$this->assertStringContainsString( '%2F', $path, 'fail closed: a value the capture forbids is encoded' );
	}

	public function test_nested_paren_scan_keeps_trailing_segment(): void {
		list( $path ) = $this->substitute( self::TMPL, array( 'id' => 'twentytwentyfour//home' ) );
		$this->assertStringContainsString( 'home', $path, 'balanced-paren scan did not close early on the nested group' );
	}

	/**
	 * B1: a char class with a leading `]` (where `]` is a literal member) must not
	 * end the scan early on the `)` embedded in the class.
	 */
	public function test_char_class_with_leading_bracket_does_not_close_early(): void {
		list( $path ) = $this->substitute( '/wp/v2/x/(?P<id>[])a-z]+)', array( 'id' => 'abc' ) );
		$this->assertSame( '/wp/v2/x/abc', $path, 'the leading ] and embedded ) stay inside the class' );
	}

	public function test_encode_capture_leaves_matching_value_raw(): void {
		$this->assertSame( 'abc', $this->encode( 'abc', '[a-z]+' ) );
		$this->assertSame( '123', $this->encode( '123', '[\d]+' ) );
	}

	public function test_encode_capture_escapes_a_value_the_pattern_forbids(): void {
		$this->assertSame( 'a%2Fb', $this->encode( 'a/b', '[a-z]+' ) );
	}

	public function test_is_numeric_subpattern_classifies_capture(): void {
		$this->assertTrue( $this->is_numeric( '[\d]+' ) );
		$this->assertTrue( $this->is_numeric( '\d' ) );
		$this->assertTrue( $this->is_numeric( '[0-9]' ) );
		$this->assertFalse( $this->is_numeric( '[a-z]+' ) );
		$this->assertFalse( $this->is_numeric( '[^/]+' ) );
	}
}
