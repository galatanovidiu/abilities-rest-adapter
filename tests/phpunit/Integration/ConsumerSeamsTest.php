<?php
/**
 * Consumer extension seams: the input-schema and dispatch-wrapper filters.
 *
 * The derived input schema and dispatch are both lazy and absent from the
 * registration args, so a consumer (e.g. a multisite policy layer) cannot reach
 * them through `wp_register_ability_args`. These two filters are the seams that
 * can: `abilities_rest_adapter_input_schema` adds properties the route never
 * declared, and `abilities_rest_adapter_dispatch_wrapper` wraps dispatch and can
 * adjust the input before it reaches the route.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class ConsumerSeamsTest extends AbilityTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The input-schema filter receives the ability name and can add a property the
	 * route never declared.
	 */
	public function test_input_schema_filter_adds_a_property(): void {
		$read = $this->register_ability( 'seam/schema', array( 'route' => '/wp/v2/posts', 'method' => 'GET' ) );

		$seen_name = null;
		add_filter(
			'abilities_rest_adapter_input_schema',
			static function ( array $schema, string $name ) use ( &$seen_name ): array {
				$seen_name                       = $name;
				$schema['properties']['blog_id'] = array( 'type' => 'integer' );
				return $schema;
			},
			10,
			2
		);

		$schema = $read->get_input_schema();
		$this->assertSame( 'seam/schema', $seen_name, 'the filter is passed the ability name' );
		$this->assertArrayHasKey( 'blog_id', $schema['properties'], 'the filter injected a property the route never declared' );
	}

	/**
	 * The dispatch-wrapper filter runs the wrapper around dispatch, and the input the
	 * wrapper hands to `$proceed` is what actually dispatches.
	 */
	public function test_dispatch_wrapper_runs_and_its_input_reaches_dispatch(): void {
		$first  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$read = $this->register_ability( 'seam/dispatch', array( 'route' => '/wp/v2/posts/(?P<id>[\d]+)', 'method' => 'GET' ) );

		$ran = false;
		add_filter(
			'abilities_rest_adapter_dispatch_wrapper',
			static function ( $wrapper, string $name ) use ( &$ran, $second ) {
				return static function ( callable $proceed, $input ) use ( &$ran, $second ) {
					$ran         = true;
					$input['id'] = $second;
					return $proceed( $input );
				};
			},
			10,
			2
		);

		$result = $read->execute( array( 'id' => $first ) );

		$this->assertTrue( $ran, 'the dispatch wrapper ran' );
		$this->assertIsArray( $result );
		$this->assertSame( $second, $result['id'], 'the input the wrapper passed to $proceed is what dispatched' );
	}
}
