<?php
require_once __DIR__ . '/../../core/Auth.php';
$auth = new Auth();
$user = $auth->getUser();
?>

<header class="header">
    <div class="header-left">
        <button class="mobile-menu-btn" onclick="toggleSidebar()">
            <i>☰</i>
        </button>
        <h1 class="header-title"><?= $pageTitle ?? 'Система мониторинга ресурсов' ?></h1>
    </div>
    
    <div class="header-right">
        <div class="user-menu" onclick="toggleUserMenu(event)">
            <div class="user-avatar">
                <?php if ($user): ?>
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                <?php else: ?>
                    ?
                <?php endif; ?>
            </div>
            <div>
                <div style="font-weight: 600; font-size: 14px;">
                    <?= $user['full_name'] ?? 'Гость' ?>
                </div>
                <div style="font-size: 12px; color: var(--text-light);">
                    <?= USER_ROLES[$user['role']] ?? 'Пользователь' ?>
                </div>
            </div>
            <div style="margin-left: 8px;">▼</div>
        </div>
    </div>
</header>

<div id="userMenu" style="display: none; position: absolute; right: 20px; top: 60px; background: white; border-radius: 8px; box-shadow: var(--shadow-lg); z-index: 1000;">
    <a href="/resource_monitoring/templates/profile.php" style="display: block; padding: 12px 20px; color: var(--text-primary); text-decoration: none; border-bottom: 1px solid #f0f0f0;">Мой профиль</a>
    <a href="/resource_monitoring/templates/settings.php" style="display: block; padding: 12px 20px; color: var(--text-primary); text-decoration: none; border-bottom: 1px solid #f0f0f0;">Настройки</a>
    <a href="/resource_monitoring/logout.php" style="display: block; padding: 12px 20px; color: var(--danger); text-decoration: none;">Выйти</a>
</div>

<script>
function toggleUserMenu(event) {
    event.stopPropagation();
    const menu = document.getElementById('userMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}

// Закрыть меню при клике вне его
document.addEventListener('click', function(event) {
    const menu = document.getElementById('userMenu');
    const userMenuBtn = document.querySelector('.user-menu');
    
    if (menu.style.display === 'block' && 
        !menu.contains(event.target) && 
        !userMenuBtn.contains(event.target)) {
        menu.style.display = 'none';
    }
});
</script>