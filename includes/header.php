<?php
require_once __DIR__ . '/bootstrap.php';

$user = current_user();
$role = user_role();
$nav = nav_items($role);
$activePage = $activePage ?? 'dashboard';
$unreadNotifications = is_logged_in() ? get_unread_notifications(current_user_id() ?? '', 5) : [];
$unreadNotificationCount = is_logged_in() ? count_unread_notifications(current_user_id() ?? '') : 0;
$currentUrl = $_SERVER['REQUEST_URI'] ?? url('dashboard/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? 'KAMALIG — Rice Supply'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($meta_description ?? 'KAMALIG: integrated rice supply transparency and inventory management for local and imported stock.'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/styles.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/dashboard.css')); ?>">
    <!-- Fonts are self-hosted (assets/fonts): Manrope headings + Inter body, no third-party font requests. -->
    <link rel="preload" href="<?php echo htmlspecialchars(url('assets/fonts/kg-display-latin.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?php echo htmlspecialchars(url('assets/fonts/kg-sans-latin.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/fonts.css')); ?>">
    <?php if (($pageClass ?? '') === 'landing-page'): ?>
        <meta property="og:title" content="<?php echo htmlspecialchars($title ?? 'KAMALIG'); ?>">
        <meta property="og:description" content="KAMALIG gives suppliers, farmers, and importers one shared view of rice stock, pricing, and origin.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="<?php echo htmlspecialchars(url('landing/')); ?>">
        <meta property="og:image" content="<?php echo htmlspecialchars(url('assets/og-image.svg')); ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?php echo htmlspecialchars(url('assets/og-image.svg')); ?>">
    <?php endif; ?>
    <?php if (($pageClass ?? '') === 'landing-page'): ?>
        <meta name="theme-color" content="#174D35">
        <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/landing.css')); ?>">
        <script>document.documentElement.className+=' js';</script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/responsive.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/commerce.css')); ?>">
    <?php if (($pageClass ?? '') === 'landing-page'): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(url('assets/landing-redesign.css')); ?>?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/landing-redesign.css'); ?>">
    <?php endif; ?>
