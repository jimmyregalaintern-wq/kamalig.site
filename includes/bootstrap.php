<?php
require_once __DIR__ . '/env.php';

// --- Deployment settings (from .env) ---
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');
$__debug = env_bool('APP_DEBUG', false);
ini_set('display_errors', $__debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting($__debug ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));
if (env_bool('ENABLE_HTTPS', false) && PHP_SAPI !== 'cli') {
    $__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$__https && !empty($_SERVER['HTTP_HOST'])) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
}

// SECURITY: cookie params must be set before session_start(). httponly
// blocks JS (and thus XSS-based) access to the session cookie; samesite=Lax
// blocks it being sent on most cross-site requests (CSRF defense-in-depth
// alongside the app's csrf_verify()); secure is only turned on when the
// request actually arrived over HTTPS, so this doesn't break local XAMPP
// dev over plain http.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Fix Guide item 8: strict session mode, a 30-minute idle timeout and an
// absolute lifetime. Override in .env with SESSION_IDLE_MINUTES / SESSION_ABSOLUTE_HOURS.
if (!defined('SESSION_IDLE_SECONDS')) define('SESSION_IDLE_SECONDS', max(5, (int) (getenv('SESSION_IDLE_MINUTES') ?: 30)) * 60);
if (!defined('SESSION_ABSOLUTE_SECONDS')) define('SESSION_ABSOLUTE_SECONDS', max(1, (int) (getenv('SESSION_ABSOLUTE_HOURS') ?: 8)) * 3600);
if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not issue
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_ABSOLUTE_SECONDS);
}
if (PHP_SAPI !== 'cli' && $isHttps && !headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000');
}
session_start();
if (PHP_SAPI !== 'cli' && !empty($_SESSION['user'])) {
    $__now = time();
    $__idle = $__now - (int) (isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : $__now);
    $__age = $__now - (int) (isset($_SESSION['session_started']) ? $_SESSION['session_started'] : $__now);
    if ($__idle > SESSION_IDLE_SECONDS || $__age > SESSION_ABSOLUTE_SECONDS) {
        // Signed out for inactivity (or the session simply got too old): start a clean session.
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_expired'] = true;
    } else {
        // Background polling (e.g. notifications/poll/) defines this constant so an
        // open tab does not keep the session alive past the idle timeout.
        if (!defined('KAMALIG_PASSIVE_REQUEST')) {
            $_SESSION['last_activity'] = $__now;
        }
        if (!isset($_SESSION['session_started'])) {
            $_SESSION['session_started'] = $__now;
        }
    }
}
require_once __DIR__ . '/db.php';
// data.php remains for compatibility when the DB is not used yet.
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/private_storage.php';
require_once __DIR__ . '/security_check.php';
require_once __DIR__ . '/captcha.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/commerce.php';
require_once __DIR__ . '/otp.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/ai_guard.php';
require_once __DIR__ . '/ocr_ai.php';
require_once __DIR__ . '/ai_insights.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/agent.php';
require_once __DIR__ . '/ai_selftest.php';
require_once __DIR__ . '/compliance.php';

// Version of the Privacy Notice + Terms a user consents to at registration.
// Bump it whenever those pages change in a way that needs fresh consent.
if (!defined('PRIVACY_CONSENT_VERSION')) define('PRIVACY_CONSENT_VERSION', '1.0');

$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$appRoot = realpath(dirname(__DIR__));
$basePath = '';
if ($documentRoot && $appRoot && str_starts_with($appRoot, $documentRoot)) {
    $basePath = '/' . trim(str_replace('\\', '/', substr($appRoot, strlen($documentRoot))), '/');
}
$basePath = $basePath === '/' ? '' : $basePath;
define('BASE_PATH', $basePath);

function url(string $path): string {
    return BASE_PATH . '/' . ltrim($path, '/');
}

/**
 * Absolute URL (scheme + host + path) for links that leave the browser's
 * context — emails, OAuth redirects, notifications. url() is path-only and
 * is only valid inside pages served by this app.
 *
 * Host comes from APP_URL (.env). SECURITY: we deliberately do NOT trust the
 * request's Host header for emailed links, because an attacker could forge it
 * ("Host header poisoning") and get a reset link pointing at their own domain
 * sent from your mailer. The HTTP_HOST fallback is only for local dev.
 */
