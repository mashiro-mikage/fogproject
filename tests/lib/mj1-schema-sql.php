<?php
require __DIR__ . '/fog-schema-collector.php';
$steps = fogCollectSchemaSteps(dirname(__DIR__, 2) . '/packages/web/commons/schema.php', 'fog_mj1', 444);
$last = end($steps);
if (!is_array($last) || count($last) !== 1 || !is_string($last[0] ?? null)) {
    fwrite(STDERR, "FAIL: schema step 444 must contain one DDL string\n");
    exit(1);
}
echo json_encode($last[0]), "\n";
