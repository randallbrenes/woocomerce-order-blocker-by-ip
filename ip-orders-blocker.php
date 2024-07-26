<?php

/**
 * Plugin Name:       Order blocker by IP
 * Description:       Block new woocommerce orders by ip address
 * Version:           1.0.4
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Randall Brenes
 *
 * WC requires at least: 6.9
 * WC tested up to:      8.7
 *
 * @package IPOrdersBlocker\OrderBlocker
 */

namespace IPOrdersBlocker\OrderBlocker;

spl_autoload_register(function ($class) {
	$namespace = __NAMESPACE__ . '\\';
	$class     = (string) $class;

	if (0 !== strncmp($namespace, $class, strlen($namespace))) {
		return;
	}

	$filepath = str_replace($namespace, '', $class);
	$filepath = __DIR__ . '/classes/' . str_replace('\\', '/', $filepath) . '.php';

	if (is_readable($filepath)) {
		include_once $filepath;
	}
});

// Initialize the plugin.
add_action('init', function () {

	// Abort if WooCommerce hasn't loaded.
	if (!did_action('woocommerce_loaded')) {
		return;
	}

	$limiter = new Blocker();
	$admin   = new Admin($limiter);

	// Initialize hooks.
	$limiter->init();
	$admin->init();

	// Turn off ordering if we've reached the defined limits.
	if ($limiter->has_reached_limit()) {
		$limiter->disable_ordering();
	}
});

// WooCommerce HPOS compatibilty
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});


if(!function_exists("ip_blocker_get_client_ip")){

	function ip_blocker_get_client_ip()
	{
		$return = [
			'header' => '',
			'address' => ''
		];
		if (isset($_SERVER['HTTP_X_REAL_IP'])) {
			$return = [
				'header' => 'HTTP_X_REAL_IP',
				'address' => sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']))
			];
		} elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$return = [
				'header' => 'HTTP_X_FORWARDED_FOR',
				'address' => (string) rest_is_ip_address(trim(current(preg_split('/,/', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))))))
			];
		} elseif (isset($_SERVER['REMOTE_ADDR'])) {
			$return = [
				'header' => 'REMOTE_ADDR',
				'address' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
			];
		}
	
		foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED') as $key) {
			if (array_key_exists($key, $_SERVER) === true) {
				foreach (explode(',', $_SERVER[$key]) as $ip) {
					$ip = trim($ip);
	
					if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
						// return $ip;
						$return = [
							'header' => $key,
							'address' => $ip
						];
	
					}
				}
			}
		}
	
		return $return;
	}
}