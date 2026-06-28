<?php
/**
 * Smoke test: proves the test harness boots WordPress and loads the plugin.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use WP_UnitTestCase;
use GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability;

/**
 * @coversNothing
 */
final class SmokeTest extends WP_UnitTestCase {

	public function test_plugin_public_api_is_loaded(): void {
		$this->assertTrue( function_exists( 'wp_register_ability_from_rest_route' ), 'public registrar function is defined' );
		$this->assertTrue( class_exists( WP_REST_Ability::class ), 'engine class is autoloaded' );
	}

	public function test_abilities_api_is_available(): void {
		$this->assertTrue( function_exists( 'wp_register_ability' ), 'Abilities API core function is present' );
	}
}
