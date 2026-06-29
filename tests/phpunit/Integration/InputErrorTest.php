<?php
/**
 * Input/route errors surface faithfully through execute(), not as permission errors.
 *
 * The route's permission check and request building run at dispatch, so a missing or
 * malformed path capture, or a not-found route, surfaces as the real `WP_Error`
 * through `execute()` — not collapsed to a generic permission error. Cases that need
 * a non-scalar or pattern-misfitting value to reach capture substitution use a loose
 * `input_schema` so the value passes the ability's own validation first.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class InputErrorTest extends AbilityTestCase {

	private const POST_ROUTE = '/wp/v2/posts/(?P<id>[\d]+)';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A missing required capture is an input error, never a permission error.
	 */
	public function test_missing_path_param_is_an_input_error(): void {
		$post  = $this->register_ability( 'probe/post-missing', array( 'route' => self::POST_ROUTE, 'method' => 'GET' ) );
		$error = $post->execute( array() );

		$this->assertTrue( is_wp_error( $error ) );
		$this->assertContains(
			$error->get_error_code(),
			array( 'ability_invalid_input', 'rest_ability_missing_route_param' ),
			'missing capture is an input error, not a permission error'
		);
	}

	/**
	 * A non-scalar capture is a 400 route-param error with no PHP warning.
	 *
	 * A loose `input_schema` lets the array value pass the ability's own validation so
	 * it reaches capture substitution at dispatch (the path the array would crash).
	 */
	public function test_non_scalar_path_param_is_invalid_route_param(): void {
		$post = $this->register_ability(
			'probe/post-nonscalar',
			array(
				'route'        => self::POST_ROUTE,
				'method'       => 'GET',
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => true,
				),
			)
		);

		$error = $post->execute( array( 'id' => array( 1, 2 ) ) );

		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'rest_ability_invalid_route_param', $error->get_error_code() );
		$this->assertSame( 400, (int) $error->get_error_data()['status'] );
	}

	/**
	 * A scalar capture that does not fit the route pattern is a route-not-found
	 * verdict, not an authz verdict — over HTTP the path would 404 before the
	 * permission callback runs, so execute() surfaces that faithfully.
	 *
	 * A loose `input_schema` (id as a string) lets the misfitting value reach capture
	 * substitution, where the numeric pattern rejects it.
	 */
	public function test_scalar_capture_that_misfits_the_pattern_is_route_not_found(): void {
		$post = $this->register_ability(
			'probe/post-misfit',
			array(
				'route'        => self::POST_ROUTE,
				'method'       => 'GET',
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'string' ) ),
					'additionalProperties' => true,
				),
			)
		);

		$error = $post->execute( array( 'id' => '12/3' ) );

		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'rest_no_route', $error->get_error_code(), 'a misfitting capture would not route over HTTP' );
		$this->assertSame( 404, (int) $error->get_error_data()['status'] );
	}

	/**
	 * A not-found route surfaces the real error through execute() and keeps input open.
	 */
	public function test_not_found_route_surfaces_real_error(): void {
		$missing = $this->register_ability( 'probe/missing', array( 'route' => '/wp/v2/this-route-does-not-exist', 'method' => 'GET' ) );

		$error = $missing->execute( array() );
		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'rest_ability_route_not_found', $error->get_error_code() );

		$input = $missing->get_input_schema();
		$this->assertTrue( $input['additionalProperties'], 'not-found input schema stays open, does not mask the error' );
	}
}
