<?php
/**
 * Safety-annotation derivation.
 *
 * A write without `destructive`/`idempotent` fails registration. `readonly` is derived from the
 * method — forced false for a write, true for a GET unless the developer opts out —
 * so a developer cannot mislabel a write as safe.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability
 */
final class SafetyAnnotationsTest extends AbilityTestCase {

	private const ROUTE = '/wp/v2/posts/(?P<id>[\d]+)';

	/**
	 * A write missing annotations fails registration rather than exposing unknown hints.
	 */
	public function test_unannotated_write_fails_registration_with_warning(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$trash = $this->register_ability( 'probe/trash', array( 'route' => self::ROUTE, 'method' => 'DELETE' ) );
		$this->assertNull( $trash, 'a write with unknown safety semantics is not registered' );
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
	 * Present annotation keys still fail when their values are not real booleans.
	 */
	public function test_non_boolean_write_annotations_fail_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$ability = $this->register_ability(
			'probe/write-string-hints',
			array(
				'route'  => self::ROUTE,
				'method' => 'POST',
				'meta'   => array(
					'annotations' => array(
						'destructive' => 'false',
						'idempotent'  => 'yes',
					),
				),
			)
		);

		$this->assertNull( $ability, 'truthy strings cannot become executable safety hints' );
	}

	/**
	 * The optional readonly override must also be a real boolean.
	 */
	public function test_non_boolean_readonly_annotation_fails_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$ability = $this->register_ability(
			'probe/read-string-readonly',
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'GET',
				'meta'   => array(
					'annotations' => array(
						'readonly'    => 'false',
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$this->assertNull( $ability );
	}

	/**
	 * A developer `readonly:true` on a write is overridden to false.
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
				'meta'   => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		$annotations = $counter->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['readonly'], 'an explicit readonly:false on a GET is preserved' );
	}

	/**
	 * A side-effecting GET is non-readonly and must declare the remaining hints.
	 */
	public function test_side_effecting_get_without_write_annotations_fails_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$ability = $this->register_ability(
			'probe/view-counter-unknown-risk',
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'GET',
				'meta'   => array( 'annotations' => array( 'readonly' => false ) ),
			)
		);

		$this->assertNull( $ability );
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

	/**
	 * A padded method like ' GET ' is trimmed, so the GET is read-only and emits no
	 * spurious write warning.
	 */
	public function test_padded_method_is_trimmed_to_a_read(): void {
		$read = $this->register_ability(
			'probe/padded-get',
			array( 'route' => '/wp/v2/posts', 'method' => ' GET ' )
		);

		$this->assertTrue( $read->get_meta_item( 'annotations' )['readonly'], 'a padded GET is read-only' );
	}

	/**
	 * A read-only GET is completed with `destructive:false` and `idempotent:true` — a
	 * read is both by definition — so its annotations carry no `null` "unknown" hint a
	 * consumer would treat as ask-first.
	 */
	public function test_read_only_get_is_non_destructive_and_idempotent(): void {
		$read = $this->register_ability(
			'probe/read-complete',
			array( 'route' => '/wp/v2/posts', 'method' => 'GET' )
		);

		$annotations = $read->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['readonly'], 'a GET is read-only' );
		$this->assertFalse( $annotations['destructive'], 'a read is non-destructive' );
		$this->assertTrue( $annotations['idempotent'], 'a read is idempotent' );
	}

	/**
	 * A readonly GET cannot carry contradictory destructive/idempotent hints.
	 */
	public function test_read_with_contradictory_annotations_fails_registration(): void {
		$this->setExpectedIncorrectUsage( 'wp_register_ability_from_rest_route' );

		$read = $this->register_ability(
			'probe/read-declared',
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'GET',
				'meta'   => array( 'annotations' => array( 'destructive' => true, 'idempotent' => false ) ),
			)
		);

		$this->assertNull( $read, 'contradictory read annotations are rejected' );
	}
}
