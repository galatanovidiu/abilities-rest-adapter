<?php
/**
 * Shared base for the adapter's integration tests.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests;

use WP_Ability;
use WP_Abilities_Registry;
use WP_UnitTestCase;

/**
 * Registers REST-backed ability fixtures and cleans them up.
 *
 * The abilities registry is a process-wide singleton with no reset between
 * tests, so every fixture is tracked and unregistered in {@see tear_down()};
 * tests use unique names to avoid collisions. `wp_register_ability_from_rest_route()`
 * only runs inside the `wp_abilities_api_init` action (guarded by `doing_action`),
 * so {@see register_ability()} registers inside a one-shot callback and fires it.
 * There is no default category, so the suite registers a `test` category once.
 */
abstract class AbilityTestCase extends WP_UnitTestCase {

	/**
	 * Names of abilities registered during the current test.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * Registers a REST-backed ability fixture and tracks it for teardown.
	 *
	 * `label`, `description`, and `category` default to test values so callers
	 * only specify what the test cares about; any of them can be overridden.
	 *
	 * @param string               $name Ability name (`namespace/slug`).
	 * @param array<string, mixed> $args Registration args (at least `route` + `method`).
	 * @return WP_Ability|null The registered ability, or null on failure.
	 */
	protected function register_ability( string $name, array $args ): ?WP_Ability {
		$this->ensure_test_category();

		$args = array_merge(
			array(
				'label'       => 'Test Ability',
				'description' => 'A test ability.',
				'category'    => 'test',
			),
			$args
		);

		$callback = static function () use ( $name, $args ): void {
			wp_register_ability_from_rest_route( $name, $args );
		};
		add_action( 'wp_abilities_api_init', $callback );
		do_action( 'wp_abilities_api_init' );
		remove_action( 'wp_abilities_api_init', $callback );

		$this->registered[] = $name;

		return wp_get_ability( $name );
	}

	/**
	 * Registers the `test` ability category once (the singleton persists it).
	 *
	 * @return void
	 */
	private function ensure_test_category(): void {
		WP_Abilities_Registry::get_instance();
		if ( wp_has_ability_category( 'test' ) ) {
			return;
		}

		$callback = static function (): void {
			wp_register_ability_category(
				'test',
				array(
					'label'       => 'Test',
					'description' => 'Abilities registered by the test suite.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $callback );
		do_action( 'wp_abilities_api_categories_init' );
		remove_action( 'wp_abilities_api_categories_init', $callback );
	}

	/**
	 * Unregisters every fixture this test created.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->registered as $name ) {
			if ( null !== wp_get_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$this->registered = array();

		parent::tear_down();
	}
}
