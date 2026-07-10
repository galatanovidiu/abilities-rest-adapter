<?php
/**
 * Schema-cleaning and list-detection logic (G5 internals).
 *
 * Pins `clean_schema_node()` (strip REST-internal keys, drop `readonly` and
 * read-only nested props for input, omit empty schema maps) and
 * `is_list()` (only a true JSON list gets the collection envelope). Reaches the
 * protected methods via reflection on a bare instance.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Unit;

use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class SchemaShapingTest extends WP_UnitTestCase {

	/**
	 * The engine instance reflection invokes against.
	 *
	 * @var \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
	 */
	private $ability;

	public function set_up(): void {
		parent::set_up();
		$this->ability = new Rest_Route_Ability(
			'probe/schema',
			array(
				'label'       => 'Schema',
				'description' => 'Schema probe.',
				'category'    => 'rest',
			)
		);
	}

	/**
	 * Builds a reflection handle for a non-public method under test.
	 *
	 * setAccessible() is required before PHP 8.1 to invoke a non-public method;
	 * it is a no-op on 8.1+ and deprecated on 8.5, so it is only called where it
	 * is still needed.
	 *
	 * @param string $name The method name on Rest_Route_Ability.
	 */
	private function accessible_method( string $name ): ReflectionMethod {
		$method = new ReflectionMethod( Rest_Route_Ability::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method;
	}

	/**
	 * Invokes the protected clean_schema_node().
	 *
	 * @param mixed $node           The schema node.
	 * @param bool  $strip_readonly Whether to drop readonly (input).
	 * @return array<string, mixed>
	 */
	private function clean( $node, bool $strip_readonly ): array {
		$method = $this->accessible_method( 'clean_schema_node' );
		return $method->invoke( $this->ability, $node, $strip_readonly );
	}

	/**
	 * Invokes the protected is_list().
	 *
	 * @param mixed $value The value to test.
	 */
	private function is_list( $value ): bool {
		$method = $this->accessible_method( 'is_list' );
		return $method->invoke( $this->ability, $value );
	}

	/**
	 * Invokes the protected detect_collection().
	 *
	 * @param array<string, mixed> $handler The route handler.
	 */
	private function detect_collection( array $handler ): bool {
		$method = $this->accessible_method( 'detect_collection' );
		return $method->invoke( $this->ability, $handler );
	}

	public function test_get_items_callback_is_a_collection(): void {
		$this->assertTrue( $this->detect_collection( array( 'callback' => array( $this->ability, 'get_items' ) ) ) );
	}

	public function test_pagination_args_alone_are_not_a_collection(): void {
		$this->assertFalse(
			$this->detect_collection(
				array(
					'callback' => static function (): void {},
					'args'     => array(
						'per_page' => array(),
						'page'     => array(),
					),
				)
			),
			'pagination args alone do not force the collection envelope'
		);
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

	public function test_input_prunes_readonly_property_from_sibling_required(): void {
		$clean = $this->clean(
			array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'readonly' => true ),
					'name' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'name' ),
			),
			true
		);

		$this->assertArrayNotHasKey( 'id', $clean['properties'] );
		$this->assertArrayHasKey( 'name', $clean['properties'] );
		$this->assertSame( array( 'name' ), $clean['required'], 'the pruned readonly prop is gone from required' );
	}

	public function test_input_drops_required_when_only_prop_is_readonly(): void {
		$clean = $this->clean(
			array(
				'type'       => 'object',
				'properties' => array(
					'id' => array( 'readonly' => true ),
				),
				'required'   => array( 'id' ),
			),
			true
		);

		$this->assertArrayNotHasKey( 'properties', $clean, 'an emptied properties map is omitted' );
		$this->assertArrayNotHasKey( 'required', $clean, 'an emptied required is dropped entirely' );
	}

	public function test_empty_properties_is_omitted(): void {
		$clean = $this->clean(
			array(
				'type'       => 'object',
				'properties' => array(),
			),
			false
		);

		$this->assertArrayNotHasKey( 'properties', $clean );
		$this->assertSame( '{"type":"object"}', wp_json_encode( $clean ) );
	}

	public function test_empty_schema_map_keywords_are_omitted(): void {
		$clean = $this->clean(
			array(
				'type'                 => 'object',
				'additionalProperties' => array( 'sanitize_callback' => 'absint' ),
				'patternProperties'    => array(),
				'items'                => array( 'context' => array( 'view' ) ),
			),
			false
		);

		$this->assertArrayNotHasKey( 'additionalProperties', $clean );
		$this->assertArrayNotHasKey( 'patternProperties', $clean );
		$this->assertArrayNotHasKey( 'items', $clean );
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
		$this->assertFalse(
			$this->is_list(
				array(
					1 => 'a',
					0 => 'b',
				)
			)
		);
		$this->assertFalse( $this->is_list( 'string' ) );
		$this->assertFalse( $this->is_list( 5 ) );
	}
}
