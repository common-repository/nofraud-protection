<?php

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Autoload dependencies.
if (is_readable(NOFRAUD_PLUGIN_PATH . '/vendor/autoload.php')) {
	require_once(NOFRAUD_PLUGIN_PATH . '/vendor/autoload.php');
}

require_once(NOFRAUD_PLUGIN_COMMON_PATH . 'class-database.php');
require_once(NOFRAUD_PLUGIN_COMMON_PATH . 'class-environment.php');
require_once(NOFRAUD_PLUGIN_COMMON_PATH . 'class-gateways.php');
require_once(NOFRAUD_PLUGIN_COMMON_PATH . 'class-creditcardtypedetector.php');
require_once(NOFRAUD_PLUGIN_COMMON_PATH . 'class-debug.php');
require_once(NOFRAUD_PLUGIN_API_PATH . 'class-api.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_TRANSACTIONS_PATH . 'class-constants.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_TRANSACTIONS_PATH . 'class-transaction-data-collector.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_TRANSACTIONS_PATH . 'class-transaction-renderer.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_TRANSACTIONS_PATH . 'class-transaction-manager.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_TRANSACTIONS_PATH . 'class-transaction-scheduler.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_METHODS_PATH . 'interface-nofraud-payment-method.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_METHODS_PATH . 'class-nofraud-payment-method.php');
require_once(NOFRAUD_PLUGIN_PAYMENT_METHODS_PATH . 'global-nofraud-payments-actions.php');
require_once(NOFRAUD_PLUGIN_PAGES_PATH . 'class-order-pages.php');
require_once(NOFRAUD_PLUGIN_PAGES_PATH . 'class-plugin-settings.php');
require_once(NOFRAUD_PLUGIN_PAGES_PATH . 'class-woocommerce-settings.php');
require_once(NOFRAUD_PLUGIN_PAGES_PATH . 'class-device-javascript-pages.php');

