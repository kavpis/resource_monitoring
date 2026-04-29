<?php
$pageTitle = 'Счётчики';
$activePage = 'sensors';
require_once __DIR__ . '/layout.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📡 Счётчики ресурсов</h1>
        <p>Управление и мониторинг счётчиков воды, тепла и электричества</p>
    </div>

    <!-- Фильтры -->
    <div class="filters-panel">
        <div class="filter-group">
            <label>Зона:</label>
            <select id="zoneFilter" onchange="loadSensors()">
                <option value="all">Все зоны</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Ресурс:</label>
            <select id="resourceFilter" onchange="loadSensors()">
                <option value="all">Все ресурсы</option>
                <option value="water">Вода</option>
                <option value="heat">Тепло</option>
                <option value="electricity">Электричество</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Статус:</label>
            <select id="statusFilter" onchange="loadSensors()">
                <option value="all">Все</option>
                <option value="active">Активные</option>
                <option value="inactive">Неактивные</option>
            </select>
        </div>
    </div>

    <!-- Таблица счётчиков -->
    <div class="table-container">
        <table class="data-table" id="sensorsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Зона</th>
                    <th>Ресурс</th>
                    <th>IP адрес</th>
                    <th>Порт</th>
                    <th>Регистр</th>
                    <th>Последнее значение</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody id="sensorsTableBody">
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px;">
                        Загрузка данных...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// Загрузка при старте
document.addEventListener('DOMContentLoaded', function() {
    loadZones();
    loadSensors();
});

async function loadZones() {
    try {
        const response = await fetch('/resource_monitoring/api.php/zones');
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('zoneFilter');
            data.data.forEach(zone => {
                const option = document.createElement('option');
                option.value = zone.zone_id;
                option.textContent = zone.zone_name;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Ошибка загрузки зон:', error);
    }
}

async function loadSensors() {
    const zoneId = document.getElementById('zoneFilter').value;
    const resource = document.getElementById('resourceFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    let url = '/resource_monitoring/api.php/sensors?';
    if (zoneId !== 'all') url += `zone_id=${zoneId}&`;
    if (resource !== 'all') url += `resource_type=${resource}&`;
    if (status !== 'all') url += `status=${status}&`;
    
    try {
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            renderSensorsTable(data.data);
        } else {
            document.getElementById('sensorsTableBody').innerHTML = 
                '<tr><td colspan="10" style="text-align: center; color: #e53e3e;">Ошибка загрузки данных</td></tr>';
        }
    } catch (error) {
        console.error('Ошибка загрузки счётчиков:', error);
        document.getElementById('sensorsTableBody').innerHTML = 
            '<tr><td colspan="10" style="text-align: center; color: #e53e3e;">Ошибка подключения к серверу</td></tr>';
    }
}

function renderSensorsTable(sensors) {
    const tbody = document.getElementById('sensorsTableBody');
    
    if (!sensors || sensors.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 40px; color: #718096;">Счётчики не найдены</td></tr>';
        return;
    }
    
    let html = '';
    sensors.forEach(sensor => {
        const statusClass = sensor.status === 'active' ? 'status-active' : 'status-inactive';
        const statusText = sensor.status === 'active' ? '✓ Активен' : '✗ Неактивен';
        const lastValue = sensor.last_reading ? `${sensor.last_value} ${sensor.unit}` : 'Нет данных';
        
        html += `
            <tr>
                <td>${sensor.sensor_id}</td>
                <td><strong>${sensor.sensor_name}</strong></td>
                <td>${sensor.zone_name || 'Не назначена'}</td>
                <td>${getResourceLabel(sensor.resource_type)}</td>
                <td>${sensor.ip_address || '-'}</td>
                <td>${sensor.port || '-'}</td>
                <td>${sensor.register || '-'}</td>
                <td>${lastValue}</td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td>
                    <button class="btn-small" onclick="editSensor(${sensor.sensor_id})">✏️</button>
                    <button class="btn-small btn-danger" onclick="deleteSensor(${sensor.sensor_id})">🗑️</button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function getResourceLabel(type) {
    const labels = {
        'water': '💧 Вода',
        'heat': '🔥 Тепло',
        'electricity': '⚡ Электричество'
    };
    return labels[type] || type;
}

function editSensor(id) {
    alert('Редактирование счётчика ID: ' + id);
    // Здесь будет модальное окно редактирования
}

function deleteSensor(id) {
    if (confirm('Вы уверены, что хотите удалить счётчик ID: ' + id + '?')) {
        // Здесь будет запрос на удаление
        alert('Функция удаления будет реализована');
    }
}
</script>

<style>
.filters-panel {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f7fafc;
    border-radius: 8px;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-group select {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 14px;
    min-width: 150px;
}

.table-container {
    overflow-x: auto;
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.data-table th {
    background: #f7fafc;
    font-weight: 600;
    color: #4a5568;
}

.data-table tr:hover {
    background: #f7fafc;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.status-active {
    background: #c6f6d5;
    color: #276749;
}

.status-inactive {
    background: #fed7d7;
    color: #c53030;
}

.btn-small {
    padding: 4px 8px;
    border: none;
    background: #4299e1;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    margin-right: 5px;
}

.btn-small:hover {
    background: #3182ce;
}

.btn-danger {
    background: #f56565;
}

.btn-danger:hover {
    background: #e53e3e;
}
</style>
