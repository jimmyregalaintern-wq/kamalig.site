<?php
/**
 * KAMALIG Terms of Use (public page, no login needed). Version 1.0
 * The operator name and contact email come from the settings table
 * (PRIVACY_OPERATOR_NAME, PRIVACY_CONTACT_EMAIL).
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
<title>Terms of Use - KAMALIG</title>
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
.links{margin-top:34px;padding-top:16px;border-top:1px solid var(--line);font-size:.92rem}
</style>
</head>
<body>
<div class="top"><div class="in">
    <img src="<?php echo $h(url('assets/logo/kamalig-icon.png')); ?>" alt="">
    <a href="<?php echo $h(url('landing/')); ?>">KAMALIG</a>
</div></div>
<main>
<h1>Terms of Use</h1>
<p class="meta">Version 1.0 &middot; Last updated 13 October 2026</p>

<div class="note">
    By creating an account or using KAMALIG you agree to these terms and to the <a href="<?php echo $h(url('privacy/')); ?>">Privacy Notice</a>. If you do not agree, please do not use the platform.
</div>

<h2>1. About KAMALIG</h2>
<p>KAMALIG is a rice supply transparency and inventory platform operated by <?php echo $h($operator); ?>. It helps farmers, importers and suppliers list stock, request and order rice, track shipments and record payments.</p>

<h2>2. Accounts and verification</h2>
<ul>
    <li>Give accurate information and keep it up to date. You are responsible for your password and for everything done through your account.</li>
    <li>You may sign in right after verifying your email, but listing, requests, cart and payments unlock only after an administrator approves your documents.</li>
    <li>Documents you upload must be genuine and belong to you or your business. We may reject documents, ask you to resubmit them, or suspend an account that gives false information.</li>
</ul>

<h2>3. KAMALIG's role</h2>
<p>KAMALIG is a platform that connects traders. Each sale is an agreement between the buyer and the seller. We do not own, inspect, store or deliver rice, and we do not guarantee the quality, quantity, price or delivery of any listing. Traders are responsible for complying with the laws that apply to them, including permits and food safety rules.</p>

<h2>4. Payments</h2>
<p>KAMALIG does not process, hold or transfer money. Buyers pay sellers directly (for example by bank transfer, GCash, Maya or cash on delivery). The platform records the payment method, reference and proof, and the seller confirms receipt. Keep your own proof of payment. If a buyer and seller disagree, the records on the platform can help, but we do not guarantee any outcome.</p>

<h2>5. Automated checks and AI</h2>
<p>KAMALIG uses automated tools, including AI, to read documents, answer questions and show market insights. These are aids, not professional advice. They can be wrong, and forecasts show trends rather than certainties. Check important information yourself before you rely on it. An administrator makes the final decision on every verification.</p>

<h2>6. Acceptable use</h2>
<p>You agree not to:</p>
<ul>
    <li>post false listings, fake documents or misleading prices;</li>
    <li>use another person's account or identity;</li>
    <li>harass other users or send spam;</li>
    <li>try to break, overload, scrape or gain unauthorised access to the platform;</li>
    <li>upload harmful files or content you have no right to share.</li>
</ul>
<p>We may suspend or close accounts that break these rules.</p>

<h2>7. Your content and data</h2>
<p>You keep ownership of the information and documents you provide. You allow us to process them to run the platform, as described in the Privacy Notice.</p>

<h2>8. Availability and changes</h2>
<p>We work to keep KAMALIG running, but it is provided as is and may be interrupted, changed or withdrawn. We may update these terms; the new version will be posted here with a new date, and continued use means you accept it.</p>

<h2>9. Limits of responsibility</h2>
<p>To the extent the law allows, KAMALIG and its operators are not responsible for losses that come from dealings between users, from information on the platform being wrong or late, or from the platform being unavailable. Nothing here limits rights that the law does not allow to be limited.</p>

<h2>10. Governing law and contact</h2>
<p>These terms are governed by the laws of the Republic of the Philippines. Questions can be sent to
<?php if ($contact !== ''): ?><a href="mailto:<?php echo $h($contact); ?>"><?php echo $h($contact); ?></a><?php else: ?>the KAMALIG administrator<?php endif; ?>.</p>

<div class="links">
    <a href="<?php echo $h(url('privacy/')); ?>">Privacy Notice</a> &middot;
    <a href="<?php echo $h(url('landing/')); ?>">Back to KAMALIG</a>
</div>
</main>
</body>
</html>
