<?php
/**
 * SmartStore - Sales & Billing Transaction API
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
    // GET: List sales or get single invoice
    // ==========================================
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $invoiceNo = trim($_GET['invoice_no'] ?? '');

        // Fetch single invoice
        if ($id || !empty($invoiceNo)) {
            $stmt = $db->prepare("
                SELECT 
                    s.*,
                    c.phone AS customer_phone,
                    c.email AS customer_email
                FROM sales s
                LEFT JOIN customers c ON s.customer_id = c.id
                WHERE " . ($id ? "s.id = ?" : "s.invoice_no = ?")
            );
            $stmt->execute([$id ?: $invoiceNo]);
            $sale = $stmt->fetch();

            if (!$sale) {
                sendJsonResponse(false, null, 'Invoice not found.', 404);
            }

            // Fetch line items
            $itemStmt = $db->prepare("
                SELECT 
                    id, 
                    product_id, 
                    product_name, 
                    sku, 
                    CAST(unit_price AS DECIMAL(10,2)) AS unit_price,
                    quantity,
                    CAST(subtotal AS DECIMAL(10,2)) AS subtotal
                FROM sale_items 
                WHERE sale_id = ?
                ORDER BY id ASC
            ");
            $itemStmt->execute([$sale['id']]);
            $items = $itemStmt->fetchAll();

            $sale['id'] = (int)$sale['id'];
            $sale['subtotal'] = (float)$sale['subtotal'];
            $sale['discount'] = (float)$sale['discount'];
            $sale['tax_rate'] = (float)$sale['tax_rate'];
            $sale['tax_amount'] = (float)$sale['tax_amount'];
            $sale['grand_total'] = (float)$sale['grand_total'];
            $sale['total'] = (float)$sale['grand_total']; // backwards compatibility
            $sale['items'] = array_map(function($i) {
                return [
                    'id'           => (int)$i['id'],
                    'product_id'   => $i['product_id'] !== null ? (int)$i['product_id'] : null,
                    'product_name' => $i['product_name'],
                    'name'         => $i['product_name'], // backwards compatibility
                    'sku'          => $i['sku'],
                    'unit_price'   => (float)$i['unit_price'],
                    'price'        => (float)$i['unit_price'],
                    'quantity'     => (int)$i['quantity'],
                    'qty'          => (int)$i['quantity'], // backwards compatibility
                    'subtotal'     => (float)$i['subtotal']
                ];
            }, $items);

            sendJsonResponse(true, $sale, 'Invoice retrieved successfully.');
        }

        // List sales
        $search   = trim($_GET['search'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to'] ?? '');
        $limit    = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

        $where = ["1=1"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(s.invoice_no LIKE ? OR s.customer_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (!empty($dateFrom)) {
            $where[] = "s.sale_date >= ?";
            $params[] = $dateFrom;
        }

        if (!empty($dateTo)) {
            $where[] = "s.sale_date <= ?";
            $params[] = $dateTo;
        }

        $sql = "
            SELECT 
                s.id,
                s.invoice_no,
                s.customer_id,
                s.customer_name AS customer,
                s.subtotal,
                s.discount,
                s.tax_rate,
                s.tax_amount,
                s.grand_total,
                s.grand_total AS total,
                s.payment_method,
                s.status,
                s.sale_date AS date,
                s.created_at,
                COUNT(si.id) AS item_count,
                COALESCE(SUM(si.quantity), 0) AS total_quantity
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.id
            ORDER BY s.id DESC
            LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll();

        // Attach items preview to each sale
        if (!empty($sales)) {
            $saleIds = array_column($sales, 'id');
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $itemQuery = $db->prepare("
                SELECT sale_id, product_name AS name, quantity AS qty, unit_price AS price, subtotal
                FROM sale_items 
                WHERE sale_id IN ($placeholders)
            ");
            $itemQuery->execute($saleIds);
            $allItems = $itemQuery->fetchAll();

            $groupedItems = [];
            foreach ($allItems as $it) {
                $groupedItems[$it['sale_id']][] = [
                    'name'     => $it['name'],
                    'qty'      => (int)$it['qty'],
                    'price'    => (float)$it['price'],
                    'subtotal' => (float)$it['subtotal']
                ];
            }

            foreach ($sales as &$s) {
                $s['id_num'] = (int)$s['id'];
                $s['id'] = $s['invoice_no']; // maintain Review 01 ID string like 'INV-1004'
                $s['subtotal'] = (float)$s['subtotal'];
                $s['discount'] = (float)$s['discount'];
                $s['tax_amount'] = (float)$s['tax_amount'];
                $s['grand_total'] = (float)$s['grand_total'];
                $s['total'] = (float)$s['grand_total'];
                $s['items'] = $groupedItems[$s['id_num']] ?? [];
            }
        }

        sendJsonResponse(true, $sales, 'Sales retrieved successfully.');
    }

    // ==========================================
    // POST: COMPLETE TRANSACTION-SAFE SALE
    // ==========================================
    if ($method === 'POST') {
        $input = getRequestData();

        $items = $input['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            sendJsonResponse(false, null, 'Cannot process an empty sale. Add at least one item to cart.', 400);
        }

        $customerName  = trim($input['customer'] ?? ($input['customer_name'] ?? 'Walk-in Customer'));
        $customerId    = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
        $discountInput = max(0, (float)($input['discount'] ?? 0));
        $paymentMethod = in_array($input['payment_method'] ?? 'cash', ['cash', 'card', 'upi', 'credit']) 
            ? $input['payment_method'] 
            : 'cash';
        $notes         = trim($input['notes'] ?? 'POS Checkout');
        $saleDate      = !empty($input['sale_date']) ? $input['sale_date'] : date('Y-m-d');

        // Resolve customer_id if a known customer name was passed without ID
        if (!$customerId && !empty($customerName) && $customerName !== 'Walk-in Customer') {
            $custCheck = $db->prepare("SELECT id FROM customers WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $custCheck->execute([$customerName]);
            $cRow = $custCheck->fetch();
            if ($cRow) {
                $customerId = (int)$cRow['id'];
            }
        }

        // ==========================================
        // BEGIN DATABASE TRANSACTION
        // ==========================================
        $db->beginTransaction();

        // 1. Validate requested items against MySQL with FOR UPDATE pessimistic lock
        $validatedItems = [];
        $calculatedSubtotal = 0.0;

        foreach ($items as $item) {
            $productId = isset($item['id']) ? (int)$item['id'] : (isset($item['product_id']) ? (int)$item['product_id'] : 0);
            $requestedQty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['quantity']) ? (int)$item['quantity'] : 0);

            if ($productId <= 0) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Invalid product in checkout cart.', 400);
            }

            if ($requestedQty <= 0) {
                $db->rollBack();
                sendJsonResponse(false, null, 'Quantity for each product must be at least 1.', 400);
            }

            // Lock product row to prevent race conditions & negative stock
            $stmt = $db->prepare("
                SELECT id, name, sku, cost_price, selling_price, stock_quantity, status 
                FROM products 
                WHERE id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                $db->rollBack();
                sendJsonResponse(false, null, "Product with ID {$productId} was not found in database.", 404);
            }

            if ($product['status'] === 'inactive') {
                $db->rollBack();
                sendJsonResponse(false, null, "Product '{$product['name']}' is currently marked inactive and cannot be sold.", 400);
            }

            $currentStock = (int)$product['stock_quantity'];
            if ($currentStock < $requestedQty) {
                $db->rollBack();
                sendJsonResponse(false, null, "Insufficient stock for '{$product['name']}'. Requested: {$requestedQty}, Available: {$currentStock}.", 400);
            }

            // Server-verified price calculation
            $unitPrice = (float)$product['selling_price'];
            $costPrice = (float)$product['cost_price'];
            $lineSubtotal = round($unitPrice * $requestedQty, 2);
            $calculatedSubtotal += $lineSubtotal;

            $validatedItems[] = [
                'product_id'     => (int)$product['id'],
                'name'           => $product['name'],
                'sku'            => $product['sku'],
                'unit_price'     => $unitPrice,
                'cost_price'     => $costPrice,
                'quantity'       => $requestedQty,
                'line_subtotal'  => $lineSubtotal,
                'previous_stock' => $currentStock,
                'new_stock'      => $currentStock - $requestedQty
            ];
        }

        // 2. Validate discount & recalculate totals server-side
        $discount = min($calculatedSubtotal, $discountInput); // discount cannot exceed subtotal
        $taxableAmount = max(0, $calculatedSubtotal - $discount);
        $taxRate = 5.00; // 5% standard rate
        $taxAmount = round($taxableAmount * ($taxRate / 100), 2);
        $grandTotal = round($taxableAmount + $taxAmount, 2);

        // 3. Generate sequential unique invoice number
        $lastSale = $db->query("SELECT MAX(id) AS max_id FROM sales")->fetch();
        $nextNum = ((int)($lastSale['max_id'] ?? 0)) + 1001;
        $invoiceNo = "INV-" . $nextNum;

        // Ensure invoice uniqueness
        $chkInv = $db->prepare("SELECT id FROM sales WHERE invoice_no = ?");
        $chkInv->execute([$invoiceNo]);
        if ($chkInv->fetch()) {
            $invoiceNo = "INV-" . date('Ymd') . "-" . rand(1000, 9999);
        }

        // 4. Insert sales master header
        $insSale = $db->prepare("
            INSERT INTO sales (
                invoice_no, customer_id, customer_name, subtotal, discount, 
                tax_rate, tax_amount, grand_total, payment_method, status, notes, sale_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)
        ");
        $insSale->execute([
            $invoiceNo,
            $customerId,
            $customerName,
            $calculatedSubtotal,
            $discount,
            $taxRate,
            $taxAmount,
            $grandTotal,
            $paymentMethod,
            $notes,
            $saleDate
        ]);
        $saleId = (int)$db->lastInsertId();

        // 5. Insert sale items, deduct stock, and write stock movement logs
        $insItem = $db->prepare("
            INSERT INTO sale_items (
                sale_id, product_id, product_name, sku, unit_price, cost_price, quantity, subtotal
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $deductStock = $db->prepare("
            UPDATE products 
            SET stock_quantity = stock_quantity - ? 
            WHERE id = ?
        ");

        $insMovement = $db->prepare("
            INSERT INTO stock_movements (
                product_id, movement_type, quantity, previous_stock, new_stock, reference_id, notes
            ) VALUES (?, 'SALE', ?, ?, ?, ?, ?)
        ");

        foreach ($validatedItems as $v) {
            // Insert line item
            $insItem->execute([
                $saleId,
                $v['product_id'],
                $v['name'],
                $v['sku'],
                $v['unit_price'],
                $v['cost_price'],
                $v['quantity'],
                $v['line_subtotal']
            ]);

            // Deduct stock in products table
            $deductStock->execute([$v['quantity'], $v['product_id']]);

            // Insert audit record in stock_movements
            $insMovement->execute([
                $v['product_id'],
                -$v['quantity'],
                $v['previous_stock'],
                $v['new_stock'],
                $invoiceNo,
                "Deducted for Sale {$invoiceNo}"
            ]);
        }

        // 6. Commit transaction safely!
        $db->commit();

        // 7. Return generated invoice and updated items
        sendJsonResponse(true, [
            'id'             => $invoiceNo,
            'sale_id'        => $saleId,
            'invoice_no'     => $invoiceNo,
            'customer'       => $customerName,
            'customer_id'    => $customerId,
            'date'           => $saleDate,
            'subtotal'       => $calculatedSubtotal,
            'discount'       => $discount,
            'tax_rate'       => $taxRate,
            'tax_amount'     => $taxAmount,
            'grand_total'    => $grandTotal,
            'total'          => $grandTotal,
            'payment_method' => $paymentMethod,
            'items'          => array_map(function($i) {
                return [
                    'name'     => $i['name'],
                    'qty'      => $i['quantity'],
                    'price'    => $i['unit_price'],
                    'subtotal' => $i['line_subtotal']
                ];
            }, $validatedItems)
        ], "Sale {$invoiceNo} completed successfully. Stock updated.", 201);
    }

    sendJsonResponse(false, null, 'Method not allowed.', 405);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logSystemError('api/sales.php', $e);
    sendJsonResponse(false, null, 'Transaction failed: ' . $e->getMessage(), 500);
}
