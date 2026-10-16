<?php
/**
 * Data Export/Import (Phase 7+) — comprehensive backup and restore workflows
 * for data portability and disaster recovery.
 * 
 * Exports include:
 * - All stocks and inventory (including history and movements)
 * - All users and their profiles
 * - All procurement orders and requests
 * - All warehouses and transfers
 * - All documents and verification status
 * - All settings and permissions
 * - Complete activity audit trail
 * 
 * Imports restore from a backup with validation and conflict detection.
 */

const BACKUP_VERSION = '1.0';
const BACKUP_SCHEMA = [
    'version' => '1.0',
    'format' => 'json',
    'sections' => [
        'metadata', 'users', 'stocks', 'stock_movements', 'price_history',
        'requests', 'procurement_orders', 'documents', 'warehouses',
        'notifications', 'settings', 'permissions', 'activity_log',
        // Added with the payment system: without these a restore loses every order's payment.
        'checkouts', 'payments', 'payment_accounts', 'cart_items',
    ],
];

/**
 * Tables restored by a database import, in FOREIGN-KEY-SAFE order (parents before
 * children). Keys are backup section names; values are table names. Both come from
 * this constant only, never from the uploaded file, so they are safe to put in SQL.
 */
const BACKUP_RESTORE_ORDER = [
    'users' => 'users',
    'warehouses' => 'warehouses',
    'stocks' => 'stocks',
    'price_history' => 'price_history',
    'checkouts' => 'checkouts',
    'requests' => 'requests',
    'procurement_orders' => 'procurement_orders',
    'stock_movements' => 'stock_movements',
    'payments' => 'payments',
    'payment_accounts' => 'payment_accounts',
    'cart_items' => 'cart_items',
    'documents' => 'documents',
    'notifications' => 'notifications',
    'activity_log' => 'activity_log',
];

