<?php
// C:\xampp\htdocs\resource_monitoring\index.php

session_start(); // ВАЖНО: всегда первая строка до любого вывода HTML

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
    // Пользователь не авторизован - перенаправляем на страницу входа
    header('Location: /resource_monitoring/templates/login.php');
    exit(); // ВАЖНО: завершаем выполнение сразу после редиректа
}

// Если пользователь авторизован, показываем основной контент
$pageTitle = 'Дашборд | Мониторинг ресурсов';
$activePage = 'dashboard';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/resource_monitoring/public/css/style.css">
    <link rel="stylesheet" href="/resource_monitoring/public/css/dashboard.css">
</head>
<body>
    <div class="app-wrapper">
        <?php include __DIR__ . '/templates/partials/sidebar.php'; ?>
        
        <div class="main-content">
            <?php include __DIR__ . '/templates/partials/header.php'; ?>
            
            <main class="container">
                <div class="dashboard-header">
                    <h1>📊 Дашборд мониторинга</h1>
                    <div class="dashboard-welcome">
                        Добро пожаловать, <strong><?= htmlspecialchars($_SESSION['login'] ?? 'Пользователь') ?></strong>
                    </div>
                </div>
                
                <!-- Статистика -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">💧</div>
                        <div class="stat-value" id="waterConsumption">0</div>
                        <div class="stat-label">Потребление воды</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🔥</div>
                        <div class="stat-value" id="heatConsumption">0</div>
                        <div class="stat-label">Потребление тепла</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⚡</div>
                        <div class="stat-value" id="electricityConsumption">0</div>
                        <div class="stat-label">Потребление эл/энергии</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🚨</div>
                        <div class="stat-value" id="activeAlerts">0</div>
                        <div class="stat-label">Активные аварии</div>
                    </div>
                </div>
                
                <!-- Графики -->
                <div class="charts-section">
                    <div class="chart-card">
                        <h3>📈 Потребление за сутки</h3>
                        <canvas id="dailyConsumptionChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3>⚠️ Аварийные события</h3>
                        <canvas id="alertsChart"></canvas>
                    </div>
                </div>
                
                <!-- Последние аварии -->
                <div class="recent-alerts">
                    <h3>🚨 Последние аварийные события</h3>
                    <div id="recentAlertsList">
                        <!-- Список аварий будет загружен через JS -->
                        <div style="text-align: center; padding: 40px; color: #718096;">
                            Загрузка событий...
                        </div>
                    </div>
                </div>
            </main>
            
            <?php include __DIR__ . '/templates/partials/footer.php'; ?>
        </div>
    </div>
    
    <script src="/resource_monitoring/public/lib/Chart.bundle.min.js"></script>
    <script>
        // Загрузка статистики
        async function loadDashboardStats() {
            try {
                const response = await fetch('/resource_monitoring/api.php/dashboard/stats', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('waterConsumption').textContent = data.data.water || 0;
                    document.getElementById('heatConsumption').textContent = data.data.heat || 0;
                    document.getElementById('electricityConsumption').textContent = data.data.electricity || 0;
                    document.getElementById('activeAlerts').textContent = data.data.active_alerts || 0;
                }
            } catch (error) {
                console.error('Ошибка загрузки статистики:', error);
            }
        }
        
        // Загрузка аварий
        async function loadRecentAlerts() {
            try {
                const response = await fetch('/resource_monitoring/api.php/alerts?status=active&limit=5', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const data = await response.json();
                
                const alertsList = document.getElementById('recentAlertsList');
                
                if (data.success && data.data.length > 0) {
                    alertsList.innerHTML = data.data.map(alert => `
                        <div class="alert-item">
                            <div class="alert-type">${alert.alert_level === 'critical' ? '🔴' : alert.alert_level === 'emergency' ? '🟠' : '🟡'} ${alert.alert_type}</div>
                            <div class="alert-description">${alert.description}</div>
                            <div class="alert-time">${new Date(alert.alert_timestamp).toLocaleString('ru-RU')}</div>
                        </div>
                    `).join('');
                } else {
                    alertsList.innerHTML = '<p style="text-align: center; color: #a0aec0;">Нет активных аварий</p>';
                }
            } catch (error) {
                console.error('Ошибка загрузки аварий:', error);
                document.getElementById('recentAlertsList').innerHTML = '<p style="color: #e53e3e;">Ошибка загрузки событий</p>';
            }
        }
        
        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            loadDashboardStats();
            loadRecentAlerts();
            
            // Инициализация графиков (пример)
            initCharts();
        });
        
        function initCharts() {
            // Пример инициализации графиков
            const dailyCtx = document.getElementById('dailyConsumptionChart').getContext('2d');
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00', '24:00'],
                    datasets: [{
                        label: 'Вода',
                        data: [0.5, 0.3, 1.2, 2.8, 1.5, 0.8, 0.4],
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        fill: true
                    }, {
                        label: 'Тепло',
                        data: [85, 82, 95, 105, 98, 90, 85],
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        }
    </script>
</body>
</html>