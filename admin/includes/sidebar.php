<?php
/**
 * Fixed Reusable Sidebar Component - No Config File Required
 * Include this file in any page that needs the sidebar
 * Usage: include 'includes/sidebar.php';
 */

// Ensure user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Get current page name for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Define sidebar menu items directly in this file
$sidebar_menu = [
    [
        'title' => 'Dashboard',
        'icon' => 'fas fa-tachometer-alt',
        'url' => 'index.php',
        'active_pages' => ['index.php']
    ],
    [
        'title' => 'Users Management',
        'icon' => 'fas fa-users',
        'url' => 'users.php',
        'active_pages' => ['users.php', 'user-add.php', 'user-edit.php']
    ],
    [
        'title' => 'Campuses & Blocks',
        'icon' => 'fas fa-university',
        'url' => 'campuses.php',
        'active_pages' => ['campuses.php', 'campus-add.php', 'campus-edit.php']
    ],
    [
        'title' => 'Parking Spots',
        'icon' => 'fas fa-parking',
        'url' => 'spots.php',
        'active_pages' => ['spots.php', 'spot-add.php', 'spot-edit.php']
    ],
    [
        'title' => 'Gates',
        'icon' => 'fas fa-door-open',
        'url' => 'gates.php',
        'active_pages' => ['gates.php', 'gate-add.php', 'gate-edit.php']
    ],
    [
        'title' => 'Wall Codes',
        'icon' => 'fas fa-qrcode',
        'url' => 'wall_codes.php',
        'active_pages' => ['wall_codes.php', 'wall-code-generate.php']
    ],
    [
        'title' => 'Parking Sessions',
        'icon' => 'fas fa-history',
        'url' => 'sessions.php',
        'active_pages' => ['sessions.php', 'session-details.php']
    ],
    [
        'title' => 'Parking ID Cards',
        'icon' => 'fas fa-id-card',
        'url' => 'cards.php',
        'active_pages' => ['cards.php', 'card-generate.php']
    ],
];

// Define bottom menu items (separated by divider)
$sidebar_bottom_menu = [
    [
        'title' => 'Settings',
        'icon' => 'fas fa-cog',
        'url' => 'settings.php',
        'active_pages' => ['settings.php']
    ],
    [
        'title' => 'Logout',
        'icon' => 'fas fa-sign-out-alt',
        'url' => '../logout.php',
        'active_pages' => []
    ]
];

// Helper function to check if menu item should be active
function isMenuActive($menu_item, $current_page) {
    return in_array($current_page, $menu_item['active_pages']);
}

// Sidebar configuration
$sidebar_config = [
    'logo_icon' => 'fas fa-car',
    'title' => 'LIU Parking',
    'subtitle' => 'Admin Dashboard',
    'primary_color' => '#003366',
    'secondary_color' => '#FFB81C',
    'width' => '280px'
];
?>

