<?php

// Plugin: WooCommerce Stripe Gateway

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use Stripe\Stripe;
use Stripe\Charge;
use WooCommerce\NoFraud\Common\Environment;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_Stripe extends NoFraud_Payment_Method {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$woocommerce_stripe_settings = get_option('woocommerce_stripe_settings', []);
		if (!empty($woocommerce_stripe_settings['testmode'])) {
			$testmode = $woocommerce_stripe_settings['testmode'];
		}
		if (!empty($testmode)) {
			$secret_key_key = 'yes' === $woocommerce_stripe_settings['testmode'] ? 'test_secret_key' : 'secret_key';
		}
		if (!empty($woocommerce_stripe_settings[$secret_key_key])) {
			$secret_key = $woocommerce_stripe_settings[$secret_key_key];
		}

		// Use key from NoFraud developer settings, if available.
		if (!isset($secret_key)) {
			$woocommerce_nofraud_developer_settings = get_option('woocommerce_nofraud_developer_settings', []);
			if (!empty($woocommerce_nofraud_developer_settings['secret_keys']['stripe'])) {
				$secret_key = $woocommerce_nofraud_developer_settings['secret_keys']['stripe'];
			}
		}

		// Setting Stripe key.
		if (empty($secret_key)) {
			return;
		}
		Stripe::setApiKey($secret_key);
		Stripe::setAppInfo(Environment::PLUGIN_NAME, Environment::PLUGIN_VERSION, Environment::SITE_DOMAIN);
	}

	public function collect( $order_data, $payment_data ) {
        Debug::add_debug_message([
            'function' => 'NoFraud_Stripe:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

		$transaction_data = parent::collect($order_data, $payment_data);
		if (empty(Stripe::getApiKey()) || empty($order_data['transaction_id'])) {
			return $transaction_data;
		}

		// Retrieve the Stripe Charge object.
		try {
			$charge = Charge::retrieve($order_data['transaction_id']);
		} catch (\Exception $exception) {
			error_log('NoFraud error: stripe: Could not retrieve Charge. ' . $exception->getMessage() );
		}
        
        // Check if source/payment_method_details contains
		if (is_object($charge) && isset($charge->source) && $charge->source && is_object($charge->source) && isset($charge->source->card) && $charge->source->card && is_object($charge->source->card)) {
            // Retrieve details via source
            $card = $charge->source->card;

            // Get "cvvResultCode".
            if (isset($card->cvc_check) && $card->cvc_check) {
                $cvc_check = 'pass' === $card->cvc_check ;
                $transaction_data['cvvResultCode'] = self::CVC_RESULT_CODE_MAPPING[$cvc_check];
            }

            // Get "avsResultCode".
            if (isset($card->address_zip_check) && $card->address_zip_check && isset($card->address_line1_check) && $card->address_line1_check) {
                $zip_check = 'pass' === $card->address_zip_check;
                $line_check = 'pass' === $card->address_line1_check;
                $transaction_data['avsResultCode'] = self::AVS_RESULT_CODE_MAPPING[$zip_check][$line_check];
            }

            // Get payment data.
            if (!empty($card->last4) && $card->last4 && 4 === strlen($card->last4)) {
                $transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($card->last4);
            }
            if (!empty($card->exp_month) && !empty($card->exp_year)) {
                $month = str_pad($card->exp_month, 2, '0', STR_PAD_LEFT);
                $year = substr($card->exp_year, -2);
                $transaction_data['payment']['creditCard']['expirationDate'] = sanitize_text_field($month . $year);
            }
            if (!empty($card->brand)) {
                $transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($card->brand);
            }
		}
        else if(is_object($charge) && isset($charge->payment_method_details) && $charge->payment_method_details && is_object($charge->payment_method_details) && isset($charge->payment_method_details->card) && $charge->payment_method_details->card && is_object($charge->payment_method_details->card)) {
            // Retrieve details via payment_method_details
            $card = $charge->payment_method_details->card;

            // Get "cvvResultCode".
            if (isset($card->checks) && $card->checks && isset($card->checks->cvc_check) && $card->checks->cvc_check) {
                $cvc_check = 'pass' === $card->checks->cvc_check;
                $transaction_data['cvvResultCode'] = self::CVC_RESULT_CODE_MAPPING[$cvc_check];
            }

            // Get "avsResultCode".
            if (isset($card->checks) && $card->checks
                && isset($card->checks->address_line1_check) && $card->checks->address_line1_check
                && isset($card->checks->address_postal_code_check) && $card->checks->address_postal_code_check
            ) {
                $zip_check = 'pass' === $card->checks->address_postal_code_check;
                $line_check = 'pass' === $card->checks->address_line1_check;
                $transaction_data['avsResultCode'] = self::AVS_RESULT_CODE_MAPPING[$zip_check][$line_check];
            }

            // Get payment data.
            if (!empty($card->last4) && $card->last4 && 4 === strlen($card->last4)) {
                $transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($card->last4);
            }
            if (!empty($card->exp_month) && !empty($card->exp_year)) {
                $month = str_pad($card->exp_month, 2, '0', STR_PAD_LEFT);
                $year = substr($card->exp_year, -2);
                $transaction_data['payment']['creditCard']['expirationDate'] = sanitize_text_field($month . $year);
            }
            if (!empty($card->brand)) {
                $transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($card->brand);
            }
        }

        Debug::add_debug_message([
            'function' => 'NoFraud_Stripe:collect():end',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

		return $transaction_data;
	}
}
