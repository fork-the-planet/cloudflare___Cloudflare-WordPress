#!/usr/bin/env php
<?php
/**
 * Removes unsupported PHP 8 return types from symfony/polyfill bootstrap80.php files.
 *
 * These files use union return types (e.g. `: string|false`) which are not supported
 * in PHP 7.4. This script strips those return types for backward compatibility.
 *
 * Usage:
 *   php scripts/fix-polyfill-return-types.php [vendor-dir]
 *
 * If no vendor directory is specified, defaults to 'vendor/' relative to the project root.
 */

$vendorDir = $argv[1] ?? dirname(__DIR__) . '/vendor';

if (! is_dir($vendorDir)) {
    echo "Vendor directory not found: {$vendorDir}" . PHP_EOL;
    exit(1);
}

$pattern = $vendorDir . '/symfony/polyfill-intl-*/bootstrap80.php';
$files   = glob($pattern);

if (empty($files)) {
    echo "No bootstrap80.php files found matching: {$pattern}" . PHP_EOL;
    exit(1);
}

foreach ($files as $file) {
    $contents = file_get_contents($file);
    $updated  = str_replace(': string|false', ' ', $contents);

    if ($contents !== $updated) {
        file_put_contents($file, $updated);
        echo "Fixed: {$file}" . PHP_EOL;
    } else {
        echo "No changes needed: {$file}" . PHP_EOL;
    }
}

echo 'Done.' . PHP_EOL;
