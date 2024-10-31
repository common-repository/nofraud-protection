<?php

// Plugin: WooCommerce Square

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use Square\SquareClient;
use Square\Environment;
final class NoFraud_Square_Credit_Card extends NoFraud_Payment_Method {

	/**
	 * Square client.
	 *
	 * @var \Square\SquareClient Square client.
	 */
	private $squareClient;

	/**
	 * Mapping from Square CVC status code to CVV checking status.
	 *
	 * @var array Mapping from Square CVC status code to CVV checking status.
	 */
	const SQUARE_CVC_STATUS_MAPPING = [
		'CVV_ACCEPTED' => true,
		'CVV_REJECTED' => false,
	];

	/**
	 * Mapping from Square AVS status code to AVS checking status.
	 *
	 * @var array Mapping from Square AVS status code to AVS checking status.
	 */
	const SQUARE_AVS_STATUS_MAPPING = [
		'AVS_ACCEPTED' => true,
		'AVS_REJECTED' => false,
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$woocommerce_square_settings = get_option('wc_square_settings', []);

		// Set Square Client's environment.
		$square_environment = isset($woocommerce_square_settings['enable_sandbox']) && 'yes' === $woocommerce_square_settings['enable_sandbox'] ? Environment::SANDBOX : Environment::PRODUCTION;
		$square_client_configuration = [
			'environment' => $square_environment,
		];

		// Set Square Client's access token using the plain sandbox token, if available.
		if (Environment::SANDBOX === $square_environment && !empty($woocommerce_square_settings['sandbox_token'])) {
			$square_client_configuration['accessToken'] = $woocommerce_square_settings['sandbox_token'];
		}

		// Set Square Client's access token using the encrypted token, if available.
		$woocommerce_square_access_tokens = get_option('wc_square_access_tokens', []);
		$square_encryption_utility_class = 'WooCommerce\Square\Utilities\Encryption_Utility';
		if (!empty($woocommerce_square_access_tokens[$square_environment]) && class_exists($square_encryption_utility_class)) {
			$encryption_utility = new $square_encryption_utility_class();
			try {
				$square_client_configuration['accessToken'] = $encryption_utility->decrypt_data($woocommerce_square_access_tokens[$square_environment]);
			} catch (\Exception $exception) {
				error_log('NoFraud error: square_credit_card: Could not decrypt access token. ' . $exception->getMessage() );
			}
		}

		// Use key from NoFraud developer settings, if available.
		if (!isset($square_client_configuration['accessToken'])) {
			$woocommerce_nofraud_developer_settings = get_option('woocommerce_nofraud_developer_settings', []);
			if (!empty($woocommerce_nofraud_developer_settings['secret_keys']['square_credit_card'])) {
				$square_client_configuration['accessToken'] = $woocommerce_nofraud_developer_settings['secret_keys']['square_credit_card'];
			}
		}

		// Initialize Square Client.
		if (empty($square_client_configuration['accessToken'])) {
			return;
		}
		$this->squareClient = new SquareClient($square_client_configuration);
	}

	public function collect( $order_data, $payment_data ) {
		$transaction_data = parent::collect($order_data, $payment_data);

		// Get payment data.
		if (!empty($payment_data['wc-square-credit-card-card-type'])) {
			$transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($payment_data['wc-square-credit-card-card-type']);
		}
		if (!empty($payment_data['wc-square-credit-card-last-four'])) {
			$transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($payment_data['wc-square-credit-card-last-four']);
		}
		if (!empty($payment_data['wc-square-credit-card-exp-month']) || !empty($payment_data['wc-square-credit-card-exp-year'])) {
			$month = str_pad($payment_data['wc-square-credit-card-exp-month'], 2, '0', STR_PAD_LEFT);
			$year = substr($payment_data['wc-square-credit-card-exp-year'], -2);
			$transaction_data['payment']['creditCard']['expirationDate'] = sanitize_text_field($month . $year);
		}

		// Early return if no valid Square client or Transaction ID.
		if (empty($this->squareClient) || empty($order_data['transaction_id'])) {
			return $transaction_data;
		}

		// Get the payment data.
		try {
			$payments_api = $this->squareClient->getPaymentsApi();
			$response = $payments_api->getPayment($order_data['transaction_id']);
			if ($response->isError()) {
				error_log('NoFraud error: square_credit_card: ' . print_r($response->getErrors(), true));
				return $transaction_data;
			}
			$response_body = $response->getBody();
			// See https://developer.squareup.com/reference/square/objects/Payment.
			$api_data = json_decode($response_body, true);
		} catch (\Exception $exception) {
			error_log('NoFraud error: square_credit_card: Could not retrieve payment API data. ' . $exception->getMessage() );
		}

		// See https://developer.squareup.com/reference/square/objects/CardPaymentDetails.
		// Available Square CVV statuses are: `CVV_ACCEPTED`, `CVV_REJECTED`, or `CVV_NOT_CHECKED`.
		if (isset($api_data['payment']['card_details']['cvv_status']) && isset(self::SQUARE_CVC_STATUS_MAPPING[$api_data['payment']['card_details']['cvv_status']])) {
			$cvv_check = self::SQUARE_CVC_STATUS_MAPPING[$api_data['payment']['card_details']['cvv_status']];
		}
		if (isset($cvv_check) && isset(self::CVC_RESULT_CODE_MAPPING[$cvv_check])) {
			$transaction_data['cvvResultCode'] = self::CVC_RESULT_CODE_MAPPING[$cvv_check];
		}
		// Available Square AVS statuses are: `AVS_ACCEPTED`, `AVS_REJECTED`, or `AVS_NOT_CHECKED`.
		if (isset($api_data['payment']['card_details']['avs_status']) && isset(self::SQUARE_AVS_STATUS_MAPPING[$api_data['payment']['card_details']['avs_status']])) {
			$avs_check = self::SQUARE_AVS_STATUS_MAPPING[$api_data['payment']['card_details']['avs_status']];
		}
		if (isset($avs_check) && isset(self::AVS_RESULT_CODE_MAPPING[$avs_check][$avs_check])) {
			$transaction_data['avsResultCode'] = self::AVS_RESULT_CODE_MAPPING[$avs_check][$avs_check];
		}

		// More card data. Overwrite the data from POST if we are able to get to this section.
		if (!empty($api_data['payment']['card_details']['card']['card_brand'])) {
			$transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($api_data['payment']['card_details']['card']['card_brand']);
		}
		if (!empty($api_data['payment']['card_details']['card']['last_4'])) {
			$transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($api_data['payment']['card_details']['card']['last_4']);
		}
		if (!empty($api_data['payment']['card_details']['card']['exp_month']) || !empty($api_data['payment']['card_details']['card']['exp_year'])) {
			$month = str_pad($api_data['payment']['card_details']['card']['exp_month'], 2, '0', STR_PAD_LEFT);
			$year = substr($api_data['payment']['card_details']['card']['exp_year'], -2);
			$transaction_data['payment']['creditCard']['expirationDate'] = sanitize_text_field($month . $year);
		}
		if (!empty($api_data['payment']['card_details']['card']['bin'])) {
			$transaction_data['payment']['creditCard']['bin'] = sanitize_text_field($api_data['payment']['card_details']['card']['bin']);
		}

		return $transaction_data;
	}
}
