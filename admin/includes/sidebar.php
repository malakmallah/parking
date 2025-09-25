<?php
/**
 * Clean Sidebar Component - LIU Parking System
 * Simplified version with consistent behavior
 */

// Ensure user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Get current page name for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Define sidebar menu items
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

// Define bottom menu items
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
?>

<style>
    :root {
        --primary: #003366;
        --gold: #FFB81C;
        --sidebar-width: 280px;
    }

    /* Base layout */
    .main-content {
        margin-left: var(--sidebar-width);
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    /* Sidebar collapsed state */
    body.sidebar-collapsed .main-content {
        margin-left: 0;
    }

    body.sidebar-collapsed .sidebar {
        transform: translateX(-100%);
    }

    /* Sidebar base styles */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: var(--sidebar-width);
        background: linear-gradient(135deg, var(--primary) 0%, #004080 100%);
        z-index: 1000;
        transition: transform 0.3s ease;
        box-shadow: 2px 0 15px rgba(0,0,0,0.1);
        overflow-y: auto;
    }

    .sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.1);
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.3);
        border-radius: 3px;
    }

    /* Header */
    .sidebar-header {
        padding: 25px 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.15);
        margin-bottom: 20px;
        position: sticky;
        top: 0;
        background: inherit;
        backdrop-filter: blur(10px);
    }

    .sidebar-header .logo {
        width: 55px;
        height: 55px;
        background: var(--gold);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        font-size: 26px;
        color: white;
        transition: transform 0.3s ease;
        box-shadow: 0 4px 15px rgba(255,184,28,0.3);
    }

    .sidebar-header .logo:hover {
        transform: scale(1.05);
    }

    .sidebar-header h4 {
        color: white;
        font-weight: 600;
        font-size: 18px;
        margin-bottom: 5px;
        letter-spacing: 0.5px;
    }

    .sidebar-header p {
        color: rgba(255,255,255,0.7);
        font-size: 12px;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Toggle button */
    .sidebar-toggle {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background: var(--primary);
        color: white;
        border: none;
        padding: 12px 14px;
        border-radius: 8px;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
        font-size: 16px;
    }

    .sidebar-toggle:hover {
        background: var(--gold);
        transform: scale(1.05);
    }

    .sidebar-toggle.active {
        background: var(--gold);
    }

    /* Menu styles */
    .sidebar-menu {
        padding: 0 15px;
    }

    .sidebar-menu .menu-item {
        margin-bottom: 8px;
    }

    .sidebar-menu .menu-link {
        display: flex;
        align-items: center;
        padding: 14px 18px;
        color: rgba(255,255,255,0.85);
        text-decoration: none;
        border-radius: 10px;
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
        transition: left 0.6s;
    }

    .sidebar-menu .menu-link:hover::before {
        left: 100%;
    }

    .sidebar-menu .menu-link:hover {
        background: rgba(255,255,255,0.12);
        color: white;
        transform: translateX(6px);
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }

    .sidebar-menu .menu-link.active {
        background: linear-gradient(135deg, rgba(255,184,28,0.2), rgba(255,255,255,0.1));
        color: white;
        border-left: 4px solid var(--gold);
        transform: translateX(6px);
        box-shadow: 0 6px 25px rgba(255,184,28,0.2);
    }

    .sidebar-menu .menu-link i {
        width: 22px;
        margin-right: 14px;
        text-align: center;
        transition: transform 0.3s ease;
        font-size: 16px;
    }

    .sidebar-menu .menu-link:hover i {
        transform: scale(1.15);
    }

    .sidebar-menu .menu-link.active i {
        color: var(--gold);
    }

    /* Divider */
    .sidebar-divider {
        border: none;
        height: 1px;
        background: rgba(255,255,255,0.15);
        margin: 25px 20px;
        opacity: 0.7;
    }

    /* Mobile styles */
    @media (max-width: 768px) {
        .main-content {
            margin-left: 0 !important;
        }

        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.show {
            transform: translateX(0);
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .sidebar-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .sidebar-close {
            display: block;
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .sidebar-close:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }
    }

    @media (min-width: 769px) {
        .sidebar-overlay {
            display: none !important;
        }

        .sidebar-close {
            display: none !important;
        }
    }

    /* Animation for menu items */
    .sidebar-menu .menu-item {
        opacity: 0;
        animation: slideInLeft 0.4s ease forwards;
    }

    .sidebar-menu .menu-item:nth-child(1) { animation-delay: 0.1s; }
    .sidebar-menu .menu-item:nth-child(2) { animation-delay: 0.15s; }
    .sidebar-menu .menu-item:nth-child(3) { animation-delay: 0.2s; }
    .sidebar-menu .menu-item:nth-child(4) { animation-delay: 0.25s; }
    .sidebar-menu .menu-item:nth-child(5) { animation-delay: 0.3s; }
    .sidebar-menu .menu-item:nth-child(6) { animation-delay: 0.35s; }
    .sidebar-menu .menu-item:nth-child(7) { animation-delay: 0.4s; }
    .sidebar-menu .menu-item:nth-child(8) { animation-delay: 0.45s; }

    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
</style>

<!-- Toggle Button -->
<button class="sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-car"></i>
        </div>
        <h4>LIU Parking</h4>
        <p>Admin Dashboard</p>
        
        <!-- Close button for mobile -->
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div class="sidebar-menu">
        <?php foreach ($sidebar_menu as $menu_item): ?>
        <div class="menu-item">
            <a href="<?= $menu_item['url'] ?>" class="menu-link <?= isMenuActive($menu_item, $current_page) ? 'active' : '' ?>">
                <i class="<?= $menu_item['icon'] ?>"></i>
                <span><?= $menu_item['title'] ?></span>
            </a>
        </div>
        <?php endforeach; ?>
        
        <hr class="sidebar-divider">
        
        <?php foreach ($sidebar_bottom_menu as $menu_item): ?>
        <div class="menu-item">
            <a href="<?= $menu_item['url'] ?>" class="menu-link <?= isMenuActive($menu_item, $current_page) ? 'active' : '' ?>">
                <i class="<?= $menu_item['icon'] ?>"></i>
                <span><?= $menu_item['title'] ?></span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const closeBtn = document.getElementById('sidebarClose');

    function isMobile() {
        return window.innerWidth <= 768;
    }

    function toggleSidebar() {
        if (isMobile()) {
            // Mobile toggle
            const isOpen = sidebar.classList.contains('show');
            if (isOpen) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.style.overflow = '';
                toggle.classList.remove('active');
            } else {
                sidebar.classList.add('show');
                overlay.classList.add('show');
                document.body.style.overflow = 'hidden';
                toggle.classList.add('active');
            }
        } else {
            // Desktop toggle
            const isCollapsed = document.body.classList.contains('sidebar-collapsed');
            if (isCollapsed) {
                document.body.classList.remove('sidebar-collapsed');
                toggle.classList.remove('active');
            } else {
                document.body.classList.add('sidebar-collapsed');
                toggle.classList.add('active');
            }
        }
    }

    function closeSidebar() {
        if (isMobile()) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
            toggle.classList.remove('active');
        }
    }

    // Event listeners
    toggle.addEventListener('click', toggleSidebar);
    
    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }
    
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Handle window resize
    window.addEventListener('resize', function() {
        if (!isMobile()) {
            // Clean up mobile states when moving to desktop
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        } else {
            // Clean up desktop states when moving to mobile
            document.body.classList.remove('sidebar-collapsed');
        }
        toggle.classList.remove('active');
    });

    // Initialize proper state
    if (!isMobile()) {
        document.body.classList.remove('sidebar-collapsed');
    }
});
</script>