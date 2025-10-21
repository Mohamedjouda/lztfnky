<?php
/**
 * Background worker that keeps the local database in sync with lzt.market listings.
 *
 * Usage examples:
 *   php fetch_worker.php        # runs forever (sleep interval defined in config.php)
 *   php fetch_worker.php once   # run a single fetch cycle and exit
 */
require __DIR__ . '/bootstrap.php';

$logger = static function (string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}" . PHP_EOL;
};

$mode = $argv[1] ?? 'loop';

if ($mode === 'once') {
    $summary = runFetchOnce($pdo, $config, $logger);
    $logger(sprintf('Done. Imported %d listing(s). Token present: %s', $summary['imported'], $summary['token_present'] ? 'yes' : 'no'));
    if (!empty($summary['errors'])) {
        foreach ($summary['errors'] as $error) {
            $logger('Error: ' . $error);
        }
    }
    exit(0);
}

$logger('Entering continuous mode. Stop with CTRL+C.');
runFetchLoop($pdo, $config, $logger);
