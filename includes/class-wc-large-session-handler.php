<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Extends the WooCommerce Core session handlers to offloads sessions from wp_options
 * to a custom sessions tables. Integrates with WP_Cache for object cache support.
 *
 * Based on the core WC_Session class
 *
 * @class 		WC_Large_Session_Handler
 * @version		1.0.0
 * @package		WooCommerce/Classes
 * @category	Class
 * @author 		Gerhard Potgieter
 */
class WC_Large_Session_Handler extends WC_Session {

	/**
	 * Cookie name
	 * @var string
	 */
	private $_cookie;

	/**
	 * Session due to expire timestamp
	 * @var int
	 */
	private $_session_expiring;

	/**
	 * Session expiration timestamp
	 * @var int
	 */
	private $_session_expiration;

	/**
	 * Boolean based on whether cookie is present
	 * @var boolean
	 */
	private $_has_cookie = false;

	/**
	 * Custom session table name
	 * @var string
	 */
	private $_table;

	/**
	 * Constructor for the session class.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		global $wpdb;
		$this->_cookie = 'wp_woocommerce_session_' . COOKIEHASH;
		$this->_table = $wpdb->prefix . WC_LARGE_SESSIONS_TABLE_NAME;

		if ( $cookie = $this->get_session_cookie() ) {
			$this->_customer_id        = $cookie[0];
			$this->_session_expiration = $cookie[1];
			$this->_session_expiring   = $cookie[2];
			$this->_has_cookie         = true;

			// Update session if its close to expiring
			if ( time() > $this->_session_expiring ) {
				$this->set_session_expiration();
				$this->update_session_timestamp( $this->_customer_id, $this->_session_expiration );
			}
		} else {
			$this->set_session_expiration();
			$this->_customer_id = $this->generate_customer_id();
		}

		$this->_data = $this->get_session_data();

		// Actions
		add_action( 'woocommerce_set_cart_cookies', array( $this, 'set_customer_session_cookie' ), 10 );
		add_action( 'woocommerce_cleanup_sessions', array( $this, 'cleanup_sessions' ), 10 );
		add_action( 'shutdown', array( $this, 'save_data' ), 20 );
		add_action( 'wp_logout', array( $this, 'destroy_session' ) );
		if ( ! is_user_logged_in() ) {
			add_action( 'woocommerce_thankyou', array( $this, 'destroy_session' ) );
			add_filter( 'nonce_user_logged_out', array( $this, 'nonce_user_logged_out' ) );
		}
	}

	/**
	 * Sets the session cookie on-demand (usually after adding an item to the cart).
	 *
	 * Since the cookie name (as of 2.1) is prepended with wp, cache systems like batcache will not cache pages when set.
	 *
	 * Warning: Cookies will only be set if this is called before the headers are sent.
	 */
	public function set_customer_session_cookie( $set ) {
		if ( $set ) {
			// Set/renew our cookie
			$to_hash           = $this->_customer_id . $this->_session_expiration;
			$cookie_hash       = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie_value      = $this->_customer_id . '||' . $this->_session_expiration . '||' . $this->_session_expiring . '||' . $cookie_hash;
			$this->_has_cookie = true;

			// Set the cookie
			wc_setcookie( $this->_cookie, $cookie_value, $this->_session_expiration, apply_filters( 'wc_session_use_secure_cookie', false ) );
		}
	}

	/**
	 * Return true if the current user has an active session, i.e. a cookie to retrieve values
	 * @return boolean
	 */
	public function has_session() {
		return isset( $_COOKIE[ $this->_cookie ] ) || $this->_has_cookie || is_user_logged_in();
	}

	/**
	 * set_session_expiration function.
	 *
	 * @access public
	 * @return void
	 */
	public function set_session_expiration() {
		$this->_session_expiring    = time() + intval( apply_filters( 'wc_session_expiring', 60 * 60 * 47 ) ); // 47 Hours
		$this->_session_expiration  = time() + intval( apply_filters( 'wc_session_expiration', 60 * 60 * 48 ) ); // 48 Hours
	}

	/**
	 * Generate a unique customer ID for guests, or return user ID if logged in.
	 *
	 * Uses Portable PHP password hashing framework to generate a unique cryptographically strong ID.
	 *
	 * @return int|string
	 */
	public function generate_customer_id() {
		if ( is_user_logged_in() ) {
			return get_current_user_id();
		} else {
			require_once( ABSPATH . 'wp-includes/class-phpass.php');
			$hasher = new PasswordHash( 8, false );
			return md5( $hasher->get_random_bytes( 32 ) );
		}
	}

	/**
	 * Return the session data based on the customer cookie.
	 *
	 * @return bool|array
	 */
	public function get_session_cookie() {
		if ( empty( $_COOKIE[ $this->_cookie ] ) ) {
			return false;
		}

		list( $customer_id, $session_expiration, $session_expiring, $cookie_hash ) = explode( '||', $_COOKIE[ $this->_cookie ] );

		// Validate hash
		$to_hash = $customer_id . $session_expiration;
		$hash    = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );

