<?php
/**
 * Define the WP Admin integration.
 *
 * @package IPOrdersBlocker\OrderBlocker
 */

namespace IPOrdersBlocker\OrderBlocker;

class Admin {
	protected $limiter;
	
	public function __construct( Blocker $limiter ) {
		$this->limiter = $limiter;
	}
	
	public function init() {
		$basename = plugin_basename( dirname( __DIR__ ) . '/orders-blocker.php' );

		add_action( 'admin_notices', [ $this, 'admin_notice' ] );

		add_filter( 'woocommerce_get_settings_pages', [ $this, 'register_page' ] );
		add_filter( sprintf( 'plugin_action_links_%s', $basename ), [ $this, 'links' ] );
		add_filter( 'woocommerce_debug_tools', [ $this, 'debug_tools' ] );
		add_action( 'woocommerce_delete_shop_order_transients', [ $this, 'reset_limiter' ] );
	}
	
	public function links( array $actions ): array {
		array_unshift( $actions, sprintf(
			'<a href="%s">%s</a>',
			$this->get_settings_url(),
			_x( 'Settings', 'plugin action link', 'orders-blocker' )
		) );

		return $actions;
	}
	
	public function register_page( array $pages ): array {
		$pages[] = new Settings( $this->limiter );

		return $pages;
	}
	
	public function admin_notice() {
		if ( ! $this->limiter->has_reached_limit() ) {
			return;
		}
	}

	public function debug_tools( array $tools ): array {
		$tools['limit_orders'] = [
			'name'     => __( 'Reset order limiting', 'orders-blocker' ),
			'button'   => __( 'Reset limiter', 'orders-blocker' ),
			'desc'     => __( 'Clear the cached order count. This may be needed if you\'ve changed your order limiting settings.', 'orders-blocker' ),
			'callback' => [ $this, 'reset_limiter' ],
		];

		return $tools;
	}
	
	public function reset_limiter() {
		$this->limiter->reset();
	}

	protected function get_settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=orders-blocker' );
	}

}
