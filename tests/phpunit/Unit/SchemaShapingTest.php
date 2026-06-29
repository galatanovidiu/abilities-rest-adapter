<?php
/**
 * Schema-cleaning and list-detection logic (G5 internals).
 *
 * Pins `clean_schema_node()` (strip REST-internal keys, drop `readonly` and
 * read-only nested props for input, normalize empty `properties` to `{}`) and
 * `is_list()` (only a true JSON list gets the collection envelope). Reaches the
 * protected methods via reflection on a bare instance.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Unit;

use GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability;
use ReflectionMethod;
use stdClass;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class SchemaShapingTest extends WP_UnitTestCase {

	/**
	 * The engine instance reflection invokes against.
	 *
	 * @var WP_REST_Ability
	 */
	private $ability;

	public function set_up(): void {
		parent::set_up();
		$this->ability = new WP_REST_Ability(
			'probe/schema',
			array(
				'label'       => 'Schema',
				'description' => 'Schema probe.',
				'category'    => 'rest',
			)
		);
	}

	/**
	 * Invokes the protected clean_schema_node().
	 *
	 * @param mixed $node           The schema node.
	 * @param bool  $strip_readonly Whether to drop readonly (input).
	 * @return array<string, mixed>
	 */
	private function clean( $node, bool $strip_readonly ): array {
		$method = new ReflectionMethod( WP_REST_Ability::class, 'clean_schema_node' );
		$method->setAccessible( true );
		return $method->invoke( $this->ability, $node, $strip_readonly );
	}

	/**
	 * Invokes the protected is_list().
	 *
	 * @param mixed $value The value to test.
	 */
	private function is_list( $value ): bool {
		$method = new ReflectionMethod( WP_REST_Ability::class, 'is_list' );
		$method->setAccessible( true );
		return $method->invoke( $this->ability, $value );
	}

	public function test_strips_rest_internal_keys(): void {
		$clean = $this->clean(
			array(
				'type'              => 'string',
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'arg_options'       => array( 'foo' => 'bar' ),
				'context'           => array( 'view', 'edit' ),
			),
			false
		);

		$this->assertSame( array( 'type' => 'string' ), $clean );
	}

	public function test_input_node_drops_readonly_key(): void {
		$clean = $this->clean(
			array(
				'type'     => 'string',
				'readonly' => true,
			),
			true
		);

		$this->assertArrayNotHasKey( 'readonly', $clean );
	}

	public function test_output_node_keeps_readonly_key(): void {
		$clean = $this->clean(
			array(
				'type'     => 'string',
				'readonly' => true,
			),
			false
		);

		$this->assertArrayHasKey( 'readonly', $clean );
		$this->assertTrue( $clean['readonly'] );
	}

	public function test_input_prunes_nested_readonly_properties(): void {
		$clean = $this->clean(
			array(
				'type'       => 'object',
				'properties' => array(
					'writable' => array( 'type' => 'string' ),
					'computed' => array(
						'type'     => 'integer',
						'readonly' => true,
					),
				),
			),
			true
		);

		$this->assertArrayHasKey( 'writable', $clean['properties'] );
		$this->assertArrayNotHasKey( 'computed', $clean['properties'] );
	}

	public function test_empty_properties_normalizes_to_object(): void {
		$clean = $this->clean(
			array(
				'type'       => 'object',
				'properties' => array(),
			),
			false
		);

		$this->assertInstanceOf( stdClass::class, $clean['properties'], 'empty properties become {} not []' );
		$this->assertSame( '{"type":"object","properties":{}}', wp_json_encode( $clean ) );
	}

	public function test_empty_object_keywords_normalize_to_object(): void {
		$clean = $this->clean(
			array(
				'type'                 => 'object',
				'additionalProperties' => array( 'sanitize_callback' => 'absint' ),
				'patternProperties'    => array(),
				'items'                => array( 'context' => array( 'view' ) ),
			),
			false
		);

		$this->assertInstanceOf( stdClass::class, $clean['additionalProperties'], 'emptied additionalProperties becomes {}' );
		$this->assertInstanceOf( stdClass::class, $clean['patternProperties'], 'empty patternProperties becomes {}' );
		$this->assertInstanceOf( stdClass::class, $clean['items'], 'emptied items becomes {}' );
	}

	public function test_boolean_additional_properties_is_left_untouched(): void {
		$clean = $this->clean(
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			false
		);

		$this->assertFalse( $clean['additionalProperties'], 'a boolean additionalProperties is not turned into {}' );
	}

	public function test_emptied_combinator_members_are_dropped(): void {
		$clean = $this->clean(
			array(
				'oneOf' => array(
					array( 'type' => 'string' ),
					array( 'sanitize_callback' => 'absint' ),
				),
			),
			false
		);

		$this->assertCount( 1, $clean['oneOf'], 'the all-stripped member is dropped' );
		$this->assertSame( array( 'type' => 'string' ), $clean['oneOf'][0] );
	}

	public function test_combinator_dropped_when_all_members_empty(): void {
		$clean = $this->clean(
			array(
				'type'  => 'string',
				'anyOf' => array(
					array( 'validate_callback' => 'rest_validate_request_arg' ),
				),
			),
			false
		);

		$this->assertArrayNotHasKey( 'anyOf', $clean, 'a combinator with no surviving members is dropped' );
	}

	public function test_drops_closure_values(): void {
		$clean = $this->clean(
			array(
				'type' => 'string',
				'cb'   => static function (): void {},
			),
			false
		);

		$this->assertArrayNotHasKey( 'cb', $clean );
	}

	public function test_is_list_recognizes_json_lists(): void {
		$this->assertTrue( $this->is_list( array() ) );
		$this->assertTrue( $this->is_list( array( 'a', 'b', 'c' ) ) );
	}

	public function test_is_list_rejects_associative_and_non_arrays(): void {
		$this->assertFalse( $this->is_list( array( 'a' => 1 ) ) );
		$this->assertFalse( $this->is_list( array( 1 => 'a', 0 => 'b' ) ) );
		$this->assertFalse( $this->is_list( 'string' ) );
		$this->assertFalse( $this->is_list( 5 ) );
	}
}
