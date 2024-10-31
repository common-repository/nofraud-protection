<?php

namespace WooCommerce\NoFraud\Pages;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Api\Api;
use WooCommerce\NoFraud\Common\Environment;

final class Device_Javascript_Pages {

	/**
	 * "post_name" of pages that should be tracked by loading device JavaScripts.
	 *
	 * @var array "post_name" of pages that should be tracked by loading device JavaScripts.
	 */
	const TRACKED_PAGE_POST_NAMES = [
		'cart',
		'checkout',
	];

	/**
	 * Device JavaScript URL format.
	 *
	 * @var string Device JavaScript URL format.
	 */
	const DEVICE_JAVASCRIPT_URL = 'https://services.nofraud.com/js/%d/customer_code.js';

	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		$instance = new self();

		// Get and store merchant data.
		add_action('woocommerce_update_options_nofraud', [$instance, 'update_merchant'], 11);

		// Enqueue Device JavaScript to certain pages.
		add_action('wp_enqueue_scripts', [$instance, 'enqueue_device_javascript']);
	}

	/**
	 * Update merchant data.
	 *
	 * @since 2.1.0
	 */
	public function update_merchant() {
		$merchant = Api::get_merchant();
		update_option('woocommerce_nofraud_merchant', $merchant);
	}

	/**
	 * Enqueue Device JavaScript to tracked pages.
	 *
	 * @since 2.1.0
	 */
	public function enqueue_device_javascript() {
		global $post;

		// Early exit, if this request is not a tracked page.
		if (!is_page() || !in_array($post->post_name, self::TRACKED_PAGE_POST_NAMES)) {
			return;
		}

		// Add device JavaScript to the tracked page.
		$merchant = get_option('woocommerce_nofraud_merchant', null);
		if (empty($merchant->id)) {
			return;
		}
		wp_enqueue_script('nofraud-protection-device-javascript', sprintf(self::DEVICE_JAVASCRIPT_URL, $merchant->id), [], Environment::PLUGIN_VERSION, true);
	}
}

Device_Javascript_Pages::register();
