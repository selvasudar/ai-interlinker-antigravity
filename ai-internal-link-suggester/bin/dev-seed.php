<?php
/**
 * Dev-only: seed a demo site. Run with: wp eval-file wp-content/plugins/ai-internal-link-suggester/bin/dev-seed.php
 *
 * Not shipped (bin/ is excluded from the release zip).
 *
 * @package AILSUG
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

$ailsug_topics = array(
	'Composting at Home'         => array( 'composting at home', 'compost bin', 'kitchen scraps' ),
	'Raised Bed Gardening'       => array( 'raised bed gardening', 'garden soil', 'drainage' ),
	'Growing Tomatoes'           => array( 'growing tomatoes', 'tomato plants', 'staking' ),
	'Organic Pest Control'       => array( 'organic pest control', 'aphids', 'neem oil' ),
	'Drip Irrigation Basics'     => array( 'drip irrigation', 'watering schedule', 'soaker hose' ),
	'Choosing Garden Soil'       => array( 'garden soil', 'soil ph', 'compost' ),
	'Companion Planting Guide'   => array( 'companion planting', 'marigolds', 'basil' ),
	'Winter Vegetable Gardening' => array( 'winter vegetables', 'cold frame', 'kale' ),
	'Pruning Fruit Trees'        => array( 'pruning fruit trees', 'apple trees', 'dormant season' ),
	'Starting Seeds Indoors'     => array( 'starting seeds indoors', 'grow lights', 'seed trays' ),
	'Mulching for Beginners'     => array( 'mulching', 'wood chips', 'weed control' ),
	'Attracting Pollinators'     => array( 'attracting pollinators', 'native flowers', 'bees' ),
);

$ailsug_ids = array();
foreach ( $ailsug_topics as $ailsug_title => $ailsug_kw ) {
	$ailsug_ids[ $ailsug_title ] = wp_insert_post(
		array(
			'post_title'  => $ailsug_title,
			'post_status' => 'publish',
			'post_type'   => 'post',
		)
	);
}

$ailsug_titles = array_keys( $ailsug_topics );
foreach ( $ailsug_topics as $ailsug_title => $ailsug_kw ) {
	$ailsug_i     = array_search( $ailsug_title, $ailsug_titles, true );
	$ailsug_other = $ailsug_topics[ $ailsug_titles[ ( $ailsug_i + 1 ) % count( $ailsug_titles ) ] ];
	$ailsug_third = $ailsug_topics[ $ailsug_titles[ ( $ailsug_i + 5 ) % count( $ailsug_titles ) ] ];
	$ailsug_link  = '';
	if ( 0 === $ailsug_i % 3 ) {
		$ailsug_link = ' See also <a href="' . esc_url( get_permalink( $ailsug_ids[ $ailsug_titles[ ( $ailsug_i + 2 ) % count( $ailsug_titles ) ] ] ) ) . '">this related guide</a>.';
	}
	$ailsug_content  = "<!-- wp:paragraph -->\n<p>This guide covers {$ailsug_kw[0]} step by step. Many gardeners start with {$ailsug_kw[1]} and learn about {$ailsug_kw[2]} along the way.{$ailsug_link}</p>\n<!-- /wp:paragraph -->\n\n";
	$ailsug_content .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why " . ucfirst( $ailsug_kw[1] ) . " matters</h2>\n<!-- /wp:heading -->\n\n";
	$ailsug_content .= "<!-- wp:paragraph -->\n<p>Before you begin, think about {$ailsug_other[0]} because it affects results. Good {$ailsug_third[0]} also helps, and so does patience.</p>\n<!-- /wp:paragraph -->\n\n";
	$ailsug_content .= "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Check your {$ailsug_kw[2]} weekly.</li>\n<!-- /wp:list-item --><!-- wp:list-item -->\n<li>Combine with {$ailsug_other[1]} where possible.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
	wp_update_post(
		array(
			'ID'           => $ailsug_ids[ $ailsug_title ],
			'post_content' => $ailsug_content,
		)
	);
}

update_post_meta( $ailsug_ids['Growing Tomatoes'], '_yoast_wpseo_focuskw', 'growing tomatoes' );
WP_CLI::success( count( $ailsug_ids ) . ' demo posts created.' );
