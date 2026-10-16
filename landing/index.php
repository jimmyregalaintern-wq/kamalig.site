<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$title = 'Welcome to KAMALIG';
$error = '';
$registerError = '';
$activeTab = 'live';
$googlePendingProfile = $_SESSION['google_pending_profile'] ?? null;
if ($googlePendingProfile) {
    $activeTab = 'register'; // land directly on the register tab, role/documents step
}
$googleLoginError = $_SESSION['google_login_error'] ?? null;
unset($_SESSION['google_login_error']);
if ($googleLoginError) {
    $error = $googleLoginError;
    $activeTab = 'login';
}

// Login and registration are handled directly on this page (see the
// "Sign in" / "Register" tabs in the access panel below). The old standalone
// login/index.php and register/index.php pages have been retired now that
// every internal redirect (require_auth(), logout, OTP verification) points
// here instead — #access-login / #access-register deep-link straight to the
// matching tab. login/forgot.php and login/reset.php remain (password
// recovery isn't part of this page).
$formType = $_POST['form'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formType === 'login') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!captcha_check()) {
        $error = CAPTCHA_FAIL_MESSAGE;
        $activeTab = 'login';
    } else {
        try {
            login_user($email, $password);
            header('Location: ' . url('dashboard/'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $activeTab = 'login';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formType === 'register') {
    csrf_verify();

    // Google-assisted registration: login/google/callback/index.php
    // stashed the verified profile here after a first-time Google
    // sign-in with no matching account. Email is locked to what Google
    // reported (never trust a posted email to override it) and no
    // password is collected since this account will be Google-only.
    $googleProfile = $_SESSION['google_pending_profile'] ?? null;
    $isGoogleRegistration = $googleProfile !== null;

    $regEmail = $isGoogleRegistration ? $googleProfile['email'] : trim($_POST['email'] ?? '');
    $regPassword = $isGoogleRegistration ? null : trim($_POST['password'] ?? '');
    $regConfirmPassword = $isGoogleRegistration ? null : trim($_POST['confirmPassword'] ?? '');
    $regName = trim($_POST['name'] ?? ($isGoogleRegistration ? $googleProfile['name'] : ''));
    $regCompany = trim($_POST['company'] ?? '');
    if ($regCompany === '') {
        // Company / group is optional at signup (Fix Guide item 7: keep
        // registration simple). Fall back to the person's name so every
        // listing and document still has a display name.
        $regCompany = $regName;
    }
    $regRole = trim($_POST['role'] ?? 'supplier');

    // Only these three self-service account types exist on the "I am a"
    // select — admin accounts are provisioned directly, never through this
    // form. Whitelisting here stops a crafted request from registering as
    // role=admin and waiting for a distracted approval.
    $allowedRegistrationRoles = ['supplier', 'farmer', 'importer'];

    // Compliance documents are OPTIONAL at signup ("Upload now or later").
    // Whoever skips this uploads them after logging in, from Documents.
    // Trading stays locked until an admin approves them either way — see
    // includes/compliance.php. When files ARE attached they go through the
    // same secure-upload + OCR/AI pipeline as documents/upload/.
    $regBusinessName = trim($_POST['regBusinessDocName'] ?? '') ?: $regCompany;
    $hasPrimaryDoc = !empty($_FILES['regBusinessDoc']['name']);
    $hasSecondaryDoc = !empty($_FILES['regValidId']['name']);

    if (!$isGoogleRegistration && !captcha_check()) {
        $registerError = CAPTCHA_FAIL_MESSAGE;
    } elseif (empty($_POST['acceptTerms'])) {
        $registerError = 'Please read and accept the Privacy Notice and Terms of Use to create an account.';
    } elseif (!in_array($regRole, $allowedRegistrationRoles, true)) {
        $registerError = 'Please choose a valid account type.';
    } elseif (!$isGoogleRegistration && $regPassword !== $regConfirmPassword) {
        $registerError = 'Passwords do not match';
    } elseif (!$isGoogleRegistration && strlen($regPassword) < 10) {
        $registerError = 'Password must be at least 10 characters';
    } elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
        $registerError = 'Invalid email format';
    } elseif ($regName === '') {
        $registerError = 'Please enter your full name';
    } elseif (get_user_by_email($regEmail)) {
        $registerError = 'An account with this email already exists';
    } else {
        // Email fragment kept for readability when an admin is scanning raw
        // ids; the random suffix is what actually guarantees uniqueness.
        $userId = 'user_' . preg_replace('/[^a-z0-9]+/i', '', strtolower($regEmail)) . '_' . time() . '_' . bin2hex(random_bytes(4));
        try {
            $uploadDir = private_storage_dir(PRIVATE_KIND_DOCUMENTS);

            // Validate + move any optional file(s) to disk BEFORE creating
            // the user row, so a bad upload never leaves a half-registered
            // account behind.
            $primaryUpload = $hasPrimaryDoc ? handle_secure_document_upload($_FILES['regBusinessDoc'], $uploadDir) : null;
            $secondaryUpload = $hasSecondaryDoc ? handle_secure_document_upload($_FILES['regValidId'], $uploadDir) : null;

            $newUser = [
                'id' => $userId,
                'email' => $regEmail,
                'password' => $isGoogleRegistration ? null : password_hash($regPassword, PASSWORD_DEFAULT),
                'google_id' => $isGoogleRegistration ? $googleProfile['sub'] : null,
                'name' => $regName,
                'role' => $regRole,
                'company' => $regCompany,
                'phone' => '',
                'location' => '',
                // Consent is stored WITH the account (same INSERT): no account can
                // exist without a consent record (RA 10173, Fix Guide item 4).
                'consent_version' => PRIVACY_CONSENT_VERSION,
                'consent_at' => date('Y-m-d H:i:s'),
                // `verified` is the admin-approval flag and stays 0 until an
                // admin approves; it no longer blocks login, it blocks
                // trading (includes/compliance.php).
                'verified' => false,
                // Google already verified this email on their end, so a
                // Google registration skips the OTP step. This is NOT admin
                // approval — `verified` above is untouched.
                'otp_verified' => $isGoogleRegistration,
            ];

            add_pending_user($newUser);

            $registrationDocs = [];
            if ($primaryUpload) {
                $registrationDocs[] = [
                    'documentType' => trim($_POST['regBusinessDocType'] ?? 'business_registration'),
                    'upload' => $primaryUpload,
                ];
            }
            if ($secondaryUpload) {
                $registrationDocs[] = [
                    'documentType' => 'valid_id',
                    'upload' => $secondaryUpload,
                ];
            }

            foreach ($registrationDocs as $docEntry) {
                $uploaded = $docEntry['upload'];
                $claims = [
                    'businessName' => $regBusinessName,
                    'permitNumber' => trim($_POST['regPermitNumber'] ?? ''),
                    'taxId' => trim($_POST['regTaxId'] ?? ''),
                    'expiryDate' => trim($_POST['regDocExpiryDate'] ?? ''),
                ];

                $document = upload_document([
                    'userId' => $userId,
                    'relatedRequestId' => null,
                    'documentType' => $docEntry['documentType'],
                    'fileName' => $uploaded['originalFileName'],
                    'storedFileName' => $uploaded['storedFileName'],
                    'mimeType' => $uploaded['mimeType'],
                    'fileSize' => $uploaded['fileSize'],
                    'fileUrl' => $uploaded['fileUrl'],
                    'status' => 'pending',
                    'businessName' => $claims['businessName'],
                    'permitNumber' => $claims['permitNumber'],
                    'taxId' => $claims['taxId'] ?: null,
                    'expiryDate' => $claims['expiryDate'] ?: null,
                ]);

                log_activity($userId, 'document.upload', 'document', $document['id'], 'submitted at registration');

                // Same OCR (Google Vision) + AI validation (Claude) pass used
                // for regular document uploads — safe no-op if API keys
                // aren't configured. checkRegisteredBusiness=false because
                // this account row was only just inserted by this request;
                // see includes/ocr_ai.php for the full reasoning.
                process_document_verification(
                    $document['id'],
                    $uploadDir . '/' . $uploaded['storedFileName'],
                    $uploaded['mimeType'],
                    $claims,
                    false
                );
            }

            log_activity($userId, 'account.register', 'user', $userId, ucfirst($regRole) . ' account created' . (count($registrationDocs) ? ' with ' . count($registrationDocs) . ' document(s)' : ' without documents') . ($isGoogleRegistration ? ' via Google sign-in' : ''));
            log_activity($userId, 'privacy.consent', 'user', $userId, 'Accepted Privacy Notice and Terms of Use, version ' . PRIVACY_CONSENT_VERSION);

            if ($isGoogleRegistration) {
                // Email already verified by Google — no OTP step. Consume
                // the stashed profile (leaving it in session would let an
                // unrelated registration on this tab reuse it) and sign the
                // new user straight in to the restricted dashboard.
                unset($_SESSION['google_pending_profile']);
                $createdUser = get_user_by_id($userId);
                if ($createdUser) {
                    start_user_session($createdUser);
                    log_activity($userId, 'account.login', 'user', $userId, 'after Google registration');
                    header('Location: ' . url('dashboard/'));
                    exit;
                }
                $registerError = 'Your account was created. Please sign in with Google.';
            } elseif (create_and_send_otp($regEmail)) {
                $_SESSION['otp_email'] = $regEmail;
                $_SESSION['otp_registration'] = true;
                header('Location: ' . url('verify-otp/'));
                exit;
            } else {
                $registerError = 'Failed to send verification code. Please try again.';
            }
        } catch (RuntimeException $e) {
            // handle_secure_document_upload() failures (bad type/size) and
            // anything else above. The user row is only created once any
            // uploads already succeeded.
            $registerError = $e->getMessage();
        } catch (PDOException $e) {
            // get_user_by_email() is a check, not a lock: two near-simultaneous
            // submits with the same email can both pass it, and the second
            // INSERT then hits the `email_unique` constraint.
            $registerError = 'An account with this email already exists';
        }
    }

    $activeTab = 'register';
}

if (is_logged_in()) {
    header('Location: ' . url('dashboard/'));
    exit;
}

$success = '';
if (!empty($_SESSION['session_expired'])) {
    $success = 'You were signed out after a period of inactivity. Please sign in again.';
    unset($_SESSION['session_expired']);
}
if (isset($_SESSION['registration_success'])) {
    // Email verification (OTP) and admin approval are two separate gates
    // (see login_user() in includes/bootstrap.php) — this message used to
    // say "Please log in", which was wrong: an applicant whose email is
    // verified but who hasn't been approved yet will still be blocked at
    // login with a "pending verification" error. Set expectations here
    // instead of leading them to a login attempt that's expected to fail.
    $success = 'Email verified! You can log in now. Upload your compliance documents from the Documents page to unlock trading once an administrator approves them.';
    unset($_SESSION['registration_success']);
    $activeTab = 'login';
}

// ---------------------------------------------------------------------
// Real, live data from the system -- no placeholder numbers below this
// line. Everything the hero panel, ticker, and status strip show is
// pulled straight from includes/data.php against the current stocks,
// users, and warehouse tables.
// ---------------------------------------------------------------------
$availableStocks = array_values(array_filter(get_stocks(), fn($s) => $s['status'] === 'available'));

$liveGroups = [];
foreach ($availableStocks as $s) {
    $key = $s['variety'] . '|' . $s['source'];
    if (!isset($liveGroups[$key])) {
        $liveGroups[$key] = [
            'variety' => $s['variety'],
            'source' => $s['source'],
            'country' => $s['countryOfOrigin'],
            'unit' => $s['unit'],
            'listingCount' => 0,
            'totalQuantity' => 0.0,
            'priceTotal' => 0.0,
        ];
    }
    $liveGroups[$key]['listingCount']++;
    $liveGroups[$key]['totalQuantity'] += (float) $s['quantity'];
    $liveGroups[$key]['priceTotal'] += (float) $s['unitPrice'];
}
foreach ($liveGroups as &$g) {
    $g['avgPrice'] = $g['listingCount'] > 0 ? $g['priceTotal'] / $g['listingCount'] : 0.0;
}
unset($g);
$liveGroups = array_values($liveGroups);
usort($liveGroups, fn($a, $b) => $b['totalQuantity'] <=> $a['totalQuantity']);

$overview = get_supply_overview_stats();
$pendingAccounts = count_pending_users();
$networkAccounts = count_users_by_role('supplier') + count_users_by_role('farmer') + count_users_by_role('importer');

$aiInsights = get_ai_supply_insights();
$aiInsight = $aiInsights[0] ?? null;
$aiChipClass = 'ai-chip';
if ($aiInsight) {
    if ($aiInsight['level'] === 'critical') {
        $aiChipClass .= ' ai-chip--critical';
    } elseif ($aiInsight['level'] === 'recommendation') {
        $aiChipClass .= ' ai-chip--recommendation';
    }
}

$countryCodes = ['Philippines' => 'PH', 'Thailand' => 'TH', 'India' => 'IN', 'Italy' => 'IT', 'Vietnam' => 'VN'];

// ---------------------------------------------------------------------
// Media slots. Photos/video live in assets/media/ (see README.txt there).
// A slot renders ONLY if its file exists, so the page never ships a broken
// image or placeholder URL: drop a licensed file in and it appears.
// ---------------------------------------------------------------------
function kg_media_url(string $file): ?string
{
    $path = __DIR__ . '/../assets/media/' . $file;
    return is_file($path) ? url('assets/media/' . $file) . '?v=' . (int) @filemtime($path) : null;
}

function kg_picture(string $slug, string $alt = '', array $o = []): string
{
    $webp = kg_media_url($slug . '.webp');
    $jpg = kg_media_url($slug . '.jpg');
    if (!$webp && !$jpg) {
        return '';
    }
    $attrs = ' alt="' . htmlspecialchars($alt) . '" decoding="async"'
        . (!empty($o['eager']) ? ' fetchpriority="high"' : ' loading="lazy"')
        . (!empty($o['class']) ? ' class="' . htmlspecialchars($o['class']) . '"' : '');
    $img = '<img src="' . htmlspecialchars($jpg ?: $webp) . '"' . $attrs . '>';
    return ($webp && $jpg)
        ? '<picture><source type="image/webp" srcset="' . htmlspecialchars($webp) . '">' . $img . '</picture>'
        : $img;
}

// Alt text per slot. Edit these if the photo you supply shows something different.
$kgAlt = [
    'story-warehouse' => 'Workers stacking sacks of rice inside a warehouse',
    'story-logistics' => 'Rice sacks being loaded onto a delivery truck',
    'aud-farmers'     => 'Filipino farmers working in a rice paddy',
    'aud-importers'   => 'An imported rice shipment being inspected at a port',
    'aud-suppliers'   => 'A worker carrying a basket past tall stacks of rice sacks',
];
$heroPicture = kg_picture('hero-poster', '', ['class' => 'lp-hero__img', 'eager' => true]);
$heroPosterUrl = kg_media_url('hero-poster.jpg') ?: kg_media_url('hero-poster.webp');
$heroMp4 = kg_media_url('hero-rice.mp4');
$heroWebm = kg_media_url('hero-rice.webm');
$picWarehouse = kg_picture('story-warehouse', $kgAlt['story-warehouse']);
$picLogistics = kg_picture('story-logistics', $kgAlt['story-logistics']);
$picCta = kg_picture('cta-field', '');
$audPics = [
    'farmers' => kg_picture('aud-farmers', $kgAlt['aud-farmers']),
    'importers' => kg_picture('aud-importers', $kgAlt['aud-importers']),
    'suppliers' => kg_picture('aud-suppliers', $kgAlt['aud-suppliers']),
];

$topGroup = $liveGroups[0] ?? null;
$snapshotRows = array_slice($liveGroups, 0, 6);
$aiLevel = $aiInsight['level'] ?? '';

// Nine modules, grouped by where they sit in a sourcing decision. Order and numbering are unchanged.
$moduleGroups = [
    'Trust & stock' => [
        ['01', 'Verified accounts', 'Suppliers, farmers, and importers register and get approved before they can list or request stock.'],
        ['02', 'Document & OCR check', 'Permits and receipts are scanned and read automatically, so nothing is retyped by hand.'],
        ['03', 'Stock & inventory', 'Farmers and importers keep listings current — quantity, price, variety, and origin, always up to date.'],
    ],
    'Sourcing & requests' => [
        ['04', 'Search & compare', 'Filter local against imported, sort by price or quantity, and compare listings side by side.'],
        ['05', 'Procurement requests', 'Suppliers request stock directly; farmers and importers accept, reject, or counter.'],
        ['06', 'Alerts & notifications', 'Price changes, new stock matching your saved varieties, and request updates, delivered as they happen.'],
    ],
    'Insight & oversight' => [
        ['07', 'Assistant', 'Ask about stock, pricing, or how to submit a request, and get an answer pulled straight from live data.'],
        ['08', 'Admin oversight', 'Approvals, verifications, and a full log of every action, kept for regulatory review.'],
        ['09', 'Reports', 'Stock and price trends across regions and varieties, exportable for audits and planning.'],
    ],
];

$pageClass = 'landing-page';
include __DIR__ . '/../includes/header.php';
?>
<a class="skip-link" href="#main">Skip to main content</a>
<header class="nav">
    <div class="wrap nav__row">
        <a class="nav__brand" href="<?php echo htmlspecialchars(url('landing/')); ?>">
            <span class="nav__mark"><img src="<?php echo htmlspecialchars(url('assets/logo/kamalig-icon-96.webp')); ?>" alt="" width="36" height="34"></span>
            KAMALIG
        </a>
        <nav class="nav__links" id="navLinks" aria-label="Primary">
            <a href="#snapshot">Inventory</a>
            <a href="#modules">Modules</a>
            <a href="#how-it-works">How it works</a>
            <a href="#audiences">Who it's for</a>
        </nav>
        <div class="nav__actions">
            <button type="button" class="nav__cta nav__cta--ghost" data-open-tab="login">Login</button>
            <button type="button" class="nav__cta" data-open-tab="register">Register Now</button>
        </div>
        <button type="button" class="nav__toggle" id="navToggle" aria-expanded="false" aria-controls="navLinks" aria-label="Open menu">
            <svg class="i-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <svg class="i-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
</header>

<?php
    // Floating auth modal -- shares the exact same PHP-handled forms as
    // before (same field names, same $error/$registerError, same
    // csrf_field()), just presented as a fixed-position overlay instead of
    // an inline hero column. auto-open only when the server actually has
    // something to show in it (a failed login/register attempt, or the
    // "verified, please log in" success message) -- a normal first visit
    // loads with $activeTab === 'live' and the modal stays closed.
    $modalInitialTab = $activeTab === 'register' ? 'register' : 'login';
    $modalAutoOpen = $activeTab !== 'live';
?>
<div class="auth-overlay" id="authOverlay" data-tab="<?php echo htmlspecialchars($modalInitialTab); ?>" data-autoopen="<?php echo $modalAutoOpen ? '1' : '0'; ?>" aria-hidden="true">
    <div class="auth-overlay__scrim" data-auth-close></div>
    <div class="access" role="dialog" aria-modal="true" aria-labelledby="auth-title-login">
        <button type="button" class="access__close" data-auth-close aria-label="Close dialog">&times;</button>
        <div class="access__logo"><img src="<?php echo htmlspecialchars(url('assets/logo/kamalig-icon-96.webp')); ?>" alt="" width="52" height="49"></div>

        <div class="access__panels">
            <div class="access__track">

                <!-- SIGN IN PANEL -->
                <div class="access__panel access-login <?php echo $activeTab === 'login' ? 'is-active' : ''; ?>" data-panel="login">
                    <h2 id="auth-title-login">Welcome back</h2>
                    <p class="access-login__lead">Sign in to manage inventory, requests, and documents in real time.</p>

                    <?php if ($success !== ''): ?>
                        <div class="access-login__alert access-login__alert--success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    <?php if ($error !== ''): ?>
                        <div class="access-login__alert access-login__alert--error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate autocomplete="on">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form" value="login">
                        <div class="form-group">
                            <label for="access-email">Email address</label>
                            <div class="access-login__field">
                                <input id="access-email" name="email" type="email" placeholder="you@example.com" autocomplete="email" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="access-password">Password<a class="field-hint-link" href="<?php echo htmlspecialchars(url('login/forgot.php')); ?>">Forgot password?</a></label>
                            <div class="access-login__field">
                                <input id="access-password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                                <button type="button" class="access-login__toggle" data-target="access-password" aria-controls="access-password" aria-pressed="false">Show</button>
                            </div>
                        </div>
                        <?php echo captcha_widget(); ?>
                        <button class="btn btn--primary btn--block" type="submit">Sign In</button>
                    </form>

                    <div class="access-login__divider-row"><span>or</span></div>
                    <a class="btn btn--secondary btn--block" href="<?php echo htmlspecialchars(url('login/google/')); ?>">Sign in with Google</a>

                    <div class="access-login__row">Don't have an account? <button type="button" class="access-login__link-btn" data-open-tab="register">Register</button></div>
                    <p class="access-login__hint">Verification is handled by an admin before your first sign-in.</p>
                </div>

                <!-- REGISTER PANEL -->
                <div class="access__panel access-login <?php echo $activeTab === 'register' ? 'is-active' : ''; ?>" data-panel="register">
                    <h2 id="auth-title-register">Create your account</h2>
                    <p class="access-login__lead">Sign up in a minute. You can log in right after verifying your email; trading unlocks once an admin approves your documents.</p>

                    <?php if ($registerError !== ''): ?>
                        <div class="access-login__alert access-login__alert--error"><?php echo htmlspecialchars($registerError); ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate autocomplete="on" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form" value="register">
                        <?php $postedRole = $activeTab === 'register' ? ($_POST['role'] ?? 'supplier') : 'supplier'; ?>
                        <div class="form-group">
                            <label for="access-role">I am a</label>
                            <select id="access-role" name="role">
                                <option value="supplier" <?php echo $postedRole === 'supplier' ? 'selected' : ''; ?>>Supplier / Trader</option>
                                <option value="farmer" <?php echo $postedRole === 'farmer' ? 'selected' : ''; ?>>Farmer / Producer</option>
                                <option value="importer" <?php echo $postedRole === 'importer' ? 'selected' : ''; ?>>Importer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="access-name">Full name</label>
                            <div class="access-login__field">
                                <input id="access-name" name="name" type="text" placeholder="Juan Dela Cruz" value="<?php echo $activeTab === 'register' ? htmlspecialchars($_POST['name'] ?? '') : ''; ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="access-company">Company / Group <span class="muted">(optional)</span></label>
                            <div class="access-login__field">
                                <input id="access-company" name="company" type="text" placeholder="Company or cooperative name" value="<?php echo $activeTab === 'register' ? htmlspecialchars($_POST['company'] ?? '') : ''; ?>">
                            </div>
                        </div>
                        <?php if ($googlePendingProfile): ?>
                            <div class="form-group">
                                <label for="access-reg-email">Email address</label>
                                <div class="access-login__field">
                                    <input id="access-reg-email" type="email" value="<?php echo htmlspecialchars($googlePendingProfile['email']); ?>" readonly disabled>
                                </div>
                                <p class="access-login__hint">Verified via Google — no password needed for this account.</p>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label for="access-reg-email">Email address</label>
                                <div class="access-login__field">
                                    <input id="access-reg-email" name="email" type="email" placeholder="you@example.com" autocomplete="email" value="<?php echo $activeTab === 'register' ? htmlspecialchars($_POST['email'] ?? '') : ''; ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="access-reg-password">Password</label>
                                <div class="access-login__field">
                                    <input id="access-reg-password" name="password" type="password" placeholder="At least 10 characters" autocomplete="new-password" required>
                                    <button type="button" class="access-login__toggle" data-target="access-reg-password" aria-controls="access-reg-password" aria-pressed="false">Show</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="access-confirm-password">Confirm password</label>
                                <div class="access-login__field">
                                    <input id="access-confirm-password" name="confirmPassword" type="password" placeholder="Re-enter your password" autocomplete="new-password" required>
                                    <button type="button" class="access-login__toggle" data-target="access-confirm-password" aria-controls="access-confirm-password" aria-pressed="false">Show</button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <hr class="access-login__divider">
                        <details class="access-login__optional">
                            <summary>Upload documents now <span class="muted">(optional &mdash; you can also do this after logging in)</span></summary>
                            <p class="muted">An admin must approve your documents before you can list stock, send requests or pay. Uploading now just starts that review sooner.</p>

                            <div class="form-group">
                                <label for="access-doc-type">Document type</label>
                                <select id="access-doc-type" name="regBusinessDocType">
                                    <option value="business_registration">Business Registration</option>
                                    <option value="permit">Import/Business Permit</option>
                                    <option value="certificate">Certificate of Registration (co-op/association)</option>
                                    <option value="tax_id">Tax ID / TIN</option>
                                    <option value="valid_id">Valid Government ID (individual farmer/producer)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="access-business-doc">Business permit / registration document</label>
                                <input id="access-business-doc" name="regBusinessDoc" type="file" accept=".pdf,.jpg,.jpeg,.png">
                                <p class="muted">PDF, JPG, or PNG, up to 8MB.</p>
                            </div>
                            <div class="form-group">
                                <label for="access-doc-name">Business / registrant name on the document</label>
                                <input id="access-doc-name" name="regBusinessDocName" type="text" placeholder="Matches the name on the attached document" value="<?php echo $activeTab === 'register' ? htmlspecialchars($_POST['regBusinessDocName'] ?? '') : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="access-permit-number">Permit / registration number <span class="muted">(if applicable)</span></label>
                                <input id="access-permit-number" name="regPermitNumber" type="text" value="<?php echo $activeTab === 'register' ? htmlspecialchars($_POST['regPermitNumber'] ?? '') : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="access-tax-id">Tax ID / TIN <span class="muted">(optional)</span></label>
                                <input id="access-tax-id" name="regTaxId" type="text" value="<?php echo $activeTab === 'register' ? htmlspecialchars($_POST['regTaxId'] ?? '') : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="access-valid-id">Valid government ID <span class="muted">(optional, speeds up review)</span></label>
                                <input id="access-valid-id" name="regValidId" type="file" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                        </details>

                        <div class="form-group">
                            <label style="display:flex; gap:10px; align-items:flex-start; font-weight:400; line-height:1.45;">
                                <input type="checkbox" name="acceptTerms" value="1" required style="margin-top:3px; width:auto; flex:none;" <?php echo ($activeTab === 'register' && !empty($_POST['acceptTerms'])) ? 'checked' : ''; ?>>
                                <span>I have read and agree to the <a href="<?php echo htmlspecialchars(url('privacy/')); ?>" target="_blank" rel="noopener">Privacy Notice</a> and the <a href="<?php echo htmlspecialchars(url('terms/')); ?>" target="_blank" rel="noopener">Terms of Use</a>, and I consent to KAMALIG processing my personal data, including any documents I upload, as described there.</span>
                            </label>
                        </div>
                        <?php if (!$googlePendingProfile) { echo captcha_widget(); } ?>
                        <button class="btn btn--primary btn--block" type="submit">Create account</button>
                        <p class="access-login__row muted" style="margin-top:8px;">After verifying your email you can log in right away. Listing, requests, cart and payments unlock once an admin approves your documents.</p>
                    </form>

                    <?php if (!$googlePendingProfile): ?>
                        <div class="access-login__divider-row"><span>or</span></div>
                        <a class="btn btn--secondary btn--block" href="<?php echo htmlspecialchars(url('login/google/')); ?>">Sign up with Google</a>
                    <?php endif; ?>

                    <div class="access-login__row">Already have an account? <button type="button" class="access-login__link-btn" data-open-tab="login">Login</button></div>
                </div>

            </div>
        </div>
    </div>
</div>

<main id="main">

<!-- HERO -->
<section class="lp-hero on-dark" aria-labelledby="hero-title">
    <div class="lp-hero__media" aria-hidden="true">
        <?php if ($heroPicture !== ''): ?>
            <?php echo $heroPicture; ?>
        <?php else: ?>
            <!-- No hero photo supplied yet: calm, static terrace artwork. Add assets/media/hero-poster.jpg to replace it. -->
            <svg class="lp-hero__terraces" viewBox="0 0 600 420" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
                <path d="M0,175 C120,145 180,205 300,185 C420,165 480,215 600,190 L600,420 L0,420 Z"/>
                <path d="M0,238 C130,212 210,258 320,238 C430,218 500,262 600,242 L600,420 L0,420 Z"/>
                <path d="M0,300 C140,278 230,314 330,300 C440,286 520,318 600,304 L600,420 L0,420 Z"/>
            </svg>
        <?php endif; ?>
        <?php if ($heroMp4 || $heroWebm): ?>
            <video class="lp-hero__video" id="heroVideo" muted loop playsinline preload="none" tabindex="-1" disablepictureinpicture
                   <?php echo $heroPosterUrl ? 'poster="' . htmlspecialchars($heroPosterUrl) . '"' : ''; ?>
                   <?php echo $heroWebm ? 'data-webm="' . htmlspecialchars($heroWebm) . '"' : ''; ?>
                   <?php echo $heroMp4 ? 'data-mp4="' . htmlspecialchars($heroMp4) . '"' : ''; ?>></video>
        <?php endif; ?>
    </div>
    <div class="lp-hero__shade" aria-hidden="true"></div>
    <?php if ($heroMp4 || $heroWebm): ?>
        <button type="button" class="lp-hero__motion" id="heroMotion" aria-pressed="false">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><rect x="2" y="1" width="3.5" height="12" rx="1"/><rect x="8.5" y="1" width="3.5" height="12" rx="1"/></svg>
            <span>Pause background video</span>
        </button>
    <?php endif; ?>

    <div class="wrap lp-hero__inner">
        <div class="lp-hero__copy">
            <span class="eyebrow">Local &amp; imported, one source of truth</span>
            <h1 id="hero-title">Every sack, <em>accounted for.</em></h1>
            <p class="lp-hero__lead">KAMALIG gives suppliers, farmers, and importers one shared view of rice stock, pricing, and origin — so sourcing decisions stop depending on who you can reach by phone today.</p>
            <div class="lp-hero__actions">
                <button type="button" class="btn btn--primary" data-open-tab="register">Request supplier access</button>
                <a href="#how-it-works" class="btn btn--ghost">See how it works</a>
            </div>
            <?php if ($aiInsight): ?>
                <?php /* Public page: headline only. Warehouse locations and quantities are for signed-in, approved users. */ ?>
                <div class="lp-aichip<?php echo $aiLevel === 'critical' ? ' lp-aichip--critical' : ''; ?>">
                    <span class="lp-aichip__tag">AI</span>
                    <span>
                        <strong><?php echo htmlspecialchars($aiInsight['title']); ?></strong>
                        Sign in to see the details. Advisory only; people decide.
                    </span>
                </div>
            <?php endif; ?>
            <p class="lp-hero__thesis">AI-Enhanced Rice Inventory System: Streamlining Procurement with OCR-Driven Transparency</p>
        </div>

        <aside class="lp-snapcard" aria-label="Live stock snapshot">
            <div class="lp-snapcard__head"><span class="live-dot" aria-hidden="true"></span>Live stock snapshot</div>
            <?php if ($topGroup): ?>
                <?php
                    $topCode = $countryCodes[$topGroup['country']] ?? strtoupper(substr($topGroup['country'], 0, 2));
                    $topImported = $topGroup['source'] === 'imported';
                ?>
                <div class="lp-snapcard__row">
                    <span class="lp-snapcard__name"><?php echo htmlspecialchars($topGroup['variety']); ?>
                        <span class="badge <?php echo $topImported ? 'badge--imported' : 'badge--local'; ?>"><?php echo $topImported ? 'Imported · ' . htmlspecialchars($topCode) : 'Local'; ?></span>
                    </span>
                    <span class="lp-snapcard__price num">&#8369;<?php echo number_format($topGroup['avgPrice'], 0); ?><small>/<?php echo htmlspecialchars($topGroup['unit']); ?></small></span>
                </div>
            <?php else: ?>
                <p class="lp-snapcard__empty">No listings yet — check back soon.</p>
            <?php endif; ?>
            <div class="lp-snapcard__foot">
                <span><b class="num"><?php echo number_format((int) $overview['totalListings']); ?></b>listings</span>
                <span><b class="num"><?php echo number_format((int) $networkAccounts); ?></b>verified</span>
            </div>
        </aside>
    </div>
</section>

<!-- INVENTORY SNAPSHOT (live data from includes/data.php) -->
<section class="lp-section lp-snap" id="snapshot" aria-labelledby="snap-title">
    <div class="wrap">
        <div class="lp-snap__top">
            <div class="lp-head">
                <span class="eyebrow">Inventory snapshot</span>
                <h2 id="snap-title">What is on the shelf right now</h2>
                <p>Local and imported rice side by side, with price and quantity pulled from current listings.</p>
            </div>
            <span class="lp-snap__live"><span class="live-dot" aria-hidden="true"></span>Updated from live listings</span>
        </div>

        <dl class="lp-facts reveal">
            <div class="lp-fact"><dt>Active listings, nationwide</dt><dd class="num"><?php echo number_format((int) $overview['totalListings']); ?></dd></div>
            <div class="lp-fact"><dt>Rice varieties tracked</dt><dd class="num"><?php echo number_format((int) $overview['totalVarieties']); ?></dd></div>
            <div class="lp-fact"><dt>Verified suppliers, farmers &amp; importers</dt><dd class="num"><?php echo number_format((int) $networkAccounts); ?></dd></div>
            <div class="lp-fact"><dt>Accounts awaiting verification</dt><dd class="num"><?php echo number_format((int) $pendingAccounts); ?></dd></div>
        </dl>

        <div class="lp-table-wrap reveal">
            <?php if (empty($snapshotRows)): ?>
                <p class="lp-snap__empty">No live listings yet — this table fills in as suppliers list stock.</p>
            <?php else: ?>
                <table class="lp-table">
                    <caption class="sr-only">Available rice by variety and source, largest quantity first</caption>
                    <thead>
                        <tr><th scope="col">Variety</th><th scope="col">Source</th><th scope="col" class="t-num">Listings</th><th scope="col" class="t-num">Quantity</th><th scope="col" class="t-num">Average price</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($snapshotRows as $row):
                        $code = $countryCodes[$row['country']] ?? strtoupper(substr($row['country'], 0, 2));
                        $imp = $row['source'] === 'imported';
                    ?>
                        <tr>
                            <td class="t-var"><?php echo htmlspecialchars($row['variety']); ?></td>
                            <td class="t-origin" data-label="Source"><span class="badge <?php echo $imp ? 'badge--imported' : 'badge--local'; ?>"><?php echo $imp ? 'Imported · ' . htmlspecialchars($code) : 'Local'; ?></span> <span class="sr-only"><?php echo htmlspecialchars($row['country']); ?></span></td>
                            <td class="t-num num" data-label="Listings"><?php echo number_format($row['listingCount']); ?></td>
                            <td class="t-num num" data-label="Quantity"><?php echo number_format($row['totalQuantity'], 0); ?> <?php echo htmlspecialchars($row['unit']); ?></td>
                            <td class="t-num num t-price" data-label="Average price">&#8369;<?php echo number_format($row['avgPrice'], 0); ?>/<?php echo htmlspecialchars($row['unit']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if (count($liveGroups) > count($snapshotRows)): ?>
            <p class="lp-snap__note">Showing the <?php echo count($snapshotRows); ?> largest of <?php echo count($liveGroups); ?> variety and source groups. Sign in to browse every listing.</p>
        <?php endif; ?>
    </div>
</section>

<!-- CORE CAPABILITIES -->
<section class="lp-section<?php echo $picWarehouse === '' ? ' lp-cap--nophoto' : ''; ?>" id="capabilities" aria-labelledby="cap-title">
    <div class="wrap lp-cap__grid">
        <div class="lp-cap__aside">
            <div class="lp-head">
                <span class="eyebrow">Core system capabilities</span>
                <h2 id="cap-title">Engineered for transparent, verified rice sourcing</h2>
            </div>
            <?php if ($picWarehouse !== ''): ?>
                <figure class="lp-media reveal"><?php echo $picWarehouse; ?></figure>
            <?php endif; ?>
        </div>
        <ul class="lp-cap__list">
            <li class="lp-cap__item reveal">
                <span class="lp-cap__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/></svg></span>
                <div><h3>Verified accounts &amp; OCR</h3><span class="lp-cap__tag num"><?php echo (int) $networkAccounts; ?> accounts verified</span>
                <p>Permits and IDs are scanned and cross-checked automatically before an admin ever has to look, so nothing rides on a photocopy alone.</p></div>
            </li>
            <li class="lp-cap__item reveal">
                <span class="lp-cap__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg></span>
                <div><h3>Real-time inventory</h3><span class="lp-cap__tag num"><?php echo (int) $overview['totalListings']; ?> listings live</span>
                <p>Farmers and importers keep quantity, price, and origin current — so a sourcing decision never runs on numbers that are already a week old.</p></div>
            </li>
            <li class="lp-cap__item reveal">
                <span class="lp-cap__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                <div><h3>Procurement automation</h3><span class="lp-cap__tag">Request &rarr; accept &rarr; track</span>
                <p>Suppliers request stock directly and farmers or importers respond, with every step logged from pending to completed.</p></div>
            </li>
            <li class="lp-cap__item reveal">
                <span class="lp-cap__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4.5 8-11V5l-8-3-8 3v6c0 6.5 8 11 8 11z"/></svg></span>
                <div><h3>Supply transparency</h3><span class="lp-cap__tag num"><?php echo (int) $overview['totalVarieties']; ?> varieties tracked</span>
                <p>Local and imported stock sit side by side with origin and documentation attached, kept as an auditable record for regulators and buyers alike.</p></div>
            </li>
        </ul>
    </div>
</section>

<!-- SUPPLY-CHAIN BAND -->
<section class="lp-section lp-chain on-dark<?php echo $picLogistics !== '' ? ' has-photo' : ''; ?>" aria-labelledby="chain-title">
    <div class="wrap lp-chain__grid">
        <div>
            <span class="eyebrow">The problem we start from</span>
            <h2 id="chain-title">Right now, checking stock means driving there — and the numbers are still old by the time you arrive.</h2>
            <div class="lp-chain__stats">
                <div class="lp-chain__stat reveal"><div class="lp-chain__num">3+</div><div class="lp-chain__label">site visits, calls, or trips per sourcing decision</div></div>
                <div class="lp-chain__stat reveal"><div class="lp-chain__num">0</div><div class="lp-chain__label">shared record of local vs. imported stock, before KAMALIG</div></div>
                <div class="lp-chain__stat reveal"><div class="lp-chain__num">100%</div><div class="lp-chain__label">manual — permits, receipts, and prices, checked by hand</div></div>
            </div>
        </div>
        <?php if ($picLogistics !== ''): ?>
            <figure class="lp-media lp-chain__media reveal"><?php echo $picLogistics; ?></figure>
        <?php endif; ?>
    </div>
</section>

<!-- NINE MODULES -->
<section class="lp-section lp-mod" id="modules" aria-labelledby="mod-title">
    <div class="wrap">
        <div class="lp-head">
            <span class="eyebrow">What's inside</span>
            <h2 id="mod-title">Nine modules, one platform</h2>
            <p>Every piece a sourcing decision touches — from verifying who you're buying from, to tracking the request through to completion.</p>
        </div>
        <div class="lp-mod__groups">
            <?php foreach ($moduleGroups as $groupTitle => $mods): ?>
                <div class="lp-mod__group reveal">
                    <h3 class="lp-mod__title"><?php echo htmlspecialchars($groupTitle); ?></h3>
                    <ol class="lp-mod__list">
                        <?php foreach ($mods as $i => $m): ?>
                            <li class="lp-mod__item<?php echo $i === 0 ? ' lp-mod__item--lead' : ''; ?>">
                                <span class="lp-mod__num"><?php echo htmlspecialchars($m[0]); ?></span>
                                <div><h4><?php echo htmlspecialchars($m[1]); ?></h4><p><?php echo htmlspecialchars($m[2]); ?></p></div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- PROCUREMENT WORKFLOW -->
<section class="lp-section" id="how-it-works" aria-labelledby="flow-title">
    <div class="wrap">
        <div class="lp-head">
            <span class="eyebrow">The process</span>
            <h2 id="flow-title">From registration to completed order</h2>
        </div>
        <ol class="lp-flow__list">
            <li class="lp-flow__step reveal"><span class="lp-flow__num" aria-hidden="true">01</span><h3>Register &amp; get verified</h3><p>Sign up, upload your permit, and get approved by an admin before you can transact.</p></li>
            <li class="lp-flow__step reveal"><span class="lp-flow__num" aria-hidden="true">02</span><h3>Browse or list stock</h3><p>Farmers and importers list rice; suppliers filter local versus imported by variety, price, and origin.</p></li>
            <li class="lp-flow__step reveal"><span class="lp-flow__num" aria-hidden="true">03</span><h3>Compare &amp; request</h3><p>Put listings side by side, then send a procurement request with the quantity you need.</p></li>
            <li class="lp-flow__step reveal"><span class="lp-flow__num" aria-hidden="true">04</span><h3>Track to completion</h3><p>Follow the request from pending to accepted to completed, with alerts at every change.</p></li>
        </ol>
    </div>
</section>

<!-- AUDIENCES -->
<section class="lp-section lp-aud" id="audiences" aria-labelledby="aud-title">
    <div class="wrap">
        <div class="lp-head">
            <span class="eyebrow">Built for three sides of the same trade</span>
            <h2 id="aud-title">Who it's for</h2>
        </div>
        <div class="lp-aud__grid">
            <?php
            $audiences = [
                ['farmers', 'lp-aud__card--dark', 'Farmers & producer groups', 'List what you have, once, and let verified suppliers find you.',
                    ['Post local stock with variety, quantity, and price', 'See requests as they come in, not as phone calls', 'Get paid for what you actually have on hand'],
                    '<path d="M12 21v-9"/><path d="M12 13c0-3.2-2.3-5.2-6.2-5.2 0 3.4 2.3 5.2 6.2 5.2z"/><path d="M12 10.5c0-3.3 2.4-5.6 6.2-5.6 0 3.6-2.4 5.6-6.2 5.6z"/>'],
                ['importers', '', 'Importers', 'Show origin and compliance up front, so buyers trust the listing.',
                    ['Attach permits and country of origin per shipment', 'Update stock as containers clear customs', 'Reach suppliers without a broker in between'],
                    '<rect x="3" y="7" width="18" height="11" rx="1.5"/><path d="M8 7v11M12 7v11M16 7v11M3 21h18"/>'],
                ['suppliers', '', 'Suppliers & procurement officers', 'Compare local and imported options without leaving your desk.',
                    ['Filter by variety, price, quantity, and origin', 'Compare up to three listings side by side', "Track every request until it's fulfilled"],
                    '<path d="M3 6.5h11V16H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>'],
            ];
            foreach ($audiences as $a): ?>
                <article class="lp-aud__card <?php echo $a[1]; ?> reveal">
                    <?php if ($audPics[$a[0]] !== ''): ?>
                        <div class="lp-aud__media"><?php echo $audPics[$a[0]]; ?></div>
                    <?php endif; ?>
                    <div class="lp-aud__body">
                        <?php if ($audPics[$a[0]] === ''): ?>
                            <span class="lp-aud__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $a[5]; ?></svg></span>
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($a[2]); ?></h3>
                        <p class="lp-aud__lead"><?php echo htmlspecialchars($a[3]); ?></p>
                        <ul><?php foreach ($a[4] as $li): ?><li><?php echo htmlspecialchars($li); ?></li><?php endforeach; ?></ul>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CLOSING CTA -->
<section class="lp-cta on-dark<?php echo $picCta !== '' ? ' has-photo' : ''; ?>" id="request" aria-labelledby="cta-title">
    <?php if ($picCta !== ''): ?><div class="lp-cta__bg" aria-hidden="true"><?php echo $picCta; ?></div><?php endif; ?>
    <div class="wrap lp-cta__inner">
        <h2 id="cta-title">Ready to see your stock the way everyone else does?</h2>
        <p>Registration takes a few minutes. Verification is handled by an admin before you go live.</p>
        <div class="lp-hero__actions">
            <button type="button" class="btn btn--primary" data-open-tab="register">Request supplier access</button>
            <button type="button" class="btn btn--ghost" data-open-tab="login">Already have an account? Sign in</button>
        </div>
    </div>
</section>

</main>

<footer class="lp-foot">
    <div class="wrap lp-foot__row">
        <div>
            <a href="<?php echo htmlspecialchars(url('landing/')); ?>" class="lp-foot__brand">
                <img src="<?php echo htmlspecialchars(url('assets/logo/kamalig-icon-96.webp')); ?>" alt="" width="32" height="30" loading="lazy">KAMALIG
            </a>
            <p class="lp-foot__meta">An integrated rice supply transparency and inventory management platform for local and imported stock.</p>
            <p class="lp-foot__meta">
                Currently tracking <?php echo number_format($overview['totalListings']); ?> active listing<?php echo $overview['totalListings'] === 1 ? '' : 's'; ?> across <?php echo (int) $overview['totalVarieties']; ?> varieties, local and imported.
                <?php if ($pendingAccounts > 0): ?>
                    <?php echo (int) $pendingAccounts; ?> account<?php echo $pendingAccounts === 1 ? '' : 's'; ?> currently awaiting verification.
                <?php endif; ?>
            </p>
        </div>
        <div class="lp-foot__cols">
            <div class="lp-foot__col">
                <h4>Platform</h4>
                <a href="#modules">Modules</a>
                <a href="#how-it-works">How it works</a>
                <a href="#audiences">Who it's for</a>
            </div>
            <div class="lp-foot__col">
                <h4>Roles</h4>
                <a href="#audiences">Farmers</a>
                <a href="#audiences">Importers</a>
                <a href="#audiences">Suppliers</a>
            </div>
            <div class="lp-foot__col">
                <h4>Legal</h4>
                <a href="<?php echo htmlspecialchars(url('privacy/')); ?>">Privacy Notice</a>
                <a href="<?php echo htmlspecialchars(url('terms/')); ?>">Terms of Use</a>
            </div>
        </div>
    </div>
</footer>

<?php include __DIR__ . '/../includes/footer.php'; ?>
