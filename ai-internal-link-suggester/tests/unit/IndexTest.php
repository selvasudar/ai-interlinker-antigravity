<?php
/**
 * Collector, link graph, eligibility and indexer tests.
 *
 * @package AILSUG
 */

use AILSUG\Index\Collector;
use AILSUG\Index\Eligibility;
use AILSUG\Index\Indexer;
use AILSUG\Index\LinkGraph;
use AILSUG\Settings;

class IndexTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
		Collector::flush_cache();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function test_parse_extracts_text_headings_links() {
		$html   = '<!-- wp:heading --><h2>Intro &amp; basics</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Read <a href="/foo/">the <em>foo</em> guide</a> now.</p><!-- /wp:paragraph -->'
			. '<p>Ünïcödé text here</p>';
		$parsed = Collector::parse( $html );
		$this->assertSame( array( 'Intro & basics' ), $parsed['headings'] );
		$this->assertSame( '/foo/', $parsed['links'][0]['href'] );
		$this->assertSame( 'the foo guide', $parsed['links'][0]['text'] );
		$this->assertStringContainsString( 'Ünïcödé text here', $parsed['text'] );
		$this->assertStringNotContainsString( 'wp:paragraph', $parsed['text'] );
	}

	public function test_word_count_is_unicode_aware() {
		$this->assertSame( 4, Collector::word_count( "Héllo wörld, it's fine" ) );
		$this->assertSame( 2, Collector::word_count( 'مرحبا بالعالم' ) );
	}

	public function test_resolve_internal_links() {
		$target = self::factory()->post->create( array( 'post_name' => 'target-page' ) );
		$this->assertSame( $target, Collector::resolve_internal( home_url( '/target-page/' ) ) );
		$this->assertSame( $target, Collector::resolve_internal( '/target-page/#section' ) );
		$this->assertSame( 0, Collector::resolve_internal( 'https://example.org/target-page/' ) );
		$this->assertSame( 0, Collector::resolve_internal( 'mailto:a@b.c' ) );
		$this->assertSame( 0, Collector::resolve_internal( '#top' ) );
	}

	public function test_link_graph_counts_and_orphans() {
		$a = self::factory()->post->create( array( 'post_name' => 'a-page' ) );
		$b = self::factory()->post->create(
			array(
				'post_name'    => 'b-page',
				'post_content' => '<p><a href="/a-page/">A</a> and <a href="/a-page/">again</a></p>',
			)
		);
		$c = self::factory()->post->create(
			array(
				'post_name'    => 'c-page',
				'post_content' => '<p><a href="/a-page/">A</a> <a href="/b-page/">B</a></p>',
			)
		);
		foreach ( array( $a, $b, $c ) as $id ) {
			Indexer::index_post( $id );
		}
		$this->assertSame( 2, (int) Indexer::get_row( $a )['inbound_count'] );
		$this->assertSame( 1, (int) Indexer::get_row( $b )['inbound_count'] );
		$this->assertSame( 0, (int) Indexer::get_row( $c )['inbound_count'] );
		$this->assertSame( 2, (int) Indexer::get_row( $c )['outbound_count'] );
		$this->assertSame( 1, LinkGraph::orphan_count() );
		$this->assertTrue( LinkGraph::links_to( $c, $b ) );

		// Unpublishing a source removes its links from the graph.
		wp_update_post(
			array(
				'ID'          => $c,
				'post_status' => 'draft',
			)
		);
		Indexer::index_post( $c );
		$this->assertNull( Indexer::get_row( $c ) );
		$this->assertSame( 1, (int) Indexer::get_row( $a )['inbound_count'] );
		$this->assertSame( 0, (int) Indexer::get_row( $b )['inbound_count'] );
	}

	public function test_eligibility_rules() {
		$ok       = self::factory()->post->create();
		$draft    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$password = self::factory()->post->create( array( 'post_password' => 'x' ) );
		$excluded = self::factory()->post->create();
		$cat      = self::factory()->category->create();
		$in_cat   = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		Settings::update(
			array(
				'excluded_posts' => array( $excluded ),
				'excluded_terms' => array( $cat ),
			)
		);

		$this->assertTrue( Eligibility::is_eligible( $ok ) );
		$this->assertFalse( Eligibility::is_eligible( $draft ) );
		$this->assertFalse( Eligibility::is_eligible( $password ) );
		$this->assertFalse( Eligibility::is_eligible( $excluded ) );
		$this->assertFalse( Eligibility::is_eligible( $in_cat ) );

		$ids = Eligibility::post_ids();
		$this->assertContains( $ok, $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $password, $ids );
		$this->assertNotContains( $excluded, $ids );
		$this->assertNotContains( $in_cat, $ids );
	}

	public function test_page_builder_detection() {
		$id = self::factory()->post->create();
		$this->assertFalse( Eligibility::is_page_builder( $id ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		$this->assertTrue( Eligibility::is_page_builder( $id ) );
	}

	public function test_content_change_marks_suggestions_stale() {
		global $wpdb;
		$t      = AILSUG\Activator::tables();
		$source = self::factory()->post->create( array( 'post_content' => '<p>One</p>' ) );
		$target = self::factory()->post->create();
		Indexer::index_post( $source );
		Indexer::index_post( $target );
		$hash = Indexer::get_row( $source )['content_hash'];
		$now  = current_time( 'mysql', true );
		$wpdb->insert(
			$t['suggestions'],
			array(
				'source_post_id'  => $source,
				'target_post_id'  => $target,
				'context_snippet' => '',
				'status'          => 'pending',
				'source_hash'     => $hash,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$sid = $wpdb->insert_id;

		Indexer::index_post( $source );
		$this->assertSame( 'pending', $wpdb->get_var( "SELECT status FROM {$t['suggestions']} WHERE id = {$sid}" ) );

		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => '<p>Two</p>',
			)
		);
		Indexer::index_post( $source );
		$this->assertSame( 'stale', $wpdb->get_var( "SELECT status FROM {$t['suggestions']} WHERE id = {$sid}" ) );
	}
}
