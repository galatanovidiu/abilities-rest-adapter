<?php
/**
 * Documented known hazard (G2): a status-only comment update clobbers the IP.
 *
 * This pins an UPSTREAM controller bug, not adapter behavior:
 * `WP_REST_Comments_Controller::prepare_item_for_database()` re-derives
 * `comment_author_IP` from the transport on every update, so a status-only
 * update silently overwrites the stored IP. The reuse thesis forbids the adapter
 * from bypassing the controller, so the divergence is inherited and pinned here.
 * If core fixes the controller, this test fails — that is the signal to update
 * the known-limitation note, not the adapter.
 *
 * @package AbilitiesRestAdapter\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter\Tests\Integration;

use GalatanOvidiu\AbilitiesRestAdapter\Tests\AbilityTestCase;

/**
 * @coversDefaultClass \GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability
 */
final class CommentIpHazardTest extends AbilityTestCase {

	/**
	 * A status-only update inherits the upstream IP clobber.
	 */
	public function test_status_only_update_clobbers_comment_author_ip(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'   => $post_id,
				'comment_author_IP' => '203.0.113.7',
				'comment_approved'  => '0',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Sanity: the comment starts with the stored IP.
		$this->assertSame( '203.0.113.7', get_comment( $comment_id )->comment_author_IP );

		$update = $this->register_ability(
			'hazard/comment-status',
			array(
				'route'  => '/wp/v2/comments/(?P<id>[\d]+)',
				'method' => 'POST',
				'meta'   => array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ),
			)
		);

		$result = $update->execute(
			array(
				'id'     => $comment_id,
				'status' => 'approved',
			)
		);
		$this->assertFalse( is_wp_error( $result ), 'the status-only update itself succeeds' );

		clean_comment_cache( $comment_id );
		$stored = get_comment( $comment_id );

		// The body looked correct, but the stored IP was silently rewritten.
		$this->assertNotSame(
			'203.0.113.7',
			$stored->comment_author_IP,
			'KNOWN HAZARD: the upstream controller did not preserve the stored IP on update'
		);
	}
}
