<?php
/**
 * AI & Cost tab sections: price table, calculator, usage dashboard, tips. Also budget notices.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\AI\Availability;
use AILSUG\AI\Gateway;
use AILSUG\AI\ModelResolver;
use AILSUG\Cost\Budget;
use AILSUG\Cost\Calibrator;
use AILSUG\Cost\Pricing;
use AILSUG\Cost\UsageLog;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cost transparency UI.
 */
final class Cost_Panel {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'ailsug_settings_ai_tab', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_ailsug_save_prices', array( __CLASS__, 'save_prices' ) );
		add_action( 'admin_post_ailsug_export_usage', array( __CLASS__, 'export_usage' ) );
		add_action( 'admin_post_ailsug_reset_usage', array( __CLASS__, 'reset_usage' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
	}

	/**
	 * When the cap or model settings change, parked jobs may run again.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public static function settings_changed( $old_value, $new_value ) {
		Settings::flush();
		$keys = array( 'budget_cap_micros', 'strict_budget', 'only_model', 'quality' );
		foreach ( $keys as $key ) {
			if ( ( $old_value[ $key ] ?? null ) !== ( $new_value[ $key ] ?? null ) ) {
				Budget::on_change();
				return;
			}
		}
	}

	/**
	 * Format micros as money, with the optional second currency.
	 *
	 * @param int|null $micros Micro-USD, null = unknown.
	 * @return string
	 */
	public static function money( $micros ) {
		if ( null === $micros ) {
			return __( 'unknown', 'ai-internal-link-suggester' );
		}
		$usd  = $micros / 1000000;
		$text = '$' . number_format_i18n( $usd, ( $usd > 0 && $usd < 0.01 ) ? 4 : 2 );
		$code = (string) Settings::get( 'currency2' );
		$rate = (float) Settings::get( 'currency2_rate' );
		if ( '' !== $code && $rate > 0 ) {
			$text .= ' (≈ ' . number_format_i18n( $usd * $rate, 2 ) . ' ' . $code . ')';
		}
		return $text;
	}

	/**
	 * Price per 1M tokens as a plain decimal for inputs.
	 *
	 * @param int $micros Micros.
	 * @return string
	 */
	private static function decimal( $micros ) {
		return rtrim( rtrim( number_format( $micros / 1000000, 6, '.', '' ), '0' ), '.' );
	}

	/**
	 * Render all sections (outside the settings form).
	 *
	 * @return void
	 */
	public static function render() {
		self::render_usage();
		self::render_prices();
		self::render_calculator();
		self::render_tips();
	}

	/**
	 * Models worth showing in the price table.
	 *
	 * @return array<string,string> model id => provider.
	 */
	private static function relevant_models() {
		$models = array();
		foreach ( array( 'economy', 'balanced', 'best' ) as $tier ) {
			$prediction = ModelResolver::predict( $tier );
			foreach ( $prediction['plausible'] as $pm ) {
				$models[ $pm[1] ] = $pm[0];
			}
		}
		$last = get_option( 'ailsug_last_model', array() );
		if ( is_array( $last ) && ! empty( $last['model'] ) ) {
			$models[ (string) $last['model'] ] = (string) $last['provider'];
		}
		$block = (string) get_option( Gateway::PRICE_BLOCK, '' );
		if ( '' !== $block ) {
			$models[ $block ] = $models[ $block ] ?? '';
		}
		foreach ( Pricing::overrides() as $id => $row ) {
			$models[ $id ] = $row['provider'];
		}
		$only = Availability::only_model();
		if ( $only ) {
			$models[ $only[1] ] = $only[0];
		}
		return $models;
	}

	/**
	 * Price table with editable overrides.
	 *
	 * @return void
	 */
	private static function render_prices() {
		$table     = Pricing::table();
		$overrides = Pricing::overrides();
		$models    = self::relevant_models();
		$connected = Availability::text_models();

		echo '<hr /><h2 id="ailsug-prices">' . esc_html__( 'Prices', 'ai-internal-link-suggester' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: date. */
				__( 'Prices are in US dollars per 1 million tokens (a token is roughly ¾ of a word). Built-in prices were checked on the providers\' official pages on %s. Prices change: please verify them and correct any that are out of date.', 'ai-internal-link-suggester' ),
				Pricing::as_of()
			)
		) . '</p>';
		echo '<p>';
		$names = array(
			'anthropic' => 'Anthropic',
			'google'    => 'Google',
			'openai'    => 'OpenAI',
		);
		foreach ( Pricing::sources() as $provider => $url ) {
			/* translators: %s: provider name. */
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sprintf( __( 'Verify %s prices', 'ai-internal-link-suggester' ), $names[ $provider ] ?? ucfirst( $provider ) ) ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ai-internal-link-suggester' ) . '</span></a> &nbsp; ';
		}
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ailsug_save_prices' );
		echo '<input type="hidden" name="action" value="ailsug_save_prices" />';
		echo '<table class="widefat striped ailsug-price-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Model', 'ai-internal-link-suggester' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Input price ($ / 1M)', 'ai-internal-link-suggester' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Output price ($ / 1M)', 'ai-internal-link-suggester' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Source', 'ai-internal-link-suggester' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reset', 'ai-internal-link-suggester' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $models ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Connect an AI provider to see the models you can use. You can still add a price below.', 'ai-internal-link-suggester' ) . '</td></tr>';
		}
		$i = 0;
		foreach ( $models as $model => $provider ) {
			$price = Pricing::price_for( $provider, $model );
			$key   = 'ailsug-price-' . $i;
			echo '<tr><th scope="row">' . esc_html( $model ) . '<br /><span class="description">' . esc_html( $provider ) . '</span>';
			printf( '<input type="hidden" name="prices[%1$d][model]" value="%2$s" /><input type="hidden" name="prices[%1$d][provider]" value="%3$s" />', (int) $i, esc_attr( $model ), esc_attr( $provider ) );
			echo '</th>';
			printf(
				'<td><label class="screen-reader-text" for="%1$s-in">%2$s</label><input type="text" inputmode="decimal" id="%1$s-in" name="prices[%3$d][input]" value="%4$s" class="small-text" /></td>',
				esc_attr( $key ),
				/* translators: %s: model id. */
				esc_html( sprintf( __( 'Input price for %s', 'ai-internal-link-suggester' ), $model ) ),
				(int) $i,
				esc_attr( $price ? self::decimal( $price['input'] ) : '' )
			);
			printf(
				'<td><label class="screen-reader-text" for="%1$s-out">%2$s</label><input type="text" inputmode="decimal" id="%1$s-out" name="prices[%3$d][output]" value="%4$s" class="small-text" /></td>',
				esc_attr( $key ),
				/* translators: %s: model id. */
				esc_html( sprintf( __( 'Output price for %s', 'ai-internal-link-suggester' ), $model ) ),
				(int) $i,
				esc_attr( $price ? self::decimal( $price['output'] ) : '' )
			);
			if ( ! $price ) {
				$source = '<span class="ailsug-badge is-orphan">' . esc_html__( 'Unknown: cost shown as unknown, estimate unavailable', 'ai-internal-link-suggester' ) . '</span>';
			} elseif ( 'user' === $price['origin'] ) {
				$source = '<span class="ailsug-badge is-manual">' . esc_html__( 'Your price', 'ai-internal-link-suggester' ) . '</span>';
			} else {
				/* translators: %s: date. */
				$source = esc_html( sprintf( __( 'Built-in, as of %s', 'ai-internal-link-suggester' ), Pricing::as_of() ) );
			}
			echo '<td>' . wp_kses_post( $source ) . '</td><td>';
			if ( isset( $overrides[ $model ] ) ) {
				printf( '<label><input type="checkbox" name="prices[%1$d][reset]" value="1" /> %2$s</label>', (int) $i, esc_html__( 'Use built-in', 'ai-internal-link-suggester' ) );
			}
			echo '</td></tr>';
			++$i;
		}//end foreach
		// Empty row to add a model.
		printf(
			'<tr><th scope="row"><label class="screen-reader-text" for="ailsug-new-model">%1$s</label><input type="text" id="ailsug-new-model" name="prices[%2$d][model]" placeholder="%3$s" class="regular-text" /><br /><label class="screen-reader-text" for="ailsug-new-provider">%4$s</label><input type="text" id="ailsug-new-provider" name="prices[%2$d][provider]" placeholder="%5$s" class="regular-text" /></th><td><label class="screen-reader-text" for="ailsug-new-in">%6$s</label><input type="text" inputmode="decimal" id="ailsug-new-in" name="prices[%2$d][input]" class="small-text" /></td><td><label class="screen-reader-text" for="ailsug-new-out">%7$s</label><input type="text" inputmode="decimal" id="ailsug-new-out" name="prices[%2$d][output]" class="small-text" /></td><td colspan="2">%8$s</td></tr>',
			esc_html__( 'Model ID', 'ai-internal-link-suggester' ),
			(int) $i,
			esc_attr__( 'Add a model ID', 'ai-internal-link-suggester' ),
			esc_html__( 'Provider', 'ai-internal-link-suggester' ),
			esc_attr__( 'provider', 'ai-internal-link-suggester' ),
			esc_html__( 'Input price', 'ai-internal-link-suggester' ),
			esc_html__( 'Output price', 'ai-internal-link-suggester' ),
			esc_html__( 'Optional: add a price for another model.', 'ai-internal-link-suggester' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save prices', 'ai-internal-link-suggester' ), 'secondary' );
		echo '</form>';

		$all = 0;
		foreach ( $connected as $list ) {
			$all += count( $list );
		}
		if ( $all > count( $models ) ) {
			echo '<details><summary>' . esc_html__( 'All text models your connected providers offer', 'ai-internal-link-suggester' ) . '</summary><ul class="ul-disc">';
			foreach ( $connected as $provider => $list ) {
				foreach ( $list as $model ) {
					$price = Pricing::price_for( $provider, $model );
					echo '<li>' . esc_html( $provider . ' — ' . $model . ': ' . ( $price ? '$' . self::decimal( $price['input'] ) . ' / $' . self::decimal( $price['output'] ) : __( 'price unknown', 'ai-internal-link-suggester' ) ) ) . '</li>';
				}
			}
			echo '</ul></details>';
		}
		unset( $table );
	}

	/**
	 * Site numbers to prefill the calculator.
	 *
	 * @return array
	 */
	public static function site_stats() {
		global $wpdb;
		$t     = Activator::tables();
		$types = array();
		$total = 0;
		foreach ( (array) Settings::get( 'post_types' ) as $type ) {
			$count   = wp_count_posts( $type );
			$n       = isset( $count->publish ) ? (int) $count->publish : 0;
			$obj     = get_post_type_object( $type );
			$types[] = array(
				'type'  => $type,
				'label' => $obj ? $obj->labels->name : $type,
				'count' => $n,
			);
			$total  += $n;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$stats = $wpdb->get_row( 'SELECT COUNT(*) AS n, AVG(word_count) AS words, SUM(keyword_source IN (\'manual\',\'seo_plugin\')) AS known FROM ' . esc_sql( $t['index'] ), ARRAY_A );
		$words = (int) round( (float) ( $stats['words'] ?? 0 ) );
		if ( $words <= 0 ) {
			$words = 800;
		}
		$share = (int) ( $stats['n'] ?? 0 ) > 0 ? 1 - ( (int) $stats['known'] / (int) $stats['n'] ) : 1.0;

		$recent     = 0;
		$types_list = (array) Settings::get( 'post_types' );
		if ( $types_list ) {
			$q      = new \WP_Query(
				array(
					'post_type'      => $types_list,
					'post_status'    => 'publish',
					'date_query'     => array( array( 'after' => '90 days ago' ) ),
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			$recent = (int) $q->found_posts;
		}

		return array(
			'types'         => $types,
			'posts'         => $total,
			'words'         => $words,
			'new_per_month' => (int) ceil( $recent / 3 ),
			'keyword_share' => round( $share, 2 ),
			'candidates'    => (int) Settings::get( 'shortlist_size', 15 ),
			'suggestions'   => (int) Settings::get( 'max_suggestions', 5 ),
			'tier'          => (string) Settings::get( 'quality', 'economy' ),
		);
	}

	/**
	 * Interactive calculator (cost.js fills the results).
	 *
	 * @return void
	 */
	private static function render_calculator() {
		$s = self::site_stats();
		echo '<hr /><h2 id="ailsug-calculator">' . esc_html__( 'Cost calculator', 'ai-internal-link-suggester' ) . '</h2>';
		echo '<p>' . esc_html__( 'Try different numbers to see what scanning your site would cost. Nothing is sent to AI here.', 'ai-internal-link-suggester' ) . '</p>';
		echo '<div class="ailsug-calc" data-stats="' . esc_attr( (string) wp_json_encode( $s ) ) . '"><div class="ailsug-calc-inputs">';

		$per_type = array();
		foreach ( $s['types'] as $type ) {
			$per_type[] = $type['label'] . ': ' . number_format_i18n( $type['count'] );
		}
		$fields = array(
			'posts'         => array( __( 'Pages to scan', 'ai-internal-link-suggester' ), $s['posts'], implode( ' · ', $per_type ) ),
			'words'         => array( __( 'Average words per page', 'ai-internal-link-suggester' ), $s['words'], __( 'Measured from your indexed pages.', 'ai-internal-link-suggester' ) ),
			'new_per_month' => array( __( 'New pages per month', 'ai-internal-link-suggester' ), $s['new_per_month'], __( 'Based on the last 90 days.', 'ai-internal-link-suggester' ) ),
			'candidates'    => array( __( 'Candidate pages per page', 'ai-internal-link-suggester' ), $s['candidates'], '' ),
			'suggestions'   => array( __( 'Suggestions per page', 'ai-internal-link-suggester' ), $s['suggestions'], '' ),
		);
		foreach ( $fields as $key => $f ) {
			printf(
				'<p><label for="ailsug-calc-%1$s">%2$s</label><br /><input type="number" min="0" id="ailsug-calc-%1$s" data-calc="%1$s" value="%3$d" class="small-text" />%4$s</p>',
				esc_attr( $key ),
				esc_html( $f[0] ),
				(int) $f[1],
				'' !== $f[2] ? ' <span class="description">' . esc_html( $f[2] ) . '</span>' : ''
			);
		}
		echo '<p><label for="ailsug-calc-tier">' . esc_html__( 'Quality', 'ai-internal-link-suggester' ) . '</label><br /><select id="ailsug-calc-tier" data-calc="tier">';
		foreach ( array(
			'economy'  => __( 'Economy', 'ai-internal-link-suggester' ),
			'balanced' => __( 'Balanced', 'ai-internal-link-suggester' ),
			'best'     => __( 'Best quality', 'ai-internal-link-suggester' ),
		) as $tier => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $tier ), selected( $s['tier'], $tier, false ), esc_html( $label ) );
		}
		echo '</select></p></div>';

		echo '<div class="ailsug-calc-results" aria-live="polite"><p class="ailsug-calc-sentence"><strong></strong></p><dl class="ailsug-calc-list">';
		$outputs = array(
			'full_scan' => __( 'First full scan', 'ai-internal-link-suggester' ),
			'per_post'  => __( 'Each newly published page', 'ai-internal-link-suggester' ),
			'monthly'   => __( 'Estimated monthly upkeep', 'ai-internal-link-suggester' ),
			'per_sugg'  => __( 'Cost per suggestion', 'ai-internal-link-suggester' ),
		);
		foreach ( $outputs as $key => $label ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd data-out="' . esc_attr( $key ) . '">—</dd>';
		}
		echo '</dl><p class="description ailsug-calc-note"></p></div>';

		echo '<details class="ailsug-calc-how"><summary>' . esc_html__( 'How is this calculated?', 'ai-internal-link-suggester' ) . '</summary><div class="ailsug-calc-assumptions"></div></details>';
		echo '</div>';
	}

	/**
	 * Usage dashboard.
	 *
	 * @return void
	 */
	private static function render_usage() {
		$b = Budget::status();
		echo '<hr /><h2 id="ailsug-usage">' . esc_html__( 'AI spend this month', 'ai-internal-link-suggester' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Figures are estimates based on token usage reported by the provider. Your provider\'s invoice is the source of truth.', 'ai-internal-link-suggester' ) . '</p>';

		if ( $b['cap'] > 0 ) {
			$pct = (int) round( $b['percent'] );
			printf(
				'<div class="ailsug-meter%5$s"><div class="ailsug-meter-label">%1$s</div><div class="ailsug-meter-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%2$d" aria-label="%3$s"><span style="width:%2$d%%"></span></div><p>%4$s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: spent, 2: cap, 3: percent. */
						__( '%1$s of %2$s estimated budget used (%3$s%%)', 'ai-internal-link-suggester' ),
						self::money( $b['spent'] ),
						self::money( $b['cap'] ),
						number_format_i18n( $pct )
					)
				),
				(int) $pct,
				esc_attr__( 'Share of the monthly budget used', 'ai-internal-link-suggester' ),
				$b['reserved'] > 0 ? esc_html(
					sprintf(
						/* translators: %s: amount. */
						__( 'Plus %s set aside for AI requests in progress.', 'ai-internal-link-suggester' ),
						self::money( $b['reserved'] )
					)
				) : '',
				$b['reached'] ? ' is-reached' : ( $b['alert'] ? ' is-alert' : '' )
			);
		} else {
			/* translators: %s: amount. */
			echo '<p>' . esc_html( sprintf( __( 'Estimated spend: %s. No monthly cap is set.', 'ai-internal-link-suggester' ), self::money( $b['spent'] ) ) ) . '</p>';
		}//end if

		$labels = array(
			'keyword' => __( 'Finding keywords', 'ai-internal-link-suggester' ),
			'rank'    => __( 'Choosing links', 'ai-internal-link-suggester' ),
		);
		echo '<div class="ailsug-usage-grid">';
		foreach ( array(
			'task'  => __( 'By task', 'ai-internal-link-suggester' ),
			'model' => __( 'By model', 'ai-internal-link-suggester' ),
			'day'   => __( 'By day', 'ai-internal-link-suggester' ),
		) as $by => $title ) {
			$rows = UsageLog::summary( $by );
			echo '<div><h3>' . esc_html( $title ) . '</h3>';
			if ( ! $rows ) {
				echo '<p class="description">' . esc_html__( 'No AI use this month yet.', 'ai-internal-link-suggester' ) . '</p></div>';
				continue;
			}
			$max = max( 1, max( array_map( 'intval', wp_list_pluck( $rows, 'cost' ) ) ) );
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html( $title ) . '</th><th scope="col">' . esc_html__( 'Requests', 'ai-internal-link-suggester' ) . '</th><th scope="col">' . esc_html__( 'Estimated cost', 'ai-internal-link-suggester' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$label = 'task' === $by ? ( $labels[ $row['label'] ] ?? $row['label'] ) : $row['label'];
				$width = (int) round( (int) $row['cost'] * 100 / $max );
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td><span class="ailsug-bar" style="width:%3$d%%" aria-hidden="true"></span> %4$s</td></tr>',
					esc_html( $label ),
					esc_html( number_format_i18n( (int) $row['calls'] ) ),
					(int) $width,
					esc_html( self::money( (int) $row['cost'] ) )
				);
			}
			echo '</tbody></table></div>';
		}//end foreach
		echo '</div>';

