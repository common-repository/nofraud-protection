<?php

// Plugin: Intuit Payments Gateway by SkyVerge

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Debug;
use WooCommerce\NoFraud\Common\Database;

final class NoFraud_Intuit_Payments_Credit_Card extends NoFraud_Payment_Method {

    /**
     * Mapping from CVV to NF result code.
     *
     * @var array Mapping from CVV to NF result code.
     */
    const INTUIT_CVV_RESULT_CODE_MAPPING = [
        'Pass' => 'M',
        'Fail' => 'N',
        'NotAvailable' => 'U',
    ];

    /**
     * Mapping from AVS to NF result code.
     *
     * @var array Mapping from AVS to NF result code.
     */
    const INTUIT_AVS_RESULT_CODE_MAPPING = [
        'Pass' => [
            'Pass' => 'Y', // Zip pass, Line pass.
            'Fail' => 'Z', // Zip pass, Line no pass.
        ],
        'Fail' => [
            'Pass' => 'A', // Zip no pass, Line pass.
            'Fail' => 'N', // Zip no pass, Line no pass.
        ],
    ];

    /**
     * WooCommerce Settings
     *
     * @var array
     */
    private $woocommerce_intuit_settings;

    /**
     * Constructor.
     */
    public function __construct() {
        //get plugin settings and decode
        $woocommerce_plugin_settings = get_option('woocommerce_intuit_settings', []);

        $this->woocommerce_intuit_settings = $woocommerce_plugin_settings;
    }

    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        Debug::add_debug_message([
            'function' => 'NoFraud_Intuit:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['intuit']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Intuit collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_Intuit:collect():error',
                'message' => 'NoFraud error: NoFraud_Intuit collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        // Transaction Data comes from do_action(wc_intuit_payments_credit_card_api_request_performed)
        //      - Data stored in _nofraud_intuit_broadcast_data postmeta
        $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_intuit_broadcast_data');

        if (!$broadcast_data) {
            sleep(1);

            // try again if race condition hit -- this should not happen though
            $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_intuit_broadcast_data');
            if (!$broadcast_data) {
                return $transaction_data;
            }
        }

        // $broadcast_data = json_decode($broadcast_data, true);

        /*
            Information we get from SkyVerge plugin:
                Last 4, CardType, Expiration, AVS Street/AVS Zip, CVV

                $broadcast_data sample:

                {"number":"xxxxxxxxxxxx4113","cardType":"Visa","expMonth":"01","expYear":"2023","avsZip":"Pass","cardSecurityCodeMatch":"NotAvailable"}

                Testing reference: https://developer.intuit.com/app/developer/qbpayments/docs/workflows/test-your-app
                CVS/AVS response reference: https://developer.intuit.com/app/developer/qbpayments/docs/api/resources/all-entities/charges#charges

                Valid responses:
                    Pass
                    Fail
                    NotAvailable
        */

        $transaction_data['cvvResultCode'] = 'U';
        if (!empty(self::INTUIT_CVV_RESULT_CODE_MAPPING[$broadcast_data['cardSecurityCodeMatch']])) {
            $transaction_data['cvvResultCode'] = self::INTUIT_CVV_RESULT_CODE_MAPPING[$broadcast_data['cardSecurityCodeMatch']];
        }

        $transaction_data['avsResultCode'] = 'U';
        if (!empty(self::INTUIT_AVS_RESULT_CODE_MAPPING[$broadcast_data['avsZip']][$broadcast_data['avsStreet']])) {
            $transaction_data['avsResultCode'] = self::INTUIT_AVS_RESULT_CODE_MAPPING[$broadcast_data['avsZip']][$broadcast_data['avsStreet']];
        }


        if ($broadcast_data['number'] && strlen($broadcast_data['number']) >= 4) {
            $transaction_data['payment']['creditCard']['last4'] = sanitize_text_field(substr($broadcast_data['number'],-4));
        }
        if (!empty($broadcast_data['expMonth']) && !empty($broadcast_data['expYear'])) {
            $transaction_data['payment']['creditCard']['expirationDate'] = sanitize_text_field($broadcast_data['expMonth'] . substr($broadcast_data['expYear'], -2));
        }
        if ($broadcast_data['cardType']) {
            $transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($broadcast_data['cardType']);
        }

        // Wipe stored broadcast transaction data if desired
        /*
            $metadata_payload_array = ['processed' => true];
            Database::update_nf_data( $order_data['id'], '_nofraud_intuit_broadcast_data', json_encode($metadata_payload_array) );
        */
        return $transaction_data;
    }
}
