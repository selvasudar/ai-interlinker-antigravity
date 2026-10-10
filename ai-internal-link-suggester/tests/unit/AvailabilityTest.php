<?php
/**
 * Availability and mock gateway tests.
 *
 * @package AILSUG
 */

use AILSUG\AI\Availability;
use AILSUG\AI\Gateway;
use AILSUG\AI\MockGateway;
use AILSUG\AI\ModelResolver;

class AvailabilityTest extends WP_UnitTestCase {

	public function test_core_ai_client_is_present() {
		$this->assertTrue( Availability::core_present() );
	}

	public function test_mock_mode_reports_available() {
		$this->assertTrue( Availability::is_mock() );
		$this->assertTrue( Availability::status()['available'] );
		$this->assertInstanceOf( MockGateway::class, Gateway::instance() );
	}

	public function test_decode_json_tolerates_fences() {
		$this->assertSame( array( 'a' => 1 ), Gateway::decode_json( "```json\n{\"a\":1}\n```" ) );
		$this->assertNull( Gateway::decode_json( 'not json' ) );
	}

	public function test_friendly_error_for_prevented_prompt() {
		$msg = Gateway::friendly_error( new WP_Error( 'prompt_prevented', 'x', array( 'status' => 503 ) ) );
		$this->assertStringContainsString( 'blocked', $msg );
	}

	public function test_prediction_prefers_tier_models() {
		add_filter(
			'ailsug_model_preferences',
			static function () {
				return array( 'economy' => array( 'nope', MockGateway::MODEL ) );
			}
		);
		$p = ModelResolver::predict( 'economy' );
		$this->assertSame( array( 'mock', MockGateway::MODEL ), $p['likely'] );
		$this->assertFalse( $p['firm'] );
	}
}
