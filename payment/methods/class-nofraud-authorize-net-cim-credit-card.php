<?php

//Plugin: SkyVerge WooCommerce Authorize.Net Gateway, Authorize.net, a Visa solution

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use WooCommerce\NoFraud\Common\Debug;

final class NoFraud_Authorize_Net_Cim_Credit_Card extends NoFraud_Payment_Method {
    
    /**
     * Authorize.net Authentication Class
     *
     * @var AnetAPI\MerchantAuthenticationType
     */
    private $merchantAuthentication;
    
    /**
     * WooCommerce Settings
     *
     * @var array
     */
    private $woocommerce_authorize_net_cim_credit_card_settings;
    
    /**
     * Friendly mapping of AuthorizeNet Response Codes
     *
     * @var array Friendly mapping of AuthorizeNet Response Codes
     */
    const AUTHORIZENET_RESPONSE_CODES = [
        'RESPONSE_OK' => "Ok",
    ];
    
    /**
     * Mapping of AuthorizeNet CVV Codes to NoFraud Codes
     *
     * @var array Mapping of AuthorizeNet CVV Codes to NoFraud Codes
     */
    const AUTHORIZENET_CVV_CODES = [
        'M' => "M",
        'N' => "N",
    ];
    
    /**
     * Constructor.
     */
    public function __construct() {
        //get woocommerce_authorize_net_cim_credit_card_settings and decode
        $woocommerce_authorize_net_cim_credit_card_settings = get_option('woocommerce_authorize_net_cim_credit_card_settings', []);
        
        //sanity check data
        if(
            empty($woocommerce_authorize_net_cim_credit_card_settings['environment'])
        ) {
            error_log('NoFraud error: woocommerce_authorize_net_cim_credit_card_settings missing required parameter(s).');
            return;
        }
        
        //select API set depending on environment
        $apiLogin = '';
        $apiTransactionKey = '';
        $apiSignatureKey = '';
        if('test' === $woocommerce_authorize_net_cim_credit_card_settings['environment']) {
            $apiLogin = $woocommerce_authorize_net_cim_credit_card_settings['test_api_login_id'];
            $apiTransactionKey = $woocommerce_authorize_net_cim_credit_card_settings['test_api_transaction_key'];
            $apiSignatureKey = $woocommerce_authorize_net_cim_credit_card_settings['test_api_signature_key'];
        }
        else {
            $apiLogin = $woocommerce_authorize_net_cim_credit_card_settings['api_login_id'];
            $apiTransactionKey = $woocommerce_authorize_net_cim_credit_card_settings['api_transaction_key'];
            $apiSignatureKey = $woocommerce_authorize_net_cim_credit_card_settings['api_signature_key'];
        }
        
        //setup API request
        $this->merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $this->merchantAuthentication->setName($apiLogin);
        $this->merchantAuthentication->setTransactionKey($apiTransactionKey);
        
        $this->woocommerce_authorize_net_cim_credit_card_settings = $woocommerce_authorize_net_cim_credit_card_settings;
    }
    
    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        //sanity check data
        if(
            empty($order_data['transaction_id'])
        ) {
            error_log('NoFraud error: NoFraud_Authorize_Net_Cim_Credit_Card collect(): Transaction ID missing from Order Data.');
            return $transaction_data;
        }

        $transaction_id = $order_data['transaction_id'];

        /*
            Information to collect:
                $transaction_data['cvvResultCode']
                $transaction_data['avsResultCode']
        
            Information to overwrite if available: (From: $response->transaction->payment->creditCard)
                $transaction_data['payment']['creditCard']['cardType']: Comes in (e.g "Visa")
                $transaction_data['payment']['creditCard']['last4']: Masked from cardNumber (e.g. "XXXX0027")
                $transaction_data['payment']['creditCard']['expirationDate']: Not available (e.g. "XXXX") : Can get from POST data
                    - Not available through API, see:
                        https://community.developer.authorize.net/t5/Integration-and-Testing/How-to-get-card-expiration-date-unmasked-from-transaction/td-p/71707
                $transaction_data['payment']['creditCard']['bin']: Not available
        */
        
