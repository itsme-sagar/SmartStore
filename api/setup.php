<?php
/**
 * SmartStore - Database Auto-Setup & Health Check
 * Review 02 - Web Technologies Lab
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$action = $_GET['action'] ?? 'check';

try {
    // First test raw connection
    $dsnNoDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
    $rawPdo = new PDO($dsnNoDb, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    if ($action === 'init' || $action === 'seed') {
        $sqlFile = dirname(__DIR__) . '/database/schema.sql';
        if (!file_exists($sqlFile)) {
            sendJsonResponse(false, null, 'Schema file database/schema.sql not found.', 500);
        }

        $sql = file_get_contents($sqlFile);
        $rawPdo->exec($sql);

        sendJsonResponse(true, [
            'database' => DB_NAME,
            'action'   => 'initialized_from_schema'
        ], 'Database smartstore_db successfully initialized with tables and Review 02 seed data.');
    }

    // Default: Check tables
    $test = testDBConnection();
    if (!$test['connected']) {
        sendJsonResponse(false, [
            'connected' => false,
            'error'     => $test['error'],
            'hint'      => 'Click Initialize Database or run database/schema.sql in phpMyAdmin'
        ], 'MySQL connection error', 503);
    }

    $db = getDBConnection();
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    sendJsonResponse(true, [
        'connected'    => true,
        'database'     => DB_NAME,
        'tables_count' => count($tables),
        'tables'       => $tables,
        'ready'        => count($tables) >= 8
    ], 'Database setup verified.');

} catch (Throwable $e) {
    logSystemError('api/setup.php', $e);
    sendJsonResponse(false, null, 'Database setup check failed: ' . $e->getMessage(), 500);
}
