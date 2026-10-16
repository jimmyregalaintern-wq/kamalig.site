<?php
function database_available(): bool {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        db();
        $available = true;
    } catch (Throwable $e) {
        $available = false;
        error_log('KAMALIG database connection failed: ' . $e->getMessage());
        // In production, never silently fall back to demo/session data: that
        // hides a misconfigured .env behind confusing symptoms such as
        // "Invalid credentials" for a real account.
        if (PHP_SAPI !== 'cli' && strtolower((string) getenv('APP_ENV')) === 'production') {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/html; charset=UTF-8');
            }
            $detail = env_bool('APP_DEBUG', false) ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '';
            echo '<!doctype html><meta charset="utf-8"><title>Service unavailable</title>'
               . '<body style="font-family:sans-serif;max-width:600px;margin:60px auto">'
               . '<h1>Database connection failed</h1>'
               . '<p>Check DB_HOST, DB_NAME, DB_USER and DB_PASS in your .env file, and that it is located one folder above public_html.</p>'
               . $detail . '</body>';
            exit;
        }
    }

    return $available;
}

function query_all(string $sql, array $params = []): array {
    if (!database_available()) {
        return [];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function query_one(string $sql, array $params = []): ?array {
    $rows = query_all($sql, $params);
    return $rows[0] ?? null;
}

function query_execute(string $sql, array $params = []): bool {
    if (!database_available()) {
        return false;
    }

    $stmt = db()->prepare($sql);
    return $stmt->execute($params);
}

function init_data(): void {
    if (!isset($_SESSION['data'])) {
        $_SESSION['data'] = [
            'stocks' => [
                [
                    'id' => 'stock1',
                    'userId' => 'farmer1',
                    'variety' => 'Dinorado',
                    'quantity' => 500,
                    'unit' => 'kg',
                    'unitPrice' => 18,
                    'source' => 'local',
                    'countryOfOrigin' => 'Philippines',
                    'status' => 'available',
                    'createdAt' => '2024-07-15T10:00:00Z',
                    'updatedAt' => '2024-07-20T14:30:00Z',
                    'supplierName' => "Maria's Farm",
                    'description' => 'Premium quality local Dinorado rice, fresh harvest',
                ],
                [
                    'id' => 'stock2',
                    'userId' => 'farmer1',
                    'variety' => 'Sinandomeng',
                    'quantity' => 250,
                    'unit' => 'sack',
                    'unitPrice' => 22,
                    'source' => 'local',
                    'countryOfOrigin' => 'Philippines',
                    'status' => 'available',
                    'createdAt' => '2024-07-10T09:15:00Z',
                    'updatedAt' => '2024-07-20T10:00:00Z',
                    'supplierName' => 'Luzon Rice Co.',
                    'description' => 'Certified local Sinandomeng rice',
                ],
                [
                    'id' => 'stock3',
                    'userId' => 'farmer1',
                    'variety' => 'Jasmine',
                    'quantity' => 100,
                    'unit' => 'ton',
                    'unitPrice' => 25,
                    'source' => 'local',
                    'countryOfOrigin' => 'Philippines',
                    'status' => 'low_stock',
                    'createdAt' => '2024-07-05T08:30:00Z',
                    'updatedAt' => '2024-07-20T11:45:00Z',
                    'supplierName' => 'Delta Farms',
                    'description' => 'Local jasmine rice, premium grade',
                ],
                [
                    'id' => 'stock4',
                    'userId' => 'importer1',
                    'variety' => 'Jasmine',
                    'quantity' => 300,
                    'unit' => 'ton',
                    'unitPrice' => 28,
                    'source' => 'imported',
                    'countryOfOrigin' => 'Thailand',
                    'status' => 'available',
                    'createdAt' => '2024-07-18T07:00:00Z',
                    'updatedAt' => '2024-07-20T15:20:00Z',
                    'supplierName' => 'Global Rice Ltd',
                    'description' => 'Thai jasmine rice, Grade A quality',
                ],
                [
                    'id' => 'stock5',
                    'userId' => 'importer1',
                    'variety' => 'Basmati',
                    'quantity' => 150,
                    'unit' => 'ton',
                    'unitPrice' => 35,
                    'source' => 'imported',
                    'countryOfOrigin' => 'India',
                    'status' => 'available',
                    'createdAt' => '2024-07-14T06:45:00Z',
                    'updatedAt' => '2024-07-20T13:00:00Z',
                    'supplierName' => 'Premium Imports',
                    'description' => 'Indian Basmati rice, authentic aroma',
                ],
                [
                    'id' => 'stock6',
                    'userId' => 'importer1',
                    'variety' => 'Carnaroli',
                    'quantity' => 50,
                    'unit' => 'ton',
                    'unitPrice' => 45,
                    'source' => 'imported',
                    'countryOfOrigin' => 'Italy',
                    'status' => 'available',
                    'createdAt' => '2024-07-12T05:30:00Z',
                    'updatedAt' => '2024-07-20T09:00:00Z',
                    'supplierName' => 'Italian Grains',
                    'description' => 'Italian Carnaroli rice for risotto',
                ],
                [
                    'id' => 'stock7',
                    'userId' => 'importer1',
                    'variety' => 'Arborio',
                    'quantity' => 75,
                    'unit' => 'ton',
                    'unitPrice' => 42,
                    'source' => 'imported',
                    'countryOfOrigin' => 'Italy',
                    'status' => 'available',
                    'createdAt' => '2024-07-11T04:15:00Z',
                    'updatedAt' => '2024-07-20T12:30:00Z',
                    'supplierName' => 'Mediterranean Trade',
                    'description' => 'Italian Arborio rice, excellent cooking properties',
                ],
                [
                    'id' => 'stock8',
                    'userId' => 'supplier1',
                    'variety' => 'Sinandomeng',
                    'quantity' => 400,
                    'unit' => 'sack',
                    'unitPrice' => 23,
                    'source' => 'local',
                    'countryOfOrigin' => 'Philippines',
                    'status' => 'available',
                    'createdAt' => '2024-07-16T11:00:00Z',
                    'updatedAt' => '2024-07-20T14:00:00Z',
                    'supplierName' => 'Santos Rice Trading',
                    'description' => 'Premium Sinandomeng from trusted supplier',
                ],
            ],
            'requests' => [
                [
                    'id' => 'req1',
                    'stockId' => 'stock1',
                    'requesterId' => 'supplier1',
                    'supplierId' => 'farmer1',
                    'variety' => 'Dinorado',
                    'quantityRequested' => 100,
                    'unit' => 'kg',
                    'notes' => 'Need for wholesale distribution',
                    'status' => 'pending',
                    'createdAt' => '2024-07-19T10:30:00Z',
                    'updatedAt' => '2024-07-20T10:30:00Z',
                    'requesterName' => 'Juan Santos',
                    'supplierName' => "Maria's Farm",
                ],
                [
                    'id' => 'req2',
                    'stockId' => 'stock4',
                    'requesterId' => 'supplier1',
                    'supplierId' => 'importer1',
                    'variety' => 'Jasmine',
                    'quantityRequested' => 50,
                    'unit' => 'ton',
                    'notes' => 'Export quality, wholesale order',
                    'status' => 'accepted',
                    'createdAt' => '2024-07-18T09:00:00Z',
                    'updatedAt' => '2024-07-19T14:20:00Z',
                    'requesterName' => 'Juan Santos',
                    'supplierName' => 'Global Rice Ltd',
                ],
            ],
            'documents' => [
                [
                    'id' => 'doc1',
                    'userId' => 'farmer1',
                    'documentType' => 'business_registration',
                    'fileName' => 'business_cert_2024.pdf',
                    'fileUrl' => '/docs/business_cert.pdf',
                    'status' => 'verified',
                    'ocrData' => [
                        'businessName' => "Maria's Rice Farm",
                        'permitNumber' => 'BR-2024-001',
                        'expiryDate' => '2025-12-31',
                    ],
                    'uploadedAt' => '2024-07-10T08:00:00Z',
                ],
                [
                    'id' => 'doc2',
                    'userId' => 'importer1',
                    'documentType' => 'permit',
                    'fileName' => 'import_permit.pdf',
                    'fileUrl' => '/docs/import_permit.pdf',
                    'status' => 'pending',
                    'ocrData' => [
                        'permitNumber' => 'IMP-2024-123',
                        'expiryDate' => '2024-12-31',
                    ],
                    'uploadedAt' => '2024-07-15T10:00:00Z',
                ],
            ],
            'notifications' => [
                [
                    'id' => 'notif1',
                    'userId' => 'supplier1',
                    'type' => 'stock_match',
                    'title' => 'New Stock Match Found',
                    'message' => 'Thai Jasmine rice at ₱28/kg matches your criteria',
                    'read' => false,
                    'createdAt' => '2024-07-20T15:00:00Z',
                    'relatedId' => 'stock4',
                ],
                [
                    'id' => 'notif2',
                    'userId' => 'farmer1',
                    'type' => 'request_status',
                    'title' => 'Procurement Request Accepted',
                    'message' => 'Your request for 100 kg Dinorado has been accepted',
                    'read' => true,
                    'createdAt' => '2024-07-19T12:00:00Z',
                    'relatedId' => 'req1',
                ],
            ],
            // No-DB (session) fallback starts empty — Warehouses/Add
            // Warehouse will populate this as the user uses them.
            'warehouses' => [],
            // No-DB (session) fallback starts empty — Stock In/Out/Adjustment
            // forms will populate this as the user uses them, same as a
            // fresh DB import would.
            'stock_movements' => [],
            // No-DB (session) fallback starts empty — populated the same way
            // the `price_history` table is: once per stock at creation, then
            // again whenever Edit Stock changes unitPrice.
            'price_history' => [],
            // Seeded to match req2 above, which starts pre-accepted — a
            // fresh DB import would have this row too, since create_request
            // + accept together produce one. Otherwise this stays empty and
            // fills up as requests get accepted (see procurement/incoming/).
            'procurement_orders' => [
                [
                    'id' => 'order1',
                    'requestId' => 'req2',
                    'stockId' => 'stock4',
                    'requesterId' => 'supplier1',
                    'supplierId' => 'importer1',
                    'requesterName' => 'Juan Santos',
                    'supplierName' => 'Global Rice Ltd',
                    'variety' => 'Jasmine',
                    'quantity' => 50,
                    'unit' => 'ton',
                    'status' => 'in_transit',
                    'createdAt' => '2024-07-19T14:20:00Z',
                    'updatedAt' => '2024-07-20T09:00:00Z',
                ],
            ],
            'users' => [
                'supplier1' => [
                    'id' => 'supplier1',
                    'email' => 'supplier@example.com',
                    'name' => 'Juan Santos',
                    'role' => 'supplier',
                    'company' => 'Santos Rice Trading',
                    'phone' => '+63-2-1234-5678',
                    'location' => 'Manila, Philippines',
                    'verified' => true,
                ],
                'farmer1' => [
                    'id' => 'farmer1',
                    'email' => 'farmer@example.com',
                    'name' => "Maria's Farm",
                    'role' => 'farmer',
                    'company' => "Maria's Rice Farm",
                    'phone' => '+63-917-123-4567',
                    'location' => 'Nueva Ecija, Philippines',
                    'verified' => true,
                ],
                'importer1' => [
                    'id' => 'importer1',
                    'email' => 'importer@example.com',
                    'name' => 'Global Rice Ltd',
                    'role' => 'importer',
                    'company' => 'Global Rice Imports',
                    'phone' => '+63-2-9876-5432',
                    'location' => 'Quezon City, Philippines',
                    'verified' => true,
                ],
                'admin1' => [
                    'id' => 'admin1',
                    'email' => 'admin@example.com',
                    'name' => 'Admin User',
                    'role' => 'admin',
                    'company' => '',
                    'verified' => true,
                ],
            ],
        ];
    }
}

function get_filter_value(string $key, $default = null) {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

// ---------------------------------------------------------------------
// Unit normalization (Critical Improvement: "Normalize inventory units").
// Kilograms are the canonical base quantity everywhere in the system —
// warehouse capacity, transparency comparisons, and price-per-kg all read
// the *_kg columns this section maintains rather than re-deriving them
// from a mix of kg/sack/ton at read time.
// ---------------------------------------------------------------------

/** The only units a stock listing/warehouse/movement may be recorded in. */
const ALLOWED_STOCK_UNITS = ['kg', 'sack', 'ton'];

/**
 * Weight of one "sack" in kg. Configurable via admin/settings/ (Phase 7's
 * settings table) since sack weight varies by region/packer — the
 * Philippine standard is 50kg, used as the default so nothing changes for
 * deployments that never touch this setting.
 */
function sack_weight_kg(): float {
    return (float) get_setting('SACK_WEIGHT_KG', '50');
}

/**
 * Convert a quantity in the given unit to its kilogram equivalent — the
 * single conversion point every part of the system should use instead of
 * summing raw quantity numbers across mixed units (which is what
 * warehouse capacity and price comparisons used to do).
 */
function unit_to_kg(float $quantity, string $unit): float {
    switch ($unit) {
        case 'ton':
            return $quantity * 1000;
        case 'sack':
            return $quantity * sack_weight_kg();
        case 'kg':
        default:
            return $quantity;
    }
}

/**
 * Price per kilogram for a listing, regardless of what unit it's priced
 * in — this is what lets Marketplace/Transparency compare a ₱/ton import
 * against a ₱/sack local listing on the same basis. Returns 0 if the unit
 * or price is somehow invalid rather than dividing by zero.
 */
function price_per_kg(float $unitPrice, string $unit): float {
    $kgPerUnit = unit_to_kg(1.0, $unit);
    return $kgPerUnit > 0 ? $unitPrice / $kgPerUnit : 0.0;
}

// ---------------------------------------------------------------------
// Reserved inventory (Phase 2: "Reserved inventory does not falsely
// reduce physical stock"). Accepting a procurement request commits a
// quantity to that buyer without moving it out of the seller's warehouse
// yet — the physical decrement only happens once it's actually shipped
// (see dispatch handling in procurement/tracking/index.php). Available
// quantity (what Marketplace and new requests can draw against) is always
// quantity - reservedQuantity, computed here rather than stored, so it
// can never drift out of sync with the two numbers it's derived from.
// ---------------------------------------------------------------------

/** Physical quantity minus whatever's already committed to other orders. */
function stock_available_quantity(array $stock): float {
    return max(0.0, (float) $stock['quantity'] - (float) ($stock['reservedQuantity'] ?? 0));
}

/**
 * Commit `$amount` of a stock listing to an accepted request/order. Does
 * NOT change physical quantity — only reservedQuantity, which narrows what
 * stock_available_quantity() reports as sellable. Fails if the requested
 * amount exceeds what's currently available, so two requests can't both
 * be accepted against the same over-committed stock. Wrapped in a transaction
 * to ensure the reservation and any dependent operations complete atomically.
 */
function reserve_stock_quantity(string $stockId, float $amount): array {
    if ($amount <= 0) {
        return ['success' => false, 'error' => 'Amount must be greater than zero.'];
    }

    if (database_available()) {
        try {
            begin_transaction();

            $stock = get_stock_by_id($stockId);
            if (!$stock) {
                rollback_transaction();
                return ['success' => false, 'error' => 'Stock not found.'];
            }
            $unit = $stock['unit'] ?? 'kg';
            $changeKg = unit_to_kg($amount, $unit);

            // BUGFIX: the previous version read reservedQuantity, checked it
            // in PHP, then wrote a value computed from that stale read. Two
            // concurrent Accepts against the same stock could both pass the
            // check (each reading the reservation count from before the
            // other's write) and both succeed, over-committing stock the
            // docstring explicitly promises can't happen. The WHERE clause
            // below re-checks availability atomically at write time — the
            // same guard decrement_stock_quantity() already uses for the
            // physical stock-out — so only one of two racing Accepts can win.
            $stmt = db()->prepare(
                'UPDATE stocks
                 SET reservedQuantity = reservedQuantity + ?,
                     reservedQuantityKg = reservedQuantityKg + ?,
                     updatedAt = ?
                 WHERE id = ? AND (quantity - reservedQuantity) >= ?'
            );
            $stmt->execute([$amount, $changeKg, date('Y-m-d H:i:s'), $stockId, $amount]);

            if ($stmt->rowCount() === 0) {
                rollback_transaction();
                $fresh = get_stock_by_id($stockId) ?? $stock;
                $available = stock_available_quantity($fresh);
                return ['success' => false, 'error' => "Only {$available} {$unit} is available (the rest is already reserved for other orders)."];
            }

            commit_transaction();
            return ['success' => true];
        } catch (Exception $e) {
            rollback_transaction();
            return ['success' => false, 'error' => 'Failed to reserve stock: ' . $e->getMessage()];
        }
    }

    // Session-fallback mode runs single-threaded per session, so the
    // read-then-write below has no concurrent-request race to guard against.
    init_data();
    $stock = get_stock_by_id($stockId);
    if (!$stock) {
        return ['success' => false, 'error' => 'Stock not found.'];
    }
    if ($amount > stock_available_quantity($stock)) {
        return ['success' => false, 'error' => 'Only ' . stock_available_quantity($stock) . ' ' . $stock['unit'] . ' is available (the rest is already reserved for other orders).'];
    }
    $newReserved = (float) ($stock['reservedQuantity'] ?? 0) + $amount;
    $newReservedKg = unit_to_kg($newReserved, $stock['unit']);
    foreach ($_SESSION['data']['stocks'] as &$s) {
        if ($s['id'] === $stockId) {
            $s['reservedQuantity'] = $newReserved;
            $s['reservedQuantityKg'] = $newReservedKg;
            $s['updatedAt'] = date('Y-m-d H:i:s');
            break;
        }
    }
    return ['success' => true];
}

/**
 * Release `$amount` of a previous reservation — either because the
 * reserved goods physically shipped (see release_stock_reservation() call
 * at dispatch, which pairs this with a real stock-out movement) or because
 * the order behind it was cancelled/rejected before shipping. Clamped to
 * zero so a double-release (e.g. a retried request) can never leave
 * reservedQuantity negative. Wrapped in a transaction.
 */
function release_stock_reservation(string $stockId, float $amount): void {
    try {
        begin_transaction();

        $stock = get_stock_by_id($stockId);
        if (!$stock) {
            rollback_transaction();
            return;
        }
        $newReserved = max(0.0, (float) ($stock['reservedQuantity'] ?? 0) - $amount);
        $newReservedKg = unit_to_kg($newReserved, $stock['unit']);

        if (database_available()) {
            // Atomic: subtract inside SQL (clamped at zero) instead of writing a
            // value computed in PHP from a possibly stale read, so a release that
            // runs at the same moment as a reserve or another release cannot
            // overwrite it.
            query_execute(
                'UPDATE stocks SET reservedQuantity = GREATEST(0, reservedQuantity - ?), reservedQuantityKg = GREATEST(0, reservedQuantityKg - ?), updatedAt = ? WHERE id = ?',
                [$amount, unit_to_kg($amount, $stock['unit']), date('Y-m-d H:i:s'), $stockId]
            );
        } else {
            init_data();
            foreach ($_SESSION['data']['stocks'] as &$s) {
                if ($s['id'] === $stockId) {
                    $s['reservedQuantity'] = $newReserved;
                    $s['reservedQuantityKg'] = $newReservedKg;
                    $s['updatedAt'] = date('Y-m-d H:i:s');
                    break;
                }
            }
        }

        commit_transaction();
    } catch (Exception $e) {
        rollback_transaction();
        error_log('Failed to release stock reservation for ' . $stockId . ': ' . $e->getMessage());
    }
}

function get_stocks(): array {
    if (database_available()) {
        return query_all('SELECT * FROM stocks ORDER BY createdAt DESC');
    }

    init_data();
    return $_SESSION['data']['stocks'];
}

function get_stock_by_id(string $id): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM stocks WHERE id = ?', [$id]);
    }

    foreach (get_stocks() as $stock) {
        if ($stock['id'] === $id) {
            return $stock;
        }
    }
    return null;
}

function get_user_by_id(string $userId): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    init_data();
    return $_SESSION['data']['users'][$userId] ?? null;
}

function get_user_stocks(string $userId): array {
    if (database_available()) {
        return query_all('SELECT * FROM stocks WHERE userId = ? ORDER BY createdAt DESC', [$userId]);
    }

    return array_values(array_filter(get_stocks(), fn($stock) => $stock['userId'] === $userId));
}

/**
 * Whether this owner already has another listing under this batch/lot
 * number. Scoped per-owner (not global) on purpose: a batch/lot number is
 * meant to trace one physical lot through the supply chain, and it's
 * expected — not a collision — for the *same* number to reappear under a
 * *different* owner once goods change hands (see
 * receive_procurement_order_stock(), which deliberately carries the
 * seller's batchNumber onto the buyer's new stock row). What this guards
 * against is one person entering two unrelated lots under one code, which
 * would make that person's own movement/order history ambiguous.
 */
function stock_batch_number_exists(string $batchNumber, string $userId): bool {
    if (database_available()) {
        $row = query_one('SELECT id FROM stocks WHERE batchNumber = ? AND userId = ?', [$batchNumber, $userId]);
        return $row !== null;
    }

    init_data();
    foreach ($_SESSION['data']['stocks'] as $stock) {
        if (($stock['batchNumber'] ?? null) === $batchNumber && $stock['userId'] === $userId) {
            return true;
        }
    }
    return false;
}