		if ( $hash != $cookie_hash ) {
			return false;
		}

		return array( $customer_id, $session_expiration, $session_expiring, $cookie_hash );
	}

	/**
	 * get_session_data function.
	 *
	 * @access public
	 * @return array
	 */
	public function get_session_data() {
		if ( $this->has_session() ) {
			return (array) $this->get_session( $this->_customer_id, array() );
		}
		return array();
	}

	/**
	 * save_data function.
	 *
	 * @access public
	 * @return void
	 */
	public function save_data() {
		// Dirty if something changed - prevents saving nothing new
		if ( $this->_dirty && $this->has_session() ) {
			global $wpdb;
			$wpdb->replace(
				$this->_table,
				array(
					'session_key' => $this->_customer_id,
					'session_value' => maybe_serialize( $this->_data ),
					'session_expiry' => $this->_session_expiration
				),
				array(
					'%s',
					'%s',
					'%d'
				)
			);

			// Delete cache and then add fresh data to cache again
			wp_cache_delete( $this->_customer_id, WC_LARGE_SESSIONS_CACHE_GROUP );
			$expire = $this->_session_expiration - time();
			wp_cache_add( $this->_customer_id, $this->_data,  WC_LARGE_SESSIONS_CACHE_GROUP, $expire );
			$this->_dirty = false;
		}
	}

	/**
	 * Destroy all session data
	 * @return void
	 */
	public function destroy_session() {
		// Clear cookie
		wc_setcookie( $this->_cookie, '', time() - YEAR_IN_SECONDS, apply_filters( 'wc_session_use_secure_cookie', false ) );

		$this->delete_session( $this->_customer_id );

		// Clear cart
		wc_empty_cart();

		// Clear data
		$this->_data        = array();
		$this->_dirty       = false;
		$this->_customer_id = $this->generate_customer_id();
	}

	/**
	 * When a user is logged out, ensure they have a unique nonce by using the customer/session ID.
	 * @return string
	 */
	public function nonce_user_logged_out( $uid ) {
		return $this->has_session() && $this->_customer_id ? $this->_customer_id : $uid;
	}

	/**
	 * cleanup_sessions function.
	 *
	 * @access public
	 * @return void
	 */
	public function cleanup_sessions() {
		global $wpdb;

		if ( ! defined( 'WP_SETUP_CONFIG' ) && ! defined( 'WP_INSTALLING' ) ) {
			$now                = time();
			$expired_sessions   = array();

			$wc_expired_sessions = $wpdb->get_results( $wpdb->prepare( "
				SELECT session_id, session_key FROM $this->_table WHERE session_expiry < %d",
				$now
			) );

			// Dont do a cache flush, rather delete items from cache indivdually
			foreach ( $wc_expired_sessions as $session ) {
				wp_cache_delete( $session->session_key, WC_LARGE_SESSIONS_CACHE_GROUP );
				$expired_sessions[] = $session->session_id;  // Expires key
			}

			if ( ! empty( $expired_sessions ) ) {
				$expired_sessions_chunked = array_chunk( $expired_sessions, 100 );
				foreach ( $expired_sessions_chunked as $chunk ) {
					$session_ids = implode( ',', $chunk );
					$wpdb->query( $wpdb->prepare( "DELETE FROM $this->_table WHERE session_id IN ( %s )", $session_ids ) );
				}
			}
		}
	}

	/**
	 * Returns the session
	 * @param string $option
	 * @param mixed $default
	 * @return mixed
	 */
	function get_session( $customer_id, $default = false ) {
		global $wpdb;

		if ( defined( 'WP_SETUP_CONFIG' ) ) {
			return false;
		}

		// Try get it from the cache, it will return false if not present or if object cache not in use
		$value = wp_cache_get( $customer_id, WC_LARGE_SESSIONS_CACHE_GROUP );

		if ( false === $value ) {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT session_value FROM $this->_table WHERE session_key = %s",
					$customer_id
				)
			);

			if ( is_null( $value ) ) {
				$value = $default;
			}

			$expire = $this->_session_expiration - time();
			wp_cache_add( $customer_id, $value, WC_LARGE_SESSIONS_CACHE_GROUP, $expire );
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Delete the session from the cache and database
	 * @param  int $customer_id
	 * @return void
	 */
	function delete_session( $customer_id ) {
		global $wpdb;

		wp_cache_delete( $customer_id, WC_LARGE_SESSIONS_CACHE_GROUP );

		$wpdb->delete(
			$this->_table,
			array(
				'session_key' => $customer_id
			),
			array(
				'%s'
			)
		);
	}

	/**
	 * Update the session expiry timestamp
	 * @param  string $customer_id
	 * @param  int $timestamp
	 * @return void
	 */
	public function update_session_timestamp( $customer_id, $timestamp ) {
		global $wpdb;
		$wpdb->update(
			$this->_table,
			array(
				'session_expiry' => $timestamp
			),
			array(
				'session_key' => $customer_id
			),
			array(
				'%d',
				'%s'
			)
		);
	}
}