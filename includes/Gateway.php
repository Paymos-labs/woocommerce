<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Exception\ApiException;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;

defined('ABSPATH') || exit;

final class Gateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'paymos';
        $this->method_title = __('Paymos', 'paymos-for-woocommerce');
        $this->method_description = __('Accept USDT and USDC at checkout. Settled on-chain in the same stablecoin, no chargebacks.', 'paymos-for-woocommerce');
        $this->icon = apply_filters(
            'paymos_woocommerce_icon',
            plugins_url('assets/img/paymos.svg', PAYMOS_WC_PLUGIN_FILE)
        );
        $this->has_fields = false;
        $this->supports = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('Pay with stablecoins', 'paymos-for-woocommerce'));
        $this->description = $this->get_option('description', __('Pay with USDT or USDC across 13 networks — Tron, Ethereum, Polygon, Base, Solana and more. No price volatility, no chargebacks, settlement on-chain in minutes.', 'paymos-for-woocommerce'));
        $this->enabled = $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'paymos-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Paymos payments', 'paymos-for-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'paymos-for-woocommerce'),
                'type' => 'text',
                'default' => __('Pay with stablecoins', 'paymos-for-woocommerce'),
            ),
            'description' => array(
                'title' => __('Description', 'paymos-for-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pay with USDT or USDC across 13 networks — Tron, Ethereum, Polygon, Base, Solana and more. No price volatility, no chargebacks, settlement on-chain in minutes.', 'paymos-for-woocommerce'),
            ),
            'mode' => array(
                'title' => __('Mode', 'paymos-for-woocommerce'),
                'type' => 'select',
                'default' => 'sandbox',
                'options' => array(
                    'sandbox' => __('Sandbox', 'paymos-for-woocommerce'),
                    'live' => __('Live', 'paymos-for-woocommerce'),
                ),
                'description' => esc_html__('Connect once, test in Sandbox, then switch to Live when you are ready.', 'paymos-for-woocommerce'),
            ),
            'connect' => array(
                'title' => __('Connect Paymos', 'paymos-for-woocommerce'),
                'type' => 'paymos_connect',
                'description' => __('Opens Paymos for approval. Paymos reuses or creates one Payment key per environment and reuses or creates the dedicated webhook for this exact store URL.', 'paymos-for-woocommerce'),
            ),
            'webhook_url' => array(
                'title' => __('Webhook URL', 'paymos-for-woocommerce'),
                'type' => 'paymos_webhook_url',
                'description' => esc_html__('Registered automatically for Sandbox and Live when you connect this store.', 'paymos-for-woocommerce'),
            ),
            'config_status' => array(
                'title' => __('Connection status', 'paymos-for-woocommerce'),
                'type' => 'paymos_config_status',
                'description' => esc_html__('The active environment must be fully configured before Paymos can process checkout.', 'paymos-for-woocommerce'),
            ),
            'debug_logging' => array(
                'title' => __('Debug logging', 'paymos-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Write Paymos logs to WooCommerce logs', 'paymos-for-woocommerce'),
                'default' => 'no',
            ),
        );
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Paymos payment error: order not found.', 'paymos-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        try {
            $environment = Config::mode();
            $config = $this->activeEnvironmentConfig($environment);
            $externalOrderId = $this->externalOrderId($order, $environment, (string) $config['project_id']);

            $payload = array(
                'project_id' => (string) $config['project_id'],
                'amount' => OrderAmountGuard::formatAmount($order->get_total()),
                'currency' => $order->get_currency(),
                'external_order_id' => $externalOrderId,
            );

            $clientId = $this->clientId($order);
            if ($clientId !== '') {
                $payload['client_id'] = $clientId;
            }

            // A new id replaces the stored invoice (another amount, environment
            // or project): close that one on the server first (BUG-166).
            $storedExternalOrderId = $this->storedExternalOrderId($order);
            if ($storedExternalOrderId !== '' && $externalOrderId !== $storedExternalOrderId) {
                $storedEnvironment = (string) $order->get_meta('_paymos_environment', true);
                // The order meta's last status may predate the stored invoice on
                // orders from older versions, so the server is always asked here.
                $this->closeBeforeReplacing(
                    (string) $order->get_meta('_paymos_invoice_id', true),
                    $storedEnvironment !== '' ? $storedEnvironment : $environment,
                    ''
                );
            }

            $invoice = $this->client($config)->invoices()->create($payload);

            // The server answers a repeated external_order_id with the invoice it
            // already made, whatever became of it — so this call is also the read
            // of the live invoice, and the only thing allowed to decide that it
            // is replaced. If it can no longer be paid (it ended unpaid, or
            // nobody started it before the deadline) cut a fresh one. An invoice
            // the server still holds open is kept, whatever the order meta says.
            if ($externalOrderId === $storedExternalOrderId && InvoiceRenewal::isRequired($invoice)) {
                // The status is the server's own answer for the stored id.
                $this->closeBeforeReplacing(
                    isset($invoice['invoice_id']) && is_scalar($invoice['invoice_id']) ? (string) $invoice['invoice_id'] : '',
                    $environment,
                    isset($invoice['status']) && is_scalar($invoice['status']) ? (string) $invoice['status'] : ''
                );
                $externalOrderId = $this->freshExternalOrderId($order);
                $payload['external_order_id'] = $externalOrderId;
                $invoice = $this->client($config)->invoices()->create($payload);
            }
        } catch (InvoiceReplacementBlockedException $e) {
            // The previous invoice may still be paid, so no second one was cut.
            // The order goes on hold for the merchant, with the reason — which
            // is not always the amount: a mode or project change blocks too.
            // The buyer is asked to contact the store rather than to try again
            // or pay another way (BUG-181). The notice is the SDK's
            // InvoiceReplacementBlockedException message, spelled out so the
            // string extractor sees it.
            Logger::error('Paymos invoice was not replaced: ' . $e->result()->summary(), array('order_id' => $order_id));
            $order->update_status('on-hold');
            $order->add_order_note(__('Paymos payment needs manual review.', 'paymos-for-woocommerce') . ' ' . $e->result()->summary());
            wc_add_notice(__('The store needs to review this order before payment can continue. Please contact the store.', 'paymos-for-woocommerce'), 'error');
            return array('result' => 'failure');
        } catch (ApiException $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('order_id' => $order_id));
            wc_add_notice(__('Paymos payment error: unable to create invoice.', 'paymos-for-woocommerce'), 'error');
            return array('result' => 'failure');
        } catch (\RuntimeException $e) {
            Logger::error('Paymos invoice create failed: ' . $e->getMessage(), array('order_id' => $order_id));
            wc_add_notice(__('Paymos payment error: configuration is invalid.', 'paymos-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $invoiceId = isset($invoice['invoice_id']) ? (string) $invoice['invoice_id'] : '';
        $paymentUrl = isset($invoice['payment_url']) ? (string) $invoice['payment_url'] : '';

        if ($invoiceId === '' || $paymentUrl === '') {
            wc_add_notice(__('Paymos payment error: invalid invoice response.', 'paymos-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $order->update_meta_data('_paymos_invoice_id', $invoiceId);
        $order->update_meta_data('_paymos_external_order_id', $externalOrderId);
        $order->update_meta_data('_paymos_payment_url', $paymentUrl);
        $order->update_meta_data('_paymos_environment', $environment);
        $order->update_meta_data('_paymos_project_id', (string) $config['project_id']);
        // The status of THIS invoice, as the server just returned it. The order
        // may carry the final status of a previous invoice (a failed order paid
        // again); left in place, the mapper would treat every event of the new
        // invoice as a stale one.
        $order->update_meta_data('_paymos_last_status', isset($invoice['status']) && is_scalar($invoice['status']) ? (string) $invoice['status'] : '');
        // A hint for My Account, which must not make an API call per order row
        // (see InvoiceState). The server moves this deadline when the buyer
        // picks a network and sends no webhook for it, so it is refreshed here,
        // on every read of the invoice, and never decides a renewal by itself.
        $order->update_meta_data('_paymos_expires_at', isset($invoice['expires_at']) && is_numeric($invoice['expires_at']) ? (string) (int) $invoice['expires_at'] : '');
        OrderAmountGuard::capture($order, $order->get_total(), $order->get_currency());
        $order->update_status('on-hold', __('Awaiting Paymos payment.', 'paymos-for-woocommerce'));
        $order->save();

        Logger::info('Paymos invoice created.', array(
            'order_id' => (string) $order_id,
            'invoice_id' => $invoiceId,
            'environment' => $environment,
            'project_id' => (string) $config['project_id'],
            'amount' => OrderAmountGuard::formatAmount($order->get_total()),
            'currency' => (string) $order->get_currency(),
        ));

        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => $paymentUrl,
        );
    }

    public function is_available()
    {
        return parent::is_available() && Config::has_environment(Config::mode());
    }

    /**
     * Stablecoin payments are settled on-chain and cannot be reversed
     * programmatically — Paymos exposes no refund API. Record the merchant's
     * intent as an order note and decline the automatic refund so WooCommerce
     * never books a refund that did not move on-chain.
     *
     * @param int $order_id
     * @param float|null $amount
     * @param string $reason
     * @return \WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if ($order) {
            $note = sprintf(
                /* translators: 1: refund amount with currency, 2: refund reason (may be empty) */
                __('Paymos refund requested for %1$s. Send the refund on-chain from your Paymos dashboard — WooCommerce did not record an automatic refund. %2$s', 'paymos-for-woocommerce'),
                $amount !== null ? wc_price($amount, array('currency' => $order->get_currency())) : $order->get_formatted_order_total(),
                $reason !== '' ? sprintf(
                    /* translators: %s: merchant-provided refund reason */
                    __('Reason: %s', 'paymos-for-woocommerce'),
                    $reason
                ) : ''
            );
            $order->add_order_note($note);
        }

        return new \WP_Error(
            'paymos_manual_refund',
            __('Paymos refunds are processed manually on-chain. Open the invoice in your Paymos dashboard and send the refund to the customer. No automatic refund was recorded.', 'paymos-for-woocommerce')
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function client(array $config)
    {
        return ClientFactory::create($config);
    }

    /**
     * The order's invoice is about to be replaced. Cancel it on the server
     * first, in its own environment, or the buyer could pay both (BUG-166):
     * the SDK cancels it, or confirms from the server that it ended unpaid.
     * Anything else — paid, still payable, 404, no answer — throws, and
     * process_payment() puts the order on hold instead of cutting a second
     * invoice.
     *
     * @param string $invoiceId
     * @param string $environment
     * @param string $recordedStatus A status the server just reported for it, or ''.
     */
    private function closeBeforeReplacing($invoiceId, $environment, $recordedStatus)
    {
        $result = (new InvoiceReplacement(function () use ($environment) {
            return $this->client($this->activeEnvironmentConfig($environment));
        }))->close($invoiceId, $recordedStatus);

        if (!$result->isClosed()) {
            throw new InvoiceReplacementBlockedException($result);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function activeEnvironmentConfig($environment)
    {
        $config = Config::environment_config($environment);
        foreach (array('api_key', 'api_secret', 'project_id', 'base_url') as $required) {
            if (!isset($config[$required]) || !is_scalar($config[$required]) || trim((string) $config[$required]) === '') {
                throw new \RuntimeException('Paymos credentials are incomplete.');
            }
        }

        return $config;
    }

    private function externalOrderId($order, $environment, $projectId)
    {
        $orderKey = (string) $order->get_order_key();
        $woocommercePrefix = 'wc_order_';
        if (strpos($orderKey, $woocommercePrefix) === 0) {
            $orderKey = substr($orderKey, strlen($woocommercePrefix));
        }

        $base = 'wc_' . $order->get_id() . '_' . $orderKey;

        if (!method_exists($order, 'get_meta')) {
            return $base;
        }

        $existing = (string) $order->get_meta('_paymos_external_order_id', true);
        // Reuse the id whenever it was cut for this amount, environment and
        // project, even if the order meta says the invoice ended or its deadline
        // passed: the meta is the order's, not the invoice's, and the deadline
        // in it is the one from creation, which the server extends when the
        // buyer picks a network (BUG-163). process_payment() asks the server
        // with this id and replaces the invoice only on its answer. Another
        // amount or environment gets a new id, as the server refuses the old
        // one there (409 invoice_idempotency_conflict); another project never
        // cut an invoice under it.
        if ($existing !== '' && OrderAmountGuard::currentMatchesSnapshot($order) && $this->snapshotBelongsTo($order, $environment, $projectId)) {
            return $existing;
        }

        if ($existing !== '') {
            return $this->freshExternalOrderId($order);
        }

        return $base;
    }

    /**
     * Whether the stored invoice was cut in this environment and project. An
     * order from before these were stored carries neither and is taken as
     * matching.
     */
    private function snapshotBelongsTo($order, $environment, $projectId)
    {
        $storedEnvironment = (string) $order->get_meta('_paymos_environment', true);
        $storedProjectId = (string) $order->get_meta('_paymos_project_id', true);

        return ($storedEnvironment === '' || $storedEnvironment === (string) $environment)
            && ($storedProjectId === '' || $storedProjectId === (string) $projectId);
    }

    private function storedExternalOrderId($order)
    {
        return method_exists($order, 'get_meta') ? (string) $order->get_meta('_paymos_external_order_id', true) : '';
    }

    /**
     * A new, never-used external_order_id for this order: the base id plus a
     * timestamp suffix, moved on by a second if it collides with the stored one.
     */
    private function freshExternalOrderId($order)
    {
        $orderKey = (string) $order->get_order_key();
        if (strpos($orderKey, 'wc_order_') === 0) {
            $orderKey = substr($orderKey, strlen('wc_order_'));
        }

        $base = 'wc_' . $order->get_id() . '_' . $orderKey;
        $stamp = time();
        $candidate = $base . '_' . $stamp;
        if ($candidate === $this->storedExternalOrderId($order)) {
            $candidate = $base . '_' . ($stamp + 1);
        }

        return $candidate;
    }

    private function clientId($order)
    {
        if (!method_exists($order, 'get_customer_id')) {
            return '';
        }

        $customerId = trim((string) $order->get_customer_id());
        return $customerId !== '' && $customerId !== '0' ? $customerId : '';
    }

    public function generate_paymos_webhook_url_html($key, $data)
    {
        $fieldKey = $this->get_field_key($key);
        $url = Config::webhook_url();

        return '<tr valign="top">'
            . '<th scope="row" class="titledesc"><label for="' . esc_attr($fieldKey) . '">' . esc_html($data['title']) . '</label></th>'
            . '<td class="forminp">'
            . '<input class="input-text regular-input" type="text" readonly="readonly" id="' . esc_attr($fieldKey) . '" value="' . esc_attr($url) . '" onclick="this.select();" />'
            . '<p class="description">' . esc_html($data['description']) . '</p>'
            . '</td>'
            . '</tr>';
    }

    public function generate_paymos_connect_html($key, $data)
    {
        return '<tr valign="top">'
            . '<th scope="row" class="titledesc">' . esc_html($data['title']) . '</th>'
            . '<td class="forminp"><button type="button" class="button button-primary" id="paymos-connect-button">'
            . esc_html__('Connect Paymos', 'paymos-for-woocommerce')
            . '</button><p id="paymos-connect-status" class="description" aria-live="polite">'
            . esc_html($data['description']) . '</p></td></tr>';
    }

    public function generate_paymos_config_status_html($key, $data)
    {
        $fieldKey = $this->get_field_key($key);
        $mode = Config::mode();
        $sandbox = Config::has_environment('sandbox') ? __('Configured', 'paymos-for-woocommerce') : __('Missing', 'paymos-for-woocommerce');
        $live = Config::has_environment('live') ? __('Configured', 'paymos-for-woocommerce') : __('Missing', 'paymos-for-woocommerce');
        $projectId = Config::masked_project_id($mode);
        $maskedKey = Config::masked_api_key($mode);

        $rows = array(
            __('Active mode', 'paymos-for-woocommerce') => $mode,
            __('Sandbox', 'paymos-for-woocommerce') => $sandbox,
            __('Live', 'paymos-for-woocommerce') => $live,
            __('Active API key', 'paymos-for-woocommerce') => $maskedKey,
            __('Project ID', 'paymos-for-woocommerce') => $projectId,
        );

        $html = '<tr valign="top">'
            . '<th scope="row" class="titledesc"><label for="' . esc_attr($fieldKey) . '">' . esc_html($data['title']) . '</label></th>'
            . '<td class="forminp">'
            . '<table class="widefat striped" id="' . esc_attr($fieldKey) . '">';

        foreach ($rows as $label => $value) {
            if ($value === '') {
                $value = __('Missing', 'paymos-for-woocommerce');
            }

            $html .= '<tr><th style="width: 180px;">' . esc_html($label) . '</th><td><code>' . esc_html($value) . '</code></td></tr>';
        }

        $html .= '</table><p class="description">' . esc_html($data['description']) . '</p></td></tr>';
        return $html;
    }

}
