<?php
/**
 * Dashboard quick wins, onboarding and Site Health tests.
 *
 * @package AILSUG
 */

use AILSUG\Admin\Dashboard;
use AILSUG\Admin\Onboarding;
use AILSUG\Admin\Site_Health;
use AILSUG\Index\Indexer;

class DashboardTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_quick_wins_favor_targets_with_few_links() {
		global $wpdb;
		$t       = AILSUG\Activator::tables();
		$popular = self::factory()->post->create(
			array(
				'post_title' => 'Popular',
				'post_name'  => 'popular',
			)
		);
		$orphan  = self::factory()->post->create( array( 'post_title' => 'Orphan' ) );
		$source  = self::factory()->post->create( array( 'post_title' => 'Source' ) );
		for ( $i = 0; $i < 4; $i++ ) {
			self::factory()->post->create( array( 'post_content' => '<p><a href="/popular/">x</a></p>' ) );
		}
		foreach ( get_posts(
			array(
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $id ) {
			Indexer::index_post( $id );
		}
		$now = current_time( 'mysql', true );
		foreach ( array( array( $popular, 95 ), array( $orphan, 80 ) ) as $row ) {
			$wpdb->insert(
				$t['suggestions'],
				array(
					'source_post_id'  => $source,
					'target_post_id'  => $row[0],
					'anchor_text'     => 'x',
					'context_snippet' => '',
					'score'           => $row[1],
					'status'          => 'pending',
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
		}
		$wins = Dashboard::quick_wins();
		$this->assertSame( (string) $orphan, $wins[0]['target_post_id'], 'An orphan target beats a slightly higher score to a well-linked page.' );
		$k = Dashboard::kpis();
		$this->assertSame( 2, $k['pending'] );
		$this->assertGreaterThan( 0, $k['orphans'] );
	}

	public function test_onboarding_steps_progress() {
		$steps = Onboarding::steps( array( 'scans' => 0 ) );
		$this->assertCount( 4, $steps );
		$this->assertTrue( $steps[0]['done'], 'Mock AI counts as connected.' );
		$this->assertFalse( $steps[2]['done'] );
		Onboarding::costs_seen();
		$this->assertTrue( Onboarding::steps( array( 'scans' => 1 ) )[2]['done'] );
		$this->assertTrue( Onboarding::steps( array( 'scans' => 1 ) )[3]['done'] );
	}

	public function test_site_health() {
		$tests = Site_Health::tests( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'ailsug_ai_provider', $tests['direct'] );
		$this->assertSame( 'good', Site_Health::test_ai()['status'] );
		$info = Site_Health::info( array() );
		$this->assertArrayHasKey( 'ai-internal-link-suggester', $info );
	}
}
