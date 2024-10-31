<?php

// Plugin: Cardknox Payment Gateway for WooCommerce

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\CreditCardTypeDetector;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_Cardknox extends NoFraud_Payment_Method {

    /**
     * Mapping from non-PROCAVS AVS to NF result code.
     * https://docs.cardknox.com/
     *
     * @var array Mapping from AVS to NF result code.
     */
    const CARDKNOX_AVS_RESULT_CODE_MAPPING = [
        'YYY' => 'Y',
        'NYZ' => 'Z',
        'YNA' => 'A',
        'NNN' => 'N',
        'YYX' => 'Y',
        'NYW' => 'Z',
        'GGG' => 'Y',
        'YGG' => 'Z',
    ];

    /**
     * Constructor.
     */
    public function __construct() {

    }

    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        Debug::add_debug_message([
            'function' => 'NoFraud_Cardknox:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);

        if (empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['cardknox']['enabled'])) {
            return $transaction_data;
        }

        //sanity check data
        if (
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Cardknox collect(): Transaction ID missing from Order Data.');
            Debug::add_debug_message([
                'function' => 'NoFraud_Cardknox:collect():error',
                'message' => 'NoFraud error: NoFraud_Cardknox collect(): Transaction ID missing from Order Data.',
                'order_id' => $order_data['id'],
                'transaction_id' => $order_data['transaction_id'],
            ]);
            return $transaction_data;
        }

        $broadcast_data = Database::get_nf_data($order_data['id'], '_nofraud_cardknox_broadcast_data');

        // don't collect unless data is available
        if (empty($broadcast_data)) {
            return $transaction_data;
        }

        $transaction_data['cvvResultCode'] = 'U';

        if (!empty($broadcast_data['xCvvResultCode'])) {
            $transaction_data['cvvResultCode'] = $broadcast_data['xCvvResultCode'];
        }

        $transaction_data['avsResultCode'] = 'U';
        if (!empty($broadcast_data['xAvsResultCode']) && !empty($broadcast_data['xAvsResultCode'])) {
            if (!empty(self::CARDKNOX_AVS_RESULT_CODE_MAPPING[$broadcast_data['xAvsResultCode']])) {
                $transaction_data['avsResultCode'] = self::CARDKNOX_AVS_RESULT_CODE_MAPPING[$broadcast_data['xAvsResultCode']];
            }
        }

        $transaction_data['payment']['creditCard']['last4'] = $broadcast_data['last4'];
        $transaction_data['payment']['creditCard']['cardType'] = $broadcast_data['xCardType'];
        $transaction_data['payment']['creditCard']['expirationDate'] = $broadcast_data['xExp'];

        // Wipe stored broadcast transaction data
        $metadata_payload_array = ['processed' => true];
        Database::update_nf_data($order_data['id'],'_nofraud_cardknox_broadcast_data', json_encode($metadata_payload_array));

        Debug::add_debug_message([
            'function' => 'NoFraud_Cardknox:collect():end',
            'order_id' => $order_data['id'],
            'transaction_id' => $order_data['transaction_id'],
        ]);


        return $transaction_data;
    }
}
