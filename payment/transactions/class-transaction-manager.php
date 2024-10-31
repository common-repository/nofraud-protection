<?php

namespace WooCommerce\NoFraud\Payment\Transactions;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Environment;
use WooCommerce\NoFraud\Api\Api;
use WooCommerce\NoFraud\Common\Debug;
use WooCommerce\NoFraud\Payment\Transactions\Constants;

final class Transaction_Manager {

	/**
	 * Transaction review - do not refresh unless this much time has passed.
	 *
	 * @var string Transaction review - do not refresh unless this much time has passed.
	 */
	const TRANSACTION_REVIEW_REFRESH_AFTER_TIMESTAMP = 60 * 5; // 5 Minutes

    /**
     * Transaction reviews - never refresh after this much time has passed.
     *
     * @var string Transaction reviews - never refresh after this much time has passed.
     */
    const TRANSACTION_REVIEW_REFRESH_LIMIT = 1209600; // 2 Weeks

	/**
	 * Transaction decisions settings.
	 *
	 * Available settings:
	 *   'refreshable'     - Is the status refreshable? e.g. API can change "review" to another status, say "pass".
	 *   'to-status->note' - Order note to go with this status change.
	 *   'to-status->from' - Transition the order, only of the current status is one of.
	 *   'to-status->to'   - Transition the order to this status by default.
	 *
	 * @var array Transaction decisions settings.
	*/
	const TRANSACTION_DECISION_SETTINGS = [
		'pass' => [
			'refreshable' => false,
			'to-status' => [
				'note' => ' NoFraud recommends to process this order.',
				'from' => [
					'on-hold',
				],
				'to' => 'processing',
			],
		],
		'review' => [
			'refreshable' => true,
			'to-status' => [
				'note' => ' NoFraud recommends to NOT ship this order until our Fraud Analysts finish this order\'s review.',
				'from' => [
					'pending',
					'processing',
				],
				'to' => 'on-hold',
			],
		],
		'fail' => [
			'refreshable' => false,
			'to-status' => [
				'note' => ' NoFraud recommends to cancel and refund this order as it has been flagged as fraudulent.',
				'from' => [
					'pending',
					'processing',
				],
				'to' => 'on-hold',
			],
		],
		'fraudulent' => [
			'refreshable' => false,
			'to-status' => [
				'note' => ' NoFraud recommends to cancel and refund this order as it has been flagged as fraudulent.',
				'from' => [
					'pending',
					'processing',
				],
				'to' => 'on-hold',
			],
		],
		'error' => [
			'refreshable' => true,
		],
		'unknown' => [
			'refreshable' => true,
		],
	];

	/**
	 * When change to the WooCommerce Order Statuses in the list, the NoFraud transaction will be cancelled.
	 *
	 * @var array Cancel transaction review Order Statuses.
	*/
	const CANCEL_TRANSACTION_REVIEW_ORDER_STATUSES = [
		'cancelled',
		'refunded',
	];

    /**
     * When considering whether to switch the status, rule these statuses out
     *
     * @var array Array of statuses that will be restricted from transitioning from
     */
    const DO_NOT_TRANSITION_STATUSES = [
        'completed',
        'cancelled',
        'refunded',
        'failed',
    ];

	/**
	 * When cancelling NoFraud transactions, only transactions of the listed decisions will be cancelled.
	 *
	 * @var array Cancel transaction review of decisions.
	*/
	const CANCEL_TRANSACTION_REVIEW_OF_DECISIONS = [
		'pass',
		'review',
	];
    
    /**
     * NoFraud transaction cancellation types
     *
     * @var array Cancel transaction review of decisions.
     */
    const CANCEL_TRANSACTION_TYPES = [
        'AUTOCANCEL' => 'AUTOCANCEL',
        'ADDRESSCHANGE' => 'ADDRESSCHANGE',
    ];
    
