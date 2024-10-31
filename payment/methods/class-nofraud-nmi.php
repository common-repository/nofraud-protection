<?php

// Plugin: WP NMI Gateway PCI for WooCommerce

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_NMI extends NoFraud_Payment_Method {

    /**
     * WooCommerce Settings
     *
     * @var array
     */
    private $woocommerce_nmi_settings;

    /**
     * Mapping from non-PROCCVV2 CVV to NF result code.
     *
     * @var array Mapping from CVV to NF result code.
     */
    const NMI_CVV_RESULT_CODE_MAPPING = [
        'M' => 'M',
        'N' => 'N',
        'P' => 'U',
        'S' => 'U',
        'U' => 'U',
    ];


    /**
     * Mapping from non-PROCAVS AVS to NF result code.
     *
     * @var array Mapping from AVS to NF result code.
     */
    const NMI_AVS_RESULT_CODE_MAPPING = [
        'A' => 'A', // Zip no pass, Line pass.
        'N' => 'N', // Zip no pass, Line no pass.
        'W' => 'Z', // Zip pass, Line no pass.
        'X' => 'Y', // Zip pass, Line pass.
        'Y' => 'Y', // Zip pass, Line pass.
        'Z' => 'Z', // Zip pass, Line no pass.
        'U' => 'U', // Zip unknown, Line unknown.
    ];


    /**
     * Constructor.
     */
    public function __construct() {
        //get plugin settings and decode
        $woocommerce_plugin_settings = get_option('woocommerce_nmi_settings', []);

        $this->woocommerce_nmi_settings = $woocommerce_plugin_settings;
    }

    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        Debug::add_debug_message([
            'function' => 'NoFraud_NMI:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['nmi']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_NMI collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_NMI:collect():error',
                'message' => 'NoFraud error: NoFraud_NMI collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        // Use transaction_id in combination with private key to retrieve transaction details to populate $transaction_data
        $nmi_private_key = null;
        $nmi_transaction_data = [];
        if (!empty($this->woocommerce_nmi_settings['enabled']) && 'yes' == $this->woocommerce_nmi_settings['enabled']) {
            if (!empty($this->woocommerce_nmi_settings['private_key'])) {
                $nmi_private_key = $this->woocommerce_nmi_settings['private_key'];
            }
        }
        if (empty($nmi_private_key)) {
            error_log('NoFraud error: NoFraud_NMI collect(): Missing NMI private key');
            Debug::add_debug_message([
                'function' => 'NoFraud_NMI:collect():error',
                'message' => 'NoFraud error: NoFraud_NMI collect(): Missing NMI private key',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        try {
            $constraints = "&transaction_id=" . $order_data['transaction_id'];
            $nmi_transaction_data = $this->get_transaction_data($nmi_private_key, $constraints);

        } catch (Exception $e) {
            error_log('NoFraud error: NoFraud_NMI collect(): ' . $e->getMessage());
            Debug::add_debug_message([
                'function' => 'NoFraud_NMI:collect():error',
                'message' => 'NoFraud error: NoFraud_NMI collect(): ' . $e->getMessage(),
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        /*
        Debug::add_debug_message([
            'function' => 'NoFraud_NMI:collect():transaction_data',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
            'nmi_transaction_data' => $nmi_transaction_data,
        ]);
        */

        /*
            Information to collect:
                $transaction_data['cvvResultCode']
                $transaction_data['avsResultCode']

            Information to overwrite if available:
                $transaction_data['payment']['creditCard']['cardType']
                $transaction_data['payment']['creditCard']['last4']
                $transaction_data['payment']['creditCard']['expirationDate']
                $transaction_data['payment']['creditCard']['bin']
        */

        if (isset($nmi_transaction_data->cc_bin[0])) {
            $transaction_data['payment']['creditCard']['bin'] = (string) $nmi_transaction_data->cc_bin[0];
        }

        if (isset($nmi_transaction_data->csc_response[0])) {
            $transaction_data['cvvResultCode'] = (string) $nmi_transaction_data->csc_response[0];

            if (empty($transaction_data['cvvResultCode']) || empty(self::NMI_CVV_RESULT_CODE_MAPPING[$transaction_data['cvvResultCode']])) {
                $transaction_data['cvvResultCode'] = 'U';
            }
        }

        if (isset($nmi_transaction_data->avs_response[0])) {
            $transaction_data['avsResultCode'] = (string) $nmi_transaction_data->avs_response[0];

            if (empty($transaction_data['avsResultCode']) || empty(self::NMI_AVS_RESULT_CODE_MAPPING[$transaction_data['avsResultCode']])) {
                $transaction_data['avsResultCode'] = 'U';
            }
        }

        if (isset($nmi_transaction_data->cc_number)) {
            $transaction_data['payment']['creditCard']['last4'] = substr($nmi_transaction_data->cc_number, -4);
        }

        if (isset($nmi_transaction_data->cc_exp[0])) {
            $transaction_data['payment']['creditCard']['expirationDate'] = (string) $nmi_transaction_data->cc_exp[0];
        }

        if (isset($nmi_transaction_data->cc_type[0])) {
            $transaction_data['payment']['creditCard']['cardType'] = (string) $nmi_transaction_data->cc_type[0];
        }

        /*
        Debug::add_debug_message([
            'function' => 'NoFraud_NMI:collect():transaction_data_processed',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
            'transaction_data' => $transaction_data,
        ]);
        */

        return $transaction_data;
    }

    private function get_transaction_data($security_key, $constraints)
    {
        $curl_obj = curl_init();
        $postStr = 'security_key=' . $security_key . $constraints;
        $url = "https://secure.networkmerchants.com/api/query.php?" . $postStr;
        curl_setopt($curl_obj, CURLOPT_URL, $url);
        curl_setopt($curl_obj, CURLOPT_RETURNTRANSFER, 1);
        $responseXML = curl_exec($curl_obj);
        curl_close($curl_obj);

        $xmlReturn = new \SimpleXMLElement($responseXML);
        if (!isset($xmlReturn->transaction)) {
            throw new Exception('No transactions returned');
        }

        foreach ($xmlReturn->transaction as $transaction) {
            return $transaction;
        }
        return null;
    }
}
