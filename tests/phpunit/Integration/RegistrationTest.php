<?php
/**
 * Registration-time validation: category enforcement and malformed args.
 *
 * Covers the removed default category (an unregistered category must fail) and
 * the non-array schema-arg guard.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
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
		// The base helper's wp_get_ability() lookup of the now-unregistered ability
		// also trips core's "not found" notice.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

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
}
