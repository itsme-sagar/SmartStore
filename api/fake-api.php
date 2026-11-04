<?php
/**
 * SmartStore - External Mock/Fake API Integration & Product Importer
 * Review 02 - Web Technologies Lab Evaluation
 * Student: Sagar Dhakal | Roll No: 24CS275
 * 
 * Demonstrates consuming an external/mock catalog API, previewing staged
 * demo items with verification badges, and importing approved items directly into MySQL.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $db = getDBConnection();

    // =========================================================================
    // GET: Fetch external demo catalogue for preview (External/Mock API feed)
    // =========================================================================
    if ($method === 'GET') {
        $externalUrl = 'https://dummyjson.com/products?limit=8&select=id,title,price,category,stock,sku';
        $demoProducts = [];
        $sourceType = 'live_mock_api';

        // Attempt live fetch with a short 2.5 second timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => 2.5,
                'header'  => "User-Agent: SmartStore-Review02-Lab/1.0\r\n"
            ]
        ]);

        $response = @file_get_contents($externalUrl, false, $context);

        if ($response !== false) {
            $parsed = json_decode($response, true);
            if (!empty($parsed['products']) && is_array($parsed['products'])) {
                foreach ($parsed['products'] as $p) {
                    $rawPrice = (float)($p['price'] ?? 10);
                    // Convert USD to INR representation approx (x80)
                    $inrPrice = round($rawPrice * 80, 2);
                    $costPrice = round($inrPrice * 0.65, 2);
                    $sku = 'EXT-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $p['title'] ?? 'ITEM'), 0, 4)) . '-' . rand(100, 999);

                    $demoProducts[] = [
                        'external_id'       => $p['id'],
                        'name'              => $p['title'],
                        'category'          => ucfirst($p['category'] ?? 'Electronics'),
                        'selling_price'     => $inrPrice,
                        'cost_price'        => $costPrice,
                        'stock_quantity'    => max(10, (int)($p['stock'] ?? 15)),
                        'min_stock_level'   => 5,
                        'suggested_sku'     => $sku,
                        'is_external_demo'  => true,
                        'source'            => 'DummyJSON External Product API'
                    ];
                }
            }
        }

        // Offline / fallback mock dataset if network is blocked in college lab
        if (empty($demoProducts)) {
            $sourceType = 'curated_mock_dataset';
            $curatedMock = [
                ['name' => 'Mechanical Gaming Keyboard RGB', 'category' => 'Electronics', 'price' => 3499.00, 'cost' => 2200.00, 'stock' => 15, 'sku' => 'EXT-MECH-501'],
                ['name' => 'Ergonomic Vertical Mouse', 'category' => 'Electronics', 'price' => 1299.00, 'cost' => 750.00, 'stock' => 22, 'sku' => 'EXT-VMSE-502'],
                ['name' => 'Aluminium Laptop Stand', 'category' => 'Accessories', 'price' => 1199.00, 'cost' => 600.00, 'stock' => 18, 'sku' => 'EXT-LSTD-503'],
                ['name' => 'Stainless Steel Thermal Flask 1L', 'category' => 'Lifestyle', 'price' => 849.00, 'cost' => 450.00, 'stock' => 30, 'sku' => 'EXT-FLSK-504'],
                ['name' => 'Smart LED Desk Bar Lamp', 'category' => 'Home', 'price' => 2199.00, 'cost' => 1350.00, 'stock' => 12, 'sku' => 'EXT-LAMP-505'],
                ['name' => 'Braided 65W Fast Cable 2m', 'category' => 'Accessories', 'price' => 399.00, 'cost' => 180.00, 'stock' => 45, 'sku' => 'EXT-CBL6-506']
            ];

            foreach ($curatedMock as $item) {
                $demoProducts[] = [
                    'external_id'      => rand(1000, 9999),
                    'name'             => $item['name'],
                    'category'         => $item['category'],
                    'selling_price'    => $item['price'],
                    'cost_price'       => $item['cost'],
                    'stock_quantity'   => $item['stock'],
                    'min_stock_level'  => 5,
                    'suggested_sku'    => $item['sku'],
                    'is_external_demo' => true,
                    'source'           => 'Curated Mock API Dataset (Lab Ready)'
                ];
            }
        }

        sendJsonResponse(true, [
            'source_type'   => $sourceType,
            'source_origin' => $sourceType === 'live_mock_api' ? 'https://dummyjson.com/products' : 'Local Mock API Gateway',
            'note'          => 'These items are staged from the Fake API. They are NOT stored in MySQL until you click "Import to MySQL".',
            'catalog'       => $demoProducts
        ], 'External catalogue staged for import.');
    }

    // =========================================================================
    // POST: Confirm and import selected item into MySQL database
    // =========================================================================
    if ($method === 'POST') {
        $input = getRequestData();

        $name          = trim($input['name'] ?? '');
        $categoryName  = trim($input['category'] ?? 'General');
        $sellingPrice  = (float)($input['selling_price'] ?? $input['price'] ?? 0);
        $costPrice     = (float)($input['cost_price'] ?? 0);
        $stockQuantity = (int)($input['stock_quantity'] ?? $input['stock'] ?? 10);
        $minStockLevel = (int)($input['min_stock_level'] ?? $input['min'] ?? 5);
        $sku           = strtoupper(trim($input['sku'] ?? $input['suggested_sku'] ?? ''));

        if (empty($name)) {
            sendJsonResponse(false, null, 'Product name is required for import.', 400);
        }

        if (empty($sku)) {
            $sku = 'IMP-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 4)) . '-' . rand(100, 999);
        }

        // Avoid duplicate SKU: if SKU exists, generate a random suffix
        $chk = $db->prepare("SELECT id FROM products WHERE sku = ?");
        $chk->execute([$sku]);
        if ($chk->fetch()) {
            $sku .= '-' . rand(10, 99);
        }

        // Resolve or create Category
        $catStmt = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
        $catStmt->execute([$categoryName]);
        $catRow = $catStmt->fetch();
        if ($catRow) {
            $categoryId = (int)$catRow['id'];
        } else {
            $catIns = $db->prepare("INSERT INTO categories (name) VALUES (?)");
            $catIns->execute([$categoryName]);
            $categoryId = (int)$db->lastInsertId();
        }

        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO products (
                category_id, name, sku, cost_price, selling_price, stock_quantity, min_stock_level, unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pcs')
        ");
        $stmt->execute([
            $categoryId, $name, $sku, $costPrice, $sellingPrice, $stockQuantity, $minStockLevel
        ]);
        $newId = (int)$db->lastInsertId();

        // Audit stock movement
        if ($stockQuantity > 0) {
            $mv = $db->prepare("
                INSERT INTO stock_movements (
                    product_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
                ) VALUES (?, 'INITIAL', ?, 0, ?, 'FAKE_API_IMPORT', 'Imported from External Mock API Catalog')
            ");
            $mv->execute([$newId, $stockQuantity, $stockQuantity]);
        }

        $db->commit();

        sendJsonResponse(true, [
            'id'             => $newId,
            'name'           => $name,
            'sku'            => $sku,
            'category'       => $categoryName,
            'selling_price'  => $sellingPrice,
            'price'          => $sellingPrice,
            'stock_quantity' => $stockQuantity,
            'stock'          => $stockQuantity,
            'imported_from'  => 'External Mock API'
        ], "Product '{$name}' successfully imported and saved into MySQL database.", 201);
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logSystemError('api/fake-api.php', $e);
    sendJsonResponse(false, null, 'Fake API import failed: ' . $e->getMessage(), 500);
}