		$runs = UsageLog::recent_runs( 10 );
		echo '<h3>' . esc_html__( 'Recent AI runs: estimate vs actual', 'ai-internal-link-suggester' ) . '</h3>';
		if ( ! $runs ) {
			echo '<p class="description">' . esc_html__( 'No AI runs yet.', 'ai-internal-link-suggester' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Started', 'ai-internal-link-suggester' ), __( 'Type', 'ai-internal-link-suggester' ), __( 'Pages', 'ai-internal-link-suggester' ), __( 'Estimated before', 'ai-internal-link-suggester' ), __( 'Actual (estimated from usage)', 'ai-internal-link-suggester' ), __( 'Model that answered', 'ai-internal-link-suggester' ), __( 'Status', 'ai-internal-link-suggester' ) ) as $h ) {
				echo '<th scope="col">' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $runs as $run ) {
				$est = $run['est_high_micros'] > 0 ? self::money( (int) $run['est_low_micros'] ) . ' – ' . self::money( (int) $run['est_high_micros'] ) : __( 'unavailable', 'ai-internal-link-suggester' );
				$act = self::money( (int) $run['actual'] );
				if ( (int) $run['calls'] > 0 && ! (int) $run['price_known'] ) {
					$act .= ' ' . __( '(some prices unknown)', 'ai-internal-link-suggester' );
				}
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
					esc_html( get_date_from_gmt( $run['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
					esc_html( $run['type'] ),
					esc_html( number_format_i18n( (int) $run['total'] ) ),
					esc_html( $est ),
					esc_html( $act ),
					esc_html( (string) $run['models'] ),
					esc_html( $run['status'] )
				);
			}
			echo '</tbody></table>';
		}//end if

		$cal = array( Calibrator::factors( 'keyword' ), Calibrator::factors( 'rank' ) );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: samples for keywords, 2: samples for links. */
				__( 'Estimates improve automatically after 20 real requests per task (so far: %1$d for keywords, %2$d for links).', 'ai-internal-link-suggester' ),
				$cal[0]['samples'],
				$cal[1]['samples']
			)
		) . '</p>';

