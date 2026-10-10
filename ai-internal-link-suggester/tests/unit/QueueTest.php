<?php
/**
 * Queue claim, retry, pause/cancel and REST permission tests.
 *
 * @package AILSUG
 */

use AILSUG\Queue\Queue;
use AILSUG\Queue\Worker;

class QueueTest extends WP_UnitTestCase {

	private function jobs( $n ) {
		$out = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$out[] = array( 'test', $i );
		}
		return $out;
	}

	public function test_claims_never_overlap() {
		$run = Queue::create_run( 'index', $this->jobs( 10 ) );
		$a   = wp_list_pluck( Queue::claim( 4, $run ), 'id' );
		$b   = wp_list_pluck( Queue::claim( 4, $run ), 'id' );
		$c   = wp_list_pluck( Queue::claim( 4, $run ), 'id' );
		$this->assertCount( 4, $a );
		$this->assertCount( 4, $b );
		$this->assertCount( 2, $c );
		$this->assertSame( array(), array_intersect( $a, $b, $c ) );
		$this->assertCount( 10, array_unique( array_merge( $a, $b, $c ) ) );
		$this->assertSame( array(), Queue::claim( 4, $run ) );
	}

	public function test_expired_lock_is_reclaimed() {
		global $wpdb;
		$t   = AILSUG\Activator::tables();
		$run = Queue::create_run( 'index', $this->jobs( 1 ) );
		$job = Queue::claim( 1, $run )[0];
		$this->assertSame( array(), Queue::claim( 1, $run ) );
		$wpdb->update( $t['queue'], array( 'locked_until' => '2000-01-01 00:00:00' ), array( 'id' => $job['id'] ) );
		$again = Queue::claim( 1, $run );
		$this->assertCount( 1, $again );
		$this->assertSame( 2, (int) $again[0]['attempts'] );
	}

	public function test_retry_then_fail_and_hold() {
		$calls = 0;
		add_filter(
			'ailsug_task_handlers',
			static function ( $h ) use ( &$calls ) {
				$h['test']   = static function () use ( &$calls ) {
					++$calls;
					return new WP_Error( 'x', 'boom' );
				};
				$h['budget'] = static function () {
					return new WP_Error( 'ailsug_budget', 'cap', array( 'hold' => 'budget' ) );
				};
				return $h;
			}
		);
		$run = Queue::create_run( 'index', $this->jobs( 1 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			Worker::process( 5, $run );
		}
		$this->assertSame( Queue::MAX_ATTEMPTS, $calls );
		$p = Queue::progress( $run );
		$this->assertSame( 1, $p['counts']['failed'] );
		$this->assertSame( 'done', $p['status'] );

		$run2 = Queue::create_run( 'index', array( array( 'budget', 1 ) ) );
		Worker::process( 5, $run2 );
		$p = Queue::progress( $run2 );
		$this->assertSame( 1, $p['counts']['budget'] );
		$this->assertSame( 'budget', $p['status'] );
		Queue::release_held();
		$this->assertSame( 1, Queue::progress( $run2 )['counts']['pending'] );
	}

	public function test_pause_resume_cancel() {
		add_filter(
			'ailsug_task_handlers',
			static function ( $h ) {
				$h['test'] = '__return_true';
				return $h;
			}
		);
		$run = Queue::create_run( 'index', $this->jobs( 6 ) );
		Worker::process( 2, $run );
		Queue::set_run_status( $run, 'paused' );
		$this->assertSame( 0, Worker::process( 5, $run ) );
		$this->assertSame( 'paused', Queue::progress( $run )['status'] );
		Queue::set_run_status( $run, 'running' );
		Worker::process( 2, $run );
		Queue::set_run_status( $run, 'cancelled' );
		$p = Queue::progress( $run );
		$this->assertSame( 4, $p['counts']['done'] );
		$this->assertSame( 2, $p['counts']['cancelled'] );
		$this->assertSame( 'cancelled', $p['status'] );
	}

	public function test_rest_routes_require_permissions() {
		do_action( 'rest_api_init' );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$req = new WP_REST_Request( 'POST', '/ai-internal-link-suggester/v1/runs' );
		$req->set_param( 'type', 'index' );
		$this->assertSame( 403, rest_get_server()->dispatch( $req )->get_status() );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->post->create( array( 'post_author' => $editor ) );
		wp_set_current_user( $author );
		$req = new WP_REST_Request( 'POST', '/ai-internal-link-suggester/v1/keywords/' . $other );
		$req->set_param( 'keyword', 'x' );
		$this->assertSame( 403, rest_get_server()->dispatch( $req )->get_status() );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$req = new WP_REST_Request( 'POST', '/ai-internal-link-suggester/v1/runs' );
		$req->set_param( 'type', 'index' );
		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'run_id', $res->get_data() );

		$req = new WP_REST_Request( 'POST', '/ai-internal-link-suggester/v1/runs' );
		$req->set_param( 'type', 'nope' );
		$this->assertSame( 400, rest_get_server()->dispatch( $req )->get_status() );
	}
}
