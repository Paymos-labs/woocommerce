<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

/** Refresh both native order and metadata caches only inside the owned order mutex. */
final class FreshOrder
{
    public static function load($orderId, WordPressOrderLock $lock)
    {
        $lock->assertOwned();
        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }
        $store = $order->get_data_store();
        // WC factory OrderCache can return an existing object; HPOS read() can
        // itself hit a second raw-row cache. Invalidate that before native read.
        if ($store->has_callable('clear_cached_data')) {
            $store->clear_cached_data(array((int) $orderId));
        }
        // HPOS has a separate orders_meta cache. Raw-row eviction only clears
        // it when a raw row existed, and WC_Data's force flag does not bypass it.
        $metaClass = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStoreMeta';
        if (function_exists('wc_get_container') && class_exists($metaClass)) {
            $metaStore = wc_get_container()->get($metaClass);
            if (is_callable(array($metaStore, 'clear_cached_data'))) {
                $metaStore->clear_cached_data(array((int) $orderId));
            }
        }
        // CPT uses WordPress post/postmeta caches; harmless for an HPOS order.
        clean_post_cache((int) $orderId);
        $store->read($order);
        // Rebuild WC_Data's outer metadata after both native caches are evicted.
        $order->read_meta_data(true);
        $lock->assertOwned();
        return $order;
    }
}
