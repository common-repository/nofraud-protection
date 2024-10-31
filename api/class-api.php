<?php
namespace WooCommerce\NoFraud\Api;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Debug;
use WooCommerce\NoFraud\Common\Environment;
use WooCommerce\NoFraud\Payment\Transactions\Transaction_Scheduler;

class Api {

    /**
     * Registers the class's hooks and actions with WordPress.
     */
    public static function register() {
        $woocommerce_nofraud_enable_rest_api = get_option('woocommerce_nofraud_enable_rest_api', false);
        if (!empty($woocommerce_nofraud_enable_rest_api)) {
            add_action( 'rest_api_init', function () {
                register_rest_route( 'nf', '/transactions/refresh/', array(
                    'methods'  => 'GET',
                    'callback' => [ get_called_class(), 'transactions_refresh' ],
                ));
            });
        }
    }

    /**
     * Force refreshes transactions
     *
     * @return array
     */
    public static function transactions_refresh( \WP_REST_Request $request ) {
        $transaction_scheduler = Transaction_Scheduler::register();
        $transaction_scheduler->refresh_transaction_reviews();
        return [
            'success' => true,
        ];
    }

	/**
	 * Get Merchant.
	 *
	 * @return stdObject|false NoFraud Merchant.
	 *
	 * @since 2.1.0
	 */
	public static function get_merchant() {
		$api_key = get_option('woocommerce_nofraud_api_key', '');
		if (empty($api_key)) {
			return false;
		}

		$url = Environment::get_service_url('api') . '/v1/internal/merchants';
		$args = [
			'timeout' => 30,
			'redirection' => 5,
			'blocking' => true,
			'headers' => [
				'nf-token' => $api_key,
			],
		];

		$response = wp_remote_get($url, $args);
		return self::get_response_body($response);
	}
    
    /**
     * Get transaction screening result
     *
     * @param int $transaction_review_id NoFraud transaction review id.
     * @return stdObject|false NoFraud transaction screening result
     *
     * @since 3.0.0
     */
    public static function get_transaction_screening_result( $transaction_review_id ) {
        Debug::add_debug_message([
            'function' => 'get_transaction_screening_result:start',
            'transaction_review_id' => $transaction_review_id,
        ]);

        $api_key = get_option('woocommerce_nofraud_api_key', '');
        if (empty($api_key)) {
            return false;
        }
    
        $additional_transaction_data = [
            'nf_token' => $api_key,
            'transaction_id' => $transaction_review_id,
        ];
    
        $url = Environment::get_service_url('portal-api') . '/api/v1/transaction-update/override-status';
        $args = [
            'body' => json_encode($additional_transaction_data),
            'timeout' => 60,
            'redirection' => 5,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => [],
        ];
    
        $additional_response = wp_remote_post($url, $args);
        $additional_response_body = self::get_response_body($additional_response);

        Debug::add_debug_message([
            'function' => 'get_transaction_screening_result:end',
            'apiEndpoint' => $url,
            'transaction_review_id' => $transaction_review_id,
            'response' => $additional_response,
        ]);

        return $additional_response_body;
    }
	
	/**
	 * Get transaction review.
	 *
	 * @param int $transaction_review_id NoFraud transaction review id.
	 * @return stdObject|false NoFraud transaction review.
	 *
	 * @since 2.1.0
	 */
	public static function get_transaction_review( $transaction_review_id ) {
        Debug::add_debug_message([
            'function' => 'get_transaction_review:start',
            'transaction_review_id' => $transaction_review_id,
        ]);

		$api_key = get_option('woocommerce_nofraud_api_key', '');
		if (empty($api_key)) {
			return false;
		}

		$url = Environment::get_service_url('api') . '/status_by_url/' . $api_key . '/' . $transaction_review_id;
		$args = [
			'timeout' => 30,
			'redirection' => 5,
			'blocking' => true,
			'headers' => [
				'nf-token' => $api_key,
			],
		];

		$response = wp_remote_get($url, $args);
        $response_body = self::get_response_body($response);

        Debug::add_debug_message([
            'function' => 'get_transaction_review:end',
            'apiEndpoint' => $url,
            'transaction_review_id' => $transaction_review_id,
        ]);

        return $response_body;
	}

	/**
	 * Post transaction review.
	 *
	 * @param array $transaction_data NoFraud transaction data.
	 * @return stdObject|false NoFraud transaction review.
	 *
	 * @since 2.1.0
	 */
	public static function post_transaction_review( $transaction_data ) {
        Debug::add_debug_message([
            'function' => 'post_transaction_review:start',
            'transaction_data' => $transaction_data,
        ]);

		$api_key = get_option('woocommerce_nofraud_api_key', '');
		if (empty($api_key)) {
			return false;
		}

		$url = Environment::get_service_url('api');
		$args = [
			'body' => json_encode($transaction_data),
			'timeout' => 60,
			'redirection' => 5,
			'blocking' => true,
			'headers' => [
				'Content-Type' => 'application/json',
				'nf-token' => $api_key,
			],
			'cookies' => [],
		];

		$response = wp_remote_post($url, $args);
        $response_body = self::get_response_body($response);

        Debug::add_debug_message([
            'function' => 'post_transaction_review:end',
            'apiEndpoint' => $url,
            'invoiceNumber' => $transaction_data['order']['invoiceNumber'],
            'response' => $response,
        ]);

		return $response_body;
	}

