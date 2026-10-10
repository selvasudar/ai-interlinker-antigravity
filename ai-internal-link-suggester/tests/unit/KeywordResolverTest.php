<?php
/**
 * Keyword priority and SEO plugin adapter tests.
 *
 * @package AILSUG
 */

use AILSUG\Index\Indexer;
use AILSUG\Index\KeywordResolver;
use AILSUG\Index\SeoPluginReader;
use AILSUG\Settings;

class KeywordResolverTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
	}

	private function force_adapters( array $adapters ) {
		add_filter(
			'ailsug_active_seo_adapters',
			static function () use ( $adapters ) {
				return $adapters;
			}
		);
	}

	public function test_priority_manual_then_seo_then_ai() {
		$this->force_adapters( array( 'yoast' ) );
		$id = self::factory()->post->create();

		$r = KeywordResolver::resolve( $id, null, 'h1' );
		$this->assertSame( '', $r['keyword'] );
		$this->assertTrue( $r['needs_ai'] );

		$ai_row = array(
			'keyword_source'     => 'ai',
			'primary_keyword'    => 'ai phrase',
			'secondary_keywords' => '["x"]',
			'ai_hash'            => 'h1',
		);
		$r      = KeywordResolver::resolve( $id, $ai_row, 'h1' );
		$this->assertSame( 'ai', $r['source'] );
		$this->assertFalse( $r['needs_ai'], 'Unchanged content must not trigger AI again.' );
		$r = KeywordResolver::resolve( $id, $ai_row, 'h2' );
		$this->assertTrue( $r['needs_ai'], 'Changed content needs a fresh AI keyword.' );

		update_post_meta( $id, '_yoast_wpseo_focuskw', 'yoast phrase' );
		$r = KeywordResolver::resolve( $id, $ai_row, 'h2' );
		$this->assertSame( 'seo_plugin', $r['source'] );
		$this->assertSame( 'yoast phrase', $r['keyword'] );
		$this->assertFalse( $r['needs_ai'] );

		KeywordResolver::set_manual( $id, '  My Phrase ' );
		$r = KeywordResolver::resolve( $id, $ai_row, 'h2' );
		$this->assertSame( 'manual', $r['source'] );
		$this->assertSame( 'My Phrase', $r['keyword'] );

		KeywordResolver::set_manual( $id, '' );
		$this->assertSame( 'seo_plugin', KeywordResolver::resolve( $id, null, 'h2' )['source'] );
	}

	public function test_rank_math_first_keyword_is_primary() {
		$this->force_adapters( array( 'rankmath' ) );
		$id = self::factory()->post->create();
		update_post_meta( $id, 'rank_math_focus_keyword', 'first one, second, third' );
		$kw = SeoPluginReader::keyword( $id );
		$this->assertSame( 'first one', $kw['keyword'] );
		$this->assertSame( array( 'second', 'third' ), $kw['secondary'] );
	}

	public function test_seopress_and_noindex_flags() {
		$this->force_adapters( array( 'seopress' ) );
		$id = self::factory()->post->create();
		update_post_meta( $id, '_seopress_analysis_target_kw', 'press kw,other' );
		$this->assertSame( 'press kw', SeoPluginReader::keyword( $id )['keyword'] );
		$this->assertFalse( SeoPluginReader::is_noindex( $id ) );
		update_post_meta( $id, '_seopress_robots_index', 'yes' );
		$this->assertTrue( SeoPluginReader::is_noindex( $id ) );
	}

	public function test_yoast_noindex_falls_back_to_post_type_default() {
		$this->force_adapters( array( 'yoast' ) );
		$id = self::factory()->post->create();
		$this->assertFalse( SeoPluginReader::is_noindex( $id ) );
		update_option( 'wpseo_titles', array( 'noindex-post' => true ) );
		$this->assertTrue( SeoPluginReader::is_noindex( $id ) );
		update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '2' );
		$this->assertFalse( SeoPluginReader::is_noindex( $id ) );
	}

	public function test_rank_math_robots_array() {
		$this->force_adapters( array( 'rankmath' ) );
		$id = self::factory()->post->create();
		update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		$this->assertTrue( SeoPluginReader::is_noindex( $id ) );
	}

	public function test_malformed_values_fall_through_silently() {
		$this->force_adapters( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ) );
		$id = self::factory()->post->create();
		update_post_meta( $id, 'rank_math_focus_keyword', array( 'not', 'a', 'string' ) );
		update_post_meta( $id, '_yoast_wpseo_focuskw', '   ' );
		$this->assertNull( SeoPluginReader::keyword( $id ) );
	}

	public function test_adapters_can_be_disabled() {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_yoast_wpseo_focuskw', 'yoast phrase' );
		add_filter(
			'ailsug_seo_keyword_sources',
			static function () {
				return array(
					array(
						'meta_key'  => '_custom_kw',
						'separator' => '|',
					),
				);
			}
		);
		update_post_meta( $id, '_custom_kw', 'custom a|custom b' );
		$this->assertSame( 'custom a', SeoPluginReader::keyword( $id )['keyword'] );
		Settings::update( array( 'use_seo_adapters' => false ) );
		$this->assertNull( SeoPluginReader::keyword( $id ) );
	}

	public function test_indexer_stores_source() {
		$id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		KeywordResolver::set_manual( $id, 'typed keyword' );
		Indexer::index_post( $id );
		$row = Indexer::get_row( $id );
		$this->assertSame( 'typed keyword', $row['primary_keyword'] );
		$this->assertSame( 'manual', $row['keyword_source'] );
	}
}
