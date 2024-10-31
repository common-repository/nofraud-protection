<?php

// Two plugins are covered by this Payment Method
//      Plugin: Authorize.Net CIM for WooCommerce by Cardpay
//      Plugin: Authorize.Net Payment Gateway For WooCommerce By Pledged Plugins

namespace WooCommerce\NoFraud\Payment\Methods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

use WooCommerce\NoFraud\Common\Debug;
use WooCommerce\NoFraud\Common\Database;

final class NoFraud_Authnet extends NoFraud_Payment_Method {
    
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
    private $woocommerce_plugin_settings;
    
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
        //get plugin settings and decode
        $woocommerce_plugin_settings = get_option('woocommerce_authnet_settings', []);
        $this->woocommerce_plugin_settings = $woocommerce_plugin_settings;
    }
    
    public function collect( $order_data, $payment_data ) {
        $transaction_data = parent::collect($order_data, $payment_data);

        $transaction_id = $order_data['transaction_id'];

        Debug::add_debug_message([
            'function' => 'NoFraud_Authnet:collect():start',
            'order_id' => $order_data['id'],
            'transaction_id' => $transaction_id,
        ]);

        //sanity check data
        if (empty($transaction_id)) {
            error_log('NoFraud error: NoFraud_Authnet collect(): Transaction ID missing from Order Data.');
            return $transaction_data;
        }

        //select API set depending on environment
        $apiLogin = '';
        $apiTransactionKey = '';

        // determine what plugin was used for this order
        $isCardpayPlugin = false;
        $isPledgedPlugin = Database::get_nf_data($order_data['id'], 'Authorize.Net Payment ID');
        if (!empty($isPledgedPlugin)) {
            // Plugin: Authorize.Net Payment Gateway For WooCommerce By Pledged Plugins
            $apiLogin = $this->woocommerce_plugin_settings['login_id'];
            $apiTransactionKey = $this->woocommerce_plugin_settings['transaction_key'];
            Debug::add_debug_message([
                'function' => 'NoFraud_Authnet:collect():isPledgedPlugin',
                'order_id' => $order_data['id'],
            ]);
        }
        else {
            // not exactly true but we don't have any other order identifiers to key in on
            // -- could check active plugins list first
            $isCardpayPlugin = true;
            Debug::add_debug_message([
                'function' => 'NoFraud_Authnet:collect():isCardpayPlugin',
                'order_id' => $order_data['id'],
            ]);
            // Plugin: Authorize.Net CIM for WooCommerce by Cardpay
            $apiLogin = $this->woocommerce_plugin_settings['api_login'];
            $apiTransactionKey = $this->woocommerce_plugin_settings['transaction_key'];
        }

        if (empty($apiLogin) || empty($apiTransactionKey)) {
            error_log('NoFraud error: woocommerce_authnet_settings missing required parameter(s).');
            return $transaction_data;
        }

        //setup API request
        $this->merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $this->merchantAuthentication->setName($apiLogin);
        $this->merchantAuthentication->setTransactionKey($apiTransactionKey);

        /*
            Information to collect:
                $transaction_data['cvvResultCode']
                $transaction_data['avsResultCode']
        
            Information to overwrite if available: (From: $response->transaction->payment->creditCard)
                $transaction_data['payment']['creditCard']['cardType']: Comes in (e.g "Visa")
                $transaction_data['payment']['creditCard']['last4']: Masked from cardNumber (e.g. "XXXX0027")
                $transaction_data['payment']['creditCard']['expirationDate']: Not available (e.g. "XXXX") : Can grab from POST data
                    - Not available through API, see:
                        https://community.developer.authorize.net/t5/Integration-and-Testing/How-to-get-card-expiration-date-unmasked-from-transaction/td-p/71707
                $transaction_data['payment']['creditCard']['bin']: Not available
        */
        
        //retrieve transaction details by transaction ID
        $request = new AnetAPI\GetTransactionDetailsRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setTransId($transaction_id);
        
        $controller = new AnetController\GetTransactionDetailsController($request);
        
        $response = null;
        
        // For CardPay, if $this->woocommerce_plugin_settings['sandbox'] is 'yes', the default plugin uses their own API credentials
        // so for debug purposes, if using sandbox, make sure to use a custom modified version, replacing their
        // $this->_api_login and $this->_transaction_key settings inside if ( 'yes' == $gateway->sandbox )
        if(
            (
                $isPledgedPlugin && 'yes' === $this->woocommerce_plugin_settings['testmode']
            )
            ||
            (
                $isCardpayPlugin && 'yes' === $this->woocommerce_plugin_settings['sandbox']
            )
        ) {
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
            
            if(!empty($creditCardMaskedTypeObj->getCardType())) {
                $transaction_data['payment']['creditCard']['cardType'] = sanitize_text_field($creditCardMaskedTypeObj->getCardType());
                
            }
            
            if(!empty($creditCardMaskedTypeObj->getCardNumber())) {
                $apiLast4CardNumber = substr($creditCardMaskedTypeObj->getCardNumber(),-4);
                if(ctype_digit($apiLast4CardNumber) && 4 === strlen($apiLast4CardNumber)) {
                    $transaction_data['payment']['creditCard']['last4'] = sanitize_text_field($apiLast4CardNumber);
                }
            }
            
            //if CVV available in POST, grab
            if(!empty($_POST['authnet-card-cvc'])) {
                $transaction_data['payment']['creditCard']['cardCode'] = sanitize_text_field($_POST['authnet-card-cvc']);
            }
    
            //if expiration available in POST, grab
            if(!empty($_POST['authnet-card-expiry']) && strlen($_POST['authnet-card-expiry']) >= 4) {
                $potentialExpirationDate = substr($_POST['authnet-card-expiry'],0,2) . substr($_POST['authnet-card-expiry'],-2);
                $potentialExpirationDate = preg_replace('/\D/', '', $potentialExpirationDate);
        
                if(strlen($potentialExpirationDate) == 4) {
                    $transaction_data['payment']['creditCard']['expirationDate'] = $potentialExpirationDate;
                }
            }

            //if BIN available in POST, grab
            if(!empty($_POST['authnet-card-number']) && strlen($_POST['authnet-card-number']) >= 7) {
                $potentialBIN = str_replace(' ','', $_POST['authnet-card-number']);
                $potentialBIN = substr($potentialBIN, 0, 6);

                if(strlen($potentialBIN) == 6) {
                    $transaction_data['payment']['creditCard']['bin'] = $potentialBIN;
                }
            }
        }
        else
        {
            $errorMessages = $response->getMessages()->getMessage();
            error_log('NoFraud error: NoFraud_Authnet collect(): ' . print_r($errorMessages, true) );
        }

        Debug::add_debug_message([
            'function' => 'NoFraud_Authnet:collect():end',
            'order_id' => $order_data['id'],
        ]);

        return $transaction_data;
    }
}
