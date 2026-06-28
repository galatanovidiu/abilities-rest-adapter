<?php
/**
 * A write ability returns the route body unchanged (D4b).
 *
 * The probes do not exercise a real POST (two creates differ by id/date, so an
 * exact body match is impossible). This asserts the honest D4b contract: the
 * created resource is returned as the body (not wrapped in a collection
 * envelope) and carries the same field set the route returns directly.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;
use WP_REST_Request;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class WriteEquivalenceTest extends AbilityTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * POST /wp/v2/posts returns the created post as the body, not an envelope (D4b).
	 */
	public function test_write_returns_body_not_envelope(): void {
		$create = $this->register_ability(
			'probe/create-post',
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'POST',
				'meta'   => array( 'annotations' => array( 'destructive' => false, 'idempotent' => false ) ),
			)
		);

		// The derived schema types `title`/`content` as objects (the route nulls
		// their validate_callback via arg_options, which the adapter strips), so the
		// schema-honest form is the canonical `{ raw: … }`, not a bare string.
		$result = $create->execute(
			array(
				'title'   => array( 'raw' => 'Adapter Write' ),
				'status'  => 'draft',
				'content' => array( 'raw' => 'Created through the adapter.' ),
			)
		);

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() . ':' . $result->get_error_message() : '' );
		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'items', $result, 'a write is the body, never a collection envelope' );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 'draft', $result['status'] );

		// The created post really exists with that title.
		$post = get_post( $result['id'] );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'Adapter Write', $post->post_title );
	}

	/**
	 * The adapter's write body carries the same fields as a direct route create.
	 */
	public function test_write_body_field_set_matches_the_route(): void {
		$create = $this->register_ability(
			'probe/create-post-shape',
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'POST',
				'meta'   => array( 'annotations' => array( 'destructive' => false, 'idempotent' => false ) ),
			)
		);

		$via_adapter = $create->execute(
			array(
				'title'  => array( 'raw' => 'Shape A' ),
				'status' => 'draft',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params(
			array(
				'title'  => array( 'raw' => 'Shape B' ),
				'status' => 'draft',
			)
		);
		$direct = rest_do_request( $request )->get_data();

		$this->assertIsArray( $via_adapter );
		$this->assertIsArray( $direct );
		$this->assertSame( array_keys( $direct ), array_keys( $via_adapter ), 'same controller, same field set' );
	}
}
