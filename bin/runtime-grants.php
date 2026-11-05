<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

// Prints reviewed, schema-specific provisioning SQL without reading credentials.
try {
    if ($argc !== 3) throw new InvalidArgumentException('Usage: php novikov/bin/runtime-grants.php SCHEMA NEW_ROLE');
    echo implode(";\n", Novikov\RuntimeGrants::statements($argv[1], $argv[2])) . ";\n";
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
