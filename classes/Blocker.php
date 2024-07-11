<?php

namespace IPOrdersBlocker\OrderBlocker;

use DateTimeImmutable;
use Exceptions\EmptyOrderTypesError;
use Exceptions\OrdersNotAcceptedError;

class Blocker
{

	private $now;

	private $settings;

	const OPTION_KEY = 'limit_orders';

	const TRANSIENT_NAME = 'limit_orders_order_count';

	public function __construct(DateTimeImmutable $now = null)
	{
		$this->now = $now ? $now : current_datetime();
	}

	public function init()
	{
		add_action('woocommerce_new_order', [$this, 'regenerate_transient']);
		add_action('update_option_' . self::OPTION_KEY, [$this, 'reset_limiter_on_update'], 10, 2);
	}

	public function is_enabled(): bool
	{
		return (bool) $this->get_setting('enabled', false);
	}

	public function get_interval(): ?string
	{
		return $this->get_setting('interval', 'daily');
	}

	public function get_limit(): int
	{
		$limit = $this->get_setting('limit');

		return $this->is_enabled() && is_numeric($limit) && 0 <= $limit ? (int) $limit : -1;
	}

	public function get_message(string $setting): string
	{
		$settings = [
			'checkout_error',
			'customer_notice',
			'order_button',
		];

		if (!in_array($setting, $settings, true)) {
			return '';
		}

		$message = $this->get_setting($setting);

		if (null === $message) {
			$message = __('Ordering is currently disabled for this store.', 'orders-blocker');
		}

		$placeholders = $this->get_placeholders($setting, $message);

		return str_replace(array_keys($placeholders), array_values($placeholders), $message);
	}

	public function get_placeholders(string $setting = '', string $message = ''): array
	{
		$date_format  = get_option('date_format');
		$time_format  = get_option('time_format');
		$current      = $this->get_interval_start();
		$next         = $this->get_next_interval_start();
		$within24hr   = $next->getTimestamp() - $current->getTimestamp() < DAY_IN_SECONDS;
		$placeholders = [
			'{current_interval}'      => $current->format($within24hr ? $time_format : $date_format),
			'{current_interval:date}' => $current->format($date_format),
			'{current_interval:time}' => $current->format($time_format),
			'{limit}'                 => $this->get_limit(),
			'{next_interval}'         => $next->format($within24hr ? $time_format : $date_format),
			'{next_interval:date}'    => $next->format($date_format),
			'{next_interval:time}'    => $next->format($time_format),
			'{timezone}'              => $next->format('T'),
		];

		return apply_filters('limit_orders_message_placeholders', $placeholders, $setting, $message);
	}

	public function get_remaining_orders(): int
	{
		$limit = $this->get_limit();

		if (!$this->is_enabled() || -1 === $limit) {
			return -1;
		}

		$ipInfo = ip_blocker_get_client_ip();
		$ipAddress = $ipInfo['address'];

		$args = array(
			'status' => array(
				'wc-completed',
				'wc-on-hold',
				'wc-processing',
				'wc-pending'
			), 'date_created' =>  '>'.$this->getOrderIntervalTimestamp(), 'ip_address' => $ipAddress
		);
		
		
		$wcOrders = wc_get_orders($args);
		$ordersCount = count($wcOrders);
		
		if (false === $ordersCount) {
			$ordersCount = $this->regenerate_transient();
		}

		return max($limit - $ordersCount, 0);
	}

	public function getOrderIntervalTimestamp()
	{
		$interval = $this->get_interval();
		$now = $this->now->getTimestamp();

		$SECONDS_IN_MIN = 60;
		$HOUR = $SECONDS_IN_MIN * 60;
		$DAY = $HOUR * 24;
		$WEEK = $DAY * 7;
		$MONTH = $DAY * 30;

		switch ($interval) {
			case 'hourly':
				return $now - $HOUR;

			case 'daily':
				return $now - $DAY;

			case 'weekly':
				return $now - $WEEK;

			case 'monthly':
				return $now - $MONTH;

			default:
				$begins = substr($interval, 0, 3);
				if ($begins === 'min') {
					$minutes = intval(substr($interval, 3));

					return $now - ($minutes * $SECONDS_IN_MIN);
				}
		}

		return $now;
	}

