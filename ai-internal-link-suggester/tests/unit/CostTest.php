<?php
/**
 * Pricing, budget, estimator and calibration tests.
 *
 * @package AILSUG
 */

use AILSUG\Cost\Budget;
use AILSUG\Cost\Calibrator;
use AILSUG\Cost\Estimator;
use AILSUG\Cost\Pricing;
use AILSUG\Settings;

class CostTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Pricing::OVERRIDES );
		delete_option( Calibrator::OPTION );
		Settings::flush();
		Pricing::flush();
	}

	public function test_bundled_prices_and_lookup() {
		$p = Pricing::price_for( 'anthropic', 'claude-haiku-5-5' );
		$this->assertSame( 100000, $p['input'] );
		$this->assertSame( 500000, $p['output'] );
		// Dated snapshot and provider prefix resolve to the base model.
		$this->assertSame( 'claude-haiku-5-5', Pricing::price_for( 'anthropic', 'claude-haiku-5-5-20261001' )['id'] );
		$this->assertSame( 'gemini-3.5-flash-lite', Pricing::price_for( 'google', 'models/gemini-3.5-flash-lite' )['id'] );
		$this->assertNull( Pricing::price_for( 'x', 'totally-unknown' ) );
	}

	public function test_cost_math_is_integer_and_rounds_up() {
		// 1,000 tokens at $0.10 / 1M = $0.0001 = 100 micros.
		$this->assertSame( 100, Pricing::cost( 1000, 100000 ) );
		$this->assertSame( 1, Pricing::cost( 1, 100000 ) );
		$this->assertSame( 0, Pricing::cost( 0, 100000 ) );
		$this->assertSame( 600, Pricing::call_cost( 'anthropic', 'claude-haiku-5-5', 1000, 1000 ) );
	}

	public function test_overrides_win_and_can_add_models() {
		Pricing::save_overrides(
			array(
				'claude-haiku-5-5' => array(
					'provider' => 'anthropic',
					'input'    => 200000,
					'output'   => 900000,
				),
				'my-model'         => array(
					'provider' => 'custom',
					'input'    => 1000000,
					'output'   => 2000000,
				),
			)
		);
		$this->assertSame( 200000, Pricing::price_for( 'anthropic', 'claude-haiku-5-5' )['input'] );
		$this->assertSame( 'user', Pricing::price_for( 'custom', 'my-model' )['origin'] );
	}

	public function test_budget_reserve_reconcile_release() {
		Settings::update( array( 'budget_cap_micros' => 1000 ) );
		$a = Budget::reserve( 400 );
		$b = Budget::reserve( 400 );
		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( 0, $b );
		$this->assertSame( 0, Budget::reserve( 400 ), 'Third reservation would exceed the cap.' );

		$this->assertTrue( Budget::reconcile( $a, 100 ) );
		$this->assertFalse( Budget::reconcile( $a, 100 ), 'A reservation can only be closed once.' );
		$s = Budget::status();
		$this->assertSame( 100, $s['spent'] );
		$this->assertSame( 400, $s['reserved'] );

		$this->assertTrue( Budget::release( $b ) );
		$s = Budget::status();
		$this->assertSame( 0, $s['reserved'] );
		$this->assertSame( 900, $s['remaining'] );
	}

	public function test_hard_stop_at_cap() {
		Settings::update( array( 'budget_cap_micros' => 500 ) );
		$id = Budget::reserve( 500 );
		Budget::reconcile( $id, 500 );
		$this->assertTrue( Budget::status()['reached'] );
		$this->assertSame( 0, Budget::reserve( 1 ) );
		// Raising the cap resumes.
		Settings::update( array( 'budget_cap_micros' => 600 ) );
		$this->assertGreaterThan( 0, Budget::reserve( 1 ) );
	}

	public function test_many_workers_never_overspend() {
		Settings::update( array( 'budget_cap_micros' => 10000 ) );
		$ok = 0;
		for ( $i = 0; $i < 25; $i++ ) {
			$ok += Budget::reserve( 1000 ) ? 1 : 0;
		}
		$this->assertSame( 10, $ok );
		$s = Budget::status();
		$this->assertLessThanOrEqual( 10000, $s['spent'] + $s['reserved'] );
	}

	public function test_stale_reservations_are_released() {
		global $wpdb;
		$t  = AILSUG\Activator::tables();
		$id = Budget::reserve( 300 );
		$wpdb->update( $t['reservations'], array( 'created_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		$this->assertSame( 1, Budget::release_stale() );
		$this->assertSame( 0, Budget::status()['reserved'] );

		$held = Budget::reserve( 300 );
		Budget::hold( $held );
		$this->assertSame( 0, Budget::release_stale(), 'Held reservations stay until their hold time passes.' );
		$wpdb->update( $t['reservations'], array( 'release_after' => '2000-01-01 00:00:00' ), array( 'id' => $held ) );
		$this->assertSame( 1, Budget::release_stale() );
	}

	public function test_chars_per_token_by_script() {
		$this->assertSame( 4.0, Estimator::chars_per_token( 'Plain English text about gardening.' ) );
		$this->assertSame( 1.0, Estimator::chars_per_token( '日本語のテキストです。庭づくりについて。' ) );
		$this->assertSame( 2.0, Estimator::chars_per_token( 'Текст на русском языке о саде.' ) );
		$this->assertGreaterThan( Estimator::tokens( 'abcdefgh' ), Estimator::tokens( '日本語のテキスト' ) );
	}

	public function test_calibration_applies_after_twenty_samples() {
		$before = Estimator::planned_tokens( 'keyword', array( 'words' => 500 ) );
		for ( $i = 0; $i < 19; $i++ ) {
			Calibrator::record( 'keyword', 100, 150, 100, 100 );
		}
		$this->assertFalse( Calibrator::factors( 'keyword' )['active'] );
		$this->assertSame( $before, Estimator::planned_tokens( 'keyword', array( 'words' => 500 ) ) );
		Calibrator::record( 'keyword', 100, 150, 100, 100 );
		$f = Calibrator::factors( 'keyword' );
		$this->assertTrue( $f['active'] );
		$this->assertEqualsWithDelta( 1.5, $f['input'], 0.01 );
		$after = Estimator::planned_tokens( 'keyword', array( 'words' => 500 ) );
		$this->assertGreaterThan( $before['input'], $after['input'] );
	}

	public function test_calculator_gives_a_range() {
		$r = Estimator::calculate(
			array(
				'posts'         => 1000,
				'words'         => 900,
				'new_per_month' => 20,
				'tier'          => 'economy',
			)
		);
		$this->assertTrue( $r['full_scan']['known'] );
		$this->assertLessThan( $r['full_scan']['high'], $r['full_scan']['low'] );
		$this->assertGreaterThan( 0, $r['per_sugg']['high'] );
	}

	public function test_unknown_prices_give_no_estimate() {
		add_filter( 'ailsug_pricing_table', '__return_empty_array' );
		Pricing::flush();
		$r = Estimator::calculate( array( 'posts' => 10 ) );
		$this->assertFalse( $r['full_scan']['known'] );
		$this->assertNull( Estimator::worst_price() );
	}
}
