<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\InvoiceRenewal;

defined('ABSPATH') || exit;

/**
 * What the order meta says about the Paymos invoice currently attached to it.
 */
final class InvoiceState
{
    /**
     * Whether the order meta suggests the attached invoice can no longer be
     * paid: it ended unpaid (underpaid, expired, cancelled), or nobody started
     * it before its deadline passed. A paid invoice never does.
     *
     * A hint only, read from local meta so the My Account list makes no API
     * call per order row. The stored deadline is the one from creation, which
     * the server extends without a webhook when the buyer picks a network, so
     * the hint can say "ended" for an invoice that is still open. It only
     * chooses which link My Account shows; Gateway::process_payment() asks the
     * server before any invoice is replaced.
     *
     * @param \WC_Order $order
     * @param int|null  $now
     * @return bool
     */
    public static function needsNewInvoice($order, $now = null)
    {
        if (!method_exists($order, 'get_meta')) {
            return false;
        }

        return InvoiceRenewal::isRequired(array(
            'status' => (string) $order->get_meta('_paymos_last_status', true),
            'expires_at' => (string) $order->get_meta('_paymos_expires_at', true),
        ), $now);
    }
}
