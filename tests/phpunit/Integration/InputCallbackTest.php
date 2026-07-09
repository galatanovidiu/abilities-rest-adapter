<?php
/**
 * The input_callback request-shaping seam and the input_schema arg.
 *
 * `input_callback` transforms the request params before dispatch — set `_fields`,
 * inject fixed params, reshape, or reject — and `input_schema` replaces the
 * derived input schema (e.g. to widen a strict field).
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class InputCallbackTest extends AbilityTestCase {

	/**
	 * A published post to fetch.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create_many( 4, array( 'post_status' => 'publish' ) );
	}

	/**
	 * The callback can set `_fields` to trim the actual response.
	 */
	public function test_input_callback_sets_fields_to_trim_response(): void {
		$callback = static function ( array $params ): array {
			$params['_fields'] = 'id,title';
			return $params;
		};

		$post   = $this->register_ability(
			'in/post-fields',
			array(
				'route'          => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'         => 'GET',
				'input_callback' => $callback,
			)
		);
		$result = $post->execute( array( 'id' => $this->post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'title' ), array_keys( $result ), '_fields set by the callback trimmed the response' );
	}

	/**
	 * The callback can inject a fixed param that the caller never supplied.
	 */
	public function test_input_callback_injects_fixed_param(): void {
		$callback = static function ( array $params ): array {
			$params['per_page'] = 1;
			return $params;
		};

		$posts    = $this->register_ability(
			'in/posts-one',
			array(
				'route'          => '/wp/v2/posts',
				'method'         => 'GET',
				'input_callback' => $callback,
			)
		);
		$envelope = $posts->execute( array() );

		$this->assertIsArray( $envelope );
		$this->assertCount( 1, $envelope['items'], 'injected per_page=1 limited the collection' );
	}

	/**
	 * The callback may reject the call by returning a WP_Error, which surfaces
	 * faithfully through execute() (the callback runs at dispatch).
	 */
	public function test_input_callback_can_reject(): void {
		$callback = static function ( array $params ) {
			return new \WP_Error( 'in_rejected', 'Rejected by input callback.', array( 'status' => 400 ) );
		};

		$post   = $this->register_ability(
			'in/reject',
			array(
				'route'          => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'         => 'GET',
				'input_callback' => $callback,
			)
		);
		$result = $post->execute( array( 'id' => $this->post_id ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'in_rejected', $result->get_error_code() );
	}

	/**
	 * A callback returning something other than an array or WP_Error fails closed:
	 * execute() surfaces a 500 rest_invalid_input_callback error instead of dispatching.
	 * Covers a scalar int, a string, and null — each is neither an array nor a WP_Error.
	 */
	public function test_non_array_non_error_callback_return_fails_closed(): void {
		foreach ( array(
			'scalar-int' => 5,
			'string'     => 'x',
			'null'       => null,
		) as $label => $return ) {
			$callback = static function () use ( $return ) {
				return $return;
			};

			$post   = $this->register_ability(
				'in/bad-return-' . $label,
				array(
					'route'          => '/wp/v2/posts/(?P<id>[\d]+)',
					'method'         => 'GET',
					'input_callback' => $callback,
				)
			);
			$result = $post->execute( array( 'id' => $this->post_id ) );

			$this->assertTrue( is_wp_error( $result ), "a {$label} return fails closed" );
			$this->assertSame( 'rest_invalid_input_callback', $result->get_error_code(), "a {$label} return uses rest_invalid_input_callback" );
			$this->assertSame( 500, $result->get_error_data()['status'], "a {$label} return is a 500" );
		}
	}

	/**
	 * A non-callable input_callback warns at registration and is ignored.
	 */
	public function test_non_callable_input_callback_warns_and_is_ignored(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$post = $this->register_ability(
			'in/bad',
			array(
				'route'          => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'         => 'GET',
				'input_callback' => 'definitely_not_a_callable_fn',
			)
		);
		$this->assertNotNull( $post, 'still registers' );

		$result = $post->execute( array( 'id' => $this->post_id ) );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result, 'bad callback ignored, normal dispatch happened' );
	}

	/**
	 * An input_schema override widens a strict field so a bare-string title is accepted.
	 */
	public function test_input_schema_override_accepts_string_title(): void {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'title'  => array( 'type' => 'string' ),
				'status' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$create = $this->register_ability(
			'in/create-str',
			array(
				'route'        => '/wp/v2/posts',
				'method'       => 'POST',
				'input_schema' => $schema,
				'meta'         => array(
					'annotations' => array(
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		$this->assertSame( $schema, $create->get_input_schema(), 'the supplied input schema is advertised verbatim' );

		$result = $create->execute(
			array(
				'title'  => 'Plain String',
				'status' => 'draft',
			)
		);

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() . ':' . $result->get_error_message() : '' );
		$this->assertSame( 'Plain String', get_post( $result['id'] )->post_title );
	}

	/**
	 * `_fields` injected by the callback trims the response on a WRITE (body routing).
	 */
	public function test_input_callback_sets_fields_on_a_write(): void {
		$callback = static function ( array $params ): array {
			$params['_fields'] = 'id,title';
			return $params;
		};

		$create = $this->register_ability(
			'in/create-fields',
			array(
				'route'          => '/wp/v2/posts',
				'method'         => 'POST',
				'input_callback' => $callback,
				'input_schema'   => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'meta'           => array(
					'annotations' => array(
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		$result = $create->execute(
			array(
				'title'  => 'Trimmed Write',
				'status' => 'draft',
			)
		);

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() . ':' . $result->get_error_message() : '' );
		$this->assertSame( array( 'id', 'title' ), array_keys( $result ), '_fields routed to the write body trimmed the response' );
		$this->assertSame( 'Trimmed Write', get_post( $result['id'] )->post_title, 'the post was still created with the title' );
	}

	/**
	 * An input_schema override that omits a path capture still fails closed.
	 *
	 * The override drops the capture-required backstop, so validation passes, but
	 * capture substitution still rejects a missing id at dispatch. The faithful error
	 * surfaces through execute().
	 */
	public function test_input_schema_override_omitting_capture_fails_closed(): void {
		$override = array(
			'type'                 => 'object',
			'properties'           => array( 'id' => array( 'type' => 'string' ) ),
			'additionalProperties' => false,
		);

		$post = $this->register_ability(
			'in/loose-capture',
			array(
				'route'        => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'       => 'GET',
				'input_schema' => $override,
			)
		);

		$result = $post->execute( array() );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rest_ability_missing_route_param', $result->get_error_code() );
	}
}
