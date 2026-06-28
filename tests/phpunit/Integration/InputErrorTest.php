<?php
/**
 * Input/route errors surface as input errors, not permission errors (G11, #9, #14).
 *
 * Ports the missing-param check from `spikes/phase2-verify.php` and review fixes
 * #9 (a non-scalar path capture is a 400 input error, no PHP warning) and #14 (a
 * not-found route surfaces the real error and does not mask it with an open
 * input schema).
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class InputErrorTest extends AbilityTestCase {

	private const POST_ROUTE = '/wp/v2/posts/(?P<id>[\d]+)';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * G11: a missing required capture is an input error, never a permission error.
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
	 * Review #9: a non-scalar capture is a 400 route-param error with no warning.
	 *
	 * Uses check_permissions() to bypass input validation and reach the capture
	 * substitution directly (the path the array value would otherwise crash).
	 */
	public function test_non_scalar_path_param_is_invalid_route_param(): void {
		$post = $this->register_ability( 'probe/post-nonscalar', array( 'route' => self::POST_ROUTE, 'method' => 'GET' ) );
		$perm = $post->check_permissions( array( 'id' => array( 1, 2 ) ) );

		$this->assertTrue( is_wp_error( $perm ) );
		$this->assertSame( 'rest_ability_invalid_route_param', $perm->get_error_code() );
		$this->assertSame( 400, (int) $perm->get_error_data()['status'] );
	}

	/**
	 * Review #14: a not-found route surfaces the real error and keeps input open.
	 */
	public function test_not_found_route_surfaces_real_error(): void {
		$missing = $this->register_ability( 'probe/missing', array( 'route' => '/wp/v2/this-route-does-not-exist', 'method' => 'GET' ) );

		$perm = $missing->check_permissions( array() );
		$this->assertTrue( is_wp_error( $perm ) );
		$this->assertSame( 'rest_ability_route_not_found', $perm->get_error_code() );

		$input = $missing->get_input_schema();
		$this->assertTrue( $input['additionalProperties'], 'not-found input schema stays open, does not mask the error' );
	}
}