/** All rows of a table, or [] when the table does not exist in this install. */
function backup_table_rows(string $table): array {
    if (!database_available() || !in_array($table, BACKUP_RESTORE_ORDER, true)) {
        return [];
    }
    try {
        return query_all('SELECT * FROM `' . $table . '`');
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Create a complete system backup as JSON. Includes all data needed to
 * restore a full copy of the database/session state to another system.
 * Suitable for archival, migration, or disaster recovery.
 */
function create_system_backup(): array {
    $backup = [
        'version' => BACKUP_VERSION,
        'createdAt' => date('Y-m-d\TH:i:s\Z'),
        'systemUrl' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'totalRecords' => 0,
        'metadata' => [
            'stocks' => count(get_stocks()),
            'users' => count(get_all_users()),
            'orders' => count(get_procurement_orders()),
            'documents' => count(get_pending_documents()),
            'warehouses' => count(get_all_warehouses()),
        ],
    ];

    // Users (include all fields for complete identity/auth restoration, except one-time codes)
    $backup['users'] = array_map(function ($u) {
        unset($u['otp_code'], $u['otp_expires_at']);
        return $u;
    }, get_all_users());
    $backup['totalRecords'] += count($backup['users']);

    // Stocks with full history
    $backup['stocks'] = get_stocks();
    $backup['stock_movements'] = [];
    foreach ($backup['stocks'] as $stock) {
        $backup['stock_movements'] = array_merge(
            $backup['stock_movements'],
            get_stock_movements($stock['id'])
        );
    }
    $backup['totalRecords'] += count($backup['stocks']) + count($backup['stock_movements']);

    // Procurement pipeline
    $backup['requests'] = get_requests();
    $backup['procurement_orders'] = get_procurement_orders();
    $backup['totalRecords'] += count($backup['requests']) + count($backup['procurement_orders']);

    // Price history (for Transparency traceability)
    $backup['price_history'] = [];
    foreach ($backup['stocks'] as $stock) {
        $backup['price_history'] = array_merge(
            $backup['price_history'],
            get_price_history($stock['id'])
        );
    }
    $backup['totalRecords'] += count($backup['price_history']);

    // Warehouses and capacity tracking
    $backup['warehouses'] = get_all_warehouses();
    $backup['totalRecords'] += count($backup['warehouses']);

    // Documents (verification trail)
    $allDocs = [];
    if (database_available()) {
        $allDocs = query_all('SELECT * FROM documents ORDER BY uploadedAt DESC');
    } else {
        init_data();
        $allDocs = $_SESSION['data']['documents'] ?? [];
    }
    $backup['documents'] = $allDocs;
    $backup['totalRecords'] += count($backup['documents']);

    // Notifications (user communication history)
    $allNotifs = [];
    if (database_available()) {
        $allNotifs = query_all('SELECT * FROM notifications ORDER BY createdAt DESC');
    } else {
        init_data();
        $allNotifs = $_SESSION['data']['notifications'] ?? [];
    }
    $backup['notifications'] = $allNotifs;
    $backup['totalRecords'] += count($backup['notifications']);

    // Settings (admin configuration)
    $backup['settings'] = [];
    if (database_available()) {
        $settingRows = query_all('SELECT * FROM settings');
        foreach ($settingRows as $row) {
            $backup['settings'][$row['settingKey']] = $row['settingValue'];
        }
    } else {
        init_data();
        $backup['settings'] = $_SESSION['data']['settings'] ?? [];
    }

    // Permissions (role-based access control)
    $backup['permissions'] = get_permission_matrix();

    // Activity audit trail (complete "who did what" record)
    $backup['activity_log'] = database_available() ? backup_table_rows('activity_log') : get_activity_log(10000);
    $backup['totalRecords'] += count($backup['activity_log']);

    // Payment system (orders without their payments cannot be restored properly)
    foreach (['checkouts', 'payments', 'payment_accounts', 'cart_items'] as $commerceTable) {
        $backup[$commerceTable] = backup_table_rows($commerceTable);
        $backup['totalRecords'] += count($backup[$commerceTable]);
    }

    return $backup;
}

/**
 * Export a backup as JSON and stream it to the browser as a downloadable
 * file. Called by admin/backup/export.
 */
function export_backup_to_download(string $filename = null): void {
    $backup = create_system_backup();
    $filename = $filename ?? 'kamalig_backup_' . date('Y-m-d_H-i-s') . '.json';

    // BUGFIX: admin/backup/index.php's "Last Backup" stat reads a marker
    // file that nothing ever wrote — it showed "Never" on every install,
    // permanently, regardless of how many backups had actually been taken.
    // Touching it here (same uploads/ directory every other file-writing
    // function in this codebase uses, via the __DIR__-relative path
    // convention from includes/uploads.php) is what makes that stat real.
    @file_put_contents(__DIR__ . '/../uploads/.last_backup', (string) time());

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen(json_encode($backup)));

    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Validate a backup file's structure before attempting import. Returns
 * ['valid' => true] or ['valid' => false, 'errors' => [...]].
 */
function validate_backup_structure(array $backup): array {
    $errors = [];

    if (!isset($backup['version'])) {
        $errors[] = 'Missing backup version';
    } elseif ($backup['version'] !== BACKUP_VERSION) {
        // BUGFIX: "{BACKUP_VERSION}" inside a double-quoted string is not
        // valid PHP interpolation syntax for a constant — {$var} interpolates
        // variables, not bare constant names, so this printed the literal
        // text "{BACKUP_VERSION}" instead of the actual expected version.
        // An admin troubleshooting a failed restore would see "(expected
        // {BACKUP_VERSION})" instead of "(expected 1.0)" — unhelpful right
        // when the message needs to be clearest.
        $errors[] = "Incompatible backup version {$backup['version']} (expected " . BACKUP_VERSION . ")";
    }

    if (!isset($backup['createdAt'])) {
        $errors[] = 'Missing backup timestamp';
    }

    $requiredSections = ['users', 'stocks', 'requests', 'procurement_orders'];
    foreach ($requiredSections as $section) {
        if (!isset($backup[$section]) || !is_array($backup[$section])) {
            $errors[] = "Missing or invalid section: $section";
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

/** Column names of a table (cached). Used to restore only columns that really exist. */
function backup_table_columns(string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_column(query_all('SHOW COLUMNS FROM `' . $table . '`'), 'Field');
    }
    return $cache[$table];
}

/** Primary key column names of a table (cached). */
function backup_table_primary_key(string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_column(query_all("SHOW KEYS FROM `" . $table . "` WHERE Key_name = 'PRIMARY'"), 'Column_name');
    }
    return $cache[$table];
}

/**
 * Restore one row. Returns 'inserted', 'updated' or 'skipped'.
 *  merge     : a row whose primary key already exists is left alone.
 *  overwrite : an existing row is updated with the backup's values.
 * Columns the table does not have are ignored; nothing in the row is ever used as SQL.
 */
function backup_restore_row(string $table, array $row, bool $overwrite): string {
    $known = backup_table_columns($table);
    $clean = [];
    foreach ($row as $col => $value) {
        if (!in_array($col, $known, true) || is_array($value)) {
            continue;
        }
        $clean[$col] = is_bool($value) ? (int) $value : $value;
    }
    $pk = backup_table_primary_key($table);
    if (!$clean || !$pk) {
        return 'skipped';
    }
    $where = [];
    $whereParams = [];
    foreach ($pk as $keyCol) {
        if (!array_key_exists($keyCol, $clean)) {
            return 'skipped';
        }
        $where[] = '`' . $keyCol . '` = ?';
        $whereParams[] = $clean[$keyCol];
    }
    $exists = query_one('SELECT 1 AS x FROM `' . $table . '` WHERE ' . implode(' AND ', $where), $whereParams) !== null;
    if ($exists && !$overwrite) {
        return 'skipped';
    }
    $cols = array_keys($clean);
    $quoted = array_map(fn($c) => '`' . $c . '`', $cols);
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    if ($exists) {
        $updates = [];
        foreach ($cols as $c) {
            if (!in_array($c, $pk, true)) {
                $updates[] = '`' . $c . '` = VALUES(`' . $c . '`)';
            }
        }
        if (!$updates) {
            return 'skipped';
        }
        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }
    query_execute($sql, array_values($clean));
    return $exists ? 'updated' : 'inserted';
}

/**
 * Database restore. ALL-OR-NOTHING: any row that cannot be restored (for example a
 * foreign key it needs is missing) aborts and rolls back the whole import and says
 * which table and row, instead of silently skipping rows and reporting success.
 */
function import_backup_database(array $backup, string $mode): array {
    $overwrite = $mode === 'overwrite' || $mode === 'replace';
    $result = ['success' => true, 'imported' => [], 'conflicts' => [], 'errors' => []];
    try {
        begin_transaction();
        foreach (BACKUP_RESTORE_ORDER as $section => $table) {
            $rows = $backup[$section] ?? [];
            $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    try {
                        $counts[backup_restore_row($table, $row, $overwrite)]++;
                    } catch (Throwable $e) {
                        throw new RuntimeException("Could not restore {$table} row " . ($row['id'] ?? '?') . ': ' . $e->getMessage());
                    }
                }
            }
            $result['imported'][$section] = $counts['inserted'] + $counts['updated'];
            if ($counts['skipped'] > 0) {
                $result['conflicts'][$section] = $counts['skipped'] . ' already existed';
            }
        }
        if (!empty($backup['settings']) && is_array($backup['settings'])) {
            foreach ($backup['settings'] as $key => $value) {
                set_setting($key, $value, 'system_import');
            }
        }
        if (!empty($backup['permissions']) && is_array($backup['permissions'])) {
            save_permission_matrix($backup['permissions']);
        }
        commit_transaction();
    } catch (Throwable $e) {
        rollback_transaction();
        error_log('KAMALIG backup restore failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Restore failed and was rolled back; nothing was changed. ' . $e->getMessage()];
    }

    log_activity(
        current_user_id(),
        'system.import',
        'backup',
        null,
        'Imported backup from ' . ($backup['systemUrl'] ?? 'unknown') . ' (' . ($backup['totalRecords'] ?? '?') . ' records, mode ' . $mode . ')'
    );
    return $result;
}

/**
 * Import a backup, restoring all data with conflict detection and
 * transaction safety. Call via admin/backup/import after validation.
 * 
 * Returns:
 * - ['success' => true, 'imported' => ['users' => N, 'stocks' => N, ...]]
 * - ['success' => false, 'error' => 'reason', 'conflict' => ...]
 */
function import_backup(array $backup, array $options = []): array {
    // Options: 'skipDuplicates' (skip records with matching IDs),
    // 'overwrite' (replace existing records), 'merge' (keep existing, add new)
    $mode = $options['mode'] ?? 'merge'; // default: don't overwrite
    $skipDuplicates = $mode === 'merge';

    $result = ['success' => true, 'imported' => [], 'conflicts' => [], 'errors' => []];

    // With a real database, use the schema-driven restore below: every column, in
    // foreign-key-safe order, all-or-nothing. The older per-record code that follows
    // is only used in session-fallback mode (no database).
    if (database_available()) {
        return import_backup_database($backup, $mode);
    }

    try {
        begin_transaction();

        // Import users
        $result['imported']['users'] = 0;
        if (!empty($backup['users'])) {
            foreach ($backup['users'] as $user) {
                if ($skipDuplicates && get_user_by_id($user['id'])) {
                    $result['conflicts']['users'][] = $user['id'];
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO users (id, email, password, name, role, company, phone, location, verified, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE name = VALUES(name), company = VALUES(company), role = VALUES(role)',
                            [
                                $user['id'], $user['email'], $user['password'] ?? null, $user['name'],
                                $user['role'], $user['company'] ?? '', $user['phone'] ?? '', $user['location'] ?? '',
                                $user['verified'] ? 1 : 0, $user['status'] ?? 'pending',
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['users'][$user['id']] = $user;
                    }
                    $result['imported']['users']++;
                } catch (Exception $e) {
                    $result['errors'][] = "Failed to import user {$user['id']}: " . $e->getMessage();
                }
            }
        }

        // Import stocks (with validation)
        $result['imported']['stocks'] = 0;
        if (!empty($backup['stocks'])) {
            foreach ($backup['stocks'] as $stock) {
                if ($skipDuplicates && get_stock_by_id($stock['id'])) {
                    $result['conflicts']['stocks'][] = $stock['id'];
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO stocks (id, userId, variety, quantity, unit, baseQuantityKg, unitPrice, source, countryOfOrigin, status, createdAt, updatedAt, supplierName, description, imageUrl, batchNumber, warehouseId, reservedQuantity, reservedQuantityKg) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updatedAt = VALUES(updatedAt)',
                            [
                                $stock['id'], $stock['userId'], $stock['variety'], $stock['quantity'], $stock['unit'],
                                $stock['baseQuantityKg'] ?? unit_to_kg($stock['quantity'], $stock['unit']),
                                $stock['unitPrice'], $stock['source'], $stock['countryOfOrigin'], $stock['status'],
                                $stock['createdAt'], $stock['updatedAt'], $stock['supplierName'] ?? '', $stock['description'] ?? '',
                                $stock['imageUrl'] ?? null, $stock['batchNumber'] ?? null, $stock['warehouseId'] ?? null,
                                $stock['reservedQuantity'] ?? 0, $stock['reservedQuantityKg'] ?? 0,
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['stocks'][] = $stock;
                    }
                    $result['imported']['stocks']++;
                } catch (Exception $e) {
                    $result['errors'][] = "Failed to import stock {$stock['id']}: " . $e->getMessage();
                }
            }
        }

        // Import stock movements (for Inventory History traceability)
        $result['imported']['stock_movements'] = 0;
        if (!empty($backup['stock_movements'])) {
            foreach ($backup['stock_movements'] as $movement) {
                if ($skipDuplicates && query_one('SELECT id FROM stock_movements WHERE id = ?', [$movement['id']])) {
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO stock_movements (id, stockId, userId, type, quantityChange, quantityChangeKg, quantityBefore, quantityBeforeKg, quantityAfter, quantityAfterKg, reason, reasonCategory, procurementOrderId, batchNumber, fromWarehouseId, toWarehouseId, createdAt) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $movement['id'], $movement['stockId'], $movement['userId'], $movement['type'],
                                $movement['quantityChange'], $movement['quantityChangeKg'],
                                $movement['quantityBefore'], $movement['quantityBeforeKg'],
                                $movement['quantityAfter'], $movement['quantityAfterKg'],
                                $movement['reason'] ?? null, $movement['reasonCategory'] ?? null,
                                $movement['procurementOrderId'] ?? null, $movement['batchNumber'] ?? null,
                                $movement['fromWarehouseId'] ?? null, $movement['toWarehouseId'] ?? null,
                                $movement['createdAt'],
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['stock_movements'][] = $movement;
                    }
                    $result['imported']['stock_movements']++;
                } catch (Exception $e) {
                    // Non-critical: skip movements if they can't be imported
                }
            }
        }

        // Import procurement orders and requests
        $result['imported']['requests'] = 0;
        if (!empty($backup['requests'])) {
            foreach ($backup['requests'] as $request) {
                if ($skipDuplicates && get_request_by_id($request['id'])) {
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO requests (id, stockId, requesterId, supplierId, variety, quantityRequested, unit, notes, status, createdAt, updatedAt, requesterName, supplierName) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE status = VALUES(status)',
                            [
                                $request['id'], $request['stockId'], $request['requesterId'], $request['supplierId'],
                                $request['variety'], $request['quantityRequested'], $request['unit'],
                                $request['notes'] ?? '', $request['status'], $request['createdAt'], $request['updatedAt'],
                                $request['requesterName'] ?? '', $request['supplierName'] ?? '',
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['requests'][] = $request;
                    }
                    $result['imported']['requests']++;
                } catch (Exception $e) {
                    $result['errors'][] = "Failed to import request {$request['id']}: " . $e->getMessage();
                }
            }
        }

        $result['imported']['orders'] = 0;
        if (!empty($backup['procurement_orders'])) {
            foreach ($backup['procurement_orders'] as $order) {
                if ($skipDuplicates && get_procurement_order_by_id($order['id'])) {
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO procurement_orders (id, requestId, stockId, batchNumber, requesterId, supplierId, requesterName, supplierName, variety, quantity, unit, unitPrice, totalPrice, status, createdAt, updatedAt, quantityShipped, quantityReceived, expectedDeliveryDate, actualDeliveryDate, carrier, trackingReference, proofOfDeliveryUrl, inspectionStatus, inspectionNotes, receivedStockId) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE status = VALUES(status), updatedAt = VALUES(updatedAt)',
                            [
                                $order['id'], $order['requestId'] ?? null, $order['stockId'], $order['batchNumber'] ?? null,
                                $order['requesterId'], $order['supplierId'], $order['requesterName'] ?? '', $order['supplierName'] ?? '',
                                $order['variety'], $order['quantity'], $order['unit'], $order['unitPrice'], $order['totalPrice'] ?? 0,
                                $order['status'], $order['createdAt'], $order['updatedAt'],
                                $order['quantityShipped'] ?? null, $order['quantityReceived'] ?? null,
                                $order['expectedDeliveryDate'] ?? null, $order['actualDeliveryDate'] ?? null,
                                $order['carrier'] ?? null, $order['trackingReference'] ?? null,
                                $order['proofOfDeliveryUrl'] ?? null, $order['inspectionStatus'] ?? null,
                                $order['inspectionNotes'] ?? null, $order['receivedStockId'] ?? null,
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['procurement_orders'][] = $order;
                    }
                    $result['imported']['orders']++;
                } catch (Exception $e) {
                    $result['errors'][] = "Failed to import order {$order['id']}: " . $e->getMessage();
                }
            }
        }

        // Import warehouses
        $result['imported']['warehouses'] = 0;
        if (!empty($backup['warehouses'])) {
            foreach ($backup['warehouses'] as $warehouse) {
                if ($skipDuplicates && get_warehouse_by_id($warehouse['id'])) {
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO warehouses (id, userId, name, location, capacity, status, createdAt, updatedAt) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), status = VALUES(status)',
                            [
                                $warehouse['id'], $warehouse['userId'], $warehouse['name'], $warehouse['location'] ?? '',
                                $warehouse['capacity'], $warehouse['status'], $warehouse['createdAt'], $warehouse['updatedAt'],
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['warehouses'][] = $warehouse;
                    }
                    $result['imported']['warehouses']++;
                } catch (Exception $e) {
                    // Non-critical
                }
            }
        }

        // Import documents
        $result['imported']['documents'] = 0;
        if (!empty($backup['documents'])) {
            foreach ($backup['documents'] as $doc) {
                if ($skipDuplicates && get_document_by_id($doc['id'])) {
                    continue;
                }
                try {
                    if (database_available()) {
                        query_execute(
                            'INSERT INTO documents (id, userId, relatedRequestId, documentType, fileName, storedFileName, fileUrl, status, businessName, permitNumber, taxId, expiryDate, uploadedAt) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE status = VALUES(status)',
                            [
                                $doc['id'], $doc['userId'], $doc['relatedRequestId'] ?? null, $doc['documentType'],
                                $doc['fileName'], $doc['storedFileName'] ?? null, $doc['fileUrl'] ?? null,
                                $doc['status'], $doc['businessName'] ?? null, $doc['permitNumber'] ?? null,
                                $doc['taxId'] ?? null, $doc['expiryDate'] ?? null, $doc['uploadedAt'],
                            ]
                        );
                    } else {
                        init_data();
                        $_SESSION['data']['documents'][] = $doc;
                    }
                    $result['imported']['documents']++;
                } catch (Exception $e) {
                    // Non-critical
                }
            }
        }

        // Import settings
        if (!empty($backup['settings']) && is_array($backup['settings'])) {
            foreach ($backup['settings'] as $key => $value) {
                try {
                    set_setting($key, $value, 'system_import');
                } catch (Exception $e) {
                    // Non-critical
                }
            }
        }

        // Import permissions
        if (!empty($backup['permissions']) && is_array($backup['permissions'])) {
            try {
                save_permission_matrix($backup['permissions']);
            } catch (Exception $e) {
                $result['errors'][] = 'Failed to import permissions: ' . $e->getMessage();
            }
        }

        commit_transaction();

        // Log the import event
        log_activity(
            current_user_id(),
            'system.import',
            'backup',
            null,
            'Imported backup from ' . ($backup['systemUrl'] ?? 'unknown') . ' (' . ($backup['totalRecords'] ?? '?') . ' records)'
        );

        return $result;
    } catch (Exception $e) {
        rollback_transaction();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
