<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../modules/reports/ReportGenerator.php';
require_once __DIR__ . '/../../modules/reports/ExportService.php';

$auth = new Auth();
$auth->requireLogin();

$activePage = 'reports';
$pageTitle = 'Отчёты и аналитика';

$reportGenerator = new ReportGenerator();
$exportService = new ExportService();
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
        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .report-templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .report-template-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow-md);
            transition: transform 0.2s, box-shadow 0.2s;
            border-left: 4px solid #667eea;
        }
        
        .report-template-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        
        .report-template-card.consumption { border-left-color: #3498db; }
        .report-template-card.alerts { border-left-color: #e74c3c; }
        .report-template-card.efficiency { border-left-color: #2ecc71; }
        .report-template-card.custom { border-left-color: #9b59b6; }
        
        .template-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }
        
        .template-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .template-icon {
            font-size: 28px;
        }
        
        .template-description {
            color: var(--text-secondary);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .template-form {
            background-color: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .export-formats {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        
        .format-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-generate {
            width: 100%;
            margin-top: 16px;
            padding: 12px;
            font-weight: 600;
        }
        
        .saved-reports {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow-md);
        }
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .reports-table th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
        }
        
        .reports-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .reports-table tbody tr:hover {
            background-color: #f7fafc;
        }
        
        .file-size {
            color: var(--text-secondary);
            font-size: 13px;
        }
        
        .report-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-download { background-color: #3498db; color: white; }
        .btn-delete { background-color: #e74c3c; color: white; }
        .btn-view { background-color: #2ecc71; color: white; }
        
        .date-range-picker {
            display: flex;
            gap: 16px;
            align-items: end;
        }
        
        .date-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .date-group label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 16px;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            display: none;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include __DIR__ . '/../partials/sidebar.php'; ?>
        
        <div class="main-content">
            <?php include __DIR__ . '/../partials/header.php'; ?>
            
            <main class="container">
                <div class="reports-header">
                    <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary);">
                        📊 Отчёты и аналитика
                    </h1>
                    <div>
                        <span style="color: var(--text-secondary); font-size: 14px;">
                            Сгенерировано: <strong id="reportsCount">0</strong> отчётов
                        </span>
                    </div>
                </div>
                
                <!-- Статистика -->
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value" id="totalReports">0</div>
                        <div class="stat-label">Всего</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="consumptionReports">0</div>
                        <div class="stat-label">Потребление</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="alertsReports">0</div>
                        <div class="stat-label">Аварии</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="efficiencyReports">0</div>
                        <div class="stat-label">Эффективность</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="customReports">0</div>
                        <div class="stat-label">Пользовательские</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="totalSize">0</div>
                        <div class="stat-label">Общий размер</div>
                    </div>
                </div>
                
                <!-- Шаблоны отчётов -->
                <div class="report-templates-grid">
                    <!-- Отчёт по потреблению -->
                    <div class="report-template-card consumption">
                        <div class="template-header">
                            <div class="template-title">📈 Потребление ресурсов</div>
                            <div class="template-icon">📊</div>
                        </div>
                        
                        <div class="template-description">
                            Отчёт по потреблению воды, тепла и электричества по зонам и типам ресурсов
                        </div>
                        
                        <div class="template-form">
                            <form id="consumptionReportForm">
                                <input type="hidden" name="report_type" value="consumption">
                                
                                <div class="date-range-picker">
                                    <div class="date-group">
                                        <label>Начало</label>
                                        <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" required>
                                    </div>
                                    <div class="date-group">
                                        <label>Конец</label>
                                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Тип ресурса</label>
                                    <select name="resource_type">
                                        <option value="all">Все ресурсы</option>
                                        <option value="water">💧 Вода</option>
                                        <option value="heat">🔥 Тепло</option>
                                        <option value="electricity">⚡ Электричество</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Зона</label>
                                    <select name="zone_id">
                                        <option value="">Все зоны</option>
                                        <option value="1">Цех №1</option>
                                        <option value="2">Цех №2</option>
                                        <option value="3">Цех №3</option>
                                        <option value="4">Административное здание</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Группировка</label>
                                    <select name="group_by">
                                        <option value="day">По дням</option>
                                        <option value="week">По неделям</option>
                                        <option value="month">По месяцам</option>
                                        <option value="hour">По часам</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Агрегация</label>
                                    <select name="aggregation">
                                        <option value="sum">Сумма</option>
                                        <option value="avg">Среднее</option>
                                        <option value="max">Максимум</option>
                                        <option value="min">Минимум</option>
                                    </select>
                                </div>
                                
                                <div class="export-formats">
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="pdf" checked> PDF
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="excel"> Excel
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="csv"> CSV
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="json"> JSON
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-generate">
                                    📤 Сформировать отчёт
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Отчёт по авариям -->
                    <div class="report-template-card alerts">
                        <div class="template-header">
                            <div class="template-title">🚨 Аварийные события</div>
                            <div class="template-icon">⚠️</div>
                        </div>
                        
                        <div class="template-description">
                            Журнал аварийных и предупредительных событий с классификацией по уровням
                        </div>
                        
                        <div class="template-form">
                            <form id="alertsReportForm">
                                <input type="hidden" name="report_type" value="alerts">
                                
                                <div class="date-range-picker">
                                    <div class="date-group">
                                        <label>Начало</label>
                                        <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" required>
                                    </div>
                                    <div class="date-group">
                                        <label>Конец</label>
                                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Уровень критичности</label>
                                    <select name="alert_level">
                                        <option value="all">Все уровни</option>
                                        <option value="critical">🔴 Критическая угроза</option>
                                        <option value="emergency">🟠 Авария</option>
                                        <option value="warning">🟡 Предупреждение</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Статус</label>
                                    <select name="status">
                                        <option value="all">Все статусы</option>
                                        <option value="active">Активные</option>
                                        <option value="resolved">Устранённые</option>
                                        <option value="false_positive">Ложные</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Зона</label>
                                    <select name="zone_id">
                                        <option value="">Все зоны</option>
                                        <option value="1">Цех №1</option>
                                        <option value="2">Цех №2</option>
                                        <option value="3">Цех №3</option>
                                        <option value="4">Административное здание</option>
                                    </select>
                                </div>
                                
                                <div class="export-formats">
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="pdf" checked> PDF
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="excel"> Excel
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="csv"> CSV
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="json"> JSON
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-generate">
                                    📤 Сформировать отчёт
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Отчёт по эффективности -->
                    <div class="report-template-card efficiency">
                        <div class="template-header">
                            <div class="template-title">🎯 Эффективность работы</div>
                            <div class="template-icon">✅</div>
                        </div>
                        
                        <div class="template-description">
                            Отчёт по эффективности зон и систем: доступность, плотность аварий, процент устранения
                        </div>
                        
                        <div class="template-form">
                            <form id="efficiencyReportForm">
                                <input type="hidden" name="report_type" value="efficiency">
                                
                                <div class="date-range-picker">
                                    <div class="date-group">
                                        <label>Начало</label>
                                        <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" required>
                                    </div>
                                    <div class="date-group">
                                        <label>Конец</label>
                                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Зона</label>
                                    <select name="zone_id">
                                        <option value="">Все зоны</option>
                                        <option value="1">Цех №1</option>
                                        <option value="2">Цех №2</option>
                                        <option value="3">Цех №3</option>
                                        <option value="4">Административное здание</option>
                                    </select>
                                </div>
                                
                                <div class="export-formats">
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="pdf" checked> PDF
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="excel"> Excel
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="csv"> CSV
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="json"> JSON
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-generate">
                                    📤 Сформировать отчёт
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Пользовательский отчёт -->
                    <div class="report-template-card custom">
                        <div class="template-header">
                            <div class="template-title">🔧 Пользовательский отчёт</div>
                            <div class="template-icon">⚙️</div>
                        </div>
                        
                        <div class="template-description">
                            Произвольный отчёт по SQL-запросу (только для администраторов)
                        </div>
                        
                        <div class="template-form">
                            <form id="customReportForm">
                                <input type="hidden" name="report_type" value="custom">
                                
                                <div class="date-range-picker">
                                    <div class="date-group">
                                        <label>Начало</label>
                                        <input type="date" name="start_date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" required>
                                    </div>
                                    <div class="date-group">
                                        <label>Конец</label>
                                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>SQL-запрос</label>
                                    <textarea name="query" placeholder="SELECT * FROM sensors WHERE status = 'online'" required></textarea>
                                </div>
                                
                                <div class="export-formats">
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="pdf"> PDF
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="excel" checked> Excel
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="csv"> CSV
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="json"> JSON
                                    </div>
                                    <div class="format-checkbox">
                                        <input type="radio" name="format" value="xml"> XML
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-generate">
                                    📤 Сформировать отчёт
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Сохранённые отчёты -->
                <div class="saved-reports">
                    <div class="card-header">
                        <h2 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-primary);">
                            📁 Сохранённые отчёты
                        </h2>
                        <button class="btn btn-outline" onclick="refreshReportsList()">
                            <span>🔄</span> Обновить
                        </button>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Имя файла</th>
                                    <th>Размер</th>
                                    <th>Дата создания</th>
                                    <th>Тип</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody id="reportsTableBody">
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                        <div style="font-size: 48px; margin-bottom: 16px;">⏳</div>
                                        <p style="font-size: 16px; font-weight: 600;">Загрузка списка отчётов...</p>
                                        <p>Получение информации о сгенерированных отчётах</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
            
            <?php include __DIR__ . '/../partials/footer.php'; ?>
        </div>
    </div>
    
    <!-- Спиннер загрузки -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <span>Генерация отчёта...</span>
        </div>
    </div>
    
    <script>
        let currentReports = [];
        
        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            loadReportsList();
            updateStatistics();
            
            // Добавление обработчиков форм
            document.getElementById('consumptionReportForm').addEventListener('submit', handleSubmitReport);
            document.getElementById('alertsReportForm').addEventListener('submit', handleSubmitReport);
            document.getElementById('efficiencyReportForm').addEventListener('submit', handleSubmitReport);
            document.getElementById('customReportForm').addEventListener('submit', handleSubmitReport);
        });
        
        // Обработка отправки формы отчёта
        function handleSubmitReport(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const params = Object.fromEntries(formData.entries());
            
            generateReport(params);
        }
        
        // Генерация отчёта
        async function generateReport(params) {
            showLoading(true);
            
            try {
                const response = await fetch('/resource_monitoring/api.php/reports/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify(params)
                });
                
                const result = await response.json();
                
                hideLoading();
                
                if (result.success) {
                    showAlert('Отчёт успешно сгенерирован', 'success');
                    loadReportsList();
                    updateStatistics();
                    
                    // Предложение скачать файл
                    if (result.download_url) {
                        setTimeout(() => {
                            if (confirm('Отчёт готов. Хотите скачать его?')) {
                                window.open(result.download_url, '_blank');
                            }
                        }, 1000);
                    }
                } else {
                    showAlert('Ошибка генерации: ' + result.message, 'danger');
                }
            } catch (error) {
                hideLoading();
                showAlert('Ошибка подключения: ' + error.message, 'danger');
            }
        }
        
        // Загрузка списка отчётов
        async function loadReportsList() {
            try {
                const response = await fetch('/resource_monitoring/api.php/reports/list', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    currentReports = result.data.reports;
                    renderReportsList(currentReports);
                    document.getElementById('reportsCount').textContent = result.data.total;
                } else {
                    console.error('Ошибка загрузки отчётов:', result.message);
                }
            } catch (error) {
                console.error('Ошибка подключения:', error);
            }
        }
        
        // Отображение списка отчётов
        function renderReportsList(reports) {
            const tbody = document.getElementById('reportsTableBody');
            
            if (reports.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                            <div style="font-size: 48px; margin-bottom: 16px;">📁</div>
                            <p style="font-size: 16px; font-weight: 600;">Нет сохранённых отчётов</p>
                            <p>Сформируйте первый отчёт с помощью шаблонов выше</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            const html = reports.map(report => {
                const sizeMB = (report.size / (1024 * 1024)).toFixed(2);
                const formattedDate = new Date(report.modified_at).toLocaleString('ru-RU');
                
                return `
                    <tr>
                        <td>
                            <strong>${escapeHtml(report.file_name)}</strong>
                            <br>
                            <small style="color: var(--text-light);">${report.file_name.substring(0, 50)}${report.file_name.length > 50 ? '...' : ''}</small>
                        </td>
                        <td>
                            <span class="file-size">${sizeMB} MB</span>
                        </td>
                        <td>
                            ${formattedDate}
                        </td>
                        <td>
                            <span class="badge badge-${report.type}">${report.type.toUpperCase()}</span>
                        </td>
                        <td class="report-actions">
                            <button class="btn-action btn-download" onclick="downloadReport('${report.file_name}')" title="Скачать">
                                📥
                            </button>
                            <button class="btn-action btn-view" onclick="previewReport('${report.file_name}')" title="Просмотр">
                                👁️
                            </button>
                            <button class="btn-action btn-delete" onclick="deleteReport('${report.file_name}')" title="Удалить">
                                🗑️
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
            
            tbody.innerHTML = html;
        }
        
        // Обновление статистики
        async function updateStatistics() {
            try {
                const response = await fetch('/resource_monitoring/api.php/reports/statistics', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const stats = result.data;
                    document.getElementById('totalReports').textContent = stats.total_reports;
                    document.getElementById('totalSize').textContent = stats.total_size_mb + ' MB';
                    // Обновление других статистик...
                }
            } catch (error) {
                console.error('Ошибка загрузки статистики:', error);
            }
        }
        
        // Скачивание отчёта
        function downloadReport(fileName) {
            window.open(`/resource_monitoring/api.php/reports/download/${fileName}`, '_blank');
        }
        
        // Предварительный просмотр отчёта
        function previewReport(fileName) {
            // TODO: Реализовать предварительный просмотр
            alert('Предварительный просмотр отчёта: ' + fileName + '\n\n(Функция будет реализована в следующей версии)');
        }
        
        // Удаление отчёта
        async function deleteReport(fileName) {
            if (!confirm(`Вы уверены, что хотите удалить отчёт "${fileName}"?`)) {
                return;
            }
            
            try {
                const response = await fetch(`/resource_monitoring/api.php/reports/delete/${fileName}`, {
                    method: 'DELETE',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Отчёт удалён', 'success');
                    loadReportsList();
                    updateStatistics();
                } else {
                    showAlert('Ошибка удаления: ' + result.message, 'danger');
                }
            } catch (error) {
                showAlert('Ошибка подключения: ' + error.message, 'danger');
            }
        }
        
        // Обновление списка
        function refreshReportsList() {
            loadReportsList();
        }
        
        // Показ спиннера
        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }
        
        // Скрытие спиннера
        function hideLoading() {
            setTimeout(() => {
                document.getElementById('loadingOverlay').style.display = 'none';
            }, 500);
        }
        
        // Уведомление
        function showAlert(message, type) {
            // Простая реализация уведомления
            const alertDiv = document.createElement('div');
            alertDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 24px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                z-index: 10000;
                animation: slideIn 0.3s;
                background-color: ${type === 'success' ? '#48bb78' : '#e53e3e'};
            `;
            alertDiv.textContent = message;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
        
        // Экранирование HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
        // Добавление CSS для анимации
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>