        //retrieve transaction details by transaction ID
        $request = new AnetAPI\GetTransactionDetailsRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setTransId($order_data['transaction_id']);
    
        $controller = new AnetController\GetTransactionDetailsController($request);
    
        $response = null;
        if('test' === $this->woocommerce_authorize_net_cim_credit_card_settings['environment']) {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }
        
        if ((null !== $response) && (self::AUTHORIZENET_RESPONSE_CODES['RESPONSE_OK'] === $response->getMessages()->getResultCode()))
        {
            $transactionDetailsObj = $response->getTransaction();
    
            if(!empty(self::AUTHORIZENET_CVV_CODES[$transactionDetailsObj->getCardCodeResponse()])) {
                $transaction_data['cvvResultCode'] = self::AUTHORIZENET_CVV_CODES[$transactionDetailsObj->getCardCodeResponse()];
            }
    
            if(!empty($transactionDetailsObj->getAVSResponse())) {
                $transaction_data['avsResultCode'] = $transactionDetailsObj->getAVSResponse();
            }
    
            $creditCardMaskedTypeObj = $transactionDetailsObj->getPayment()->getCreditCard();

            if (!empty($creditCardMaskedTypeObj) && is_object($creditCardMaskedTypeObj) && method_exists($creditCardMaskedTypeObj,'getCardType')) {
                if(!empty($creditCardMaskedTypeObj->getCardType())) {
                    $transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($creditCardMaskedTypeObj->getCardType());

                }

                if(!empty($creditCardMaskedTypeObj->getCardNumber())) {
                    $apiLast4CardNumber = substr($creditCardMaskedTypeObj->getCardNumber(),-4);
                    if(ctype_digit($apiLast4CardNumber) && 4 === strlen($apiLast4CardNumber)) {
                        $transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($apiLast4CardNumber);
                    }
                }

                //get expiration from post data
                if(!empty($_POST['wc-authorize-net-cim-credit-card-expiry']) && strlen($_POST['wc-authorize-net-cim-credit-card-expiry']) >= 4) {
                    $potentialExpirationDate = substr($_POST['wc-authorize-net-cim-credit-card-expiry'],0,2) . substr($_POST['wc-authorize-net-cim-credit-card-expiry'],-2);
                    $potentialExpirationDate = preg_replace('/\D/', '', $potentialExpirationDate);

                    if(strlen($potentialExpirationDate) == 4) {
                        $transaction_data['payment']['creditCard']['expirationDate'] = $potentialExpirationDate;
                    }
                }
            }
        }
        else
        {
            $errorMessages = $response->getMessages()->getMessage();
            error_log('NoFraud error: NoFraud_Authorize_Net_Cim_Credit_Card collect(): ' . print_r($errorMessages, true) );
        }
        