	public function get_interval_start(): DateTimeImmutable
	{
		$interval = $this->get_setting('interval');
		$start    = $this->now;

		switch ($interval) {
			case 'hourly':
				$start = $start->setTime((int) $start->format('G'), 0, 0);
				break;

			case 'daily':
				$start = $start->setTime(0, 0, 0);
				break;

			case 'weekly':
				$start_of_week = (int) get_option('week_starts_on');
				$current_dow   = (int) $start->format('w');
				$diff          = $current_dow - $start_of_week;

				if (0 > $diff) {
					$diff += 7;
				}

				if (0 !== $diff) {
					$start = $start->sub(new \DateInterval('P' . $diff . 'D'));
				}

				$start = $start->setTime(0, 0, 0);
				break;

			case 'monthly':
				$start = $start->setDate((int) $start->format('Y'), (int) $start->format('m'), 1)
					->setTime(0, 0, 0);
				break;

			default:
				$begins = substr($interval, 0, 3);
				if ($begins === 'min') {
					$minutes = intval(substr($interval, 3));

					$start = $start->setTime((int) $start->format('G'), $minutes, 30, 0);
				}
		}

		return apply_filters('limit_orders_interval_start', $start, $interval);
	}

	public function get_next_interval_start(): DateTimeImmutable
	{
		$interval = $this->get_setting('interval');
		$current  = $this->get_interval_start();
		$start    = clone $current;

		switch ($interval) {
			case 'hourly':
				$start = $start->add(new \DateInterval('PT1H'));
				break;

			case 'daily':
				$start = $start->add(new \DateInterval('P1D'));
				break;

			case 'weekly':
				$start = $start->add(new \DateInterval('P7D'));
				break;

			case 'monthly':
				$start = $start->add(new \DateInterval('P1M'));
				break;

			default:
				$begins = substr($interval, 0, 3);
				if ($begins === 'min') {
					$minutes = intval(substr($interval, 3));

					$start = $start->add(new \DateInterval('PT' . $minutes . 'M'));
				}
		}

		return apply_filters('limit_orders_next_interval', $start, $current, $interval);
	}

	public function get_seconds_until_next_interval(): int
	{
		return $this->get_next_interval_start()->getTimestamp() - $this->now->getTimestamp();
	}

	public function has_orders_in_current_interval(): bool
	{
		return $this->get_limit() > $this->get_remaining_orders();
	}

	public function has_reached_limit(): bool
	{
		return 0 === $this->get_remaining_orders();
	}

	public function disable_ordering()
	{
		add_action('wp', [$this, 'customer_notice']);

		add_filter('woocommerce_is_purchasable', '__return_false');

		add_action('woocommerce_checkout_create_order', [$this, 'abort_checkout']);

		add_filter('woocommerce_order_button_html', [$this, 'order_button_html']);
	}

	public function abort_checkout()
	{
		throw new OrdersNotAcceptedError($this->get_message('checkout_error'));
	}

	public function order_button_html(): string
	{
		return '<p>' . wp_kses_post($this->get_message('order_button')) . '</p>';
	}

	public function customer_notice()
	{
		if (is_admin() || (!is_woocommerce() && !is_cart() && !is_checkout())) {
			return;
		}

		$message = $this->get_message('customer_notice');

		if (!wc_has_notice($message, 'notice')) {
			wc_add_notice($message, 'notice');
		}
	}

	public function regenerate_transient(): int
	{
		try {
			$count = $this->count_qualifying_orders();
		} catch (EmptyOrderTypesError $e) {
			add_action('init', [$this, 'regenerate_transient']);
			return 0;
		}

		set_transient(self::TRANSIENT_NAME, $count, $this->get_seconds_until_next_interval());

		return $count;
	}

	public function reset()
	{
		delete_transient(self::TRANSIENT_NAME);
	}

	public function reset_limiter_on_update($previous, $new)
	{
		if ($previous !== $new) {
			$this->reset();
		}
	}

	protected function count_qualifying_orders(): int
	{
		$count = apply_filters('limit_orders_pre_count_qualifying_orders', false, $this);

		if (false !== $count) {
			return (int) $count;
		}

		$order_types = wc_get_order_types('order-count');

		if (empty($order_types)) {
			throw new EmptyOrderTypesError('No order types were found.');
		}

		$orders = wc_get_orders([
			'type'         => $order_types,
			'date_created' => '>=' . $this->get_interval_start()->getTimestamp(),
			'return'       => 'ids',
			'limit'        => max($this->get_limit(), 1000),
		]);

		return count($orders);
	}

	protected function get_setting(string $setting, $default = null)
	{
		if (null === $this->settings) {
			$this->settings = get_option(self::OPTION_KEY, []);
		}

		return $this->settings[$setting] ?? $default;
	}
}
