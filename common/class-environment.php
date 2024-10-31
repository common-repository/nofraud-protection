<?php

namespace WooCommerce\NoFraud\Common;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class Environment {

	/**
	 * Plugin name.
	 *
	 * @var string Plugin name.
	 */
	const PLUGIN_NAME = 'NoFraud Protection for WooCommerce';

	/**
	 * Plugin version.
	 *
	 * @var string Plugin version.
	 */
	const PLUGIN_VERSION = '4.4.4';

	/**
	 * Plugin site.
	 *
	 * @var string Plugin site.
	 */
	const SITE_DOMAIN = 'https://nofraud.com';

	/**
	 * Service URLs.
	 *
	 * @var array Service URLs.
	 */
	const SERVICE_URLS = [
		'local' => [
			'portal' => 'http://localhost:3000',
			'portal-api' => 'http://portal:3000',
			'api' => 'http://api:9000',
			'helpcenter' => 'https://helpcenter.nofraud.com',
            'checkoutscript' => 'https://dynamic-checkout-test.nofraud-test.com/latest/scripts/nf-src-woocommerce.js',
		],
		'test' => [
            'portal' => 'https://portal.nofraud.com',
            'portal-api' => 'https://portal-api.nofraud.com',
            'api' => 'https://apitest.nofraud.com',
            'helpcenter' => 'https://helpcenter.nofraud.com',
            'checkoutscript' => 'https://dynamic-checkout-test.nofraud-test.com/latest/scripts/nf-src-woocommerce.js',
		],
        'dev' => [
            'portal' => 'https://portal-ami-qe2.nofraud-test.com',
            'portal-api' => 'https://portal-ami-qe2.nofraud-test.com',
            'api' => 'https://api-qe2.nofraud-test.com/',
            'helpcenter' => 'https://helpcenter.nofraud.com',
            'checkoutscript' => 'https://dynamic-checkout-test.nofraud-test.com/latest/scripts/nf-src-woocommerce.js',
        ],
		'qa' => [
            'portal' => 'https://portal-ami-qe2.nofraud-test.com',
            'portal-api' => 'https://portal-ami-qe2.nofraud-test.com',
            'api' => 'https://api-qe2.nofraud-test.com/',
			'helpcenter' => 'https://helpcenter.nofraud.com',
            'checkoutscript' => 'https://cdn-checkout-qe2.nofraud-test.com/scripts/nf-src-woocommerce.js',
		],
		'live' => [
			'portal' => 'https://portal.nofraud.com',
			'portal-api' => 'https://portal-api.nofraud.com',
			'api' => 'https://api.nofraud.com',
			'helpcenter' => 'https://helpcenter.nofraud.com',
            'checkoutscript' => 'https://cdn-checkout.nofraud.com/scripts/nf-src-woocommerce.js',
		],
	];

	/**
	 * Get a certain service's URL.
	 *
	 * @param string $service_name The service's name.
	 * @return string The service's URL.
	 *
	 * @since 2.0.0
	 */
	public static function get_service_url( $service_name ) {
		return self::SERVICE_URLS[self::get_mode()][$service_name];
	}

	/**
	 * Get the current site environment / running mode.
	 *
	 * @return string The current mode. Can be one of "local", "test", "qa", or "live".
	 *
	 * @since 2.0.0
	 */
	private static function get_mode() {
		$live_mode = get_option('woocommerce_nofraud_live_mode');
		if ('yes' === $live_mode) {
			return 'live';
		}

		$development_mode = get_option('woocommerce_nofraud_development_mode');
		if (in_array($development_mode, ['local', 'qa', 'dev'])) {
			return $development_mode;
		}
		
		return 'test';
	}

    /**
     * Evaluate and return status of debug mode
     *
     * @return boolean Is debug mode enabled?
     *
     * @since 4.0.2
     */
    public static function is_debug_enabled() {
        static $is_debug_mode = 'unknown';

        if ('unknown' != $is_debug_mode) {
            return $is_debug_mode;
        }

        $is_debug_mode = get_option('woocommerce_nofraud_debug_mode');
        if ('yes' === $is_debug_mode) {
            $is_debug_mode = true;
        }
        else {
            $is_debug_mode = false;
        }

        return $is_debug_mode;
    }
}