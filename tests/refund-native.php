<?php

declare(strict_types=1);

/**
 * Native stand contract, deliberately separate from RefundAdapterTest.php stubs.
 * A stand driver returns package versions/hashes, storage mode, DB engines and
 * scenario evidence. No environment is provisioned or started by this fixture.
 */
$contract = array(
    'platform' => 'WooCommerce',
    'required_package_matrix' => array('8.0.0/legacy', '8.0.0/HPOS', '11.2.0/legacy', '11.2.0/HPOS'),
    'observed_packages' => array(),
    'required_environment_evidence' => array('wordpress_version', 'woocommerce_version', 'package_sha256',
        'php_version', 'storage_mode', 'transactional_tables', 'plugin_revision', 'sdk_revision'),
    'scenarios' => array(
        'protected_native_ajax' => 'Nonce/capability/ownership before POST; other gateways retain native handler; full draft only.',
        'over20_then_full100' => 'G120/P100: one over20 cash note, paid/refunded0/stock unchanged, then one offline native full100 document.',
        'lost_response_same_token' => 'Kill after cash commit; retry persisted token; one Withdrawal and no temporary native refund.',
        'callback_before_binding' => 'Signed scoped callback triggers independent POST replay/GET; event ID never binds cash.',
        'native_transaction_crashes' => 'Kill before and after native commit; native record/CRUD marker/stock/module projection share InnoDB transaction.',
        'reorg_recompletion' => 'Rewind keeps full document or cash note; recompletion never repeats financial/restock/mail effects.',
        'queued_email_restart' => 'Queue native mail, restart worker: marked refund suppressed; exact claimed module mail allowed; unrelated gateway unaffected.',
        'smtp_lost_ack' => 'Accepted SMTP then process death: started becomes unknown/review; no automatic second email.',
        'concurrent_full_actions' => 'Two DB connections, same native payment: one cash operation; stale owner cannot bind or project.',
        'schema_install_upgrade' => 'Clean install/update/recovery; retained intents and bindings after deactivate/reactivate.',
    ),
    'status' => 'skipped',
    'reason' => 'No approved native WordPress/WooCommerce stand or installed native packages are available. Service stubs are not native proof.',
);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    foreach ($contract['scenarios'] as $name => $assertion) {
        echo 'SKIP ' . $name . ': ' . $contract['reason'] . "\n";
    }
    echo 'SKIPPED ' . count($contract['scenarios']) . ' native scenarios; observed packages: 0' . "\n";
}

return $contract;
