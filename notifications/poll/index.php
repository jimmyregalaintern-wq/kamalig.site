<?php
// Lightweight JSON endpoint polled by the notification bell (assets/notifications-live.js).
// It is a passive background request: it must NOT extend the idle-session timer,
// otherwise an open tab would keep a user signed in forever (see includes/bootstrap.php).
define('KAMALIG_PASSIVE_REQUEST', true);
require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$userId = current_user_id() ?? '';
$items = [];
foreach (get_unread_notifications($userId, 5) as $note) {
    $items[] = [
        'id' => (string) $note['id'],
        'title' => (string) $note['title'],
        'message' => (string) $note['message'],
    ];
}

echo json_encode([
    'ok' => true,
    'count' => count_unread_notifications($userId),
    'items' => $items,
    'csrf' => csrf_token(),
]);
