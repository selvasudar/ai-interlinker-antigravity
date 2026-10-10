<?php
/**
 * Settings tests.
 *
 * @package AILSUG
 */

use AILSUG\Settings;

class SettingsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
	}

	public function test_to_micros_is_exact() {
		$this->assertSame( 12500000, Settings::to_micros( '12.50' ) );
		$this->assertSame( 1, Settings::to_micros( '0.000001' ) );
		$this->assertSame( 0, Settings::to_micros( 'abc' ) );
		$this->assertSame( 3100000, Settings::to_micros( '3,1' ) );
	}

	public function test_defaults_are_sensible() {
		$this->assertSame( 'economy', Settings::get( 'quality' ) );
		$this->assertSame( array( 'publish' ), Settings::get( 'statuses' ) );
		$this->assertFalse( Settings::get( 'delete_on_uninstall' ) );
		$this->assertTrue( Settings::get( 'strict_budget' ) );
		$this->assertContains( 'post', Settings::get( 'post_types' ) );
		$this->assertNotContains( 'attachment', Settings::get( 'post_types' ) );
	}

	public function test_sanitize_whitelists_and_clamps() {
		$out = Settings::sanitize(
			array(
				'quality'        => 'premium',
				'schedule'       => 'weekly',
				'min_score'      => '500',
				'post_types'     => array( 'post', 'nope' ),
				'excluded_posts' => '3, 4,abc, 4',
				'only_model'     => 'anthropic:claude-haiku-5-5',
				'currency2'      => 'inr',
				'budget_cap'     => '25.5',
				'_ailsug_bools'  => 'new_tab',
			)
		);
		$this->assertSame( 'economy', $out['quality'] );
		$this->assertSame( 'weekly', $out['schedule'] );
		$this->assertSame( 100, $out['min_score'] );
		$this->assertSame( array( 'post' ), $out['post_types'] );
		$this->assertSame( array( 3, 4 ), $out['excluded_posts'] );
		$this->assertSame( 'anthropic:claude-haiku-5-5', $out['only_model'] );
		$this->assertSame( 'INR', $out['currency2'] );
		$this->assertSame( 25500000, $out['budget_cap_micros'] );
		$this->assertFalse( $out['new_tab'] );
	}

	public function test_update_keeps_canonical_micros() {
		Settings::update( array( 'budget_cap_micros' => 7000000 ) );
		$this->assertSame( 7000000, Settings::get( 'budget_cap_micros' ) );
	}

	public function test_rejects_bad_model_id() {
		$out = Settings::sanitize( array( 'only_model' => '<script>' ) );
		$this->assertSame( '', $out['only_model'] );
	}
}