    /**
     * NoFraud transaction update types
     *
     * @var array Cancel transaction review of decisions.
     */
    const UPDATE_TRANSACTION_TYPES = [
        'SHIPPINGADDRESSCHANGE' => 'SHIPPINGADDRESSCHANGE',
    ];
	
	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		$instance = new self();

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:register()',
        ]);

		// Submit payment data to NoFraud to get transaction review.
		add_action('woocommerce_payment_complete', [$instance, 'evaluate_transaction'], 51);
        add_action('woocommerce_checkout_order_processed', [$instance, 'mark_authorization_orders_refreshable'], 51);
        add_action('woocommerce_thankyou', [$instance, 'thankyou_evaluate_transaction'], 51);

		// Cancel transaction review when order status to change to is in the list.
		foreach (self::CANCEL_TRANSACTION_REVIEW_ORDER_STATUSES as $order_status) {
			add_action('woocommerce_order_status_' . $order_status, [$instance, 'cancel_transaction']);
		}
	}

    /**
     * Handle Authorize events where woocommerce_payment_complete doesn't get called
     *
     * @param int $order_id Order ID.
     * @return void
     *
     * @since 4.1.0
     */
    public function thankyou_evaluate_transaction( $order_id )
    {

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:thankyou_evaluate_transaction',
            'order_id' => $order_id,
        ]);

        $order = wc_get_order($order_id);
        $_nofraud_transaction_review = Database::get_nf_data($order_id, Constants::TRANSACTION_REVIEW_KEY);

        if (empty($_nofraud_transaction_review)) {
            $payment_method = $order->get_payment_method();
            $payment_action = Database::get_nf_data($order_id, 'payment_action');
            $isAuthorizeTransaction = false;

            // if Braintree DoAuthorization type, try collecting here since payment complete did not trigger
            if ($payment_method == 'braintree_cc' && Database::get_nf_data($order_id, '_transaction_status') == 'authorized') {
                $isAuthorizeTransaction = true;
            }

            // if PayFlow DoAuthorization type, try collecting here since payment complete did not trigger
            if ($payment_method == 'paypal_pro_payflow' && $payment_action == 'DoAuthorization') {
                $isAuthorizeTransaction = true;
            }

            // if SkyVerge Authorize.net type and charge not captured, use the transaction ID to collect the data
            if ($payment_method == 'authorize_net_cim_credit_card') {
                $_wc_authorize_net_cim_credit_card_charge_captured = Database::get_nf_data($order_id, '_wc_authorize_net_cim_credit_card_charge_captured');
                $_wc_authorize_net_cim_credit_card_authorization_code = Database::get_nf_data($order_id, '_wc_authorize_net_cim_credit_card_authorization_code');

                if ($_wc_authorize_net_cim_credit_card_charge_captured == 'no' && !empty($_wc_authorize_net_cim_credit_card_authorization_code)) {
                    $isAuthorizeTransaction = true;
                }
            }

            // if Stripe, try collecting here since payment complete did not trigger
            if ($payment_method == 'stripe' && Database::get_nf_data($order_id, '_stripe_charge_captured') == 'no') {
                $isAuthorizeTransaction = true;
            }

            // if NMI, try collecting here
            if ($payment_method == 'nmi' && Database::get_nf_data($order_id, '_nmi_charge_captured') == 'no') {
                $isAuthorizeTransaction = true;
            }

            // if accept.blue, try collecting here
            if ($payment_method == 'acceptblue-cc') {
                $acceptblue_transaction_charged = Database::get_nf_data($order_id, '_acceptblue_transaction_charged');
                $acceptblue_paid_date = Database::get_nf_data($order_id, '_paid_date');
                if ($acceptblue_transaction_charged == 'no' && empty($acceptblue_paid_date)) {
                    $isAuthorizeTransaction = true;
                }
            }


            if ($isAuthorizeTransaction) {
                Debug::add_debug_message([
                    'function' => 'NoFraud:Transaction_Manager:thankyou_evaluate_transaction:isAuthorizeTransaction',
                    'order_id' => $order_id,
                ]);

                $woocommerce_nofraud_transaction_capture = get_option('woocommerce_nofraud_transaction_capture', 'CAPTUREAUTHORIZE');
                switch($woocommerce_nofraud_transaction_capture) {
                    case 'AUTHORIZE':
                    case 'CAPTUREAUTHORIZE':
                        Debug::add_debug_message([
                            'function' => 'NoFraud:Transaction_Manager:thankyou_evaluate_transaction:isAuthorizeTransaction:evaluate_transaction',
                            'order_id' => $order_id,
                        ]);
                        $this->evaluate_transaction($order_id);
                        break;
                    default:
                        return false;
                        break;
                }
            }
        }
    }

    /**
     * Marks an order refreshable for Authorize type transactions on Order Process hook
     *
     * @param int $order_id Order ID.
     * @return void
     *
     * @since 4.1.0
     */
    public function mark_authorization_orders_refreshable( $order_id ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:mark_authorization_orders_refreshable',
            'order_id' => $order_id,
        ]);

        $order = wc_get_order($order_id);
        $payment_method = $order->get_payment_method();
        $payment_action = Database::get_nf_data($order_id, 'payment_action');

        $isAuthorizeTransaction = false;

        // if Braintree DoAuthorization type, try collecting here since payment complete did not trigger
        if ($payment_method == 'braintree_cc' && $payment_action = Database::get_nf_data($order_id, '_transaction_status') == 'authorized') {
            $isAuthorizeTransaction = true;
        }

        // if PayFlow DoAuthorization type, try collecting here since payment complete did not trigger
        if ($payment_method == 'paypal_pro_payflow' && $payment_action == 'DoAuthorization') {
            $isAuthorizeTransaction = true;
        }

        // if SkyVerge Authorize.net type and charge not captured, use the transaction ID to collect the data
        if ($payment_method == 'authorize_net_cim_credit_card') {
            $_wc_authorize_net_cim_credit_card_charge_captured = Database::get_nf_data($order_id, '_wc_authorize_net_cim_credit_card_charge_captured');
            $_wc_authorize_net_cim_credit_card_authorization_code = Database::get_nf_data($order_id, '_wc_authorize_net_cim_credit_card_authorization_code');
            if ($_wc_authorize_net_cim_credit_card_charge_captured == 'no' && !empty($_wc_authorize_net_cim_credit_card_authorization_code)) {
                $isAuthorizeTransaction = true;
            }
        }

        if ($isAuthorizeTransaction) {
            $order_refreshable = Database::get_nf_data($order_id, Constants::TRANSACTION_REVIEW_REFRESHABLE_KEY);

            if ($order_refreshable !== 'refreshable') {
                $woocommerce_nofraud_transaction_capture = get_option('woocommerce_nofraud_transaction_capture', 'CAPTUREAUTHORIZE');
                switch($woocommerce_nofraud_transaction_capture) {
                    case 'AUTHORIZE':
                    case 'CAPTUREAUTHORIZE':
                        $this->evaluate_transaction($order_id);
                        break;
                    default:
                        return false;
                        break;
                }
            }
        }
    }

	/**
	 * Submit transaction data to NoFraud and act on result review data.
	 *
	 * @param int $order_id Order ID.
	 * @return stdObject NoFraud transaction review.
	 *
	 * @since 2.0.0
	 */
	public function evaluate_transaction( $order_id ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:evaluate_transaction',
            'order_id' => $order_id,
        ]);

        $order = wc_get_order($order_id);
        $original_transaction_review = Database::get_nf_data($order_id, Constants::TRANSACTION_REVIEW_KEY);

        // sanity check don't evaluate if existing review is under review. instead, just refresh
        if (!empty($original_transaction_review->decision) && $original_transaction_review->decision == 'review') {
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:get_transaction_review():dont_evaluate_under_review',
                'order_id' => $order_id,
                'existing_transaction_review' => $original_transaction_review,
            ]);
            $transaction_review = $this->get_transaction_review($order_id);
            return $transaction_review;
        }

		$transaction_review = $this->get_transaction_review($order_id);
        if (!empty($transaction_review)) {
            if (isset($transaction_review->id)) {
                if (!isset($transaction_review->override)) {
                    $transaction_screening_result = Api::get_transaction_screening_result($transaction_review->id);

                    if (isset($transaction_screening_result->code) && '200' === $transaction_screening_result->code) {
                        $transaction_review->override = $transaction_screening_result->override;
                        if ('true' === $transaction_screening_result->override) {
                            $transaction_review->override_message = $transaction_screening_result->message;

                            wc_create_order_note($order_id, $transaction_review->override_message);

                            if (isset($transaction_screening_result->data->override)) {
                                $transaction_review->override_type = $transaction_screening_result->data->override;
                            }
                        }

                        //save updated review with override details
                        Database::update_nf_data($order_id,Constants::TRANSACTION_REVIEW_KEY, $transaction_review);
                    }
                }
            }

            if (!isset($transaction_review->is_new) || !$transaction_review->is_new) {
                return $transaction_review;
            }
        }

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:evaluate_transaction',
            'order_id' => $order_id,
            'original_transaction_review' => $original_transaction_review,
            'transaction_review' => $transaction_review,
        ]);

        // only create notes/change status if decision actually changed
        if (
            !empty($original_transaction_review) &&
            property_exists($original_transaction_review, 'decision') &&
            property_exists($transaction_review, 'decision') &&
            $original_transaction_review->decision == $transaction_review->decision
        ) {
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:evaluate_transaction:samedecision',
                'order_id' => $order_id,
                'original_transaction_review->decision' => $original_transaction_review->decision,
                'transaction_review->decision' => $transaction_review->decision,
            ]);
        }
        else {
            $this->create_order_notes($order_id, $transaction_review);
            $this->transition_order_status($order_id, $transaction_review);
        }
		
		return $transaction_review;
	}

	/**
	 * Create order notes by NoFraud transaction review.
	 *
	 * @param int $order_id Order ID.
	 * @param stdObject $transaction_review NoFraud transaction review.
	 */
	private function create_order_notes( $order_id, $transaction_review ) {
		if (empty($transaction_review->decision)) {
			return;
		}

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:create_order_notes',
            'order_id' => $order_id,
        ]);

		$transaction_review_id = empty($transaction_review->id) ? null : $transaction_review->id;
		$order_note = Transaction_Renderer::get_transaction_order_note($transaction_review_id, $transaction_review->decision);
		wc_create_order_note($order_id, $order_note);
	}

	/**
	 * Transition order status and add notes by NoFraud transaction review.
	 *
	 * @param int $order_id Order ID.
	 * @param stdObject $transaction_review NoFraud transaction review.
	 */
	public function transition_order_status( $order_id, $transaction_review ) {
		if (!isset($transaction_review->decision)) {
			return;
		}
		$transaction_review_decision = $transaction_review->decision;
		if (!isset(self::TRANSACTION_DECISION_SETTINGS[$transaction_review_decision]) || !isset(self::TRANSACTION_DECISION_SETTINGS[$transaction_review_decision]['to-status'])) {
			return;
		}

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:transition_order_status()',
            'order_id' => $order_id,
        ]);

        // do not transition if setting is 'donothing'
        $to_status_identifier = $transaction_review_decision;
        // Order status "fraudulent" and "fail" use the same option for "fail".
        if ('fraudulent' === $to_status_identifier) {
            $to_status_identifier = 'fail';
        }
        $to_status = get_option('woocommerce_nofraud_to_order_status_by_' . $to_status_identifier, self::TRANSACTION_DECISION_SETTINGS[$transaction_review_decision]['to-status']['to']);

        if ('donothing' === $to_status) {
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:transition_order_status():donothing',
                'order_id' => $order_id,
            ]);
            return;
        }

		// Check if needs to transition order status.
		$order = wc_get_order($order_id);
		$order_status = $order->get_status();
		$from_statuses = self::TRANSACTION_DECISION_SETTINGS[$transaction_review_decision]['to-status']['from'];

        // do not transition if order FROM is Completed or if FROM and TO are the same
        $from_nice_order_status = wc_get_order_status_name($order_status);
        $to_nice_order_status = wc_get_order_status_name($to_status);

        // do not transition finished orders
        $doNotTransitionArray = apply_filters('woocommerce_nofraud_do_not_transition_statuses', self::DO_NOT_TRANSITION_STATUSES);
        $skipThisObj = false;
        foreach ($doNotTransitionArray as $doNotTransitionStatus) {
            if (stripos($order_status, $doNotTransitionStatus) !== false) {
                $skipThisObj = true;
            }
        }

        if ($order_status == 'wc-completed' || $from_nice_order_status == 'Completed' || $skipThisObj) {
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:transition_order_status():ignoretransition',
                'order_id' => $order_id,
                'from_nice_order_status' => $from_nice_order_status,
                'to_nice_order_status' => $to_nice_order_status,
            ]);
            return;
        }

        // Check if we should void/refund the order if feature is enabled, state is transitionable, and NF decision is fail
        $woocommerce_nofraud_automatic_voidrefund = get_option('woocommerce_nofraud_automatic_voidrefund');

        $voidrefundActionInitiated = false;
        if ('yes' === $woocommerce_nofraud_automatic_voidrefund) {
            $nofraud_voidrefund_processed = $order->get_meta('nofraud_voidrefund_processed');
            if (
                in_array($to_status_identifier, ['fail'])
                &&
                empty($nofraud_voidrefund_processed)
            ) {
                // check on transaction status.
                $order_data = $order->get_data();

                if (!empty($order_data['payment_method']) && $order_data['payment_method'] == 'authorize_net_cim_credit_card') {
                    $payment_method = $order_data['payment_method'];

                    // Require payment method file.
                    $payment_method_file_name = 'class-nofraud-' . str_replace('_', '-', $payment_method);
                    $payment_method_file_path = NOFRAUD_PLUGIN_PAYMENT_METHODS_PATH . $payment_method_file_name . '.php';
                    if (is_readable($payment_method_file_path)) {
                        require_once($payment_method_file_path);
                    }

                    $payment_method_class_name_suffix = '_' . ucwords(str_replace('-', '_', $payment_method), '_');
                    $payment_method_class_namespace = Transaction_Data_Collector::PAYMENT_METHOD_CLASS_PREFIX . $payment_method_class_name_suffix;
                    if (!class_exists($payment_method_class_namespace)) {
                        $payment_method_class_namespace = Transaction_Data_Collector::PAYMENT_METHOD_CLASS_GENERIC_METHOD;
                    }
                    $nofraud_payment_method = new $payment_method_class_namespace();

                    $_wc_authorize_net_cim_credit_card_trans_id = $order->get_meta('_wc_authorize_net_cim_credit_card_trans_id');

                    // process voidrefund
                    if (method_exists($nofraud_payment_method,'voidrefund')) {
                        $nofraud_payment_method->voidrefund($order, $_wc_authorize_net_cim_credit_card_trans_id, $nofraud_payment_method);
                        $voidrefundActionInitiated = true;
                    }
                    else {

                        $test = class_exists('WooCommerce\NoFraud\Payment\Methods\NoFraud_Authorize_Net_Cim_Credit_Card');

                        Debug::add_debug_message([
                            'function' => 'NoFraud:Transaction_Manager:transition_order_status():voidrefund:methodmissing2',
                            'order_id' => $order_id,
                            'payment_method_class_namespace' => Transaction_Data_Collector::PAYMENT_METHOD_CLASS_PREFIX . $payment_method_class_name_suffix,
                            'test' => $test,
                        ]);
                    }
                }
            }
        }

        if ($from_nice_order_status == $to_nice_order_status) {
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:transition_order_status():ignoretransitionsame',
                'order_id' => $order_id,
                'from_nice_order_status' => $from_nice_order_status,
                'to_nice_order_status' => $to_nice_order_status,
            ]);
            return;
        }


        $order_transition_note = (!$voidrefundActionInitiated) ? self::TRANSACTION_DECISION_SETTINGS[$transaction_review_decision]['to-status']['note'] : '';
        // translators: %1$s from status, %2$s to status.
        $order_note = sprintf(__('NoFraud automatically changed this order\'s status from %1$s to %2$s.', 'nofraud-protection'), $from_nice_order_status, $to_nice_order_status);
        $order_note .= __($order_transition_note, 'nofraud-protection');

        // use workaround if sync enabled
        $woocommerce_custom_orders_table_data_sync_enabled = get_option('woocommerce_custom_orders_table_data_sync_enabled');
        if ('yes' === $woocommerce_custom_orders_table_data_sync_enabled) {
            // Workaround for HPOS sync
            Database::update_nf_data($order_id,Constants::TRANSACTION_STATUS_WORKAROUND_KEY, [
                'to_status' => $to_status,
                'order_note' => $order_note,
                'order_transition_note' => $order_transition_note,
            ]);

            update_option('woocommerce_nofraud_protection_orders_to_process', 1, true);
        }
        else {
            // Transition order status.
            $order->set_status($to_status);
            $order->save();

            // Add order notes for this transition.
            wc_create_order_note($order_id, $order_note);
        }
	}

	/**
	 * Get transaction review.
	 *
	 * @param int $order_id Order ID.
	 * @return stdObject|false NoFraud transaction review.
	 */
	public function get_transaction_review( $order_id ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:get_transaction_review()',
            'order_id' => $order_id,
        ]);

        $order = wc_get_order($order_id);
        $transaction_review = Database::get_nf_data($order_id, Constants::TRANSACTION_REVIEW_KEY);

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:get_transaction_review():retrieved_review',
            'order_id' => $order_id,
            'transaction_review' => $transaction_review,
        ]);

        // This condition checking is a safe guard. You should never see a transaction review retrieved from DB carrying this "is_new" field.
		if (isset($transaction_review->is_new)) {
			unset($transaction_review->is_new);
		}
        
        if (!isset($transaction_review->cancelled) || !$transaction_review->cancelled) {
            // Try again to get latest decision, if all of the following conditions are met:
            // 1) Already has a review and with UUID.
            // 2) Review's decision is still refreshable.
            // 3) $transaction_review's age is older than TRANSACTION_REVIEW_REFRESH_AFTER_TIMESTAMP.
            if (isset($transaction_review->id)
                && isset($transaction_review->decision)
                && isset(self::TRANSACTION_DECISION_SETTINGS[$transaction_review->decision])
                && self::TRANSACTION_DECISION_SETTINGS[$transaction_review->decision]['refreshable']
                && isset($transaction_review->updated_local)
                && $transaction_review->updated_local + self::TRANSACTION_REVIEW_REFRESH_AFTER_TIMESTAMP < time()
            ) {
                $transaction_review = $this->refresh_transaction_review($order_id, $transaction_review->id);
            }
    
            // If successfully retrieved/refreshed this transaction review and without errors, return it.
            if (!empty($transaction_review->decision)) {
                return $transaction_review;
            }
        }
        
        //don't create new transactions if cancelled
        if (isset($transaction_review->cancelled) && $transaction_review->cancelled) {
            return $transaction_review;
        }
        
        //check if payment gateway was disabled at time of order
        //if so, return dummy review data
        $transaction_status = Database::get_nf_data($order_id, Constants::TRANSACTION_STATUS_KEY);
        if($transaction_status && $transaction_status === Constants::TRANSACTION_STATUS_GATEWAYDISABLED) {
            $transaction_review = new \stdClass();
            $transaction_review->decision = 'disabled';
            return $transaction_review;
        }

        // sanity check don't do a decision if order is older than TRANSACTION_REVIEW_REFRESH_LIMIT
        if (strtotime($order->get_date_created()) < time() - self::TRANSACTION_REVIEW_REFRESH_LIMIT) {
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:get_transaction_review():order_too_old',
                'order_id' => $order_id,
            ]);

            $transaction_review = new \stdClass();
            $transaction_review->decision = 'N/A';
            return $transaction_review;
        }

		// Otherwise, create a new transaction review.
		return $this->create_transaction_review($order_id);
	}

	/**
	 * Refresh a transaction review by retrieving it again from API.
	 *
	 * @param int $order_id Order ID.
	 * @return stdObject|false NoFraud transaction review.
	 */
	private function refresh_transaction_review( $order_id ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:refresh_transaction_review()',
            'order_id' => $order_id,
        ]);

        $order = wc_get_order($order_id);

		// Gather data.
        $transaction_review = Database::get_nf_data($order_id, Constants::TRANSACTION_REVIEW_KEY);
		// This condition checking is a safe guard. You should never see a transaction review retrieved from DB carrying this "is_new" field.
		if (isset($transaction_review->is_new)) {
			unset($transaction_review->is_new);
		}
		if (!isset($transaction_review->id)) {
			return false;
		}

		$transaction_review = Api::get_transaction_review($transaction_review->id);
		return $this->process_transaction_review($order_id, $transaction_review);
	}

	/**
	 * Submit transaction data to NoFraud API to create a transaction review.
	 *
	 * @param int $order_id Order ID.
	 * @return stdObject|false NoFraud transaction review.
	 */
	private function create_transaction_review( $order_id ) {
        Debug::add_debug_message([
            'function' => 'create_transaction_review',
            'order_id' => $order_id,
        ]);

        $order = wc_get_order($order_id);
        $woocommerce_nofraud_transaction_capture = get_option('woocommerce_nofraud_transaction_capture', 'CAPTUREAUTHORIZE');

        // sanity check don't do a decision if order is older than TRANSACTION_REVIEW_REFRESH_LIMIT
        if (strtotime($order->get_date_created()) < time() - self::TRANSACTION_REVIEW_REFRESH_LIMIT) {
            Debug::add_debug_message([
                'function' => 'create_transaction_review:order_too_old',
                'order_id' => $order_id,
            ]);
            return false;
        }

		// Gather data.
		$woocommerce_transaction_data = $this->get_transaction_woocommerce_data($order_id);
		if (empty($woocommerce_transaction_data)) {
            Debug::add_debug_message([
                'function' => 'create_transaction_review:notransactiondata',
                'order_id' => $order_id,
            ]);
			return false;
		}
        $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
		// Early return if plugin transaction data is not available. i.e. payment has not been made.
		if (empty($plugin_transaction_data)) {
            $payment_method = $order->get_payment_method();
            $payment_action = Database::get_nf_data($order_id, 'payment_action');

            $isAuthorizeTransaction = false;

            // if Braintree DoAuthorization type, try collecting here since payment complete did not trigger
            if ($payment_method == 'braintree_cc' && Database::get_nf_data($order_id, '_transaction_status') == 'authorized') {
                $isAuthorizeTransaction = true;
                $Transaction_Data_Collector = new Transaction_Data_Collector();
                $Transaction_Data_Collector->collect($order_id);
                $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
            }

            // if PayFlow DoAuthorization type, try collecting here since payment complete did not trigger
            if ($payment_method == 'paypal_pro_payflow' && $payment_action == 'DoAuthorization') {
                $isAuthorizeTransaction = true;
                $Transaction_Data_Collector = new Transaction_Data_Collector();
                $Transaction_Data_Collector->collect($order_id);
                $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
            }

            // if SkyVerge Authorize.net type and charge not captured, use the transaction ID to collect the data
            if ($payment_method == 'authorize_net_cim_credit_card') {
                $_wc_authorize_net_cim_credit_card_charge_captured = Database::get_nf_data($order_id, '_wc_authorize_net_cim_credit_card_charge_captured');
                $_wc_authorize_net_cim_credit_card_authorization_code = Database::get_nf_data($order_id, '_wc_authorize_net_cim_credit_card_authorization_code');

                if ($_wc_authorize_net_cim_credit_card_charge_captured == 'no' && !empty($_wc_authorize_net_cim_credit_card_authorization_code)) {
                    $isAuthorizeTransaction = true;
                    $Transaction_Data_Collector = new Transaction_Data_Collector();
                    $Transaction_Data_Collector->collect($order_id);
                    $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
                }
            }

            // if Stripe, try collecting here since payment complete did not trigger
            if ($payment_method == 'stripe' && Database::get_nf_data($order_id, '_stripe_charge_captured') == 'no') {
                $isAuthorizeTransaction = true;
                $Transaction_Data_Collector = new Transaction_Data_Collector();
                $Transaction_Data_Collector->collect($order_id);
                $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
            }

            // if NMI transaction
            if ($payment_method == 'nmi' && Database::get_nf_data($order_id, '_nmi_charge_captured') == 'no') {
                $isAuthorizeTransaction = true;
                $Transaction_Data_Collector = new Transaction_Data_Collector();
                $Transaction_Data_Collector->collect($order_id);
                $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
            }

            // if Cardknox, try to collect here
            if ($payment_method == 'cardknox') {
                if (Database::get_nf_data($order_id, '_cardknox_transaction_captured') !== 'yes') {
                    $isAuthorizeTransaction = true;
                }
                $Transaction_Data_Collector = new Transaction_Data_Collector();
                $Transaction_Data_Collector->collect($order_id);
                $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
            }

            // if accept.blue type and charge not captured, use the transaction ID to collect the data
            if ($payment_method == 'acceptblue-cc') {
                $acceptblue_transaction_charged = Database::get_nf_data($order_id, '_acceptblue_transaction_charged');
                $acceptblue_paid_date = Database::get_nf_data($order_id, '_paid_date');
                if ($acceptblue_transaction_charged == 'no' && empty($acceptblue_paid_date)) {
                    $isAuthorizeTransaction = true;
                    $Transaction_Data_Collector = new Transaction_Data_Collector();
                    $Transaction_Data_Collector->collect($order_id);
                    $plugin_transaction_data = Database::get_nf_data($order_id, Constants::TRANSACTION_DATA_KEY);
                }
            }

            // only create transactions for allowed transaction types
            if ($isAuthorizeTransaction) {
                switch($woocommerce_nofraud_transaction_capture) {
                    case 'AUTHORIZE':
                    case 'CAPTUREAUTHORIZE':
                        break;
                    default:
                        return false;
                        break;
                }
            }
            else {
                switch($woocommerce_nofraud_transaction_capture) {
                    case 'CAPTURE':
                    case 'CAPTUREAUTHORIZE':
                        break;
                    default:
                        return false;
                        break;
                }
            }

            if (empty($plugin_transaction_data)) {
                Debug::add_debug_message([
                    'function' => 'create_transaction_review:noplugintransactiondata',
                    'order_id' => $order_id,
                ]);
                return false;
            }
		}
        else {
            switch($woocommerce_nofraud_transaction_capture) {
                case 'CAPTURE':
                case 'CAPTUREAUTHORIZE':
                    break;
                default:
                    return false;
                    break;
            }
        }
		$transaction_data = array_merge($woocommerce_transaction_data, $plugin_transaction_data);

		$transaction_review = Api::post_transaction_review($transaction_data);
		
		return $this->process_transaction_review($order_id, $transaction_review);
	}

	/**
	 * Get transaction data from WooCommerce object.
	 *
	 * @param int $order_id The order ID.
	 * @return false|array Transaction data from WooCommerce objects.
	 */
	private function get_transaction_woocommerce_data( $order_id ) {
		$order = wc_get_order($order_id);
		if (!is_object($order) || !$order->get_data()) {
			return false;
		}
		$order_data = $order->get_data();
		if (!is_array($order_data)) {
			return false;
		}
		$order_billing_data = $order_data['billing'];
		$order_shipping_data = $order_data['shipping'];
		$customer = $order->get_user();

        // Handle customer IP for multiples
        $customerIP = $order->get_customer_ip_address();
        if ( is_array($customerIP) && !empty($customerIP[0]) ) {
            $customerIP = $customerIP[0];
        }
        else if ( is_string($customerIP) && strpos($customerIP, ',') !== false ) {
            $tempIPs = explode(',', $customerIP);
            if (!empty($tempIPs[0])) {
                $customerIP = $tempIPs[0];
            }
        }

		// General fields
		$woocommerce_transaction_data = [
			'app' => Environment::PLUGIN_NAME,
			'version' => Environment::PLUGIN_VERSION,
			'amount' => $order_data['total'],
			'currency_code' => $order_data['currency'],
			'gateway_name' => $order_data['payment_method_title'],
			'gateway_status' => 'pass',
			'customerIP' => $customerIP,
		];

		// "customer" field.
		// These checks will also defend against when customer id is 0. i.e. customer is an anonymous user.
		if (!empty($customer->ID)) {
			$woocommerce_transaction_data['customer']['id'] = $customer->ID;
		}
		if (!empty($customer->data->user_email)) {
			$woocommerce_transaction_data['customer']['email'] = $customer->data->user_email;
		}
		if (!empty($customer->data->user_registered)) {
			$woocommerce_transaction_data['customer']['joined_on'] = $customer->data->user_registered;
		}
		// If customer_id is 0, get the email address from the billing data.
		if (0 === $order_data['customer_id']) {
			$woocommerce_transaction_data['customer']['email'] = $order_billing_data['email'];
		}

		// "order" field.
		$woocommerce_transaction_data['order']['invoiceNumber'] = $order->get_order_number();

		// "billTo" field.
		if (!empty($order_billing_data['first_name'])) {
			$woocommerce_transaction_data['billTo']['firstName'] = $order_billing_data['first_name'];
		}
		if (!empty($order_billing_data['last_name'])) {
			$woocommerce_transaction_data['billTo']['lastName'] = $order_billing_data['last_name'];
		}
		if (!empty($order_billing_data['company'])) {
			$woocommerce_transaction_data['billTo']['company'] = $order_billing_data['company'];
		}
		if (!empty($order_billing_data['address_1'])) {
			$woocommerce_transaction_data['billTo']['address'] = $order_billing_data['address_1'];
		}
		if (!empty($order_billing_data['address_2'])) {
			$woocommerce_transaction_data['billTo']['address'] = $woocommerce_transaction_data['billTo']['address'] . ' ' . $order_billing_data['address_2'];
		}
		if (!empty($order_billing_data['city'])) {
			$woocommerce_transaction_data['billTo']['city'] = $order_billing_data['city'];
		}
		if (!empty($order_billing_data['state'])) {
			$woocommerce_transaction_data['billTo']['state'] = $order_billing_data['state'];
		}
		if (!empty($order_billing_data['postcode'])) {
			$woocommerce_transaction_data['billTo']['zip'] = $order_billing_data['postcode'];
		}
		if (!empty($order_billing_data['country'])) {
			$woocommerce_transaction_data['billTo']['country'] = $order_billing_data['country'];
		}
		if (!empty($order_billing_data['phone'])) {
			$woocommerce_transaction_data['billTo']['phoneNumber'] = $order_billing_data['phone'];
		}

		// "shipTo" field.
		if (!empty($order_shipping_data['first_name'])) {
			$woocommerce_transaction_data['shipTo']['firstName'] = $order_shipping_data['first_name'];
		}
		if (!empty($order_shipping_data['last_name'])) {
			$woocommerce_transaction_data['shipTo']['lastName'] = $order_shipping_data['last_name'];
		}
		if (!empty($order_shipping_data['company'])) {
			$woocommerce_transaction_data['shipTo']['company'] = $order_shipping_data['company'];
		}
		if (!empty($order_shipping_data['address_1'])) {
			$woocommerce_transaction_data['shipTo']['address'] = $order_shipping_data['address_1'];
		}
		if (!empty($order_shipping_data['address_2'])) {
			$woocommerce_transaction_data['shipTo']['address'] = $woocommerce_transaction_data['shipTo']['address'] . ' ' . $order_shipping_data['address_2'];
		}
		if (!empty($order_shipping_data['city'])) {
			$woocommerce_transaction_data['shipTo']['city'] = $order_shipping_data['city'];
		}
		if (!empty($order_shipping_data['state'])) {
			$woocommerce_transaction_data['shipTo']['state'] = $order_shipping_data['state'];
		}
		if (!empty($order_shipping_data['postcode'])) {
			$woocommerce_transaction_data['shipTo']['zip'] = $order_shipping_data['postcode'];
		}
		if (!empty($order_shipping_data['country'])) {
			$woocommerce_transaction_data['shipTo']['country'] = $order_shipping_data['country'];
		}

		// "lineItems" field.
		if (!empty($order_data['line_items'])) {
			$line_items = [];
			foreach ($order_data['line_items'] as $line_item) {
				$lineItem = [];
				$line_item_product = $line_item->get_product();
				$product_sku = $line_item_product && method_exists($line_item_product, 'get_sku') ? $line_item_product->get_sku() : null;
				if (!empty($product_sku)) {
					$lineItem['sku'] = $product_sku;
				}
				$line_item_data = $line_item->get_data();
				if (!empty($line_item_data['name'])) {
					$lineItem['name'] = $line_item_data['name'];
				}
				if (!empty($line_item_data['quantity'])) {
					$lineItem['quantity'] = $line_item_data['quantity'];
				}
				$lineItem_total = empty($line_item_data['total']) ?	0 : (float) $line_item_data['total'];
                $lineItem_total_tax = empty($line_item_data['total_tax']) ? 0 : (float) $line_item_data['total_tax'];

                $intQty = (int) $lineItem['quantity'];
                if (($intQty) > 1) {
                    $lineItem_total = number_format((float) ($lineItem_total / $intQty), 2, '.', '');
                    $lineItem_total_tax = number_format((float) ($lineItem_total_tax / $intQty), 2, '.', '');
                }

				$lineItem['price'] = (string) ( $lineItem_total );

				$line_items[] = $lineItem;
			}
			$woocommerce_transaction_data['lineItems'] = $line_items;
		}

		// "shipping" field.
		if (isset($order_data['shipping_total'])) {
			$woocommerce_transaction_data['shippingAmount'] = $order_data['shipping_total'];
		}
		if (isset($order_data['shipping_tax'])) {
			$woocommerce_transaction_data['shippingAmount'] = (string) ( (float) $woocommerce_transaction_data['shippingAmount'] + (float) $order_data['shipping_tax'] );
		}
		
        $orderShippingMethods = $order->get_shipping_methods();
        $orderShippingMethodObj = reset($orderShippingMethods);
		if(!empty($orderShippingMethodObj)) {
            $orderShippingName = $orderShippingMethodObj->get_name();
            
            if(!empty($orderShippingName)) {
                $woocommerce_transaction_data['shippingMethod'] = $orderShippingName;
            }
        }

		// "userFields" field.
		$woocommerce_transaction_data['userFields']['is_woocommerce'] = true;
		$siteurl_option = get_option('siteurl', '');
		if (!empty($siteurl_option)) {
			$woocommerce_transaction_data['userFields']['siteurl'] = $siteurl_option;
		}
		$blogname_option = get_option('blogname', '');
		if (!empty($blogname_option)) {
			$woocommerce_transaction_data['userFields']['blogname'] = $blogname_option;
		}
		if (!empty($order_data['payment_method_title'])) {
			$woocommerce_transaction_data['userFields']['woocommerce_payment_method'] = $order_data['payment_method_title'];
		}
		if (!empty($order_data['payment_method'])) {
			$woocommerce_transaction_data['userFields']['woocommerce_gateway_payment_method'] = $order_data['payment_method'];
		}

		return $woocommerce_transaction_data;
	}

	/**
	 * Process transaction review.
	 *
	 * @param int $order_id Order ID.
	 * @param stdObject|false NoFraud transaction review.
	 * @return stdObject|false NoFraud transaction review.
	 */
	private function process_transaction_review( $order_id, $transaction_review ) {
		if (!is_object($transaction_review)) {
			return $transaction_review;
		}
		$transaction_review->updated_local = time();

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:process_transaction_review():start',
            'order_id' => $order_id,
        ]);

		// If this transaction review contains errors, report it.
		if (!empty($transaction_review->Errors)) {
			error_log('NoFraud error: process_transaction_review: ' . print_r($transaction_review->Errors, true));
            Debug::add_debug_message([
                'function' => 'NoFraud:Transaction_Manager:process_transaction_review():errors',
                'order_id' => $order_id,
                'errors' => print_r($transaction_review->Errors, true),
            ]);
		}
		// Save and return.
        Database::update_nf_data($order_id,Constants::TRANSACTION_REVIEW_KEY, $transaction_review);
		// If there is a decision and it is not refreshable, unmark the Order from refreshable.
		if (isset($transaction_review->decision) && isset(self::TRANSACTION_DECISION_SETTINGS[$transaction_review->decision]['refreshable']) && !self::TRANSACTION_DECISION_SETTINGS[$transaction_review->decision]['refreshable']) {
            Database::delete_nf_data($order_id,Constants::TRANSACTION_REVIEW_REFRESHABLE_KEY);
            Database::delete_nf_data($order_id,Constants::TRANSACTION_DATA_KEY);
		}
		
		// If this transaction review contains override information, add to order notes
        if (isset($transaction_review->override) && isset($transaction_review->override_message) && !empty($transaction_review->override_message)) {
            wc_create_order_note($order_id, $transaction_review->override_message);
        }
		
		// Notice "is_new" is attached after storing into DB. "is_new" should not be stored into DB.
		$transaction_review->is_new = true;

		do_action('nofraud_protection_after_process_transaction_review', $transaction_review);

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:process_transaction_review():end',
            'order_id' => $order_id,
        ]);

		return $transaction_review;
	}
    
    /**
     * Update transaction information
     *
     * @param WC_Order $order WooCommerce Order.
     * @param array $updated_props Array of props that were changed by update
     * @return stdObject NoFraud transaction review.
     *
     * @since 2.1.4
     */
    public function update_transaction( $order, $updated_props ) {
        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:update_transaction()',
            'order_id' => $order->get_id(),
        ]);

        $transaction_review = $this->get_transaction_review($order->get_id());
        
        //update shipping address
        if (!empty($updated_props) && is_array($updated_props) && in_array('shipping', $updated_props)) {
            $original_transaction_review_data = Database::get_nf_data($order->get_id(), Constants::TRANSACTION_REVIEW_KEY);
            if(empty($original_transaction_review_data)) {
                return;
            }
    
            $woocommerce_transaction_data = $this->get_transaction_woocommerce_data($order->get_id());
            if (empty($woocommerce_transaction_data)) {
                return;
            }
    
            // Call Portal API to update transaction.
            $update_transaction_response = Api::update_transaction_address($transaction_review->id, $woocommerce_transaction_data);
            if (!$update_transaction_response) {
                return $transaction_review;
            }
    
            //refresh the transaction
            $this->process_transaction_review($order->get_id(), $update_transaction_response);
            $old_transaction_review = $transaction_review;
            $transaction_review = $this->refresh_transaction_review($order->get_id());
    
            // Create a Order Note.
            $order_note = Transaction_Renderer::get_update_transaction_order_note($old_transaction_review, $transaction_review, Transaction_Manager::UPDATE_TRANSACTION_TYPES['SHIPPINGADDRESSCHANGE']);
            wc_create_order_note($order->get_id(), $order_note);
        }
        
        return $transaction_review;
    }

	/**
	 * Cancel transaction review by Order Id.
	 *
	 * @param int $order_id Order ID.
     * @param string $cancel_type Specify type of transaction cancellation
     * @param boolean $ignore_cancel_response Set to true to cancel anyway despite API denial
	 * @return stdObject NoFraud transaction review.
	 *
	 * @since 2.1.4
	 */
	public function cancel_transaction( $order_id, $cancel_type = self::CANCEL_TRANSACTION_TYPES['AUTOCANCEL'], $ignore_cancel_response = false ) {
        // only process if order is Checkout payment method
        $order = wc_get_order($order_id);
        $payment_method = $order->get_payment_method();

        // do not process checkout orders
        if ('nofraud' == $payment_method) {
            return;
        }

        Debug::add_debug_message([
            'function' => 'NoFraud:Transaction_Manager:cancel_transaction()',
            'order_id' => $order_id,
        ]);

		$transaction_review = $this->get_transaction_review($order_id);
		
		// Cancel the NoFraud transaction review only if:
		//   1. Review exists and has id,
		//   2. Review's decision is "pass" or "review", and
		//   3. Review hasn't already been cancelled.
		if (!$transaction_review || empty($transaction_review->id) || !in_array($transaction_review->decision, self::CANCEL_TRANSACTION_REVIEW_OF_DECISIONS) || (isset($transaction_review->cancelled) && $transaction_review->cancelled) ) {
			return $transaction_review;
		}

		// Call Portal API to cancel transaction.
		$cancel_transaction_response = Api::cancel_transaction_review($transaction_review->id);
		if (!$cancel_transaction_response || '200' !== $cancel_transaction_response->code) {
		    if(!$ignore_cancel_response) {
                return $transaction_review;
            }
		}

		// Create a Order Note.
		$order_note = Transaction_Renderer::get_cancel_transaction_order_note($transaction_review, $cancel_type);
		wc_create_order_note($order_id, $order_note);

		// Set the transaction review's "cancelled" flag to on, and save.
		$transaction_review->cancelled = true;
        Database::update_nf_data($order_id,Constants::TRANSACTION_REVIEW_KEY, $transaction_review);

        $transaction_review = $this->get_transaction_review($order_id);

		return $transaction_review;
	}
}

Transaction_Manager::register();
