<?php
/**
 * Suggestion engine tests: TextMap, shortlist, anchor finder, validator, ranker.
 *
 * @package AILSUG
 */

use AILSUG\AI\Gateway;
use AILSUG\AI\MockGateway;
use AILSUG\Cost\Pricing;
use AILSUG\Index\Indexer;
use AILSUG\Index\KeywordResolver;
use AILSUG\Queue\Queue;
use AILSUG\Queue\Runs;
use AILSUG\Queue\Worker;
use AILSUG\Settings;
use AILSUG\Suggest\AnchorFinder;
use AILSUG\Suggest\Ranker;
use AILSUG\Suggest\Shortlister;
use AILSUG\Suggest\TextMap;
use AILSUG\Suggest\Validator;

class SuggestTest extends WP_UnitTestCase {

	/** @var MockGateway */
	private $gw;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Gateway::PRICE_BLOCK );
		Settings::flush();
		Pricing::flush();
		Settings::update( array( 'min_link_distance' => 0 ) );
		$this->set_permalink_structure( '/%postname%/' );
		$this->gw = new MockGateway();
		Gateway::set_instance( $this->gw );
	}

	public function tear_down() {
		Gateway::set_instance( null );
		parent::tear_down();
	}

	private function page( $title, $content, $keyword = null ) {
		$id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_name'    => sanitize_title( $title ),
			)
		);
		if ( $keyword ) {
			KeywordResolver::set_manual( $id, $keyword );
		}
		Indexer::index_post( $id );
		return $id;
	}

	/* ---------- TextMap ---------- */

	public function test_textmap_excludes_ineligible_text() {
		$html = '<!-- wp:paragraph --><p>Soil one <a href="/x">soil link</a> and <code>soil code</code>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2>Soil heading</h2><!-- /wp:heading -->'
			. '<!-- wp:html --><div>soil html</div><!-- /wp:html -->'
			. '<figure><img src="a.jpg"><figcaption>soil caption</figcaption></figure>'
			. '<p>Use [shortcode soil] then soil.</p>';
		$map  = new TextMap( $html );
		$hits = $map->find( 'soil', null, true );
		$this->assertCount( 2, $hits, 'Only "Soil one" and "then soil" are eligible.' );
		$this->assertCount( 1, $map->find( 'soil' ), 'Exact case wins by default.' );
		foreach ( $hits as $h ) {
			$this->assertSame( 'soil', strtolower( substr( $html, $h['raw_start'], $h['raw_end'] - $h['raw_start'] ) ) );
		}
		$this->assertArrayHasKey( 'b0p0', $map->chunks() );
	}

	public function test_textmap_multiple_identical_phrases_in_order() {
		$html = '<p>Compost is great. More compost here.</p><p>Even more compost.</p>';
		$map  = new TextMap( $html );
		$hits = $map->find( 'compost', null, true );
		$this->assertCount( 3, $hits );
		$this->assertLessThan( $hits[1]['raw_start'], $hits[0]['raw_start'] );
		$this->assertSame( 'b0p1', $hits[2]['chunk'] );
		$this->assertCount( 1, $map->find( 'compost', 'b0p1' ) );
		$this->assertCount( 2, $map->find( 'compost' ), 'Case-sensitive matches first.' );
	}

	public function test_textmap_entities_and_unicode() {
		$html = '<p>Salt &amp; pepper — café crème &nbsp;brûlée.</p>';
		$map  = new TextMap( $html );
		$h    = $map->find( 'Salt & pepper' )[0];
		$this->assertSame( 'Salt &amp; pepper', substr( $html, $h['raw_start'], $h['raw_end'] - $h['raw_start'] ) );
		$h = $map->find( 'café crème' )[0];
		$this->assertSame( 'café crème', substr( $html, $h['raw_start'], $h['raw_end'] - $h['raw_start'] ) );
		$this->assertEmpty( $map->find( 'caf' ), 'Whole words only.' );
	}

	public function test_textmap_classic_paragraphs() {
		$html = "First para about mulch.\n\nSecond para about <em>wood</em> chips and mulch.";
		$map  = new TextMap( $html );
		$this->assertCount( 2, $map->chunks() );
		$this->assertSame( 'b0p1', $map->find( 'mulch' )[1]['chunk'] );
		$this->assertSame( 'b0p0', $map->find( 'mulch' )[0]['chunk'] );
	}

	/* ---------- Shortlist and anchors ---------- */

	public function test_shortlist_exclusions() {
		$source  = $this->page( 'Composting basics', '<p>Learn about garden soil and raised beds.</p>', 'composting basics' );
		$soil    = $this->page( 'Garden soil guide', '<p>Soil.</p>', 'garden soil' );
		$beds    = $this->page( 'Raised beds', '<p>Beds.</p>', 'raised beds' );
		$noindex = $this->page( 'Soil secrets', '<p>Soil.</p>', 'soil secrets' );
		$draft   = self::factory()->post->create(
			array(
				'post_title'  => 'Soil draft',
				'post_status' => 'draft',
			)
		);
		add_filter(
			'ailsug_is_noindex',
			static function ( $n, $id ) use ( $noindex ) {
				return $id === $noindex ? true : $n;
			},
			10,
			2
		);
		Indexer::index_post( $noindex );
		$ids = wp_list_pluck( Shortlister::shortlist( $source ), 'post_id' );
		$ids = array_map( 'intval', $ids );
		$this->assertNotContains( $source, $ids );
		$this->assertNotContains( $noindex, $ids );
		$this->assertNotContains( $draft, $ids );

		// Already-linked targets are excluded.
		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => '<p>Learn about garden soil and <a href="' . get_permalink( $beds ) . '">raised beds</a>.</p>',
			)
		);
		Indexer::index_post( $source );
		$ids = array_map( 'intval', wp_list_pluck( Shortlister::shortlist( $source ), 'post_id' ) );
		$this->assertNotContains( $beds, $ids );
		unset( $soil );
	}

	public function test_anchor_finder_picks_matching_chunks_and_stems() {
		$map        = new TextMap( '<p>Intro text.</p><p>Healthy tomato plants need staking.</p><p>Unrelated words.</p>' );
		$candidates = array(
			array(
				'post_id'         => 1,
				'title'           => 'Tomato',
				'primary_keyword' => 'tomato plant',
				'secondary'       => array(),
				'summary'         => '',
			),
			array(
				'post_id'         => 2,
				'title'           => 'Nothing',
				'primary_keyword' => 'quantum physics',
				'secondary'       => array(),
				'summary'         => '',
			),
		);
		$found      = AnchorFinder::opportunities( $map, $candidates, 6 );
		$this->assertSame( array( 'b0p1' ), array_keys( $found['chunks'] ) );
		$this->assertSame( array( 1 ), array_map( 'intval', wp_list_pluck( $found['candidates'], 'post_id' ) ) );
	}

	/* ---------- Validator ---------- */

	public function test_validator_rejects_bad_output() {
		$source = $this->page( 'Source', '<p>Good garden soil matters. Also <a href="/x">compost bins</a> help.</p><p>Read more here.</p>' );
		$map    = new TextMap( get_post( $source )->post_content );
		$raw    = array(
			'suggestions' => array(
				array(
					'target_id'   => 999,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'garden soil',
					'score'       => 90,
					'reason'      => 'x',
				), // Not shortlisted.
				array(
					'target_id'   => 11,
					'chunk_id'    => 'b9p9',
					'anchor_text' => 'garden soil',
					'score'       => 90,
					'reason'      => 'x',
				), // Chunk not supplied.
				array(
					'target_id'   => 11,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'organic soil',
					'score'       => 90,
					'reason'      => 'x',
				), // Not verbatim.
				array(
					'target_id'   => 12,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'compost bins',
					'score'       => 90,
					'reason'      => 'x',
				), // Inside an existing link.
				array(
					'target_id'   => 13,
					'chunk_id'    => 'b0p1',
					'anchor_text' => 'Read more',
					'score'       => 90,
					'reason'      => 'x',
				), // Generic.
				array(
					'target_id'   => 11,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'garden soil',
					'score'       => 250,
					'reason'      => str_repeat( 'r', 300 ),
				), // Valid.
				array(
					'target_id'   => 11,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'Good garden soil',
					'score'       => 70,
					'reason'      => 'dup',
				), // Same target.
				array(
					'target_id'   => 14,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'soil matters',
					'score'       => 60,
					'reason'      => 'overlap',
				), // Overlaps.
				'not an array',
			),
		);
		$out    = Validator::validate( $raw, array( 11, 12, 13, 14 ), array( 'b0p0', 'b0p1' ), $map, $source );
		$this->assertCount( 1, $out );
		$this->assertSame( 11, $out[0]['target_id'] );
		$this->assertSame( 100, $out[0]['score'] );
		$this->assertSame( 140, mb_strlen( $out[0]['reason'] ) );
		$this->assertSame( array(), Validator::validate( 'garbage', array(), array(), $map, $source ) );
	}

	public function test_validator_spacing_and_max() {
		Settings::update(
			array(
				'min_link_distance' => 5,
				'max_suggestions'   => 1,
			)
		);
		$source = $this->page( 'Source', '<p>alpha beta gamma delta epsilon zeta eta theta.</p>' );
		$map    = new TextMap( get_post( $source )->post_content );
		$raw    = array(
			'suggestions' => array(
				array(
					'target_id'   => 1,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'alpha beta',
					'score'       => 90,
					'reason'      => '',
				),
				array(
					'target_id'   => 2,
					'chunk_id'    => 'b0p0',
					'anchor_text' => 'gamma delta',
					'score'       => 80,
					'reason'      => '',
				),
			),
		);
		$this->assertCount( 1, Validator::validate( $raw, array( 1, 2 ), array( 'b0p0' ), $map, $source ) );
		Settings::update( array( 'max_suggestions' => 5 ) );
		$this->assertCount( 1, Validator::validate( $raw, array( 1, 2 ), array( 'b0p0' ), $map, $source ), 'Too close together.' );
		Settings::update( array( 'min_link_distance' => 1 ) );
		$this->assertCount( 2, Validator::validate( $raw, array( 1, 2 ), array( 'b0p0' ), $map, $source ) );
	}

	/* ---------- Ranker ---------- */

	public function test_ranker_end_to_end_with_cache() {
		global $wpdb;
		$t      = AILSUG\Activator::tables();
		$target = $this->page( 'Garden soil guide', '<p>All about soil.</p>', 'garden soil' );
		$other  = $this->page( 'Quantum stuff', '<p>Physics.</p>', 'quantum physics' );
		$source = $this->page( 'Raised beds', '<p>Raised beds need good garden soil to thrive.</p>', 'raised beds' );

		$this->assertTrue( Ranker::task( $source, 'r1' ) );
		$this->assertSame( 1, $this->gw->calls );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['suggestions']} WHERE source_post_id = %d", $source ), ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $target, $rows[0]['target_post_id'] );
		$this->assertSame( 'garden soil', $rows[0]['anchor_text'] );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( Indexer::get_row( $source )['content_hash'], $rows[0]['source_hash'] );

		// Unchanged: no new AI call.
		$this->assertTrue( Ranker::task( $source, 'r2' ) );
		$this->assertSame( 1, $this->gw->calls );
		// Forced regenerate: a new call.
		$this->assertTrue( Ranker::task_force( $source, 'r3' ) );
		$this->assertSame( 2, $this->gw->calls );
		unset( $other );
	}

	public function test_no_local_match_means_no_ai_call() {
		$this->page( 'Garden soil guide', '<p>Soil.</p>', 'garden soil' );
		$source = $this->page( 'Unrelated', '<p>Nothing relevant in here at all.</p>', 'unrelated' );
		$this->assertTrue( Ranker::task( $source, 'r1' ) );
		$this->assertSame( 0, $this->gw->calls );
	}

	public function test_hallucinated_mock_output_is_dropped() {
		global $wpdb;
		$t = AILSUG\Activator::tables();
		$this->page( 'Garden soil guide', '<p>Soil.</p>', 'garden soil' );
		$source = $this->page( 'Raised beds', '<p>Raised beds need good garden soil.</p>', 'raised beds' );
		add_filter(
			'ailsug_mock_response',
			static function () {
				$text = wp_json_encode(
					array(
						'suggestions' => array(
							array(
								'target_id'   => 123456,
								'chunk_id'    => 'b0p0',
								'anchor_text' => 'garden soil',
								'score'       => 99,
								'reason'      => 'x',
							),
						),
					)
				);
				return array(
					'text'          => $text,
					'input_tokens'  => 100,
					'output_tokens' => 20,
					'provider'      => 'mock',
					'model'         => MockGateway::MODEL,
				);
			}
		);
		Ranker::task( $source, 'r1' );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['suggestions']} WHERE source_post_id = %d", $source ) ) );
	}

	public function test_scan_run_selection_and_rejections_are_remembered() {
		global $wpdb;
		$t      = AILSUG\Activator::tables();
		$target = $this->page( 'Garden soil guide', '<p>Soil basics.</p>', 'garden soil' );
		$source = $this->page( 'Raised beds', '<p>Raised beds need good garden soil.</p>', 'raised beds' );
		$e      = Runs::estimate( 'scan' );
		$this->assertSame( 2, $e['rank_posts'] );
		$run = Runs::start_paid( 'scan' );
		Worker::process( 20, $run );
		$this->assertSame( 'done', Queue::progress( $run )['status'] );
		$this->assertSame( 0, Runs::estimate( 'scan' )['rank_posts'], 'Nothing changed: nothing to scan.' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$t['suggestions']} SET status = 'rejected' WHERE source_post_id = %d", $source ) );
		Ranker::task_force( $source, 'again' );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['suggestions']} WHERE source_post_id = %d AND target_post_id = %d", $source, $target ) ), 'A rejected target is not suggested again.' );
	}
}
