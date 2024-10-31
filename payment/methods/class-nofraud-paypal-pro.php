<?php

// Plugin: PayPal for WooCommerce

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Database;

final class NoFraud_Paypal_Pro extends NoFraud_Payment_Method {
    
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
        $woocommerce_plugin_settings = get_option('woocommerce_paypal_pro_settings', []);
        
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

        $transaction_data['cvvResultCode'] = Database::get_nf_data($order_data['id'], '_CVV2MATCH');
        $transaction_data['avsResultCode'] = Database::get_nf_data($order_data['id'], '_AVSCODE');
        
        if(!empty($_POST['paypal_pro-card-number']) && strlen($_POST['paypal_pro-card-number']) >= 4) {
            $transaction_data['payment']['creditCard']['last4'] = sanitize_text_field(substr($_POST['paypal_pro-card-number'], -4));
            $transaction_data['payment']['creditCard']['cardCode'] = sanitize_text_field($_POST['paypal_pro-card-cvc']);
            $creditCardNumberCleaned = sanitize_text_field(preg_replace("/\s+/", "", $_POST['paypal_pro-card-number']));
            $transaction_data['payment']['creditCard']['cardType'] = CreditCardTypeDetector::detect($creditCardNumberCleaned);
        }
        
        if(!empty($_POST['paypal_pro-card_expiration_month']) && !empty($_POST['paypal_pro-card_expiration_year'])) {
            $transaction_data['payment']['creditCard']['expirationDate'] = sprintf("%02d%02d", $_POST['paypal_pro-card_expiration_month'], $_POST['paypal_pro-card_expiration_year']);
        }

        return $transaction_data;
    }
}
