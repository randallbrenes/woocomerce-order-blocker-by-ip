<?php
/**
 * Define the WooCommerce settings page for the plugin.
 *
 * @package IPOrdersBlocker\OrderBlocker
 */

namespace IPOrdersBlocker\OrderBlocker;

use WC_Settings_Page;

class Settings extends WC_Settings_Page {

	/**
	 * The current limiter instance.
	 *
	 * @var \IPOrdersBlocker\OrderBlocker\Blocker
	 */
	private $limiter;

	/**
	 * Construct the settings page.
	 */
	public function __construct( Blocker $limiter ) {
		$this->id      = 'orders-blocker';
		$this->label   = __( 'IP Orders blocker', 'orders-blocker' );
		$this->limiter = $limiter;

		parent::__construct();
	}

	/**
	 * Get settings array.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$placeholders           = (array) $this->limiter->get_placeholders();
		$update_warning         = '';
		$available_placeholders = '';

		// Warn users if changes will impact current limits.
		if ( $this->limiter->has_orders_in_current_interval() ) {
			$update_warning  = '<div class="notice notice-info"><p>';
			$update_warning .= __( 'Please be aware that making changes to these settings will recalculate limits for current orders.', 'orders-blocker' );
			$update_warning .= '</p></div>';
		}

		// Build a list of available placeholders.
		if ( ! empty( $placeholders ) ) {
			$available_placeholders  = __( 'Available placeholders:', 'orders-blocker' ) . ' <var>';
			$available_placeholders .= implode( '</var>, <var>', array_keys( $placeholders ) );
			$available_placeholders .= '</var>';
		}

		$clientIp = ip_blocker_get_client_ip();


		return apply_filters( 'woocommerce_get_settings_' . $this->id, [
			[
				'id'   => 'orders-blocker-general',
				'type' => 'title',
				'name' => _x( 'IP Orders blocker settings', 'settings section title', 'orders-blocker' ),
				'desc' => __( 'Automatically avoid new orders once the number of orders by IP address limit has been met. Set Max # of orders to -1 to not limit', 'orders-blocker' ) . $update_warning,
			],
			[
				'id'   => 'orders-blocker-ip-info',
				'type' => 'title',
				'name' => _x( 'Client IP Info', 'settings section title', 'orders-blocker' ),
				'desc' => __( 'Getting client ip from: <strong>'.$clientIp['header'].'</strong> | Client IP: <strong>'.$clientIp['address'].'</strong>', 'orders-blocker' ),
			],
			[
				'id'      => Blocker::OPTION_KEY . '[enabled]',
				'name'    => __( 'Enable Orders blocker', 'orders-blocker' ),
				'desc'    => __( 'Prevent new orders once the specified Maximum # of orders has been met on the specified period of time', 'orders-blocker' ),
				'type'    => 'checkbox',
				'default' => false,
			],
			[
				'id'                => Blocker::OPTION_KEY . '[limit]',
				'name'              => __( 'Max. # of orders', 'orders-blocker' ),
				'desc_tip'          => __( 'Customers will be unable to checkout after this number of orders are made on the specified period of time.', 'orders-blocker' ),
				'desc'				=> __('Set -1 to no limit'),
				'type'              => 'number',
				'css'               => 'width: 150px;',
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'id'       => Blocker::OPTION_KEY . '[interval]',
				'name'     => __( 'Period of time', 'orders-blocker' ),
				'desc_tip' => __( 'The time to verify placed orders', 'orders-blocker' ),
				'type'     => 'select',
				'options'  => $this->get_intervals(),
			],
			[
				'id'   => 'orders-blocker-general',
				'type' => 'sectionend',
			],
			[
				'id'   => 'orders-blocker-messaging',
				'type' => 'title',
				'name' => _x( 'Customers display settings', 'settings section title', 'orders-blocker' ),
				'desc' => '<p>' . __( 'Customize the messages shown to customers once ordering is disabled.', 'orders-blocker' ) . '</p>' . $available_placeholders ? '<p>' . $available_placeholders . '</p>' : '',
			],
			[
				'id'       => Blocker::OPTION_KEY . '[customer_notice]',
				'name'     => __( 'Customer notice', 'orders-blocker' ),
				'desc_tip' => __( 'This message will appear on shop pages on the front-end of your site.', 'orders-blocker' ),
				'type'     => 'text',
				'default'  => __( 'You are allowed to put {limit} order by hour, please try later.', 'orders-blocker' ),
			],
			[
				'id'       => Blocker::OPTION_KEY . '[order_button]',
				'name'     => __( '"Place Order" button', 'orders-blocker' ),
				'desc_tip' => __( 'This text will replace the "Place Order" button on the checkout screen.', 'orders-blocker' ),
				'type'     => 'text',
				'default'  => __( 'You are allowed to put {limit} order by hour, please try later.', 'orders-blocker' ),
			],
			[
				'id'       => Blocker::OPTION_KEY . '[checkout_error]',
				'name'     => __( 'Checkout error message', 'orders-blocker' ),
				'desc_tip' => __( 'This error message will be displayed if a customer attempts to checkout once ordering is disabled.', 'orders-blocker' ),
				'type'     => 'text',
				'default'  => __( 'Ordering is temporarily disabled for this store.', 'orders-blocker' ),
			],
			[
				'id'   => 'orders-blocker-messaging',
				'type' => 'sectionend',
			],
		] );
	}

	/**
	 * Retrieve the available intervals for order limiting.
	 *
	 * @global $wp_locale
	 *
	 * @return array An array of interval names, keyed with their lengths in seconds.
	 */
	protected function get_intervals(): array {
		global $wp_locale;

		$intervals = [
			'min5'  => _x( 'Last 5 minutes ', 'order threshold interval', 'orders-blocker' ),
			'min15'  => _x( 'Last 15 minutes ', 'order threshold interval', 'orders-blocker' ),
			'min30'  => _x( 'Last 30 minutes ', 'order threshold interval', 'orders-blocker' ),
			'hourly'  => _x( 'Last Hour ', 'order threshold interval', 'orders-blocker' ),
			'daily'   => _x( 'Last day (24 hours) ', 'order threshold interval', 'orders-blocker' ),
			'weekly'  => sprintf(
				/* Translators: %1$s is the first day of the week, based on site configuration. */
				_x( 'Last 7 days', 'order threshold interval', 'orders-blocker' ),
				$wp_locale->get_weekday( get_option( 'start_of_week' ) )
			),
			'monthly' => _x( 'Last 30 days', 'order threshold interval', 'orders-blocker' ),
		];

		/**
		 * Filter the available intervals.
		 *
		 * @param array $intervals Available time intervals.
		 */
		return apply_filters( 'limit_orders_interval_select', $intervals );
	}
}
