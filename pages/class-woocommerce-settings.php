<?php

namespace WooCommerce\NoFraud\Pages;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Environment;
use WooCommerce\NoFraud\Common\Gateways;
use WooCommerce\NoFraud\Common\Database;
use WooCommerce\NoFraud\Payment\Transactions\Transaction_Manager;
use WooCommerce\NoFraud\Payment\Transactions\Constants;

final class WooCommerce_Settings {

	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		$instance = new self();

        // Register admin_init
        add_action( 'admin_init', [$instance, 'admin_init'] );
		// Add NoFraud setting tab to setting tabs group.
		add_filter('woocommerce_settings_tabs_array', [$instance, 'add_setting_to_woocommerce_settings_tab'], 50);
		// Add NoFraud setting tab itself.
		add_action('woocommerce_settings_tabs_nofraud', [$instance, 'add_settings_tab']);
		// Update by NoFraud setting tab.
		add_action('woocommerce_update_options_nofraud', [$instance, 'update_settings_tab'], 10);
    }

    /**
     * Check plugin version and run dbDelta if different, check for order statuses to update
     *
     * @since 4.2.0
     */
    public function admin_init() {
        global $wpdb;

        $current_version_in_db = get_option('woocommerce_nofraud_protection_version', false);
        if (empty($current_version_in_db) || $current_version_in_db !== Environment::PLUGIN_VERSION) {
            update_option('woocommerce_nofraud_protection_version', Environment::PLUGIN_VERSION);

            // setup NF database table
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = '';
            if ( ! empty($wpdb->charset) )
                $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
            if ( ! empty($wpdb->collate) )
                $charset_collate .= " COLLATE $wpdb->collate";

            $nf_transactions_table_schema ="CREATE TABLE {$wpdb->prefix}nf_transactions (
                id bigint(20) unsigned NOT NULL auto_increment,
                order_id bigint(20) unsigned NOT NULL,
                meta_key varchar(64) default NULL,
                meta_value LONGTEXT default NULL,
                PRIMARY KEY  (id),
	            KEY order_id (order_id)                
                ) $charset_collate;";

            dbDelta($nf_transactions_table_schema);
        }

        // check if there are any order statuses to re-process (HPOS sync workaround)
        $nf_orders_to_process = get_option('woocommerce_nofraud_protection_orders_to_process', false);
        if (!empty($nf_orders_to_process)) {
            update_option('woocommerce_nofraud_protection_orders_to_process', false, true);

            // get orders with re-process and process
            $ordersArray = Database::get_status_workaround_array();
            if (!empty($ordersArray)) {
                foreach($ordersArray as $nfdataObj) {
                    if (!empty($nfdataObj['meta_value'])) {
                        $workaroundArray = json_decode($nfdataObj['meta_value'], true);

                        if (!empty($workaroundArray['to_status'])) {
                            $order = wc_get_order($nfdataObj['order_id']);

                            $currentOrderStatusStr = $order->get_status();
                            // do not transition finished orders
                            $doNotTransitionArray = apply_filters('woocommerce_nofraud_do_not_transition_statuses', Transaction_Manager::DO_NOT_TRANSITION_STATUSES);
                            $skipThisObj = false;
                            foreach ($doNotTransitionArray as $doNotTransitionStatus) {
                                if (stripos($currentOrderStatusStr, $doNotTransitionStatus) !== false) {
                                    $skipThisObj = true;
                                }
                            }

                            if ($skipThisObj) {
                                wc_create_order_note($nfdataObj['order_id'], $workaroundArray['order_transition_note']);
                                continue;
                            }

                            $order->set_status($workaroundArray['to_status']);
                            $order->save();

                            // Add order notes for this transition.
                            if (!empty($workaroundArray['to_status'])) {
                                wc_create_order_note($nfdataObj['order_id'], $workaroundArray['order_note']);
                            }
                        }

                        Database::delete_nf_data($nfdataObj['order_id'],Constants::TRANSACTION_STATUS_WORKAROUND_KEY);
                    }
                }
            }
        }
    }

	/**
	 * Add a new "NoFraud" settings tab to the WooCommerce settings tabs array.
	 *
	 * @param string[] $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the NoFraud tab.
	 * @return string[] Array of WooCommerce setting tabs & their labels, including the NoFraud tab.
	 *
	 * @since 2.0.0
	 */
	public function add_setting_to_woocommerce_settings_tab( $settings_tabs ) {
		$settings_tabs['nofraud'] = __('NoFraud', 'nofraud-protection');
		return $settings_tabs;
	}

	/**
	 * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
	 *
	 * @since 2.0.0
	 */
	public function add_settings_tab() {
		woocommerce_admin_fields($this->get_settings());
	}

	/**
	 * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
	 *
	 * @since 2.0.0
	 */
	public function update_settings_tab() {
		woocommerce_update_options($this->get_settings());
	}

	/**
	 * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
	 *
	 * @return array Array of settings for @see woocommerce_admin_fields() function.
	 */
	private function get_settings() {
		// The 1st group of settings - Key and Mode.
		$api_key_message = 'Your NoFraud API Key is validated and good to go.';

		$no_merchant = empty(get_option('woocommerce_nofraud_merchant', ''));
		$no_api_key = empty(get_option('woocommerce_nofraud_api_key', ''));
		if ($no_merchant) {
			$api_key_message = 'Your NoFraud API Key does not appear to be valid. ';
		}
		if ($no_api_key) {
			$api_key_message = 'Please enter a NoFraud API Key. ';
		}
		if ($no_api_key || $no_merchant) {
			$portal_url = Environment::get_service_url('portal');
			$helpcenter_url = Environment::get_service_url('helpcenter');
			$sign_up_url = esc_url($portal_url . '/users/sign_up');
			$guide_url = esc_url($helpcenter_url . '/hc/en-us/articles/4403350322196-WooCommerce-Plugin-Install-Guide');
			$integration_url = esc_url($portal_url . '/integration');
			$api_key_message .= 'You can <a href="' . $sign_up_url . '" target="_blank">sign up</a> for free and follow the <a href="' . $guide_url . '" target="_blank">guide</a> to get your NoFraud API Key from NoFraud Portal\'s <a href="' . $integration_url . '" target="_blank">Integration page</a>.';
		}

		$order_statuses = wc_get_order_statuses();
		$updated_order_statuses = [];
		foreach ($order_statuses as $order_status_key => $order_status) {
			$updated_order_status_key = preg_replace('/^wc-/', '', $order_status_key);
			$updated_order_statuses[$updated_order_status_key] = $order_status;
		}
        $updated_order_statuses['donothing'] = 'Do Nothing';

        $transaction_types = [
            'CAPTURE' => 'CAPTURE',
            'AUTHORIZE' => 'AUTHORIZE',
            'CAPTUREAUTHORIZE' => 'CAPTURE & AUTHORIZE',
        ];
		
		$settings = [
			[
				'name' => __('NoFraud', 'nofraud-protection'),
				'type' => 'title',
				'desc' => '',
				'id' => 'woocommerce_nofraud_section_title',
			],
			[
				'name' => __('NoFraud API Key', 'nofraud-protection'),
				'type' => 'text',
				'desc' => __($api_key_message, 'nofraud-protection'),
				'id' => 'woocommerce_nofraud_api_key',
				'default' => '',
			],
			[
				'name' => __('Live Mode', 'nofraud-protection'),
				'type' => 'checkbox',
				'desc' => __('Checking this option means your NoFraud plugin is in Live mode. Otherwise the plugin is in Test mode; Test mode is free.', 'nofraud-protection'),
				'id' => 'woocommerce_nofraud_live_mode',
				'default' => 'no',
			],
            [
                'name' => __('Debug Mode', 'nofraud-protection'),
                'type' => 'checkbox',
                'desc' => __('When enabled, additional diagnostic information will be displayed on WP Admin screens that can help NoFraud support troubleshoot issues with the plugin.', 'nofraud-protection'),
                'id' => 'woocommerce_nofraud_debug_mode',
                'default' => 'no',
            ],
            [
                'name' => __('Automatically Void/Refund Order on NoFraud "Fail"', 'nofraud-protection'),
                'type' => 'checkbox',
                'desc' => __('When enabled, payment transaction will automatically be voided/refunded on a NoFraud "fail" decision. (This feature is currently only supported for the SkyVerge Authorize.net plugin)', 'nofraud-protection'),
                'id' => 'woocommerce_nofraud_automatic_voidrefund',
                'default' => 'no',
            ],
            [
                'title' => __('Transaction Status to Initiate Decision Evaluation on', 'nofraud-protection'),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'desc' => __('Capture is the default transaction status. Authorize is only supported on some Gateway types (currently: PayFlow, NMI and SkyVerge Authorize.net)', 'nofraud-protection'),
                'options' => $transaction_types,
                'id' => 'woocommerce_nofraud_transaction_capture',
                'default' => "CAPTUREAUTHORIZE",
            ],
			[
				'title' => __('On NoFraud Decision "Fail", Set Order Status to', 'nofraud-protection'),
				'type' => 'select',
				'class' => 'wc-enhanced-select',
				'desc' => __('Automatically set the Order to the selected Order Status when NoFraud decision is "fail" or "fraudulent".', 'nofraud-protection'),
				'options' => $updated_order_statuses,
				'id' => 'woocommerce_nofraud_to_order_status_by_fail',
				'default' => Transaction_Manager::TRANSACTION_DECISION_SETTINGS['fail']['to-status']['to'],
			],
            [
                'title' => __('On NoFraud Decision "Pass", Set Order Status to', 'nofraud-protection'),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'desc' => __('Automatically set the Order to the selected Order Status when NoFraud decision is "pass".', 'nofraud-protection'),
                'options' => $updated_order_statuses,
                'id' => 'woocommerce_nofraud_to_order_status_by_pass',
                'default' => Transaction_Manager::TRANSACTION_DECISION_SETTINGS['pass']['to-status']['to'],
            ],
            [
                'title' => __('On NoFraud Decision "Review", Set Order Status to', 'nofraud-protection'),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'desc' => __('Automatically set the Order to the selected Order Status when NoFraud decision is "review", which means NoFraud Analysts are reviewing the order.', 'nofraud-protection'),
                'options' => $updated_order_statuses,
                'id' => 'woocommerce_nofraud_to_order_status_by_review',
                'default' => Transaction_Manager::TRANSACTION_DECISION_SETTINGS['review']['to-status']['to'],
            ],
			[
				'type' => 'sectionend',
				'id' => 'woocommerce_nofraud_section_end',
			],
        ];
        
        $settings[] =
            [
                'name' => __('Gateway Settings', 'nofraud-protection-section-payments'),
                'type' => 'title',
                'desc' => 'This allows you to enable or disable NoFraud Protection on available gateways.',
                'id' => 'woocommerce_nofraud_section_payments_title',
            ];
        
        $paymentGatewaysArray = [];
        
        foreach(Gateways::NOFRAUD_SUPPORTED_GATEWAYS as $gatewayName => $gatewayValues) {
            $paymentGatewaysArray[$gatewayName] = [
                'detectedStr' => '(not detected)',
            ];
        }
        
        if ( !function_exists('is_plugin_active') ) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        foreach(Gateways::NOFRAUD_SUPPORTED_PLUGINS as $pluginLocation => $pluginValues) {
            if ( is_plugin_active( $pluginLocation ) ) {
                $paymentGatewaysArray[$pluginValues['gateway']]['detectedStr'] = '(detected)';
            }
        }

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['acceptblue']['enabled'])) {
            $settings[] =
                [
                    'name' => __('accept.blue ' . $paymentGatewaysArray['braintree']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for accept.blue<br /><br />Supported accept.blue Plugin:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/payment-gateway-accept-blue-for-woocommerce/">' . __('Payment gateway: accept.blue for WooCommerce', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['acceptblue']['key'],
                    'default' => 'yes',
                ];
        }
        
        $settings[] =
            [
                'name' => __('Authorize.net ' . $paymentGatewaysArray['authorize_net']['detectedStr'], 'nofraud-protection-section-payments'),
                'type' => 'checkbox',
                'desc' => 'Check to enable NoFraud screening for Authorize.net transactions<br /><br />Supported Authorize.net Plugins:<br /><a target="_blank" rel="nofollow" href="https://woocommerce.com/products/authorize-net/">' . __('SkyVerge Authorize.net, a Visa solution', 'nofraud-protection-section-payments') . '</a><br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/authnet-cim-for-woo/">' . __('Cardpay Solutions, Inc. Authorize.Net CIM for WooCommerce', 'nofraud-protection-section-payments') . '</a><br /><a target="_blank" rel="nofollow" href="https://pledgedplugins.com/products/authorize-net-payment-gateway-woocommerce/">' . __('Pledged Plugins Authorize.Net Payment Gateway For WooCommerce', 'nofraud-protection-section-payments') . '</a>',
                'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['authorize_net']['key'],
                'default' => 'yes',
            ];

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['bluesnap']['enabled'])) {
            $settings[] =
                [
                    'name' => __('BlueSnap ' . $paymentGatewaysArray['bluesnap']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for BlueSnap<br /><br />Supported BlueSnap Plugin:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/bluesnap-payment-gateway-for-woocommerce/">' . __('BlueSnap Payment Gateway for WooCommerce', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['bluesnap']['key'],
                    'default' => 'yes',
                ];
        }

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['braintree']['enabled'])) {
            $settings[] =
                [
                    'name' => __('Braintree ' . $paymentGatewaysArray['braintree']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for Braintree<br /><br />Supported Braintree Plugin:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/woo-payment-gateway/">' . __('Payment Plugins Braintree For WooCommerce', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['braintree']['key'],
                    'default' => 'yes',
                ];
        }

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['cardknox']['enabled'])) {
            $settings[] =
                [
                    'name' => __('Cardknox ' . $paymentGatewaysArray['cardknox']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for Cardknox<br /><br />Supported Cardknox Plugin:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/woo-cardknox-gateway/">' . __('Cardknox Payment Gateway for WooCommerce', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['braintree']['key'],
                    'default' => 'yes',
                ];
        }

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['intuit']['enabled'])) {
            $settings[] =
                [
                    'name' => __('Intuit Payments ' . $paymentGatewaysArray['intuit']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for Intuit Payments<br /><br />Supported Intuit Payments Plugin:<br /><a target="_blank" rel="nofollow" href="https://woocommerce.com/products/intuit-qbms/">' . __('Intuit Payments Gateway', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['intuit']['key'],
                    'default' => 'yes',
                ];
        }

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['nmi']['enabled'])) {
            $settings[] =
                [
                    'name' => __('NMI ' . $paymentGatewaysArray['nmi']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for NMI transactions<br /><br />Supported NMI Plugin:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/wp-nmi-gateway-pci-woocommerce/">' . __('WP NMI Gateway PCI for WooCommerce By Pledged Plugins (Free/Enterprise)', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['nmi']['key'],
                    'default' => 'yes',
                ];
        }

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['paypal']['enabled'])) {
            $settings[] =
                [
                    'name' => __('Paypal ' . $paymentGatewaysArray['paypal']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for Paypal transactions<br /><br />Supported Paypal Plugins:<br /><a target="_blank" rel="nofollow" href="https://www.angelleye.com/product/woocommerce-paypal-plugin/">' . __('AngellEYE PayPal for WooCommerce (DoDirectPayment/PayFlow)', 'nofraud-protection-section-payments') . '</a><br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/woocommerce-paypal-pro-payment-gateway/">' . __('wp.insider WooCommerce PayPal Pro Payment Gateway', 'nofraud-protection-section-payments') . '</a>',
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['paypal']['key'],
                    'default' => 'yes',
                ];
        }
        
        $settings[] =
            [
                'name' => __('Square ' . $paymentGatewaysArray['square']['detectedStr'], 'nofraud-protection-section-payments'),
                'type' => 'checkbox',
                'desc' => 'Check to enable NoFraud screening for Square transactions<br /><br />Supported Square Plugins:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/woocommerce-square/">' . __('WooCommerce Square', 'nofraud-protection-section-payments') . '</a>',
                'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['square']['key'],
                'default' => 'yes',
            ];
        
        $settings[] =
            [
                'name' => __('Stripe ' . $paymentGatewaysArray['stripe']['detectedStr'], 'nofraud-protection-section-payments'),
                'type' => 'checkbox',
                'desc' => 'Check to enable NoFraud screening for Stripe transactions<br /><br />Supported Stripe Plugins:<br /><a target="_blank" rel="nofollow" href="https://wordpress.org/plugins/woocommerce-gateway-stripe/">' . __('WooCommerce Stripe Gateway', 'nofraud-protection-section-payments') . '</a>',
                'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['stripe']['key'],
                'default' => 'yes',
            ];

        if(!empty(Gateways::NOFRAUD_SUPPORTED_GATEWAYS['worldpay']['enabled'])) {

            $detected_vantiv_env = get_option('woocommerce_vantiv_credit_card_settings', '');
            $detected_vantiv_env_str = '<br /><br />No configured Worldpay environment detected.';

            if (!empty($detected_vantiv_env)) {
                $detected_vantiv_env_str = '<br /><br />Worldpay environment detected: ' . esc_html($detected_vantiv_env['environment']);
            }

            $settings[] =
                [
                    'name' => __('Worldpay Payments ' . $paymentGatewaysArray['worldpay']['detectedStr'], 'nofraud-protection-section-payments'),
                    'type' => 'checkbox',
                    'desc' => 'Check to enable NoFraud screening for Worldpay<br /><br />Supported Worldpay Plugin:<br /><a target="_blank" rel="nofollow" href="https://www.radialstudios.com/woocommerce-plugins/">' . __('WooCommerce Vantiv Gateway', 'nofraud-protection-section-payments') . '</a>' . $detected_vantiv_env_str,
                    'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['worldpay']['key'],
                    'default' => 'yes',
                ];
            $settings[] =
                [
                    'name' => __('Worldpay Profile ID', 'nofraud-protection'),
                    'type' => 'text',
                    'desc' => __('', 'nofraud-protection'),
                    'id' => 'woocommerce_nofraud_worldpay_profile_id',
                    'default' => '',
                ];
            $settings[] =
                [
                    'name' => __('Worldpay Merchant ID', 'nofraud-protection'),
                    'type' => 'text',
                    'desc' => __('', 'nofraud-protection'),
                    'id' => 'woocommerce_nofraud_worldpay_merchant_id',
                    'default' => '',
                ];
            $settings[] =
                [
                    'name' => __('Worldpay Shared Key', 'nofraud-protection'),
                    'type' => 'text',
                    'desc' => __('', 'nofraud-protection'),
                    'id' => 'woocommerce_nofraud_worldpay_shared_key',
                    'default' => '',
                ];
        }

        $settings[] =
            [
                'name' => __('Other Payment Gateways and Plugins', 'nofraud-protection-section-payments'),
                'type' => 'checkbox',
                'desc' => __('If checked, NoFraud will still attempt to make a decision for unsupported payment gateways and plugins', 'nofraud-protection-section-payments'),
                'id' => Gateways::NOFRAUD_SUPPORTED_GATEWAYS['other']['key'],
                'default' => 'yes',
            ];
        
        $settings[] =
            [
                'type' => 'sectionend',
                'id' => 'woocommerce_nofraud_section-payments_end',
            ];

		return apply_filters('woocommerce_nofraud_get_settings', $settings);
	}
}

WooCommerce_Settings::register();
