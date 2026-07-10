<?php
/**
 * Registration-time validation: category enforcement and malformed args.
 *
	 * Covers category enforcement, malformed adapter args, and schema overrides.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class RegistrationTest extends AbilityTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * There is no default category: an unregistered category fails registration.
	 */
	public function test_unregistered_category_fails_registration(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		$ability = $this->register_ability(
			'reg/bad-cat',
			array(
				'route'    => '/wp/v2/users/me',
				'method'   => 'GET',
				'category' => 'no-such-category',
			)
		);

		$this->assertNull( $ability, 'an unregistered category is rejected, not silently defaulted' );
	}

	/**
	 * A non-array schema arg warns at registration and falls back to the derived schema.
	 */
	public function test_non_array_schema_arg_warns_and_is_ignored(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$me = $this->register_ability(
			'reg/bad-schema',
			array(
				'route'         => '/wp/v2/users/me',
				'method'        => 'GET',
				'output_schema' => 'not-an-array',
			)
		);

		$this->assertNotNull( $me, 'still registers' );
		$this->assertArrayHasKey( 'properties', $me->get_output_schema(), 'fell back to the derived schema' );
	}

	/**
	 * The adapter accepts exactly the methods documented by its public contract.
	 */
	public function test_unsupported_http_method_fails_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$ability = $this->register_ability(
			'reg/bad-method',
			array(
				'route'  => '/wp/v2/users/me',
				'method' => 'OPTIONS',
				'meta'   => array(
					'annotations' => array(
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$this->assertNull( $ability );
	}

	/**
	 * A wrong-typed meta value must not be silently replaced with adapter defaults.
	 */
	public function test_non_array_meta_fails_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$ability = $this->register_ability(
			'reg/bad-meta',
			array(
				'route'  => '/wp/v2/users/me',
				'method' => 'GET',
				'meta'   => 'not-an-array',
			)
		);

		$this->assertNull( $ability );
	}

	/**
	 * A wrong-typed annotations value must not become an apparently safe read.
	 */
	public function test_non_array_annotations_fail_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$ability = $this->register_ability(
			'reg/bad-annotations',
			array(
				'route'  => '/wp/v2/users/me',
				'method' => 'GET',
				'meta'   => array( 'annotations' => 'not-an-array' ),
			)
		);

		$this->assertNull( $ability );
	}

	/**
	 * Direct build_args consumers retain the public array return contract.
	 */
	public function test_invalid_build_args_returns_an_unregistrable_array(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$args = Rest_Route_Ability::build_args(
			'reg/invalid-helper-args',
			array(
				'route'  => '/wp/v2/users/me',
				'method' => 'OPTIONS',
			)
		);

		$this->assertSame( array(), $args );
	}
}
