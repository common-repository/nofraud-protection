<?php

namespace WooCommerce\NoFraud\Payment\Transactions;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class Constants {

	/**
	 * "Transaction review refreshable" key to be stored in wp_options table.
	 *
	 * @var string "Transaction review refreshable" key to be stored in wp_options table.
	 */
	const TRANSACTION_REVIEW_REFRESHABLE_KEY = '_nofraud_transaction_review_refreshable';

	/**
	 * "Transaction data" key to be stored in wp_options table.
	 *
	 * @var string "Transaction data" key to be stored in wp_options table.
	 */
	const TRANSACTION_DATA_KEY = '_nofraud_transaction_data';

	/**
	 * "Transaction review" key to be stored in wp_options table.
	 *
	 * @var string "Transaction review" key to be stored in wp_options table.
	 */
	const TRANSACTION_REVIEW_KEY = '_nofraud_transaction_review';

    /**
     * "Transaction ID" key for Checkout Transaction ID in wp_postmeta table.
     *
     * @var string "Transaction ID" key for Checkout Transaction ID in wp_postmeta table.
     */
    const NF_CHECKOUT_TRANSACTION_ID_KEY = 'nf-transaction-id';

    /**
     * "Payment method" key to be stored in wp_postmeta table.
     *
     * @var string "WooCommerce Payment Method" key to be stored in wp_options table.
     */
    const ORDER_PAYMENT_METHOD = '_payment_method';
    
    /**
     * "Transaction Status" key to be stored in wp_postmeta table.
     *
     * @var string "Transaction Status" key to be stored in wp_options table.
     */
    const TRANSACTION_STATUS_KEY = '_nofraud_transaction_review_status';
    
    /**
     * "Transaction Disabled Status"
     *
     * @var string Gateweaydisabled means payment gateway was explicitly disabled in the options
     */
    const TRANSACTION_STATUS_GATEWAYDISABLED = 'gatewaydisabled';
    
    /**
     * Response from do_action of external plugin
     *
     * @var array Response data (varies by plugin)
     */
    const TRANSACTION_EXTERNAL_PLUGIN_RESPONSE = '_nofraud_external_plugin_response';

    /**
     * Workaround parameter for HPOS sync status overwrite
     *
     * @var string "Transaction data" key to be stored in wp_options table.
     */
    const TRANSACTION_STATUS_WORKAROUND_KEY = '_nofraud_transaction_status_workaround';
}
