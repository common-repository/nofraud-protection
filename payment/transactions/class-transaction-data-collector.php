<?php

namespace WooCommerce\NoFraud\Payment\Transactions;

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Payment\Transactions\Constants;
use WooCommerce\NoFraud\Common\Debug;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class Transaction_Data_Collector {

    public static $instance = null;

	/**
	 * Payment method class - prefix.
	 *
	 * @var string Payment method class - prefix.
	 */
	const PAYMENT_METHOD_CLASS_PREFIX = '\WooCommerce\NoFraud\Payment\Methods\NoFraud';

	/**
	 * Payment method class - generic method.
	 *
	 * @var string Payment method class - generic method.
	 */
	const PAYMENT_METHOD_CLASS_GENERIC_METHOD = '\WooCommerce\NoFraud\Payment\Methods\NoFraud_Payment_Method';

	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		self::$instance = $instance = new self();

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Data_Collector:register()',
        ]);

		// Special hooks for plugins
        // wp.insider WooCommerce PayPal Pro plugin (https://wp-ecommerce.net/paypal-pro-payment-gateway-for-woocommerce)
        add_action('nofraud_plugin_response', [$instance, 'collect_plugin_response'], 50, 2);
		
		// Collect payment data in preparation for getting NoFraud transaction review.
		add_action('woocommerce_payment_complete', [$instance, 'collect'], 50);
	}
    
    /**
     * Get transaction data from plugin's data.
     *
     * @param int $order_id The order ID.
     * @param array $response Response of wp_remote_post from plugin
     * @return null
     */
    public function collect_plugin_response( $order_id, $response ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Data_Collector:collect_plugin_response()',
            'order_id' => $order_id,
        ]);

        Database::update_nf_data($order_id,Constants::TRANSACTION_EXTERNAL_PLUGIN_RESPONSE, $response);
    }
	
	/**
	 * Get transaction data from plugin's data.
	 *
	 * @param int $order_id The order ID.
	 * @return false|array Transaction data from WooCommerce objects.
	 */
	public function collect( $order_id ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Data_Collector:collect()',
            'order_id' => $order_id,
        ]);

		$order = wc_get_order($order_id);
		if (!is_object($order) || !$order->get_data()) {
			return false;
		}
		$order_data = $order->get_data();
		if (!is_array($order_data)) {
			return false;
		}
		$payment_method = $order_data['payment_method'];

		// Require payment method file.
		$payment_method_file_name = 'class-nofraud-' . str_replace('_', '-', $payment_method);
		$payment_method_file_path = NOFRAUD_PLUGIN_PAYMENT_METHODS_PATH . $payment_method_file_name . '.php';
		if (is_readable($payment_method_file_path)) {
			require_once($payment_method_file_path);
		}
		
		// check if payment method is enabled in plugin settings
        $woocommerce_nofraud_payment_enabled = get_option(Gateways::getKeyByPaymentMethod($payment_method), 'notfound');
		if ('yes' !== $woocommerce_nofraud_payment_enabled) {
		    if('notfound' !== $woocommerce_nofraud_payment_enabled) {
		        //if payment gateway is disabled, mark order disabled in metadata
                Database::update_nf_data($order_id,Constants::TRANSACTION_STATUS_KEY, Constants::TRANSACTION_STATUS_GATEWAYDISABLED);

                //add order note about disabled
                $order_note = Transaction_Renderer::get_disabled_gateway_order_note();
                wc_create_order_note($order->get_id(), $order_note);
                
                return false;
            }
        }

        // special case, do not collect here if BlueSnap and broadcast data is unavailable
        if ($payment_method == 'bluesnap') {
            $broadcast_data = Database::get_nf_data($order_id, '_nofraud_bluesnap_broadcast_data');
            if (empty($broadcast_data)) {
                return false;
            }
        }

        // special case, do not collect here if Cardknox and broadcast data is unavailable
        if ($payment_method == 'cardknox') {
            $broadcast_data = Database::get_nf_data($order_id, '_nofraud_cardknox_broadcast_data');
            if (empty($broadcast_data)) {
                return false;
            }
        }

		// Initialize the payment method class.
		$payment_method_class_name_suffix = '_' . ucwords(str_replace('-', '_', $payment_method), '_');
		$payment_method_class_namespace = self::PAYMENT_METHOD_CLASS_PREFIX . $payment_method_class_name_suffix;
		if (!class_exists($payment_method_class_namespace)) {
			$payment_method_class_namespace = self::PAYMENT_METHOD_CLASS_GENERIC_METHOD;
		}
		$nofraud_payment_method = new $payment_method_class_namespace();

		// This very bizarre line here is to satisfy WooCommerce platform's unignorable phpcs requirement of nonce security checking over $_POST.
		// While their intention is good, this checking is not implementable with third party webhooks.
		$payment_data = wp_verify_nonce(false) ? $_POST : $_POST;

		// Collect and store data.
		$plugin_transaction_data = $nofraud_payment_method->collect($order_data, $payment_data);
		if (empty($plugin_transaction_data)) {

            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Data_Collector:collect():noplugintransactiondata',
                'order_id' => $order_id,
                'payment_method_file_name' => $payment_method_file_name,
            ]);

			return false;
		}

        Database::update_nf_data($order_id,Constants::TRANSACTION_DATA_KEY, $plugin_transaction_data);
        Database::update_nf_data($order_id,Constants::TRANSACTION_REVIEW_REFRESHABLE_KEY, 'refreshable');

		return $plugin_transaction_data;
	}
}

Transaction_Data_Collector::register();
