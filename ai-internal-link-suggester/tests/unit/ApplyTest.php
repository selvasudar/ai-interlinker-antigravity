<?php
/**
 * Applier and reverter behaviour: revalidation, limits, locks, edits after apply.
 *
 * @package AILSUG
 */

use AILSUG\Index\Indexer;
use AILSUG\Settings;
use AILSUG\Suggest\Applier;
use AILSUG\Suggest\Reverter;
use AILSUG\Suggest\Store;
use AILSUG\Suggest\TextMap;

class ApplyTest extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
		Settings::update( array( 'min_link_distance' => 0 ) );
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	private function post( $content, $title = 'Source' ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
				)
			)
		);
		Indexer::index_post( $id );
		return $id;
	}

	private function suggest( $source, $target, $anchor, $status = 'accepted' ) {
		global $wpdb;
		$t   = AILSUG\Activator::tables();
		$c   = get_post( $source )->post_content;
		$occ = ( new TextMap( $c ) )->find( $anchor );
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$t['suggestions'],
			array(
				'source_post_id'  => $source,
				'target_post_id'  => $target,
				'anchor_text'     => $anchor,
				'chunk_id'        => $occ ? $occ[0]['chunk'] : 'b0p0',
				'context_snippet' => '',
				'score'           => 80,
				'status'          => $status,
				'source_hash'     => md5( $c ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_apply_creates_revision_record_and_link() {
		$target = $this->post( '<p>T</p>', 'Garden soil guide' );
		$source = $this->post( '<!-- wp:paragraph --><p>Good garden soil helps.</p><!-- /wp:paragraph -->' );
		$id     = $this->suggest( $source, $target, 'garden soil' );
		$res    = Applier::apply( array( $id ) );
		$this->assertSame( 'applied', $res['results'][0]['status'] );
		$content = get_post( $source )->post_content;
		$this->assertStringContainsString( '<a href="' . get_permalink( $target ) . '">garden soil</a>', $content );
		$this->assertSame( 'applied', Store::get( $id )['status'] );
		$rec = Reverter::records( array(), $res['batch_id'] )[0];
		$this->assertGreaterThan( 0, (int) $rec['revision_id'] );
		$this->assertStringContainsString( 'Good garden soil helps.', get_post( (int) $rec['revision_id'] )->post_content, 'The saved revision holds the pre-apply content.' );
		$this->assertTrue( AILSUG\Index\LinkGraph::links_to( $source, $target ) );
	}

	public function test_content_changed_between_suggestion_and_apply() {
		$t1     = $this->post( '<p>x</p>', 'Garden soil' );
		$t2     = $this->post( '<p>x</p>', 'Compost' );
		$source = $this->post( '<p>Intro.</p><p>Good garden soil and compost.</p>' );
		$a      = $this->suggest( $source, $t1, 'garden soil' );
		$b      = $this->suggest( $source, $t2, 'compost' );
		// Someone edits the page afterwards: one anchor survives, the other is gone.
		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => '<p>New intro text.</p><p>Good garden soil and manure.</p>',
			)
		);
		$res = Applier::apply( array( $a, $b ) );
		$by  = array_column( $res['results'], 'status', 'id' );
		$this->assertSame( 'applied', $by[ $a ] );
		$this->assertSame( 'stale', $by[ $b ] );
		$this->assertStringContainsString( 'New intro text.', get_post( $source )->post_content );
	}

	public function test_limits_duplicates_and_existing_links() {
		Settings::update( array( 'max_links_per_apply' => 1 ) );
		$t1     = $this->post( '<p>x</p>', 'One' );
		$t2     = $this->post( '<p>x</p>', 'Two' );
		$source = $this->post( '<p>alpha beta gamma delta and <a href="' . get_permalink( $t2 ) . '">old link</a>.</p>' );
		Indexer::index_post( $source );
		$a   = $this->suggest( $source, $t1, 'alpha' );
		$b   = $this->suggest( $source, $t2, 'gamma' );
		$res = Applier::apply( array( $a, $b ) );
		$by  = array_column( $res['results'], 'status', 'id' );
		$this->assertSame( 'applied', $by[ $a ] );
		$this->assertSame( 'skipped', $by[ $b ], 'Already linked to target two.' );
	}

	public function test_locked_and_page_builder_posts_are_skipped() {
		$target = $this->post( '<p>x</p>', 'Target' );
		$source = $this->post( '<p>Good garden soil.</p>' );
		$id     = $this->suggest( $source, $target, 'garden soil' );
		$other  = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $source, '_edit_lock', time() . ':' . $other );
		$this->assertSame( 'skipped', Applier::apply( array( $id ) )['results'][0]['status'] );
		delete_post_meta( $source, '_edit_lock' );
		update_post_meta( $source, '_elementor_edit_mode', 'builder' );
		$res = Applier::apply( array( $id ) );
		$this->assertSame( 'skipped', $res['results'][0]['status'] );
		$this->assertStringContainsString( 'page builder', $res['results'][0]['message'] );
	}

	public function test_no_permission_no_change() {
		$target = $this->post( '<p>x</p>', 'Target' );
		$source = $this->post( '<p>Good garden soil.</p>' );
		$id     = $this->suggest( $source, $target, 'garden soil' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 'skipped', Applier::apply( array( $id ) )['results'][0]['status'] );
		$this->assertStringNotContainsString( '<a ', get_post( $source )->post_content );
	}

	public function test_revert_after_unrelated_edit_keeps_new_edits() {
		$target = $this->post( '<p>x</p>', 'Target' );
		$source = $this->post( '<!-- wp:paragraph --><p>Good garden soil helps plants grow strong every season.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second paragraph.</p><!-- /wp:paragraph -->' );
		$res    = Applier::apply( array( $this->suggest( $source, $target, 'garden soil' ) ) );
		$edited = str_replace( 'Second paragraph.', 'Second paragraph, edited later by a human.', get_post( $source )->post_content );
		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => wp_slash( $edited ),
			)
		);

		$out = Reverter::revert( Reverter::records( array(), $res['batch_id'] ) );
		$this->assertSame( 'reverted', $out[0]['status'] );
		$content = get_post( $source )->post_content;
		$this->assertStringNotContainsString( '<a ', $content );
		$this->assertStringContainsString( 'edited later by a human', $content );
	}

	public function test_revert_stops_when_surroundings_changed() {
		$target = $this->post( '<p>x</p>', 'Target' );
		$source = $this->post( '<p>Good garden soil helps plants grow strong every season.</p>' );
		$res    = Applier::apply( array( $this->suggest( $source, $target, 'garden soil' ) ) );
		$link   = '<a href="' . get_permalink( $target ) . '">garden soil</a>';
		// A human rewrote the sentence around the link and copied the link twice.
		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => wp_slash( "<p>Rewritten: {$link} matters.</p><p>Also {$link} here.</p>" ),
			)
		);
		$before = get_post( $source )->post_content;

		$out = Reverter::revert( Reverter::records( array(), $res['batch_id'] ) );
		$this->assertSame( 'manual', $out[0]['status'] );
		$this->assertStringContainsString( 'revision.php?revision=', $out[0]['revision_url'] );
		$this->assertSame( $before, get_post( $source )->post_content, 'Newer edits are never overwritten.' );
	}

	public function test_new_tab_markup() {
		Settings::update( array( 'new_tab' => true ) );
		$target = $this->post( '<p>x</p>', 'Target' );
		$source = $this->post( '<p>Good garden soil.</p>' );
		$res    = Applier::apply( array( $this->suggest( $source, $target, 'garden soil' ) ) );
		$this->assertStringContainsString( 'target="_blank" rel="noopener">garden soil</a>', get_post( $source )->post_content );
		Reverter::revert( Reverter::records( array(), $res['batch_id'] ) );
		$this->assertSame( '<p>Good garden soil.</p>', get_post( $source )->post_content );
	}

	public function test_other_open_suggestions_survive_an_apply() {
		$t1     = $this->post( '<p>x</p>', 'One' );
		$t2     = $this->post( '<p>x</p>', 'Two' );
		$source = $this->post( '<p>Good garden soil.</p><p>Compost bins too.</p>' );
		$a      = $this->suggest( $source, $t1, 'garden soil' );
		$b      = $this->suggest( $source, $t2, 'Compost bins', 'pending' );
		Applier::apply( array( $a ) );
		$this->assertSame( 'pending', Store::get( $b )['status'] );
		$this->assertSame( 'applied', Applier::apply( array( $b ) )['results'][0]['status'] );
	}

	public function test_applying_links_on_a_target_does_not_stale_suggestions_to_it() {
		$a      = $this->post( '<p>x</p>', 'Alpha page' );
		$target = $this->post( '<p>Target mentions alpha page here.</p>', 'Target page' );
		$source = $this->post( '<p>Good target page reading.</p>', 'Source' );
		$to_t   = $this->suggest( $source, $target, 'target page', 'pending' );
		// Add a link inside the target page itself.
		Applier::apply( array( $this->suggest( $target, $a, 'alpha page' ) ) );
		$this->assertSame( 'pending', Store::get( $to_t )['status'] );
		// Real text edits on the target do make suggestions to it stale.
		wp_update_post(
			array(
				'ID'           => $target,
				'post_content' => '<p>Completely different words now.</p>',
			)
		);
		Indexer::index_post( $target );
		$this->assertSame( 'stale', Store::get( $to_t )['status'] );
	}
}
