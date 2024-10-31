<?php

namespace WooCommerce\NoFraud\Payment\Transactions;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Debug;

final class Transaction_Scheduler {
    static $self = null;

	/**
	 * Transaction reviews - refresh this many Orders in every cron run.
	 *
	 * @var string Transaction reviews - refresh this many Orders in every cron run.
	 */
	const TRANSACTION_REVIEWS_REFRESH_ORDER_COUNT = 10; // 10 Orders

	/**
	 * Transaction reviews - refresh after this much time has passed.
	 *
	 * @var string Transaction reviews - refresh after this much time has passed.
	 */
	const TRANSACTION_REVIEWS_REFRESH_ORDER_PERIOD = 1209600; // 2 Weeks

	/**
	 * Transaction reviews - refresh after this much time has passed.
	 *
	 * @var string Transaction reviews - refresh after this much time has passed.
	 */
	const TRANSACTION_REVIEWS_REFRESH_INTERVAL = 1800; // 30 Minutes

	/**
	 * Registers the class's hooks and actions with WordPress.
     *
     * @return Object Reference to singleton of this class
	 */
	public static function register() {
        if (!empty(self::$self)) {
            return self::$self;
        }

		$instance = new self();
        self::$self = $instance;

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Scheduler:register()',
        ]);

		// Add transaction reviews refreshable query.
		add_filter('woocommerce_order_data_store_cpt_get_orders_query', [$instance, 'add_refreshable_query'], 10, 2);
		// Hook the refresh function.
		add_action('wc_nofraud_transaction_reviews_refresh', [$instance, 'refresh_transaction_reviews']);
		// Add cron interval.
		add_filter('cron_schedules', [$instance, 'add_cron_interval']);

		// Activate cron hook, on plugin activation.
		register_activation_hook(NOFRAUD_PLUGIN_BASENAME, [$instance, 'activate_cron']);
		// Deactivate cron hook, on plugin deactivation.
		register_deactivation_hook(NOFRAUD_PLUGIN_BASENAME, [$instance, 'deactivate_cron']);

        return $instance;
	}

	/**
	 * Add query for getting Orders with refreshable reviews.
	 *
	 * @param array $query Args for WP_Query.
	 * @param array $query_variables Query variables from WC_Order_Query.
	 * @return array Modified query.
	 */
	public function add_refreshable_query( $query, $query_variables ) {
		if (empty($query_variables['wc_nofraud_transaction_reviews_refreshable'])) {
			return $query;
		}

		$query['meta_query'][] = [
			'key' => Constants::TRANSACTION_REVIEW_REFRESHABLE_KEY,
			'compare' => 'EXISTS',
		];
		return $query;
	}

	/**
	 * Refresh transaction reviews.
	 *
	 * @since 2.0.0
	 */
	public function refresh_transaction_reviews() {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Scheduler:refresh_transaction_reviews()',
        ]);

		// Find refreshable Orders.
		$order_period = time() - self::TRANSACTION_REVIEWS_REFRESH_ORDER_PERIOD;
		$order_ids = wc_get_orders([
			'limit' => self::TRANSACTION_REVIEWS_REFRESH_ORDER_COUNT, // Number of Orders.
			'orderby' => 'date', // Sort by date.
			'order' => 'DESC', // Recent first.
			'return' => 'ids', // Just return ids.
			'date_created' => '>' . $order_period, // Order's age must be less than 2 weeks old.
		]);
		if (empty($order_ids)) {
			return;
		}

		// Evaluate transactions of those Orders.
		$transaction_agent = new Transaction_Manager();
		foreach ($order_ids as $order_id) {
            // double check refreshable is on new dataset (don't process pre-4.2.0 orders again)
            $order_refreshable = Database::get_nf_data($order_id, Constants::TRANSACTION_REVIEW_REFRESHABLE_KEY, false);
            if (!empty($order_refreshable)) {
                $transaction_agent->evaluate_transaction($order_id);
            }
		}
	}

	/**
	 * Add cron interval.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array Updated cron schedules.
	 *
	 * @since 2.0.0
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['wc_nofraud_transaction_reviews_refresh_cron_interval'] = [
			'interval' => self::TRANSACTION_REVIEWS_REFRESH_INTERVAL,
			'display' => __('Every 30 Minutes', 'nofraud-protection'),
		];
		return $schedules;
	}

	/**
	 * Activate cron.
	 *
	 * @since 2.0.0
	 */
	public function activate_cron() {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Scheduler:activate_cron()',
        ]);

		// Scheduled transaction reviews' refresh event, if it does not exist already.
		if (wp_next_scheduled('wc_nofraud_transaction_reviews_refresh')) {
			return;
		}
		wp_schedule_event(time(), 'wc_nofraud_transaction_reviews_refresh_cron_interval', 'wc_nofraud_transaction_reviews_refresh');
	}

	/**
	 * Deactivate cron.
	 *
	 * @since 2.0.0
	 */
	public function deactivate_cron() {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Scheduler:deactivate_cron()',
        ]);

		// Find out when the last event was scheduled.
		$timestamp = wp_next_scheduled('wc_nofraud_transaction_reviews_refresh');
		// Unschedule previous event, if any.
		wp_unschedule_event($timestamp, 'wc_nofraud_transaction_reviews_refresh');
	}
}

Transaction_Scheduler::register();
