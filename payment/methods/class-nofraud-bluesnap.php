<?php

// Plugin: BlueSnap Payment Gateway for WooCommerce

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_Bluesnap extends NoFraud_Payment_Method {

    /**
     * Mapping from non-PROCCVV2 CVV to NF result code.
     *
     * @var array Mapping from CVV to NF result code.
     */
    const BLUESNAP_CVV_RESULT_CODE_MAPPING = [
        'MA' => 'M',
        'NM' => 'N',
        'U' => 'U',
    ];

    /**
     * Mapping from non-PROCAVS AVS to NF result code.
     *
     * @var array Mapping from AVS to NF result code.
     */
    const BLUESNAP_AVS_RESULT_CODE_MAPPING = [
        'M' => [
            'M' => 'Y', // Zip pass, Line pass.
            'N' => 'Z', // Zip pass, Line no pass.
            'U' => 'Z', // Zip pass, Line no pass.
        ],
        'N' => [
            'M' => 'A', // Zip no pass, Line pass.
            'N' => 'N', // Zip no pass, Line no pass.
            'U' => 'N', // Zip no pass, Line no pass.
        ],
        'U' => [
            'M' => 'A', // Zip unknown, Line pass.
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
            'function' => 'NoFraud_Bluesnap:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['bluesnap']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Bluesnap collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_Bluesnap:collect():error',
                'message' => 'NoFraud error: NoFraud_Bluesnap collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        // Transaction Data comes from:
        //      - do_action(wc_gateway_bluesnap_new_card_payment_success) - non-saved CC
        //      - do_action(wc_gateway_bluesnap_token_payment_success) - saved CC
        //      - Data stored in _nofraud_bluesnap_broadcast_data postmeta
        $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_bluesnap_broadcast_data');

        // don't collect unless data is available
        if (empty($broadcast_data)) {
            return $transaction_data;
        }

        $transaction_data['cvvResultCode'] = 'U';

        if (!empty($broadcast_data['cvvResponseCode']) && !empty(self::BLUESNAP_CVV_RESULT_CODE_MAPPING[$broadcast_data['cvvResponseCode']])) {
            $transaction_data['cvvResultCode'] = self::BLUESNAP_CVV_RESULT_CODE_MAPPING[$broadcast_data['cvvResponseCode']];
        }

        $transaction_data['avsResultCode'] = 'U';
        if (!empty($broadcast_data['avsResponseCodeZip']) && !empty($broadcast_data['avsResponseCodeAddress'])) {
            if (!empty(self::BLUESNAP_AVS_RESULT_CODE_MAPPING[$broadcast_data['avsResponseCodeZip']][$broadcast_data['avsResponseCodeAddress']])) {
                $transaction_data['avsResultCode'] = self::BLUESNAP_AVS_RESULT_CODE_MAPPING[$broadcast_data['avsResponseCodeZip']][$broadcast_data['avsResponseCodeAddress']];
            }
        }

        $transaction_data['payment']['creditCard']['last4'] = $broadcast_data['last4'];
        $transaction_data['payment']['creditCard']['cardType'] = $this->getSanitizedCardType($broadcast_data['type']);
        $transaction_data['payment']['creditCard']['bin'] = $broadcast_data['bin'];
        $transaction_data['payment']['creditCard']['expirationDate'] = sprintf("%02d%02d", $broadcast_data['expirationMonth'], substr($broadcast_data['expirationYear'],-2));

        // Wipe stored broadcast transaction data
        $metadata_payload_array = ['processed' => true];
        Database::update_nf_data($order_data['id'],'_nofraud_bluesnap_broadcast_data', json_encode($metadata_payload_array));

        Debug::add_debug_message([
            'function' => 'NoFraud_Bluesnap:collect():end',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);


        return $transaction_data;
    }

    // https://developers.bluesnap.com/reference/credit-card-codes
    private function getSanitizedCardType($bluesnap_card_type) {
        switch($bluesnap_card_type) {
            case 'VISA':
                return "Visa";
            case 'MASTERCARD':
                return "MasterCard";
            case 'AMEX':
                return "Amex";
            case 'DISCOVER':
                return "Discover";
            case 'JCB':
                return "JCB";
            case 'DINERS':
                return "DinersClub";
        }
        return null;
    }
}
