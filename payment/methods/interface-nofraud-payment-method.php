<?php

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

interface NoFraud_Payment_Method_Interface {

	/**
	 * Collects data.
	 *
	 * @param array $order_data Order data.
	 * @param array $payment_data Payment data.
	 * @return array Transaction data.
	 *
	 * @since 2.0.0
	 */
	public function collect( $order_data, $payment_data );
}
