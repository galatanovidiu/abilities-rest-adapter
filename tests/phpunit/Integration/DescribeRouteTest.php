<?php
/**
 * The `describe()` introspection snapshot (G10, the CLI command's data source).
 *
 * `describe()` constructs no registered ability and dispatches nothing, so these
 * tests build no fixtures and need no current user — they assert the snapshot the
 * `wp ability describe-route` command renders.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class DescribeRouteTest extends WP_UnitTestCase {

	/**
	 * A single-item GET: found, read-only, no captures, closed input, real output.
	 */
	public function test_describes_single_item_get(): void {
		$info = WP_REST_Ability::describe( '/wp/v2/users/me', 'GET' );

		$this->assertTrue( $info['found'], 'route resolves' );
		$this->assertNull( $info['error'] );
		$this->assertTrue( $info['readonly'], 'GET is read-only' );
		$this->assertFalse( $info['is_collection'], 'singleton is not a collection' );
		$this->assertSame( array(), $info['captures'], 'no path captures' );
		$this->assertSame( 'object', $info['input_schema']['type'] );
		$this->assertFalse( $info['input_schema']['additionalProperties'], 'input is closed' );
		$this->assertArrayHasKey( 'properties', $info['output_schema'], 'output schema derived' );
	}

	/**
	 * A collection GET: is_collection true; output advertises the envelope.
	 */
	public function test_describes_collection_envelope(): void {
		$info = WP_REST_Ability::describe( '/wp/v2/posts', 'GET' );

		$this->assertTrue( $info['found'] );
		$this->assertTrue( $info['is_collection'], 'posts is a collection' );
		$this->assertArrayHasKey( 'items', $info['output_schema']['properties'] );
		$this->assertArrayHasKey( 'total', $info['output_schema']['properties'] );
		$this->assertArrayHasKey( 'total_pages', $info['output_schema']['properties'] );
	}

	/**
	 * A numeric path capture is reported as an integer and is a required input.
	 */
	public function test_describes_numeric_capture(): void {
		$info = WP_REST_Ability::describe( '/wp/v2/posts/(?P<id>[\d]+)', 'GET' );

		$this->assertTrue( $info['found'] );
		$this->assertSame( array( 'id' => 'integer' ), $info['captures'], 'id capture typed integer' );
		$this->assertContains( 'id', $info['input_schema']['required'], 'id is required input' );
	}

	/**
	 * A non-numeric path capture is reported as a string (guards is_numeric_subpattern()).
	 */
	public function test_describes_string_capture(): void {
		$info = WP_REST_Ability::describe( '/wp/v2/types/(?P<type>[\w-]+)', 'GET' );

		$this->assertTrue( $info['found'] );
		$this->assertSame( array( 'type' => 'string' ), $info['captures'], 'a [\\w-]+ capture is a string, not an integer' );
		$this->assertContains( 'type', $info['input_schema']['required'], 'type is required input' );
	}

	/**
	 * A write reports readonly = false (the command then flags the missing annotations).
	 */
	public function test_describes_write_as_not_readonly(): void {
		$info = WP_REST_Ability::describe( '/wp/v2/posts/(?P<id>[\d]+)', 'DELETE' );

		$this->assertTrue( $info['found'], 'DELETE on a single post resolves' );
		$this->assertFalse( $info['readonly'], 'a write is not read-only' );
		$this->assertSame( array( 'id' => 'integer' ), $info['captures'] );
	}

	/**
	 * An unregistered route resolves to found = false with a faithful error message.
	 */
	public function test_reports_not_found(): void {
		$info = WP_REST_Ability::describe( '/wp/v2/not-a-real-route', 'GET' );

		$this->assertFalse( $info['found'], 'unknown route is not found' );
		$this->assertIsString( $info['error'] );
		$this->assertNotEmpty( $info['error'], 'a not-found message is surfaced' );
	}
}
