<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/Auth.php';

$auth = new Auth();
$auth->requireLogin();

$activePage = $activePage ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Система мониторинга ресурсов';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> | Система мониторинга</title>
    
    <!-- Стили -->
    <link rel="stylesheet" href="/resource_monitoring/public/css/style.css">
    <link rel="stylesheet" href="/resource_monitoring/public/css/dashboard.css">
    
    <!-- Chart.js -->
    <script src="/resource_monitoring/public/lib/Chart.bundle.min.js"></script>
</head>
<body>
    <div class="app-wrapper">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        
        <div class="main-content">
            <?php include __DIR__ . '/partials/header.php'; ?>
            
            <main>
                <?= $content ?? '' ?>
            </main>
            
            <?php include __DIR__ . '/partials/footer.php'; ?>
        </div>
    </div>
    
    <!-- Скрипты -->
    <script src="/resource_monitoring/public/js/main.js"></script>
    <script src="/resource_monitoring/public/js/charts.js"></script>
    <script src="/resource_monitoring/public/js/alerts.js"></script>
    
    <?php if (isset($scripts)): ?>
        <?= $scripts ?>
    <?php endif; ?>
</body>
</html>