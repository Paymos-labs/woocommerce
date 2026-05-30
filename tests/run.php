<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/OrderMapperTest.php';
require __DIR__ . '/WebhookEnvironmentVerifierTest.php';
require __DIR__ . '/WebhookControllerTest.php';
require __DIR__ . '/ReconcilerTest.php';

$count = 0;

foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_') !== 0) {
        continue;
    }

    $function();
    $count++;
    echo "PASS {$function}\n";
}

paymos_reset_test_state();

echo "OK {$count} tests\n";
