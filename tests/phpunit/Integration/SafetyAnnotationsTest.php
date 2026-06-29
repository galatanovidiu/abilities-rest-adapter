<?php
/**
 * Safety-annotation derivation (DSAFE) and review fix #2.
 *
 * Ports the DSAFE section of `spikes/phase2-verify.php` plus review #2: a write
 * without `destructive`/`idempotent` registers but warns and leaves them null
 * (unknown = ask-first, never a false "safe"); `readonly` is always derived from
 * the method, so a developer's `readonly:true` on a write cannot mislabel it.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class SafetyAnnotationsTest extends AbilityTestCase {

	private const ROUTE = '/wp/v2/posts/(?P<id>[\d]+)';

	/**
	 * A write missing annotations registers, warns, and leaves the hints null.
	 */
	public function test_unannotated_write_registers_with_warning(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$trash = $this->register_ability( 'probe/trash', array( 'route' => self::ROUTE, 'method' => 'DELETE' ) );
		$this->assertNotNull( $trash, 'the write still registers' );

		$annotations = $trash->get_meta_item( 'annotations' );
		$this->assertArrayHasKey( 'destructive', $annotations );
		$this->assertNull( $annotations['destructive'], 'unset destructive stays null, not a false "safe"' );
		$this->assertArrayHasKey( 'idempotent', $annotations );
		$this->assertNull( $annotations['idempotent'] );
		$this->assertFalse( $annotations['readonly'], 'a write is never readonly' );
	}

	/**
	 * A write that declares its annotations keeps them and emits no warning.
	 */
	public function test_annotated_write_keeps_declared_annotations(): void {
		$ok = $this->register_ability(
			'probe/trash-ok',
			array(
				'route'  => self::ROUTE,
				'method' => 'DELETE',
				'meta'   => array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ),
			)
		);

		$annotations = $ok->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertTrue( $annotations['idempotent'] );
		$this->assertFalse( $annotations['readonly'] );
	}

	/**
	 * Review #2: a developer `readonly:true` on a write is overridden to false.
	 */
	public function test_developer_readonly_override_on_write_is_forced_false(): void {
		$lie = $this->register_ability(
			'probe/trash-readonly-lie',
			array(
				'route'  => self::ROUTE,
				'method' => 'DELETE',
				'meta'   => array( 'annotations' => array( 'readonly' => true, 'destructive' => true, 'idempotent' => false ) ),
			)
		);

		$annotations = $lie->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'], 'write forced readonly:false despite the override' );
		$this->assertTrue( $annotations['destructive'], 'developer destructive:true is preserved' );
	}

	/**
	 * A GET the developer flags `readonly:false` (a side-effecting GET) keeps that
	 * more-conservative value instead of being forced back to true.
	 */
	public function test_developer_readonly_false_on_get_is_honored(): void {
		$counter = $this->register_ability(
			'probe/view-counter',
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'GET',
				'meta'   => array( 'annotations' => array( 'readonly' => false ) ),
			)
		);

		$annotations = $counter->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'], 'an explicit readonly:false on a GET is preserved' );
	}

	/**
	 * A plain GET (no opt-out) is still auto-marked read-only.
	 */
	public function test_plain_get_is_marked_readonly(): void {
		$read = $this->register_ability(
			'probe/read',
			array( 'route' => '/wp/v2/posts', 'method' => 'GET' )
		);

		$this->assertTrue( $read->get_meta_item( 'annotations' )['readonly'], 'a GET defaults to readonly:true' );
	}
}
