<?php
/**
 * Proof that apply → revert is byte-identical on representative real-world content.
 * Bulk apply stays behind a feature flag until this suite passes.
 *
 * @package AILSUG
 */

use AILSUG\Index\Indexer;
use AILSUG\Settings;
use AILSUG\Suggest\Applier;
use AILSUG\Suggest\Content_Writer;
use AILSUG\Suggest\Reverter;
use AILSUG\Suggest\TextMap;

class ApplyRevertCorpusTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
		Settings::update(
			array(
				'max_links_per_apply' => 20,
				'min_link_distance'   => 0,
				'max_suggestions'     => 20,
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function corpus() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/corpus/manifest.json' ), true );
		$out      = array();
		foreach ( $manifest as $file => $anchors ) {
			$out[ $file ] = array( $file, $anchors );
		}
		return $out;
	}

	private function source( $content ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => 'Corpus source',
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			)
		);
		Indexer::index_post( $id );
		return $id;
	}

	private function suggestions( $post_id, array $anchors ) {
		global $wpdb;
		$t       = AILSUG\Activator::tables();
		$content = get_post( $post_id )->post_content;
		$map     = new TextMap( $content );
		$ids     = array();
		foreach ( $anchors as $i => $anchor ) {
			$occ = $map->find( $anchor );
			if ( ! $occ ) {
				continue;
			}
			$target = self::factory()->post->create( array( 'post_title' => "Target {$i} {$anchor}" ) );
			Indexer::index_post( $target );
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				$t['suggestions'],
				array(
					'source_post_id'  => $post_id,
					'target_post_id'  => $target,
					'anchor_text'     => $occ[0]['text'],
					'chunk_id'        => $occ[0]['chunk'],
					'context_snippet' => '',
					'score'           => 90 - $i,
					'status'          => 'accepted',
					'source_hash'     => md5( $content ),
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		return $ids;
	}

	private function applied_markups( $post_id ) {
		return wp_list_pluck( Content_Writer::active_records( $post_id ), 'inserted_markup' );
	}

	/**
	 * @dataProvider corpus
	 */
	public function test_bulk_apply_then_batch_revert_is_byte_identical( $file, $anchors ) {
		$source   = $this->source( file_get_contents( dirname( __DIR__ ) . '/corpus/' . $file ) );
		$original = get_post( $source )->post_content;
		$ids      = $this->suggestions( $source, $anchors );
		$this->assertNotEmpty( $ids, "{$file}: at least one anchor must be eligible." );

		$res     = Applier::apply( $ids );
		$applied = array_filter(
			$res['results'],
			static function ( $r ) {
				return 'applied' === $r['status'];
			}
		);
		$this->assertNotEmpty( $applied, "{$file}: " . wp_json_encode( $res['results'] ) );

		$after   = get_post( $source )->post_content;
		$markups = $this->applied_markups( $source );
		$this->assertCount( count( $applied ), $markups );
		foreach ( $markups as $m ) {
			$this->assertStringContainsString( $m, $after );
		}
		// Everything outside the inserted links is untouched.
		$this->assertSame( $original, Content_Writer::unwrap_all( $after, $markups ), "{$file}: only link markup may change." );
		// Links never land inside attributes, comments, headings or existing links.
		foreach ( $markups as $m ) {
			$this->assertMatchesRegularExpression( '#^<a href="http[^"]+">[^<]+</a>$#u', $m );
		}
		$hrefs = array();
		$proc  = new WP_HTML_Tag_Processor( $after );
		while ( $proc->next_tag( 'A' ) ) {
			$hrefs[] = (string) $proc->get_attribute( 'href' );
		}
		foreach ( $markups as $m ) {
			preg_match( '#href="([^"]+)"#', $m, $h );
			$this->assertContains( html_entity_decode( $h[1] ), $hrefs, "{$file}: inserted link must be a real <a> element." );
		}

		$rev = Reverter::revert( Reverter::records( array(), $res['batch_id'] ) );
		foreach ( $rev as $r ) {
			$this->assertSame( 'reverted', $r['status'], "{$file}: " . $r['message'] );
		}
		$this->assertSame( $original, get_post( $source )->post_content, "{$file}: revert must restore the content byte for byte." );
	}

	/**
	 * @dataProvider corpus
	 */
	public function test_separate_batches_revert_in_any_order( $file, $anchors ) {
		$source   = $this->source( file_get_contents( dirname( __DIR__ ) . '/corpus/' . $file ) );
		$original = get_post( $source )->post_content;
		$batches  = array();
		foreach ( $this->suggestions( $source, $anchors ) as $id ) {
			$res = Applier::apply( array( $id ) );
			if ( 'applied' === $res['results'][0]['status'] ) {
				$batches[] = $res['batch_id'];
			}
		}
		$this->assertNotEmpty( $batches );
		// Revert oldest first (the harder order: newer links sit next to older ones).
		foreach ( $batches as $batch ) {
			foreach ( Reverter::revert( Reverter::records( array(), $batch ) ) as $r ) {
				$this->assertSame( 'reverted', $r['status'], "{$file}: " . $r['message'] );
			}
		}
		$this->assertSame( $original, get_post( $source )->post_content );
	}

	public function test_long_post() {
		$paras = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$paras[] = "<!-- wp:paragraph -->\n<p>Paragraph {$i} talks about " . ( 0 === $i % 50 ? 'garden soil and composting at home' : 'other things' ) . " in some detail, with é and 🌱.</p>\n<!-- /wp:paragraph -->";
		}
		$source   = $this->source( implode( "\n\n", $paras ) );
		$original = get_post( $source )->post_content;
		$ids      = $this->suggestions( $source, array( 'garden soil', 'composting at home' ) );
		$res      = Applier::apply( $ids );
		$this->assertCount(
			2,
			array_filter(
				wp_list_pluck( $res['results'], 'status' ),
				static function ( $s ) {
					return 'applied' === $s;
				}
			)
		);
		Reverter::revert( Reverter::records( array(), $res['batch_id'] ) );
		$this->assertSame( $original, get_post( $source )->post_content );
	}
}