<!-- Sidebar CSS -->
<style>
    :root {
        --primary-color: <?= $sidebar_config['primary_color'] ?>;
        --secondary-color: <?= $sidebar_config['secondary_color'] ?>;
        --sidebar-width: <?= $sidebar_config['width'] ?>;
    }

    /* Layout base */
    .main-content {
        margin-left: var(--sidebar-width);
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    /* When collapsed on desktop */
    body.sidebar-collapsed .main-content {
        margin-left: 0;
    }

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: var(--sidebar-width);
        background: linear-gradient(135deg, var(--primary-color) 0%, #004080 100%);
        z-index: 1000;
        transition: transform 0.3s ease, width 0.3s ease;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        overflow-y: auto;
    }

    /* Collapsed state (desktop) */
    @media (min-width: 769px) {
        body.sidebar-collapsed .sidebar {
            transform: translateX(-100%);
        }
    }

    .sidebar::-webkit-scrollbar { width: 5px; }
    .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.3);
        border-radius: 10px;
    }

    .sidebar-header {
        padding: 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        margin-bottom: 20px;
        position: sticky;
        top: 0;
        background: inherit;
        z-index: 1001;
    }

    .sidebar-header .logo {
        width: 50px;
        height: 50px;
        background: var(--secondary-color);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        font-size: 24px;
        color: white;
        transition: transform 0.3s ease;
    }
    .sidebar-header .logo:hover { transform: scale(1.1); }

    .sidebar-header h4 {
        color: white;
        font-weight: 600;
        font-size: 18px;
        margin-bottom: 5px;
    }
    .sidebar-header p {
        color: rgba(255,255,255,0.7);
        font-size: 12px;
        margin: 0;
    }

    /* Close button (mobile only by default; we keep it hidden on desktop) */
    .sidebar-close { display: none; }

    .sidebar-menu { padding: 0 15px; }
    .sidebar-menu .menu-item { margin-bottom: 5px; }

    .sidebar-menu .menu-link {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        border-radius: 8px;
        transition: all 0.3s ease;
        font-size: 14px;
        font-weight: 500;
        position: relative;
        overflow: hidden;
    }

    .sidebar-menu .menu-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
        transition: left 0.5s;
    }
    .sidebar-menu .menu-link:hover::before { left: 100%; }

    .sidebar-menu .menu-link:hover,
    .sidebar-menu .menu-link.active {
        background: rgba(255,255,255,0.1);
        color: white;
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    .sidebar-menu .menu-link.active {
        background: rgba(255,255,255,0.15);
        border-left: 3px solid var(--secondary-color);
    }
    .sidebar-menu .menu-link i {
        width: 20px;
        margin-right: 12px;
        text-align: center;
        transition: transform 0.3s ease;
    }
    .sidebar-menu .menu-link:hover i { transform: scale(1.2); }

    .sidebar-divider {
        border-color: rgba(255,255,255,0.1);
        margin: 20px 0;
        opacity: 0.5;
    }

    /* Toggle button: visible on both mobile and desktop */
    .sidebar-toggle {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background: var(--primary-color);
        color: white;
        border: none;
        padding: 10px 12px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
    }
    .sidebar-toggle:hover {
        background: var(--secondary-color);
        transform: scale(1.1);
    }

    /* Mobile-specific behavior */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); }
        .sidebar.show { transform: translateX(0); }

        .main-content { margin-left: 0 !important; }

        .sidebar-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            display: none; opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.show { display: block; opacity: 1; }

        /* Show the close button only on mobile */
        .sidebar-close {
            display: block;
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
            backdrop-filter: blur(2px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .sidebar-close:hover {
            transform: scale(1.05);
            background: rgba(255,255,255,0.25);
        }
        .sidebar-close i { pointer-events: none; }
    }

    /* Animation for menu items */
    .sidebar-menu .menu-item {
        opacity: 0;
        animation: slideInLeft 0.5s ease forwards;
    }
    .sidebar-menu .menu-item:nth-child(1) { animation-delay: 0.1s; }
    .sidebar-menu .menu-item:nth-child(2) { animation-delay: 0.2s; }
    .sidebar-menu .menu-item:nth-child(3) { animation-delay: 0.3s; }
    .sidebar-menu .menu-item:nth-child(4) { animation-delay: 0.4s; }
    .sidebar-menu .menu-item:nth-child(5) { animation-delay: 0.5s; }
    .sidebar-menu .menu-item:nth-child(6) { animation-delay: 0.6s; }
    .sidebar-menu .menu-item:nth-child(7) { animation-delay: 0.7s; }
    .sidebar-menu .menu-item:nth-child(8) { animation-delay: 0.8s; }
    .sidebar-menu .menu-item:nth-child(9) { animation-delay: 0.9s; }

    @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-20px); }
        to { opacity: 1; transform: translateX(0); }
    }
</style>

<!-- Toggle Button (desktop + mobile) -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true" aria-controls="sidebar">
    <i class="fas fa-bars" aria-hidden="true"></i>
