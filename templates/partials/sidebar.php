<?php
require_once __DIR__ . '/../../core/Auth.php';
$auth = new Auth();
$userRole = $auth->getRole();
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            🏭 СИСТЕМА МОНИТОРИНГА
            <small>Ресурсов предприятия</small>
        </div>
    </div>
    
    <ul class="sidebar-menu">
        <li class="sidebar-item">
            <a href="/resource_monitoring/" class="sidebar-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <i>📊</i>
                <span>Дашборд</span>
            </a>
        </li>
        
        <li class="sidebar-item">
            <a href="/resource_monitoring/templates/sensors.php" class="sidebar-link <?= $activePage === 'sensors' ? 'active' : '' ?>">
                <i>📡</i>
                <span>Счётчики</span>
            </a>
        </li>
        
        <li class="sidebar-item">
            <a href="#" class="sidebar-link <?= in_array($activePage, ['alerts', 'active_alerts']) ? 'active' : '' ?>" onclick="toggleSubmenu(event, 'alerts')">
                <i>🚨</i>
                <span>Аварии</span>
                <span style="margin-left: auto; font-size: 18px;">▶</span>
            </a>
            <ul class="sidebar-submenu" id="alerts-submenu">
                <li><a href="/resource_monitoring/templates/alerts.php">Журнал событий</a></li>
                <li><a href="/resource_monitoring/templates/alerts.php?status=active">Активные аварии</a></li>
            </ul>
        </li>
        
        <li class="sidebar-item">
            <a href="/resource_monitoring/templates/analytics.php" class="sidebar-link <?= $activePage === 'analytics' ? 'active' : '' ?>">
                <i>📈</i>
                <span>Аналитика</span>
            </a>
        </li>
        
        <li class="sidebar-item">
            <a href="/resource_monitoring/templates/reports.php" class="sidebar-link <?= $activePage === 'reports' ? 'active' : '' ?>">
                <i>📄</i>
                <span>Отчёты</span>
            </a>
        </li>
        
        <?php if (in_array($userRole, ['admin', 'manager'])): ?>
        <li class="sidebar-item">
            <a href="#" class="sidebar-link <?= in_array($activePage, ['admin_users', 'admin_zones', 'admin_settings']) ? 'active' : '' ?>" onclick="toggleSubmenu(event, 'admin')">
                <i>⚙️</i>
                <span>Администрирование</span>
                <span style="margin-left: auto; font-size: 18px;">▶</span>
            </a>
            <ul class="sidebar-submenu" id="admin-submenu">
                <li><a href="/resource_monitoring/templates/admin/users.php">Пользователи</a></li>
                <li><a href="/resource_monitoring/templates/admin/zones.php">Зоны</a></li>
                <li><a href="/resource_monitoring/templates/admin/settings.php">Настройки</a></li>
            </ul>
        </li>
        <?php endif; ?>
    </ul>
</aside>

<script>
function toggleSubmenu(event, menuId) {
    event.preventDefault();
    const submenu = document.getElementById(menuId + '-submenu');
    const arrow = event.currentTarget.querySelector('span:last-child');
    
    if (submenu) {
        const isVisible = submenu.classList.contains('show');
        submenu.classList.toggle('show');
        arrow.textContent = isVisible ? '▶' : '▼';
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('show');
}

// Закрыть сайдбар при клике вне его на мобильных
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const menuBtn = document.querySelector('.mobile-menu-btn');
    
    if (window.innerWidth < 992 && sidebar.classList.contains('show') && 
        !sidebar.contains(event.target) && !menuBtn.contains(event.target)) {
        sidebar.classList.remove('show');
    }
});
</script>