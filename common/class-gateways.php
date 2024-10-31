<?php

namespace WooCommerce\NoFraud\Common;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class Gateways {
    
    /**
     * Prefix for gateway settings keys
     *
     * @var string Gateway key prefix
     */
    const NOFRAUD_GATEWAY_KEY_PREFIX = 'woocommerce_nofraud_payment_';
    
    /**
     * Gateways
     *
     * @var array Gateways
     */
    const NOFRAUD_SUPPORTED_GATEWAYS = [
        'acceptblue' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'acceptblue',
            'enabled' => true,
        ],
        'authorize_net' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'authorize_net',
            'enabled' => true,
        ],
        'bluesnap' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'bluesnap',
            'enabled' => true,
        ],
        'braintree' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'braintree',
            'enabled' => true,
        ],
        'cardknox' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'cardknox',
            'enabled' => true,
        ],
        'intuit' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'intuit',
            'enabled' => true,
        ],
        'nmi' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'nmi',
            'enabled' => true,
        ],
        'other' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'other',
            'enabled' => true,
        ],
        'paypal' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'paypal',
            'enabled' => true,
        ],
        'square' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'square',
            'enabled' => true,
        ],
        'stripe' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'stripe',
            'enabled' => true,
        ],
        'worldpay' => [
            'key' => self::NOFRAUD_GATEWAY_KEY_PREFIX . 'worldpay',
            'enabled' => true,
        ],
    ];
    
    /**
     * Supported plugins and associated gateways
     *
     * @var array Supported plugins
     */
    const NOFRAUD_SUPPORTED_PLUGINS = [
        'payment-gateway-accept-blue-for-woocommerce/acceptblue-payments.php' => [
            'gateway' => 'acceptblue',
            'payment_method' => 'acceptblue-cc',
        ],
        'authnet-cim-for-woo/woocommerce-cardpay-authnet.php' => [
            'gateway' => 'authorize_net',
            'payment_method' => 'authnet',
        ],
        'woocommerce-gateway-intuit-qbms/woocommerce-gateway-intuit-qbms.php' => [
            'gateway' => 'intuit',
            'payment_method' => 'intuit_payments_credit_card',
        ],
        'wp-nmi-gateway-pci-woocommerce/gateway.php' => [
            'gateway' => 'nmi',
            'payment_method' => 'nmi',
        ],
        'paypal-for-woocommerce/paypal-for-woocommerce.php' => [
            'gateway' => 'paypal',
            'payment_method' => 'paypal',
        ],
        'woo-authorize-net-gateway-aim/gateway.php' => [
            'gateway' => 'authorize_net',
            'payment_method' => 'authnet',
        ],
        'woocommerce-gateway-authorize-net-cim/woocommerce-gateway-authorize-net-cim.php' => [
            'gateway' => 'authorize_net',
            'payment_method' => 'authorize_net_cim_credit_card',
        ],
        'woocommerce-paypal-payments/woocommerce-paypal-payments.php' => [
            'gateway' => 'paypal',
            'payment_method' => 'ppcp-credit-card-gateway',
        ],
        'woocommerce-paypal-pro-payment-gateway/woo-paypal-pro.php' => [
            'gateway' => 'paypal',
            'payment_method' => 'paypalpro',
        ],
        'woocommerce-square/woocommerce-square.php' => [
            'gateway' => 'square',
            'payment_method' => 'square_credit_card',
        ],
        'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' => [
            'gateway' => 'stripe',
            'payment_method' => 'stripe',
        ],
        'worldpay-ecommerce-payments-for-woocommerce_1.0.6_registertoken/woocommerce-gateway-vantiv.php' => [
            'gateway' => 'worldpay',
            'payment_method' => 'vantiv_credit_card',
        ],
        'worldpay-ecommerce-payments-for-woocommerce_1.0.9_registertoken/woocommerce-gateway-vantiv.php' => [
            'gateway' => 'worldpay',
            'payment_method' => 'vantiv_credit_card',
        ],
        'woo-payment-gateway/braintree-payments.php' => [
            'gateway' => 'braintree',
            'payment_method' => 'braintree_cc',
        ],
        'bluesnap-payment-gateway-for-woocommerce/bluesnap-for-woocommerce.php' => [
            'gateway' => 'bluesnap',
            'payment_method' => 'bluesnap',
        ],
        'woocommerce-gateway-nmi/woocommerce-gateway-nmi.php' => [
            'gateway' => 'nmi',
            'payment_method' => 'nmi',
        ],
        'woo-cardknox-gateway/woocommerce-gateway-cardknox.php' => [
            'gateway' => 'cardknox',
            'payment_method' => 'cardknox',
        ],
    ];
    
    /**
     *
     * Figure out gateway through payment method
     *
     * @param string $payment_method
     *
     * @return string
     */
    public static function getGatewayByPaymentMethod($payment_method)
    {
        foreach(self::NOFRAUD_SUPPORTED_PLUGINS as $pluginLocation => $pluginValues) {
            if($pluginValues['payment_method'] == $payment_method) {
                return $pluginValues['gateway'];
            }
        }
        return 'other';
    }
    
    /**
     *
     * Retrieve Wordpress option_name by payment method
     *
     * @param string $payment_method
     *
     * @return string
     */
    public static function getKeyByPaymentMethod($payment_method)
    {
        return self::NOFRAUD_SUPPORTED_GATEWAYS[self::getGatewayByPaymentMethod($payment_method)]['key'];
    }
}
