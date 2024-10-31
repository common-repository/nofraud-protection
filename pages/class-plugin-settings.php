<?php

namespace WooCommerce\NoFraud\Pages;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Environment;

final class Plugin_Settings {

	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		$instance = new self();

		// Add links to Plugins page.
		add_action('plugin_action_links_' . NOFRAUD_PLUGIN_BASENAME, [$instance, 'add_plugin_action_links']);
	}

	/**
	 * Get all the action links like Docs, Support and Settings for this plugin.
	 *
	 * @param string[] $links Array of action links for this plugin.
	 * @return array Array of action links for this plugin.
	 *
	 * @since 2.0.0
	 */
	public function add_plugin_action_links( $links ) {
		$helpcenter_url = Environment::get_service_url('helpcenter');
		return array_merge([
		'<a href="' . esc_url($helpcenter_url . '/hc/en-us') . '" target="_blank">' . __('Docs', 'nofraud-protection') . '</a>',
		'<a href="' . esc_url($helpcenter_url . '/hc/en-us/requests/new') . '" target="_blank">' . __('Support', 'nofraud-protection') . '</a>',
		'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=nofraud')) . '">' . __('Settings', 'nofraud-protection') . '</a>',
		], $links);
	}
}

Plugin_Settings::register();
