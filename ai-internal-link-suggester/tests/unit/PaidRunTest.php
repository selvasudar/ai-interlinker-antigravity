<?php
/**
 * Paid run estimate/start and the AI keyword task.
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

class PaidRunTest extends WP_UnitTestCase {

	/** @var MockGateway */
	private $gw;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Gateway::PRICE_BLOCK );
		Settings::flush();
		Pricing::flush();
		$this->gw = new MockGateway();
		Gateway::set_instance( $this->gw );
	}

	public function tear_down() {
		Gateway::set_instance( null );
		parent::tear_down();
	}

	public function test_estimate_and_keyword_run() {
		$a = self::factory()->post->create(
			array(
				'post_title'   => 'Composting at Home',
				'post_content' => '<p>Compost basics.</p>',
			)
		);
		$b = self::factory()->post->create(
			array(
				'post_title'   => 'Raised Beds',
				'post_content' => '<p>Beds.</p>',
			)
		);
		$c = self::factory()->post->create( array( 'post_title' => 'Manual one' ) );
		KeywordResolver::set_manual( $c, 'typed' );
		foreach ( array( $a, $b, $c ) as $id ) {
			Indexer::index_post( $id );
		}

		$e = Runs::estimate( 'keywords' );
		$this->assertSame( 2, $e['posts'], 'Pages with a manual keyword skip AI.' );
		$this->assertTrue( $e['known'] );
		$this->assertSame( '', $e['blocker'] );
		$this->assertSame( 0, $this->gw->calls, 'Estimating never calls AI.' );

		$run = Runs::start_paid( 'keywords' );
		$this->assertIsString( $run );
		Worker::process( 10, $run );
		$this->assertSame( 2, $this->gw->calls );
		$this->assertSame( 'ai', Indexer::get_row( $a )['keyword_source'] );
		$this->assertSame( 'composting at home', Indexer::get_row( $a )['primary_keyword'] );
		$this->assertSame( 'done', Queue::progress( $run )['status'] );
		$this->assertGreaterThan( 0, Queue::progress( $run )['spent_micros'] );

		// Nothing changed: a second run has nothing to pay for.
		$this->assertSame( 0, Runs::estimate( 'keywords' )['posts'] );
	}

	public function test_over_budget_requires_a_limit() {
		for ( $i = 0; $i < 5; $i++ ) {
			Indexer::index_post( self::factory()->post->create( array( 'post_title' => "Page {$i}" ) ) );
		}
		$one = Runs::estimate( 'keywords' );
		$per = (int) ceil( $one['worst'] / 5 );
		Settings::update( array( 'budget_cap_micros' => $per * 2 + 1 ) );

		$e = Runs::estimate( 'keywords' );
		$this->assertSame( 2, $e['fits'] );
		$this->assertWPError( Runs::start_paid( 'keywords' ) );
		$run = Runs::start_paid( 'keywords', null, 2 );
		$this->assertIsString( $run );
		$this->assertSame( 2, Queue::progress( $run )['total'] );
	}

	public function test_strict_unknown_price_blocks_start() {
		Indexer::index_post( self::factory()->post->create() );
		Settings::update(
			array(
				'budget_cap_micros' => 1000000,
				'strict_budget'     => true,
			)
		);
		add_filter( 'ailsug_pricing_table', '__return_empty_array' );
		Pricing::flush();
		$e = Runs::estimate( 'keywords' );
		$this->assertNotSame( '', $e['blocker'] );
		$this->assertWPError( Runs::start_paid( 'keywords' ) );
	}

	public function test_rest_requires_confirmation() {
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Indexer::index_post( self::factory()->post->create() );
		$req = new WP_REST_Request( 'POST', '/ai-internal-link-suggester/v1/runs' );
		$req->set_param( 'type', 'keywords' );
		$this->assertSame( 400, rest_get_server()->dispatch( $req )->get_status() );
		$req->set_param( 'confirmed', true );
		$this->assertSame( 200, rest_get_server()->dispatch( $req )->get_status() );

		$est = new WP_REST_Request( 'GET', '/ai-internal-link-suggester/v1/estimate/run' );
		$est->set_param( 'type', 'keywords' );
		$this->assertSame( 200, rest_get_server()->dispatch( $est )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $est )->get_status() );
	}
}
