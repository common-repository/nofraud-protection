<?php

namespace WooCommerce\NoFraud\Pages;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\Hpos;
use WooCommerce\NoFraud\Payment\Transactions\Constants;
use WooCommerce\NoFraud\Payment\Transactions\Transaction_Manager;
use WooCommerce\NoFraud\Payment\Transactions\Transaction_Renderer;

final class Order_Pages {

	/**
	 * Orders page column id.
	 *
	 * @var string Orders page column id.
	 */
	const ORDERS_PAGE_COLUMN_ID = 'nofraud_transaction_review';

	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		$instance = new self();


		// "Orders" page
		// Adds "NoFraud decision" column header to "Orders" page after "Date" column.
		add_filter( 'manage_edit-shop_order_columns', [$instance, 'add_orders_page_list_header'] );
        add_filter( 'woocommerce_shop_order_list_table_columns', [$instance, 'add_orders_page_list_header'] );

        // Populates data into "NoFraud decision" column.
        add_action('manage_shop_order_posts_custom_column', [$instance, 'add_orders_page_list_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$instance, 'add_orders_page_list_column'], 10, 2);

		// "Order" page
		// Adds "NoFraud decision" section to "Order" page.
		add_action('woocommerce_admin_order_data_after_billing_address', [$instance, 'add_order_page_nofraud_section']);
  
		// Add hook for handling address changes
        add_action('woocommerce_order_object_updated_props', [$instance, 'edit_order_updated_props_action'], 50, 2);
    }

	/**
	 * Add "NoFraud decision" section to Order page.
	 *
	 * @param WC_Order $order WooCommerce Order.
	 *
	 * @since 2.0.0
	 */
	public function add_order_page_nofraud_section( $order ) {
		$transaction_manager = new Transaction_Manager();
		$transaction_review = $transaction_manager->evaluate_transaction($order->get_id());
		$transaction_status_markup = Transaction_Renderer::get_transaction_decision_markup($transaction_review);
		if (!$transaction_status_markup) {
			return;
		}
		echo '<p><strong>' . esc_html(__('NoFraud decision', 'nofraud-protection')) . ':</strong> <br/>' . wp_kses($transaction_status_markup, Transaction_Renderer::TRANSACTION_DECISION_MARKUP_ALLOWED_HTML) . '</p>';
	}

	/**
	 * Adds "NoFraud decision" column header to 'Orders' page "Date" column.
	 *
	 * @param string[] $columns Array of column names.
	 * @return string[] Array of column names.
	 *
	 * @since 2.0.0
	 */
	public function add_orders_page_list_header( $columns ) {
		$new_columns = [];
		foreach ($columns as $column_name => $column_info) {
			$new_columns[$column_name] = $column_info;
			if ('order_date' !== $column_name) {
				continue;
			}
			$new_columns[self::ORDERS_PAGE_COLUMN_ID] = __('NoFraud decision', 'nofraud-protection');
		}
		return $new_columns;
	}

	/**
	 * Populates data into "NoFraud decision" column.
	 *
	 * @param string $column_name
	 *
	 * @since 2.0.0
	 */
	public function add_orders_page_list_column( $column_name, $order = null ) {
		global $post;

		if (self::ORDERS_PAGE_COLUMN_ID !== $column_name) {
			return;
		}

        $orderID = null;
        if (!empty($post)) {
            $orderID = $post->ID;
        }
        if (!empty($order)) {
            $orderID = $order->get_id();
        }

		$transaction_manager = new Transaction_Manager();
		$transaction_review = $transaction_manager->evaluate_transaction($orderID);
		$transaction_status_markup = Transaction_Renderer::get_transaction_decision_markup($transaction_review);
		echo wp_kses($transaction_status_markup, Transaction_Renderer::TRANSACTION_DECISION_MARKUP_ALLOWED_HTML);
	}
    
    /**
     * Handle woocommerce_order_object_updated_props action
     *
     * @param WC_Order $order WooCommerce Order.
     * @param array $updated_props Array of props that were changed by update
     *
     * @since 3.0.0
     */
    public function edit_order_updated_props_action( $order, $updated_props ) {
        //check if shipping was updated
        if (!empty($updated_props) && is_array($updated_props) && in_array('shipping', $updated_props)) {
            //check for NoFraud determination (if this order was checked)
            //if no original transaction found, skip this action
            //this helps us only attempt to re-verify NoFraud enabled order updates
            $original_transaction_review_data = Database::get_nf_data($order->get_id(), Constants::TRANSACTION_REVIEW_KEY);
            if(empty($original_transaction_review_data)) {
                return;
            }
    
            //check if payment gateway for this payment method is enabled
            //do not update/rescreen if NoFraud is disabled for this gateway
            $order_data = $order->get_data();
            $payment_method = $order_data['payment_method'];
            $woocommerce_nofraud_payment_enabled = get_option(Gateways::getKeyByPaymentMethod($payment_method), 'notfound');
            if ('yes' !== $woocommerce_nofraud_payment_enabled) {
                if('notfound' !== $woocommerce_nofraud_payment_enabled) {
                    return;
                }
            }
    
            $transaction_manager = new Transaction_Manager();
    
            //update
            $transaction_manager->update_transaction($order, $updated_props);
        }
    }
}

Order_Pages::register();
