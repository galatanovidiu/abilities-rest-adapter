<?php
/**
 * Permission-error surfacing (G1) and the D2 false→rest_forbidden refinement.
 *
 * Ports the G1 section of `spikes/phase2-verify.php`: a low-privilege user hits
 * a guarded route and the real denial survives via `check_permissions()` and the
 * 7.1 `wp_ability_permission_result` filter, while bare `execute()` collapses to
 * the generic `ability_invalid_permissions` (core behavior we must not fight).
 * Adds a custom route whose permission callback returns bare `false`, to lock the
 * D2 refinement (`false`/`null` → `rest_forbidden` with a 401/403 status).
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
		// integer 1). Real dispatch allows it; the adapter must too (F2).
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
		// request attribute (`methods`), present only when the full handler is set
		// as attributes — as real dispatch does (F1).
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
	 * GET /wp/v2/settings as a subscriber: the real WP_Error surfaces faithfully (G1).
	 */
	public function test_check_permissions_carries_the_real_error(): void {
		$settings = $this->register_ability( 'probe/settings', array( 'route' => '/wp/v2/settings', 'method' => 'GET' ) );

		$seen = null;
		add_filter(
			'wp_ability_permission_result',
			static function ( $perm, $name ) use ( &$seen ) {
				if ( 'probe/settings' === $name ) {
					$seen = is_wp_error( $perm ) ? $perm->get_error_code() : var_export( $perm, true );
				}
				return $perm;
			},
			10,
			2
		);

		$perm = $settings->check_permissions( array() );
		$this->assertTrue( is_wp_error( $perm ), 'check_permissions() returns the real WP_Error' );

		$data = $perm->get_error_data();
		$this->assertSame( 403, (int) $data['status'], 'real error carries a 403 status' );

		$this->assertNotNull( $seen, 'the 7.1 filter observed a result' );
		$this->assertStringNotContainsString( 'ability_invalid_permissions', (string) $seen, 'the filter saw the real code, not the generic one' );

		// The wrapped route denies with the same code the adapter surfaces.
		$direct = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$this->assertTrue( $direct->is_error() );
		$this->assertSame( $perm->get_error_code(), $direct->as_error()->get_error_code(), 'adapter matches the route denial code' );
	}

	/**
	 * Bare execute() collapses the denial to the generic error (core behavior).
	 */
	public function test_bare_execute_genericizes_the_denial(): void {
		// The generic-collapse path fires _doing_it_wrong inside core's execute().
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$settings = $this->register_ability( 'probe/settings-exec', array( 'route' => '/wp/v2/settings', 'method' => 'GET' ) );
		$exec     = $settings->execute( array() );

		$this->assertTrue( is_wp_error( $exec ) );
		$this->assertSame( 'ability_invalid_permissions', $exec->get_error_code() );
	}

	/**
	 * D2 refinement: a bare-false permission callback becomes rest_forbidden (401/403).
	 */
	public function test_bare_false_permission_becomes_rest_forbidden(): void {
		$ability = $this->register_ability( 'probe/forbidden', array( 'route' => '/arat-test/v1/forbidden', 'method' => 'GET' ) );
		$perm    = $ability->check_permissions( array() );

		$this->assertTrue( is_wp_error( $perm ) );
		$this->assertSame( 'rest_forbidden', $perm->get_error_code() );

		$data = $perm->get_error_data();
		$this->assertContains( (int) $data['status'], array( 401, 403 ), 'status from rest_authorization_required_code()' );
	}

	/**
	 * F2: a truthy-but-not-`true` permission verdict is allowed through execute().
	 *
	 * Dispatch allows any verdict that is not false/null/WP_Error, but
	 * `WP_Ability::execute()` denies on `true !== $has_permissions`. The adapter
	 * normalizes the verdict to literal `true` so the call is not wrongly denied.
	 */
	public function test_truthy_permission_is_allowed_through_execute(): void {
		$ability = $this->register_ability( 'probe/truthy', array( 'route' => '/arat-test/v1/truthy', 'method' => 'GET' ) );

		$this->assertTrue( $ability->check_permissions( array() ), 'truthy verdict normalizes to literal true' );

		$exec = $ability->execute( array() );
		$this->assertFalse( is_wp_error( $exec ), 'execute() allows the call rather than collapsing to ability_invalid_permissions' );
		$this->assertSame( array(), $exec, 'the route body passes through' );
	}

	/**
	 * F1: the permission callback sees the full handler attributes, as over HTTP.
	 *
	 * The route's callback allows only when it can read a non-`args` attribute
	 * (`methods`); that key is present only when the adapter sets the full handler
	 * as request attributes, mirroring dispatch's `set_attributes( $handler )`.
	 */
	public function test_permission_callback_sees_full_handler_attributes(): void {
		$ability = $this->register_ability( 'probe/attr-probe', array( 'route' => '/arat-test/v1/attr-probe', 'method' => 'GET' ) );

		$this->assertTrue( $ability->check_permissions( array() ), 'full handler attributes are exposed to the permission callback' );

		$exec = $ability->execute( array() );
		$this->assertFalse( is_wp_error( $exec ), 'execute() is allowed because the synthetic check matches dispatch' );
	}
}
