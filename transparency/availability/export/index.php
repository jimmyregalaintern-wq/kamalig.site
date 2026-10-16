<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_auth();

$sourceFilter = get_filter_value('source', 'all');
$varietyFilter = get_filter_value('variety', '');

$availability = get_supply_availability($varietyFilter !== '' ? $varietyFilter : null);
if ($sourceFilter !== 'all') {
    $availability = array_values(array_filter($availability, fn($row) => $row['source'] === $sourceFilter));
}

$rows = [];
foreach ($availability as $row) {
    $reservedKgForRow = max(0, $row['totalQuantityKg'] - $row['availableQuantityKg']);
    $rows[] = [
        $row['variety'], ucfirst($row['source']), $row['listingCount'],
        $row['totalQuantityKg'], $reservedKgForRow, $row['availableQuantityKg'], $row['avgPricePerKg'],
    ];
}

export_csv('supply_availability.csv', ['Variety', 'Source', 'Listings', 'Total Quantity (kg)', 'Reserved (kg)', 'Available (kg)', 'Avg Price (PHP/kg)'], $rows);
