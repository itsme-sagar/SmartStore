<?php
/**
 * SmartStore - Purchases & Stock Restocking API
 * Review 02 - Web Technologies Lab
 * Student: Sagar Dhakal | Roll No: 24CS275
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $db = getDBConnection();

    // ==========================================
    // GET: List purchase orders
    // ==========================================
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($id) {
            $stmt = $db->prepare("
                SELECT p.*, s.phone AS supplier_phone, s.email AS supplier_email
                FROM purchases p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $purchase = $stmt->fetch();
            if (!$purchase) {
                sendJsonResponse(false, null, 'Purchase record not found.', 404);
            }

            $itemsStmt = $db->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
            $itemsStmt->execute([$id]);
            $purchase['items'] = $itemsStmt->fetchAll();

            sendJsonResponse(true, $purchase, 'Purchase record retrieved.');
        }

        $sql = "
            SELECT 
                p.id,
                p.po_number,
                p.supplier_id,
                p.supplier_name,
                p.total_amount,
                p.status,
                p.notes,
                p.purchase_date,
                p.created_at,
                COUNT(pi.id) AS item_count,
                COALESCE(SUM(pi.quantity), 0) AS total_units
            FROM purchases p
            LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
            GROUP BY p.id
            ORDER BY p.id DESC
        ";
        $stmt = $db->query($sql);
        $purchases = $stmt->fetchAll();

        sendJsonResponse(true, $purchases, 'Purchases retrieved.');
    }

    // ==========================================
    // POST: Create restock / purchase order
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();

        $supplierId = !empty($input['supplier_id']) ? (int)$input['supplier_id'] : null;
        $supplierName = trim($input['supplier_name'] ?? '');
        $items = $input['items'] ?? [];
        $notes = trim($input['notes'] ?? 'Stock replenishment');
        $purchaseDate = !empty($input['purchase_date']) ? $input['purchase_date'] : date('Y-m-d');

        if (empty($items) || !is_array($items)) {
            sendJsonResponse(false, null, 'Please specify at least one product to restock.', 400);
        }

        if ($supplierId && empty($supplierName)) {
            $sup = $db->prepare("SELECT name, company FROM suppliers WHERE id = ?");
            $sup->execute([$supplierId]);
            $supRow = $sup->fetch();
            if ($supRow) {
                $supplierName = $supRow['company'] ?: $supRow['name'];
            }
        }

        if (empty($supplierName)) {
            $supplierName = 'General Supplier';
        }

        $db->beginTransaction();

        $poNumber = 'PO-' . date('Ymd') . '-' . rand(100, 999);
        $totalAmount = 0.0;
        $validatedRestocks = [];

        foreach ($items as $item) {
            $prodId = (int)($item['product_id'] ?? $item['id'] ?? 0);
            $qty = (int)($item['quantity'] ?? $item['qty'] ?? 0);
            $unitCost = (float)($item['unit_cost'] ?? $item['cost_price'] ?? 0);

            if ($prodId <= 0 || $qty <= 0) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Invalid product or quantity in restock list.', 400);
            }

            // Lock product row
            $chk = $db->prepare("SELECT id, name, sku, stock_quantity, cost_price FROM products WHERE id = ? FOR UPDATE");
            $chk->execute([$prodId]);
            $product = $chk->fetch();

            if (!$product) {
                $db->rollBack();
                sendJsonResponse(false, null, "Product with ID {$prodId} not found.", 404);
            }

            if ($unitCost <= 0) {
                $unitCost = (float)$product['cost_price'];
            }

            $lineSubtotal = round($unitCost * $qty, 2);
            $totalAmount += $lineSubtotal;

            $currentStock = (int)$product['stock_quantity'];
            $newStock = $currentStock + $qty;

            $validatedRestocks[] = [
                'product_id'     => $prodId,
                'name'           => $product['name'],
                'unit_cost'      => $unitCost,
                'quantity'       => $qty,
                'subtotal'       => $lineSubtotal,
                'previous_stock' => $currentStock,
                'new_stock'      => $newStock
            ];
        }

        // Insert purchase header
        $insPO = $db->prepare("
            INSERT INTO purchases (po_number, supplier_id, supplier_name, total_amount, status, notes, purchase_date)
            VALUES (?, ?, ?, ?, 'received', ?, ?)
        ");
        $insPO->execute([$poNumber, $supplierId, $supplierName, $totalAmount, $notes, $purchaseDate]);
        $purchaseId = (int)$db->lastInsertId();

        // Insert items, update stock & log movements
        $insItem = $db->prepare("
            INSERT INTO purchase_items (purchase_id, product_id, product_name, unit_cost, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $updStock = $db->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $insMv = $db->prepare("
            INSERT INTO stock_movements (product_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes)
            VALUES (?, 'PURCHASE', ?, ?, ?, ?, ?)
        ");

        foreach ($validatedRestocks as $vr) {
            $insItem->execute([
                $purchaseId, $vr['product_id'], $vr['name'], $vr['unit_cost'], $vr['quantity'], $vr['subtotal']
            ]);
            $updStock->execute([$vr['quantity'], $vr['product_id']]);
            $insMv->execute([
                $vr['product_id'], $vr['quantity'], $vr['previous_stock'], $vr['new_stock'], $poNumber, "Restocked via {$poNumber}"
            ]);
        }

        $db->commit();

        sendJsonResponse(true, [
            'id'           => $purchaseId,
            'po_number'    => $poNumber,
            'supplier'     => $supplierName,
            'total_amount' => $totalAmount,
            'items_count'  => count($validatedRestocks)
        ], "Restock completed successfully under {$poNumber}.", 201);
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logSystemError('api/purchases.php', $e);
    sendJsonResponse(false, null, 'Restock operation failed: ' . $e->getMessage(), 500);
}
