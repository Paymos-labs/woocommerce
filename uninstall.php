<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

$paymosCleanup = static function () {
    global $wpdb;
    foreach (array('paymos_woocommerce_credentials_v1', 'paymos_woocommerce_bootstrap_fingerprint', 'woocommerce_paymos_settings', 'paymos_woocommerce_connect_state_v1', 'paymos_woocommerce_event_schema', 'paymos_woocommerce_reconcile_waiting_cursor', 'paymos_woocommerce_reconcile_closed_cursor') as $option) {
        delete_option($option);
    }
    wp_unschedule_hook('paymos_reconcile_orders');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove pre-v2 adapter-owned transient fallback rows during uninstall only.
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like('_transient_paymos_evt_') . '%', $wpdb->esc_like('_transient_timeout_paymos_evt_') . '%'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only this adapter's runtime deduplication table, never order history.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'paymos_webhook_events'));
};
if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $paymosBlogId) {
        switch_to_blog((int) $paymosBlogId);
        try {
            $paymosCleanup();
        } finally {
            restore_current_blog();
        }
    }
} else {
    $paymosCleanup();
}
unset($paymosCleanup, $paymosBlogId);
