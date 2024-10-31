<?php

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class NoFraud_Payment_Method implements NoFraud_Payment_Method_Interface {

	/**
	 * Mapping from CVV checking status to CVV result code.
	 *
	 * @var array Mapping from CVV checking status to CVV result code.
	 */
	const CVC_RESULT_CODE_MAPPING = [
		true => 'M',
		false => 'N',
	];

	/**
	 * Mapping from AVS checking status to AVS result code.
	 *
	 * @var array Mapping from AVS checking status to AVS result code.
	 */
	const AVS_RESULT_CODE_MAPPING = [
		true => [
			true => 'Y', // Zip pass, Line pass.
			false => 'Z', // Zip pass, Line no pass.
		],
		false => [
			true => 'A', // Zip no pass, Line pass.
			false => 'N', // Zip no pass, Line no pass.
		],
	];

	public function collect( $order_data, $payment_data ) {
		$transaction_data = [
			'avsResultCode' => 'U',
			'cvvResultCode' => 'U',
		];

		// Get payment data.
		if (isset($order_data['payment_method'])) {
			$transaction_data['payment']['method'] = sanitize_text_field($order_data['payment_method']);
		}
		if (!empty($payment_data['billing_cardtype'])) {
			$transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($payment_data['billing_cardtype']);
		}
		if (!empty($payment_data['billing_credircard']) && strlen($payment_data['billing_credircard']) >= 13) {
			$bin = substr($payment_data['billing_credircard'], 0, 6);
			$last4 = substr($payment_data['billing_credircard'], -4);
			$transaction_data['payment']['creditCard']['bin'] = sanitize_text_field($bin);
			$transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($last4);
		}
		if (!empty($payment_data['billing_expdatemonth']) && !empty($payment_data['billing_expdateyear'])) {
			$month = str_pad($payment_data['billing_expdatemonth'], 2, '0', STR_PAD_LEFT);
			$year = substr($payment_data['billing_expdateyear'], -2);
			$transaction_data['payment']['creditCard']['expirationDate'] = sanitize_text_field($month . $year);
		}
		if (!empty($payment_data['billing_ccvnumber'])) {
			$transaction_data['payment']['creditCard']['cardCode'] = sanitize_text_field($payment_data['billing_ccvnumber']);
		}

		return $transaction_data;
	}
}