function create_stock(array $data, bool $allowDuplicateBatch = false): array {
    // Strengthen server-side validation: unit must be one of the three the
    // rest of the system (forms, unit_to_kg(), warehouse capacity) knows
    // how to handle — a form's <select> already limits this client-side,
    // but nothing previously stopped a raw POST from sending anything.
    $unit = $data['unit'] ?? 'kg';
    if (!in_array($unit, ALLOWED_STOCK_UNITS, true)) {
        throw new InvalidArgumentException('Unit must be one of: ' . implode(', ', ALLOWED_STOCK_UNITS));
    }

    // A batch/lot number is what makes this listing traceable end to end
    // (stock -> movements -> procurement order -> delivery). Callers can
    // supply their own (e.g. a farmer's harvest lot code); if they don't,
    // generate one rather than leave new stock untraceable by default.
    $batchNumber = trim($data['batchNumber'] ?? '');
    if ($batchNumber === '') {
        $batchNumber = 'LOT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    } elseif (!$allowDuplicateBatch && stock_batch_number_exists($batchNumber, $data['userId'] ?? '')) {
        // Belt-and-suspenders: inventory/add/'s form already checks this
        // before calling create_stock(), but enforcing it here too means
        // no other caller (CSV import, a future API) can bypass it and
        // create two lots sharing one traceability number under the same
        // owner. Scoped per-owner — see stock_batch_number_exists().
        // $allowDuplicateBatch is for the one legitimate exception: Phase
        // 2's warehouse quantity splitting (split_stock_across_warehouse())
        // deliberately creates a second row under the SAME owner and SAME
        // batch number, because it's still the same physical lot just
        // divided across two locations — not a naming collision.
        throw new InvalidArgumentException('Batch/lot number "' . $batchNumber . '" is already in use.');
    }

    // supplierName is what every marketplace card/compare/detail view
    // displays as "who's selling this" — none of the call sites (Add
    // Stock, CSV import, procurement receipt) actually pass it in, so
    // without this fallback every listing would show a blank supplier.
    // Fall back to the owning account's name, same field procurement
    // requests use for requesterName/supplierName elsewhere in this file.
    $supplierName = trim($data['supplierName'] ?? '');
    if ($supplierName === '') {
        $owner = get_user_by_id($data['userId'] ?? '');
        $supplierName = $owner['name'] ?? '';
    }

    $stock = array_merge($data, [
        'id' => 'stock_' . time() . '_' . bin2hex(random_bytes(4)),
        'batchNumber' => $batchNumber,
        'supplierName' => $supplierName,
        'imageUrl' => $data['imageUrl'] ?? null,
        'baseQuantityKg' => unit_to_kg((float) ($data['quantity'] ?? 0), $unit),
        // New stock is never reserved at creation — reservations only ever
        // come from an accepted procurement request (reserve_stock_quantity()).
        'reservedQuantity' => 0.0,
        'reservedQuantityKg' => 0.0,
        'createdAt' => date('Y-m-d H:i:s'),
        'updatedAt' => date('Y-m-d H:i:s'),
    ]);

    if (database_available()) {
        query_execute(
            'INSERT INTO stocks (id, userId, variety, quantity, unit, baseQuantityKg, unitPrice, source, countryOfOrigin, status, createdAt, updatedAt, supplierName, description, imageUrl, batchNumber) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $stock['id'],
                $stock['userId'],
                $stock['variety'],
                $stock['quantity'],
                $stock['unit'],
                $stock['baseQuantityKg'],
                $stock['unitPrice'],
                $stock['source'],
                $stock['countryOfOrigin'],
                $stock['status'],
                $stock['createdAt'],
                $stock['updatedAt'],
                $stock['supplierName'],
                $stock['description'] ?? '',
                $stock['imageUrl'],
                $stock['batchNumber'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['stocks'][] = $stock;
    }

    log_activity($stock['userId'], 'stock.create', 'stock', $stock['id'], $stock['variety'] . ': ' . $stock['quantity'] . ' ' . $stock['unit']);
    maybe_notify_stock_threshold($stock);
    // Starting price, so Price Monitoring has a baseline to trend from —
    // oldPrice is NULL to mark this as the listing's first recorded price
    // rather than a change from something.
    record_price_change($stock['id'], null, (float) $stock['unitPrice']);

    return $stock;
}

/**
 * Update an existing stock listing's editable fields. Callers are
 * responsible for checking ownership (or admin override) before calling
 * this — it does not re-check who's allowed to edit which row.
 */
function update_stock(string $id, array $fields): void {
    // 'quantity' deliberately excluded: this is the ordinary "edit a
    // listing" path (variety, price, description, photo, etc.), not a
    // physical stock change. Quantity must only ever move through
    // record_stock_movement() (Stock In / Stock Out / Adjustment), which
    // writes an auditable stock_movements row alongside it — otherwise a
    // routine edit could silently alter how much rice supposedly exists
    // with no record of why. If a caller passes 'quantity' here (e.g. an
    // old form field that wasn't updated), it's silently dropped rather
    // than applied, so this is a hard boundary rather than a convention.
    $allowed = [
        'variety', 'unit', 'unitPrice', 'source',
        'countryOfOrigin', 'description', 'status', 'warehouseId', 'imageUrl',
    ];
    $fields = array_intersect_key($fields, array_flip($allowed));
    if (empty($fields)) {
        return;
    }
    $fields['updatedAt'] = date('Y-m-d H:i:s');

    // Unit can change on an ordinary edit (e.g. correcting "sack" to "kg"
    // on a listing that was mis-entered) even though quantity itself
    // can't — recompute the normalized kg figures (physical and reserved)
    // to match so warehouse capacity and price-per-kg stay correct. See
    // unit_to_kg().
    if (array_key_exists('unit', $fields)) {
        $existingForUnit = get_stock_by_id($id);
        if ($existingForUnit) {
            $fields['baseQuantityKg'] = unit_to_kg((float) $existingForUnit['quantity'], $fields['unit']);
            $fields['reservedQuantityKg'] = unit_to_kg((float) ($existingForUnit['reservedQuantity'] ?? 0), $fields['unit']);
        }
    }

    // Snapshot the price before it's overwritten below, so a real change
    // (not just re-saving the same value) can be logged once the update
    // itself has gone through.
    $oldPrice = null;
    if (array_key_exists('unitPrice', $fields)) {
        $existing = get_stock_by_id($id);
        $oldPrice = $existing ? (float) $existing['unitPrice'] : null;
    }

    if (database_available()) {
        $setSql = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($fields)));
        query_execute("UPDATE stocks SET $setSql WHERE id = ?", [...array_values($fields), $id]);
    } else {
        init_data();
        foreach ($_SESSION['data']['stocks'] as &$stock) {
            if ($stock['id'] === $id) {
                $stock = array_merge($stock, $fields);
                break;
            }
        }
    }

    if (array_key_exists('unitPrice', $fields) && $oldPrice !== null && $oldPrice !== (float) $fields['unitPrice']) {
        record_price_change($id, $oldPrice, (float) $fields['unitPrice']);
    }
}

/**
 * Internal-only: actually write a new physical quantity (+ status, + the
 * recomputed kg figure) to a stock row. NOT part of update_stock()'s
 * public field set on purpose — the only callers of this should be
 * record_stock_movement(), decrement_stock_quantity(), and
 * receive_procurement_order_stock() (via create_stock()), each of which
 * writes/received a stock_movements row for the same change in the same
 * breath. Calling this without also logging a movement recreates exactly
 * the silent-quantity-edit problem update_stock() now guards against.
 */
function apply_stock_quantity_change(string $id, float $newQuantity, string $newStatus): void {
    $stock = get_stock_by_id($id);
    $unit = $stock['unit'] ?? 'kg';
    $fields = [
        'quantity' => $newQuantity,
        'baseQuantityKg' => unit_to_kg($newQuantity, $unit),
        'status' => $newStatus,
        'updatedAt' => date('Y-m-d H:i:s'),
    ];

    if (database_available()) {
        $setSql = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($fields)));
        query_execute("UPDATE stocks SET $setSql WHERE id = ?", [...array_values($fields), $id]);
    } else {
        init_data();
        foreach ($_SESSION['data']['stocks'] as &$s) {
            if ($s['id'] === $id) {
                $s = array_merge($s, $fields);
                break;
            }
        }
    }

    maybe_notify_stock_threshold(get_stock_by_id($id));
}

/**
 * Record a stock quantity change and apply it in one step: writes a
 * stock_movements row (for Inventory History / Warehouse / Transparency to
 * read later) and updates stocks.quantity + status to match.
 *
 * @param string $stockId
 * @param string $type    'in' | 'out' | 'adjustment'
 * @param float  $change   Signed amount: positive for 'in' and positive
 *                         adjustments, negative for 'out' and negative
 *                         adjustments.
 * @param string|null $reason Optional free-text note (e.g. "Fresh harvest",
 *                             "Sold to buyer", "Recount correction").
 * @return array{success:bool, error?:string, stock?:array}
 */
