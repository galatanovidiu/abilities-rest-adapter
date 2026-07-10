<?php
/**
 * Route identity checks for overlapping REST route patterns.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class RouteIdentityTest extends AbilityTestCase {

	private const ANGLE_CAPTURE_ROUTE = '/arat-identity/v1/angle/(?<id>[\d]+)';

	private const QUOTE_CAPTURE_ROUTE = "/arat-identity/v1/quote/(?'id'[\\d]+)";

	/** @var int */
	private static $numeric_permission_calls = 0;

	/** @var int */
	private static $numeric_handler_calls = 0;

	/** @var int */
	private static $slug_permission_calls = 0;

	/** @var int */
	private static $slug_handler_calls = 0;

	/** @var int */
	private static $nested_sibling_permission_calls = 0;

	/** @var int */
	private static $nested_sibling_handler_calls = 0;

	public function set_up(): void {
		parent::set_up();

		self::$numeric_permission_calls = 0;
		self::$numeric_handler_calls    = 0;
		self::$slug_permission_calls    = 0;
		self::$slug_handler_calls       = 0;
		self::$nested_sibling_permission_calls = 0;
		self::$nested_sibling_handler_calls    = 0;

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
	 * Registers a specific numeric route before an overlapping permissive route.
	 */
	public function register_test_routes(): void {
		register_rest_route(
			'arat-identity/v1',
			'/things/(?P<id>[\d]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					++self::$numeric_permission_calls;
					return true;
				},
				'callback'            => static function () {
					++self::$numeric_handler_calls;
					return array( 'handler' => 'numeric' );
				},
			)
		);

		register_rest_route(
			'arat-identity/v1',
			'/angle/(?<id>[\d]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( $request ) {
					return array( 'id' => $request['id'] );
				},
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'arat-identity/v1',
			"/quote/(?'id'[\\d]+)",
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( $request ) {
					return array( 'id' => $request['id'] );
				},
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'arat-identity/v1',
			'/things/(?P<slug>[^/]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					++self::$slug_permission_calls;
					return true;
				},
				'callback'            => static function () {
					++self::$slug_handler_calls;
					return array( 'handler' => 'slug' );
				},
			)
		);

		// Register the broad namespace first, then its nested namespace. Core groups
		// matching routes by this namespace order before it matches concrete paths.
		register_rest_route(
			'arat-nested',
			'/bootstrap',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => '__return_empty_array',
			)
		);

		register_rest_route(
			'arat-nested/v1',
			'/things/(?P<id>[\d]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return array( 'handler' => 'nested-target' );
				},
			)
		);

		// This endpoint is later in the global route map, but core groups the broad
		// namespace first and therefore dispatches it before the nested target above.
		register_rest_route(
			'arat-nested',
			'/v1/things/(?P<slug>[^/]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					++self::$nested_sibling_permission_calls;
					return true;
				},
				'callback'            => static function () {
					++self::$nested_sibling_handler_calls;
					return array( 'handler' => 'broad-sibling' );
				},
			)
		);
	}

	/**
	 * A concrete path owned by an earlier sibling must never leave the ability.
	 */
	public function test_overlapping_sibling_route_is_rejected_before_permission_or_dispatch(): void {
		$ability = $this->register_ability(
			'identity/get-thing-by-slug',
			array(
				'route'  => '/arat-identity/v1/things/(?P<slug>[^/]+)',
				'method' => 'GET',
			)
		);

		$result = $ability->execute( array( 'slug' => '123' ) );

		$this->assertTrue( is_wp_error( $result ), 'an overlapping path fails closed' );
		$this->assertSame( 'rest_ability_route_mismatch', $result->get_error_code() );
		$this->assertSame( 409, (int) $result->get_error_data()['status'] );
		$this->assertSame( 0, self::$numeric_permission_calls, 'the sibling permission callback never runs' );
		$this->assertSame( 0, self::$numeric_handler_calls, 'the sibling handler never runs' );
	}

	/**
	 * A URL-safe value that fails its own capture regex is a no-route input error.
	 */
	public function test_url_safe_capture_mismatch_is_rejected_before_sibling_matching(): void {
		$ability = $this->register_ability(
			'identity/get-thing-by-id',
			array(
				'route'        => '/arat-identity/v1/things/(?P<id>[\d]+)',
				'method'       => 'GET',
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'string' ) ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
			)
		);

		$result = $ability->execute( array( 'id' => 'abc' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
		$this->assertSame( 404, (int) $result->get_error_data()['status'] );
		$this->assertSame( 0, self::$slug_permission_calls, 'the sibling permission callback never runs' );
		$this->assertSame( 0, self::$slug_handler_calls, 'the sibling handler never runs' );
	}

	/**
	 * The identity guard must use core's namespace-grouped route ordering.
	 */
	public function test_nested_namespace_overlap_is_rejected_using_core_route_order(): void {
		$ability = $this->register_ability(
			'identity/get-nested-thing',
			array(
				'route'  => '/arat-nested/v1/things/(?P<id>[\d]+)',
				'method' => 'GET',
			)
		);

		$result = $ability->execute( array( 'id' => 123 ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rest_ability_route_mismatch', $result->get_error_code() );
		$this->assertSame( 0, self::$nested_sibling_permission_calls, 'the namespace sibling permission callback never runs' );
		$this->assertSame( 0, self::$nested_sibling_handler_calls, 'the namespace sibling handler never runs' );
	}

	/**
	 * All PCRE named-capture forms accepted by WordPress must substitute correctly.
	 *
	 * @dataProvider named_capture_route_provider
	 */
	public function test_wordpress_named_capture_syntaxes_execute( string $name, string $route ): void {
		$ability = $this->register_ability(
			$name,
			array(
				'route'  => $route,
				'method' => 'GET',
			)
		);

		$result = $ability->execute( array( 'id' => 123 ) );

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 123, $result['id'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function named_capture_route_provider(): array {
		return array(
			'angle brackets' => array( 'identity/get-angle', self::ANGLE_CAPTURE_ROUTE ),
			'quoted name'    => array( 'identity/get-quote', self::QUOTE_CAPTURE_ROUTE ),
		);
	}
}
