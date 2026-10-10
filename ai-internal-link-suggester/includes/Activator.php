<?php
/**
 * Activation, deactivation and schema management.
 *
 * @package AILSUG
 */

namespace AILSUG;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/updates tables with dbDelta. Never contacts an AI provider.
 */
final class Activator {

	const DB_OPTION = 'ailsug_db_version';

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, array(), '', false );
		}
	}

	/**
	 * Deactivation hook: remove scheduled events only. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( array( 'ailsug_cron_tick', 'ailsug_cron_schedule', 'ailsug_cron_cleanup' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Run dbDelta when the stored schema version differs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== AILSUG_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Table names keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		global $wpdb;
		$names = array( 'index', 'links', 'suggestions', 'usage', 'queue', 'runs', 'applied', 'budget', 'reservations' );
		$out   = array();
		foreach ( $names as $name ) {
			$out[ $name ] = $wpdb->prefix . 'ailsug_' . $name;
		}
		return $out;
	}

	/**
	 * Create or update all tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		$sql = array();

		$sql[] = "CREATE TABLE {$t['index']} (
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT '',
  title text NOT NULL,
  primary_keyword varchar(191) NOT NULL DEFAULT '',
  keyword_source varchar(20) NOT NULL DEFAULT '',
  secondary_keywords text NOT NULL,
  search_intent varchar(20) NOT NULL DEFAULT '',
  summary varchar(255) NOT NULL DEFAULT '',
  content_hash char(32) NOT NULL DEFAULT '',
  ai_hash char(32) NOT NULL DEFAULT '',
  word_count int(10) unsigned NOT NULL DEFAULT 0,
  inbound_count int(10) unsigned NOT NULL DEFAULT 0,
  outbound_count int(10) unsigned NOT NULL DEFAULT 0,
  lang varchar(20) NOT NULL DEFAULT '',
  is_noindex tinyint(1) NOT NULL DEFAULT 0,
  is_builder tinyint(1) NOT NULL DEFAULT 0,
  indexed_at datetime DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'indexed',
  PRIMARY KEY  (post_id),
  KEY post_type (post_type),
  KEY inbound_count (inbound_count),
  KEY status (status),
  FULLTEXT KEY ailsug_ft (title,primary_keyword,secondary_keywords,summary)
) $c;";

		$sql[] = "CREATE TABLE {$t['links']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_post_id bigint(20) unsigned NOT NULL,
  target_post_id bigint(20) unsigned NOT NULL,
  anchor_text varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY source_post_id (source_post_id),
  KEY target_post_id (target_post_id)
) $c;";

		$sql[] = "CREATE TABLE {$t['suggestions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_post_id bigint(20) unsigned NOT NULL,
  target_post_id bigint(20) unsigned NOT NULL,
  anchor_text varchar(255) NOT NULL DEFAULT '',
  chunk_id varchar(32) NOT NULL DEFAULT '',
  context_snippet text NOT NULL,
  reason varchar(255) NOT NULL DEFAULT '',
  score tinyint(3) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  source_hash char(32) NOT NULL DEFAULT '',
  target_hash char(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  applied_at datetime DEFAULT NULL,
  run_id varchar(36) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY source_status (source_post_id,status),
  KEY target_post_id (target_post_id),
  KEY status_score (status,score),
  KEY run_id (run_id)
) $c;";

		$sql[] = "CREATE TABLE {$t['usage']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id varchar(36) NOT NULL DEFAULT '',
  task varchar(20) NOT NULL DEFAULT '',
  provider varchar(64) NOT NULL DEFAULT '',
  model varchar(128) NOT NULL DEFAULT '',
  requested_model varchar(128) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  est_input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  est_output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  pre_cost_micros bigint(20) NOT NULL DEFAULT 0,
  est_cost_micros bigint(20) NOT NULL DEFAULT 0,
  is_estimate tinyint(1) NOT NULL DEFAULT 1,
  price_known tinyint(1) NOT NULL DEFAULT 0,
  success tinyint(1) NOT NULL DEFAULT 1,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY created_at (created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t['queue']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id varchar(36) NOT NULL DEFAULT '',
  task varchar(20) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  locked_until datetime DEFAULT NULL,
  claim_token varchar(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY claim_token (claim_token),
  KEY run_status (run_id,status),
  KEY post_task (post_id,task)
) $c;";

		$sql[] = "CREATE TABLE {$t['runs']} (
  run_id varchar(36) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'scan',
  status varchar(20) NOT NULL DEFAULT 'running',
  total int(10) unsigned NOT NULL DEFAULT 0,
  est_low_micros bigint(20) NOT NULL DEFAULT 0,
  est_high_micros bigint(20) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (run_id),
  KEY created_at (created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t['applied']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  suggestion_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  batch_id varchar(36) NOT NULL DEFAULT '',
  content_hash_before char(32) NOT NULL DEFAULT '',
  content_hash_after char(32) NOT NULL DEFAULT '',
  inserted_markup text NOT NULL,
  anchor_text varchar(255) NOT NULL DEFAULT '',
  context_before varchar(255) NOT NULL DEFAULT '',
  context_after varchar(255) NOT NULL DEFAULT '',
  occurrence int(10) unsigned NOT NULL DEFAULT 0,
  revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
  applied_by bigint(20) unsigned NOT NULL DEFAULT 0,
  applied_at datetime NOT NULL,
  reverted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY suggestion_id (suggestion_id),
  KEY post_id (post_id),
  KEY batch_id (batch_id)
) $c;";

		$sql[] = "CREATE TABLE {$t['budget']} (
  period char(7) NOT NULL,
  cap_micros bigint(20) NOT NULL DEFAULT 0,
  spent_micros bigint(20) NOT NULL DEFAULT 0,
  reserved_micros bigint(20) NOT NULL DEFAULT 0,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (period)
) $c;";

		$sql[] = "CREATE TABLE {$t['reservations']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  period char(7) NOT NULL,
  amount_micros bigint(20) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'held',
  release_after datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_OPTION, AILSUG_DB_VERSION, false );
	}
}
