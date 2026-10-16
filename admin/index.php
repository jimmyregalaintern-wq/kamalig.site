<?php
// "Admin Home" was folded into the main Dashboard (see redesign brief,
// section 2) — dashboard/index.php now renders the full admin view when
// user_role() === 'admin'. This file is kept only so old links/bookmarks
// to admin/ still land somewhere, instead of 404ing.
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');
header('Location: ' . url('dashboard/'));
exit;