	/**
	 * Cancel transaction review.
	 *
	 * @param string $transaction_review_id NoFraud transaction review id.
	 * @return stdObject|false NoFraud cancel transaction response.
	 *
	 * @since 2.1.4
	 */
	public static function cancel_transaction_review( $transaction_review_id ) {
        Debug::add_debug_message([
            'function' => 'cancel_transaction_review:start',
            'transaction_review_id' => $transaction_review_id,
        ]);

		$api_key = get_option('woocommerce_nofraud_api_key', '');
		if (empty($api_key)) {
			return false;
		}

		$url = Environment::get_service_url('portal-api') . '/api/v1/transaction-update/cancel-transaction';
		$body = json_encode([
			'nf_token' => $api_key,
			'transaction_id' => $transaction_review_id,
		]);
		$args = [
			'body' => $body,
			'timeout' => 30,
			'redirection' => 5,
			'blocking' => true,
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		$response = wp_remote_post($url, $args);

        Debug::add_debug_message([
            'function' => 'cancel_transaction_review:end',
            'apiEndpoint' => $url,
            'transaction_review_id' => $transaction_review_id,
            'response' => $response,
        ]);

		return self::get_response_body($response);
	}
    
    /**
     * Update transaction address
     *
     * @param string $transaction_review_id NoFraud transaction review id.
     * @param array $woocommerce_transaction_data NoFraud request body
     * @return stdObject|false NoFraud transaction review.
     *
     * @since 2.1.0
     */
    public static function update_transaction_address( $transaction_review_id, $woocommerce_transaction_data ) {
        Debug::add_debug_message([
            'function' => 'update_transaction_address:start',
            'transaction_review_id' => $transaction_review_id,
        ]);

        $api_key = get_option('woocommerce_nofraud_api_key', '');
        if (empty($api_key)) {
            return false;
        }
        
        //set up defaults if fields left blank
        $woocommerce_transaction_data['shipTo']['firstName'] = (!empty($woocommerce_transaction_data['shipTo']['firstName'])) ? $woocommerce_transaction_data['shipTo']['firstName'] : '';
        $woocommerce_transaction_data['shipTo']['lastName'] = (!empty($woocommerce_transaction_data['shipTo']['lastName'])) ? $woocommerce_transaction_data['shipTo']['lastName'] : '';
        $woocommerce_transaction_data['shipTo']['company'] = (!empty($woocommerce_transaction_data['shipTo']['company'])) ? $woocommerce_transaction_data['shipTo']['company'] : '';
        $woocommerce_transaction_data['shipTo']['address'] = (!empty($woocommerce_transaction_data['shipTo']['address'])) ? $woocommerce_transaction_data['shipTo']['address'] : '';
        $woocommerce_transaction_data['shipTo']['city'] = (!empty($woocommerce_transaction_data['shipTo']['city'])) ? $woocommerce_transaction_data['shipTo']['city'] : '';
        $woocommerce_transaction_data['shipTo']['state'] = (!empty($woocommerce_transaction_data['shipTo']['state'])) ? $woocommerce_transaction_data['shipTo']['state'] : '';
        $woocommerce_transaction_data['shipTo']['zip'] = (!empty($woocommerce_transaction_data['shipTo']['zip'])) ? $woocommerce_transaction_data['shipTo']['zip'] : '';
        $woocommerce_transaction_data['shipTo']['country'] = (!empty($woocommerce_transaction_data['shipTo']['country'])) ? $woocommerce_transaction_data['shipTo']['country'] : '';
        
        //build appropriate body package from $woocommerce_transaction_data
        $transaction_data = [
			'nf_token' => $api_key,
			'transaction_id' => $transaction_review_id,
            'update_data' => [
                'shipTo' => [
                    'firstName' => $woocommerce_transaction_data['shipTo']['firstName'],
                    'lastName' => $woocommerce_transaction_data['shipTo']['lastName'],
                    'company' => $woocommerce_transaction_data['shipTo']['company'],
                    'address' => $woocommerce_transaction_data['shipTo']['address'],
                    'city' => $woocommerce_transaction_data['shipTo']['city'],
                    'state' => $woocommerce_transaction_data['shipTo']['state'],
                    'zip' => $woocommerce_transaction_data['shipTo']['zip'],
                    'country' => $woocommerce_transaction_data['shipTo']['country'],
                ],
            ]
        ];
        
        $url = Environment::get_service_url('portal-api') . '/api/v1/transaction-update/update-data';
        $args = [
            'body' => json_encode($transaction_data),
            'timeout' => 60,
            'redirection' => 5,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => [],
        ];
        
        $response = wp_remote_post($url, $args);

        Debug::add_debug_message([
            'function' => 'update_transaction_address:end',
            'apiEndpoint' => $url,
            'transaction_review_id' => $transaction_review_id,
            'response' => $response,
        ]);

        return self::get_response_body($response);
    }

	/**
	 * Get Response body.
	 *
	 * @param string $caller_function_name The caller function's name.
	 * @return string|false The response body.
	 */
	private static function get_response_body( $response ) {
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();
			// debug_backtrace() is to find the function name of the caller of this function.
			error_log('NoFraud error: ' . debug_backtrace(false, 2)[1]['function'] . ': ' . print_r($error_message, true));

            Debug::add_debug_message([
                'function' => 'get_response_body:error',
                'error' => $error_message,
            ]);

			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		if (200 !== $response_code && 404 !== $response_code) {
			// debug_backtrace() is to find the function name of the caller of this function.
			error_log('NoFraud error: ' . debug_backtrace(false, 2)[1]['function'] . ': Response code is "' . $response_code . '" with body "' . $response_body . '"');

            Debug::add_debug_message([
                'function' => 'get_response_body:error',
                'response_code' => $response_code,
                'response_body' => $response_body,
            ]);

			return false;
		}

		return $response_body ? json_decode($response_body) : $response_body;
	}
}

Api::register();
