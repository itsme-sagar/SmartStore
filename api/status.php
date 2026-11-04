<?php
/**
 * SmartStore - System Health & Database Status API
 * Review 02 - Web Technologies Lab
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$test = testDBConnection();

if (!$test['connected']) {
    sendJsonResponse(false, [
        'status'    => 'database_unreachable',
        'details'   => $test,
        'app_mode'  => 'offline_fallback',
        'system'    => 'SmartStore v2.0 Review 02'
    ], 'MySQL database could not be reached. Ensure Apache and MySQL are running in XAMPP.', 503);
}

try {
    $db = getDBConnection();

    // Query core counts
    $stats = [];
    $stats['products']   = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $stats['categories'] = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $stats['customers']  = (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $stats['suppliers']  = (int)$db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
    $stats['sales']      = (int)$db->query("SELECT COUNT(*) FROM sales")->fetchColumn();
    $stats['purchases']  = (int)$db->query("SELECT COUNT(*) FROM purchases")->fetchColumn();

    sendJsonResponse(true, [
        'status'         => 'healthy',
        'database'       => $test['database'],
        'server_version' => $test['version'],
        'host'           => $test['host'],
        'server_time'    => $test['server_time'],
        'table_counts'   => $stats,
        'app'            => [
            'name'       => 'SmartStore – Inventory & Sales Management System',
            'version'    => '2.0.0 (Review 02)',
            'student'    => 'Sagar Dhakal (24CS275)',
            'subject'    => 'U21CS501 & U21CS504 Web Technologies'
        ]
    ], 'System operational and database connected successfully.', 200);

} catch (Throwable $e) {
    logSystemError('api/status.php', $e);
    sendJsonResponse(false, null, 'Error querying system status: ' . $e->getMessage(), 500);
}
