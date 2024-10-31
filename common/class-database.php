<?php

namespace WooCommerce\NoFraud\Common;

use WooCommerce\NoFraud\Payment\Transactions\Constants;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class Database {
    public static $cached_data = [];

    /**
     * Update/insert into nf_transactions table
     *
     * @param int $order_id
     * @param string $key
     * @param string $value
     * @return boolean
     *
     * @since 4.2.0
     */
    public static function update_nf_data($order_id, $key, $value) {
        global $wpdb;

        if (is_array($value)) {
            $value = json_encode($value);
        }
        else {
            if (is_object($value)) {
                $value = serialize($value);
            }
        }


        $tablename = $wpdb->prefix . "nf_transactions";
        $sql = $wpdb->prepare("SELECT * FROM {$tablename} WHERE meta_key = %s AND order_id = %d", $key, $order_id);
        $results = $wpdb->get_results($sql, ARRAY_A);

        if (!empty($results)) {
            $sql = $wpdb->prepare("UPDATE {$tablename} SET meta_value = %s WHERE meta_key = %s AND order_id = %d", $value, $key, $order_id);
            $results = $wpdb->query($sql);
        }
        else {
            $results = $wpdb->insert($tablename, ['order_id' => $order_id, 'meta_key' => $key, 'meta_value' => $value]);
        }

        unset(self::$cached_data[$order_id]);

        return $results;
    }

    /**
     * Get value from nf_transactions table
     *
     * @param int $order_id
     * @param string $key
     * @return mixed
     *
     * @since 4.2.0
     */
    public static function get_nf_data($order_id, $key, $useFallback = true) {
        global $wpdb;

        if (!empty(self::$cached_data[$order_id])) {
            if (!empty(self::$cached_data[$order_id][$key])) {
                return self::$cached_data[$order_id][$key];
            }
        }

        $tablename = $wpdb->prefix . "nf_transactions";
        $sql = $wpdb->prepare("SELECT * FROM {$tablename} WHERE meta_key = %s AND order_id = %d", $key, $order_id);
        $results = $wpdb->get_results($sql, ARRAY_A);

        $order_nf_data = [];

        if (!empty(self::$cached_data[$order_id])) {
            $order_nf_data = self::$cached_data[$order_id];
        }

        if (!empty($results)) {
            foreach($results as $metaObj) {
                if (is_serialized($metaObj['meta_value'])) {
                    $order_nf_data[$metaObj['meta_key']] = maybe_unserialize($metaObj['meta_value']);
                }
                else {
                    $decodedJson = json_decode($metaObj['meta_value'], true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $order_nf_data[$metaObj['meta_key']] = $decodedJson;
                    }
                    else {
                        $order_nf_data[$metaObj['meta_key']] = $metaObj['meta_value'];
                    }
                }
            }
        }

        if (!empty($order_nf_data)) {
            if (count(self::$cached_data) > 2) {
                self::$cached_data = [];
            }
            self::$cached_data[$order_id] = $order_nf_data;
        }

        if (!empty(self::$cached_data[$order_id][$key])) {
            return self::$cached_data[$order_id][$key];
        }

        if ($useFallback) {
            return self::fallback_get_nf_data($order_id, $key);
        }

        return null;
    }

    /**
     * Get value from WC meta or post meta as a fallback
     *
     * @param int $order_id
     * @param string $key
     * @return mixed
     *
     * @since 4.2.0
     */
    public static function fallback_get_nf_data($order_id, $key) {
        $returnVal = null;

        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            $returnVal = $order->get_meta($key, true);
        }

        if (empty($returnVal)) {
            $returnVal = get_post_meta($order_id, $key, true);
        }

        return $returnVal;
    }

    /**
     * Delete from nf_transactions table
     *
     * @param int $order_id
     * @param string $key
     * @return boolean
     *
     * @since 4.2.0
     */
    public static function delete_nf_data($order_id, $key) {
        global $wpdb;

        $tablename = $wpdb->prefix . "nf_transactions";
        $sql = $wpdb->prepare("DELETE FROM {$tablename} WHERE meta_key = %s AND order_id = %d", $key, $order_id);
        $results = $wpdb->query($sql);

        if (!empty(self::$cached_data[$order_id])) {
            unset(self::$cached_data[$order_id]);
        }

        return $results;
    }

    /**
     * Get all orders with TRANSACTION_STATUS_WORKAROUND_KEY
     *
     * @return array
     *
     * @since 4.2.0
     */
    public static function get_status_workaround_array() {
        global $wpdb;

        $tablename = $wpdb->prefix . "nf_transactions";
        $sql = $wpdb->prepare("SELECT * FROM {$tablename} WHERE meta_key = %s", Constants::TRANSACTION_STATUS_WORKAROUND_KEY);
        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results;
    }
}