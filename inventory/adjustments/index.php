<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

// The separate Adjust screen was retired: corrections now go through Stock In
// or Stock Out with the reason "Recount correction", which is still stored as
// an 'adjustment' movement so history and reports stay consistent. This stub
// keeps old bookmarks and links working.
$stockId = trim($_GET['stockId'] ?? '');
header('Location: ' . url($stockId !== '' ? 'inventory/stock-in/?stockId=' . urlencode($stockId) : 'inventory/'));
exit;