</head>
<body class="<?php echo htmlspecialchars($pageClass ?? ''); ?>">
    <?php if (($pageClass ?? '') === 'landing-page'): ?>
        <!-- Landing page: no app shell or sidebar -->
    <?php else: ?>
        <div class="app-shell" id="app-shell">
            <?php if (is_logged_in()): ?>
                <aside class="sidebar" id="sidebar">
                    <div class="brand">
                        <img src="<?php echo htmlspecialchars(url('assets/logo/kamalig-icon.png')); ?>" alt="KAMALIG" class="brand__mark">
                        <div class="brand__text">
                            <h2>KAMALIG</h2>
                            <p class="brand__subtitle">Rice Supply Intelligence</p>
                        </div>
                        <button type="button" class="sidebar-collapse-btn no-print" id="sidebar-collapse-btn" aria-label="Collapse sidebar" title="Collapse sidebar">
                            <?php echo nav_icon_svg('chevron-left'); ?>
                        </button>
                    </div>
                    <nav>
                        <?php
                        $renderedGroup = null;
                        foreach ($nav as $item):
                            $group = $item['group'] ?? null;
                            if ($group !== null && $group !== $renderedGroup):
                                $renderedGroup = $group;
                        ?>
                            <p class="nav-group-label"><?php echo htmlspecialchars(strtoupper($group)); ?></p>
                        <?php endif; ?>
                            <?php if (!empty($item['locked'])): ?>
                            <span class="nav-link nav-link--locked" aria-disabled="true"
                               title="Locked until an admin approves your compliance documents"
                               data-tooltip="Locked until approved">
                                <span class="nav-icon"><?php echo nav_icon_svg($item['svg'] ?? ''); ?></span>
                                <span class="nav-label"><?php echo htmlspecialchars($item['label']); ?></span>
                                <span class="nav-lock"><?php echo nav_icon_svg('lock'); ?></span>
                            </span>
                            <?php continue; endif; ?>
                            <a href="<?php echo htmlspecialchars(url($item['href'])); ?>"
                               class="nav-link <?php echo $activePage === strtolower(str_replace(['/', ' ', '.php'], ['', '-', ''], $item['href'])) ? 'active' : ''; ?>"
                               data-tooltip="<?php echo htmlspecialchars($item['label']); ?>">
                                <span class="nav-icon"><?php echo nav_icon_svg($item['svg'] ?? ''); ?></span>
                                <span class="nav-label"><?php echo htmlspecialchars($item['label']); ?></span>
                                <?php if (($item['href'] ?? '') === 'cart/' && ($cartBadge = cart_count(current_user_id() ?? '')) > 0): ?>
                                    <span class="nav-badge" aria-label="<?php echo (int) $cartBadge; ?> items in cart"><?php echo (int) $cartBadge; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </aside>
                <div class="sidebar-scrim" id="sidebar-scrim"></div>
            <?php endif; ?>

            <main class="content">
                <?php if (is_logged_in()): ?>
                    <header class="topbar">
                        <div class="topbar-heading">
                            <button type="button" class="icon-btn mobile-menu-btn no-print" id="mobile-menu-btn" aria-label="Open menu">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/></svg>
                            </button>
                            <h1><?php echo htmlspecialchars($title ?? 'Dashboard'); ?></h1>
                            <p class="topbar-breadcrumb">Home / <?php echo htmlspecialchars($title ?? 'Dashboard'); ?></p>
                        </div>
                        <div class="topbar-actions">
                            <?php if (current_user_is_compliant()): ?>
                            <div class="topbar-search no-print">
                                <button type="button" class="topbar-search__btn" id="topbar-search-btn" aria-label="Search" data-tooltip="Search">
                                    <?php echo nav_icon_svg('search'); ?>
                                </button>
                                <?php // Was a bare <input> with no <form> around it and no `name` — it
                                      // visually opened/closed but typing + Enter did nothing at all,
                                      // since there was nothing to submit to. Wrapping it in a real GET
                                      // form to /search/ is what makes it actually search. ?>
                                <form action="<?php echo htmlspecialchars(url('search/')); ?>" method="get" role="search">
                                    <input type="search" name="q" class="topbar-search__input" id="topbar-search-input" placeholder="Search KAMALIG…" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" autocomplete="off">
                                </form>
                            </div>
                            <?php endif; ?>
                            <div class="notif-bell no-print" data-poll-url="<?php echo htmlspecialchars(url('notifications/poll/')); ?>" data-notif-url="<?php echo htmlspecialchars(url('notifications/')); ?>" data-action-url="<?php echo htmlspecialchars(url('notifications/')); ?>" data-return-to="<?php echo htmlspecialchars($currentUrl); ?>">
                                <input type="checkbox" id="notif-toggle" class="notif-toggle">
                                <label for="notif-toggle" class="notif-bell__button" aria-label="Notifications" data-tooltip="Notifications">
                                    <?php echo nav_icon_svg('bell'); ?>
                                    <?php if ($unreadNotificationCount > 0): ?>
                                        <span class="notif-bell__badge"><?php echo htmlspecialchars($unreadNotificationCount > 9 ? '9+' : (string) $unreadNotificationCount); ?></span>
                                    <?php endif; ?>
                                </label>
                                <div class="notif-dropdown">
                                    <div class="notif-dropdown__header">
                                        <strong>Notifications</strong>
                                        <?php if ($unreadNotificationCount > 0): ?>
                                            <form method="post" action="<?php echo htmlspecialchars(url('notifications/')); ?>">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="mark_all">
                                                <input type="hidden" name="returnTo" value="<?php echo htmlspecialchars($currentUrl); ?>">
                                                <button class="notif-dropdown__markall" type="submit">Mark all as read</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (empty($unreadNotifications)): ?>
                                        <p class="muted notif-dropdown__empty">No unread notifications.</p>
                                    <?php else: ?>
                                        <?php foreach ($unreadNotifications as $note): ?>
                                            <form method="post" action="<?php echo htmlspecialchars(url('notifications/')); ?>" class="notif-dropdown__item">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="notificationId" value="<?php echo htmlspecialchars($note['id']); ?>">
                                                <input type="hidden" name="returnTo" value="<?php echo htmlspecialchars($currentUrl); ?>">
                                                <button type="submit" class="notif-dropdown__item-btn">
                                                    <span class="notif-dropdown__title"><?php echo htmlspecialchars($note['title']); ?></span>
                                                    <span class="notif-dropdown__msg"><?php echo htmlspecialchars($note['message']); ?></span>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars(url('notifications/')); ?>" class="notif-dropdown__viewall">View all notifications →</a>
                                </div>
                            </div>
                            <a href="<?php echo htmlspecialchars(url($role === 'admin' ? 'admin/settings/' : 'settings/')); ?>" class="icon-btn no-print" aria-label="Settings" data-tooltip="Settings">
                                <?php echo nav_icon_svg('settings'); ?>
                            </a>
                            <div class="profile-menu no-print">
                                <input type="checkbox" id="profile-toggle" class="profile-toggle">
                                <label for="profile-toggle" class="topbar-user" aria-label="Account menu" data-tooltip="Account">
                                    <span class="topbar-user__avatar"><?php echo nav_icon_svg('user'); ?></span>
                                    <span class="topbar-user__name"><?php echo htmlspecialchars($user['name'] ?? 'Admin User'); ?></span>
                                    <span class="topbar-user__chevron"><?php echo nav_icon_svg('chevron-down'); ?></span>
                                </label>
                                <div class="profile-dropdown">
                                    <div class="profile-dropdown__header">
                                        <span class="profile-dropdown__avatar"><?php echo nav_icon_svg('user'); ?></span>
                                        <div>
                                            <p class="profile-dropdown__name"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></p>
                                            <p class="profile-dropdown__role muted"><?php echo htmlspecialchars(ucfirst($role)); ?></p>
                                        </div>
                                    </div>
                                    <a href="<?php echo htmlspecialchars(url($role === 'admin' ? 'admin/settings/' : 'settings/')); ?>" class="profile-dropdown__item">
                                        <?php echo nav_icon_svg('settings'); ?>
                                        <span>Settings</span>
                                    </a>
                                    <?php if ($role === 'admin'): ?>
                                    <a href="<?php echo htmlspecialchars(url('settings/')); ?>" class="profile-dropdown__item">
                                        <?php echo nav_icon_svg('user'); ?>
                                        <span>Account &amp; password</span>
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars(url('logout/')); ?>" class="profile-dropdown__item profile-dropdown__item--danger">
                                        <?php echo nav_icon_svg('logout'); ?>
                                        <span>Logout</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </header>
                <?php endif; ?>
                <section class="page-card">
    <?php endif; ?>
