<?php

// Plugin: PayPal Payments

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Payment\Transactions\Constants;

final class NoFraud_Ppcp_credit_card_gateway extends NoFraud_Payment_Method {
    
    /**
     * WooCommerce Settings
     *
     * @var array
     */
    private $woocommerce_plugin_settings;
    
    /**
     * Constructor.
     */
    public function __construct() {
        //get plugin settings and decode
        $woocommerce_plugin_settings = get_option('woocommerce-ppcp-settings', []);
        
        $this->woocommerce_plugin_settings = $woocommerce_plugin_settings;
    }
    
    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);
        
        if(empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['paypal']['enabled'])) {
            return $transaction_data;
        }
        
        //sanity check data
        if(
        empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Authnet collect(): Transaction ID missing from Order Data.');
            return $transaction_data;
        }
        
        /*
            Information to collect:
                $transaction_data['cvvResultCode']
                $transaction_data['avsResultCode']
        
            Information to overwrite if available:
                $transaction_data['payment']['creditCard']['cardType']: Self-calculate
                $transaction_data['payment']['creditCard']['last4']
                $transaction_data['payment']['creditCard']['expirationDate']
                $transaction_data['payment']['creditCard']['bin']: Not available
        */
    
        
    
        return $transaction_data;
    }
}
