<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../modules/data_collection/DataCollector.php';

$auth = new Auth();
$auth->requireRole('admin');

$activePage = 'admin_data_collection';
$pageTitle = 'Модуль сбора данных';

$collector = new DataCollector();
$settings = $collector->getPollingInterval();
$stats = $collector->getStatistics();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> | Система мониторинга</title>
    <link rel="stylesheet" href="/resource_monitoring/public/css/style.css">
    <link rel="stylesheet" href="/resource_monitoring/public/css/dashboard.css">
    <style>
        .data-collection-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .settings-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        
        .setting-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .setting-group:last-child {
            border-bottom: none;
        }
        
        .setting-info {
            flex: 1;
        }
        
        .setting-info h4 {
            margin: 0 0 4px 0;
            font-size: 16px;
            color: var(--text-primary);
        }
        
        .setting-info p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 13px;
        }
        
        .setting-control {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .actions-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .btn-action {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-collect { background-color: #3b82f6; color: white; }
        .btn-test { background-color: #10b981; color: white; }
        .btn-reset { background-color: #f59e0b; color: white; }
        
        .connections-table {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }
        
        .connections-table th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 14px 16px;
            text-align: left;
        }
        
        .connections-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-available { background-color: #c6f6d5; color: #276749; }
        .status-unavailable { background-color: #fed7d7; color: #742a2a; }
        .status-unknown { background-color: #e2e8f0; color: #4a5568; }
        
        .logs-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            margin-top: 20px;
        }
        
        .logs-container {
            background-color: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include __DIR__ . '/../partials/sidebar.php'; ?>
        
        <div class="main-content">
            <?php include __DIR__ . '/../partials/header.php'; ?>
            
            <main class="container">
                <div class="data-collection-header">
                    <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary);">
                        📡 Модуль сбора данных
                    </h1>
                    <div>
                        <span style="color: var(--text-secondary); font-size: 14px;">
                            Статус: <strong style="color: #38a169;">Активен</strong>
                        </span>
                    </div>
                </div>
                
                <!-- Статистика -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['total_readings'] ?? 0 ?></div>
                        <div class="stat-label">Всего показаний</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: #48bb78;"><?= $stats['valid_readings'] ?? 0 ?></div>
                        <div class="stat-label">Корректные</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: #e53e3e;"><?= $stats['invalid_readings'] ?? 0 ?></div>
                        <div class="stat-label">Ошибочные</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['unique_sensors'] ?? 0 ?></div>
                        <div class="stat-label">Активные счётчики</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: #dd6b20;"><?= $stats['generated_alerts'] ?? 0 ?></div>
                        <div class="stat-label">Сгенерировано аварий</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: #805ad5;"><?= $stats['online_sensors'] ?? 0 ?></div>
                        <div class="stat-label">Онлайн</div>
                    </div>
                </div>
                
                <!-- Настройки -->
                <div class="settings-card">
                    <h2 style="margin: 0 0 20px 0; font-size: 20px; color: var(--text-primary);">⚙️ Настройки опроса</h2>
                    
                    <div class="setting-group">
                        <div class="setting-info">
                            <h4>Интервал опроса</h4>
                            <p>Частота опроса счётчиков в секундах (5-3600)</p>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="pollingInterval" value="<?= $settings['interval'] ?>" min="5" max="3600" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; width: 100px;">
                            <button class="btn btn-primary" onclick="updatePollingInterval()">
                                Сохранить
                            </button>
                        </div>
                    </div>
                    
                    <div class="setting-group">
                        <div class="setting-info">
                            <h4>Таймаут подключения</h4>
                            <p>Время ожидания ответа от счётчика в секундах</p>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="connectionTimeout" value="<?= $settings['timeout'] ?>" min="1" max="30" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; width: 100px;">
                            <button class="btn btn-primary" onclick="updateTimeout()">
                                Сохранить
                            </button>
                        </div>
                    </div>
                    
                    <div class="setting-group">
                        <div class="setting-info">
                            <h4>Количество попыток</h4>
                            <p>Количество попыток подключения при ошибке</p>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="retryCount" value="<?= $settings['retries'] ?>" min="1" max="10" style="padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; width: 100px;">
                            <button class="btn btn-primary" onclick="updateRetryCount()">
                                Сохранить
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Действия -->
                <div class="actions-section">
                    <h2 style="margin: 0 0 16px 0; font-size: 20px; color: var(--text-primary);">⚡ Действия</h2>
                    
                    <div class="action-buttons">
                        <button class="btn-action btn-collect" onclick="manualCollect()">
                            <span>🔄</span> Ручной запуск опроса
                        </button>
                        <button class="btn-action btn-test" onclick="checkAllConnections()">
                            <span>🔍</span> Проверить все соединения
                        </button>
                        <button class="btn-action btn-reset" onclick="resetCollectionHistory()">
                            <span>🗑️</span> Очистить историю
                        </button>
                        <button class="btn btn-outline" onclick="viewLogs()">
                            <span>📋</span> Просмотр логов
                        </button>
                    </div>
                </div>
                
                <!-- Статус соединений -->
                <div class="card">
                    <div class="card-header">
                        <h3>🔗 Статус подключения счётчиков</h3>
                        <button class="btn btn-sm btn-outline" onclick="refreshConnections()">
                            <span>🔄</span> Обновить
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="connectionsList">
                            <p style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                Загрузка статуса подключений...
                            </p>
                        </div>
                    </div>
                </div>
            </main>
            
            <?php include __DIR__ . '/../partials/footer.php'; ?>
        </div>
    </div>
    
    <script>
        // Обновление интервала опроса
        async function updatePollingInterval() {
            const interval = document.getElementById('pollingInterval').value;
            
            if (interval < 5 || interval > 3600) {
                alert('Интервал должен быть от 5 до 3600 секунд');
                return;
            }
            
            try {
                const response = await fetch('/resource_monitoring/api.php/collect/set_interval', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify({ interval: parseInt(interval) })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Интервал опроса обновлён', 'success');
                } else {
                    showAlert('Ошибка: ' + result.message, 'danger');
                }
            } catch (error) {
                showAlert('Ошибка подключения: ' + error.message, 'danger');
            }
        }
        
        // Ручной запуск сбора
        async function manualCollect() {
            if (!confirm('Запустить ручной опрос всех счётчиков?')) {
                return;
            }
            
            try {
                const response = await fetch('/resource_monitoring/api.php/collect/manual_collect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify({})
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert(`Сбор данных завершён: ${result.data.collected} успешных, ${result.data.errors} ошибок`, 'success');
                    refreshConnections(); // Обновить статусы
                } else {
                    showAlert('Ошибка сбора: ' + result.message, 'danger');
                }
            } catch (error) {
                showAlert('Ошибка подключения: ' + error.message, 'danger');
            }
        }
        
        // Проверка всех соединений
        async function checkAllConnections() {
            try {
                const response = await fetch('/resource_monitoring/api.php/collect/check_connections', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert(`Проверка завершена: ${result.data.available_count}/${result.data.total_sensors} доступны`, 'success');
                    renderConnections(result.data);
                } else {
                    showAlert('Ошибка проверки: ' + result.message, 'danger');
                }
            } catch (error) {
                showAlert('Ошибка подключения: ' + error.message, 'danger');
            }
        }
        
        // Обновление списка соединений
        async function refreshConnections() {
            try {
                const response = await fetch('/resource_monitoring/api.php/sensors', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    renderConnections(result.data);
                }
            } catch (error) {
                console.error('Error refreshing connections:', error);
            }
        }
        
        // Отображение списка соединений
        function renderConnections(connections) {
            const container = document.getElementById('connectionsList');
            
            if (connections.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                        <p style="font-size: 16px; font-weight: 600;">Нет счётчиков</p>
                        <p>Добавьте счётчики для мониторинга подключений</p>
                    </div>
                `;
                return;
            }
            
            const html = `
                <table class="table">
                    <thead>
                        <tr>
                            <th>Счётчик</th>
                            <th>Зона</th>
                            <th>IP-адрес</th>
                            <th>Статус</th>
                            <th>Последняя связь</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${connections.map(conn => {
                            const statusClass = conn.status === 'online' ? 'status-available' : 
                                              conn.status === 'offline' ? 'status-unavailable' : 'status-unknown';
                            
                            const statusText = conn.status === 'online' ? '🟢 Онлайн' : 
                                             conn.status === 'offline' ? '🔴 Оффлайн' : '🟡 Неизвестно';
                            
                            return `
                                <tr>
                                    <td><strong>${conn.sensor_name}</strong></td>
                                    <td>${conn.zone_name}</td>
                                    <td><code>${conn.ip_address}</code></td>
                                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                                    <td>${conn.last_communication || '—'}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline" onclick="testConnection(${conn.sensor_id})">
                                            🔄 Проверить
                                        </button>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
            
            container.innerHTML = html;
        }
        
        // Тестирование соединения с конкретным счётчиком
        async function testConnection(sensorId) {
            try {
                const response = await fetch(`/resource_monitoring/api.php/collect/test_connection/${sensorId}`, {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const statusText = result.available ? '✅ Доступен' : '❌ Недоступен';
                    showAlert(`Счётчик ${statusText}`, result.available ? 'success' : 'warning');
                    refreshConnections(); // Обновить список
                } else {
                    showAlert('Ошибка проверки: ' + result.message, 'danger');
                }
            } catch (error) {
                showAlert('Ошибка подключения: ' + error.message, 'danger');
            }
        }
        
        // Просмотр логов
        function viewLogs() {
            window.open('/resource_monitoring/logs/', '_blank');
        }
        
        // Очистка истории (заглушка)
        function resetCollectionHistory() {
            if (confirm('Вы уверены, что хотите очистить историю сбора данных? Это действие нельзя отменить.')) {
                // TODO: Реализовать очистку истории через API
                alert('Функция очистки истории будет реализована в следующей версии.');
            }
        }
        
        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            refreshConnections();
        });
    </script>
</body>
</html>