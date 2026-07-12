<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Exception\ApiException;

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
            $externalOrderId = $this->externalOrderId($order);

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

            $invoice = $this->client($config)->invoices()->create($payload);
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

    private function externalOrderId($order)
    {
        $base = 'wc_' . $order->get_id() . '_' . $order->get_order_key();

        if (!method_exists($order, 'get_meta')) {
            return $base;
        }

        $existing = (string) $order->get_meta('_paymos_external_order_id', true);
        if ($existing !== '' && OrderAmountGuard::currentMatchesSnapshot($order)) {
            return $existing;
        }

        if ($existing !== '') {
            return $base . '_' . time();
        }

        return $base;
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