		$export = wp_nonce_url( admin_url( 'admin-post.php?action=ailsug_export_usage' ), 'ailsug_export_usage' );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export usage CSV', 'ai-internal-link-suggester' ) . '</a></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ailsug-inline-form">';
		wp_nonce_field( 'ailsug_reset_usage' );
		echo '<input type="hidden" name="action" value="ailsug_reset_usage" />';
		echo '<label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'Yes, reset this month\'s usage counters and log', 'ai-internal-link-suggester' ) . '</label> ';
		submit_button( __( 'Reset counters', 'ai-internal-link-suggester' ), 'delete small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Cost-saving tips.
	 *
	 * @return void
	 */
	private static function render_tips() {
		echo '<hr /><h2>' . esc_html__( 'Ways to spend less', 'ai-internal-link-suggester' ) . '</h2><ul class="ul-disc">';
		$tips = array(
			__( 'Use the Economy quality setting. It is more than good enough for keywords and link choices.', 'ai-internal-link-suggester' ),
			__( 'Only include the content types that matter (for example posts and pages, not every custom type).', 'ai-internal-link-suggester' ),
			__( 'Exclude thin or very old pages you do not want links to or from.', 'ai-internal-link-suggester' ),
			__( 'Set focus keywords in your SEO plugin or type them yourself: those pages skip the AI keyword step.', 'ai-internal-link-suggester' ),
			__( 'Leave automatic updates off and re-scan only pages that changed.', 'ai-internal-link-suggester' ),
		);
		foreach ( $tips as $tip ) {
			echo '<li>' . esc_html( $tip ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Save price overrides.
	 *
	 * @return void
	 */
	public static function save_prices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ai-internal-link-suggester' ) );
		}
		check_admin_referer( 'ailsug_save_prices' );
		$overrides = Pricing::overrides();
		$bundled   = Pricing::bundled()['models'] ?? array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized below.
		$rows = isset( $_POST['prices'] ) && is_array( $_POST['prices'] ) ? wp_unslash( $_POST['prices'] ) : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$model    = sanitize_text_field( (string) ( $row['model'] ?? '' ) );
			$provider = sanitize_key( (string) ( $row['provider'] ?? '' ) );
			if ( '' === $model || ! preg_match( '/^[A-Za-z0-9._\/:-]{1,128}$/', $model ) ) {
				continue;
			}
			if ( ! empty( $row['reset'] ) ) {
				unset( $overrides[ $model ] );
				continue;
			}
			$in  = trim( (string) ( $row['input'] ?? '' ) );
			$out = trim( (string) ( $row['output'] ?? '' ) );
			if ( '' === $in || '' === $out ) {
				continue;
			}
			$in_m  = Settings::to_micros( $in );
			$out_m = Settings::to_micros( $out );
			// Only store as an override when it differs from the built-in price.
			$builtin = isset( $bundled[ $model ] ) ? array( Pricing::decimal_to_micros( $bundled[ $model ]['input_per_million'] ), Pricing::decimal_to_micros( $bundled[ $model ]['output_per_million'] ) ) : null;
			if ( $builtin && ! isset( $overrides[ $model ] ) && $builtin[0] === $in_m && $builtin[1] === $out_m ) {
				continue;
			}
			$overrides[ $model ] = array(
				'provider' => $provider,
				'input'    => $in_m,
				'output'   => $out_m,
			);
		}//end foreach
		Pricing::save_overrides( $overrides );
		Budget::on_change();
		add_settings_error( 'ailsug', 'prices', __( 'Prices saved.', 'ai-internal-link-suggester' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '#ailsug-prices' );
		exit;
	}

	/**
	 * Usage CSV.
	 *
	 * @return void
	 */
	public static function export_usage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ai-internal-link-suggester' ) );
		}
		check_admin_referer( 'ailsug_export_usage' );
		$out = array();
		foreach ( UsageLog::month_rows() as $r ) {
			$out[] = array( $r['created_at'], $r['run_id'], $r['task'], $r['provider'], $r['model'], $r['requested_model'], $r['est_input_tokens'], $r['input_tokens'], $r['est_output_tokens'], $r['output_tokens'], number_format( $r['pre_cost_micros'] / 1000000, 6, '.', '' ), number_format( $r['est_cost_micros'] / 1000000, 6, '.', '' ), $r['price_known'] ? 'yes' : 'no', $r['is_estimate'] ? 'yes' : 'no', $r['post_id'] );
		}
		Csv::download(
			'internal-links-ai-usage-' . gmdate( 'Y-m' ) . '.csv',
			array( 'Time (UTC)', 'Run', 'Task', 'Provider', 'Model that answered', 'Preferred model', 'Estimated input tokens', 'Input tokens', 'Estimated output tokens', 'Output tokens', 'Estimated cost before (USD)', 'Cost from usage (USD)', 'Price known', 'Tokens estimated', 'Post ID' ),
			$out
		);
	}

	/**
	 * Reset this month's counters (confirmed).
	 *
	 * @return void
	 */
	public static function reset_usage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ai-internal-link-suggester' ) );
		}
		check_admin_referer( 'ailsug_reset_usage' );
		if ( ! empty( $_POST['confirm'] ) ) {
			UsageLog::reset_month();
			Budget::reset_period();
			add_settings_error( 'ailsug', 'reset', __( 'This month\'s usage counters were reset.', 'ai-internal-link-suggester' ), 'success' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
		}
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '#ailsug-usage' );
		exit;
	}

	/**
	 * Budget and price notices for administrators (plugin screens and the WP dashboard only).
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'dashboard' !== $screen->id && false === strpos( (string) $screen->id, Menu::SLUG ) ) ) {
			return;
		}
		$b    = Budget::status();
		$link = '<a href="' . esc_url( Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '">' . esc_html__( 'Settings → AI & Cost', 'ai-internal-link-suggester' ) . '</a>';
		if ( $b['reached'] ) {
			/* translators: %s: link to settings. */
			echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf( __( 'Internal Links: the estimated monthly AI budget is used up, so no new AI work will start. Your page index and link map keep working. Raise the cap in %s or wait until next month.', 'ai-internal-link-suggester' ), $link ) ) . '</p></div>';
		} elseif ( $b['alert'] ) {
			/* translators: 1: percent, 2: link to settings. */
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Internal Links: %1$s%% of the estimated monthly AI budget is used. See %2$s.', 'ai-internal-link-suggester' ), number_format_i18n( (int) round( $b['percent'] ) ), $link ) ) . '</p></div>';
		}
		$block = (string) get_option( Gateway::PRICE_BLOCK, '' );
		if ( '' !== $block && ! Pricing::price_for( '', $block ) ) {
			/* translators: 1: model id, 2: link to settings. */
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Internal Links: the AI model "%1$s" answered, but its price is unknown. Paid AI runs are paused until you enter its price in %2$s.', 'ai-internal-link-suggester' ), esc_html( $block ), $link ) ) . '</p></div>';
		}
	}
}
