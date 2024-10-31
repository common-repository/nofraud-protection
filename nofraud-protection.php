<?php
/**
 * Plugin Name: NoFraud Protection for WooCommerce
 * Plugin URI: https://nofraud.com
 * Description: Eliminate fraudulent orders on your WooCommerce store with NoFraud. If you get a fraud chargeback, NoFraud will pay you back! Visit www.nofraud.com to learn more.
 * Version: 4.4.4
 * Author: NoFraud
 * Requires PHP: 5.6
 * Requires at least: 5.6
 * Tested up to: 6.6.2
 * WC requires at least: 3.0.0
 * WC tested up to: 9.3.3
 * License: GPL-3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: nofraud-protection
 * */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

define('NOFRAUD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOFRAUD_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('NOFRAUD_PLUGIN_PATH', plugin_dir_path(__FILE__));

require_once(NOFRAUD_PLUGIN_PATH . '/common/defines.php');
require_once(NOFRAUD_PLUGIN_COMMON_PATH . '/class-loader.php');
