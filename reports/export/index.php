<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$type = $_GET['type'] ?? '';

switch ($type) {
    case 'requests':
        $rows = [];
        foreach (get_requests() as $r) {
            $rows[] = [
                $r['id'], $r['requesterName'], $r['supplierName'], $r['variety'],
                $r['quantityRequested'], $r['unit'], $r['status'], $r['createdAt'], $r['updatedAt'],
            ];
        }
        export_csv('procurement_requests.csv', ['ID', 'Requester', 'Supplier', 'Variety', 'Quantity', 'Unit', 'Status', 'Created', 'Updated'], $rows);
        break;

    case 'stocks':
        $rows = [];
        foreach (get_stocks() as $s) {
            $rows[] = [
                $s['id'], $s['supplierName'], $s['variety'], $s['quantity'], $s['unit'],
                $s['unitPrice'], $s['source'], $s['countryOfOrigin'], $s['status'], $s['createdAt'],
            ];
        }
        export_csv('inventory_stocks.csv', ['ID', 'Owner', 'Variety', 'Quantity', 'Unit', 'Unit Price', 'Source', 'Country of Origin', 'Status', 'Listed'], $rows);
        break;

    case 'accounts':
        $rows = [];
        $accountStatusLabels = ['approved' => 'Active', 'pending' => 'Pending', 'rejected' => 'Rejected', 'suspended' => 'Suspended'];
        foreach (get_all_users() as $u) {
            $status = $u['status'] ?? ($u['verified'] ? 'approved' : 'pending');
            $rows[] = [
                $u['id'], $u['name'], $u['email'], $u['role'], $u['company'] ?? '',
                $accountStatusLabels[$status] ?? ucfirst($status),
            ];
        }
        export_csv('supplier_accounts.csv', ['ID', 'Name', 'Email', 'Role', 'Company', 'Status'], $rows);
        break;

    default:
        http_response_code(400);
        echo 'Unknown export type.';
        break;
}
