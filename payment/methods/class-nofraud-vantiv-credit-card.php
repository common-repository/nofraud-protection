<?php

// Plugin: Vantiv Payments Gateway by SkyVerge

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_Vantiv_Credit_Card extends NoFraud_Payment_Method {

    // http://support.worldpay.com/support/CNP-API/content/cardvalrespcodes.htm
    /**
     * Mapping from CVV to NF result code.
     *
     * @var array Mapping from CVV to NF result code.
     */
    const VANTIV_CVV_RESULT_CODE_MAPPING = [
        'M' => 'M',
        'N' => 'N',
    ];

    const VANTIV_CARD_TYPE_MAPPING = [
        'VISA' => 'Visa',
        'MC' => 'MasterCard',
        'AMEX' => 'American Express',
        'DISC' => 'Discover',
        'DINERS' => 'Diners',
        'JCB' => 'JCB',
    ];

    // http://support.worldpay.com/support/CNP-API/content/avsrespcodes.htm
    /**
     * Mapping from AVS to NF result code.
     *
     * @var array Mapping from AVS to NF result code.
     */
    const VANTIV_AVS_RESULT_CODE_MAPPING = [
        '00' => 'Y',
        '01' => 'Y',
        '02' => 'Y',
        '10' => 'Z',
        '11' => 'Z',
        '12' => 'A',
        '13' => 'A',
        '14' => 'Z',
        '20' => 'N',
    ];

    /**
     * Constructor.
     */
    public function __construct() {

    }

    private function generate_nonce() {
        $length = 9;
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        Debug::add_debug_message([
            'function' => 'NoFraud_Vantiv:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['worldpay']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Vantiv collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_Vantiv:collect():error',
                'message' => 'NoFraud error: NoFraud_Vantiv collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        // Transaction Data comes from do_action(wc_vantiv_credit_card_api_request_performed)
        //      - Data stored in _nofraud_vantiv_broadcast_data postmeta
        $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_vantiv_broadcast_data');

        if (!$broadcast_data) {
            sleep(1);

            // try again if race condition hit -- this should not happen though
            $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_vantiv_broadcast_data');
            if (!$broadcast_data) {
                return $transaction_data;
            }
        }

        // $broadcast_data = json_decode($broadcast_data, true);

        /*
            Information we get from SkyVerge plugin:
                avsResult, cardValidationResult, bin, expDate, type

                $broadcast_data sample:

                {"avsResult":"00","cardValidationResult":"M","bin":"543510","expDate":"0125", "type":"MC"}
        */

        $transaction_data['cvvResultCode'] = 'U';
        if (!empty(self::VANTIV_CVV_RESULT_CODE_MAPPING[$broadcast_data['cardValidationResult']])) {
            $transaction_data['cvvResultCode'] = self::VANTIV_CVV_RESULT_CODE_MAPPING[$broadcast_data['cardValidationResult']];
        }

        $transaction_data['avsResultCode'] = 'U';
        if (!empty(self::VANTIV_AVS_RESULT_CODE_MAPPING[$broadcast_data['avsResult']])) {
            $transaction_data['avsResultCode'] = self::VANTIV_AVS_RESULT_CODE_MAPPING[$broadcast_data['avsResult']];
        }

        $transaction_data['payment']['creditCard']['expirationDate'] = $broadcast_data['expDate'];
        $transaction_data['payment']['creditCard']['bin'] = $broadcast_data['bin'];

        if (!empty(self::VANTIV_CARD_TYPE_MAPPING[$broadcast_data['type']])) {
            $transaction_data['payment']['creditCard']['cardType'] = self::VANTIV_CARD_TYPE_MAPPING[$broadcast_data['type']];
        }

        // Wipe stored broadcast transaction data if desired
        /*
            $metadata_payload_array = ['processed' => true];
            Database::update_nf_data( $order_data['id'], '_nofraud_vantiv_broadcast_data', json_encode($metadata_payload_array) );
        */

        // use stored litleToken (Omnitoken 16 digits) if available to fill in extra details
        $litleToken = get_field('litleToken', $order_data['id']);

        if (!empty($litleToken)) {
            // retrieve WorldPay API settings
            $profileID = get_option('woocommerce_nofraud_worldpay_profile_id', '');
            $merchantIdentifier = get_option('woocommerce_nofraud_worldpay_merchant_id', '');
            $sharedKey = get_option('woocommerce_nofraud_worldpay_shared_key', '');

            $detected_vantiv_env = get_option('woocommerce_vantiv_credit_card_settings', '');
            if (!empty($detected_vantiv_env)) {
                $detected_vantiv_env = $detected_vantiv_env['environment'];
            }

            $timestamp = date("c", time());
            $timestamp = str_replace('+00:00','Z', $timestamp);
            $nonce = $this->generate_nonce();
            $requestUrl = 'api/Tokens/' . $litleToken . '?profileId=' . $profileID . '&payloadType=Card&includeCvv2=true&includeExpirationDate=true';
            $url = 'https://tesapi.paymetric.com/' . $requestUrl;
            $requestMethod = 'GET';
            $requestPacket = '';

            // switch to dev endpoint if pre-live environment set
            if ('pre_live' == $detected_vantiv_env) {
                $url = 'https://cert-tesapi.paymetric.com/' . $requestUrl;
            }

            $raw_signature = $merchantIdentifier . '|' . $sharedKey . '|' . $timestamp . '|' . $nonce . '|' . $requestUrl . '|' . $requestMethod . '|' . $requestPacket;

            $final_signature = hash('sha256', base64_encode(str_replace(' ','', strtoupper($raw_signature))));
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'apiMerchantIdentifier: ' . $merchantIdentifier,
                    'timeStamp: ' . $timestamp,
                    'nonce: ' . $nonce,
                    'signature: ' . $final_signature,
                ),
            ));

            $response = curl_exec($curl);
            $data = json_decode($response, true);

            if (!empty($data['statusCode']) && !empty($data['cardDetails']['cardNumber']) && $data['statusCode'] == "100") {
                $transaction_data['payment']['creditCard']['last4'] = substr($data['cardDetails']['cardNumber'], -4);
            }
            else {
                error_log('NoFraud error: NoFraud_Vantiv collect(): Error retrieving Worldpay token');
                Debug::add_debug_message([
                    'function' => 'NoFraud_Vantiv:collect():error',
                    'message' => 'NoFraud error: NoFraud_Vantiv collect(): Error retrieving Worldpay token',
                    'order_id' => $order_data['id'],
                    'response' => $response,
                    'detected_vantiv_env' => $detected_vantiv_env,
                ]);
            }

            curl_close($curl);
        }
        else {
            error_log('NoFraud error: NoFraud_Vantiv collect(): Empty litleToken field');
            Debug::add_debug_message([
                'function' => 'NoFraud_Vantiv:collect():error',
                'message' => 'NoFraud error: NoFraud_Vantiv collect(): Empty litleToken field',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
        }

        return $transaction_data;
    }
}