        return $transaction_data;
    }

    public function voidrefund( $order, $_wc_authorize_net_cim_credit_card_trans_id, $nofraud_payment_method ) {
        $transaction_id = $_wc_authorize_net_cim_credit_card_trans_id;
        $order_id = $order->get_id();

        //retrieve transaction details by transaction ID
        $request = new AnetAPI\GetTransactionDetailsRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setTransId($transaction_id);

        $controller = new AnetController\GetTransactionDetailsController($request);

        $response = null;
        if('test' === $this->woocommerce_authorize_net_cim_credit_card_settings['environment']) {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }

        $last4 = null;
        $amountToRefund = '0.00';
        $transactionStatus = null;
        if ((null !== $response) && (self::AUTHORIZENET_RESPONSE_CODES['RESPONSE_OK'] === $response->getMessages()->getResultCode()))
        {
            $transactionDetailsObj = $response->getTransaction();

            $creditCardMaskedTypeObj = $transactionDetailsObj->getPayment()->getCreditCard();
            if (!empty($creditCardMaskedTypeObj) && is_object($creditCardMaskedTypeObj) && method_exists($creditCardMaskedTypeObj,'getCardType')) {
                if(!empty($creditCardMaskedTypeObj->getCardNumber())) {
                    $apiLast4CardNumber = substr($creditCardMaskedTypeObj->getCardNumber(),-4);
                    if(ctype_digit($apiLast4CardNumber) && 4 === strlen($apiLast4CardNumber)) {
                        $last4 = sanitize_text_field($apiLast4CardNumber);
                    }
                }
            }

            $amountToRefund = $transactionDetailsObj->getSettleAmount();
            $transactionStatus = $transactionDetailsObj->getTransactionStatus();
        }
        else
        {
            $errorMessages = $response->getMessages()->getMessage();
            error_log('NoFraud error: NoFraud_Authorize_Net_Cim_Credit_Card refund(): ' . print_r($errorMessages, true) );
        }

        if (in_array($transactionStatus, ['capturedPendingSettlement','authorizedPendingCapture'])) {
            $tresponse = $nofraud_payment_method->void($_wc_authorize_net_cim_credit_card_trans_id);
            if ($tresponse != null && $tresponse->getMessages() != null) {
                Debug::add_debug_message([
                    'function' => 'woocommerce_nofraud_automatic_voidrefund:VOID:success',
                    'order_id' => $order_id,
                    '_wc_authorize_net_cim_credit_card_void_auth_code' => $tresponse->getAuthCode(),
                    '_wc_authorize_net_cim_credit_card_void_trans_id' => $tresponse->getTransId(),
                ]);

                $order->update_meta_data( '_wc_authorize_net_cim_credit_card_void_auth_code', $tresponse->getAuthCode() );
                $order->update_meta_data( '_wc_authorize_net_cim_credit_card_void_trans_id', $tresponse->getTransId() );
                $order->update_meta_data( 'nofraud_voidrefund_processed', 1 );

                $order_note = __('NoFraud automatically voided the charge for this order.', 'nofraud-protection');
                wc_create_order_note($order_id, $order_note);
            }
            else {
                if ($tresponse->getErrors() != null) {
                    Debug::add_debug_message([
                        'function' => 'woocommerce_nofraud_automatic_voidrefund:VOID:failed',
                        'order_id' => $order_id,
                        'error_code' => $tresponse->getErrors()[0]->getErrorCode(),
                        'error_msg' => $tresponse->getErrors()[0]->getErrorText(),
                    ]);

                    $order_note = sprintf(__('NoFraud attempted to void the charge for this order but the attempt failed with error code (%1$s):  %2$s.', 'nofraud-protection'), $tresponse->getErrors()[0]->getErrorCode(), $tresponse->getErrors()[0]->getErrorText());
                    wc_create_order_note($order_id, $order_note);
                }
            }
        }

        if ('settledSuccessfully' === $transactionStatus) {
            $_wc_authorize_net_cim_credit_card_account_four = $order->get_meta('_wc_authorize_net_cim_credit_card_account_four');
            if (!empty($_wc_authorize_net_cim_credit_card_account_four)) {
                $tresponse = $nofraud_payment_method->refund($order, $_wc_authorize_net_cim_credit_card_trans_id, $last4, $amountToRefund);
                if ($tresponse != null && $tresponse->getMessages() != null) {
                    Debug::add_debug_message([
                        'function' => 'woocommerce_nofraud_automatic_voidrefund:REFUND:success',
                        'order_id' => $order_id,
                        '_wc_authorize_net_cim_credit_card_void_auth_code' => $tresponse->getAuthCode(),
                        '_wc_authorize_net_cim_credit_card_void_trans_id' => $tresponse->getTransId(),
                    ]);

                    $order->update_meta_data( '_wc_authorize_net_cim_credit_card_refund_auth_code', $tresponse->getAuthCode() );
                    $order->update_meta_data( '_wc_authorize_net_cim_credit_card_refund_trans_id', $tresponse->getTransId() );
                    $order->update_meta_data( 'nofraud_voidrefund_processed', 1 );

                    $order_note = sprintf(__('NoFraud automatically refunded the charge for this order (Transaction ID %1$s)', 'nofraud-protection'), $tresponse->getTransId());
                    wc_create_order_note($order_id, $order_note);
                }
                else {
                    if ($tresponse->getErrors() != null) {
                        Debug::add_debug_message([
                            'function' => 'woocommerce_nofraud_automatic_voidrefund:REFUND:failed',
                            'order_id' => $order_id,
                            'error_code' => $tresponse->getErrors()[0]->getErrorCode(),
                            'error_msg' => $tresponse->getErrors()[0]->getErrorText(),
                        ]);

                        $order_note = sprintf(__('NoFraud attempted to refund the charge for this order but the attempt failed with error code (%1$s):  %2$s.', 'nofraud-protection'), $tresponse->getErrors()[0]->getErrorCode(), $tresponse->getErrors()[0]->getErrorText());
                        wc_create_order_note($order_id, $order_note);
                    }
                }
            }
            else {
                Debug::add_debug_message([
                    'function' => 'woocommerce_nofraud_automatic_voidrefund:REFUND:failed:missing_last4',
                    'order_id' => $order_id,
                ]);
            }
        }
    }

    public function void( $transaction_id ) {
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType( "voidTransaction");
        $transactionRequestType->setRefTransId($transaction_id);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setRefId($transaction_id);
        $request->setTransactionRequest( $transactionRequestType);

        $controller = new AnetController\CreateTransactionController($request);

        $response = null;
        if('test' === $this->woocommerce_authorize_net_cim_credit_card_settings['environment']) {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }

        $tresponse = null;
        if ((null !== $response) && (self::AUTHORIZENET_RESPONSE_CODES['RESPONSE_OK'] === $response->getMessages()->getResultCode())) {
            $tresponse = $response->getTransactionResponse();
        }
        else {
            $errorMessages = $response->getMessages()->getMessage();
            error_log('NoFraud error: NoFraud_Authorize_Net_Cim_Credit_Card void(): ' . print_r($errorMessages, true) );
        }

        return $tresponse;
    }

    public function refund( $order, $_wc_authorize_net_cim_credit_card_trans_id, $last4, $amountToRefund ) {
        $transaction_id = $_wc_authorize_net_cim_credit_card_trans_id;

        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($last4);
        // intentional: https://developer.authorize.net/api/reference/index.html#payment-transactions-refund-a-transaction
        // For refunds, use XXXX instead of the card expiration date.
        $creditCard->setExpirationDate("XXXX");
        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType( "refundTransaction");
        $transactionRequestType->setAmount($amountToRefund);
        $transactionRequestType->setPayment($paymentOne);
        $transactionRequestType->setRefTransId($transaction_id);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setRefId($transaction_id);
        $request->setTransactionRequest( $transactionRequestType);

        $controller = new AnetController\CreateTransactionController($request);

        $response = null;
        if('test' === $this->woocommerce_authorize_net_cim_credit_card_settings['environment']) {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else {
            $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }

        $tresponse = null;
        if ((null !== $response) && (self::AUTHORIZENET_RESPONSE_CODES['RESPONSE_OK'] === $response->getMessages()->getResultCode())) {
            $tresponse = $response->getTransactionResponse();
        }
        else {
            $errorMessages = $response->getMessages()->getMessage();
            error_log('NoFraud error: NoFraud_Authorize_Net_Cim_Credit_Card refund(): ' . print_r($errorMessages, true) );
        }

        return $tresponse;
    }
}
