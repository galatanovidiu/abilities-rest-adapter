<?php
/**
 * The output_callback reshaping seam and the output_schema arg.
 *
 * The adapter facilitates: the ability-definer reshapes a successful response
 * with an `output_callback` and describes the result with an `output_schema`.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class OutputCallbackTest extends AbilityTestCase {

	public function set_up(): void {
		parent::set_up();

		// Rebuild the REST server so the custom error route registers.
		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', array( $this, 'register_test_routes' ) );
		rest_get_server();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
	}

	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this, 'register_test_routes' ) );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Registers a route whose handler returns a WP_Error (a dispatch-level error).
	 *
	 * @return void
	 */
	public function register_test_routes(): void {
		register_rest_route(
			'arat-test/v1',
			'/boom',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return new \WP_Error( 'boom', 'Boom', array( 'status' => 500 ) );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The callback runs over the collection envelope, sees input + response, and reshapes.
	 */
	public function test_callback_reshapes_collection_envelope(): void {
		$captured = array();
		$callback = static function ( $data, $input, $response ) use ( &$captured ) {
			$captured['input_is_array']  = is_array( $input );
			$captured['response_is_obj'] = $response instanceof \WP_REST_Response;
			$lean                        = array();
			foreach ( $data['items'] as $item ) {
				$lean[] = array(
					'id'    => $item['id'],
					'title' => $item['title']['rendered'],
				);
			}
			return array(
				'items' => $lean,
				'total' => $data['total'],
			);
		};

		$posts  = $this->register_ability( 'cb/posts', array( 'route' => '/wp/v2/posts', 'method' => 'GET', 'output_callback' => $callback ) );
		$result = $posts->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayNotHasKey( 'total_pages', $result, 'callback dropped total_pages' );
		$this->assertSame( array( 'id', 'title' ), array_keys( $result['items'][0] ), 'each item reshaped to id + title' );
		$this->assertTrue( $captured['input_is_array'], 'callback received the input' );
		$this->assertTrue( $captured['response_is_obj'], 'callback received the WP_REST_Response' );
	}

	/**
	 * The callback may reject a successful result by returning a WP_Error.
	 */
	public function test_callback_can_return_wp_error(): void {
		$callback = static function ( $data, $input, $response ) {
			return new \WP_Error( 'demo_rejected', 'Rejected by callback.', array( 'status' => 422 ) );
		};

		$me     = $this->register_ability( 'cb/reject', array( 'route' => '/wp/v2/users/me', 'method' => 'GET', 'output_callback' => $callback ) );
		$result = $me->execute( array() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'demo_rejected', $result->get_error_code() );
	}

	/**
	 * The callback never runs when dispatch returns an error.
	 */
	public function test_callback_not_called_on_dispatch_error(): void {
		$called   = false;
		$callback = static function ( $data, $input, $response ) use ( &$called ) {
			$called = true;
			return $data;
		};

		$boom   = $this->register_ability( 'cb/boom', array( 'route' => '/arat-test/v1/boom', 'method' => 'GET', 'output_callback' => $callback ) );
		$result = $boom->execute( array() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'boom', $result->get_error_code(), 'the real dispatch error passes through' );
		$this->assertFalse( $called, 'callback must not run on an error response' );
	}

	/**
	 * An output_schema is advertised and validates the reshaped output.
	 */
	public function test_output_schema_is_advertised_and_validates(): void {
		$schema   = array(
			'type'                 => 'object',
			'properties'           => array( 'handle' => array( 'type' => 'string' ) ),
			'required'             => array( 'handle' ),
			'additionalProperties' => false,
		);
		$callback = static function ( $data, $input, $response ) {
			return array( 'handle' => $data['name'] );
		};

		$me = $this->register_ability(
			'cb/me-handle',
			array(
				'route'           => '/wp/v2/users/me',
				'method'          => 'GET',
				'output_callback' => $callback,
				'output_schema'   => $schema,
			)
		);

		$this->assertSame( $schema, $me->get_output_schema(), 'the supplied schema is advertised verbatim' );

		$result = $me->execute( array() );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'handle' ), array_keys( $result ), 'reshaped output passed validation' );
	}

	/**
	 * Output validation against the supplied schema rejects a mismatched reshape.
	 */
	public function test_output_schema_rejects_mismatched_data(): void {
		$schema   = array(
			'type'                 => 'object',
			'properties'           => array( 'handle' => array( 'type' => 'string' ) ),
			'required'             => array( 'handle' ),
			'additionalProperties' => false,
		);
		$callback = static function ( $data, $input, $response ) {
			return array( 'wrong_key' => 'oops' );
		};

		$me     = $this->register_ability(
			'cb/me-bad',
			array(
				'route'           => '/wp/v2/users/me',
				'method'          => 'GET',
				'output_callback' => $callback,
				'output_schema'   => $schema,
			)
		);
		$result = $me->execute( array() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'ability_invalid_output', $result->get_error_code() );
	}

	/**
	 * With an output_callback and no output_schema, no schema is advertised and
	 * output validation is skipped (so a reshape the route schema would reject passes).
	 */
	public function test_output_callback_without_schema_skips_validation(): void {
		// This reshape (a bare-string field) would fail the route's derived item schema;
		// it must pass because no output schema is advertised.
		$callback = static function ( $data, $input, $response ) {
			return array( 'summary' => 'a plain string, not the user object' );
		};

		$me = $this->register_ability( 'cb/no-schema', array( 'route' => '/wp/v2/users/me', 'method' => 'GET', 'output_callback' => $callback ) );

		$this->assertSame( array(), $me->get_output_schema(), 'no output schema is advertised when a callback reshapes' );

		$result = $me->execute( array() );
		$this->assertSame( array( 'summary' => 'a plain string, not the user object' ), $result, 'reshape passed through unvalidated' );
	}

	/**
	 * A non-callable output_callback warns at registration and is ignored.
	 */
	public function test_non_callable_output_callback_warns_and_is_ignored(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$me = $this->register_ability( 'cb/bad', array( 'route' => '/wp/v2/users/me', 'method' => 'GET', 'output_callback' => 'definitely_not_a_callable_fn' ) );
		$this->assertNotNull( $me, 'still registers' );

		$data = $me->execute( array() );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data, 'bad callback ignored, default body returned' );
	}
}
