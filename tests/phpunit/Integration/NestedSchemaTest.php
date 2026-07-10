<?php
/**
 * Nested REST schema projection through a registered ability.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;
use WP_REST_Request;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class NestedSchemaTest extends AbilityTestCase {

	private const ROUTE = '/arat-schema/v1/nested';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', array( $this, 'register_test_routes' ) );
		rest_get_server();
	}

	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this, 'register_test_routes' ) );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Registers an object argument whose field names overlap schema keywords.
	 */
	public function register_test_routes(): void {
		register_rest_route(
			'arat-schema/v1',
			'/nested',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( $request ) {
					return array( 'config' => $request['config'] );
				},
				'args'                => array(
					'config' => array(
						'type'                 => 'object',
						'properties'           => array(
							'context'  => array( 'type' => 'string' ),
							'readonly' => array( 'type' => 'boolean' ),
						),
						'required'             => array( 'context', 'readonly' ),
						'additionalProperties' => false,
					),
				),
			)
		);

		register_rest_route(
			'arat-schema/v1',
			'/defaulted',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( $request ) {
					return array( 'config' => $request['config'] );
				},
				'args'                => array(
					'config'       => array(
						'type'       => 'object',
						'properties' => array(
							'context'  => array( 'type' => 'string' ),
							'readonly' => array( 'type' => 'boolean' ),
						),
						'default'    => array(
							'context'  => 'view',
							'readonly' => false,
						),
					),
					'empty_config' => array(
						'type'    => 'object',
						'default' => new \stdClass(),
					),
				),
			)
		);

		register_rest_route(
			'arat-schema/v1',
			'/object-output',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => '__return_empty_array',
				'schema'              => static function (): array {
					return array(
						'properties' => array(
							'visible' => (object) array(
								'type'    => 'string',
								'context' => array( 'view' ),
							),
							'hidden'  => (object) array(
								'type'    => 'string',
								'context' => array( 'edit' ),
							),
							'invalid' => 'not-a-schema',
						),
					);
				},
			)
		);

		register_rest_route(
			'arat-schema/v1',
			'/empty-output',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => '__return_empty_array',
				'schema'              => static function (): array {
					return array( 'type' => 'object' );
				},
			)
		);
	}

	/**
	 * Nested data-property names must not be mistaken for schema keywords.
	 */
	public function test_nested_keyword_named_properties_match_direct_rest_behavior(): void {
		$input = array(
			'config' => array(
				'context'  => 'editor',
				'readonly' => true,
			),
		);

		$direct_request = new WP_REST_Request( 'POST', self::ROUTE );
		$direct_request->set_body_params( $input );
		$direct = rest_do_request( $direct_request );
		$this->assertFalse( $direct->is_error(), 'the source REST route accepts the nested object' );

		$ability = $this->register_ability(
			'schema/update-nested',
			array(
				'route'  => self::ROUTE,
				'method' => 'POST',
				'meta'   => array(
					'annotations' => array(
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$result = $ability->execute( $input );

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( $direct->get_data(), $result );
	}

	/**
	 * Data-valued schema keywords preserve keys that happen to match schema keywords.
	 */
	public function test_nested_default_value_is_preserved_verbatim(): void {
		$ability = $this->register_ability(
			'schema/get-defaulted',
			array(
				'route'  => '/arat-schema/v1/defaulted',
				'method' => 'GET',
			)
		);

		$schema = $ability->get_input_schema();

		$this->assertSame(
			array(
				'context'  => 'view',
				'readonly' => false,
			),
			$schema['properties']['config']['default']
		);
		$this->assertInstanceOf( \stdClass::class, $schema['properties']['empty_config']['default'] );
		$this->assertSame( '{}', wp_json_encode( $schema['properties']['empty_config']['default'] ) );
	}

	/**
	 * Object-shaped output properties obey root context and malformed entries drop out.
	 */
	public function test_object_output_properties_are_filtered_at_the_root(): void {
		$ability = $this->register_ability(
			'schema/get-object-output',
			array(
				'route'  => '/arat-schema/v1/object-output',
				'method' => 'GET',
			)
		);

		$schema = $ability->get_output_schema();

		$this->assertSame( array( 'visible' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'type' => 'string' ), $schema['properties']['visible'] );
	}

	/**
	 * A callable route schema without properties advertises no output schema.
	 */
	public function test_callable_output_schema_without_properties_does_not_fatal(): void {
		$ability = $this->register_ability(
			'schema/get-empty-output',
			array(
				'route'  => '/arat-schema/v1/empty-output',
				'method' => 'GET',
			)
		);

		$this->assertSame( array(), $ability->get_output_schema() );
	}
}
