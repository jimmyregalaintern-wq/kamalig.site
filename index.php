<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . url('dashboard/'));
    exit;
}

// Show public landing page for unauthenticated visitors
header('Location: ' . url('landing/'));
exit;
