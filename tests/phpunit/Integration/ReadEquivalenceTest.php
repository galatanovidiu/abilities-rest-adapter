<?php
/**
 * Behavioral equivalence of read abilities vs `rest_do_request()`.
 *
 * Verifies that read abilities return the same body as `rest_do_request()`: a
 * singleton GET, a collection envelope, a path-capture route, a collection
 * without `per_page`, and that empty nested `properties` serialize as `{}`.
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
final class ReadEquivalenceTest extends AbilityTestCase {

	/**
	 * A published post to fetch by id and to populate the collection.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Probe Post',
				'post_content' => 'Body.',
			)
		);
	}

	/**
	 * GET /wp/v2/users/me: execute() == rest_do_request() body; readonly; closed input.
	 */
	public function test_singleton_get_equals_rest_do_request(): void {
		$me     = $this->register_ability( 'probe/me', array( 'route' => '/wp/v2/users/me', 'method' => 'GET' ) );
		$actual = $me->execute( array() );
		$direct = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) )->get_data();

		$this->assertFalse( is_wp_error( $actual ), 'execute() did not error' );
		$this->assertEquals( $direct, $actual, 'execute() equals rest_do_request() body' );

		$annotations = $me->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'], 'GET auto-readonly = true' );

		$input = $me->get_input_schema();
		$this->assertSame( 'object', $input['type'], 'input schema is an object' );
		$this->assertFalse( $input['additionalProperties'], 'input schema is closed' );

		$output = $me->get_output_schema();
		$this->assertSame( 'object', $output['type'], 'output schema is an object' );
		$this->assertArrayHasKey( 'properties', $output, 'output schema has properties' );
	}

	/**
	 * GET /wp/v2/posts: collection wraps as {items,total,total_pages} from headers (D4a).
	 */
	public function test_collection_wraps_with_envelope(): void {
		$posts = $this->register_ability( 'probe/posts', array( 'route' => '/wp/v2/posts', 'method' => 'GET' ) );

		$envelope = $posts->execute( array() );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$headers  = $response->get_headers();

		$this->assertIsArray( $envelope );
		$this->assertArrayHasKey( 'items', $envelope );
		$this->assertArrayHasKey( 'total', $envelope );
		$this->assertArrayHasKey( 'total_pages', $envelope );
		$this->assertEquals( $response->get_data(), $envelope['items'], 'items equal the route body' );
		$this->assertSame( (int) $headers['X-WP-Total'], (int) $envelope['total'], 'total equals X-WP-Total header' );

		$schema = $posts->get_output_schema();
		$this->assertArrayHasKey( 'items', $schema['properties'] );
		$this->assertArrayHasKey( 'total', $schema['properties'] );
		$this->assertArrayHasKey( 'total_pages', $schema['properties'] );
	}

	/**
	 * GET /wp/v2/posts/(?P<id>[\d]+): path-capture round-trip; id required + integer (D5).
	 */
	public function test_path_capture_round_trips(): void {
		$post   = $this->register_ability( 'probe/post', array( 'route' => '/wp/v2/posts/(?P<id>[\d]+)', 'method' => 'GET' ) );
		$actual = $post->execute( array( 'id' => $this->post_id ) );
		$direct = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->post_id ) )->get_data();

		$this->assertFalse( is_wp_error( $actual ), 'execute([id]) did not error' );
		$this->assertEquals( $direct, $actual, 'execute([id]) equals rest_do_request() body' );

		$input = $post->get_input_schema();
		$this->assertContains( 'id', $input['required'], 'id is a required input' );
		$this->assertSame( 'integer', $input['properties']['id']['type'], 'id is typed integer from [\\d]+' );
	}

	/**
	 * GET /wp/v2/themes is a collection even though it has no `per_page`.
	 */
	public function test_themes_collection_without_per_page(): void {
		$themes   = $this->register_ability( 'probe/themes', array( 'route' => '/wp/v2/themes', 'method' => 'GET' ) );
		$envelope = $themes->execute( array() );
		$direct   = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/themes' ) );

		$this->assertIsArray( $envelope );
		$this->assertArrayHasKey( 'items', $envelope );
		$this->assertArrayHasKey( 'total', $envelope );
		$this->assertArrayHasKey( 'total_pages', $envelope );
		$this->assertEquals( $direct->get_data(), $envelope['items'], 'themes items equal the route body' );

		$schema = $themes->get_output_schema();
		$this->assertSame( 'array', $schema['properties']['items']['type'], 'themes schema advertises an items array' );
	}

	/**
	 * Nested empty `properties` serialize as `{}`, never `[]`.
	 */
	public function test_block_types_schema_has_no_empty_array_properties(): void {
		$block_types = $this->register_ability( 'probe/block-types', array( 'route' => '/wp/v2/block-types', 'method' => 'GET' ) );
		$encoded     = wp_json_encode( $block_types->get_output_schema() );

		$this->assertStringNotContainsString( '"properties":[]', (string) $encoded );
	}

	/**
	 * Nested edit-only fields are not advertised by the default view schema.
	 */
	public function test_view_output_schema_filters_nested_context_fields(): void {
		$post   = $this->register_ability( 'probe/post-view-schema', array( 'route' => '/wp/v2/posts/(?P<id>[\d]+)', 'method' => 'GET' ) );
		$schema = $post->get_output_schema();
		$result = $post->execute( array( 'id' => $this->post_id ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( 'rendered', 'protected' ), array_keys( $result['content'] ), 'the route returns only view-context content fields' );
		$this->assertSame(
			array( 'rendered', 'protected' ),
			array_keys( $schema['properties']['content']['properties'] ),
			'the derived schema advertises the same nested view-context fields'
		);
	}
}
