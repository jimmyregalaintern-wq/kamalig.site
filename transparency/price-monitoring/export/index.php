<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_auth();

$filters = [
    'variety' => get_filter_value('variety', ''),
    'dateFrom' => get_filter_value('dateFrom', ''),
    'dateTo' => get_filter_value('dateTo', ''),
];

// The main page caps "Recent Price Changes" at 150 rows for readability;
// the export isn't meant to scroll on screen, so it takes a much higher
// ceiling instead of trying to paginate a CSV download.
$history = get_recent_price_changes(5000, $filters);

$rows = [];
foreach ($history as $p) {
    $rows[] = [
        $p['createdAt'], $p['variety'], $p['supplierName'],
        $p['oldPrice'] ?? '', $p['newPrice'],
    ];
}

export_csv('price_changes.csv', ['Date', 'Variety', 'Supplier', 'Old Price', 'New Price'], $rows);
