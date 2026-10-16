<?php
// Database connection settings — read from .env (see includes/env.php)
require_once __DIR__ . '/env.php';
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'rice_ms');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

/**
 * Transaction helpers (Fix Guide item 3: Accept-order must be atomic).
 *
 * These can now be NESTED. The outermost begin_transaction() opens a real PDO
 * transaction; every inner begin_transaction() becomes a SAVEPOINT, so a helper
 * such as reserve_stock_quantity() or create_procurement_order() can keep its
 * own begin/commit/rollback and still be called from inside a bigger
 * transaction without PDO throwing "There is already an active transaction".
 * An inner rollback undoes only the inner work; the outer caller decides
 * whether the whole thing commits. In session-fallback mode (no database) all
 * three are no-ops, as before.
 *
 * Usage pattern (unchanged):
 *   begin_transaction();
 *   try {
 *       query_execute(...);
 *       commit_transaction();
 *   } catch (Throwable $e) {
 *       rollback_transaction();
 *       throw $e;
 *   }
 */
function &transaction_stack(): array {
    static $stack = [];
    return $stack;
}

function begin_transaction(): void {
    if (!database_available()) {
        return;
    }
    $pdo = db();
    $stack = &transaction_stack();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $stack = ['root'];
        return;
    }
    // Already inside a transaction (ours, or a raw PDO one): use a savepoint.
    $name = 'kamalig_sp' . count($stack);
    $pdo->exec('SAVEPOINT ' . $name);
    $stack[] = $name;
}

/**
 * Commit the current transaction level. No-op in session fallback mode.
 */
function commit_transaction(): void {
    if (!database_available()) {
        return;
    }
    $pdo = db();
    $stack = &transaction_stack();
    $top = array_pop($stack);
    if ($top === null || $top === 'root') {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $stack = [];
        return;
    }
    $pdo->exec('RELEASE SAVEPOINT ' . $top);
}

/**
 * Roll back the current transaction level. No-op in session fallback mode.
 */
function rollback_transaction(): void {
    if (!database_available()) {
        return;
    }
    $pdo = db();
    $stack = &transaction_stack();
    $top = array_pop($stack);
    if ($top === null || $top === 'root') {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $stack = [];
        return;
    }
    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $top);
    $pdo->exec('RELEASE SAVEPOINT ' . $top);
}
