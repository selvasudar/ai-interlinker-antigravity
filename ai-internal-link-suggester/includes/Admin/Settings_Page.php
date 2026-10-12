<?php
/**
 * Settings screen with tabs.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\AI\Availability;
use AILSUG\AI\ModelResolver;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the Settings screen through the Settings API.
 */
final class Settings_Page {

	const GROUP = 'ailsug_settings_group';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_ailsug_refresh_ai', array( __CLASS__, 'handle_refresh_ai' ) );
		add_action( 'admin_post_ailsug_export_settings', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_ailsug_import_settings', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Register the option with its sanitize callback.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Tabs.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'general'  => __( 'General', 'interlink-ai' ),
			'linking'  => __( 'Linking rules', 'interlink-ai' ),
			'ai'       => __( 'AI & Cost', 'interlink-ai' ),
			'advanced' => __( 'Advanced', 'interlink-ai' ),
			'status'   => __( 'Status', 'interlink-ai' ),
		);
	}

	/**
	 * Current tab from the query string (whitelisted).
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'interlink-ai' ) );
		}
		$tab = self::current_tab();
		echo '<div class="wrap ailsug-wrap">';
		echo '<h1>' . esc_html__( 'Interlink AI Settings', 'interlink-ai' ) . '</h1>';
		settings_errors();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'interlink-ai' ) . '">';
		foreach ( self::tabs() as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( Menu::url( 'settings', array( 'tab' => $key ) ) ),
				$tab === $key ? ' nav-tab-active' : '',
				$tab === $key ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'status' === $tab ) {
			self::render_status();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php" class="ailsug-settings-form">';
		settings_fields( self::GROUP );
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		submit_button();
		echo '</form>';

		if ( 'advanced' === $tab ) {
			self::render_tools();
		}
		if ( 'ai' === $tab ) {
			/**
			 * Renders additional AI & Cost sections (usage, prices, calculator), outside the settings form.
			 */
			do_action( 'ailsug_settings_ai_tab' );
		}
		echo '</div>';
	}

	/**
	 * Field name helper.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Hidden list of checkbox fields on a tab, so unchecked boxes save as false.
	 *
	 * @param string[] $keys Keys.
	 * @return void
	 */
	private static function bools( array $keys ) {
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::name( '_ailsug_bools' ) ), esc_attr( implode( ',', $keys ) ) );
	}

	/**
	 * Render a number row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @param int    $min   Min.
	 * @param int    $max   Max.
	 * @return void
	 */
	private static function number_row( $key, $label, $help, $min, $max ) {
		$id = 'ailsug-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="number" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" class="small-text" aria-describedby="%1$s-help" />',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			(int) Settings::get( $key ),
			(int) $min,
			(int) $max
		);
		echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p></td></tr>';
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @return void
	 */
	private static function checkbox_row( $key, $label, $help ) {
		$id = 'ailsug-' . $key;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s aria-describedby="%1$s-help" /> %4$s</label>',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			checked( (bool) Settings::get( $key ), true, false ),
			esc_html( $label )
		);
		echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p></td></tr>';
	}

	/**
	 * Render an ID-list row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @return void
	 */
	private static function ids_row( $key, $label, $help ) {
		$id = 'ailsug-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" aria-describedby="%1$s-help" />',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			esc_attr( implode( ', ', (array) Settings::get( $key ) ) )
		);
		echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p></td></tr>';
	}

	/**
	 * General tab.
	 *
	 * @return void
	 */
	private static function tab_general() {
		self::bools( array( 'post_types', 'statuses', 'use_seo_adapters', 'delete_on_uninstall' ) );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Content to include', 'interlink-ai' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Content to include', 'interlink-ai' ) . '</legend>';
		$selected = (array) Settings::get( 'post_types' );
		foreach ( Settings::default_post_types() as $type ) {
			$object = get_post_type_object( $type );
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( self::name( 'post_types' ) ),
				esc_attr( $type ),
				checked( in_array( $type, $selected, true ), true, false ),
				esc_html( $object ? $object->labels->name : $type )
			);
		}
		echo '<p class="description">' . esc_html__( 'Which kinds of content get keywords and link suggestions. Fewer types means a cheaper scan.', 'interlink-ai' ) . '</p></fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Statuses', 'interlink-ai' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Statuses', 'interlink-ai' ) . '</legend>';
		$statuses = (array) Settings::get( 'statuses' );
		$labels   = array(
			'publish' => __( 'Published', 'interlink-ai' ),
			'future'  => __( 'Scheduled', 'interlink-ai' ),
			'private' => __( 'Private', 'interlink-ai' ),
		);
		foreach ( $labels as $status => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( self::name( 'statuses' ) ),
				esc_attr( $status ),
				checked( in_array( $status, $statuses, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Links are only ever suggested to published pages.', 'interlink-ai' ) . '</p></fieldset></td></tr>';

		self::ids_row( 'excluded_posts', __( 'Never touch these pages', 'interlink-ai' ), __( 'Post IDs separated by commas. These pages are skipped as sources and as targets.', 'interlink-ai' ) );
		self::ids_row( 'excluded_terms', __( 'Skip these categories/tags', 'interlink-ai' ), __( 'Category or tag IDs separated by commas. Pages in them are skipped.', 'interlink-ai' ) );

		$mode = Settings::get( 'update_mode' );
		echo '<tr><th scope="row">' . esc_html__( 'When content changes', 'interlink-ai' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'When content changes', 'interlink-ai' ) . '</legend>';
		printf( '<label><input type="radio" name="%1$s" value="manual" %2$s /> %3$s</label><br />', esc_attr( self::name( 'update_mode' ) ), checked( 'manual', $mode, false ), esc_html__( 'Do nothing until I scan again (recommended, no surprise costs)', 'interlink-ai' ) );
		printf( '<label><input type="radio" name="%1$s" value="auto" %2$s /> %3$s</label>', esc_attr( self::name( 'update_mode' ) ), checked( 'auto', $mode, false ), esc_html__( 'Re-check a page automatically when its text changes (uses AI, costs money)', 'interlink-ai' ) );
		echo '</fieldset></td></tr>';

		self::checkbox_row( 'use_seo_adapters', __( 'Use keywords from my SEO plugin', 'interlink-ai' ), __( 'If Yoast SEO, Rank Math, All in One SEO or SEOPress already has a focus keyword for a page, use it and skip the AI step for that page (saves money).', 'interlink-ai' ) );
		self::checkbox_row( 'delete_on_uninstall', __( 'Delete all plugin data when uninstalling', 'interlink-ai' ), __( 'Off by default. Links already added to your pages are never removed.', 'interlink-ai' ) );
		echo '</table>';
	}

	/**
	 * Linking rules tab.
	 *
	 * @return void
	 */
	private static function tab_linking() {
		self::bools( array( 'new_tab' ) );
		echo '<table class="form-table" role="presentation">';
		self::number_row( 'max_suggestions', __( 'Suggestions per page', 'interlink-ai' ), __( 'The most link ideas to show for one page.', 'interlink-ai' ), 1, 20 );
		self::number_row( 'max_links_per_apply', __( 'New links per page at a time', 'interlink-ai' ), __( 'The most links added to one page in one go. Keeps pages readable.', 'interlink-ai' ), 1, 20 );
		self::number_row( 'max_same_target', __( 'Links to the same page', 'interlink-ai' ), __( 'How many times one page may link to the same target. 1 is best practice.', 'interlink-ai' ), 1, 5 );
		self::number_row( 'min_score', __( 'Minimum relevance score', 'interlink-ai' ), __( 'Hide suggestions scored below this (1–100). Higher means fewer but better ideas.', 'interlink-ai' ), 1, 100 );
		self::number_row( 'min_link_distance', __( 'Words between links', 'interlink-ai' ), __( 'Keep at least this many words between two new links.', 'interlink-ai' ), 0, 1000 );
		self::checkbox_row( 'new_tab', __( 'Open links in a new tab', 'interlink-ai' ), __( 'Usually not recommended for internal links.', 'interlink-ai' ) );
		self::ids_row( 'priority_targets', __( 'Priority pages', 'interlink-ai' ), __( 'Post IDs of your most important pages (for example product or service pages). They are suggested more often.', 'interlink-ai' ) );
		echo '</table>';
	}

	/**
	 * AI & Cost tab.
	 *
	 * @return void
	 */
	private static function tab_ai() {
		self::bools( array( 'strict_budget' ) );
		$status = Availability::status();

		echo '<h2>' . esc_html__( 'Connected AI', 'interlink-ai' ) . '</h2>';
		self::ai_status_box( $status );

		echo '<table class="form-table" role="presentation">';
		$quality = Settings::get( 'quality' );
		echo '<tr><th scope="row">' . esc_html__( 'Quality', 'interlink-ai' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Quality', 'interlink-ai' ) . '</legend>';
		$tiers = array(
			'economy'  => __( 'Economy (recommended): fast and cheapest. Finding keywords and matching pages does not need the most powerful AI.', 'interlink-ai' ),
			'balanced' => __( 'Balanced: a bit smarter at choosing anchors, roughly 10–20× the cost of Economy.', 'interlink-ai' ),
			'best'     => __( 'Best quality: the most capable models, the highest cost. Rarely worth it for linking.', 'interlink-ai' ),
		);
		foreach ( $tiers as $tier => $label ) {
			printf( '<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label><br />', esc_attr( self::name( 'quality' ) ), esc_attr( $tier ), checked( $tier, $quality, false ), esc_html( $label ) );
		}
		echo '<p class="description">' . esc_html__( 'This is a preference. If a preferred model is not available, WordPress may use another one you have connected. Costs are always worked out from the model that actually answered.', 'interlink-ai' ) . '</p>';
		echo '</fieldset></td></tr>';

		$only    = (string) Settings::get( 'only_model' );
		$options = array();
		foreach ( Availability::text_models() as $provider => $models ) {
			foreach ( $models as $model ) {
				$options[ $provider . ':' . $model ] = $provider . ' — ' . $model;
			}
		}
		if ( '' !== $only && ! isset( $options[ $only ] ) ) {
			$options[ $only ] = $only . ' ' . __( '(not currently available)', 'interlink-ai' );
		}
		echo '<tr><th scope="row"><label for="ailsug-only-model">' . esc_html__( 'Use only this model', 'interlink-ai' ) . '</label></th><td>';
		echo '<select id="ailsug-only-model" name="' . esc_attr( self::name( 'only_model' ) ) . '" aria-describedby="ailsug-only-model-help">';
		echo '<option value="">' . esc_html__( '— No, follow the quality setting —', 'interlink-ai' ) . '</option>';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $only, $value, false ), esc_html( $label ) );
		}
		echo '</select><p class="description" id="ailsug-only-model-help">' . esc_html__( 'Optional. Locks every request to one model so costs are predictable. If that model becomes unavailable, AI runs stop with a clear message instead of switching models.', 'interlink-ai' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="ailsug-budget-cap">' . esc_html__( 'Estimated monthly budget cap (USD)', 'interlink-ai' ) . '</label></th><td>';
		printf( '<input type="text" inputmode="decimal" id="ailsug-budget-cap" name="%1$s" value="%2$s" class="small-text" aria-describedby="ailsug-budget-cap-help" />', esc_attr( self::name( 'budget_cap' ) ), esc_attr( self::money( (int) Settings::get( 'budget_cap_micros' ) ) ) );
		echo '<p class="description" id="ailsug-budget-cap-help">' . esc_html__( 'No new AI work starts once the estimated spend for this month reaches this amount. Set 0 for no cap. This is a cap on estimated spend: your provider\'s invoice is the source of truth.', 'interlink-ai' ) . '</p></td></tr>';

		self::number_row( 'alert_threshold', __( 'Warn me at (% of budget)', 'interlink-ai' ), __( 'Show a warning on the dashboard when estimated spend reaches this share of the cap.', 'interlink-ai' ), 1, 100 );

		echo '<tr><th scope="row"><label for="ailsug-confirm">' . esc_html__( 'Ask me before runs above (USD)', 'interlink-ai' ) . '</label></th><td>';
		printf( '<input type="text" inputmode="decimal" id="ailsug-confirm" name="%1$s" value="%2$s" class="small-text" />', esc_attr( self::name( 'confirm_amount' ) ), esc_attr( self::money( (int) Settings::get( 'confirm_micros' ) ) ) );
		echo '</td></tr>';

		self::checkbox_row( 'strict_budget', __( 'Strict budget mode', 'interlink-ai' ), __( 'Recommended. When the price of the AI model is unknown, paid AI runs are paused until you enter its price below. If you turn this off, the budget cap cannot be enforced for models with unknown prices.', 'interlink-ai' ) );

		echo '<tr><th scope="row"><label for="ailsug-currency2">' . esc_html__( 'Also show costs in', 'interlink-ai' ) . '</label></th><td>';
		printf( '<input type="text" id="ailsug-currency2" name="%1$s" value="%2$s" class="small-text" maxlength="3" placeholder="INR" /> ', esc_attr( self::name( 'currency2' ) ), esc_attr( (string) Settings::get( 'currency2' ) ) );
		printf(
			'<label for="ailsug-currency2-rate">%1$s</label> <input type="text" inputmode="decimal" id="ailsug-currency2-rate" name="%2$s" value="%3$s" class="small-text" />',
			esc_html__( '1 USD =', 'interlink-ai' ),
			esc_attr( self::name( 'currency2_rate' ) ),
			esc_attr( (string) (float) Settings::get( 'currency2_rate' ) )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Three-letter currency code and your own exchange rate. The plugin never looks rates up online.', 'interlink-ai' ) . '</p></td></tr>';
		echo '</table>';
	}

	/**
	 * Advanced tab.
	 *
	 * @return void
	 */
	private static function tab_advanced() {
		self::bools( array( 'debug_log', 'verbose_log' ) );
		echo '<table class="form-table" role="presentation">';
		self::number_row( 'batch_size', __( 'Pages per batch', 'interlink-ai' ), __( 'How many pages are processed in each background step. Lower is gentler on slow hosting.', 'interlink-ai' ), 1, 50 );

		$schedule = Settings::get( 'schedule' );
		echo '<tr><th scope="row"><label for="ailsug-schedule">' . esc_html__( 'Automatic re-scan', 'interlink-ai' ) . '</label></th><td><select id="ailsug-schedule" name="' . esc_attr( self::name( 'schedule' ) ) . '">';
		foreach ( array(
			'off'    => __( 'Off (recommended)', 'interlink-ai' ),
			'daily'  => __( 'Daily: changed pages only', 'interlink-ai' ),
			'weekly' => __( 'Weekly: changed pages only', 'interlink-ai' ),
		) as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $schedule, $value, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Scheduled scans use AI and count against your budget.', 'interlink-ai' ) . '</p></td></tr>';

		self::number_row( 'shortlist_size', __( 'Candidate pages per page', 'interlink-ai' ), __( 'How many possible targets are shown to the AI for each page. More can find better links but costs more.', 'interlink-ai' ), 3, 50 );
		self::number_row( 'max_chunks', __( 'Paragraphs sent per page', 'interlink-ai' ), __( 'Only the most relevant paragraphs are sent, never the whole page.', 'interlink-ai' ), 1, 20 );
		self::number_row( 'excerpt_words', __( 'Opening words read for keywords', 'interlink-ai' ), __( 'How much of the start of each page the AI reads to find its keyword.', 'interlink-ai' ), 50, 1000 );
		self::checkbox_row( 'debug_log', __( 'Debug log', 'interlink-ai' ), __( 'Records errors and timings (never page content) to help with troubleshooting.', 'interlink-ai' ) );
		self::checkbox_row( 'verbose_log', __( 'Verbose debug log', 'interlink-ai' ), __( 'Warning: also records the text sent to the AI. Turn off when you are done.', 'interlink-ai' ) );
		echo '</table>';
	}

	/**
	 * Tools below the Advanced form: export/import, reset.
	 *
	 * @return void
	 */
	private static function render_tools() {
		echo '<hr /><h2>' . esc_html__( 'Tools', 'interlink-ai' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ailsug-inline-form">';
		wp_nonce_field( 'ailsug_export_settings' );
		echo '<input type="hidden" name="action" value="ailsug_export_settings" />';
		submit_button( __( 'Export settings', 'interlink-ai' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="ailsug-inline-form">';
		wp_nonce_field( 'ailsug_import_settings' );
		echo '<input type="hidden" name="action" value="ailsug_import_settings" />';
		echo '<label for="ailsug-import-file">' . esc_html__( 'Import settings file', 'interlink-ai' ) . '</label> ';
		echo '<input type="file" id="ailsug-import-file" name="ailsug_settings_file" accept="application/json,.json" /> ';
		submit_button( __( 'Import', 'interlink-ai' ), 'secondary', 'submit', false );
		echo '</form>';

		/**
		 * Renders additional tools (e.g. reset index).
		 */
		do_action( 'ailsug_settings_tools' );
	}

	/**
	 * Status tab.
	 *
	 * @return void
	 */
	private static function render_status() {
		global $wpdb;
		$status = Availability::status();

		echo '<h2>' . esc_html__( 'AI connection', 'interlink-ai' ) . '</h2>';
		self::ai_status_box( $status );

		$prediction = ModelResolver::predict();
		$last       = get_option( 'ailsug_last_model', array() );

		echo '<table class="widefat striped ailsug-status-table"><tbody>';
		self::status_row( __( 'Likely model for the next run', 'interlink-ai' ), $prediction['likely'] ? implode( ' — ', $prediction['likely'] ) : __( 'Unknown', 'interlink-ai' ) );
		self::status_row( __( 'Model that answered last', 'interlink-ai' ), is_array( $last ) && ! empty( $last['model'] ) ? $last['provider'] . ' — ' . $last['model'] : __( 'None yet', 'interlink-ai' ) );

		$tables = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live counts for a status screen.
		$counts = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . esc_sql( $tables['queue'] ) . ' GROUP BY status', OBJECT_K );
		$parts  = array();
		foreach ( (array) $counts as $row ) {
			$parts[] = $row->status . ': ' . number_format_i18n( (int) $row->n );
		}
		self::status_row( __( 'Background queue', 'interlink-ai' ), $parts ? implode( ', ', $parts ) : __( 'Empty', 'interlink-ai' ) );

		$next = wp_next_scheduled( 'ailsug_cron_tick' );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$cron = __( 'WordPress background tasks (WP-Cron) are turned off on this site. Use "Run now" on the dashboard, or ask your host to run cron.', 'interlink-ai' );
		} else {
			$cron = $next
				/* translators: %s: human time difference. */
				? sprintf( __( 'Working. Next background step in %s.', 'interlink-ai' ), human_time_diff( time(), $next ) )
				: __( 'Working. Nothing scheduled.', 'interlink-ai' );
		}
		self::status_row( __( 'Background tasks', 'interlink-ai' ), $cron );
		self::status_row( __( 'Database version', 'interlink-ai' ), (string) get_option( Activator::DB_OPTION, '—' ) . ' (' . __( 'expected', 'interlink-ai' ) . ' ' . AILSUG_DB_VERSION . ')' );
		self::status_row( __( 'Plugin version', 'interlink-ai' ), AILSUG_VERSION );
		echo '</tbody></table>';
	}

	/**
	 * One status table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function status_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * AI status box with next steps.
	 *
	 * @param array $status Availability status.
	 * @return void
	 */
	public static function ai_status_box( array $status ) {
		$ok = ! empty( $status['available'] );
		echo '<div class="ailsug-status-box ' . ( $ok ? 'is-ok' : 'is-warning' ) . '">';
		echo '<p><strong>' . ( $ok ? esc_html__( '✓ Ready', 'interlink-ai' ) : esc_html__( '✗ Not ready', 'interlink-ai' ) ) . '</strong> ' . esc_html( $status['message'] ) . '</p>';
		if ( ! empty( $status['providers'] ) ) {
			/* translators: %s: comma separated provider ids. */
			echo '<p>' . esc_html( sprintf( __( 'Connected providers: %s', 'interlink-ai' ), implode( ', ', $status['providers'] ) ) ) . '</p>';
		}
		if ( ! $ok && 'no_provider' === $status['code'] ) {
			echo '<p>' . esc_html__( 'To use AI features, install one of the official provider plugins and add your key in Settings → Connectors:', 'interlink-ai' ) . '</p><ul class="ul-disc">';
			foreach ( Availability::provider_plugins() as $slug => $label ) {
				$url = current_user_can( 'install_plugins' ) ? self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $slug ) : 'https://wordpress.org/plugins/' . $slug . '/';
				echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
			}
			echo '</ul><p>' . esc_html__( 'Everything else (page index, link map, orphan pages, keywords you type yourself) works without AI.', 'interlink-ai' ) . '</p>';
		}
		echo '<p>';
		if ( Availability::core_present() && current_user_can( 'manage_options' ) ) {
			echo '<a class="button" href="' . esc_url( Availability::connectors_url() ) . '">' . esc_html__( 'Open Settings → Connectors', 'interlink-ai' ) . '</a> ';
		}
		$refresh = wp_nonce_url( admin_url( 'admin-post.php?action=ailsug_refresh_ai' ), 'ailsug_refresh_ai' );
		echo '<a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'Check again', 'interlink-ai' ) . '</a>';
		if ( ! empty( $status['checked_at'] ) ) {
			/* translators: %s: human time difference. */
			echo ' <span class="description">' . esc_html( sprintf( __( 'Checked %s ago.', 'interlink-ai' ), human_time_diff( (int) $status['checked_at'] ) ) ) . '</span>';
		}
		echo '</p></div>';
	}

	/**
	 * Format micros as a plain decimal string.
	 *
	 * @param int $micros Micros.
	 * @return string
	 */
	public static function money( $micros ) {
		return rtrim( rtrim( number_format( $micros / 1000000, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Refresh AI availability.
	 *
	 * @return void
	 */
	public static function handle_refresh_ai() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'interlink-ai' ) );
		}
		check_admin_referer( 'ailsug_refresh_ai' );
		Availability::forget();
		delete_transient( 'ailsug_ai_models' );
		Availability::status( true );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : Menu::url( 'settings', array( 'tab' => 'status' ) ) );
		exit;
	}

	/**
	 * Download settings as JSON.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'interlink-ai' ) );
		}
		check_admin_referer( 'ailsug_export_settings' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=interlink-ai-settings.json' );
		echo wp_json_encode(
			array(
				'plugin'   => 'interlink-ai',
				'version'  => AILSUG_VERSION,
				'settings' => Settings::all(),
				'pricing'  => get_option( 'ailsug_pricing_overrides', array() ),
			),
			JSON_PRETTY_PRINT
		);
		exit;
	}

	/**
	 * Import settings from a JSON upload (values go through the normal sanitizer).
	 *
	 * @return void
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'interlink-ai' ) );
		}
		check_admin_referer( 'ailsug_import_settings' );

		$ok = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is checked with is_uploaded_file.
		$tmp = isset( $_FILES['ailsug_settings_file']['tmp_name'] ) ? (string) $_FILES['ailsug_settings_file']['tmp_name'] : '';
		if ( $tmp && is_uploaded_file( $tmp ) && filesize( $tmp ) < 200000 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded file.
			$data = json_decode( (string) file_get_contents( $tmp ), true );
			if ( is_array( $data ) && 'interlink-ai' === ( $data['plugin'] ?? '' ) && is_array( $data['settings'] ?? null ) ) {
				$input = $data['settings'];
				// Convert stored micros back to the decimal fields the sanitizer expects.
				if ( isset( $input['budget_cap_micros'] ) ) {
					$input['budget_cap'] = self::money( (int) $input['budget_cap_micros'] );
				}
				if ( isset( $input['confirm_micros'] ) ) {
					$input['confirm_amount'] = self::money( (int) $input['confirm_micros'] );
				}
				unset( $input['budget_cap_micros'], $input['confirm_micros'] );
				$input['_ailsug_bools'] = 'post_types,statuses';
				update_option( Settings::OPTION, Settings::sanitize( $input ), false );
				Settings::flush();
				if ( isset( $data['pricing'] ) && is_array( $data['pricing'] ) && class_exists( '\\AILSUG\\Cost\\Pricing' ) ) {
					\AILSUG\Cost\Pricing::save_overrides( $data['pricing'] );
				}
				$ok = true;
			}
		}//end if
		add_settings_error( 'ailsug', $ok ? 'imported' : 'import_failed', $ok ? __( 'Settings imported.', 'interlink-ai' ) : __( 'That file could not be imported. Choose a settings file exported by this plugin.', 'interlink-ai' ), $ok ? 'success' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Menu::url( 'settings', array( 'tab' => 'advanced' ) ) ) );
		exit;
	}
}
