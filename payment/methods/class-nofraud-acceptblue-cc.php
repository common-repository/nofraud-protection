<?php

// Plugin: Payment gateway: accept.blue for WooCommerce

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_Acceptblue_Cc extends NoFraud_Payment_Method {

    /**
     * Mapping from non-PROCCVV2 CVV to NF result code.
     *
     * @var array Mapping from CVV to NF result code.
     */
    const ACCEPTBLUE_CVV_RESULT_CODE_MAPPING = [
        'M' => 'M',
        'N' => 'N',
        'U' => 'U',
    ];

    /**
     * Mapping from non-PROCAVS AVS to NF result code.
     *
     * @var array Mapping from AVS to NF result code.
     */
    const ACCEPTBLUE_AVS_RESULT_CODE_MAPPING = [
        'YYY' => 'Y',
        'YYX' => 'Y',
        'NYZ' => 'Z',
        'NYW' => 'Z',
        'YNA' => 'A',
        'NNN' => 'N',
        'GGG' => 'Y',
        'YGG' => 'Z',
    ];

    /**
     * WooCommerce Settings
     *
     * @var array
     */
    private $woocommerce_acceptblue_cc_settings;

    /**
     * Constructor.
     */
    public function __construct() {
        //get plugin settings and decode
        $woocommerce_acceptblue_cc_settings = get_option('woocommerce_acceptblue-cc_settings', []);

        $this->woocommerce_acceptblue_cc_settings = $woocommerce_acceptblue_cc_settings;
    }

    private function is_sandbox_mode() {
        if (!empty($this->woocommerce_acceptblue_cc_settings['enabled_debug_mode']) && 'yes' == $this->woocommerce_acceptblue_cc_settings['enabled_debug_mode']) {
            return true;
        }
        return false;
    }

    private function get_api_keys() {
        $acceptblue_tokenization_key = null;
        $acceptblue_api_key = null;
        $acceptblue_pin_code = null;
        if ($this->is_sandbox_mode()) {
            if (!empty($this->woocommerce_acceptblue_cc_settings['sandbox_public_key'])) {
                $acceptblue_tokenization_key = $this->woocommerce_acceptblue_cc_settings['sandbox_public_key'];
            }
            if (!empty($this->woocommerce_acceptblue_cc_settings['sandbox_source_key'])) {
                $acceptblue_api_key = $this->woocommerce_acceptblue_cc_settings['sandbox_source_key'];
            }
            if (!empty($this->woocommerce_acceptblue_cc_settings['sandbox_pin_code'])) {
                $acceptblue_pin_code = $this->woocommerce_acceptblue_cc_settings['sandbox_pin_code'];
            }
        }
        else {
            if (!empty($this->woocommerce_acceptblue_cc_settings['public_key'])) {
                $acceptblue_tokenization_key = $this->woocommerce_acceptblue_cc_settings['public_key'];
            }
            if (!empty($this->woocommerce_acceptblue_cc_settings['source_key'])) {
                $acceptblue_api_key = $this->woocommerce_acceptblue_cc_settings['source_key'];
            }
            if (!empty($this->woocommerce_acceptblue_cc_settings['pin_code'])) {
                $acceptblue_pin_code = $this->woocommerce_acceptblue_cc_settings['pin_code'];
            }
        }

        return [
            'acceptblue_tokenization_key' => $acceptblue_tokenization_key,
            'acceptblue_api_key' => $acceptblue_api_key,
            'acceptblue_pin_code' => $acceptblue_pin_code,
        ];
    }

    private function is_missing_keys($keys) {
        foreach($keys as $value) {
            if (empty($value)) {
                return true;
            }
        }
        return false;
    }

    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        Debug::add_debug_message([
            'function' => 'NoFraud_Acceptblue_Cc:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['acceptblue']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Acceptblue_Cc collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_Acceptblue_Cc:collect():error',
                'message' => 'NoFraud error: NoFraud_Acceptblue_Cc collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        // Use transaction_id in combination with private key to retrieve transaction details to populate $transaction_data
        $keys = $this->get_api_keys();

        if ($this->is_missing_keys($keys)) {
            error_log('NoFraud error: NoFraud_Acceptblue_Cc collect(): Missing required accept.blue configuration values');
            Debug::add_debug_message([
                'function' => 'NoFraud_Acceptblue_Cc:collect():error',
                'message' => 'NoFraud error: NoFraud_Acceptblue_Cc collect(): Missing required accept.blue configuration values',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        $acceptblue_transaction_data = null;
        try {
            $acceptblue_transaction_data = $this->get_transaction_data($order_data, $keys);
        } catch (Exception $e) {
            error_log('NoFraud error: NoFraud_Acceptblue_Cc collect(): ' . $e->getMessage());
            Debug::add_debug_message([
                'function' => 'NoFraud_Acceptblue_Cc:collect():error',
                'message' => 'NoFraud error: NoFraud_Acceptblue_Cc collect(): ' . $e->getMessage(),
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        /*
            Debug::add_debug_message([
                'function' => 'NoFraud_Acceptblue_Cc:collect():transaction_data',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
                'acceptblue_transaction_data' => $acceptblue_transaction_data,
            ]);
        */

        /*
            Information to collect:
                $transaction_data['status_details']['status'] - "settled"
                $transaction_data['card_details']['bin'] - "411111"
                $transaction_data['card_details']['last4'] - "1111"
                $transaction_data['card_details']['expiry_month'] - "1"
                $transaction_data['card_details']['expiry_year'] - "2026"
                $transaction_data['card_details']['card_type'] - "Visa"
                $transaction_data['card_details']['avs_result_code'] - "YYY"
                $transaction_data['card_details']['cvv_result_code'] - "M"
        */

        if (isset($acceptblue_transaction_data['card_details']['bin'])) {
            $transaction_data['payment']['creditCard']['bin'] = (string) $acceptblue_transaction_data['card_details']['bin'];
        }

        if (isset($acceptblue_transaction_data['card_details']['cvv_result_code'])) {
            if (!empty(self::ACCEPTBLUE_CVV_RESULT_CODE_MAPPING[$acceptblue_transaction_data['card_details']['cvv_result_code']])) {
                $transaction_data['cvvResultCode'] = self::ACCEPTBLUE_CVV_RESULT_CODE_MAPPING[$acceptblue_transaction_data['card_details']['cvv_result_code']];
            }
            else {
                $transaction_data['cvvResultCode'] = 'U';
            }
        }

        if (isset($acceptblue_transaction_data['card_details']['avs_result_code'])) {
            if (!empty(self::ACCEPTBLUE_AVS_RESULT_CODE_MAPPING[$acceptblue_transaction_data['card_details']['avs_result_code']])) {
                $transaction_data['avsResultCode'] = self::ACCEPTBLUE_AVS_RESULT_CODE_MAPPING[$acceptblue_transaction_data['card_details']['avs_result_code']];
            }
            else {
                $transaction_data['avsResultCode'] = 'U';
            }
        }

        if (isset($acceptblue_transaction_data['card_details']['last4'])) {
            $transaction_data['payment']['creditCard']['last4'] = $acceptblue_transaction_data['card_details']['last4'];
        }

        if (isset($acceptblue_transaction_data['card_details']['expiry_year'])) {
            $transaction_data['payment']['creditCard']['expirationDate'] = sprintf("%02d%02d",$acceptblue_transaction_data['card_details']['expiry_month'], substr($acceptblue_transaction_data['card_details']['expiry_year'], -2));
        }

        if (isset($acceptblue_transaction_data['card_details']['card_type'])) {
            $transaction_data['payment']['creditCard']['cardType'] = (string) $acceptblue_transaction_data['card_details']['card_type'];
        }

        return $transaction_data;
    }

    private function get_transaction_data($order_data, $keys)
    {
        // https://docs.accept.blue/api/v2#tag/transactions/paths/~1transactions~1%7Bid%7D/get
        $base_url = 'https://api.accept.blue/api/v2/';
        if ($this->is_sandbox_mode()) {
            $base_url = 'https://api.develop.accept.blue/api/v2/';
        }

        $url = $base_url . 'transactions/' . $order_data['transaction_id'];
        $curl_obj = curl_init($url);
        curl_setopt($curl_obj, CURLOPT_USERPWD, $keys['acceptblue_api_key'] . ":" . $keys['acceptblue_pin_code']);
        curl_setopt( $curl_obj, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($curl_obj, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl_obj);
        curl_close($curl_obj);

        $returnArray = [];
        if (!empty($result)) {
            $returnArray = json_decode($result,true);
        }

        return $returnArray;
    }
}
