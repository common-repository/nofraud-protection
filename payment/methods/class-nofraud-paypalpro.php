<?php

// Plugin: wp.insider WooCommerce PayPal Pro plugin (https://wp-ecommerce.net/paypal-pro-payment-gateway-for-woocommerce)

namespace WooCommerce\NoFraud\Payment\Methods;

use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Payment\Transactions\Constants;
use WooCommerce\NoFraud\Common\Database;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class NoFraud_Paypalpro extends NoFraud_Payment_Method {

	public function collect( $order_data, $payment_data ) {
		$transaction_data = parent::collect($order_data, $payment_data);
        
        if(empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['paypal']['enabled'])) {
            return $transaction_data;
        }
        
        //retrieve previously stored postmeta
        $pluginResponseData = Database::get_nf_data($order_data['id'], Constants::TRANSACTION_EXTERNAL_PLUGIN_RESPONSE);
        
        if(!empty($pluginResponseData)) {
            $transaction_data['cvvResultCode'] = $pluginResponseData['CVV2MATCH'];
            $transaction_data['avsResultCode'] = $pluginResponseData['AVSCODE'];
        }

		return $transaction_data;
	}
}
