<?php

namespace WooCommerce\NoFraud\Common;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CreditCardTypeDetector
{
    public static function detect($card)
    {
        if(preg_match("/^4[0-9]{0,15}$/i", $card)) {
            return 'Visa';
        }
        if(preg_match("/^5[1-5][0-9]{5,}|222[1-9][0-9]{3,}|22[3-9][0-9]{4,}|2[3-6][0-9]{5,}|27[01][0-9]{4,}|2720[0-9]{3,}$/i", $card)) {
            return 'MasterCard';
        }
        if(preg_match("/^3$|^3[47][0-9]{0,13}$/i", $card)) {
            return 'Amex';
        }
        if(preg_match("/^6$|^6[05]$|^601[1]?$|^65[0-9][0-9]?$|^6(?:011|5[0-9]{2})[0-9]{0,12}$/i", $card)) {
            return 'Discover';
        }
        if(preg_match("/^(?:2131|1800|35[0-9]{3})[0-9]{3,}$/i", $card)) {
            return 'JCB';
        }
        if(preg_match("/^3(?:0[0-5]|[68][0-9])[0-9]{4,}$/i", $card)) {
            return 'DinersClub';
        }
        
        return 'Unknown';
    }
}