function absolute_url(string $path): string {
    $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
    if ($appUrl === '') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
            error_log('absolute_url(): APP_URL is not set in .env and no valid Host header is available.');
            return url($path); // last resort, same behaviour as before
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $appUrl = ($https ? 'https://' : 'http://') . $host;
    }
    return $appUrl . url($path);
}

/**
 * Resolve a stock listing's photo for display: the uploaded imageUrl if
 * one exists, otherwise a shared placeholder — so every view that shows a
 * listing (Marketplace grid, listing detail, Compare, Inventory, Search)
 * renders the same fallback instead of each reimplementing its own null
 * check or broken-image icon.
 */
function stock_image_src(?string $imageUrl): string {
    return ($imageUrl !== null && $imageUrl !== '') ? url($imageUrl) : url('public/placeholder.jpg');
}

/**
 * Validate a "come back to this page" path (e.g. from the floating
 * Assistant widget's returnTo field) before it's ever passed to header
 * Location: — must be a local relative path, never an absolute URL to
 * another host. Falls back to the dashboard if it looks off.
 */
function safe_return_path(?string $path): string {
    $path = trim((string) $path);
    if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
        return url('dashboard/');
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $path;
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function current_user_id(): ?string {
    return current_user()['id'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function require_auth(): void {
    if (!is_logged_in()) {
        // Landing page's Sign In tab is the app's entry point — see
        // landing/index.php (login/index.php has been retired).
        header('Location: ' . url('landing/') . '#access-login');
        exit;
    }

    // BUGFIX: $_SESSION['user'] is a snapshot taken at login time, and
    // nothing re-checked it against the database afterward — an account
    // suspended (or rejected after the fact) mid-session kept full access
    // to everything until that session happened to expire on its own,
    // completely defeating the point of "suspend this account" as an
    // immediate stop. Re-validating the live status here, on every
    // authenticated page load, is what makes a suspension actually take
    // effect on the very next click instead of the next login.
    $liveUser = get_user_by_id(current_user_id() ?? '');
    // Compliance gate (Fix Guide item 7): a 'rejected' account is no longer
    // thrown out — it means "needs resubmission" and the user may log in to
    // see the admin's reason and upload new documents. Only a suspension
    // ends the session.
    if (!$liveUser || ($liveUser['status'] ?? '') === 'suspended') {
        logout_user();
        header('Location: ' . url('landing/') . '#access-login');
        exit;
    }

    // Keep the session snapshot in step with the database so approval shows
    // up on the very next click instead of the next login.
    $_SESSION['user']['verified'] = $liveUser['verified'];
    $_SESSION['user']['role'] = $liveUser['role'];

    // ONE deny-by-default check for every page that requires login — see
    // includes/compliance.php. Not-yet-approved users only reach the
    // allow-listed pages (dashboard, documents, notifications, settings).
    compliance_enforce($liveUser);
}

/**
 * Standalone, mobile-friendly error page (used before the app layout exists,
 * e.g. permission checks). Has its own viewport meta + styles so it never
 * renders as a tiny zoomed-out unstyled page on phones.
 */
function render_error_page(int $code, string $title, string $message): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $home = function_exists('is_logged_in') && is_logged_in() ? url('dashboard/') : url('landing/');
    $label = function_exists('is_logged_in') && is_logged_in() ? 'Back to dashboard' : 'Back to home';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . ' — KAMALIG</title>'
       . '<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'padding:20px;background:#f6f4ec;color:#2f2f2f;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}'
       . '.box{width:100%;max-width:440px;background:#fff;border:1px solid #e3dfcf;border-radius:16px;padding:28px 24px;text-align:center}'
       . '.code{font-size:2.4rem;font-weight:800;color:#26734D;margin:0}h1{font-size:1.2rem;margin:6px 0 10px}'
       . 'p{margin:0 0 20px;line-height:1.5;color:#555}a{display:inline-block;padding:11px 18px;border-radius:9px;background:#26734D;'
       . 'color:#fff;text-decoration:none;font-weight:600}</style></head><body><div class="box">'
       . '<p class="code">' . (int) $code . '</p><h1>' . htmlspecialchars($title) . '</h1>'
       . '<p>' . htmlspecialchars($message) . '</p>'
       . '<a href="' . htmlspecialchars($home) . '">' . $label . '</a></div></body></html>';
    exit;
}

function require_role($role): void {
    require_auth();
    $allowed = is_array($role) ? $role : [$role];
    if (!in_array(user_role(), $allowed, true)) {
        render_error_page(403, 'Access denied', 'You do not have permission to view this page.');
    }
}

/**
 * Like require_role(), but the allowed-roles list comes from the
 * `permissions` table (migration 014) instead of being hardcoded — an
 * admin can edit it from admin/roles/. $fallbackRoles is used whenever the
 * table has no rows yet for this feature (not migrated, or an admin
 * hasn't touched it), so this is safe to call even before the migration
 * runs — behavior matches today's require_role($fallbackRoles) exactly.
 * Fails immediately on invalid feature keys (see validate_permission_feature())
 * rather than silently falling back, preventing permission-bypass bugs.
 */
function require_permission(string $featureKey, array $fallbackRoles): void {
    validate_permission_feature($featureKey);
    require_auth();
    $allowed = permission_allowed_roles($featureKey, $fallbackRoles);
    if (!in_array(user_role(), $allowed, true)) {
        render_error_page(403, 'Access denied', 'You do not have permission to view this page.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        render_error_page(403, 'Request could not be verified', 'Please go back and try again.');
    }
}

function login_user(string $email, string $password): array {
    // Fix Guide item 9: per-IP throttle on top of the per-account lockout, so
    // one address cannot hammer the form and cannot lock real users out by itself.
    login_throttle_assert_allowed();

    $user = get_user_by_email($email);
    if (!$user) {
        // Do the same amount of hashing work as a real failed login, so response
        // time does not reveal whether an email address has an account.
        password_verify($password, '$2y$10$8/MthmFxy7bKwJMLE/VBU.YzaLuMe.X2YElcc0fD67ziMcMPtVEFG');
        login_throttle_record_failure();
        throw new RuntimeException('Invalid credentials');
    }

    // SECURITY: check for an active lockout before touching the password at
    // all. See migrations/016_add_login_lockout_fields.sql — after
    // LOGIN_LOCKOUT_THRESHOLD wrong passwords in a row, the account is
    // locked for LOGIN_LOCKOUT_MINUTES regardless of whether the *next*
    // attempt happens to be correct, closing the brute-force window this
    // form previously had no limit on.
    $lockoutUntil = get_login_lockout_until($user);
    if ($lockoutUntil !== null) {
        $minutesLeft = max(1, (int) ceil((($lockoutUntil->getTimestamp()) - time()) / 60));
        throw new RuntimeException("Too many failed attempts. Please try again in $minutesLeft minute(s).");
    }

    if (empty($user['password']) || !password_verify($password, $user['password'])) {
        record_login_failure($user['id']);
        login_throttle_record_failure();
        throw new RuntimeException('Invalid credentials');
    }

    if (($user['status'] ?? '') === 'suspended') {
        $reason = trim((string) ($user['rejection_reason'] ?? ''));
        throw new RuntimeException($reason !== ''
            ? "Your account has been suspended: $reason"
            : 'Your account has been suspended. Contact support for details.');
    }

    // Two independent gates, both required: `otp_verified` (proved control
    // of the email address) and `verified`/`status` (an admin reviewed and
    // approved the account). A verified email must never be treated as an
    // approved account — see verify-otp/index.php, which previously
    // conflated the two by writing straight to `verified` on OTP success.
    // Checking email first gives a more useful message to someone who
    // registered but never finished the OTP step (as opposed to someone
    // correctly waiting on admin review).
    if (empty($user['otp_verified'])) {
        throw new RuntimeException('Please verify your email address before logging in. Check your inbox for the verification code, or request a new one.');
    }

    // Compliance gate (Fix Guide item 7): admin approval no longer blocks
    // LOGIN — it blocks TRADING. A verified-email user who is still
    // pending or rejected logs in normally and lands on a restricted
    // dashboard (see includes/compliance.php). Only suspension (checked
    // above) and an unverified email stop a login.

    // Password and account-status checks all passed — clear any accumulated
    // failure count so a user who mistyped a couple of times earlier isn't
    // left partway toward a lockout.
    record_login_success($user['id']);

    // SECURITY: issue a fresh session ID at the moment of login. Without
    // this, a session ID an attacker set for the victim before they logged
    // in (e.g. via a crafted link, on a network that lets cookies be
    // planted) would remain valid *after* login too — a classic session
    // fixation attack. `true` also destroys the old session's data on the
    // server side.
    session_regenerate_id(true);
    $_SESSION['session_started'] = time();
    $_SESSION['last_activity'] = time();

    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'company' => $user['company'] ?? '',
        'verified' => $user['verified'],
    ];

    return $_SESSION['user'];
}

/**
 * Session-setting half of a successful Google sign-in — mirrors
 * login_user()'s tail end exactly (fresh session id against fixation,
 * same $_SESSION['user'] shape) so every other page that reads
 * $_SESSION['user'] doesn't need to know or care which auth method was
 * used. Callers (login/google/callback/index.php) are responsible for
 * having already resolved the account via google_oauth_resolve_account()
 * and confirmed it's an approved, non-suspended account — this function
 * only handles the session write, not the account-state checks, since
 * those differ enough between 'login' and freshly-'link'ed accounts that
 * duplicating login_user()'s checks here would drift out of sync with it
 * over time.
 */
function google_login_session(array $user): array {
    return start_user_session($user);
}

/**
 * Generic session-setting step shared by every sign-in path: password
 * login, Google sign-in, and the automatic sign-in right after a
 * registration's email OTP succeeds. Fresh session id against fixation.
 * Callers must have already checked that the account is not suspended.
 */
function start_user_session(array $user): array {
    session_regenerate_id(true);
    $_SESSION['session_started'] = time();
    $_SESSION['last_activity'] = time();

    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'company' => $user['company'] ?? '',
        'verified' => $user['verified'],
    ];

    return $_SESSION['user'];
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
    session_start();
}

function user_role(): string {
    // Fix Guide item 14: nobody logged in means NO role. It used to fall back
    // to 'supplier', which could show supplier features to anonymous visitors.
    $user = current_user();
    return $user['role'] ?? '';
}

function nav_items(string $role): array {
    // Dashboard is universal. Notifications now lives as a bell icon in the
    // topbar (includes/header.php) instead of a sidebar page, and Messages
    // is now the floating AI Assistant widget (includes/assistant_widget.php,
    // rendered from footer.php) instead of a full nav page — see Phase 6.
    // Documents (submitting compliance docs) belongs to account holders —
    // farmer/supplier/importer — not admin, who reviews documents rather
    // than submitting their own.
    //
    // Every item can carry a 'group' key (sidebar section header, admin
    // only — see includes/header.php) and an 'svg' key (line-icon key, see
    // nav_icon_svg() below); both are optional and fall back gracefully.
    $common = [
        ['label' => 'Dashboard', 'href' => 'dashboard/', 'icon' => '◈', 'group' => 'Main', 'svg' => 'dashboard'],
        // Transparency is a reporting layer over everyone's stock/movement/
        // price data, not any one user's own records, so it's common to
        // every role (including admin) rather than added per-role below.
        // It's its own sidebar group (not folded into Main) per Section
        // 11's "Simplify Sidebar" taxonomy: Main, Inventory, Procurement,
        // Transparency, Account.
        ['label' => 'Transparency', 'href' => 'transparency/', 'icon' => '📈', 'group' => 'Transparency', 'svg' => 'transparency'],
    ];

    $documents = [
        ['label' => 'Documents', 'href' => 'documents/', 'icon' => '▤', 'group' => 'Account', 'svg' => 'documents'],
    ];

    // Supplier/trader accounts can list their own stock (seed data already
    // has supplier1 owning a listing) as well as browse and buy from others,
    // so they get Inventory alongside Marketplace/Requests/Incoming.
    // Group labels follow Section 11's taxonomy (Main, Inventory,
    // Procurement, Transparency, Account) rather than the flat list this
    // used to render as. Supplier terminology ("Marketplace"/"Requests")
    // already matches Section 11's "Role-Specific Terminology" — no label
    // change needed here, just grouping.
    $supplier = [
        ['label' => 'Inventory', 'href' => 'inventory/', 'icon' => '📦', 'group' => 'Inventory', 'svg' => 'inventory'],
        ['label' => 'Warehouses', 'href' => 'warehouses/', 'icon' => '🏢', 'group' => 'Inventory', 'svg' => 'warehouse'],
        ['label' => 'Marketplace', 'href' => 'marketplace/', 'icon' => '🛒', 'group' => 'Procurement', 'svg' => 'marketplace'],
        ['label' => 'Cart', 'href' => 'cart/', 'icon' => '🧺', 'group' => 'Procurement', 'svg' => 'cart'],
        ['label' => 'Requests', 'href' => 'procurement/requests/', 'icon' => '✉', 'group' => 'Procurement', 'svg' => 'requests'],
        ['label' => 'Incoming', 'href' => 'procurement/incoming/', 'icon' => '⬇', 'group' => 'Procurement', 'svg' => 'delivery'],
        ['label' => 'Tracking', 'href' => 'procurement/tracking/', 'icon' => '🚚', 'group' => 'Procurement', 'svg' => 'tracking'],
        ['label' => 'Payments', 'href' => 'payments/', 'icon' => '💳', 'group' => 'Procurement', 'svg' => 'payments'],
    ];

    // "Farmers: My Harvest/My Inventory" (Section 11) — same
    // inventory/index.php page underneath, just a label that reads as the
    // farmer's own crop rather than generic warehouse stock.
    $farmer = [
        ['label' => 'My Harvest', 'href' => 'inventory/', 'icon' => '📦', 'group' => 'Inventory', 'svg' => 'inventory'],
        ['label' => 'Warehouses', 'href' => 'warehouses/', 'icon' => '🏢', 'group' => 'Inventory', 'svg' => 'warehouse'],
        ['label' => 'Incoming', 'href' => 'procurement/incoming/', 'icon' => '⬇', 'group' => 'Procurement', 'svg' => 'delivery'],
        ['label' => 'Tracking', 'href' => 'procurement/tracking/', 'icon' => '🚚', 'group' => 'Procurement', 'svg' => 'tracking'],
        ['label' => 'Payments', 'href' => 'payments/', 'icon' => '💳', 'group' => 'Procurement', 'svg' => 'payments'],
    ];

    // Importer's core function is listing imported stock, same shape as
    // farmer — previously importer fell through to $supplier's nav and had
    // no Inventory/Add Stock link at all, despite the page allowing it.
    $importer = [
        ['label' => 'Inventory', 'href' => 'inventory/', 'icon' => '📦', 'group' => 'Inventory', 'svg' => 'inventory'],
        ['label' => 'Warehouses', 'href' => 'warehouses/', 'icon' => '🏢', 'group' => 'Inventory', 'svg' => 'warehouse'],
        ['label' => 'Incoming', 'href' => 'procurement/incoming/', 'icon' => '⬇', 'group' => 'Procurement', 'svg' => 'delivery'],
        ['label' => 'Tracking', 'href' => 'procurement/tracking/', 'icon' => '🚚', 'group' => 'Procurement', 'svg' => 'tracking'],
        ['label' => 'Payments', 'href' => 'payments/', 'icon' => '💳', 'group' => 'Procurement', 'svg' => 'payments'],
    ];

    // Admin nav, grouped to match the redesigned sidebar. "Admin Home"
    // (admin/) is deliberately dropped — Dashboard already serves as the
    // administrator's home, so admin/index.php now just redirects there.
    // "Rice Inventory" and "Warehouses" are deliberately NOT included here:
    // inventory/index.php and warehouses/index.php are each-account-holder's
    // own-listing pages (require_permission('inventory'|'warehouses', ...)
    // defaults to farmer/importer/supplier only, per migration 014's seed
    // data), and neither page has any admin-specific view — admin doesn't
    // own stock or warehouses, so linking them here previously only led to
    // a 403 (or, once an admin edits admin/roles/, someone else's own-data
    // page framed as if it were an admin oversight screen). Admin already
    // gets Supply Management oversight via Varieties, Procurement Tracking,
    // and Deliveries below. Admin's own groups (Supply Management,
    // Analytics, Administration, System) are more granular than the
    // 5-group Main/Inventory/Procurement/Transparency/Account taxonomy —
    // intentional, since an admin's job is oversight across categories a
    // single flat "Procurement" or "Account" group would blur together;
    // "Admin Documents" is labeled "Compliance" per Section 11's
    // "Admins: Compliance/Analytics" terminology.
    $admin = [
        ['label' => 'Varieties', 'href' => 'admin/varieties/', 'icon' => '🌾', 'group' => 'Supply Management', 'svg' => 'varieties'],
        ['label' => 'Procurement Tracking', 'href' => 'procurement/tracking/', 'icon' => '🚚', 'group' => 'Supply Management', 'svg' => 'tracking'],
        ['label' => 'Deliveries', 'href' => 'procurement/incoming/', 'icon' => '⬇', 'group' => 'Supply Management', 'svg' => 'delivery'],
        ['label' => 'Warehouse Capacity', 'href' => 'admin/warehouses/', 'icon' => '🏭', 'group' => 'Supply Management', 'svg' => 'warehouse'],
        ['label' => 'Payments', 'href' => 'payments/', 'icon' => '💳', 'group' => 'Supply Management', 'svg' => 'payments'],

        ['label' => 'AI Insights', 'href' => 'dashboard/#ai-insights', 'icon' => '✨', 'group' => 'Analytics', 'svg' => 'ai'],
        ['label' => 'Analytics', 'href' => 'reports/', 'icon' => '📊', 'group' => 'Analytics', 'svg' => 'reports'],
        ['label' => 'Activity Log', 'href' => 'admin/activity/', 'icon' => '🕒', 'group' => 'Analytics', 'svg' => 'activity'],

        ['label' => 'Pending Verification', 'href' => 'pending-verification/', 'icon' => '⚠', 'group' => 'Administration', 'svg' => 'pending'],
        ['label' => 'Admin Accounts', 'href' => 'admin/accounts/', 'icon' => '👥', 'group' => 'Administration', 'svg' => 'accounts'],
        ['label' => 'Roles & Permissions', 'href' => 'admin/roles/', 'icon' => '🔑', 'group' => 'Administration', 'svg' => 'roles'],
        ['label' => 'Compliance', 'href' => 'admin/documents/', 'icon' => '📄', 'group' => 'Administration', 'svg' => 'documents'],

        ['label' => 'Settings', 'href' => 'admin/settings/', 'icon' => '⚙', 'group' => 'System', 'svg' => 'settings'],
        ['label' => 'Support Messages', 'href' => 'admin/messages/', 'icon' => '💬', 'group' => 'System', 'svg' => 'messages'],
    ];

    if ($role === 'admin') {
        $items = array_merge($common, $admin);
    } elseif ($role === 'farmer') {
        $items = array_merge($common, $documents, $farmer);
    } elseif ($role === 'importer') {
        $items = array_merge($common, $documents, $importer);
    } else {
        $items = array_merge($common, $documents, $supplier);
    }

    $items = array_values(array_filter($items, fn($item) => nav_item_visible_for_role($item['href'], $role)));

    // Compliance gate (Fix Guide item 7): until an admin approves the
    // account, trading items stay visible but locked, so the user can see
    // what completing compliance unlocks. Same allow-list the server-side
    // check uses (includes/compliance.php) — nav and enforcement can't drift.
    if ($role !== 'admin' && is_logged_in() && !current_user_is_compliant()) {
        foreach ($items as &$navItem) {
            if (!compliance_href_allowed($navItem['href'])) {
                $navItem['locked'] = true;
            }
        }
        unset($navItem);
    }

    return $items;
}

/**
 * Whether a nav item's href should be shown to this role right now. Maps
 * href prefixes to require_permission() feature keys so that revoking a
 * role's access from admin/roles/ immediately hides the menu link instead
 * of leaving a dead end that 403s on click. Uses the same permission_allowed_roles()
 * table, preventing drift between page access checks and menu visibility.
 * Items with no matching feature key (Dashboard, Transparency, admin-only pages,
 * etc.) are always shown.
 */
function nav_item_visible_for_role(string $href, string $role): bool {
    // Dynamic map of href prefixes to permission feature keys.
    // Built from known_permission_features() so it auto-updates when features change.
    static $prefixMap = null;
    if ($prefixMap === null) {
        $prefixMap = [
            'inventory/' => 'inventory',
            'warehouses/' => 'warehouses',
            'procurement/requests/' => 'procurement.requests.create',
            'cart/' => 'procurement.requests.create',
            'checkout/' => 'procurement.requests.create',
        ];
    }

    foreach ($prefixMap as $prefix => $featureKey) {
        if (str_starts_with($href, $prefix)) {
            $feature = known_permission_features()[$featureKey] ?? null;
            if ($feature === null) {
                // Feature key not found — this shouldn't happen, but fail closed
                // (don't show the menu item) rather than silently allowing access.
                return false;
            }
            $allowed = permission_allowed_roles($featureKey, $feature['fallback']);
            return in_array($role, $allowed, true);
        }
    }

    return true;
}

/**
 * Inline line-icon SVGs for the sidebar/topbar, keyed by the 'svg' field on
 * a nav item. Self-contained (no external icon font/CDN) so the app keeps
 * working offline under XAMPP. Falls back to a simple dot if the key is
 * unrecognised, so a missing mapping never breaks the layout.
 */
function nav_icon_svg(string $key): string {
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'transparency' => '<path d="M3 17l5-5 4 4 8-9"/><path d="M15 7h5v5"/>',
        'inventory' => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
        'varieties' => '<path d="M12 21c-4-1-7-5-7-10a7 7 0 0 1 14 0c0 5-3 9-7 10z"/><path d="M12 11v10"/>',
        'warehouse' => '<path d="M3 21V9l9-5 9 5v12"/><path d="M7 21v-7h10v7"/>',
        'marketplace' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9h16v11H4z"/><path d="M9 13a3 3 0 0 0 6 0"/>',
        'requests' => '<path d="M4 4h16v13H8l-4 4z"/>',
        'delivery' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="18" cy="18" r="1.8"/>',
        'tracking' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'ai' => '<path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/>',
        'reports' => '<path d="M4 20V10"/><path d="M12 20V4"/><path d="M20 20v-7"/><path d="M2 20h20"/>',
        'activity' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/>',
        'pending' => '<path d="M12 2L2 20h20L12 2z"/><path d="M12 9v5"/><circle cx="12" cy="17" r="0.6" fill="currentColor" stroke="none"/>',
        'accounts' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5c1.7.4 3 1.9 3 3.7s-1.3 3.3-3 3.7"/><path d="M18 14.7c2 .6 3.4 2.3 4 5.3"/>',
        'roles' => '<circle cx="8" cy="15" r="4.5"/><path d="M11.2 11.8L19 4"/><path d="M15.5 8.5L18 11"/><path d="M18.5 5.5L21 8"/>',
        'documents' => '<path d="M7 3h8l4 4v14H7z"/><path d="M15 3v4h4"/><path d="M10 13h6"/><path d="M10 17h6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.6V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.6 1H21a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.6 1z"/>',
        'messages' => '<path d="M4 4h16v13H8l-4 4z"/><path d="M8 9h8"/><path d="M8 12.5h5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bell' => '<path d="M6 10a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c1-4 4-6 7.5-6s6.5 2 7.5 6"/>',
        'logout' => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'chevron-left' => '<path d="M15 5l-7 7 7 7"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'sparkle' => '<path d="M12 3l1.4 4.6L18 9l-4.6 1.4L12 15l-1.4-4.6L6 9l4.6-1.4L12 3z"/>',
        'coins' => '<ellipse cx="9" cy="7" rx="6" ry="3.2"/><path d="M3 7v5c0 1.8 2.7 3.2 6 3.2s6-1.4 6-3.2V7"/><path d="M3 12v5c0 1.8 2.7 3.2 6 3.2 1 0 2-.1 2.8-.4"/><ellipse cx="17" cy="14.5" rx="4" ry="2.2"/><path d="M13 14.5v3c0 1.2 1.8 2.2 4 2.2s4-1 4-2.2v-3"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5l5 5"/><path d="M14.5 9.5l-5 5"/>',
        'cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M3 4h2.5l2.2 11h10.6l2-8H6.4"/>',
        'payments' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10.5h18"/><path d="M7 15h3"/>',
        'flow-arrow' => '<path d="M12 4v16"/><path d="M7 15l5 5 5-5"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    ];
    $inner = $paths[$key] ?? '<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
