<?php

/*
 Plugin Name: WooCommerce Large Sessions
 Description: WooCommerce session handler for sites with large number of visitors, offload sessions from wp_options to wp_cache and a custom table
 Version: 1.0.0
 Author: Gerhard Potgieter
 Author URI: http://gerhardpotgieter.com
 Requires at least: 3.3
 Tested up to: 3.4

	Copyright: © 2015 Gerhard Potgieter
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html

 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_LARGE_SESSIONS_CACHE_GROUP', 'wc_session_id' );
define( 'WC_LARGE_SESSIONS_TABLE_NAME', 'woocommerce_sessions' );

Class WC_Large_Sessions {
	/**
	 * Plugin version
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Single instance of this class
	 * @var null|object
	 */
	protected static $instance = null;

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct() {
		add_filter( 'woocommerce_session_handler', array( $this, 'set_woocommerce_session_class' ), 10, 1 );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Set the WC session class to the Large Session class name
	 * @param string
	 */
	public function set_woocommerce_session_class( $class ) {
		require_once( 'includes/class-wc-large-session-handler.php' );
		$class = 'WC_Large_Session_Handler';
		return $class;
	}

	/**
	 * Install custom table to hold session data
	 * @return void
	 */
	public function install() {
		global $wpdb;
		$db_version = '1.0.0';

		$table_name = $wpdb->prefix . WC_LARGE_SESSIONS_TABLE_NAME;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
				session_id bigint(20) NOT NULL AUTO_INCREMENT,
				session_key char(32) NOT NULL,
				session_value longtext NOT NULL,
				session_expiry bigint(20) NOT NULL,
				UNIQUE KEY  session_id (session_id),
				PRIMARY KEY  session_key (session_key)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		add_option( 'wc_large_sessions_db_version', $db_version );

		// Clear previous cleanup sessions and schedule it to run every hour
		wp_clear_scheduled_hook( 'woocommerce_cleanup_sessions' );
		wp_schedule_event( time(), 'hourly', 'woocommerce_cleanup_sessions' );
	}
}

add_action( 'plugins_loaded', array( 'WC_Large_Sessions', 'get_instance' ), 0 );
register_activation_hook( __FILE__, array( 'WC_Large_Sessions', 'install' ) );

