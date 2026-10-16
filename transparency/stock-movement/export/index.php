<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_auth();

$filters = [
    'variety' => get_filter_value('variety', ''),
    'type' => get_filter_value('type', ''),
    'dateFrom' => get_filter_value('dateFrom', ''),
    'dateTo' => get_filter_value('dateTo', ''),
];

// Exports the whole filtered set, not just the current page — a CSV that
// only covered one 50-row page would be a confusing partial download.
$rows = [];
$page = 1;
do {
    $result = get_all_stock_movements($filters, $page, 200);
    foreach ($result['rows'] as $m) {
        $rows[] = [
            $m['createdAt'], $m['variety'], ucfirst($m['source']), $m['supplierName'],
            $m['batchNumber'] ?? '', ucfirst($m['type']), $m['quantityChange'], $m['unit'],
            $m['quantityBefore'], $m['quantityAfter'],
        ];
    }
    $page++;
} while (count($rows) < $result['total']);

export_csv('stock_movements.csv', ['Date', 'Variety', 'Source', 'Supplier', 'Batch', 'Type', 'Change', 'Unit', 'Before', 'After'], $rows);
