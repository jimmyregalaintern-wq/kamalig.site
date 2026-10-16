<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_role('admin');

$entries = get_activity_log(1000);

$rows = [];
foreach ($entries as $entry) {
    $rows[] = [
        $entry['createdAt'],
        $entry['userId'] ?? 'system',
        $entry['action'],
        $entry['entityType'],
        $entry['entityId'] ?? '',
        $entry['details'] ?? '',
    ];
}

export_csv('audit_trail.csv', ['When', 'User', 'Action', 'Entity Type', 'Entity ID', 'Details'], $rows);
