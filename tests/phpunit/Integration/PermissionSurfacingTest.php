<?php
/**
 * Permission-error surfacing.
 *
 * The adapter runs only the optional `require_permission` guard in the ability's
 * permission phase; the wrapped route's own permission check runs at dispatch,
 * inside `rest_do_request()`. So a route denial — or any 404/validation error —
 * surfaces through `execute()` as the real REST error, instead of being collapsed
 * to the generic `ability_invalid_permissions`. A standalone `check_permissions()`
 * reflects the guard alone and returns `true` when there is none.
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
final class PermissionSurfacingTest extends AbilityTestCase {

	public function set_up(): void {
		parent::set_up();

		// Rebuild the REST server so the custom test route registers, then run as a subscriber.
		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', array( $this, 'register_test_routes' ) );
		rest_get_server();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this, 'register_test_routes' ) );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Registers the custom routes the permission tests dispatch against.
	 *
	 * @return void
	 */
	public function register_test_routes(): void {
		register_rest_route(
			'arat-test/v1',
			'/forbidden',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => '__return_false',
			)
		);

		// A permission callback that returns a truthy-but-not-`true` verdict (an
		// integer 1). Real dispatch allows it, so the call must succeed through execute().
		register_rest_route(
			'arat-test/v1',
			'/truthy',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => static function () {
					return 1;
				},
			)
		);

		// A permission callback that allows only when it can read a non-`args`
		// request attribute (`methods`), present only when dispatch sets the full
		// handler as attributes — which it does natively at dispatch time.
		register_rest_route(
			'arat-test/v1',
			'/attr-probe',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => static function ( $request ) {
					$attributes = $request->get_attributes();
					return isset( $attributes['methods'] );
				},
			)
		);
	}

	/**
	 * A standalone check_permissions() reflects the guard alone: with no guard it
	 * returns true, even for a route the current user cannot actually call.
	 */
	public function test_check_permissions_is_guard_only(): void {
		$settings = $this->register_ability( 'probe/settings-guard', array( 'route' => '/wp/v2/settings', 'method' => 'GET' ) );

		$this->assertTrue(
			$settings->check_permissions( array() ),
			'no guard → permission phase passes; the route decides later, at dispatch'
		);
	}

	/**
	 * execute() surfaces the route's real denial faithfully — no generic collapse,
	 * no _doing_it_wrong. GET /wp/v2/settings as a subscriber is denied by the route.
	 */
	public function test_execute_surfaces_the_real_route_denial(): void {
		$settings = $this->register_ability( 'probe/settings-exec', array( 'route' => '/wp/v2/settings', 'method' => 'GET' ) );

		$exec = $settings->execute( array() );
		$this->assertTrue( is_wp_error( $exec ), 'the denial surfaces as a WP_Error' );
		$this->assertNotSame( 'ability_invalid_permissions', $exec->get_error_code(), 'not collapsed to the generic code' );

		$data = $exec->get_error_data();
		$this->assertSame( 403, (int) $data['status'], 'the real 403 status survives' );

		// The wrapped route denies with the same code execute() surfaces.
		$direct = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$this->assertTrue( $direct->is_error() );
		$this->assertSame( $direct->as_error()->get_error_code(), $exec->get_error_code(), 'execute() matches the route denial code' );
	}

	/**
	 * A bare-false permission callback surfaces through execute() as rest_forbidden
	 * (401/403) — dispatch normalizes the bare false natively.
	 */
	public function test_bare_false_route_denial_surfaces_as_rest_forbidden(): void {
		$ability = $this->register_ability( 'probe/forbidden', array( 'route' => '/arat-test/v1/forbidden', 'method' => 'GET' ) );

		$exec = $ability->execute( array() );
		$this->assertTrue( is_wp_error( $exec ) );
		$this->assertSame( 'rest_forbidden', $exec->get_error_code() );
		$this->assertContains( (int) $exec->get_error_data()['status'], array( 401, 403 ), 'status from rest_authorization_required_code()' );
	}

	/**
	 * A truthy-but-not-`true` permission verdict is allowed through execute().
	 *
	 * Dispatch allows any verdict that is not false/null/WP_Error, and the adapter
	 * no longer pre-runs the route callback, so dispatch decides natively.
	 */
	public function test_truthy_route_permission_is_allowed(): void {
		$ability = $this->register_ability( 'probe/truthy', array( 'route' => '/arat-test/v1/truthy', 'method' => 'GET' ) );

		$exec = $ability->execute( array() );
		$this->assertFalse( is_wp_error( $exec ), 'execute() allows the truthy verdict' );
		$this->assertSame( array(), $exec, 'the route body passes through' );
	}

	/**
	 * The route's permission callback sees the full handler attributes at dispatch.
	 *
	 * The route allows only when it can read a non-`args` attribute (`methods`); that
	 * key is present because `rest_do_request()` sets the full handler as attributes.
	 */
	public function test_route_permission_sees_full_attributes_at_dispatch(): void {
		$ability = $this->register_ability( 'probe/attr-probe', array( 'route' => '/arat-test/v1/attr-probe', 'method' => 'GET' ) );

		$exec = $ability->execute( array() );
		$this->assertFalse( is_wp_error( $exec ), 'execute() is allowed because dispatch exposes full handler attributes' );
		$this->assertSame( array(), $exec );
	}
}
