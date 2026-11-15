<?php
/**
 * Plugin Name:       TBT Poll
 * Description:       Polls for lessons, with live results. A vote is recorded in the TBT Notes activity log, so it shows in Results and earns Points.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            The Blue Tree
 * Text Domain:       tbt-poll
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTPL_VERSION', '0.1.0' );
define( 'TBTPL_DB_VERSION', '1' );
define( 'TBTPL_FILE', __FILE__ );
define( 'TBTPL_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTPL_URL', plugin_dir_url( __FILE__ ) );

require_once TBTPL_DIR . 'includes/class-tbtpl-format.php';
require_once TBTPL_DIR . 'includes/class-tbtpl-db.php';
require_once TBTPL_DIR . 'includes/class-tbtpl-polls.php';
require_once TBTPL_DIR . 'includes/class-tbtpl-admin.php';
require_once TBTPL_DIR . 'includes/class-tbtpl-rest.php';
require_once TBTPL_DIR . 'includes/class-tbtpl-shortcode.php';

register_activation_hook( __FILE__, array( 'TBTPL_DB', 'install' ) );

/**
 * Register this plugin on the TBT Hub Overview page. A filter on Hub's own
 * hook: when Hub is absent nothing applies it.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtpl_register_hub_item( $items ) {
	$items   = is_array( $items ) ? $items : array();
	$items[] = array(
		'slug'        => 'tbt-poll',
		'title'       => 'TBT Poll',
		'description' => 'Polls for lessons, with live results',
		'capability'  => 'manage_options',
		'url'         => admin_url( 'edit.php?post_type=tbt_poll' ),
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtpl_register_hub_item' );

/**
 * Name the tool in TBT Results, even before Results learns it.
 *
 * @param array $labels Slug => label.
 * @return array
 */
function tbtpl_tool_label( $labels ) {
	$labels         = is_array( $labels ) ? $labels : array();
	$labels['poll'] = 'Poll';
	return $labels;
}
add_filter( 'tbtr_tool_labels', 'tbtpl_tool_label' );

// Notes, Results, Points and Hub are checked when used, never here, so
// none of them being missing can stop this plugin loading.
add_action(
	'plugins_loaded',
	static function () {
		TBTPL_DB::maybe_upgrade();
		TBTPL_Polls::register();
		TBTPL_Admin::register();
		TBTPL_Rest::register();
		TBTPL_Shortcode::register();
	}
);

/* -------------------------------------------------------------------------
 * Public PHP API — used by TBT Lesson Builder. Keep these signatures.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'tbt_poll_parse' ) ) {
	/**
	 * Parse and validate the paste format.
	 *
	 * @param string $text Questions, each followed by lines "A. …", "B. …".
	 * @return array|WP_Error Questions, or code tbtpl_invalid with the first problem.
	 */
	function tbt_poll_parse( string $text ) {
		return TBTPL_Polls::api_parse( $text );
	}
}

if ( ! function_exists( 'tbt_poll_create' ) ) {
	/**
	 * Create a published poll. Administrators only.
	 *
	 * @param string $title     The lesson title.
	 * @param array  $questions [ [ 'q' => '…', 'a' => [ '…', … ] ], … ].
	 * @return int|WP_Error The poll's ID.
	 */
	function tbt_poll_create( string $title, array $questions ) {
		return TBTPL_Polls::api_create( $title, $questions );
	}
}

if ( ! function_exists( 'tbt_poll_shortcode' ) ) {
	/**
	 * The shortcode for a poll, e.g. '[tbt_poll id="123" domain="gi"]'.
	 *
	 * @param int    $poll_id Poll ID.
	 * @param string $domain  gi, pd, le or ''.
	 * @return string
	 */
	function tbt_poll_shortcode( int $poll_id, string $domain = '' ): string {
		return TBTPL_Polls::api_shortcode( $poll_id, $domain );
	}
}
