<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('paymos_woocommerce_credentials_v1');
delete_option('paymos_woocommerce_bootstrap_fingerprint');
delete_option('woocommerce_paymos_settings');