function record_stock_movement(string $stockId, string $type, float $change, ?string $reason = null, ?string $reasonCategory = null, ?string $procurementOrderId = null): array {
    $stock = get_stock_by_id($stockId);
    if (!$stock) {
        return ['success' => false, 'error' => 'Stock not found.'];
    }

    $before = (float) $stock['quantity'];
    $after = $before + $change;
    if ($after < 0) {
        return ['success' => false, 'error' => "Only {$before} {$stock['unit']} available, cannot remove " . abs($change) . '.'];
    }

    // Same capacity guard as place_stock_in_warehouse(): if this listing is
    // already sitting in a warehouse, growing its quantity (Stock In, or an
    // Adjustment increase) can push that warehouse over capacity just as
    // easily as a placement can — check it here too rather than only at
    // the moment stock is moved in. Warehouse capacity is normalized kg
    // (see get_warehouse_capacity_used()), so the increase is converted to
    // kg before comparing, rather than comparing raw numbers across
    // possibly-different units.
    $changeKg = unit_to_kg($change, $stock['unit']);
    if ($change > 0 && !empty($stock['warehouseId'])) {
        $warehouse = get_warehouse_by_id($stock['warehouseId']);
        if ($warehouse && (float) $warehouse['capacity'] > 0) {
            // capacity_used already includes this stock's current (pre-change)
            // quantity, so add only the increase being applied.
            $usedAfter = get_warehouse_capacity_used($stock['warehouseId']) + $changeKg;
            if ($usedAfter > (float) $warehouse['capacity']) {
                $remaining = max(0, (float) $warehouse['capacity'] - get_warehouse_capacity_used($stock['warehouseId']));
                return ['success' => false, 'error' => "{$warehouse['name']} only has {$remaining} kg of capacity left — reduce the amount, or move this listing out of that warehouse first."];
            }
        }
    }

    $movement = [
        'id' => 'move_' . time() . '_' . bin2hex(random_bytes(4)),
        'stockId' => $stockId,
        'userId' => current_user_id() ?? $stock['userId'],
        'type' => $type,
        'quantityChange' => $change,
        'quantityChangeKg' => $changeKg,
        'quantityBefore' => $before,
        'quantityBeforeKg' => unit_to_kg($before, $stock['unit']),
        'quantityAfter' => $after,
        'quantityAfterKg' => unit_to_kg($after, $stock['unit']),
        'reason' => $reason,
        'reasonCategory' => $reasonCategory,
        'procurementOrderId' => $procurementOrderId,
        'batchNumber' => $stock['batchNumber'] ?? null,
        'fromWarehouseId' => null,
        'toWarehouseId' => null,
        'createdAt' => date('Y-m-d H:i:s'),
    ];

    if (database_available()) {
        query_execute(
            'INSERT INTO stock_movements (id, stockId, userId, type, quantityChange, quantityChangeKg, quantityBefore, quantityBeforeKg, quantityAfter, quantityAfterKg, reason, reasonCategory, procurementOrderId, batchNumber, fromWarehouseId, toWarehouseId, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $movement['id'], $movement['stockId'], $movement['userId'], $movement['type'],
                $movement['quantityChange'], $movement['quantityChangeKg'],
                $movement['quantityBefore'], $movement['quantityBeforeKg'],
                $movement['quantityAfter'], $movement['quantityAfterKg'],
                $movement['reason'], $movement['reasonCategory'], $movement['procurementOrderId'],
                $movement['batchNumber'], $movement['fromWarehouseId'], $movement['toWarehouseId'], $movement['createdAt'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['stock_movements'][] = $movement;
    }

    // Same status logic Edit Stock already uses: zero (or below) is
    // sold_out, otherwise available — unless the listing is archived, which
    // a movement should never silently un-archive.
    $newStatus = $stock['status'];
    if ($newStatus !== 'archived') {
        $newStatus = $after > 0 ? 'available' : 'sold_out';
    }

    apply_stock_quantity_change($stockId, $after, $newStatus);
    log_activity($movement['userId'], 'stock.' . $type, 'stock', $stockId, ($reason ?: ucfirst($type)) . ': ' . $change . ' ' . $stock['unit']);

    // Same trigger as the capacity check earlier in this function — the
    // guard and the alert key off the identical condition; the alert just
    // fires before the block ever would.
    if ($change > 0 && !empty($stock['warehouseId'])) {
        maybe_notify_warehouse_capacity($stock['warehouseId']);
    }

    $result = ['success' => true, 'stock' => get_stock_by_id($stockId)];

    // A decrease (Stock Out, or a negative Adjustment) can legitimately eat
    // into quantity that's already reserved for another accepted order —
    // e.g. reporting damage on a lot that's partly promised to a buyer.
    // This isn't blocked (the physical loss is real either way), but it's
    // surfaced as a warning rather than silently leaving that order's
    // reservation pointing at rice that no longer exists.
    if ($change < 0 && $after < (float) ($stock['reservedQuantity'] ?? 0)) {
        $shortfall = (float) $stock['reservedQuantity'] - $after;
        $result['warning'] = "This leaves only {$after} {$stock['unit']}, but {$stock['reservedQuantity']} {$stock['unit']} is already reserved for other orders — {$shortfall} {$stock['unit']} short. You may need to adjust or cancel an affected order.";
    }

    return $result;
}

/**
 * Movement history for one stock listing, most recent first. Backs the
 * Inventory History view and the per-item history panel.
 */
function get_stock_movements(string $stockId): array {
    if (database_available()) {
        return query_all('SELECT * FROM stock_movements WHERE stockId = ? ORDER BY createdAt DESC', [$stockId]);
    }

    init_data();
    $rows = array_values(array_filter(
        $_SESSION['data']['stock_movements'] ?? [],
        fn($m) => $m['stockId'] === $stockId
    ));
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $rows;
}

/**
 * All movements across a user's own stock, most recent first — the full
 * Inventory History list (as opposed to one item's history).
 */
function get_user_stock_movements(string $userId): array {
    if (database_available()) {
        return query_all(
            'SELECT m.* FROM stock_movements m INNER JOIN stocks s ON s.id = m.stockId WHERE s.userId = ? ORDER BY m.createdAt DESC',
            [$userId]
        );
    }

    init_data();
    $ownStockIds = array_column(get_user_stocks($userId), 'id');
    $rows = array_values(array_filter(
        $_SESSION['data']['stock_movements'] ?? [],
        fn($m) => in_array($m['stockId'], $ownStockIds, true)
    ));
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $rows;
}

// ---------------------------------------------------------------------
// Transparency module (Supply Overview / Stock Movement / Supply
// Availability / Price Monitoring) — mostly read-only views over
// stocks/stock_movements; price_history is the one new table.
// ---------------------------------------------------------------------

/**
 * Log one price change for a stock listing. Called automatically from
 * create_stock() (oldPrice NULL — the listing's starting price) and from
 * update_stock() whenever unitPrice actually changes, so callers never need
 * to log this themselves.
 */
function record_price_change(string $stockId, ?float $oldPrice, float $newPrice): void {
    $entry = [
        'id' => 'price_' . time() . '_' . bin2hex(random_bytes(4)),
        'stockId' => $stockId,
        'userId' => current_user_id(),
        'oldPrice' => $oldPrice,
        'newPrice' => $newPrice,
        'createdAt' => date('Y-m-d H:i:s'),
    ];

    if (database_available()) {
        query_execute(
            'INSERT INTO price_history (id, stockId, userId, oldPrice, newPrice, createdAt) VALUES (?, ?, ?, ?, ?, ?)',
            [$entry['id'], $entry['stockId'], $entry['userId'], $entry['oldPrice'], $entry['newPrice'], $entry['createdAt']]
        );
    } else {
        init_data();
        $_SESSION['data']['price_history'][] = $entry;
    }
}

/**
 * Price history for one stock listing, most recent first.
 */
function get_price_history(string $stockId): array {
    if (database_available()) {
        return query_all('SELECT * FROM price_history WHERE stockId = ? ORDER BY createdAt DESC', [$stockId]);
    }

    init_data();
    $rows = array_values(array_filter(
        $_SESSION['data']['price_history'] ?? [],
        fn($p) => $p['stockId'] === $stockId
    ));
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $rows;
}

/**
 * Most recent price changes system-wide, joined with the stock's variety/
 * unit/source/supplierName so Price Monitoring can render a readable feed
 * without a separate lookup per row.
 *
 * $filters supports 'variety' (exact match) and 'dateFrom'/'dateTo' (Y-m-d,
 * inclusive) — same convention as get_all_stock_movements(). Filtering is
 * applied before $limit, so a filtered view isn't silently missing older
 * matches that would've fallen outside an unfiltered top-$limit.
 */
function get_recent_price_changes(int $limit = 100, array $filters = []): array {
    $variety = trim($filters['variety'] ?? '');
    $dateFrom = trim($filters['dateFrom'] ?? '');
    $dateTo = trim($filters['dateTo'] ?? '');

    if (database_available()) {
        $where = [];
        $params = [];
        if ($variety !== '') {
            $where[] = 's.variety = ?';
            $params[] = $variety;
        }
        if ($dateFrom !== '') {
            $where[] = 'p.createdAt >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'p.createdAt <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return query_all(
            "SELECT p.*, s.variety, s.unit, s.source, s.supplierName
             FROM price_history p
             INNER JOIN stocks s ON s.id = p.stockId
             $whereSql
             ORDER BY p.createdAt DESC
             LIMIT " . (int) $limit,
            $params
        );
    }

    init_data();
    $stocksById = array_column(get_stocks(), null, 'id');
    $rows = array_values(array_filter(
        $_SESSION['data']['price_history'] ?? [],
        function ($p) use ($stocksById, $variety, $dateFrom, $dateTo) {
            if (!isset($stocksById[$p['stockId']])) {
                return false;
            }
            if ($variety !== '' && $stocksById[$p['stockId']]['variety'] !== $variety) {
                return false;
            }
            if ($dateFrom !== '' && $p['createdAt'] < $dateFrom . ' 00:00:00') {
                return false;
            }
            if ($dateTo !== '' && $p['createdAt'] > $dateTo . ' 23:59:59') {
                return false;
            }
            return true;
        }
    ));
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    $rows = array_slice($rows, 0, $limit);
    return array_map(function ($p) use ($stocksById) {
        $stock = $stocksById[$p['stockId']];
        return array_merge($p, [
            'variety' => $stock['variety'],
            'unit' => $stock['unit'],
            'source' => $stock['source'],
            'supplierName' => $stock['supplierName'],
        ]);
    }, $rows);
}

/**
 * Current price snapshot per variety (min/avg/max, listing count) across
 * available stock — the summary cards at the top of Price Monitoring.
 */
/**
 * Min/avg/max price per variety across currently-available listings,
 * normalized to ₱/kg (Section 8: "Price monitoring should compare
 * normalized ₱/kg") — computed in PHP via price_per_kg() rather than a
 * plain SQL AVG(unitPrice), which would silently average a ₱/kg listing
 * against a ₱/sack listing of the same variety as if they were priced the
 * same way.
 */
function get_price_summary_by_variety(): array {
    $summary = [];
    foreach (get_stocks() as $stock) {
        if ($stock['status'] !== 'available') {
            continue;
        }
        $variety = $stock['variety'];
        $pricePerKg = price_per_kg((float) $stock['unitPrice'], $stock['unit']);
        if (!isset($summary[$variety])) {
            $summary[$variety] = ['variety' => $variety, 'listingCount' => 0, 'minPrice' => $pricePerKg, 'maxPrice' => $pricePerKg, 'total' => 0.0];
        }
        $summary[$variety]['listingCount']++;
        $summary[$variety]['minPrice'] = min($summary[$variety]['minPrice'], $pricePerKg);
        $summary[$variety]['maxPrice'] = max($summary[$variety]['maxPrice'], $pricePerKg);
        $summary[$variety]['total'] += $pricePerKg;
    }
    $rows = array_values($summary);
    foreach ($rows as &$row) {
        $row['avgPrice'] = $row['listingCount'] > 0 ? $row['total'] / $row['listingCount'] : 0;
        unset($row['total']);
    }
    usort($rows, fn($a, $b) => strcmp($a['variety'], $b['variety']));
    return $rows;
}

/**
 * Day-by-day average available-listing price over the last $days days,
 * optionally scoped to one variety — powers the Price Monitoring trend
 * chart. Reconstructed the same way get_avg_rice_price_trend() reconstructs
 * its single before/after comparison, just repeated for every day in the
 * window: walk each currently-available stock's price_history (oldest
 * first) to find what its price actually was as of that day, then average
 * across every matching stock. A stock with no history at all is assumed
 * to have held its current price for the whole window — same assumption
 * get_avg_rice_price_trend() already makes, kept consistent here so the
 * two don't disagree on the same underlying data.
 */
function get_price_trend_series(int $days = 30, ?string $variety = null): array {
    $days = max(2, min(180, $days));
    $variety = trim((string) $variety);

    $stocks = array_values(array_filter(get_stocks(), function ($s) use ($variety) {
        if ($s['status'] !== 'available') {
            return false;
        }
        if ($variety !== '' && $s['variety'] !== $variety) {
            return false;
        }
        return true;
    }));
    if (empty($stocks)) {
        return [];
    }

    // Ascending-time history per stock, computed once so each of the $days
    // day-lookups below is a cheap linear scan instead of a fresh
    // fetch/sort per day.
    $historyByStock = [];
    foreach ($stocks as $stock) {
        $entries = get_price_history($stock['id']);
        usort($entries, fn($a, $b) => strcmp($a['createdAt'], $b['createdAt']));
        $historyByStock[$stock['id']] = $entries;
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $dayEnd = date('Y-m-d 23:59:59', strtotime("-{$i} days"));
        $sum = 0.0;
        foreach ($stocks as $stock) {
            $price = (float) $stock['unitPrice'];
            foreach ($historyByStock[$stock['id']] as $entry) {
                if ($entry['createdAt'] > $dayEnd) {
                    break;
                }
                $price = (float) $entry['newPrice'];
            }
            // Convert to ₱/kg before averaging — otherwise a per-sack listing
            // and a per-kg listing of the same variety get averaged as if
            // they were the same unit, producing a meaningless number
            // (Section 8: "Price monitoring should compare normalized ₱/kg").
            $sum += price_per_kg($price, $stock['unit']);
        }
        $series[] = [
            'date' => date('Y-m-d', strtotime("-{$i} days")),
            'label' => date('M j', strtotime("-{$i} days")),
            'avgPrice' => $sum / count($stocks),
        ];
    }
    return $series;
}

/**
 * Every stock row that has ever carried this batch/lot number — the
 * original listing, any warehouse splits (split_stock_across_warehouse()),
 * and any buyer-received copies (receive_procurement_order_stock()) all
 * share one batch number by design (see create_stock()'s
 * $allowDuplicateBatch parameter), so this is the full set of "physical
 * pieces" of one lot across its life and across ownership changes.
 */
function get_stocks_by_batch_number(string $batchNumber): array {
    if (database_available()) {
        return query_all('SELECT * FROM stocks WHERE batchNumber = ? ORDER BY createdAt ASC', [$batchNumber]);
    }
    init_data();
    $rows = array_values(array_filter($_SESSION['data']['stocks'], fn($s) => ($s['batchNumber'] ?? null) === $batchNumber));
    usort($rows, fn($a, $b) => strcmp($a['createdAt'], $b['createdAt']));
    return $rows;
}

/** Every movement recorded against this batch number, oldest first, across every stock row that has ever carried it. */
function get_movements_by_batch_number(string $batchNumber): array {
    if (database_available()) {
        return query_all('SELECT * FROM stock_movements WHERE batchNumber = ? ORDER BY createdAt ASC', [$batchNumber]);
    }
    init_data();
    $rows = array_values(array_filter($_SESSION['data']['stock_movements'] ?? [], fn($m) => ($m['batchNumber'] ?? null) === $batchNumber));
    usort($rows, fn($a, $b) => strcmp($a['createdAt'], $b['createdAt']));
    return $rows;
}

/** Every procurement order that sourced from this batch number, oldest first. */
function get_procurement_orders_by_batch_number(string $batchNumber): array {
    if (database_available()) {
        return query_all('SELECT * FROM procurement_orders WHERE batchNumber = ? ORDER BY createdAt ASC', [$batchNumber]);
    }
    init_data();
    $rows = array_values(array_filter($_SESSION['data']['procurement_orders'] ?? [], fn($o) => ($o['batchNumber'] ?? null) === $batchNumber));
    usort($rows, fn($a, $b) => strcmp($a['createdAt'], $b['createdAt']));
    return $rows;
}

/**
 * Reconstructs one batch/lot's life story end to end for the Transparency
 * "Batch Timeline" (Section 8.1: Source/farmer, Warehouse receipt/
 * transfer, Marketplace listing, Procurement order, Dispatch, Transit,
 * Delivery, Inspection, Buyer receipt) by merging three independently-kept
 * records — the stock row(s), stock_movements, and procurement_orders
 * that all share this batch number — into one chronological list. Harvest
 * date isn't tracked as a distinct field anywhere in this system, so it's
 * not a separate stage here; "Source/farmer" and "Marketplace listing"
 * collapse into a single "Listed" event (stock creation IS the listing in
 * this system's model, not two separate steps).
 *
 * Returns null if nothing carries this batch number at all.
 */
function get_batch_timeline(string $batchNumber): ?array {
    $stocks = get_stocks_by_batch_number($batchNumber);
    if (empty($stocks)) {
        return null;
    }
    $movements = get_movements_by_batch_number($batchNumber);
    $orders = get_procurement_orders_by_batch_number($batchNumber);

    $events = [];
    foreach ($stocks as $stock) {
        // A stock row created to hold what a buyer received (see
        // receive_procurement_order_stock()) is a distinct stage
        // ("Buyer receipt") from the original lot being listed
        // ("Source/farmer" + "Marketplace listing") — both create a stocks
        // row, but they mean different things in the lot's life story.
        $isReceivedCopy = str_starts_with((string) ($stock['description'] ?? ''), 'Received via procurement order');
        $events[] = [
            'at' => $stock['createdAt'],
            'stage' => $isReceivedCopy ? 'Buyer Receipt' : 'Source / Marketplace Listing',
            'detail' => $stock['variety'] . ' — ' . $stock['quantity'] . ' ' . $stock['unit'] . ' (' . ($stock['source'] === 'imported' ? 'Imported, ' . ($stock['countryOfOrigin'] ?: 'origin not recorded') : 'Local') . ')',
            'actor' => $stock['supplierName'] ?: $stock['userId'],
            'stockId' => $stock['id'],
        ];
    }
    foreach ($movements as $m) {
        $stage = match (true) {
            $m['type'] === 'transfer' && empty($m['fromWarehouseId']) && !empty($m['toWarehouseId']) => 'Warehouse Receipt',
            $m['type'] === 'transfer' => 'Warehouse Transfer',
            $m['type'] === 'out' && $m['reasonCategory'] === 'procurement_order' => 'Dispatch',
            $m['type'] === 'out' => 'Stock Out',
            $m['type'] === 'in' => 'Stock In',
            default => 'Adjustment',
        };
        $events[] = [
            'at' => $m['createdAt'],
            'stage' => $stage,
            'detail' => ($m['reason'] ?: ucfirst($m['type'])) . ' (' . $m['quantityChange'] . ')',
            'actor' => $m['userId'],
            'stockId' => $m['stockId'],
        ];
    }
    foreach ($orders as $o) {
        $events[] = [
            'at' => $o['createdAt'],
            'stage' => 'Procurement Order',
            'detail' => $o['quantity'] . ' ' . $o['unit'] . ' ordered by ' . $o['requesterName'] . ' from ' . $o['supplierName'] . ' (Order ' . $o['id'] . ')',
            'actor' => $o['requesterName'],
            'orderId' => $o['id'],
        ];
        if (!empty($o['quantityShipped'])) {
            $events[] = [
                'at' => $o['updatedAt'],
                'stage' => 'Dispatch / Transit',
                'detail' => $o['quantityShipped'] . ' ' . $o['unit'] . ' shipped' . ($o['carrier'] ? ' via ' . $o['carrier'] : '') . ($o['trackingReference'] ? ' (Ref: ' . $o['trackingReference'] . ')' : ''),
                'actor' => $o['supplierName'],
                'orderId' => $o['id'],
            ];
        }
        if (!empty($o['actualDeliveryDate'])) {
            $events[] = [
                'at' => $o['actualDeliveryDate'],
                'stage' => 'Delivery',
                'detail' => 'Delivered to ' . $o['requesterName'],
                'actor' => $o['supplierName'],
                'orderId' => $o['id'],
            ];
        }
        if (!empty($o['inspectionStatus'])) {
            $events[] = [
                'at' => $o['updatedAt'],
                'stage' => 'Inspection',
                'detail' => ucfirst($o['inspectionStatus']) . ' — ' . ($o['quantityReceived'] ?? '0') . ' ' . $o['unit'] . ' received' . (!empty($o['inspectionNotes']) ? ': ' . $o['inspectionNotes'] : ''),
                'actor' => $o['requesterName'],
                'orderId' => $o['id'],
            ];
        }
    }

    usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));

    return [
        'batchNumber' => $batchNumber,
        'stocks' => $stocks,
        'events' => $events,
    ];
}

/**
 * Every stock movement system-wide, most recent first, joined with the
 * stock's variety/unit/source/supplierName — backs the Transparency Stock
 * Movement feed (as opposed to get_user_stock_movements(), which is scoped
 * to one user's own inventory).
 *
 * $filters supports: 'variety' (exact match), 'type' (in|out|adjustment|
 * transfer), 'dateFrom'/'dateTo' (Y-m-d, inclusive). Any key left out or
 * blank is not applied. Filtering happens before pagination (both in SQL
 * and in the session-fallback path) so page 2 of a filtered view is never
 * missing rows that would've been trimmed off by an unfiltered LIMIT.
 *
 * Returns ['rows' => [...], 'total' => int] — total is the filtered count
 * across every page, so the caller can render "Page X of Y" / a Next
 * button without a second round trip.
 */
function get_all_stock_movements(array $filters = [], int $page = 1, int $perPage = 50): array {
    $variety = trim($filters['variety'] ?? '');
    $type = trim($filters['type'] ?? '');
    $dateFrom = trim($filters['dateFrom'] ?? '');
    $dateTo = trim($filters['dateTo'] ?? '');
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    if (database_available()) {
        $where = [];
        $params = [];
        if ($variety !== '') {
            $where[] = 's.variety = ?';
            $params[] = $variety;
        }
        if ($type !== '') {
            $where[] = 'm.type = ?';
            $params[] = $type;
        }
        if ($dateFrom !== '') {
            $where[] = 'm.createdAt >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'm.createdAt <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) (query_one(
            "SELECT COUNT(*) AS c FROM stock_movements m INNER JOIN stocks s ON s.id = m.stockId $whereSql",
            $params
        )['c'] ?? 0);

        $rows = query_all(
            "SELECT m.*, s.variety, s.unit, s.source, s.supplierName
             FROM stock_movements m
             INNER JOIN stocks s ON s.id = m.stockId
             $whereSql
             ORDER BY m.createdAt DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    init_data();
    $stocksById = array_column(get_stocks(), null, 'id');
    $rows = array_values(array_filter(
        $_SESSION['data']['stock_movements'] ?? [],
        function ($m) use ($stocksById, $variety, $type, $dateFrom, $dateTo) {
            if (!isset($stocksById[$m['stockId']])) {
                return false;
            }
            $stock = $stocksById[$m['stockId']];
            if ($variety !== '' && $stock['variety'] !== $variety) {
                return false;
            }
            if ($type !== '' && $m['type'] !== $type) {
                return false;
            }
            if ($dateFrom !== '' && $m['createdAt'] < $dateFrom . ' 00:00:00') {
                return false;
            }
            if ($dateTo !== '' && $m['createdAt'] > $dateTo . ' 23:59:59') {
                return false;
            }
            return true;
        }
    ));
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    $total = count($rows);
    $rows = array_slice($rows, $offset, $perPage);
    $rows = array_map(function ($m) use ($stocksById) {
        $stock = $stocksById[$m['stockId']];
        return array_merge($m, [
            'variety' => $stock['variety'],
            'unit' => $stock['unit'],
            'source' => $stock['source'],
            'supplierName' => $stock['supplierName'],
        ]);
    }, $rows);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Movement volume by type over the last $days, system-wide — the summary
 * cards at the top of the Stock Movement view (and the Dashboard's own
 * copy of them). quantityChangeKg (not quantityChange) is summed as an
 * absolute value, both so 'out' movements (stored as negative) show a
 * positive volume rather than cancelling the total, and so movements
 * recorded against stocks in different units (kg/sack/ton) add up to a
 * real combined weight instead of mixing raw unit-less numbers together.
 *
 * BUGFIX: this previously summed the raw quantityChange column directly.
 * A stock movement's quantityChange is in whatever unit that stock uses —
 * summing a kg movement, a sack movement, and a ton movement together is
 * the same unit-mixing mistake already fixed in get_stock_counts_by_variety()
 * and Marketplace Compare's price display. quantityChangeKg is recorded
 * alongside quantityChange on every movement for exactly this reason (see
 * place_stock_in_warehouse()'s insert).
 */
function get_movement_totals(int $days = 30, ?string $userId = null): array {
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $types = ['in', 'out', 'adjustment', 'transfer'];

    if (database_available()) {
        if ($userId !== null) {
            $rows = query_all(
                'SELECT type, COUNT(*) AS movementCount, SUM(ABS(quantityChangeKg)) AS totalQuantity
                 FROM stock_movements
                 WHERE createdAt >= ? AND userId = ?
                 GROUP BY type',
                [$since, $userId]
            );
        } else {
            $rows = query_all(
                'SELECT type, COUNT(*) AS movementCount, SUM(ABS(quantityChangeKg)) AS totalQuantity
                 FROM stock_movements
                 WHERE createdAt >= ?
                 GROUP BY type',
                [$since]
            );
        }
        $byType = array_column($rows, null, 'type');
    } else {
        init_data();
        $byType = [];
        foreach ($_SESSION['data']['stock_movements'] ?? [] as $m) {
            if ($m['createdAt'] < $since) {
                continue;
            }
            if ($userId !== null && ($m['userId'] ?? null) !== $userId) {
                continue;
            }
            if (!isset($byType[$m['type']])) {
                $byType[$m['type']] = ['type' => $m['type'], 'movementCount' => 0, 'totalQuantity' => 0.0];
            }
            $byType[$m['type']]['movementCount']++;
            $byType[$m['type']]['totalQuantity'] += abs((float) ($m['quantityChangeKg'] ?? $m['quantityChange']));
        }
    }

    // Always return all four types, zero-filled, so the summary cards don't
    // have to check isset() for a type with no activity yet.
    $result = [];
    foreach ($types as $type) {
        $result[$type] = $byType[$type] ?? ['type' => $type, 'movementCount' => 0, 'totalQuantity' => 0.0];
    }
    return $result;
}


/**
 * Available stock grouped by variety and source — the Supply Availability
 * table. Similar to get_stock_counts_by_variety() but split by local vs.
 * imported, since that's the distinction Transparency cares about.
 *
 * $variety, when non-empty, narrows this to one variety (exact match) —
 * the same "all vs. one" convention get_price_history()/get_stock_by_id()
 * use elsewhere, rather than a separate get_supply_availability_by_variety().
 */
/**
 * Available rice grouped by variety + source, normalized to kg
 * (totalQuantityKg, availableQuantityKg) and ₱/kg (avgPricePerKg) so a
 * kg-priced local lot and a ton-priced imported lot of the same variety
 * can be compared and summed meaningfully (Section 8: normalized
 * comparison) — a plain SUM(quantity)/AVG(unitPrice) would add/average
 * raw numbers across whatever unit each listing happened to use.
 * availableQuantityKg nets out Phase 2 reservations; totalQuantityKg is
 * the physical total regardless of how much of it is reserved.
 */
function get_supply_availability(?string $variety = null): array {
    $variety = trim((string) $variety);

    $groups = [];
    foreach (get_stocks() as $stock) {
        if ($stock['status'] !== 'available') {
            continue;
        }
        if ($variety !== '' && $stock['variety'] !== $variety) {
            continue;
        }
        $key = $stock['variety'] . '|' . $stock['source'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'variety' => $stock['variety'], 'source' => $stock['source'],
                'listingCount' => 0, 'totalQuantityKg' => 0.0, 'availableQuantityKg' => 0.0, 'total' => 0.0,
            ];
        }
        $groups[$key]['listingCount']++;
        $groups[$key]['totalQuantityKg'] += (float) ($stock['baseQuantityKg'] ?? unit_to_kg((float) $stock['quantity'], $stock['unit']));
        $groups[$key]['availableQuantityKg'] += unit_to_kg(stock_available_quantity($stock), $stock['unit']);
        $groups[$key]['total'] += price_per_kg((float) $stock['unitPrice'], $stock['unit']);
    }
    $rows = array_values($groups);
    foreach ($rows as &$row) {
        $row['avgPricePerKg'] = $row['listingCount'] > 0 ? $row['total'] / $row['listingCount'] : 0;
        unset($row['total']);
    }
    usort($rows, fn($a, $b) => $a['variety'] <=> $b['variety'] ?: $a['source'] <=> $b['source']);
    return $rows;
}
/**
 * System- (or user-) wide supply breakdown for the Transparency module.
 * Section 8 requires distinguishing Total Physical, Available, Reserved,
 * In Transit, and Received rather than a single "quantity" figure — this
 * is the one place all five are computed together so Supply Overview,
 * Availability, and the dashboard can't drift apart on the definitions.
 * All figures are in kg (baseQuantityKg) so they're comparable across
 * mixed-unit listings — see unit_to_kg().
 */
function get_supply_overview_stats(?string $userId = null): array {
    $baseStocks = $userId !== null ? get_user_stocks($userId) : get_stocks();
    $availableStocks = array_values(array_filter($baseStocks, fn($s) => $s['status'] === 'available'));
    $activeStocks = array_values(array_filter($baseStocks, fn($s) => $s['status'] !== 'archived'));

    $localQuantity = 0.0;
    $importedQuantity = 0.0;
    $varieties = [];
    foreach ($availableStocks as $stock) {
        $varieties[$stock['variety']] = true;
        $kg = (float) ($stock['baseQuantityKg'] ?? unit_to_kg((float) $stock['quantity'], $stock['unit']));
        if ($stock['source'] === 'imported') {
            $importedQuantity += $kg;
        } else {
            $localQuantity += $kg;
        }
    }

    // Total Physical: every unit of rice that physically exists in the
    // system right now, regardless of whether it's sellable, committed to
    // an order, or already sold out (archived listings are excluded — an
    // archived listing represents rice the owner has explicitly taken off
    // the books, e.g. after a full sale recorded outside the platform).
    $totalPhysicalKg = array_sum(array_map(fn($s) => (float) ($s['baseQuantityKg'] ?? unit_to_kg((float) $s['quantity'], $s['unit'])), $activeStocks));
    // Reserved: committed to an accepted-but-not-yet-shipped order (Phase
    // 2) — still physically present, just not sellable to anyone else.
    $reservedKg = array_sum(array_map(fn($s) => (float) ($s['reservedQuantityKg'] ?? 0), $activeStocks));
    // Available: what's left to sell/request right now.
    $availableKg = max(0.0, $totalPhysicalKg - $reservedKg);

    $orders = $userId !== null ? get_user_procurement_orders($userId) : get_procurement_orders();
    $orderStatusCounts = ['ordered' => 0, 'in_transit' => 0, 'delivered' => 0, 'completed' => 0, 'disputed' => 0, 'cancelled' => 0];
    // In Transit: quantity that has physically left the seller (dispatched)
    // but hasn't been confirmed received yet — i.e. currently "between"
    // two parties' inventories, visible to neither as available stock.
    $inTransitKg = 0.0;
    // Received: quantity that has completed the full pipeline and landed
    // in the buyer's own inventory as a new stock listing.
    $receivedKg = 0.0;
    foreach ($orders as $order) {
        if (isset($orderStatusCounts[$order['status']])) {
            $orderStatusCounts[$order['status']]++;
        }
        if (in_array($order['status'], ['in_transit', 'delivered'], true)) {
            $inTransitKg += unit_to_kg((float) ($order['quantityShipped'] ?? $order['quantity']), $order['unit']);
        }
        if ($order['quantityReceived'] !== null && in_array($order['inspectionStatus'] ?? null, ['accepted', 'conditional'], true)) {
            $receivedKg += unit_to_kg((float) $order['quantityReceived'], $order['unit']);
        }
    }

    return [
        'totalListings' => count($availableStocks),
        'totalVarieties' => count($varieties),
        'localQuantity' => $localQuantity,
        'importedQuantity' => $importedQuantity,
        'orderStatusCounts' => $orderStatusCounts,
        'totalPhysicalKg' => $totalPhysicalKg,
        'reservedKg' => $reservedKg,
        'availableKg' => $availableKg,
        'inTransitKg' => $inTransitKg,
        'receivedKg' => $receivedKg,
    ];
}


// ---------------------------------------------------------------------
// Warehouse module (Warehouses / Capacity / Stock Placement / Transfers)
// ---------------------------------------------------------------------

function get_user_warehouses(string $userId): array {
    if (database_available()) {
        return query_all('SELECT * FROM warehouses WHERE userId = ? ORDER BY createdAt DESC', [$userId]);
    }

    init_data();
    return array_values(array_filter($_SESSION['data']['warehouses'] ?? [], fn($w) => $w['userId'] === $userId));
}

function get_warehouse_by_id(string $id): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM warehouses WHERE id = ?', [$id]);
    }

    init_data();
    foreach ($_SESSION['data']['warehouses'] ?? [] as $w) {
        if ($w['id'] === $id) {
            return $w;
        }
    }
    return null;
}

function create_warehouse(array $data): array {
    $warehouse = array_merge($data, [
        'id' => 'wh_' . time() . '_' . bin2hex(random_bytes(4)),
        'status' => 'active',
        'createdAt' => date('Y-m-d H:i:s'),
        'updatedAt' => date('Y-m-d H:i:s'),
    ]);

    if (database_available()) {
        query_execute(
            'INSERT INTO warehouses (id, userId, name, location, capacity, status, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $warehouse['id'], $warehouse['userId'], $warehouse['name'], $warehouse['location'],
                $warehouse['capacity'], $warehouse['status'], $warehouse['createdAt'], $warehouse['updatedAt'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['warehouses'][] = $warehouse;
    }

    log_activity($warehouse['userId'], 'warehouse.create', 'warehouse', $warehouse['id'], $warehouse['name']);

    return $warehouse;
}

/**
 * Update a warehouse's editable fields. Callers are responsible for
 * checking ownership before calling this.
 */
function update_warehouse(string $id, array $fields): void {
    $allowed = ['name', 'location', 'capacity', 'status'];
    $fields = array_intersect_key($fields, array_flip($allowed));
    if (empty($fields)) {
        return;
    }
    $fields['updatedAt'] = date('Y-m-d H:i:s');

    if (database_available()) {
        $setSql = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($fields)));
        query_execute("UPDATE warehouses SET $setSql WHERE id = ?", [...array_values($fields), $id]);
    } else {
        init_data();
        foreach ($_SESSION['data']['warehouses'] as &$w) {
            if ($w['id'] === $id) {
                $w = array_merge($w, $fields);
                break;
            }
        }
    }
}

/**
 * Split part of a stock lot off into its own row and place that portion
 * into a (possibly different) warehouse, leaving the rest of the lot where
 * it was — Phase 2's "Warehouse quantity splitting" (Section 5's example:
 * a 1,000kg lot moved as 400kg to Warehouse A and 600kg to Warehouse B).
 * The new row shares the original's batch number on purpose (same
 * physical lot, just divided across locations — see create_stock()'s
 * $allowDuplicateBatch parameter) so it stays traceable as part of the
 * same batch in Transparency/reporting.
 */
function split_stock_across_warehouse(string $stockId, float $amount, ?string $toWarehouseId, ?string $reason = null): array {
    $stock = get_stock_by_id($stockId);
    if (!$stock) {
        return ['success' => false, 'error' => 'Stock not found.'];
    }
    if ($amount <= 0 || $amount >= (float) $stock['quantity']) {
        // Splitting off "everything" isn't a split, it's a full move —
        // callers should use place_stock_in_warehouse() for that instead
        // (this function assumes some quantity remains behind).
        return ['success' => false, 'error' => 'Split quantity must be greater than zero and less than the full lot quantity.'];
    }
    if ($amount > stock_available_quantity($stock)) {
        return ['success' => false, 'error' => 'Cannot split reserved quantity — only ' . stock_available_quantity($stock) . ' ' . $stock['unit'] . ' is unreserved.'];
    }

    // Remove the split-off amount from the original lot first — same
    // "Use stock adjustments, never silent edits" rule as everywhere else
    // in this file, so the split shows up in the original lot's own
    // movement history.
    $removeResult = record_stock_movement(
        $stockId,
        'out',
        -$amount,
        ($reason ?: 'Split into a separate lot') . ' — moved to ' . ($toWarehouseId ? (get_warehouse_by_id($toWarehouseId)['name'] ?? 'another warehouse') : 'unassigned'),
        'internal_transfer'
    );
    if (!$removeResult['success']) {
        return $removeResult;
    }

    $newStock = create_stock([
        'userId' => $stock['userId'],
        'variety' => $stock['variety'],
        'quantity' => $amount,
        'unit' => $stock['unit'],
        'unitPrice' => $stock['unitPrice'],
        'source' => $stock['source'],
        'countryOfOrigin' => $stock['countryOfOrigin'] ?? '',
        'supplierName' => $stock['supplierName'] ?? '',
        'description' => $stock['description'] ?? '',
        'status' => 'available',
        'batchNumber' => $stock['batchNumber'],
    ], true); // allowDuplicateBatch: same lot, same owner, split across locations

    log_activity(current_user_id() ?? $stock['userId'], 'stock.split', 'stock', $newStock['id'], $amount . ' ' . $stock['unit'] . ' split from ' . $stockId . ' (batch ' . ($stock['batchNumber'] ?? 'n/a') . ')');

    // Reuses place_stock_in_warehouse()'s own capacity check against the
    // new row's quantity ($amount) — so a split into an over-capacity
    // warehouse is blocked exactly like any other placement would be.
    $placement = place_stock_in_warehouse($newStock['id'], $toWarehouseId, $reason);
    if (!$placement['success']) {
        // Roll back: put the split-off amount back on the original lot
        // rather than leaving it stranded on an unplaceable orphan row.
        record_stock_movement($stockId, 'in', $amount, 'Split cancelled — capacity check failed on destination', 'internal_transfer');
        archive_stock($newStock['id']);
        return $placement;
    }

    return ['success' => true, 'originalStock' => get_stock_by_id($stockId), 'newStock' => get_stock_by_id($newStock['id'])];
}
function get_warehouse_capacity_used(string $warehouseId): float {
    if (database_available()) {
        $row = query_one(
            "SELECT COALESCE(SUM(baseQuantityKg), 0) AS total FROM stocks WHERE warehouseId = ? AND status != 'archived'",
            [$warehouseId]
        );
        return (float) ($row['total'] ?? 0);
    }

    init_data();
    $total = 0.0;
    foreach ($_SESSION['data']['stocks'] as $stock) {
        if (($stock['warehouseId'] ?? null) === $warehouseId && $stock['status'] !== 'archived') {
            $total += (float) ($stock['baseQuantityKg'] ?? unit_to_kg((float) $stock['quantity'], $stock['unit'] ?? 'kg'));
        }
    }
    return $total;
}

/**
 * Move a stock listing into a warehouse (or out of one, if $toWarehouseId
 * is null). Writes a stock_movements row of type 'transfer' — quantity
 * doesn't change, only where the stock sits — and updates stocks.warehouseId
 * to match. Callers are responsible for checking ownership of both the
 * stock and the destination warehouse before calling this.
 */
function place_stock_in_warehouse(string $stockId, ?string $toWarehouseId, ?string $reason = null): array {
    $stock = get_stock_by_id($stockId);
    if (!$stock) {
        return ['success' => false, 'error' => 'Stock not found.'];
    }

    $fromWarehouseId = $stock['warehouseId'] ?? null;
    if ($fromWarehouseId === $toWarehouseId) {
        return ['success' => false, 'error' => 'Stock is already there.'];
    }

    $qty = (float) $stock['quantity'];
    $qtyKg = (float) ($stock['baseQuantityKg'] ?? unit_to_kg($qty, $stock['unit'] ?? 'kg'));

    // Warehouses display a capacity and a used% (including an "over
    // capacity" badge on the Warehouses list), but nothing previously
    // stopped a placement from exceeding it — the badge only ever showed
    // up after the fact. Enforce it here, the single choke point every
    // placement/transfer goes through. Capacity and usage are both kg, so
    // the listing's own quantity is converted to kg before comparing.
    //
    // BUGFIX: this used to read get_warehouse_capacity_used(), check it in
    // PHP, then write afterward with nothing locked in between — the same
    // read-then-write race reserve_stock_quantity() had. Two concurrent
    // placements into the same near-full warehouse could both read the
    // same "used" total and both pass. Locking the destination warehouse's
    // own row for the rest of this transaction serializes concurrent
    // placements targeting it, so the second one to actually run sees the
    // first one's effect before its own check runs.
    $locked = false;
    if ($toWarehouseId !== null && database_available()) {
        begin_transaction();
        $locked = true;
        db()->prepare('SELECT id FROM warehouses WHERE id = ? FOR UPDATE')->execute([$toWarehouseId]);
    }

    if ($toWarehouseId !== null) {
        $destination = get_warehouse_by_id($toWarehouseId);
        if ($destination && (float) $destination['capacity'] > 0) {
            $usedExcludingThisStock = get_warehouse_capacity_used($toWarehouseId);
            if ($usedExcludingThisStock + $qtyKg > (float) $destination['capacity']) {
                $remaining = max(0, (float) $destination['capacity'] - $usedExcludingThisStock);
                if ($locked) {
                    rollback_transaction();
                }
                return ['success' => false, 'error' => "This would put {$destination['name']} over capacity — only {$remaining} kg of capacity remains, but this listing needs {$qtyKg} kg."];
            }
        }
    }

    $movement = [
        'id' => 'move_' . time() . '_' . bin2hex(random_bytes(4)),
        'stockId' => $stockId,
        'userId' => current_user_id() ?? $stock['userId'],
        'type' => 'transfer',
        'quantityChange' => 0,
        'quantityChangeKg' => 0,
        'quantityBefore' => $qty,
        'quantityBeforeKg' => $qtyKg,
        'quantityAfter' => $qty,
        'quantityAfterKg' => $qtyKg,
        'reason' => $reason,
        'batchNumber' => $stock['batchNumber'] ?? null,
        'fromWarehouseId' => $fromWarehouseId,
        'toWarehouseId' => $toWarehouseId,
        'createdAt' => date('Y-m-d H:i:s'),
    ];

    if (database_available()) {
        query_execute(
            'INSERT INTO stock_movements (id, stockId, userId, type, quantityChange, quantityChangeKg, quantityBefore, quantityBeforeKg, quantityAfter, quantityAfterKg, reason, batchNumber, fromWarehouseId, toWarehouseId, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $movement['id'], $movement['stockId'], $movement['userId'], $movement['type'],
                $movement['quantityChange'], $movement['quantityChangeKg'],
                $movement['quantityBefore'], $movement['quantityBeforeKg'],
                $movement['quantityAfter'], $movement['quantityAfterKg'],
                $movement['reason'], $movement['batchNumber'], $movement['fromWarehouseId'], $movement['toWarehouseId'], $movement['createdAt'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['stock_movements'][] = $movement;
    }

    update_stock($stockId, ['warehouseId' => $toWarehouseId]);
    if ($locked) {
        commit_transaction();
    }
    log_activity($movement['userId'], 'stock.transfer', 'stock', $stockId, $reason ?: 'Warehouse placement changed');

    // Only the destination warehouse needs checking — the *from* warehouse's
    // usage only went down.
    maybe_notify_warehouse_capacity($toWarehouseId);

    return ['success' => true, 'stock' => get_stock_by_id($stockId)];
}

/**
 * All warehouse transfers across a user's own stock, most recent first.
 * Backs the Warehouse Transfers view.
 */
function get_user_warehouse_transfers(string $userId): array {
    if (database_available()) {
        return query_all(
            "SELECT m.* FROM stock_movements m INNER JOIN stocks s ON s.id = m.stockId WHERE s.userId = ? AND m.type = 'transfer' ORDER BY m.createdAt DESC",
            [$userId]
        );
    }

    $rows = array_values(array_filter(get_user_stock_movements($userId), fn($m) => $m['type'] === 'transfer'));
    return $rows;
}

// ---------------------------------------------------------------------

/**
 * Soft-delete a listing: keeps the row (so past procurement requests still
 * resolve to a real stock via stockId) but flips status to 'archived' so it
 * drops out of the marketplace and the owner's active-listing views. Callers
 * are responsible for checking ownership before calling this.
 */
function archive_stock(string $id): void {
    update_stock($id, ['status' => 'archived']);
    log_activity(current_user_id(), 'stock.archive', 'stock', $id);
}

function get_requests(): array {
    if (database_available()) {
        return query_all('SELECT * FROM requests ORDER BY createdAt DESC');
    }

    init_data();
    return $_SESSION['data']['requests'];
}

function get_user_requests(string $userId, string $type = 'received'): array {
    if (database_available()) {
        if ($type === 'sent') {
            return query_all('SELECT * FROM requests WHERE requesterId = ? ORDER BY createdAt DESC', [$userId]);
        }
        return query_all('SELECT * FROM requests WHERE supplierId = ? ORDER BY createdAt DESC', [$userId]);
    }

    return array_values(array_filter(get_requests(), fn($request) => $type === 'sent' ? $request['requesterId'] === $userId : $request['supplierId'] === $userId));
}

function create_request(array $data): array {
    $request = array_merge($data, [
        'id' => 'req_' . time() . '_' . bin2hex(random_bytes(4)),
        'createdAt' => date('Y-m-d H:i:s'),
        'updatedAt' => date('Y-m-d H:i:s'),
    ]);

    if (database_available()) {
        query_execute(
            'INSERT INTO requests (id, stockId, requesterId, supplierId, variety, quantityRequested, unit, notes, status, createdAt, updatedAt, requesterName, supplierName) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $request['id'],
                $request['stockId'],
                $request['requesterId'],
                $request['supplierId'],
                $request['variety'],
                $request['quantityRequested'],
                $request['unit'],
                $request['notes'] ?? '',
                $request['status'],
                $request['createdAt'],
                $request['updatedAt'],
                $request['requesterName'],
                $request['supplierName'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['requests'][] = $request;
    }

    log_activity($request['requesterId'], 'request.create', 'request', $request['id'], $request['quantityRequested'] . ' ' . $request['unit'] . ' of ' . $request['variety']);

    return $request;
}

/**
 * Atomically reduce a stock's quantity when a procurement request against it
 * is accepted. Returns ['success' => true, 'stock' => array] on success, or
 * ['success' => false, 'error' => string] if there isn't enough quantity left
 * (e.g. two requests against the same stock were accepted close together).
 */
/**
 * Physically remove `$amount` from a stock listing — the real, atomic
 * stock-out. Used both for direct decrements and, since Phase 2, as the
 * dispatch-time counterpart to reserve_stock_quantity(): pass
 * $procurementOrderId so the movement is traceable to the order and the
 * matching reservation is released in the same call (not left orphaned,
 * pointing at goods that already left the building).
 */
function decrement_stock_quantity(string $stockId, float $amount, ?string $reason = null, ?string $reasonCategory = null, ?string $procurementOrderId = null): array {
    if (database_available()) {
        // The `quantity >= ?` guard makes this check-and-decrement atomic at
        // the database level, so two concurrent accepts can't both succeed
        // against a stock that only has enough for one of them. We check
        // rowCount() (not just execute()'s boolean) because a 0-row UPDATE
        // still "succeeds" as far as PDO is concerned.
        $before = get_stock_by_id($stockId);
        $unit = $before['unit'] ?? 'kg';
        $changeKg = unit_to_kg($amount, $unit);
        $stmt = db()->prepare('UPDATE stocks SET quantity = quantity - ?, baseQuantityKg = baseQuantityKg - ?, updatedAt = ? WHERE id = ? AND quantity >= ?');
        $stmt->execute([$amount, $changeKg, date('Y-m-d H:i:s'), $stockId, $amount]);

        if ($stmt->rowCount() === 0) {
            $stock = get_stock_by_id($stockId);
            $available = $stock['quantity'] ?? 0;
            return ['success' => false, 'error' => "Only {$available} available, but {$amount} was requested."];
        }

        // Flip to sold_out once the listing hits zero so it drops out of
        // the marketplace (which only shows status = 'available') instead
        // of staying listed and biteable with nothing left to fulfill it.
        $updated = get_stock_by_id($stockId);
        if ($updated && (float) $updated['quantity'] <= 0 && $updated['status'] === 'available') {
            query_execute('UPDATE stocks SET status = ? WHERE id = ?', ['sold_out', $stockId]);
            $updated['status'] = 'sold_out';
        }
        maybe_notify_stock_threshold($updated);
        record_movement_only($stockId, 'out', -$amount, (float) ($before['quantity'] ?? $amount), (float) $updated['quantity'], $reason, $before['batchNumber'] ?? null, $before['unit'] ?? 'kg', $reasonCategory, $procurementOrderId);
        if ($procurementOrderId !== null) {
            release_stock_reservation($stockId, $amount);
            $updated = get_stock_by_id($stockId);
        }
        return ['success' => true, 'stock' => $updated];
    }

    init_data();
    foreach ($_SESSION['data']['stocks'] as &$stock) {
        if ($stock['id'] === $stockId) {
            if ($stock['quantity'] < $amount) {
                return ['success' => false, 'error' => "Only {$stock['quantity']} available, but {$amount} was requested."];
            }
            $before = (float) $stock['quantity'];
            $stock['quantity'] -= $amount;
            $stock['baseQuantityKg'] = unit_to_kg((float) $stock['quantity'], $stock['unit'] ?? 'kg');
            $stock['updatedAt'] = date('c');
            if ((float) $stock['quantity'] <= 0 && $stock['status'] === 'available') {
                $stock['status'] = 'sold_out';
            }
            maybe_notify_stock_threshold($stock);
            record_movement_only($stockId, 'out', -$amount, $before, (float) $stock['quantity'], $reason, $stock['batchNumber'] ?? null, $stock['unit'] ?? 'kg', $reasonCategory, $procurementOrderId);
            $result = ['success' => true, 'stock' => $stock];
            if ($procurementOrderId !== null) {
                release_stock_reservation($stockId, $amount);
                $result['stock'] = get_stock_by_id($stockId);
            }
            return $result;
        }
    }
    return ['success' => false, 'error' => 'Stock not found.'];
}

/**
 * Write a stock_movements row without re-touching stocks.quantity — for
 * callers like decrement_stock_quantity() that already applied the
 * quantity change themselves (often with their own atomicity guard) and
 * just need the movement logged for Inventory History / traceability.
 */
function record_movement_only(string $stockId, string $type, float $change, float $before, float $after, ?string $reason, ?string $batchNumber, string $unit = 'kg', ?string $reasonCategory = null, ?string $procurementOrderId = null): void {
    $movement = [
        'id' => 'move_' . time() . '_' . bin2hex(random_bytes(4)),
        'stockId' => $stockId,
        'userId' => current_user_id() ?? '',
        'type' => $type,
        'quantityChange' => $change,
        'quantityChangeKg' => unit_to_kg($change, $unit),
        'quantityBefore' => $before,
        'quantityBeforeKg' => unit_to_kg($before, $unit),
        'quantityAfter' => $after,
        'quantityAfterKg' => unit_to_kg($after, $unit),
        'reason' => $reason,
        'reasonCategory' => $reasonCategory,
        'procurementOrderId' => $procurementOrderId,
        'batchNumber' => $batchNumber,
        'fromWarehouseId' => null,
        'toWarehouseId' => null,
        'createdAt' => date('Y-m-d H:i:s'),
    ];

    if (database_available()) {
        query_execute(
            'INSERT INTO stock_movements (id, stockId, userId, type, quantityChange, quantityChangeKg, quantityBefore, quantityBeforeKg, quantityAfter, quantityAfterKg, reason, reasonCategory, procurementOrderId, batchNumber, fromWarehouseId, toWarehouseId, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $movement['id'], $movement['stockId'], $movement['userId'], $movement['type'],
                $movement['quantityChange'], $movement['quantityChangeKg'],
                $movement['quantityBefore'], $movement['quantityBeforeKg'],
                $movement['quantityAfter'], $movement['quantityAfterKg'],
                $movement['reason'], $movement['reasonCategory'], $movement['procurementOrderId'],
                $movement['batchNumber'], $movement['fromWarehouseId'], $movement['toWarehouseId'], $movement['createdAt'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['stock_movements'][] = $movement;
    }
}

/**
 * Update a request's status. Pass $expectedCurrentStatus (e.g. 'pending')
 * to make the transition atomic and guard against double-processing: two
 * near-simultaneous Accept submits on the same request could otherwise both
 * read status='pending' before either write lands, both reserve stock, and
 * both create a procurement order. With the guard, the UPDATE's WHERE
 * clause re-checks the current status at write time — only the first one
 * to land wins, and this returns null (instead of the updated row) for
 * every caller that loses the race, so they can back out cleanly instead
 * of proceeding as if their transition succeeded.
 */
function update_request_status(string $id, string $status, ?string $expectedCurrentStatus = null): ?array {
    if (database_available()) {
        if ($expectedCurrentStatus !== null) {
            $stmt = db()->prepare('UPDATE requests SET status = ?, updatedAt = ? WHERE id = ? AND status = ?');
            $stmt->execute([$status, date('Y-m-d H:i:s'), $id, $expectedCurrentStatus]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            return get_request_by_id($id);
        }
        query_execute('UPDATE requests SET status = ?, updatedAt = ? WHERE id = ?', [
            $status,
            date('Y-m-d H:i:s'),
            $id,
        ]);
        return get_request_by_id($id);
    }

    init_data();
    foreach ($_SESSION['data']['requests'] as &$request) {
        if ($request['id'] === $id) {
            if ($expectedCurrentStatus !== null && $request['status'] !== $expectedCurrentStatus) {
                return null;
            }
            $request['status'] = $status;
            $request['updatedAt'] = date('c');
            return $request;
        }
    }
    return null;
}

// ---------------------------------------------------------------------
// Procurement Orders (created once a request is accepted) + Tracking
// ---------------------------------------------------------------------

// Fixed forward-only pipeline a procurement order moves through.
// Procurement Tracking renders this as a timeline over `status` (see
// render_tracking_steps() in procurement/tracking/index.php) — 'disputed'
// and 'cancelled' are side branches off 'delivered'/'ordered'/'in_transit'
// respectively, not further stages in this array.
const PROCUREMENT_ORDER_STATUSES = ['ordered', 'in_transit', 'delivered', 'completed'];

/**
 * The status that follows the given one in the pipeline, or null if it's
 * already the last stage (or not a recognized stage).
 *
 * NOTE: since Phase 2, procurement/tracking/index.php no longer uses this
 * for a single generic "advance" action — dispatch, mark-delivered, and
 * inspect are each their own explicit step because they each need
 * different inputs (quantity shipped, quantity received + inspection
 * outcome, etc.) and reach different next statuses (delivered can go to
 * either 'completed' or 'disputed'). Kept for render_tracking_steps()'s
 * "is this step done yet" comparisons and any future caller that only
 * needs the plain linear order.
 */
function next_procurement_order_status(string $status): ?string {
    $index = array_search($status, PROCUREMENT_ORDER_STATUSES, true);
    if ($index === false || $index === count(PROCUREMENT_ORDER_STATUSES) - 1) {
        return null;
    }
    return PROCUREMENT_ORDER_STATUSES[$index + 1];
}

/**
 * Create the confirmed-order record for a request that was just accepted.
 * Carries a copy of the request's core details so the order stays readable
 * even if the originating request record changes later. Wrapped in a
 * transaction to ensure all updates (order insert, status change, audit log)
 * succeed or fail atomically.
 */
function create_procurement_order(array $request): array {
    // Snapshot the batch/lot number from the source stock at the moment the
    // order is created, same rationale as requesterName/supplierName above:
    // the order stays traceable to the batch it actually came from even if
    // that stock listing is later restocked under a new batch, or archived.
    $sourceStock = get_stock_by_id($request['stockId']);

    // Snapshot the price too, for the same reason: unitPrice/totalPrice are
    // frozen here, at order-creation time. If the supplier later changes
    // the marketplace listing's price (or the listing is deleted), this
    // order still shows exactly what was agreed/paid — previously
    // procurement_orders recorded no price at all, so there was no way to
    // answer "how much was this order actually worth" without cross
    // referencing a stock row that might no longer reflect it.
    // Cart/checkout requests carry the price the buyer saw and agreed to; honour it so
    // the buyer pays what they were shown, even if the listing's price changed meanwhile.
    $unitPrice = (float) ($request['unitPrice'] ?? $sourceStock['unitPrice'] ?? 0);
    $quantity = (float) $request['quantityRequested'];
    $totalPrice = round($unitPrice * $quantity, 2);

    $order = [
        'id' => 'order_' . time() . '_' . bin2hex(random_bytes(4)),
        'requestId' => $request['id'],
        'stockId' => $request['stockId'],
        'batchNumber' => $sourceStock['batchNumber'] ?? null,
        'requesterId' => $request['requesterId'],
        'supplierId' => $request['supplierId'],
        'requesterName' => $request['requesterName'] ?? '',
        'supplierName' => $request['supplierName'] ?? '',
        'variety' => $request['variety'],
        'quantity' => $request['quantityRequested'],
        'unit' => $request['unit'],
        'unitPrice' => $unitPrice,
        'totalPrice' => $totalPrice,
        'status' => 'ordered',
        'createdAt' => date('Y-m-d H:i:s'),
        'updatedAt' => date('Y-m-d H:i:s'),
    ];

    try {
        begin_transaction();

        if (database_available()) {
            query_execute(
                'INSERT INTO procurement_orders (id, requestId, stockId, batchNumber, requesterId, supplierId, requesterName, supplierName, variety, quantity, unit, unitPrice, totalPrice, status, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $order['id'], $order['requestId'], $order['stockId'], $order['batchNumber'], $order['requesterId'], $order['supplierId'],
                    $order['requesterName'], $order['supplierName'], $order['variety'], $order['quantity'], $order['unit'],
                    $order['unitPrice'], $order['totalPrice'],
                    $order['status'], $order['createdAt'], $order['updatedAt'],
                ]
            );
        } else {
            init_data();
            $_SESSION['data']['procurement_orders'][] = $order;
        }

        log_activity($order['supplierId'], 'order.create', 'procurement_order', $order['id'], $order['quantity'] . ' ' . $order['unit'] . ' of ' . $order['variety'] . ' (₱' . number_format($totalPrice, 2) . ')');

        commit_transaction();
    } catch (Exception $e) {
        rollback_transaction();
        error_log('Failed to create procurement order: ' . $e->getMessage());
        throw $e;
    }

    return $order;
}

/**
 * Create the requester's own stock listing once their procurement order
 * has been delivered — the one place new stock enters an account without
 * the owner manually adding it. Quantity/unit/batchNumber come from the
 * order; unitPrice/source/countryOfOrigin are copied from the original
 * stock so, e.g., purchased imported rice is still reported as imported
 * rather than invented as a third source bucket. Idempotent via
 * procurement_orders.receivedStockId.
 */
/**
 * Create the buyer's own stock listing for what they actually received —
 * called once the buyer completes Receiving/Inspection with an 'accepted'
 * or 'conditional' outcome (see procurement/tracking/index.php). Takes an
 * explicit $receivedQuantity rather than assuming the full ordered amount,
 * since Phase 2 allows partial fulfillment (short-shipped, or a
 * conditional accept of only part of a damaged delivery) — the buyer's
 * inventory should reflect what physically arrived and passed inspection,
 * not what was merely ordered. Wrapped in a transaction to ensure the
 * new stock, order update, audit log, and notification all complete
 * atomically.
 */
function receive_procurement_order_stock(array $order, ?float $receivedQuantity = null): ?array {
    if (!empty($order['receivedStockId'])) {
        return get_stock_by_id($order['receivedStockId']);
    }

    $quantity = $receivedQuantity ?? (float) ($order['quantityReceived'] ?? $order['quantity']);
    if ($quantity <= 0) {
        return null;
    }

    $sourceStock = get_stock_by_id($order['stockId']);

    try {
        begin_transaction();

        try {
            $newStock = create_stock([
                'userId' => $order['requesterId'],
                'variety' => $order['variety'],
                'quantity' => $quantity,
                'unit' => $order['unit'],
                'unitPrice' => $sourceStock['unitPrice'] ?? 0,
                'source' => $sourceStock['source'] ?? 'local',
                'countryOfOrigin' => $sourceStock['countryOfOrigin'] ?? '',
                'description' => 'Received via procurement order ' . $order['id'] . ' from ' . ($order['supplierName'] ?? 'supplier'),
                'status' => 'available',
                'batchNumber' => $order['batchNumber'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            // Extremely unlikely (the buyer would have to already own a
            // listing under this exact batch number), but automatic receipt
            // must never hard-fail here — fall back to a fresh generated
            // number rather than blocking the delivery from being recorded.
            $newStock = create_stock([
                'userId' => $order['requesterId'],
                'variety' => $order['variety'],
                'quantity' => $quantity,
                'unit' => $order['unit'],
                'unitPrice' => $sourceStock['unitPrice'] ?? 0,
                'source' => $sourceStock['source'] ?? 'local',
                'countryOfOrigin' => $sourceStock['countryOfOrigin'] ?? '',
                'description' => 'Received via procurement order ' . $order['id'] . ' from ' . ($order['supplierName'] ?? 'supplier') . ' (original batch: ' . ($order['batchNumber'] ?? 'n/a') . ')',
                'status' => 'available',
                'batchNumber' => null,
            ]);
        }

        if (database_available()) {
            query_execute('UPDATE procurement_orders SET receivedStockId = ? WHERE id = ?', [$newStock['id'], $order['id']]);
        } else {
            init_data();
            foreach ($_SESSION['data']['procurement_orders'] as &$o) {
                if ($o['id'] === $order['id']) {
                    $o['receivedStockId'] = $newStock['id'];
                    break;
                }
            }
        }

        log_activity(
            $order['requesterId'],
            'order.received',
            'stock',
            $newStock['id'],
            $quantity . ' ' . $order['unit'] . ' of ' . $order['variety'] . ' received into inventory'
        );

        create_notification([
            'userId' => $order['requesterId'],
            'type' => 'order_received',
            'title' => 'Stock received',
            'message' => 'Your delivered order for ' . $quantity . ' ' . $order['unit'] . ' of ' . $order['variety'] . ' is now in your inventory — place it in a warehouse when ready.',
            'relatedId' => $newStock['id'],
        ]);

        commit_transaction();
        return $newStock;
    } catch (Exception $e) {
        rollback_transaction();
        error_log('Failed to receive procurement order stock: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Human-readable status for an order, folding in partial fulfillment: an
 * order that's 'completed' but received less than it shipped (short
 * delivery, or a conditional partial accept) should read "Partially
 * Fulfilled" rather than a plain "Completed" that implies the full
 * quantity arrived (Section 6.2's example: ordered 1,000kg, received
 * 800kg -> "Partially Fulfilled", not an all-or-nothing status).
 */
function procurement_order_display_status(array $order): string {
    if ($order['status'] === 'completed' && $order['quantityReceived'] !== null && (float) $order['quantityReceived'] < (float) $order['quantity']) {
        return 'partially_fulfilled';
    }
    return $order['status'];
}

function get_procurement_orders(): array {
    if (database_available()) {
        return query_all('SELECT * FROM procurement_orders ORDER BY createdAt DESC');
    }

    init_data();
    $rows = $_SESSION['data']['procurement_orders'];
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $rows;
}

/**
 * Orders a user is party to, either as the buyer (requester) or the seller
 * (supplier) — unlike Requests, an order belongs to both sides equally once
 * it exists, so there's a single combined view rather than sent/received.
 */
function get_user_procurement_orders(string $userId): array {
    if (database_available()) {
        return query_all(
            'SELECT * FROM procurement_orders WHERE requesterId = ? OR supplierId = ? ORDER BY createdAt DESC',
            [$userId, $userId]
        );
    }

    return array_values(array_filter(
        get_procurement_orders(),
        fn($order) => $order['requesterId'] === $userId || $order['supplierId'] === $userId
    ));
}

function get_procurement_order_by_id(string $id): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM procurement_orders WHERE id = ?', [$id]);
    }

    foreach (get_procurement_orders() as $order) {
        if ($order['id'] === $id) {
            return $order;
        }
    }
    return null;
}

function get_procurement_order_by_request_id(string $requestId): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM procurement_orders WHERE requestId = ?', [$requestId]);
    }

    foreach (get_procurement_orders() as $order) {
        if ($order['requestId'] === $requestId) {
            return $order;
        }
    }
    return null;
}

/**
 * Advance an order to the given status. Only moves forward one step at a
 * time along PROCUREMENT_ORDER_STATUSES — callers should pass the result of
 * next_procurement_order_status() rather than an arbitrary stage, so an
 * order can't skip a step or move backwards.
 */
function update_procurement_order_status(string $id, string $status, ?string $expectedCurrentStatus = null): ?array {
    if (database_available()) {
        // With $expectedCurrentStatus the change only applies if the order is
        // STILL in that status (compare-and-set). Returns null when it lost the
        // race, e.g. the seller dispatched while a cancel was running.
        if ($expectedCurrentStatus !== null) {
            $stmt = db()->prepare('UPDATE procurement_orders SET status = ?, updatedAt = ? WHERE id = ? AND status = ?');
            $stmt->execute([$status, date('Y-m-d H:i:s'), $id, $expectedCurrentStatus]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            return get_procurement_order_by_id($id);
        }
        query_execute('UPDATE procurement_orders SET status = ?, updatedAt = ? WHERE id = ?', [
            $status,
            date('Y-m-d H:i:s'),
            $id,
        ]);
        return get_procurement_order_by_id($id);
    }

    init_data();
    foreach ($_SESSION['data']['procurement_orders'] as &$order) {
        if ($order['id'] === $id) {
            $order['status'] = $status;
            $order['updatedAt'] = date('c');
            return $order;
        }
    }
    return null;
}

/**
 * Write shipment/delivery/receiving-inspection fields onto an order in one
 * go (Phase 2: dispatch, delivery, and receiving each set a different
 * subset of these). $fields keys are restricted the same way
 * update_stock() restricts its own — an allow-list, not a denylist — so a
 * caller can never accidentally overwrite requesterId/supplierId/price
 * snapshot fields through this generic path.
 */
function update_procurement_order_fields(string $id, array $fields): ?array {
    $allowed = [
        'status', 'quantityShipped', 'quantityReceived', 'expectedDeliveryDate',
        'actualDeliveryDate', 'carrier', 'trackingReference', 'proofOfDeliveryUrl',
        'inspectionStatus', 'inspectionNotes',
    ];
    $fields = array_intersect_key($fields, array_flip($allowed));
    if (empty($fields)) {
        return get_procurement_order_by_id($id);
    }
    $fields['updatedAt'] = date('Y-m-d H:i:s');

    if (database_available()) {
        $setSql = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($fields)));
        query_execute("UPDATE procurement_orders SET $setSql WHERE id = ?", [...array_values($fields), $id]);
        return get_procurement_order_by_id($id);
    }

    init_data();
    foreach ($_SESSION['data']['procurement_orders'] as &$order) {
        if ($order['id'] === $id) {
            $order = array_merge($order, $fields);
            return $order;
        }
    }
    return null;
}

/**
 * Cancel an order that hasn't shipped/arrived yet, and reverse the stock
 * decrement that was applied when its request was accepted — otherwise a
 * cancelled order permanently and silently costs the supplier that
 * quantity. Recorded as a normal 'in' movement against the same stock/
 * batch, so it reads correctly in Inventory History rather than as an
 * untraceable manual restock. Only reachable before 'delivered' — once
 * goods have physically arrived, cancellation isn't the right tool
 * (the order should run its course to 'completed' instead).
 */
function cancel_procurement_order(string $id, ?string $cancelledByUserId, ?string $reason = null): array {
    $order = get_procurement_order_by_id($id);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found.'];
    }
    if (!in_array($order['status'], ['ordered', 'in_transit'], true)) {
        return ['success' => false, 'error' => 'Only orders that haven\'t been delivered yet can be cancelled.'];
    }

    // What "undo" means depends on how far the order got:
    //  - still 'ordered': nothing has physically moved yet (Phase 2 accept
    //    only reserves stock — see reserve_stock_quantity()), so cancelling
    //    just releases that reservation.
    //  - 'in_transit': goods already physically left via dispatch (see the
    //    decrement_stock_quantity() call in procurement/tracking/index.php),
    //    so cancelling has to put that quantity back with a real 'in'
    //    movement, not just release a reservation that was already
    //    released at dispatch time.
    // Everything below is ONE transaction, and the status change goes first as a
    // compare-and-set: if the order moved on in the meantime (for example the
    // seller dispatched it), nothing is released or restored twice.
    // $cancelledByUserId is null when the system cancels (payment deadline cron),
    // so the activity log never gets a made-up user id that foreign keys reject.
    begin_transaction();
    try {
        if (update_procurement_order_status($order['id'], 'cancelled', $order['status']) === null) {
            rollback_transaction();
            return ['success' => false, 'error' => 'The order changed while it was being cancelled. Reload and check its status.'];
        }
        if ($order['status'] === 'ordered') {
            release_stock_reservation($order['stockId'], (float) $order['quantity']);
        } else {
            record_stock_movement(
                $order['stockId'],
                'in',
                (float) ($order['quantityShipped'] ?? $order['quantity']),
                'Returned from cancelled order ' . $order['id'] . ($reason ? (': ' . $reason) : ''),
                'procurement_order',
                $order['id']
            );
        }
        commerce_on_order_cancelled($order); // unpaid payment -> cancelled, paid -> refund due
        log_activity($cancelledByUserId, 'order.cancel', 'procurement_order', $order['id'], $reason);
        commit_transaction();
    } catch (Throwable $e) {
        rollback_transaction();
        error_log('KAMALIG cancel order ' . $order['id'] . ' failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'The order could not be cancelled. Nothing was changed.'];
    }

    $notifyUserId = $cancelledByUserId === $order['requesterId'] ? $order['supplierId'] : $order['requesterId'];
    create_notification([
        'userId' => $notifyUserId,
        'type' => 'order_status',
        'title' => 'Order cancelled',
        'message' => 'The order for ' . $order['quantity'] . ' ' . $order['unit'] . ' of ' . $order['variety'] . ' was cancelled' . ($reason ? (': ' . $reason) : '.'),
        'relatedId' => $order['id'],
    ]);

    return ['success' => true, 'order' => get_procurement_order_by_id($order['id'])];
}

/**
 * Centralized activity log — call this at every meaningful mutation
 * (stock created, request accepted/rejected, document uploaded/verified,
 * account approved) so "who did what, when" is answerable from one table
 * instead of being scattered across (or missing from) individual modules.
 */
function log_activity(?string $userId, string $action, string $entityType, ?string $entityId = null, ?string $details = null): void {
    $entry = [
        'id' => 'log_' . time() . '_' . bin2hex(random_bytes(4)),
        'userId' => $userId,
        'action' => $action,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'details' => $details,
        'createdAt' => date('Y-m-d H:i:s'),
    ];

    if (database_available()) {
        query_execute(
            'INSERT INTO activity_log (id, userId, action, entityType, entityId, details, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$entry['id'], $entry['userId'], $entry['action'], $entry['entityType'], $entry['entityId'], $entry['details'], $entry['createdAt']]
        );
        return;
    }

    init_data();
    $_SESSION['data']['activity_log'][] = $entry;
}

/**
 * Most recent activity, newest first, optionally scoped to one entity
 * (e.g. all log entries for a specific request or document).
 */
function get_activity_log(int $limit = 100, ?string $entityType = null, ?string $entityId = null, ?string $userId = null): array {
    if (database_available()) {
        // LIMIT is concatenated as a cast int (not bound as a placeholder):
        // PDO::ATTR_EMULATE_PREPARES is off for this connection, and native
        // prepared statements reject a string-bound LIMIT value — same
        // reason get_recent_price_changes() below does the same thing.
        if ($entityType !== null && $entityId !== null && $userId !== null) {
            return query_all(
                'SELECT * FROM activity_log WHERE entityType = ? AND entityId = ? AND userId = ? ORDER BY createdAt DESC LIMIT ' . (int) $limit,
                [$entityType, $entityId, $userId]
            );
        }
        if ($entityType !== null && $entityId !== null) {
            return query_all(
                'SELECT * FROM activity_log WHERE entityType = ? AND entityId = ? ORDER BY createdAt DESC LIMIT ' . (int) $limit,
                [$entityType, $entityId]
            );
        }
        if ($userId !== null) {
            return query_all(
                'SELECT * FROM activity_log WHERE userId = ? ORDER BY createdAt DESC LIMIT ' . (int) $limit,
                [$userId]
            );
        }
        return query_all('SELECT * FROM activity_log ORDER BY createdAt DESC LIMIT ' . (int) $limit);
    }

    init_data();
    $log = $_SESSION['data']['activity_log'] ?? [];
    if ($entityType !== null && $entityId !== null) {
        $log = array_filter($log, fn($e) => $e['entityType'] === $entityType && $e['entityId'] === $entityId);
    }
    if ($userId !== null) {
        $log = array_filter($log, fn($e) => ($e['userId'] ?? null) === $userId);
    }
    $log = array_reverse($log); // newest first
    return array_slice(array_values($log), 0, $limit);
}


function get_documents(string $userId): array {
    if (database_available()) {
        return query_all('SELECT * FROM documents WHERE userId = ? ORDER BY uploadedAt DESC', [$userId]);
    }

    init_data();
    return array_values(array_filter($_SESSION['data']['documents'], fn($doc) => $doc['userId'] === $userId));
}

function get_documents_for_request(string $requestId): array {
    if (database_available()) {
        return query_all('SELECT * FROM documents WHERE relatedRequestId = ? ORDER BY uploadedAt DESC', [$requestId]);
    }

    init_data();
    return array_values(array_filter($_SESSION['data']['documents'] ?? [], fn($doc) => ($doc['relatedRequestId'] ?? null) === $requestId));
}

/**
 * Identity-document types that establish who a supplier actually is —
 * as opposed to transactional paperwork like invoices or delivery
 * receipts, which don't say anything about the account's own legitimacy.
 * Used by is_verified_supplier() to decide what counts toward the
 * "Verified Supplier" badge (Section 7).
 */
const SUPPLIER_IDENTITY_DOCUMENT_TYPES = ['business_registration', 'permit', 'certificate'];

/**
 * Whether this user has at least one admin-verified identity document on
 * file — backs the "Verified Supplier" badge Section 7 calls for on every
 * Marketplace listing. Deliberately does NOT check account approval
 * status (`users.verified`/`status`) — an account can be approved to use
 * the platform (Critical Improvement 3) without ever having a document
 * verified, and those are different claims: one says "this person may use
 * KAMALIG", the other says "this specific business identity has been
 * checked".
 */
function is_verified_supplier(string $userId): bool {
    foreach (get_documents($userId) as $doc) {
        if ($doc['status'] === 'verified' && in_array($doc['documentType'], SUPPLIER_IDENTITY_DOCUMENT_TYPES, true)) {
            return true;
        }
    }
    return false;
}

/**
 * System-wide, per-variety threshold below which Transparency flags that
 * variety as running short across the whole platform — distinct from
 * low_stock_threshold(), which flags one seller's own listing regardless
 * of how much everyone else combined still has for sale.
 */
function variety_shortage_threshold_kg(): float {
    return (float) get_setting('VARIETY_SHORTAGE_THRESHOLD_KG', '500');
}

/**
 * Varieties whose total available supply, summed across every seller and
 * normalized to kg, has fallen to or below variety_shortage_threshold_kg()
 * — Phase 3's "Supply shortage alerts". This is a market-level signal (is
 * this variety becoming scarce platform-wide?) as opposed to
 * maybe_notify_stock_threshold(), which only ever looks at one listing in
 * isolation and can't see that, say, five different suppliers are all
 * individually fine but the variety as a whole is nearly sold out.
 */
function get_variety_shortage_alerts(): array {
    $threshold = variety_shortage_threshold_kg();
    $totals = [];
    foreach (get_stocks() as $stock) {
        if ($stock['status'] !== 'available') {
            continue;
        }
        $variety = $stock['variety'];
        $totals[$variety] = ($totals[$variety] ?? 0.0) + unit_to_kg(stock_available_quantity($stock), $stock['unit']);
    }
    $alerts = [];
    foreach ($totals as $variety => $availableKg) {
        if ($availableKg <= $threshold) {
            $alerts[] = ['variety' => $variety, 'availableKg' => $availableKg];
        }
    }
    usort($alerts, fn($a, $b) => $a['availableKg'] <=> $b['availableKg']);
    return $alerts;
}
function get_supplier_compliance_summary(): array {
    $sellerIds = array_unique(array_column(
        array_filter(get_stocks(), fn($s) => $s['status'] !== 'archived'),
        'userId'
    ));
    $verifiedCount = 0;
    foreach ($sellerIds as $id) {
        if (is_verified_supplier($id)) {
            $verifiedCount++;
        }
    }
    return [
        'totalSuppliers' => count($sellerIds),
        'verifiedSuppliers' => $verifiedCount,
        'unverifiedSuppliers' => count($sellerIds) - $verifiedCount,
    ];
}

function upload_document(array $data): array {
    $document = array_merge([
        'relatedRequestId' => null,
        'storedFileName' => null,
        'mimeType' => null,
        'fileSize' => null,
        'ocrRawText' => null,
        'ocrConfidence' => null,
        'ocrProcessedAt' => null,
        'ocrStatus' => 'not_processed',
        'aiFlag' => 0,
        'aiFlagReason' => null,
        'aiMatchScore' => null,
        'verifiedBy' => null,
        'verifiedAt' => null,
    ], $data, [
        // 'doc_' . time() alone collides whenever two documents are uploaded
        // in the same second (documents.id is the PRIMARY KEY, so the second
        // INSERT throws instead of just failing gracefully). Match the same
        // time()+random-suffix pattern used by every other id generator in
        // this file (stock_, move_, price_, wh_, order_, log_) for the same
        // reason: collision-resistant even under concurrent uploads.
        'id' => 'doc_' . time() . '_' . bin2hex(random_bytes(4)),
        'uploadedAt' => date('Y-m-d H:i:s'),
    ]);

    if (database_available()) {
        query_execute(
            'INSERT INTO documents (
                id, userId, relatedRequestId, documentType, fileName, storedFileName, mimeType, fileSize,
                fileUrl, status, businessName, permitNumber, taxId, expiryDate,
                ocrRawText, ocrConfidence, ocrProcessedAt, ocrStatus,
                aiFlag, aiFlagReason, aiMatchScore, verifiedBy, verifiedAt, uploadedAt
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $document['id'],
                $document['userId'],
                $document['relatedRequestId'],
                $document['documentType'],
                $document['fileName'],
                $document['storedFileName'],
                $document['mimeType'],
                $document['fileSize'],
                $document['fileUrl'],
                $document['status'],
                $document['ocrData']['businessName'] ?? $document['businessName'] ?? null,
                $document['ocrData']['permitNumber'] ?? $document['permitNumber'] ?? null,
                $document['ocrData']['taxId'] ?? $document['taxId'] ?? null,
                $document['ocrData']['expiryDate'] ?? $document['expiryDate'] ?? null,
                $document['ocrRawText'],
                $document['ocrConfidence'],
                $document['ocrProcessedAt'],
                $document['ocrStatus'],
                $document['aiFlag'],
                $document['aiFlagReason'],
                $document['aiMatchScore'],
                $document['verifiedBy'],
                $document['verifiedAt'],
                $document['uploadedAt'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['documents'][] = $document;
    }

    return $document;
}

function update_document_ocr(string $id, array $fields): void {
    // $fields may include: ocrRawText, ocrConfidence, ocrProcessedAt, ocrStatus,
    // aiFlag, aiFlagReason, aiMatchScore, businessName, permitNumber, taxId, expiryDate
    $allowed = [
        'ocrRawText', 'ocrConfidence', 'ocrProcessedAt', 'ocrStatus',
        'aiFlag', 'aiFlagReason', 'aiMatchScore',
        'businessName', 'permitNumber', 'taxId', 'expiryDate',
    ];
    $fields = array_intersect_key($fields, array_flip($allowed));
    if (empty($fields)) {
        return;
    }

    if (database_available()) {
        $setSql = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($fields)));
        query_execute("UPDATE documents SET $setSql WHERE id = ?", [...array_values($fields), $id]);
        return;
    }

    init_data();
    foreach ($_SESSION['data']['documents'] as &$doc) {
        if ($doc['id'] === $id) {
            $doc = array_merge($doc, $fields);
            break;
        }
    }
}

function verify_document(string $id, string $status, string $verifiedByUserId): void {
    // $status expected: 'verified' | 'rejected'
    $now = date('Y-m-d H:i:s');

    // Fetched before the update purely to build the notification below — the
    // owner and file/type never change here, so reading them first vs. after
    // makes no difference to correctness, just avoids a second lookup after
    // the session-storage branch mutates the row in place.
    $doc = get_document_by_id($id);

    // BUGFIX: a double-click on Verify/Reject, or the browser back button
    // plus a resubmit, re-ran this unconditionally every time — including
    // re-sending the "your document was verified/rejected" notification to
    // the submitter on a document that had already been decided. Skipping
    // once it's already in the target status makes this idempotent, the
    // same way request/order status transitions already are elsewhere.
    if ($doc && ($doc['status'] ?? null) === $status) {
        return;
    }

    if (database_available()) {
        query_execute(
            'UPDATE documents SET status = ?, verifiedBy = ?, verifiedAt = ? WHERE id = ?',
            [$status, $verifiedByUserId, $now, $id]
        );
        log_activity($verifiedByUserId, 'document.' . $status, 'document', $id);
    } else {
        init_data();
        foreach ($_SESSION['data']['documents'] as &$doc2) {
            if ($doc2['id'] === $id) {
                $doc2['status'] = $status;
                $doc2['verifiedBy'] = $verifiedByUserId;
                $doc2['verifiedAt'] = $now;
                break;
            }
        }
        log_activity($verifiedByUserId, 'document.' . $status, 'document', $id);
    }

    if (!$doc || empty($doc['userId'])) {
        return;
    }

    $label = str_replace('_', ' ', $doc['documentType'] ?? 'document');
    if ($status === 'verified') {
        $message = 'Your ' . $label . ' (' . ($doc['fileName'] ?? $id) . ') has been verified.';
    } else {
        // Give the submitter the actual reason where one exists (duplicate,
        // unregistered business name, OCR/AI mismatch) instead of a bare
        // "rejected" with no way to know what to fix before resubmitting.
        $message = 'Your ' . $label . ' (' . ($doc['fileName'] ?? $id) . ') was rejected.';
        if (!empty($doc['aiFlagReason'])) {
            $message .= ' Reason: ' . $doc['aiFlagReason'];
        }
    }

    create_notification([
        'userId' => $doc['userId'],
        'type' => 'document_status',
        'title' => 'Document ' . $status,
        'message' => $message,
        'relatedId' => $id,
    ]);
}

function create_notification(array $data): array {
    $notification = array_merge([
        'read' => 0,
        'relatedId' => null,
    ], $data, [
        'id' => 'notif_' . time() . '_' . bin2hex(random_bytes(4)),
        'createdAt' => date('Y-m-d H:i:s'),
    ]);

    if (database_available()) {
        query_execute(
            'INSERT INTO notifications (id, userId, type, title, message, `read`, createdAt, relatedId) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $notification['id'],
                $notification['userId'],
                $notification['type'],
                $notification['title'],
                $notification['message'],
                $notification['read'] ? 1 : 0,
                $notification['createdAt'],
                $notification['relatedId'],
            ]
        );
    } else {
        init_data();
        $_SESSION['data']['notifications'][] = $notification;
    }

    return $notification;
}

function get_notifications(string $userId): array {
    if (database_available()) {
        return query_all('SELECT * FROM notifications WHERE userId = ? ORDER BY createdAt DESC', [$userId]);
    }

    init_data();
    return array_values(array_filter($_SESSION['data']['notifications'], fn($note) => $note['userId'] === $userId));
}

function mark_notification_as_read(string $notificationId): void {
    if (database_available()) {
        query_execute('UPDATE notifications SET `read` = 1 WHERE id = ?', [$notificationId]);
        return;
    }

    init_data();
    foreach ($_SESSION['data']['notifications'] as &$notification) {
        if ($notification['id'] === $notificationId) {
            $notification['read'] = true;
            return;
        }
    }
}

/**
 * Most recent unread notifications for the topbar bell dropdown
 * (includes/header.php) — a short preview, not the full history that
 * notifications/ still shows.
 */
function get_unread_notifications(string $userId, int $limit = 5): array {
    if (database_available()) {
        return query_all(
            'SELECT * FROM notifications WHERE userId = ? AND `read` = 0 ORDER BY createdAt DESC LIMIT ' . (int) $limit,
            [$userId]
        );
    }

    init_data();
    $rows = array_values(array_filter(
        $_SESSION['data']['notifications'],
        fn($note) => $note['userId'] === $userId && !$note['read']
    ));
    usort($rows, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return array_slice($rows, 0, $limit);
}

/**
 * Unread count for the bell icon's badge — runs on every page (header.php),
 * so this is a COUNT query rather than fetching+counting the full list.
 */
function count_unread_notifications(string $userId): int {
    if (database_available()) {
        $row = query_one('SELECT COUNT(*) AS cnt FROM notifications WHERE userId = ? AND `read` = 0', [$userId]);
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter(
        $_SESSION['data']['notifications'],
        fn($note) => $note['userId'] === $userId && !$note['read']
    ));
}

/**
 * "Mark all as read" for the bell dropdown — only touches the given user's
 * own notifications.
 */
function mark_all_notifications_as_read(string $userId): void {
    if (database_available()) {
        query_execute('UPDATE notifications SET `read` = 1 WHERE userId = ? AND `read` = 0', [$userId]);
        return;
    }

    init_data();
    foreach ($_SESSION['data']['notifications'] as &$notification) {
        if ($notification['userId'] === $userId) {
            $notification['read'] = true;
        }
    }
}

function get_all_users(): array {
    if (database_available()) {
        return query_all('SELECT * FROM users ORDER BY role ASC, name ASC');
    }

    init_data();
    $users = array_values($_SESSION['data']['users']);
    usort($users, fn($a, $b) => [$a['role'], $a['name']] <=> [$b['role'], $b['name']]);
    return $users;
}

function get_pending_users(): array {
    // Compliance gate (Fix Guide item 7): every signup now starts as
    // verified = 0 / pending, usually with no documents yet. The admin's
    // review queue should only hold applications actually waiting on an
    // admin, i.e. email verified AND all required documents submitted
    // (compliance state 'under_review'). Users who still have to upload
    // anything are not the admin's to act on.
    if (database_available()) {
        $candidates = query_all("SELECT * FROM users WHERE verified = 0 AND otp_verified = 1 AND (status IS NULL OR status = 'pending') ORDER BY email ASC");
    } else {
        init_data();
        $candidates = array_values(array_filter(
            $_SESSION['data']['users'],
            fn($user) => !$user['verified'] && ($user['status'] ?? 'pending') === 'pending'
        ));
    }

    return array_values(array_filter(
        $candidates,
        fn($user) => compliance_evaluate($user)['state'] === 'under_review'
    ));
}

/**
 * Accounts an admin has already rejected — kept separate from the pending
 * queue so a rejection doesn't keep resurfacing as something to review, but
 * still visible so admins can see who was turned down and why.
 */
function get_rejected_users(): array {
    if (database_available()) {
        return query_all("SELECT * FROM users WHERE status = 'rejected' ORDER BY reviewed_at DESC");
    }

    init_data();
    $users = array_values(array_filter($_SESSION['data']['users'], fn($user) => ($user['status'] ?? '') === 'rejected'));
    usort($users, fn($a, $b) => strcmp($b['reviewed_at'] ?? '', $a['reviewed_at'] ?? ''));
    return $users;
}

function approve_pending_user(string $userId, ?string $approvedByUserId = null): void {
    $now = date('Y-m-d H:i:s');
    if (database_available()) {
        query_execute(
            "UPDATE users SET verified = 1, status = 'approved', rejection_reason = NULL, reviewed_by = ?, reviewed_at = ? WHERE id = ?",
            [$approvedByUserId, $now, $userId]
        );
        log_activity($approvedByUserId, 'account.approve', 'user', $userId);
        notify_account_decision($userId, 'approved');
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $_SESSION['data']['users'][$userId]['verified'] = true;
        $_SESSION['data']['users'][$userId]['status'] = 'approved';
        $_SESSION['data']['users'][$userId]['rejection_reason'] = null;
        $_SESSION['data']['users'][$userId]['reviewed_by'] = $approvedByUserId;
        $_SESSION['data']['users'][$userId]['reviewed_at'] = $now;
    }
    log_activity($approvedByUserId, 'account.approve', 'user', $userId);
    notify_account_decision($userId, 'approved');
}

/**
 * Reject a pending account. Unlike approve, this always needs a reason —
 * the applicant (and any admin reviewing the decision later) should know
 * why, not just that it happened.
 */
function reject_pending_user(string $userId, string $reason, ?string $rejectedByUserId = null): void {
    $now = date('Y-m-d H:i:s');
    if (database_available()) {
        query_execute(
            "UPDATE users SET verified = 0, status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?",
            [$reason, $rejectedByUserId, $now, $userId]
        );
        log_activity($rejectedByUserId, 'account.reject', 'user', $userId, $reason);
        notify_account_decision($userId, 'rejected', $reason);
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $_SESSION['data']['users'][$userId]['verified'] = false;
        $_SESSION['data']['users'][$userId]['status'] = 'rejected';
        $_SESSION['data']['users'][$userId]['rejection_reason'] = $reason;
        $_SESSION['data']['users'][$userId]['reviewed_by'] = $rejectedByUserId;
        $_SESSION['data']['users'][$userId]['reviewed_at'] = $now;
    }
    log_activity($rejectedByUserId, 'account.reject', 'user', $userId, $reason);
    notify_account_decision($userId, 'rejected', $reason);
}

/**
 * Record an in-app notification for an approve/reject decision. The
 * applicant can't log in to see it while rejected/pending, but this keeps
 * one consistent audit trail and means the message is already waiting for
 * them if a future admin flips the decision or once they can sign in.
 */
function notify_account_decision(string $userId, string $decision, ?string $reason = null): void {
    $copy = [
        'approved' => ['Account approved', 'Your compliance documents were approved. You can now list stock, send requests and trade on KAMALIG.'],
        'rejected' => ['Documents need resubmission', $reason !== null && $reason !== ''
            ? "An admin could not approve your documents: $reason. Open Documents to upload corrected files."
            : "An admin could not approve your documents. Open Documents to upload corrected files."],
        'suspended' => ['Account suspended', $reason !== null && $reason !== ''
            ? "Your account has been suspended: $reason"
            : 'Your account has been suspended. Contact support for details.'],
        'reactivated' => ['Account reactivated', 'Your KAMALIG account has been reactivated. You can log in again.'],
    ];
    [$title, $message] = $copy[$decision] ?? ['Account update', 'Your account status has changed.'];

    create_notification([
        'userId' => $userId,
        'type' => 'account',
        'title' => $title,
        'message' => $message,
        'relatedId' => $userId,
    ]);
}

/**
 * Suspend an already-approved account so it can no longer log in, without
 * losing its history the way a full reject/delete would. Reuses the same
 * status + rejection_reason columns the reject flow already added — one
 * more state (`suspended`) alongside pending/approved/rejected, rather
 * than a whole parallel "is this account disabled" column.
 */
function suspend_user(string $userId, string $reason, ?string $suspendedByUserId = null): void {
    $now = date('Y-m-d H:i:s');
    if (database_available()) {
        query_execute(
            "UPDATE users SET status = 'suspended', rejection_reason = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?",
            [$reason, $suspendedByUserId, $now, $userId]
        );
    } else {
        init_data();
        if (isset($_SESSION['data']['users'][$userId])) {
            $_SESSION['data']['users'][$userId]['status'] = 'suspended';
            $_SESSION['data']['users'][$userId]['rejection_reason'] = $reason;
            $_SESSION['data']['users'][$userId]['reviewed_by'] = $suspendedByUserId;
            $_SESSION['data']['users'][$userId]['reviewed_at'] = $now;
        }
    }
    log_activity($suspendedByUserId, 'account.suspend', 'user', $userId, $reason);
    notify_account_decision($userId, 'suspended', $reason);
}

/**
 * Reactivate a suspended account back to approved/verified.
 */
function reactivate_user(string $userId, ?string $reactivatedByUserId = null): void {
    $now = date('Y-m-d H:i:s');
    if (database_available()) {
        query_execute(
            "UPDATE users SET verified = 1, status = 'approved', rejection_reason = NULL, reviewed_by = ?, reviewed_at = ? WHERE id = ?",
            [$reactivatedByUserId, $now, $userId]
        );
    } else {
        init_data();
        if (isset($_SESSION['data']['users'][$userId])) {
            $_SESSION['data']['users'][$userId]['verified'] = true;
            $_SESSION['data']['users'][$userId]['status'] = 'approved';
            $_SESSION['data']['users'][$userId]['rejection_reason'] = null;
            $_SESSION['data']['users'][$userId]['reviewed_by'] = $reactivatedByUserId;
            $_SESSION['data']['users'][$userId]['reviewed_at'] = $now;
        }
    }
    log_activity($reactivatedByUserId, 'account.reactivate', 'user', $userId);
    notify_account_decision($userId, 'reactivated');
}

/**
 * Change an account's role from the admin accounts panel. Deliberately
 * narrow: only among the platform's account-holder roles, never to/from
 * 'admin' — promoting or demoting admin access is a higher-stakes action
 * than this simple dropdown should allow.
 */
function update_user_role(string $userId, string $newRole, ?string $changedByUserId = null): bool {
    if (!in_array($newRole, ['farmer', 'supplier', 'importer'], true)) {
        return false;
    }
    $user = get_user_by_id($userId);
    if (!$user || $user['role'] === 'admin') {
        return false;
    }
    $oldRole = $user['role'];
    if ($oldRole === $newRole) {
        return true;
    }

    if (database_available()) {
        query_execute('UPDATE users SET role = ? WHERE id = ?', [$newRole, $userId]);
    } else {
        init_data();
        if (isset($_SESSION['data']['users'][$userId])) {
            $_SESSION['data']['users'][$userId]['role'] = $newRole;
        }
    }
    log_activity($changedByUserId, 'account.role_change', 'user', $userId, "$oldRole → $newRole");
    return true;
}

function get_user_by_email(string $email): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    init_data();
    foreach ($_SESSION['data']['users'] as $user) {
        if (strcasecmp($user['email'], $email) === 0) {
            return $user;
        }
    }
    return null;
}

/**
 * Companion to get_user_by_email() for the Google Sign-In lookup path —
 * see includes/google_oauth.php's google_oauth_resolve_account() for how
 * this and get_user_by_email() together implement the account-linking
 * rule (link only onto an already otp_verified account, never silently
 * onto an unverified one).
 */
function get_user_by_google_id(string $googleId): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM users WHERE google_id = ?', [$googleId]);
    }

    init_data();
    foreach ($_SESSION['data']['users'] as $user) {
        if (($user['google_id'] ?? null) === $googleId) {
            return $user;
        }
    }
    return null;
}

/**
 * Attaches a Google identity to an already-otp_verified account. Never
 * call this without having gone through google_oauth_resolve_account()
 * first and confirmed action === 'link' — this function itself doesn't
 * re-check otp_verified, to keep the linking decision in one place.
 */
function link_google_account(string $userId, string $googleId): void {
    if (database_available()) {
        query_execute('UPDATE users SET google_id = ? WHERE id = ?', [$googleId, $userId]);
        return;
    }

    init_data();
    foreach ($_SESSION['data']['users'] as &$user) {
        if ($user['id'] === $userId) {
            $user['google_id'] = $googleId;
            break;
        }
    }
}

function add_pending_user(array $user): void {
    if (database_available()) {
        query_execute(
            // BUGFIX: this INSERT used to leave out otp_verified and google_id,
            // so a Google registration (landing/index.php passes both) was
            // saved with otp_verified = 0 and no google_id. Its next Google
            // sign-in then could not find the account by google_id and fell
            // into the "email exists but not verified" collision branch.
            'INSERT INTO users (id, email, password, name, role, company, phone, location, verified, otp_verified, google_id, consent_version, consent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $user['id'],
                $user['email'],
                $user['password'] ?? null,
                $user['name'],
                $user['role'],
                $user['company'],
                $user['phone'],
                $user['location'],
                $user['verified'] ? 1 : 0,
                !empty($user['otp_verified']) ? 1 : 0,
                $user['google_id'] ?? null,
                $user['consent_version'] ?? null,
                $user['consent_at'] ?? null,
            ]
        );
        return;
    }

    init_data();
    $_SESSION['data']['users'][$user['id']] = array_merge(['status' => 'pending'], $user);
}

function get_pending_documents(): array {
    if (database_available()) {
        return query_all('SELECT * FROM documents WHERE status = ? ORDER BY uploadedAt DESC', ['pending']);
    }

    init_data();
    return array_values(array_filter($_SESSION['data']['documents'] ?? [], fn($doc) => $doc['status'] === 'pending'));
}

function get_request_by_id(string $id): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM requests WHERE id = ?', [$id]);
    }

    foreach (get_requests() as $request) {
        if ($request['id'] === $id) {
            return $request;
        }
    }
    return null;
}

function count_users_by_role(string $role): int {
    if (database_available()) {
        $row = query_one('SELECT COUNT(*) AS cnt FROM users WHERE role = ?', [$role]);
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter($_SESSION['data']['users'], fn($user) => $user['role'] === $role));
}

function count_stocks_by_status(string $status): int {
    if (database_available()) {
        $row = query_one('SELECT COUNT(*) AS cnt FROM stocks WHERE status = ?', [$status]);
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter($_SESSION['data']['stocks'], fn($stock) => $stock['status'] === $status));
}

function count_pending_users(): int {
    // Cheap SQL count for the PUBLIC landing page (do not run the per-user
    // compliance evaluation on a public page). Counts email-verified
    // accounts not yet approved; the admin's own queue, which is narrower
    // (documents submitted), is get_pending_users().
    if (database_available()) {
        $row = query_one("SELECT COUNT(*) AS cnt FROM users WHERE verified = 0 AND otp_verified = 1 AND (status IS NULL OR status = 'pending')");
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter($_SESSION['data']['users'], fn($user) => !$user['verified'] && ($user['status'] ?? 'pending') === 'pending'));
}

function count_pending_documents(): int {
    if (database_available()) {
        $row = query_one('SELECT COUNT(*) AS cnt FROM documents WHERE status = ?', ['pending']);
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter($_SESSION['data']['documents'] ?? [], fn($doc) => $doc['status'] === 'pending'));
}

function count_requests_by_status(string $status): int {
    if (database_available()) {
        $row = query_one('SELECT COUNT(*) AS cnt FROM requests WHERE status = ?', [$status]);
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter($_SESSION['data']['requests'], fn($request) => $request['status'] === $status));
}

function count_stocks_by_source(string $source): int {
    if (database_available()) {
        $row = query_one('SELECT COUNT(*) AS cnt FROM stocks WHERE source = ?', [$source]);
        return (int) ($row['cnt'] ?? 0);
    }

    init_data();
    return count(array_filter($_SESSION['data']['stocks'], fn($stock) => $stock['source'] === $source));
}

function get_stock_counts_by_variety(): array {
    // BUGFIX: this previously summed the raw `quantity` column directly —
    // SUM(quantity) in DB mode, += $stock['quantity'] in session mode —
    // mixing together values measured in different units (kg, sack, ton)
    // with no conversion. A variety listed partly in kg, partly in 50kg
    // sacks, and partly in 1000kg tons would get a "Total Quantity" that's
    // just the raw numbers added together (e.g. 500 + 10 + 2 = 512)
    // instead of the true combined weight (500 + 500 + 2000 = 3000 kg) —
    // the same unit-mixing bug already fixed in Marketplace Compare's
    // price display. baseQuantityKg is the same kg-normalized column
    // place_stock_in_warehouse() and the capacity checks already rely on
    // for exactly this reason.
    if (database_available()) {
        return query_all(
            'SELECT variety, COUNT(*) AS listingCount, SUM(COALESCE(baseQuantityKg, quantity)) AS totalQuantityKg FROM stocks GROUP BY variety ORDER BY listingCount DESC'
        );
    }

    init_data();
    $counts = [];
    foreach ($_SESSION['data']['stocks'] as $stock) {
        $variety = $stock['variety'];
        if (!isset($counts[$variety])) {
            $counts[$variety] = ['variety' => $variety, 'listingCount' => 0, 'totalQuantityKg' => 0];
        }
        $counts[$variety]['listingCount']++;
        $counts[$variety]['totalQuantityKg'] += (float) ($stock['baseQuantityKg'] ?? unit_to_kg((float) $stock['quantity'], $stock['unit'] ?? 'kg'));
    }
    usort($counts, fn($a, $b) => $b['listingCount'] <=> $a['listingCount']);
    return array_values($counts);
}

function update_user_profile(string $userId, array $fields): void {
    $name = $fields['name'];
    $company = $fields['company'];
    $phone = $fields['phone'];
    $location = $fields['location'];

    if (database_available()) {
        query_execute(
            'UPDATE users SET name = ?, company = ?, phone = ?, location = ? WHERE id = ?',
            [$name, $company, $phone, $location, $userId]
        );
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $_SESSION['data']['users'][$userId]['name'] = $name;
        $_SESSION['data']['users'][$userId]['company'] = $company;
        $_SESSION['data']['users'][$userId]['phone'] = $phone;
        $_SESSION['data']['users'][$userId]['location'] = $location;
    }
}

function update_user_password(string $userId, string $passwordHash): void {
    if (database_available()) {
        query_execute('UPDATE users SET password = ? WHERE id = ?', [$passwordHash, $userId]);
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $_SESSION['data']['users'][$userId]['password'] = $passwordHash;
    }
}

// Login throttling — see migrations/016_add_login_lockout_fields.sql.
// After LOGIN_LOCKOUT_THRESHOLD consecutive failed password attempts on one
// account, further attempts are blocked until LOGIN_LOCKOUT_MINUTES have
// passed. A correct password (record_login_success) clears the counter
// immediately, so normal users who mistype once or twice never notice this.
const LOGIN_LOCKOUT_THRESHOLD = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

/**
 * Returns null if the account isn't currently locked, or the lockout
 * expiry (as a DateTime) if it is. Never throws — a missing/legacy row
 * without these columns is simply treated as not locked.
 */
function get_login_lockout_until(array $user): ?DateTime {
    $until = $user['lockout_until'] ?? null;
    if (!$until) {
        return null;
    }
    try {
        $untilDt = new DateTime($until);
    } catch (Exception $e) {
        return null;
    }
    return $untilDt > new DateTime() ? $untilDt : null;
}

/**
 * Call after a failed password check. Increments the failure counter and,
 * once it reaches the threshold, sets a lockout_until timestamp and resets
 * the counter so the next successful login starts clean.
 */
function record_login_failure(string $userId): void {
    if (database_available()) {
        $user = get_user_by_id($userId);
        $attempts = ((int) ($user['failed_login_attempts'] ?? 0)) + 1;
        if ($attempts >= LOGIN_LOCKOUT_THRESHOLD) {
            $lockoutUntil = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MINUTES * 60));
            query_execute(
                'UPDATE users SET failed_login_attempts = 0, lockout_until = ? WHERE id = ?',
                [$lockoutUntil, $userId]
            );
        } else {
            query_execute(
                'UPDATE users SET failed_login_attempts = ? WHERE id = ?',
                [$attempts, $userId]
            );
        }
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $attempts = ((int) ($_SESSION['data']['users'][$userId]['failed_login_attempts'] ?? 0)) + 1;
        if ($attempts >= LOGIN_LOCKOUT_THRESHOLD) {
            $_SESSION['data']['users'][$userId]['failed_login_attempts'] = 0;
            $_SESSION['data']['users'][$userId]['lockout_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MINUTES * 60));
        } else {
            $_SESSION['data']['users'][$userId]['failed_login_attempts'] = $attempts;
        }
    }
}

/**
 * Call after a successful login. Clears any failure count / lockout so a
 * legitimate user who eventually got their password right isn't left
 * partway toward a lockout from earlier mistypes.
 */
function record_login_success(string $userId): void {
    if (database_available()) {
        query_execute(
            'UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = ?',
            [$userId]
        );
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $_SESSION['data']['users'][$userId]['failed_login_attempts'] = 0;
        $_SESSION['data']['users'][$userId]['lockout_until'] = null;
    }
}

function get_warehouses(): array {
    return ['Philippines', 'Thailand', 'India', 'Italy'];
}

function get_varieties(): array {
    if (database_available()) {
        $rows = query_all('SELECT name FROM varieties ORDER BY name ASC');
        return array_map(fn($row) => $row['name'], $rows);
    }

    init_data();
    if (!isset($_SESSION['data']['varieties'])) {
        $_SESSION['data']['varieties'] = ['Dinorado', 'Sinandomeng', 'Jasmine', 'Basmati', 'Arborio', 'Carnaroli'];
    }
    return $_SESSION['data']['varieties'];
}

function add_variety(string $name): bool {
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    if (database_available()) {
        try {
            query_execute(
                'INSERT INTO varieties (id, name, createdAt) VALUES (?, ?, ?)',
                ['variety_' . bin2hex(random_bytes(6)), $name, date('Y-m-d H:i:s')]
            );
            return true;
        } catch (Throwable $e) {
            // Most likely the unique(name) constraint — treat as "already exists".
            return false;
        }
    }

    init_data();
    if (!isset($_SESSION['data']['varieties'])) {
        $_SESSION['data']['varieties'] = ['Dinorado', 'Sinandomeng', 'Jasmine', 'Basmati', 'Arborio', 'Carnaroli'];
    }
    if (in_array($name, $_SESSION['data']['varieties'], true)) {
        return false;
    }
    $_SESSION['data']['varieties'][] = $name;
    return true;
}

function delete_variety(string $name): void {
    if (database_available()) {
        query_execute('DELETE FROM varieties WHERE name = ?', [$name]);
        return;
    }

    init_data();
    if (isset($_SESSION['data']['varieties'])) {
        $_SESSION['data']['varieties'] = array_values(array_filter(
            $_SESSION['data']['varieties'],
            fn($existing) => $existing !== $name
        ));
    }
}

/**
 * Feature-level permissions (Phase 7), backed by the `permissions` table
 * (migration 014). See require_permission() in includes/bootstrap.php —
 * that's the enforcement point; this is just the lookup.
 */
function permission_allowed_roles(string $featureKey, array $fallbackRoles): array {
    if (database_available()) {
        $rows = query_all('SELECT role FROM permissions WHERE featureKey = ?', [$featureKey]);
        if (!empty($rows)) {
            // '__none__' is a sentinel saved by save_permission_matrix() when an
            // admin explicitly deselects every role — without it, zero rows would
            // be indistinguishable from "never configured" and silently fall back
            // to $fallbackRoles instead of staying locked to no one.
            return array_values(array_filter(
                array_map(fn($row) => $row['role'], $rows),
                fn($role) => $role !== '__none__'
            ));
        }
        return $fallbackRoles;
    }

    init_data();
    if (isset($_SESSION['data']['permissions'][$featureKey])) {
        return $_SESSION['data']['permissions'][$featureKey];
    }
    return $fallbackRoles;
}

/**
 * The full set of features the admin/roles/ page can edit, with their
 * original hardcoded role lists as fallback defaults (used when a feature
 * has no rows in `permissions` yet). Kept as one lookup so the admin page
 * and require_permission() call sites can't drift apart.
 */
function known_permission_features(): array {
    return [
        'inventory' => ['label' => 'Inventory', 'fallback' => ['farmer', 'importer', 'supplier']],
        'warehouses' => ['label' => 'Warehouses', 'fallback' => ['farmer', 'importer', 'supplier']],
        'procurement.requests.create' => ['label' => 'Send Procurement Request', 'fallback' => ['supplier']],
    ];
}

/**
 * Validate that a feature key is known (exists in known_permission_features())
 * before it's used in require_permission(). This catches typos and invalid keys
 * at runtime instead of silently falling back to defaults — a fail-safe that
 * prevents accidental permission bypass from code errors.
 */
function validate_permission_feature(string $featureKey): void {
    $known = known_permission_features();
    if (!isset($known[$featureKey])) {
        trigger_error(
            "Unknown permission feature key: '$featureKey'. Valid keys: " . implode(', ', array_keys($known)),
            E_USER_ERROR
        );
    }
}

function get_permission_matrix(): array {
    $matrix = [];
    foreach (known_permission_features() as $key => $feature) {
        $matrix[$key] = permission_allowed_roles($key, $feature['fallback']);
    }
    return $matrix;
}

/**
 * $matrix is [featureKey => [role, role, ...]], covering every key from
 * known_permission_features(). Replaces the full set of allowed roles for
 * each of those features.
 *
 * BUGFIX: 'admin' is force-included in every feature's role list here, at
 * the data layer, rather than relying on each caller to do it. The first
 * fix for this (the self-lockout described in require_permission()'s doc
 * comment) only patched admin/roles/index.php's POST handler — but
 * import_backup() below calls this function directly with raw, unsanitized
 * backup content, bypassing that page entirely. A corrupted or maliciously
 * crafted backup file could still have excluded 'admin' from a feature and
 * silently lock every admin out of it on import. Enforcing the invariant
 * here instead means every caller, present and future, gets it for free.
 */
function save_permission_matrix(array $matrix): void {
    foreach ($matrix as $featureKey => $roles) {
        $matrix[$featureKey] = array_values(array_unique(array_merge(
            is_array($roles) ? $roles : [],
            ['admin']
        )));
    }

    if (database_available()) {
        foreach ($matrix as $featureKey => $roles) {
            query_execute('DELETE FROM permissions WHERE featureKey = ?', [$featureKey]);
            if (empty($roles)) {
                query_execute('INSERT INTO permissions (featureKey, role) VALUES (?, ?)', [$featureKey, '__none__']);
                continue;
            }
            foreach ($roles as $role) {
                query_execute('INSERT INTO permissions (featureKey, role) VALUES (?, ?)', [$featureKey, $role]);
            }
        }
        return;
    }

    init_data();
    foreach ($matrix as $featureKey => $roles) {
        $_SESSION['data']['permissions'][$featureKey] = $roles;
    }
}

/**
 * Record a granular audit log entry for each individual permission change
 * (each feature+role pair) so that permission audits show exactly what
 * changed, who changed it, and when. Called by admin/roles/index.php after
 * save_permission_matrix() completes.
 */
function log_permission_changes(array $oldMatrix, array $newMatrix, ?string $adminUserId = null): void {
    $details = [];
    foreach (array_unique(array_merge(array_keys($oldMatrix), array_keys($newMatrix))) as $featureKey) {
        $old = $oldMatrix[$featureKey] ?? [];
        $new = $newMatrix[$featureKey] ?? [];
        if ($old !== $new) {
            $oldStr = empty($old) ? '(none)' : implode(', ', $old);
            $newStr = empty($new) ? '(none)' : implode(', ', $new);
            $details[] = $featureKey . ': ' . $oldStr . ' → ' . $newStr;
        }
    }

    if (!empty($details)) {
        log_activity(
            $adminUserId,
            'permissions.bulk_update',
            'permission',
            null,
            implode('; ', $details)
        );
    }
}

/**
 * Record a granular audit log entry for each individual setting change
 * (each key+value pair) so that settings audits show exactly what
 * changed, who changed it, and when. Called by admin/settings/index.php
 * after all set_setting() calls complete.
 */
function log_setting_changes(array $changes, ?string $adminUserId = null): void {
    foreach ($changes as $key => $newValue) {
        $oldValue = null;
        if (database_available()) {
            $row = query_one('SELECT settingValue FROM settings WHERE settingKey = ?', [$key]);
            $oldValue = $row['settingValue'] ?? null;
        } else {
            init_data();
            $oldValue = $_SESSION['data']['settings'][$key] ?? null;
        }

        $oldStr = $oldValue !== null ? $oldValue : '(default)';
        log_activity(
            $adminUserId,
            'setting.change',
            'setting',
            $key,
            $oldStr . ' → ' . $newValue
        );
    }
}

/**
 * Generic admin-editable settings (Phase 7), backed by the `settings`
 * table (migration 013). A key that's never been saved through the admin
 * Settings page (admin/settings/) falls back to its env var, then to
 * $default — so existing deployments configured via env vars keep working
 * unchanged until an admin actually edits the value in the UI. Falls back
 * to the same env-var-or-default behavior in session-only mode (no DB).
 */
function get_setting(string $key, string $default): string {
    if (database_available()) {
        $row = query_one('SELECT settingValue FROM settings WHERE settingKey = ?', [$key]);
        if ($row !== null) {
            return $row['settingValue'];
        }
        return (string) (getenv($key) ?: $default);
    }

    init_data();
    if (isset($_SESSION['data']['settings'][$key])) {
        return $_SESSION['data']['settings'][$key];
    }
    return (string) (getenv($key) ?: $default);
}

function set_setting(string $key, string $value, ?string $updatedBy = null): void {
    if (database_available()) {
        query_execute(
            'INSERT INTO settings (settingKey, settingValue, updatedAt, updatedBy) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = VALUES(updatedAt), updatedBy = VALUES(updatedBy)',
            [$key, $value, date('Y-m-d H:i:s'), $updatedBy]
        );
        return;
    }

    init_data();
    $_SESSION['data']['settings'][$key] = $value;
}

/**
 * Shared configuration keys for the admin settings screen and the runtime
 * threshold checks that consume them. Keeping the metadata centralised stops
 * the UI and the business logic from drifting apart as additional settings
 * are added.
 */
function known_setting_fields(): array {
    return [
        'LOW_STOCK_THRESHOLD' => [
            'label' => 'Low stock threshold (kg)',
            'help' => 'A listing is flagged "low stock" once its quantity, converted to kilograms, falls to or below this number. Sack and ton listings are converted automatically, so one value works for every unit.',
            'default' => '500',
            'min' => 0,
            'max' => 10000000,
            'step' => 'any',
        ],
        'WAREHOUSE_CAPACITY_ALERT_PCT' => [
            'label' => 'Warehouse capacity alert threshold (%)',
            'help' => 'A warehouse owner is notified once that warehouse\'s usage reaches this percentage of its capacity.',
            'default' => '85',
            'min' => 0,
            'max' => 100,
            'step' => 'any',
        ],
        'SACK_WEIGHT_KG' => [
            'label' => 'Sack weight (kg)',
            'help' => 'Used to convert listings priced/measured in "sack" units into kilograms — the base unit warehouse capacity, transparency, and price-per-kg comparisons all use. The Philippine standard is 50kg; change this only if your sacks are packed differently.',
            'default' => '50',
            'min' => 0.1,
            'max' => 500,
            'step' => 'any',
        ],
        'VARIETY_SHORTAGE_THRESHOLD_KG' => [
            'label' => 'Variety shortage alert threshold (kg)',
            'help' => 'Transparency flags a rice variety as running low system-wide once its total available quantity (across every seller, normalized to kg) falls to or below this number — a market-level signal, distinct from an individual seller\'s own low-stock threshold above.',
            'default' => '500',
            'min' => 0,
            'max' => 10000000,
            'step' => 'any',
        ],
    ];
}

function validate_setting_value(string $key, string $value): ?string {
    $settings = known_setting_fields();
    if (!isset($settings[$key])) {
        return 'Unknown setting.';
    }

    $field = $settings[$key];
    $raw = trim($value);
    if ($raw === '' || !is_numeric($raw)) {
        return $field['label'] . ' must be a number.';
    }

    $numeric = (float) $raw;
    $min = isset($field['min']) ? (float) $field['min'] : 0.0;
    $max = isset($field['max']) ? (float) $field['max'] : null;

    if ($numeric < $min) {
        return $field['label'] . ' must be at least ' . $min . '.';
    }
    if ($max !== null && $numeric > $max) {
        return $field['label'] . ' cannot exceed ' . $max . '.';
    }

    return null;
}

/**
 * Rule-based stock level threshold used for low-stock alerts, compared in
 * kilograms (unit_to_kg()) so kg, sack and ton listings share one scale.
 * Deliberately simple fixed numbers (not per-variety or per-role) — this is
 * a prototype-level "rule-based" check, same spirit as the AI validation
 * flags. Editable from admin/settings/; see get_setting() above for the
 * env-var/default fallback chain.
 */
function low_stock_threshold(): float {
    return (float) get_setting('LOW_STOCK_THRESHOLD', '500');
}

function warehouse_capacity_alert_threshold(): float {
    return (float) get_setting('WAREHOUSE_CAPACITY_ALERT_PCT', '85');
}

/**
 * Create a notification unless an unread notification of the same
 * type+relatedId already exists for this user — stops every stock edit
 * that keeps a listing under the low-stock line from re-notifying the
 * owner on every save. In session-fallback mode (no DB) we skip the dedupe
 * check and just create it, since there's no easy way to query it back.
 */
function notify_once(string $userId, string $type, ?string $relatedId, string $title, string $message): void {
    if (database_available() && $relatedId !== null) {
        $existing = query_one(
            'SELECT id FROM notifications WHERE userId = ? AND type = ? AND relatedId = ? AND `read` = 0',
            [$userId, $type, $relatedId]
        );
        if ($existing) {
            return;
        }
    }

    create_notification([
        'userId' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'relatedId' => $relatedId,
    ]);
}

/**
 * Check a stock listing against the low-stock threshold (compared in
 * kilograms) and notify its owner if it has crossed it. Called after any change to a
 * stock's quantity (create, edit, or a procurement request being accepted).
 * A listing that's sold out or archived isn't "low stock" in the sense that
 * matters here (there's nothing to restock into an active listing), so
 * those are skipped.
 */
function maybe_notify_stock_threshold(?array $stock): void {
    if (!$stock || $stock['status'] !== 'available') {
        return;
    }

    $qty = (float) $stock['quantity'];
    $qtyKg = unit_to_kg($qty, (string) ($stock['unit'] ?? 'kg'));
    $ownerId = $stock['userId'];

    if ($qtyKg > 0 && $qtyKg <= low_stock_threshold()) {
        notify_once(
            $ownerId,
            'low_stock',
            $stock['id'],
            'Low stock warning',
            'Your listing of ' . $stock['variety'] . ' is down to ' . $qty . ' ' . $stock['unit'] . ' — consider restocking.'
        );
    }
}

/**
 * Notify a warehouse's owner once its usage crosses the admin-configurable
 * alert threshold (default 85%). Mirrors maybe_notify_stock_threshold()'s
 * notify_once() pattern — fires once, then stays quiet until the
 * notification is read, rather than re-notifying on every subsequent
 * placement. Skips archived warehouses and ones with no capacity set
 * (capacity 0 already means "untracked" everywhere else in this file).
 */
function maybe_notify_warehouse_capacity(?string $warehouseId): void {
    if (!$warehouseId) {
        return;
    }
    $warehouse = get_warehouse_by_id($warehouseId);
    if (!$warehouse || $warehouse['status'] === 'archived' || (float) $warehouse['capacity'] <= 0) {
        return;
    }

    $used = get_warehouse_capacity_used($warehouseId);
    $pct = ($used / (float) $warehouse['capacity']) * 100;

    if ($pct >= warehouse_capacity_alert_threshold()) {
        notify_once(
            $warehouse['userId'],
            'warehouse_capacity',
            $warehouse['id'],
            'Warehouse nearing capacity',
            $warehouse['name'] . ' is at ' . round($pct) . '% of capacity (' . $used . ' / ' . $warehouse['capacity'] . ') — consider transferring stock out or expanding capacity.'
        );
    }
}

/**
 * Look for an existing (non-rejected) document with the same claimed
 * business name and permit number as the one being checked — a simple,
 * deterministic stand-in for "possible duplicate document" detection.
 * Excludes the document being checked itself.
 */
function find_duplicate_document(string $businessName, string $permitNumber, string $excludeDocumentId): ?array {
    $businessName = trim($businessName);
    $permitNumber = trim($permitNumber);
    if ($businessName === '' || $permitNumber === '') {
        return null;
    }

    if (database_available()) {
        return query_one(
            "SELECT id, fileName, uploadedAt FROM documents
             WHERE id != ? AND status != 'rejected'
               AND LOWER(TRIM(businessName)) = LOWER(?)
               AND LOWER(TRIM(permitNumber)) = LOWER(?)
             ORDER BY uploadedAt ASC LIMIT 1",
            [$excludeDocumentId, $businessName, $permitNumber]
        );
    }

    init_data();
    foreach ($_SESSION['data']['documents'] ?? [] as $doc) {
        if ($doc['id'] === $excludeDocumentId || ($doc['status'] ?? '') === 'rejected') {
            continue;
        }
        if (strcasecmp(trim($doc['businessName'] ?? ''), $businessName) === 0
            && strcasecmp(trim($doc['permitNumber'] ?? ''), $permitNumber) === 0) {
            return $doc;
        }
    }
    return null;
}

/**
 * Whether a claimed business name matches a registered account's company
 * name — a simple stand-in for "unregistered supplier/product" checking.
 */
function is_registered_business_name(string $businessName): bool {
    $businessName = trim($businessName);
    if ($businessName === '') {
        return true; // nothing claimed, nothing to flag as unregistered
    }

    if (database_available()) {
        $match = query_one('SELECT id FROM users WHERE LOWER(TRIM(company)) = LOWER(?) LIMIT 1', [$businessName]);
        return $match !== null;
    }

    init_data();
    foreach ($_SESSION['data']['users'] ?? [] as $user) {
        if (strcasecmp(trim($user['company'] ?? ''), $businessName) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Fetch a single document by id — used by the admin document review/
 * correction screen.
 */
function get_document_by_id(string $id): ?array {
    if (database_available()) {
        return query_one('SELECT * FROM documents WHERE id = ?', [$id]);
    }

    init_data();
    foreach ($_SESSION['data']['documents'] ?? [] as $doc) {
        if ($doc['id'] === $id) {
            return $doc;
        }
    }
    return null;
}

/**
 * Apply reviewer corrections to a document's claimed fields before
 * verify/reject — the "human review and correction" step. Separate from
 * update_document_ocr() (which the OCR/AI pipeline writes to) so the two
 * call sites stay easy to tell apart in the activity log.
 */
function correct_document_fields(string $id, array $fields, string $correctedByUserId): void {
    update_document_ocr($id, $fields);
    log_activity($correctedByUserId, 'document.correct', 'document', $id);
}

// ---------------------------------------------------------------------
// Admin dashboard aggregates (KAMALIG redesign)
// Every value below is computed from real stocks / warehouses /
// procurement_orders / stock_movements / price_history rows. Where there
// isn't enough history to support a comparison (e.g. no price_history yet),
// the function returns null for that figure instead of inventing one.
// ---------------------------------------------------------------------

/**
 * All warehouses system-wide (every owner), for the admin dashboard —
 * unlike get_user_warehouses(), which is scoped to one account.
 */
function get_all_warehouses(): array {
    if (database_available()) {
        return query_all('SELECT * FROM warehouses ORDER BY name ASC');
    }

    init_data();
    $rows = array_values($_SESSION['data']['warehouses'] ?? []);
    usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $rows;
}

/**
 * One row per active warehouse: capacity, quantity currently stored there,
 * percent of capacity used, and a healthy/moderate/low/critical band
 * derived from that percentage. There's no per-warehouse safety-stock
 * figure in the schema, so capacity utilisation is the closest real signal
 * available for "is this location running low".
 */
function get_warehouse_inventory_overview(?string $userId = null): array {
    $warehouses = $userId !== null ? get_user_warehouses($userId) : get_all_warehouses();
    $rows = [];
    foreach ($warehouses as $wh) {
        if (($wh['status'] ?? 'active') === 'archived') {
            continue;
        }
        $capacity = (float) $wh['capacity'];
        $used = get_warehouse_capacity_used($wh['id']);
        if ($capacity > 0) {
            $pct = min(100, ($used / $capacity) * 100);
            $status = $pct < 15 ? 'critical' : ($pct < 35 ? 'low' : ($pct < 65 ? 'moderate' : 'healthy'));
        } else {
            $pct = 0.0;
            $status = 'unknown';
        }
        $rows[] = [
            'id' => $wh['id'],
            'name' => $wh['name'],
            'location' => $wh['location'] ?: $wh['name'],
            'capacity' => $capacity,
            'used' => $used,
            'pct' => $pct,
            'status' => $status,
        ];
    }
    usort($rows, fn($a, $b) => $b['used'] <=> $a['used']);
    return $rows;
}


/**
 * Incoming vs. outgoing quantity per calendar month for the last $months
 * months, taken from stock_movements, plus a reconstructed "available
 * stock" figure for each month-end. The reconstruction starts from today's
 * real total (sum of non-archived stock) and walks the signed movement
 * ledger backward — it is derived data, not a fabricated series, and the
 * most recent point always matches the live KPI total exactly.
 */
function get_monthly_supply_trend(int $months = 6, ?string $userId = null): array {
    $months = max(1, min(24, $months));
    $since = date('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months'));

    if (database_available()) {
        if ($userId !== null) {
            $rows = query_all(
                "SELECT DATE_FORMAT(createdAt, '%Y-%m') AS ym,
                        SUM(CASE WHEN quantityChange > 0 THEN quantityChange ELSE 0 END) AS incoming,
                        SUM(CASE WHEN quantityChange < 0 THEN -quantityChange ELSE 0 END) AS outgoing,
                        SUM(quantityChange) AS net
                 FROM stock_movements
                 WHERE createdAt >= ? AND type IN ('in','out','adjustment') AND userId = ?
                 GROUP BY ym
                 ORDER BY ym ASC",
                [$since, $userId]
            );
        } else {
            $rows = query_all(
                "SELECT DATE_FORMAT(createdAt, '%Y-%m') AS ym,
                        SUM(CASE WHEN quantityChange > 0 THEN quantityChange ELSE 0 END) AS incoming,
                        SUM(CASE WHEN quantityChange < 0 THEN -quantityChange ELSE 0 END) AS outgoing,
                        SUM(quantityChange) AS net
                 FROM stock_movements
                 WHERE createdAt >= ? AND type IN ('in','out','adjustment')
                 GROUP BY ym
                 ORDER BY ym ASC",
                [$since]
            );
        }
    } else {
        init_data();
        $byMonth = [];
        foreach ($_SESSION['data']['stock_movements'] ?? [] as $m) {
            if (!in_array($m['type'], ['in', 'out', 'adjustment'], true) || $m['createdAt'] < $since) {
                continue;
            }
            if ($userId !== null && ($m['userId'] ?? null) !== $userId) {
                continue;
            }
            $ym = substr($m['createdAt'], 0, 7);
            if (!isset($byMonth[$ym])) {
                $byMonth[$ym] = ['ym' => $ym, 'incoming' => 0.0, 'outgoing' => 0.0, 'net' => 0.0];
            }
            $change = (float) $m['quantityChange'];
            if ($change > 0) {
                $byMonth[$ym]['incoming'] += $change;
            } else {
                $byMonth[$ym]['outgoing'] += -$change;
            }
            $byMonth[$ym]['net'] += $change;
        }
        ksort($byMonth);
        $rows = array_values($byMonth);
    }

    $currentTotal = 0.0;
    $baseStocks = $userId !== null ? get_user_stocks($userId) : get_stocks();
    foreach ($baseStocks as $s) {
        if ($s['status'] !== 'archived') {
            $currentTotal += (float) $s['quantity'];
        }
    }

    $labels = [];
    $cursor = strtotime($since);
    for ($i = 0; $i < $months; $i++) {
        $labels[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }

    $byYm = [];
    foreach ($rows as $r) {
        $byYm[$r['ym']] = $r;
    }

    $netAfter = [];
    $running = 0.0;
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = $labels[$i];
        $netAfter[$ym] = $running;
        $running += (float) ($byYm[$ym]['net'] ?? 0);
    }

    $out = [];
    foreach ($labels as $ym) {
        $row = $byYm[$ym] ?? ['incoming' => 0.0, 'outgoing' => 0.0];
        $out[] = [
            'label' => date('M', strtotime($ym . '-01')),
            'incoming' => round((float) $row['incoming'], 1),
            'outgoing' => round((float) $row['outgoing'], 1),
            'available' => round(max(0, $currentTotal - $netAfter[$ym]), 1),
        ];
    }
    return $out;
}

/**
 * Same shape and same "walk backward from current total using net stock
 * movement" logic as get_monthly_supply_trend(), just bucketed by day
 * instead of by month. Powers the 7D/30D ranges on the dashboard's Rice
 * Supply Trend chart — real stock_movements rows, no placeholder data.
 */
function get_daily_supply_trend(int $days = 7, ?string $userId = null): array {
    $days = max(1, min(90, $days));
    $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

    if (database_available()) {
        if ($userId !== null) {
            $rows = query_all(
                "SELECT DATE(createdAt) AS ymd,
                        SUM(CASE WHEN quantityChange > 0 THEN quantityChange ELSE 0 END) AS incoming,
                        SUM(CASE WHEN quantityChange < 0 THEN -quantityChange ELSE 0 END) AS outgoing,
                        SUM(quantityChange) AS net
                 FROM stock_movements
                 WHERE createdAt >= ? AND type IN ('in','out','adjustment') AND userId = ?
                 GROUP BY ymd
                 ORDER BY ymd ASC",
                [$since, $userId]
            );
        } else {
            $rows = query_all(
                "SELECT DATE(createdAt) AS ymd,
                        SUM(CASE WHEN quantityChange > 0 THEN quantityChange ELSE 0 END) AS incoming,
                        SUM(CASE WHEN quantityChange < 0 THEN -quantityChange ELSE 0 END) AS outgoing,
                        SUM(quantityChange) AS net
                 FROM stock_movements
                 WHERE createdAt >= ? AND type IN ('in','out','adjustment')
                 GROUP BY ymd
                 ORDER BY ymd ASC",
                [$since]
            );
        }
    } else {
        init_data();
        $byDay = [];
        foreach ($_SESSION['data']['stock_movements'] ?? [] as $m) {
            if (!in_array($m['type'], ['in', 'out', 'adjustment'], true) || $m['createdAt'] < $since) {
                continue;
            }
            if ($userId !== null && ($m['userId'] ?? null) !== $userId) {
                continue;
            }
            $ymd = substr($m['createdAt'], 0, 10);
            if (!isset($byDay[$ymd])) {
                $byDay[$ymd] = ['ymd' => $ymd, 'incoming' => 0.0, 'outgoing' => 0.0, 'net' => 0.0];
            }
            $change = (float) $m['quantityChange'];
            if ($change > 0) {
                $byDay[$ymd]['incoming'] += $change;
            } else {
                $byDay[$ymd]['outgoing'] += -$change;
            }
            $byDay[$ymd]['net'] += $change;
        }
        ksort($byDay);
        $rows = array_values($byDay);
    }

    $currentTotal = 0.0;
    $baseStocks = $userId !== null ? get_user_stocks($userId) : get_stocks();
    foreach ($baseStocks as $s) {
        if ($s['status'] !== 'archived') {
            $currentTotal += (float) $s['quantity'];
        }
    }

    $labels = [];
    $cursor = strtotime($since);
    for ($i = 0; $i < $days; $i++) {
        $labels[] = date('Y-m-d', $cursor);
        $cursor = strtotime('+1 day', $cursor);
    }

    $byYmd = [];
    foreach ($rows as $r) {
        $byYmd[$r['ymd']] = $r;
    }

    $netAfter = [];
    $running = 0.0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $ymd = $labels[$i];
        $netAfter[$ymd] = $running;
        $running += (float) ($byYmd[$ymd]['net'] ?? 0);
    }

    $labelFormat = $days > 14 ? 'M j' : 'D';
    $out = [];
    foreach ($labels as $ymd) {
        $row = $byYmd[$ymd] ?? ['incoming' => 0.0, 'outgoing' => 0.0];
        $out[] = [
            'label' => date($labelFormat, strtotime($ymd)),
            'incoming' => round((float) $row['incoming'], 1),
            'outgoing' => round((float) $row['outgoing'], 1),
            'available' => round(max(0, $currentTotal - $netAfter[$ymd]), 1),
        ];
    }
    return $out;
}


/**
 * Most recent procurement orders, each joined with its stock's unitPrice
 * so an estimated peso amount (quantity x unitPrice) can be shown — the
 * orders table itself only stores quantity, not a locked-in price.
 */
function get_recent_procurement_orders_detailed(int $limit = 6, ?string $userId = null): array {
    if (database_available()) {
        if ($userId !== null) {
            return query_all(
                'SELECT po.*, s.unitPrice
                 FROM procurement_orders po
                 LEFT JOIN stocks s ON s.id = po.stockId
                 WHERE po.requesterId = ? OR po.supplierId = ?
                 ORDER BY po.createdAt DESC
                 LIMIT ' . (int) $limit,
                [$userId, $userId]
            );
        }
        return query_all(
            'SELECT po.*, s.unitPrice
             FROM procurement_orders po
             LEFT JOIN stocks s ON s.id = po.stockId
             ORDER BY po.createdAt DESC
             LIMIT ' . (int) $limit
        );
    }

    $stocksById = array_column(get_stocks(), null, 'id');
    $source = $userId !== null ? get_user_procurement_orders($userId) : get_procurement_orders();
    $rows = array_slice($source, 0, $limit);
    return array_map(function ($o) use ($stocksById) {
        $o['unitPrice'] = $stocksById[$o['stockId']]['unitPrice'] ?? null;
        return $o;
    }, $rows);
}


/** Count and estimated peso value of every procurement order not yet completed. */
function get_active_procurement_summary(?string $userId = null): array {
    $orders = $userId !== null ? get_user_procurement_orders($userId) : get_procurement_orders();
    $active = array_values(array_filter($orders, fn($o) => !in_array($o['status'], ['completed', 'cancelled'], true)));
    // Uses each order's own snapshotted totalPrice (set once, at order-
    // creation time — see create_procurement_order()) rather than
    // recomputing from the source stock's *current* price, which could
    // have changed since the order was placed. Recomputing here would
    // silently reintroduce the exact bug Critical Improvement 5 fixed:
    // an order's value drifting whenever the underlying listing's price
    // changes.
    $totalValue = array_sum(array_column($active, 'totalPrice'));
    return ['count' => count($active), 'totalValue' => $totalValue];
}


/** Orders still on the way: placed but not yet marked delivered/completed. */
function get_pending_deliveries_summary(?string $userId = null): array {
    $orders = $userId !== null ? get_user_procurement_orders($userId) : get_procurement_orders();
    $pending = array_values(array_filter($orders, fn($o) => in_array($o['status'], ['ordered', 'in_transit'], true)));
    $inTransit = count(array_filter($pending, fn($o) => $o['status'] === 'in_transit'));
    return ['count' => count($pending), 'inTransit' => $inTransit];
}


/**
 * Average rice price now vs. ~$days ago, reconstructed from price_history.
 * For each currently-available stock: if it has a recorded price change
 * before the cutoff, use the price that change set; otherwise (no history
 * at all) its price hasn't moved, so today's price stands in for both.
 * Returns previous=null (no comparison, not zero) when there are no
 * available stocks to average at all.
 */
function get_avg_rice_price_trend(int $days = 30, ?string $userId = null): array {
    $baseStocks = $userId !== null ? get_user_stocks($userId) : get_stocks();
    $stocks = array_values(array_filter($baseStocks, fn($s) => $s['status'] === 'available'));
    $count = count($stocks);
    if ($count === 0) {
        return ['current' => 0.0, 'previous' => null, 'changePct' => null];
    }

    $current = array_sum(array_map(fn($s) => (float) $s['unitPrice'], $stocks)) / $count;
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $priorSum = 0.0;
    foreach ($stocks as $stock) {
        $priorPrice = (float) $stock['unitPrice'];
        foreach (get_price_history($stock['id']) as $entry) {
            if ($entry['createdAt'] <= $cutoff) {
                $priorPrice = (float) $entry['newPrice'];
                break;
            }
            $priorPrice = (float) $entry['oldPrice'];
        }
        $priorSum += $priorPrice;
    }
    $previous = $priorSum / $count;
    $changePct = $previous > 0 ? (($current - $previous) / $previous) * 100 : null;

    return ['current' => $current, 'previous' => $previous, 'changePct' => $changePct];
}


/**
 * Rule-based supply alerts for the AI Supply Intelligence card — the same
 * "deterministic checks over real numbers" approach the rest of KAMALIG
 * already uses for low-stock notifications, not a live model call. Every
 * figure quoted is computed from real warehouse/stock/movement rows.
 */
function get_ai_supply_insights(?string $userId = null): array {
    $warehouses = get_warehouse_inventory_overview($userId);
    if (empty($warehouses)) {
        return [[
            'level' => 'info',
            'title' => 'Not Enough Data Yet',
            'message' => 'No active warehouses are recorded yet, so supply-level analysis is not available. Add a warehouse and inventory to enable AI Supply Intelligence.',
            'confidence' => null,
        ]];
    }

    $insights = [];
    $movementTotals = get_movement_totals(30, $userId);
    $dailyOutRate = $movementTotals['out']['totalQuantity'] > 0 ? $movementTotals['out']['totalQuantity'] / 30 : 0.0;

    // Warehouses with no capacity set report pct=0/status='unknown' (see
    // get_warehouse_inventory_overview()) — they can't be judged low, high,
    // or healthy, so they're excluded from every band check below and
    // called out separately instead of silently counting as "fine".
    $known = array_values(array_filter($warehouses, fn($w) => $w['status'] !== 'unknown'));
    $unknown = array_values(array_filter($warehouses, fn($w) => $w['status'] === 'unknown'));

    $atRisk = array_values(array_filter($known, fn($w) => in_array($w['status'], ['critical', 'low'], true)));

    // Same admin-configurable line notifications already alert a warehouse
    // owner against (see maybe_notify_warehouse_capacity() /
    // admin/settings/) — kept in sync so this panel never contradicts a
    // "nearing capacity" notification the owner already has.
    $alertPct = warehouse_capacity_alert_threshold();
    $overCapacity = array_values(array_filter($known, fn($w) => $w['pct'] >= $alertPct));

    if (!empty($atRisk)) {
        usort($atRisk, fn($a, $b) => $a['pct'] <=> $b['pct']);
        $worst = $atRisk[0];
        // $dailyOutRate is the account/system-wide 30-day distribution
        // pace, not this specific warehouse's — the closest proxy
        // available without per-warehouse movement history, so treat the
        // days-left figure as an estimate, not a precise forecast.
        $daysLeft = $dailyOutRate > 0 ? (int) floor($worst['used'] / $dailyOutRate) : null;
        $others = count($atRisk) - 1;
        $insights[] = [
            'level' => 'critical',
            'title' => 'Potential Supply Shortage',
            'message' => $worst['location'] . ' is holding ' . number_format($worst['used'], 0) . ' MT against a '
                . number_format($worst['capacity'], 0) . ' MT capacity (' . round($worst['pct']) . '% full).'
                . ($daysLeft !== null
                    ? ' At the current distribution pace it may be exhausted in about ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . '.'
                    : ' No recent distribution activity to project a depletion date from.')
                . ($others > 0 ? ' ' . $others . ' other location' . ($others === 1 ? '' : 's') . ' ' . ($others === 1 ? 'is' : 'are') . ' also running low.' : ''),
            'confidence' => null, // was a hard-coded number
        ];
    }

    if (!empty($overCapacity)) {
        usort($overCapacity, fn($a, $b) => $b['pct'] <=> $a['pct']);
        $fullest = $overCapacity[0];
        $others = count($overCapacity) - 1;
        $insights[] = [
            'level' => 'warning',
            'title' => 'Warehouse Nearing Capacity',
            'message' => $fullest['location'] . ' is at ' . round($fullest['pct']) . '% of its '
                . number_format($fullest['capacity'], 0) . ' MT capacity, above the ' . round($alertPct) . '% alert threshold.'
                . ($others > 0 ? ' ' . $others . ' other location' . ($others === 1 ? '' : 's') . ' ' . ($others === 1 ? 'is' : 'are') . ' also near capacity.' : '')
                . ' Consider transferring stock out or expanding capacity.',
            'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
        ];
    }

    $deficit = 0.0;
    foreach ($known as $w) {
        if (in_array($w['status'], ['critical', 'low'], true) && $w['capacity'] > 0) {
            $target = $w['capacity'] * 0.65;
            if ($target > $w['used']) {
                $deficit += $target - $w['used'];
            }
        }
    }
    if ($deficit > 0) {
        $insights[] = [
            'level' => 'recommendation',
            'title' => 'Procurement Recommendation',
            'message' => 'Based on current warehouse levels, procuring approximately ' . number_format($deficit, 0) . ' MT of rice would bring low and critical locations back to a healthy stock band.',
            'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
        ];
    }

    if (!empty($unknown)) {
        $allUnknown = count($unknown) === count($warehouses);
        $insights[] = [
            'level' => 'info',
            'title' => $allUnknown ? 'Capacity Not Set' : 'Partial Capacity Data',
            'message' => $allUnknown
                ? 'None of your warehouses have a capacity value set, so shortage and overstock analysis cannot run. Add a capacity to each warehouse to enable it.'
                : count($unknown) . ' of ' . count($warehouses) . ' warehouse(s) have no capacity set and are excluded from the analysis above. Add a capacity to include them.',
            'confidence' => null,
        ];
    }

    // Only claim stability once every warehouse that *can* be judged is
    // both above the low-stock band and below the capacity alert line —
    // previously this fired whenever nothing was "low/critical", which
    // wrongly included warehouses sitting well past the capacity alert
    // threshold, or accounts where capacity just wasn't set at all.
    if (empty($atRisk) && empty($overCapacity) && !empty($known)) {
        $insights[] = [
            'level' => 'positive',
            'title' => 'Supply Stability',
            'message' => 'All tracked warehouses with capacity data are currently in the moderate-to-healthy stock band, with none below the low-stock threshold or above the ' . round($alertPct) . '% capacity alert threshold.',
            'confidence' => null, // was a hard-coded number; trend indicators have no measured confidence
        ];
    }

    return $insights;
}


/** Human-readable label for an activity_log action code, for the dashboard feed. */
function describe_activity_action(string $action): string {
    static $map = [
        'stock.create' => 'New stock listing created',
        'stock.update' => 'Stock listing updated',
        'stock.in' => 'Stock received into inventory',
        'stock.out' => 'Stock distributed from inventory',
        'stock.adjustment' => 'Stock quantity adjusted',
        'stock.transfer' => 'Stock moved between warehouses',
        'stock.archive' => 'Stock listing archived',
        'stock.restore' => 'Stock listing restored',
        'warehouse.create' => 'Warehouse added',
        'warehouse.update' => 'Warehouse details updated',
        'warehouse.archive' => 'Warehouse archived',
        'warehouse.restore' => 'Warehouse restored',
        'request.create' => 'Procurement request submitted',
        'request.accept' => 'Procurement request accepted',
        'request.reject' => 'Procurement request declined',
        'order.create' => 'Procurement order created',
        'order.in_transit' => 'Procurement order marked in transit',
        'order.delivered' => 'Procurement order marked delivered',
        'order.completed' => 'Procurement order completed',
        'document.pending' => 'Document submitted for review',
        'document.verified' => 'Document verified',
        'document.rejected' => 'Document rejected',
        'document.correct' => 'Document details corrected',
        'account.approve' => 'Account approved',
        'account.reject' => 'Account application rejected',
        'account.suspend' => 'Account suspended',
        'account.reactivate' => 'Account reactivated',
        'account.role_change' => 'Account role changed',
        'variety.add' => 'Rice variety added',
        'variety.delete' => 'Rice variety removed',
        'settings.update' => 'System settings updated',
        'permissions.update' => 'Role permissions updated',
        'profile.update' => 'Profile updated',
        'profile.password_change' => 'Password changed',
        'auth.password_reset' => 'Password reset completed',
    ];
    if (isset($map[$action])) {
        return $map[$action];
    }
    return ucfirst(str_replace(['.', '_'], ' ', $action));
}

/** Compact "N minutes/hours/days ago" for a MySQL datetime string. */
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $n = (int) floor($diff / 60);
        return $n . ' min' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $n = (int) floor($diff / 3600);
        return $n . ' hour' . ($n === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 2592000) {
        $n = (int) floor($diff / 86400);
        return $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', strtotime($datetime));
}