</button>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar" role="navigation" aria-label="Primary">
    <div class="sidebar-header">
        <div class="logo">
            <i class="<?= $sidebar_config['logo_icon'] ?>" aria-hidden="true"></i>
        </div>
        <h4><?= $sidebar_config['title'] ?></h4>
        <p><?= $sidebar_config['subtitle'] ?></p>

        <!-- Close button (mobile only) -->
        <button class="sidebar-close" id="sidebarClose" aria-label="Close sidebar">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    
    <!-- User Info -->
    <div class="sidebar-user">

    
    <div class="sidebar-menu">
        <?php foreach ($sidebar_menu as $menu_item): ?>
        <div class="menu-item">
            <a href="<?= $menu_item['url'] ?>" class="menu-link <?= isMenuActive($menu_item, $current_page) ? 'active' : '' ?>">
                <i class="<?= $menu_item['icon'] ?>" aria-hidden="true"></i>
                <span><?= $menu_item['title'] ?></span>
            </a>
        </div>
        <?php endforeach; ?>
        
        <!-- Divider -->
        <hr class="sidebar-divider">
        
        <?php foreach ($sidebar_bottom_menu as $menu_item): ?>
        <div class="menu-item">
            <a href="<?= $menu_item['url'] ?>" class="menu-link <?= isMenuActive($menu_item, $current_page) ? 'active' : '' ?>">
                <i class="<?= $menu_item['icon'] ?>" aria-hidden="true"></i>
                <span><?= $menu_item['title'] ?></span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</nav>

<!-- Sidebar JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarClose = document.getElementById('sidebarClose');

    function isMobile() {
        return window.innerWidth <= 768;
    }

    function setAria(expanded) {
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function openSidebar() {
        if (isMobile()) {
            sidebar.classList.add('show');
            sidebarOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        } else {
            document.body.classList.remove('sidebar-collapsed');
        }
        if (sidebarToggle) sidebarToggle.style.transform = 'rotate(90deg)';
        setAria(true);
    }

    function closeSidebar() {
        if (isMobile()) {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        } else {
            document.body.classList.add('sidebar-collapsed');
        }
        if (sidebarToggle) sidebarToggle.style.transform = 'rotate(0deg)';
        setAria(false);
        // Return focus to toggle for accessibility
        if (sidebarToggle) sidebarToggle.focus();
    }

    function toggleSidebar() {
        if (isMobile()) {
            if (sidebar.classList.contains('show')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        } else {
            if (document.body.classList.contains('sidebar-collapsed')) {
                openSidebar();
            } else {
                closeSidebar();
            }
        }
    }

    // Initialize desktop state (expanded by default)
    if (!isMobile()) {
        document.body.classList.remove('sidebar-collapsed');
        setAria(true);
    }

    // Toggle button (desktop + mobile)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }

    // Close button (mobile)
    if (sidebarClose) {
        sidebarClose.addEventListener('click', function(e) {
            e.stopPropagation();
            closeSidebar();
        });
    }

    // Overlay click (mobile)
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    // Click outside to close (mobile + desktop)
    document.addEventListener('click', function(event) {
        const clickInsideSidebar = sidebar.contains(event.target);
        const clickToggle = sidebarToggle && sidebarToggle.contains(event.target);
        if (!clickInsideSidebar && !clickToggle) {
            if (isMobile() && sidebar.classList.contains('show')) {
                closeSidebar();
            }
            if (!isMobile() && !document.body.classList.contains('sidebar-collapsed')) {
                // Optional: close on outside click for desktop
                closeSidebar();
            }
        }
    });

    // Window resize: normalize states
    window.addEventListener('resize', function() {
        if (isMobile()) {
            // When moving to mobile, collapse desktop state visually
            document.body.classList.remove('sidebar-collapsed');
            setAria(false);
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
            if (sidebarToggle) sidebarToggle.style.transform = 'rotate(0deg)';
        } else {
            // When moving to desktop, ensure overlay is hidden and enable desktop collapse logic
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
            // Keep current collapsed state; default expanded
            if (!document.body.classList.contains('sidebar-collapsed')) {
                setAria(true);
                if (sidebarToggle) sidebarToggle.style.transform = 'rotate(90deg)';
            } else {
                setAria(false);
                if (sidebarToggle) sidebarToggle.style.transform = 'rotate(0deg)';
            }
        }
    });

    // Esc key closes (mobile + desktop)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (isMobile() && sidebar.classList.contains('show')) {
                closeSidebar();
            }
            if (!isMobile() && !document.body.classList.contains('sidebar-collapsed')) {
                closeSidebar();
            }
        }
    });

    // Smooth scroll to active menu item
    const activeMenuItem = document.querySelector('.sidebar-menu .menu-link.active');
    if (activeMenuItem) {
        setTimeout(() => {
            activeMenuItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 300);
    }
});
</script>
