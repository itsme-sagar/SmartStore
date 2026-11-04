<?php
/**
 * SmartStore - Automated API & Database Verification Test Suite
 * Review 02 - Web Technologies Lab
 * Student: Sagar Dhakal | Roll No: 24CS275
 */

require_once __DIR__ . '/../config/database.php';

$baseUrl = 'http://localhost/SmartStore-Review02/api';
$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}: {$details}\n";
        $failed++;
    }
}

function httpPost(string $url, array $data): array {
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\nAccept: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true) ?: [];
}

function httpGet(string $url): array {
    $options = [
        'http' => [
            'header'  => "Accept: application/json\r\n",
            'method'  => 'GET',
            'ignore_errors' => true
        ]
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true) ?: [];
}

echo "=== SMARTSTORE REVIEW 02 - AUTOMATED TEST SUITE ===\n\n";

// 1. Status API Test
echo "Test 1: System Health & Database Connectivity\n";
$status = httpGet("{$baseUrl}/status.php");
assertTest("Status returns HTTP 200 and success=true", $status['success'] === true);
assertTest("Status reports healthy database", ($status['data']['status'] ?? '') === 'healthy');
assertTest("Status reports MariaDB/MySQL", !empty($status['data']['server_version']));

// 2. Product API Validation
echo "\nTest 2: Product Creation & Validation\n";
$testSku = "TEST-SKU-" . rand(1000, 9999);
$newProd = httpPost("{$baseUrl}/products.php", [
    'name' => 'Automated Test Product',
    'sku' => $testSku,
    'category' => 'Electronics',
    'price' => 999.00,
    'cost_price' => 600.00,
    'stock' => 15,
    'min' => 5
]);
assertTest("Product created successfully with valid input", $newProd['success'] === true);
$createdProdId = $newProd['data']['id'] ?? 0;

// Duplicate SKU rejection test
$dupProd = httpPost("{$baseUrl}/products.php", [
    'name' => 'Duplicate SKU Item',
    'sku' => $testSku,
    'category' => 'Electronics',
    'price' => 500.00,
    'stock' => 10,
    'min' => 2
]);
assertTest("Duplicate SKU correctly rejected with error", $dupProd['success'] === false);

// Negative price rejection test
$negProd = httpPost("{$baseUrl}/products.php", [
    'name' => 'Negative Price Item',
    'sku' => 'NEG-PRICE-101',
    'category' => 'Electronics',
    'price' => -100.00,
    'stock' => 10,
    'min' => 2
]);
assertTest("Negative price correctly rejected", $negProd['success'] === false);

// 3. Customer CRM Test
echo "\nTest 3: Customer CRM CRUD\n";
$testPhone = "9" . rand(100000000, 999999999);
$newCust = httpPost("{$baseUrl}/customers.php", [
    'name' => 'Kavita Verma',
    'phone' => $testPhone,
    'email' => 'kavita@test.example',
    'address' => 'Test Colony, Bengaluru'
]);
assertTest("Customer created successfully", $newCust['success'] === true);

// 4. Sales Transaction Safety & Rollback Verification
echo "\nTest 4: Transaction Safety & Rollback on Insufficient Stock\n";
$db = getDBConnection();
$stockBefore = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$createdProdId}")->fetchColumn();
assertTest("Target product initial stock is 15", $stockBefore === 15);

// Attempt sale with quantity = 999 (should fail and rollback)
$failedSale = httpPost("{$baseUrl}/sales.php", [
    'customer' => 'Walk-in Customer',
    'payment_method' => 'cash',
    'discount' => 0,
    'items' => [
        ['id' => $createdProdId, 'qty' => 999]
    ]
]);
assertTest("Excessive sale correctly rejected by server", $failedSale['success'] === false);

$stockAfterFail = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$createdProdId}")->fetchColumn();
assertTest("Database stock remained intact at 15 after rollback", $stockAfterFail === 15);

// Attempt valid sale with quantity = 3
echo "\nTest 5: Valid Sale Transaction & Automatic Stock Deduction\n";
$validSale = httpPost("{$baseUrl}/sales.php", [
    'customer' => 'Kavita Verma',
    'payment_method' => 'upi',
    'discount' => 50.00,
    'items' => [
        ['id' => $createdProdId, 'qty' => 3]
    ]
]);
assertTest("Valid sale succeeded and generated invoice", $validSale['success'] === true && !empty($validSale['data']['invoice_no']));

$stockAfterSale = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$createdProdId}")->fetchColumn();
assertTest("Stock automatically deducted in MySQL from 15 to 12", $stockAfterSale === 12);

// Check stock movement audit log
$auditCheck = $db->query("
    SELECT movement_type, quantity, previous_stock, new_stock 
    FROM stock_movements 
    WHERE product_id = {$createdProdId} AND movement_type = 'SALE'
    ORDER BY id DESC LIMIT 1
")->fetch();
assertTest("Stock movement audit row recorded with delta -3", ($auditCheck['quantity'] ?? 0) == -3 && ($auditCheck['new_stock'] ?? 0) == 12);

// 6. Supplier Restock / Purchase Order Test
echo "\nTest 6: Supplier Restock & Stock Addition\n";
$restock = httpPost("{$baseUrl}/purchases.php", [
    'supplier_id' => 1,
    'notes' => 'Automated test replenishment',
    'items' => [
        ['product_id' => $createdProdId, 'quantity' => 10, 'unit_cost' => 600.00]
    ]
]);
assertTest("Restock transaction succeeded with PO number", $restock['success'] === true && !empty($restock['data']['po_number']));

$stockAfterRestock = (int)$db->query("SELECT stock_quantity FROM products WHERE id = {$createdProdId}")->fetchColumn();
assertTest("Stock replenished in MySQL from 12 to 22", $stockAfterRestock === 22);

// 7. Fake API Importer Test
echo "\nTest 7: External Fake API Integration & Import\n";
$fakeCatalog = httpGet("{$baseUrl}/fake-api.php");
assertTest("Fake API returned staged catalog feed", $fakeCatalog['success'] === true && !empty($fakeCatalog['data']['catalog']));

$firstItem = $fakeCatalog['data']['catalog'][0] ?? null;
if ($firstItem) {
    $imported = httpPost("{$baseUrl}/fake-api.php", $firstItem);
    assertTest("Staged item imported and saved into MySQL products table", $imported['success'] === true && !empty($imported['data']['id']));
}

// 8. Analytics Verification Test
echo "\nTest 8: Database Analytics Real Aggregations\n";
$analytics = httpGet("{$baseUrl}/analytics.php");
assertTest("Analytics returns live summary metrics", $analytics['success'] === true && isset($analytics['data']['summary']['total_revenue']));
assertTest("Analytics 7-day chart contains 7 daily datapoints", count($analytics['data']['chart_7days'] ?? []) === 7);

echo "\n============================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "============================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
