<?php
use Automattic\WooCommerce\Utilities\OrderUtil;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', 'nofraud-protection/nofraud-protection.php', true );
    }
} );

define('NOFRAUD_PLUGIN_COMMON_PATH', NOFRAUD_PLUGIN_PATH . '/common/');
define('NOFRAUD_PLUGIN_API_PATH', NOFRAUD_PLUGIN_PATH . '/api/');
define('NOFRAUD_PLUGIN_CHECKOUT_PATH', NOFRAUD_PLUGIN_PATH . '/checkout/');
define('NOFRAUD_PLUGIN_PAGES_PATH', NOFRAUD_PLUGIN_PATH . '/pages/');
define('NOFRAUD_PLUGIN_PAYMENT_PATH', NOFRAUD_PLUGIN_PATH . '/payment/');
define('NOFRAUD_PLUGIN_PAYMENT_TRANSACTIONS_PATH', NOFRAUD_PLUGIN_PAYMENT_PATH . 'transactions/');
define('NOFRAUD_PLUGIN_PAYMENT_METHODS_PATH', NOFRAUD_PLUGIN_PAYMENT_PATH . 'methods/');
