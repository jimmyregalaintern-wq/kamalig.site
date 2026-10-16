<?php
require_once __DIR__ . '/../includes/bootstrap.php';

logout_user();
// The landing page is the app's sign-in entry point (login/index.php was
// retired — see landing/index.php); #access-login opens straight to its
// Sign In tab, same pattern already used by the OTP verification flow.
header('Location: ' . url('landing/') . '#access-login');
exit;
