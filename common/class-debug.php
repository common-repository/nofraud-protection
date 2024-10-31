<?php
namespace WooCommerce\NoFraud\Common;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Payment\Transactions\Transaction_Manager;

final class Debug {
    /**
     * Registers the class's hooks and actions with WordPress.
     */
    public static function register() {
        $instance = new self();

        if (Environment::is_debug_enabled()) {
            add_action('admin_notices', [$instance, 'nf_admin_notices']);
        }
    }

    /**
     * Add debug message if debug mode enabled
     *
     * @param string debug message to show
     */
    public static function add_debug_message($msg) {
        if (Environment::is_debug_enabled()) {
            //check if transaction object, if so, remove sensitive fields
            if (is_array($msg) && !empty($msg['transaction_data']['customer'])) {
                unset($msg['transaction_data']['customerIP']);
                unset($msg['transaction_data']['customer']);
                unset($msg['transaction_data']['billTo']);
                unset($msg['transaction_data']['shipTo']);
                unset($msg['transaction_data']['payment']);
            }

            $upload_dir   = wp_upload_dir();
            $filename = $upload_dir['basedir'] . '/nofraud_debug.log';

            // truncate log if > 1MB
            if (file_exists($filename)) {
                if (filesize($filename) > 1000000) {
                    $fp = fopen($filename, "w");
                    fclose($fp);
                }
            }


            $fp = fopen( $filename, "a" );
            fwrite($fp, "*********\n");
            fwrite($fp, "NoFraud Debug: " . date('Y-m-d H:i') . "\n");
            fwrite($fp, "=========\n");

            if (is_string($msg)) {
                fwrite($fp, htmlentities($msg) . "\n");
            }
            else if (is_array($msg)) {
                fwrite($fp, print_r($msg, true) . "\n");
            }

            fwrite($fp, "=========\n\n");
            fclose( $fp );
        }
    }

    /**
     * Write Debug Output to file
     *
     * @return void
     *
     * @since 4.0.2
     */
    public function nf_admin_notices() {
        echo '<div class="notice notice-info is-dismissible"><p><b>NoFraud Debug Mode Enabled:</b></p>';

        $upload_dir   = wp_upload_dir();
        $filename = $upload_dir['basedir'] . '/nofraud_debug.log';
        $fileurl = $upload_dir['baseurl'] . '/nofraud_debug.log';

        if (file_exists($filename)) {

            if (!empty($_GET['nfclearlog'])) {
                $fh = fopen($filename,'w');
                fclose($fh);
                echo '<p>Notice: nfclearlog parameter detected. Log cleared.</p>';
            }

            // grab a order and try to voidrefund it, will only work on process/hold orders that are placed in test environment
            if (!empty($_GET['nftestvoidrefundorder'])) {
                $order = wc_get_order($_GET['nftestvoidrefundorder']);
                if (!empty($order)) {
                    // check that order is a test order
                    $_wc_authorize_net_cim_credit_card_environment = $order->get_meta('_wc_authorize_net_cim_credit_card_environment');
                    if ('test' === $_wc_authorize_net_cim_credit_card_environment) {
                        $transaction_manager = new Transaction_Manager();
                        $transaction_review = (object) array(
                            'decision'=>'fail'
                        );
                        $transaction_manager->transition_order_status($order->get_id(), $transaction_review);
                        echo '<p>Notice: nftestvoidrefundorder parameter detected. Attempted voidrefund on said order.</p>';
                    }
                    else {
                        echo '<p>Notice: nftestvoidrefundorder parameter detected. Order not valid for voidrefund.</p>';
                    }
                }
                else {
                    echo '<p>Notice: nftestvoidrefundorder parameter detected. Order not found.</p>';
                }
            }

            echo sprintf('<p>Debug output can be found in nofraud_debug.log in the Wordpress upload folder (<a href="%s">%s</a>).</p></div>', $fileurl, $filename);
        }
        else {
            echo sprintf('<p>Debug output will be written to nofraud_debug.log in the Wordpress upload folder (%s).</p></div>', $filename);
        }
    }
}

Debug::register();