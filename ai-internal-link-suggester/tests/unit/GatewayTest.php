<?php
/**
 * Gateway flow tests (mock transport): budget, actual model, unknown prices, retries.
 *
 * @package AILSUG
 */

use AILSUG\AI\Gateway;
use AILSUG\AI\MockGateway;
use AILSUG\Cost\Budget;
use AILSUG\Cost\Pricing;
use AILSUG\Settings;

class GatewayTest extends WP_UnitTestCase {

	/** @var MockGateway */
	private $gw;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Gateway::PRICE_BLOCK );
		Settings::flush();
		Pricing::flush();
		$this->gw = new MockGateway();
	}

	private function usage_rows() {
		global $wpdb;
		$t = AILSUG\Activator::tables();
		return $wpdb->get_results( "SELECT * FROM {$t['usage']} ORDER BY id ASC", ARRAY_A );
	}

	private function keyword_call() {
		return $this->gw->run(
			'keyword',
			array(
				'title'    => 'Growing Tomatoes at Home',
				'headings' => array( 'Soil' ),
				'text'     => 'Some text.',
			),
			array(
				'run_id'  => 'r1',
				'post_id' => 7,
			)
		);
	}

	public function test_successful_call_is_logged_and_reconciled() {
		$r = $this->keyword_call();
		$this->assertIsArray( $r );
		$this->assertSame( 'growing tomatoes at', $r['data']['primary_keyword'] );
		$rows = $this->usage_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( MockGateway::MODEL, $rows[0]['model'] );
		$this->assertSame( '1', $rows[0]['price_known'] );
		$this->assertGreaterThan( 0, (int) $rows[0]['est_cost_micros'] );
		$b = Budget::status();
		$this->assertSame( (int) $rows[0]['est_cost_micros'], $b['spent'] );
		$this->assertSame( 0, $b['reserved'] );
	}

	public function test_actual_model_differs_from_requested() {
		add_filter(
			'ailsug_mock_model',
			static function () {
				return 'claude-sonnet-5-5';
			}
		);
		$this->keyword_call();
		$row = $this->usage_rows()[0];
		$this->assertSame( 'claude-sonnet-5-5', $row['model'] );
		$this->assertSame( MockGateway::MODEL, $row['requested_model'] );
		$expected = Pricing::call_cost( 'anthropic', 'claude-sonnet-5-5', (int) $row['input_tokens'], (int) $row['output_tokens'] );
		$this->assertSame( $expected, (int) $row['est_cost_micros'], 'Cost must be priced from the model that answered.' );
		$this->assertSame( 'claude-sonnet-5-5', get_option( 'ailsug_last_model' )['model'] );
	}

	public function test_unknown_actual_model_in_strict_mode_blocks_next_runs() {
		Settings::update(
			array(
				'budget_cap_micros' => 5000000,
				'strict_budget'     => true,
			)
		);
		add_filter(
			'ailsug_mock_model',
			static function () {
				return 'mystery-model-9';
			}
		);
		$r = $this->keyword_call();
		$this->assertIsArray( $r );
		$row = $this->usage_rows()[0];
		$this->assertSame( '0', $row['price_known'] );
		$this->assertGreaterThan( 0, (int) $row['est_cost_micros'], 'Unknown price counts the worst-case reservation.' );
		$this->assertSame( 'mystery-model-9', get_option( Gateway::PRICE_BLOCK ) );

		$again = $this->keyword_call();
		$this->assertWPError( $again );
		$this->assertSame( 'ailsug_price_unknown', $again->get_error_code() );
		$this->assertSame( 'budget', $again->get_error_data()['hold'] );

		// Entering the price lifts the block.
		Pricing::save_overrides(
			array(
				'mystery-model-9' => array(
					'provider' => 'mock',
					'input'    => 100000,
					'output'   => 100000,
				),
			)
		);
		$this->assertIsArray( $this->keyword_call() );
	}

	public function test_unknown_plausible_price_in_strict_mode_refuses_before_calling() {
		Settings::update(
			array(
				'budget_cap_micros' => 5000000,
				'strict_budget'     => true,
			)
		);
		add_filter( 'ailsug_pricing_table', '__return_empty_array' );
		Pricing::flush();
		$r = $this->keyword_call();
		$this->assertWPError( $r );
		$this->assertSame( 0, $this->gw->calls, 'No AI call may be made.' );
	}

	public function test_non_strict_mode_allows_unknown_prices() {
		Settings::update(
			array(
				'budget_cap_micros' => 5000000,
				'strict_budget'     => false,
			)
		);
		add_filter( 'ailsug_pricing_table', '__return_empty_array' );
		Pricing::flush();
		$this->assertIsArray( $this->keyword_call() );
		$this->assertSame( '0', $this->usage_rows()[0]['price_known'] );
	}

	public function test_budget_reached_holds_without_calling() {
		Settings::update( array( 'budget_cap_micros' => 1 ) );
		$r = $this->keyword_call();
		$this->assertWPError( $r );
		$this->assertSame( 'ailsug_budget', $r->get_error_code() );
		$this->assertSame( 0, $this->gw->calls );
	}

	public function test_invalid_json_retries_once_then_skips() {
		add_filter(
			'ailsug_mock_response',
			static function () {
				return array(
					'text'          => 'not json',
					'input_tokens'  => 50,
					'output_tokens' => 5,
					'provider'      => 'mock',
					'model'         => MockGateway::MODEL,
				);
			}
		);
		$r = $this->keyword_call();
		$this->assertWPError( $r );
		$this->assertSame( 'ailsug_bad_json', $r->get_error_code() );
		$this->assertSame( 2, $this->gw->calls );
		$this->assertCount( 2, $this->usage_rows(), 'Both paid calls are logged.' );
		$this->assertSame( 0, Budget::status()['reserved'] );
	}

	public function test_prevented_prompt_releases_reservation() {
		add_filter(
			'ailsug_mock_response',
			static function () {
				return new WP_Error( 'prompt_prevented', 'Prompt execution was prevented by a filter.', array( 'status' => 503 ) );
			}
		);
		$r = $this->keyword_call();
		$this->assertWPError( $r );
		$this->assertFalse( $r->get_error_data()['retry'] );
		$b = Budget::status();
		$this->assertSame( 0, $b['reserved'] );
		$this->assertSame( 0, $b['spent'] );
		$this->assertCount( 0, $this->usage_rows() );
	}

	public function test_network_error_holds_reservation() {
		add_filter(
			'ailsug_mock_response',
			static function () {
				return new WP_Error( 'prompt_network_error', 'timeout', array( 'status' => 503 ) );
			}
		);
		$r = $this->keyword_call();
		$this->assertTrue( $r->get_error_data()['retry'] );
		$this->assertGreaterThan( 0, Budget::status()['reserved'], 'May still be billed: held until the cleanup job releases it.' );
	}

	public function test_missing_usage_falls_back_to_estimate() {
		add_filter(
			'ailsug_mock_response',
			static function () {
				return array(
					'text'          => '{"primary_keyword":"x y","secondary_keywords":[],"search_intent":"informational","summary":"s"}',
					'input_tokens'  => 0,
					'output_tokens' => 0,
					'provider'      => 'mock',
					'model'         => MockGateway::MODEL,
				);
			}
		);
		$this->assertIsArray( $this->keyword_call() );
		$row = $this->usage_rows()[0];
		$this->assertSame( '1', $row['is_estimate'] );
		$this->assertSame( $row['est_input_tokens'], $row['input_tokens'] );
	}
}
