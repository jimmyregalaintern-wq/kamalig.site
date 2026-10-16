<?php
/**
 * KAMALIG Privacy Notice (public page, no login needed). Version 1.0
 * The operator name and contact email come from the settings table
 * (PRIVACY_OPERATOR_NAME, PRIVACY_CONTACT_EMAIL); see the installer notes.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$operator = '';
$contact = '';
try {
    $operator = trim(get_setting('PRIVACY_OPERATOR_NAME', ''));
    $contact = trim(get_setting('PRIVACY_CONTACT_EMAIL', ''));
} catch (Throwable $e) {
    // The page must still render if settings cannot be read.
}
if ($operator === '') {
    $operator = 'the KAMALIG operators';
}
$h = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Notice - KAMALIG</title>
<style>
:root{--ink:#1d2a1d;--muted:#586458;--line:#d7ded7;--bg:#f4f6f4;--leaf:#1b6e2e}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.top{background:#fff;border-bottom:1px solid var(--line)}
.top .in{max-width:860px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:12px}
.top img{height:32px;width:auto}
.top a{color:var(--ink);font-weight:700;text-decoration:none;letter-spacing:.02em}
main{max-width:860px;margin:0 auto;padding:28px 20px 56px}
h1{font-size:1.9rem;margin:0 0 6px}
.meta{color:var(--muted);font-size:.9rem;margin:0 0 22px}
h2{font-size:1.15rem;margin:30px 0 8px}
p,li{margin:.4em 0}
ul{padding-left:22px}
a{color:var(--leaf)}
.note{background:#fff;border:1px solid var(--line);border-left:4px solid var(--leaf);padding:12px 16px;border-radius:8px}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);font-size:.93rem}
th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top}
th{background:#eef2ee}
.links{margin-top:34px;padding-top:16px;border-top:1px solid var(--line);font-size:.92rem}
</style>
</head>
<body>
<div class="top"><div class="in">
    <img src="<?php echo $h(url('assets/logo/kamalig-icon.png')); ?>" alt="">
    <a href="<?php echo $h(url('landing/')); ?>">KAMALIG</a>
</div></div>
<main>
<h1>Privacy Notice</h1>
<p class="meta">Version 1.0 &middot; Last updated 13 October 2026</p>

<div class="note">
    This notice explains what personal data KAMALIG collects, why, who receives it, how long it is kept, and the rights you have under the Data Privacy Act of 2012 (Republic Act No. 10173). It applies to everyone who creates an account or uses the platform.
</div>

<h2>1. Who we are</h2>
<p>KAMALIG is a rice supply transparency and inventory platform for farmers, importers and suppliers. It is operated by <?php echo $h($operator); ?>. For any privacy question or request, contact us at
<?php if ($contact !== ''): ?><a href="mailto:<?php echo $h($contact); ?>"><?php echo $h($contact); ?></a><?php else: ?>the KAMALIG administrator<?php endif; ?>.</p>

<h2>2. What we collect</h2>
<ul>
    <li><strong>Account details:</strong> name, email address, role (farmer, importer or supplier), company or group name, phone number and location. Your password is stored only as a one-way hash, so nobody can read it. If you sign in with Google, we receive your Google name, email address and account identifier (never your Google password).</li>
    <li><strong>Verification documents:</strong> business permits or registration, import permits, cooperative or association certificates, valid government ID, and tax ID numbers, together with the document name and the text read from the document by our automated checks.</li>
    <li><strong>Trading records:</strong> your stock listings, warehouses, requests, orders and shipment details, the delivery address and contact phone you give at checkout, the payment method, payment reference numbers and proof-of-payment images, and your messages and notifications.</li>
    <li><strong>Activity records:</strong> an audit trail of important actions (for example registration, document uploads, approvals, setting changes and AI assistant use) so that we can investigate problems and misuse.</li>
    <li><strong>Technical data:</strong> a session cookie that keeps you signed in. Security checks and our hosting provider also process your IP address. We do not use advertising or tracking cookies.</li>
</ul>

<h2>3. Why we use it</h2>
<ul>
    <li>to create and manage your account and send sign-up codes, password resets and order notifications;</li>
    <li>to verify that traders are genuine, by having an administrator review your documents before you can trade;</li>
    <li>to run the marketplace: listings, requests, orders, payment tracking and delivery records;</li>
    <li>to provide the AI features, such as document checks, the assistant and market insights;</li>
    <li>to keep the platform secure and prevent fraud and abuse.</li>
</ul>
<p>We rely on your consent, which you give when you create an account, and on what is necessary to provide the service you asked for. Government-issued IDs and tax identifiers are treated as sensitive personal information and are used only for verification.</p>

<h2>4. Who can see your data</h2>
<ul>
    <li><strong>Other users</strong> see only what a transaction needs: your company name, your listing details, and the order, delivery and payment details of orders you share with them.</li>
    <li><strong>Administrators</strong> can see account details and your verification documents, in order to review and approve accounts.</li>
    <li>Your verification documents and payment proofs are kept in private storage. They are not public links; only you and administrators can open them.</li>
    <li>We do not sell your personal data.</li>
</ul>

<h2>5. Service providers and transfers abroad</h2>
<p>We use the following providers. Some process data on servers outside the Philippines. Optional features are used only when they are switched on.</p>
<div class="tw"><table>
    <tr><th>Provider</th><th>Used for</th><th>What it receives</th></tr>
    <tr><td>Hostinger</td><td>Web hosting, database and email delivery</td><td>All data stored by the platform; your email address and the messages we send you</td></tr>
    <tr><td>Google</td><td>Sign in with Google; text reading (Cloud Vision); web fonts</td><td>Your Google profile when you choose Google sign-in; images of uploaded documents when text reading is on; your IP address when fonts load</td></tr>
    <tr><td>Anthropic</td><td>AI document checks, the AI assistant and market insights</td><td>Text read from your documents (up to 6,000 characters) and the details you declared; your questions to the assistant and the platform data needed to answer them</td></tr>
    <tr><td>Cloudflare Turnstile</td><td>Bot protection on sign-up and sign-in</td><td>Your IP address and browser signals</td></tr>
</table></div>
<p>AI results are only an aid. An administrator makes the final decision on every document.</p>

<h2>6. How long we keep it</h2>
<ul>
    <li>Account, trading and payment records are kept while your account is active, and afterwards for as long as needed for record-keeping, dispute handling and legal obligations.</li>
    <li>Accounts that never complete verification are deleted after a set period (30 days by default).</li>
    <li>When you ask us to delete your data, we delete or anonymise it, except records we must keep.</li>
</ul>

<h2>7. How we protect it</h2>
<p>Passwords are hashed. Verification documents and payment proofs are stored privately and opened only after an access check. Access is limited by role, important actions are logged, and the site is served over HTTPS. No system is completely secure. If a personal data breach affects you, we will notify you and the National Privacy Commission as the law requires.</p>

<h2>8. Your rights</h2>
<p>Under the Data Privacy Act you have the right to:</p>
<ul>
    <li>be informed about how your data is processed;</li>
    <li>access the personal data we hold about you;</li>
    <li>object to processing, and withdraw your consent;</li>
    <li>correct inaccurate or outdated data;</li>
    <li>request erasure or blocking of your data;</li>
    <li>receive a copy of your data in a usable format (data portability);</li>
    <li>claim damages if your data was misused;</li>
    <li>file a complaint with the National Privacy Commission (privacy.gov.ph).</li>
</ul>
<p>To use any of these rights, contact us as shown in section 1. We will answer as soon as we reasonably can. Some features, such as trading, cannot work without the data they need, so withdrawing consent may mean closing your account.</p>

<h2>9. Age</h2>
<p>KAMALIG is intended for adults who trade rice as individuals or businesses. It is not meant for children.</p>

<h2>10. Changes to this notice</h2>
<p>If we change this notice, we will post the new version here with a new date, and tell you about important changes.</p>

<div class="links">
    <a href="<?php echo $h(url('terms/')); ?>">Terms of Use</a> &middot;
    <a href="<?php echo $h(url('landing/')); ?>">Back to KAMALIG</a>
</div>
</main>
</body>
</html>
