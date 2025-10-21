<?php
// Central bootstrap file that wires the configuration, database connection and schema checks.
$config = require __DIR__ . '/config.php';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], $options);
} catch (PDOException $e) {
    throw new RuntimeException('Unable to connect to database. Please double check credentials.', 0, $e);
}

require_once __DIR__ . '/functions.php';

// Ensure tables exist before the rest of the application runs.
ensureDatabaseSchema($pdo);
