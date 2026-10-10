<?php
/**
 * REST tests for suggestions, apply and revert.
 *
 * @package AILSUG
 */

use AILSUG\Index\Indexer;
use AILSUG\Settings;
use AILSUG\Suggest\Store;
use AILSUG\Suggest\TextMap;

class SuggestionsRestTest extends WP_UnitTestCase {

	private $admin;
	private $author;
	private $source;
	private $target;
	private $sid;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
		Settings::update( array( 'min_link_distance' => 0 ) );
		$this->set_permalink_structure( '/%postname%/' );
		do_action( 'rest_api_init' );
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->admin );
		$this->target = self::factory()->post->create( array( 'post_title' => 'Garden soil' ) );
		$this->source = wp_insert_post(
			array(
				'post_title'   => 'Source',
				'post_content' => '<p>Good garden soil and compost bins.</p>',
				'post_status'  => 'publish',
				'post_author'  => $this->admin,
			)
		);
		Indexer::index_post( $this->target );
		Indexer::index_post( $this->source );
		global $wpdb;
		$t   = AILSUG\Activator::tables();
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$t['suggestions'],
			array(
				'source_post_id'  => $this->source,
				'target_post_id'  => $this->target,
				'anchor_text'     => 'garden soil',
				'chunk_id'        => 'b0p0',
				'context_snippet' => '',
				'score'           => 80,
				'status'          => 'pending',
				'source_hash'     => md5( get_post( $this->source )->post_content ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$this->sid = (int) $wpdb->insert_id;
	}

	private function req( $method, $route, array $params = array() ) {
		$r = new WP_REST_Request( $method, '/ai-internal-link-suggester/v1' . $route );
		foreach ( $params as $k => $v ) {
			$r->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $r );
	}

	public function test_author_cannot_touch_others_pages() {
		wp_set_current_user( $this->author );
		$this->assertSame( 403, $this->req( 'POST', '/suggestions/' . $this->sid, array( 'status' => 'accepted' ) )->get_status() );
		$this->assertSame( 403, $this->req( 'GET', '/posts/' . $this->source . '/links' )->get_status() );
		$res = $this->req(
			'POST',
			'/suggestions/apply',
			array(
				'ids'       => array( $this->sid ),
				'confirmed' => true,
			)
		);
		$this->assertSame( 'skipped', $res->get_data()['results'][0]['status'] );
		$this->assertStringNotContainsString( '<a ', get_post( $this->source )->post_content );
		$this->assertSame(
			403,
			$this->req(
				'POST',
				'/runs',
				array(
					'type'      => 'regenerate',
					'post_ids'  => array( $this->source ),
					'confirmed' => true,
				)
			)->get_status()
		);
	}

	public function test_apply_needs_confirmation_and_revert_works() {
		$this->assertSame(
			400,
			$this->req(
				'POST',
				'/suggestions/apply',
				array(
					'ids'       => array( $this->sid ),
					'confirmed' => false,
				)
			)->get_status()
		);
		$res = $this->req(
			'POST',
			'/suggestions/apply',
			array(
				'ids'       => array( $this->sid ),
				'confirmed' => true,
			)
		)->get_data();
		$this->assertSame( 'applied', $res['results'][0]['status'] );
		$out = $this->req( 'POST', '/applied/revert', array( 'batch_id' => $res['batch_id'] ) )->get_data();
		$this->assertSame( 'reverted', $out['results'][0]['status'] );
		$this->assertSame( '<p>Good garden soil and compost bins.</p>', get_post( $this->source )->post_content );
	}

	public function test_edit_anchor_is_revalidated() {
		$bad = $this->req( 'POST', '/suggestions/' . $this->sid, array( 'anchor_text' => 'not in the page' ) );
		$this->assertSame( 400, $bad->get_status() );
		$ok = $this->req( 'POST', '/suggestions/' . $this->sid, array( 'anchor_text' => 'Good garden soil' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'Good garden soil', Store::get( $this->sid )['anchor_text'] );
		$self = $this->req( 'POST', '/suggestions/' . $this->sid, array( 'target' => (string) $this->source ) );
		$this->assertSame( 400, $self->get_status(), 'A page cannot link to itself.' );
	}

	public function test_bulk_flag_blocks_multi_apply() {
		add_filter( 'ailsug_bulk_apply_enabled', '__return_false' );
		$res = $this->req(
			'POST',
			'/suggestions/apply',
			array(
				'ids'       => array( $this->sid, 999 ),
				'confirmed' => true,
			)
		);
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_panel_data() {
		$d = $this->req( 'GET', '/posts/' . $this->source . '/links' )->get_data();
		$this->assertTrue( $d['indexed'] );
		$this->assertCount( 1, $d['suggestions'] );
		$this->assertSame( 'garden soil', $d['suggestions'][0]['anchor_text'] );
		$this->assertTrue( $d['can_run'] );
	}
}
