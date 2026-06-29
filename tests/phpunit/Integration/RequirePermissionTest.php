<?php
/**
 * The opt-in `require_permission` floor.
 *
 * The guard is the only permission check the adapter runs in the ability's
 * permission phase; the route's own check runs at dispatch. These tests lock the
 * guarantees: the guard tightens a route that would otherwise allow; a passing
 * guard cannot widen what the route denies (the route denial surfaces through
 * `execute()`); a guard's `WP_Error` surfaces from `check_permissions()`; and the
 * guard fires once per `execute()` while the route's own callback fires once (at
 * dispatch). Plus the registration guard: a non-callable value warns and is ignored.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;
use WP_Error;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class RequirePermissionTest extends AbilityTestCase {

	/**
	 * How many times the `/counted` route's own permission callback ran.
	 *
	 * @var int
	 */
	public static $route_permission_calls = 0;

	public function set_up(): void {
		parent::set_up();

		self::$route_permission_calls = 0;

		// Rebuild the REST server so the custom test routes register.
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
	 * Registers the custom routes these tests wrap.
	 *
	 * @return void
	 */
	public function register_test_routes(): void {
		// A route the adapter would allow anyone to call.
		register_rest_route(
			'arat-test/v1',
			'/public-read',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => '__return_true',
			)
		);

		// A route gated on a capability, so the route itself denies a subscriber.
		register_rest_route(
			'arat-test/v1',
			'/cap-gated',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// A route whose permission callback counts its own invocations.
		register_rest_route(
			'arat-test/v1',
			'/counted',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => static function () {
					++self::$route_permission_calls;
					return true;
				},
			)
		);
	}

	/**
	 * A `false` guard denies a route that would otherwise allow (with the no-guard
	 * baseline as the regression anchor).
	 */
	public function test_guard_denies_a_route_that_would_allow(): void {
		wp_set_current_user( 0 );

		// Baseline: no guard, the public route allows.
		$open = $this->register_ability( 'rp/open', array( 'route' => '/arat-test/v1/public-read', 'method' => 'GET' ) );
		$this->assertTrue( $open->check_permissions( array() ), 'the public route allows without a guard' );

		// Same route, deny-all guard: forbidden.
		$gated = $this->register_ability(
			'rp/gated',
			array(
				'route'              => '/arat-test/v1/public-read',
				'method'             => 'GET',
				'require_permission' => static function () {
					return false;
				},
			)
		);

		$perm = $gated->check_permissions( array() );
		$this->assertTrue( is_wp_error( $perm ), 'a false guard denies the call' );
		$this->assertSame( 'rest_forbidden', $perm->get_error_code() );

		$data = $perm->get_error_data();
		$this->assertContains( (int) $data['status'], array( 401, 403 ), 'status from rest_authorization_required_code()' );
	}

	/**
	 * A passing guard defers to the route, which can still deny — the guard cannot
	 * widen access. The route's denial surfaces through execute(), not the
	 * permission phase (which only sees the guard).
	 */
	public function test_guard_passes_then_route_decides(): void {
		$ability = $this->register_ability(
			'rp/cap',
			array(
				'route'              => '/arat-test/v1/cap-gated',
				'method'             => 'GET',
				'require_permission' => static function () {
					return true;
				},
			)
		);

		// Capable user: guard passes, route allows.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $ability->check_permissions( array() ), 'guard true → permission phase passes for the capable user' );
		$this->assertSame( array(), $ability->execute( array() ), 'the route allows the capable user' );

		// Incapable user: guard still passes (so the phase allows), but the ROUTE
		// denies at dispatch, surfaced faithfully by execute().
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( $ability->check_permissions( array() ), 'a passing guard cannot pre-empt the route; the phase still passes' );

		$exec = $ability->execute( array() );
		$this->assertTrue( is_wp_error( $exec ), 'the route denies the incapable user at dispatch' );
		$this->assertSame( 'rest_forbidden', $exec->get_error_code() );
	}

	/**
	 * A `WP_Error` from the guard surfaces unchanged through `check_permissions()`.
	 */
	public function test_guard_wp_error_surfaces_unchanged(): void {
		wp_set_current_user( 0 );

		$ability = $this->register_ability(
			'rp/wperr',
			array(
				'route'              => '/arat-test/v1/public-read',
				'method'             => 'GET',
				'require_permission' => static function () {
					return new WP_Error( 'rp_custom_denied', 'Nope.', array( 'status' => 418 ) );
				},
			)
		);

		$perm = $ability->check_permissions( array() );
		$this->assertTrue( is_wp_error( $perm ) );
		$this->assertSame( 'rp_custom_denied', $perm->get_error_code(), 'the guard error code surfaces unchanged' );

		$data = $perm->get_error_data();
		$this->assertSame( 418, (int) $data['status'], 'the guard error status surfaces unchanged' );
	}

	/**
	 * The guard fires once per `execute()`; the route's own callback also fires once
	 * (only at dispatch — the permission phase does not pre-run it).
	 */
	public function test_guard_and_route_each_run_once_per_execute(): void {
		wp_set_current_user( 0 );

		$guard_calls = 0;
		$ability     = $this->register_ability(
			'rp/counted',
			array(
				'route'              => '/arat-test/v1/counted',
				'method'             => 'GET',
				'require_permission' => function () use ( &$guard_calls ) {
					++$guard_calls;
					return true;
				},
			)
		);

		$result = $ability->execute( array() );

		$this->assertFalse( is_wp_error( $result ), 'the call is allowed' );
		$this->assertSame( 1, $guard_calls, 'the guard fires once per execute()' );
		$this->assertSame( 1, self::$route_permission_calls, 'the route permission callback fires once (only at dispatch)' );
	}

	/**
	 * No `require_permission` → the permission phase passes and the route decides at
	 * dispatch.
	 */
	public function test_absent_guard_passes_and_route_decides(): void {
		wp_set_current_user( 0 );

		$ability = $this->register_ability( 'rp/none', array( 'route' => '/arat-test/v1/public-read', 'method' => 'GET' ) );

		$this->assertTrue( $ability->check_permissions( array() ), 'no guard → permission phase passes' );
		$this->assertSame( array(), $ability->execute( array() ), 'the public route allows at dispatch' );
	}

	/**
	 * A non-callable `require_permission` warns at registration and is ignored.
	 */
	public function test_non_callable_require_permission_warns_and_is_ignored(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );
		wp_set_current_user( 0 );

		$ability = $this->register_ability(
			'rp/bad',
			array(
				'route'              => '/arat-test/v1/public-read',
				'method'             => 'GET',
				'require_permission' => 'definitely_not_a_callable_fn',
			)
		);

		$this->assertNotNull( $ability, 'still registers' );
		$this->assertTrue( $ability->check_permissions( array() ), 'a non-callable guard is ignored; the phase passes' );
	}
}
