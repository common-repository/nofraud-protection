<?php

// Plugin: AngelEye PayPal for WooCommerce (PayPal PayFlow Pro 2.0)

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Debug;
use WooCommerce\NoFraud\Common\Database;

final class NoFraud_Paypal_Pro_Payflow extends NoFraud_Payment_Method {

    /**
     * Mapping from non-PROCCVV2 CVV to NF result code.
     *
     * @var array Mapping from CVV to NF result code.
     */
    const PAYFLOW_CVV_RESULT_CODE_MAPPING = [
        'Y' => 'M',
        'N' => 'N',
        'X' => 'U',
    ];

    /**
     * Mapping from non-PROCAVS AVS to NF result code.
     *
     * @var array Mapping from AVS to NF result code.
     */
    const PAYFLOW_AVS_RESULT_CODE_MAPPING = [
        'Y' => [
            'Y' => 'Y', // Zip pass, Line pass.
            'N' => 'Z', // Zip pass, Line no pass.
            'X' => 'Z', // Zip pass, Line no pass.
        ],
        'N' => [
            'Y' => 'A', // Zip no pass, Line pass.
            'N' => 'N', // Zip no pass, Line no pass.
            'X' => 'N', // Zip no pass, Line no pass.
        ],
        'X' => [
            'Y' => 'A', // Zip unknown, Line pass.
            'N' => 'N', // Zip unknown, Line no pass.
            'U' => 'U', // Zip unknown, Line unknown.
        ],
    ];

    /**
     * Constructor.
     */
    public function __construct() {

    }

    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        Debug::add_debug_message([
            'function' => 'NoFraud_Paypal_Pro_Payflow:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['paypal']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Paypal_Pro_Payflow collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_Paypal_Pro_Payflow:collect():error',
                'message' => 'NoFraud error: NoFraud_Paypal_Pro_Payflow collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        // Transaction Data comes from do_action(wc_intuit_payments_credit_card_api_request_performed)
        //      - Data stored in _nofraud_intuit_broadcast_data postmeta
        $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_angeleye_payflow_broadcast_data');

        if (!$broadcast_data) {
            // check for Authorization data instead
            $payment_action = Database::get_nf_data($order_data['id'], 'payment_action', true);
            $ACCT = Database::get_nf_data($order_data['id'], 'ACCT', true);
            if ($payment_action === 'DoAuthorization' && !empty($ACCT)) {
                $broadcast_data = [
                    'PROCCVV2' => Database::get_nf_data($order_data['id'], 'PROCCVV2'),
                    'CVV2MATCH' => Database::get_nf_data($order_data['id'], 'CVV2MATCH'),
                    'PROCAVS' => Database::get_nf_data($order_data['id'], 'PROCAVS'),
                    'AVSZIP' => Database::get_nf_data($order_data['id'], 'AVSZIP'),
                    'AVSADDR' => Database::get_nf_data($order_data['id'], 'AVSADDR'),
                    'last4' => Database::get_nf_data($order_data['id'], 'ACCT'),
                    'type' => Database::get_nf_data($order_data['id'], 'PROCCVV2'),
                    'cvc' => Database::get_nf_data($order_data['id'], 'PROCCVV2'),
                    'exp' => Database::get_nf_data($order_data['id'], 'PROCCVV2'),
                ];
            }

            if (!$broadcast_data) {


                Debug::add_debug_message([
                    'function' => 'NoFraud_Paypal_Pro_Payflow:collect():error',
                    'message' => 'NoFraud error: NoFraud_Paypal_Pro_Payflow collect(): Broadcast data not found.',
                    'order_id' => $order_data['id'],
                    'transaction_id' => $order_data['transaction_id'],
                ]);

                return $transaction_data;
            }
        }
        else {
            // $broadcast_data = json_decode($broadcast_data, true);
        }

        $transaction_data['cvvResultCode'] = 'U';

        if (!empty($broadcast_data['PROCCVV2']) && $broadcast_data['PROCCVV2'] != 'U') {
            $transaction_data['cvvResultCode'] = $broadcast_data['PROCCVV2'];
        }
        else {
            if (!empty(self::PAYFLOW_CVV_RESULT_CODE_MAPPING[$broadcast_data['CVV2MATCH']])) {
                $transaction_data['cvvResultCode'] = self::PAYFLOW_CVV_RESULT_CODE_MAPPING[$broadcast_data['CVV2MATCH']];
            }
        }

        $transaction_data['avsResultCode'] = 'U';
        if (!empty($broadcast_data['PROCAVS']) && $broadcast_data['PROCAVS'] != 'U') {
            $transaction_data['avsResultCode'] = $broadcast_data['PROCAVS'];
        }
        else {
            if (!empty(self::PAYFLOW_AVS_RESULT_CODE_MAPPING[$broadcast_data['AVSZIP']][$broadcast_data['AVSADDR']])) {
                $transaction_data['avsResultCode'] = self::PAYFLOW_AVS_RESULT_CODE_MAPPING[$broadcast_data['AVSZIP']][$broadcast_data['AVSADDR']];
            }
        }

        if (!empty($broadcast_data['bin'])) {
            $transaction_data['payment']['creditCard']['bin'] = $broadcast_data['bin'];
        }

        $transaction_data['payment']['creditCard']['last4'] = $broadcast_data['last4'];
        $transaction_data['payment']['creditCard']['cardType'] = $broadcast_data['type'];
        $transaction_data['payment']['creditCard']['cardCode'] = $broadcast_data['cvc'];

        if (!empty($broadcast_data['exp'])) {
            $transaction_data['payment']['creditCard']['expirationDate'] = $broadcast_data['exp'];
        }
        else {
            $transaction_data['payment']['creditCard']['expirationDate'] = $broadcast_data['exp_month'] . $broadcast_data['exp_year'];
        }

        // Wipe stored broadcast transaction data
        $metadata_payload_array = ['processed' => true];
        Database::update_nf_data($order_data['id'],'_nofraud_angeleye_payflow_broadcast_data', json_encode($metadata_payload_array));


        return $transaction_data;
    }
}
