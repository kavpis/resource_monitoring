<?php
$pageTitle = 'Аналитика';
$activePage = 'analytics';
require_once __DIR__ . '/layout.php';
?>

<div class="container">
    <div class="page-header">
        <h1>📈 Аналитика потребления</h1>
        <p>Детальный анализ и тренды потребления ресурсов</p>
    </div>

    <!-- Фильтры -->
    <div class="filters-panel">
        <div class="filter-group">
            <label>Период:</label>
            <select id="periodFilter" onchange="loadAnalytics()">
                <option value="7">Последние 7 дней</option>
                <option value="30" selected>Последние 30 дней</option>
                <option value="90">Последние 90 дней</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Ресурс:</label>
            <select id="resourceFilter" onchange="loadAnalytics()">
                <option value="all">Все ресурсы</option>
                <option value="water">Вода</option>
                <option value="heat">Тепло</option>
                <option value="electricity">Электричество</option>
            </select>
        </div>
    </div>

    <!-- Карточки статистики -->
    <div class="stats-grid" id="analyticsStats">
        <div class="stat-card">
            <div class="stat-label">Общее потребление</div>
            <div class="stat-value" id="totalConsumption">0</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Среднее в день</div>
            <div class="stat-value" id="avgDaily">0</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Тренд</div>
            <div class="stat-value" id="trendIndicator">-</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Аномалий за период</div>
            <div class="stat-value" id="anomalyCount">0</div>
        </div>
    </div>

    <!-- Графики -->
    <div class="charts-section">
        <div class="chart-card full-width">
            <h3>📊 Динамика потребления по дням</h3>
            <canvas id="dailyTrendChart"></canvas>
        </div>
        
        <div class="chart-card">
            <h3>📈 Структура потребления</h3>
            <canvas id="resourceStructureChart"></canvas>
        </div>
        
        <div class="chart-card">
            <h3>⚡ Эффективность по зонам</h3>
            <canvas id="zoneEfficiencyChart"></canvas>
        </div>
    </div>

    <!-- Рекомендации -->
    <div class="recommendations-panel" id="recommendationsPanel">
        <h3>💡 Рекомендации системы</h3>
        <div id="recommendationsList">
            <div class="loading">Загрузка рекомендаций...</div>
        </div>
    </div>
</div>

<script>
let dailyTrendChartInstance = null;
let resourceStructureChartInstance = null;
let zoneEfficiencyChartInstance = null;

// Загрузка аналитики при старте
document.addEventListener('DOMContentLoaded', function() {
    loadAnalytics();
});

async function loadAnalytics() {
    const days = document.getElementById('periodFilter').value;
    const resource = document.getElementById('resourceFilter').value;
    
    const endDate = new Date().toISOString().split('T')[0];
    const startDate = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
    
    try {
        // Загрузка трендов
        const trendsResponse = await fetch(`/resource_monitoring/api.php/analytics/trends?start_date=${startDate}&end_date=${endDate}&resource_type=${resource}`);
        const trendsData = await trendsResponse.json();
        
        if (trendsData.success) {
            updateStats(trendsData.data);
            renderDailyTrendChart(trendsData.data.daily);
            updateTrendIndicator(trendsData.data.forecast);
        }
        
        // Загрузка данных по эффективности
        const efficiencyResponse = await fetch(`/resource_monitoring/api.php/analytics/efficiency?start_date=${startDate}&end_date=${endDate}`);
        const efficiencyData = await efficiencyResponse.json();
        
        if (efficiencyData.success) {
            renderResourceStructureChart(efficiencyData.data.zone_efficiency);
            renderZoneEfficiencyChart(efficiencyData.data.zone_efficiency);
            renderRecommendations(efficiencyData.data.recommendations);
            updateAnomalyCount(efficiencyData.data.anomalies);
        }
        
    } catch (error) {
        console.error('Ошибка загрузки аналитики:', error);
        document.getElementById('analyticsStats').innerHTML = 
            '<div class="error-message">Ошибка загрузки данных. Проверьте подключение к серверу.</div>';
    }
}

function updateStats(data) {
    if (!data || !data.overall) return;
    
    let total = 0;
    data.overall.forEach(item => {
        total += parseFloat(item.total || 0);
    });
    
    const days = document.getElementById('periodFilter').value;
    const avgDaily = total / days;
    
    document.getElementById('totalConsumption').textContent = total.toFixed(2);
    document.getElementById('avgDaily').textContent = avgDaily.toFixed(2);
}

function updateTrendIndicator(forecast) {
    if (!forecast) return;
    
    const trendEl = document.getElementById('trendIndicator');
    const trend = forecast.trend;
    const change = forecast.change_percent;
    
    if (trend === 'increasing') {
        trendEl.textContent = `↗ +${change}%`;
        trendEl.style.color = '#e53e3e';
    } else if (trend === 'decreasing') {
        trendEl.textContent = `↘ ${change}%`;
        trendEl.style.color = '#38a169';
    } else {
        trendEl.textContent = `→ ${change}%`;
        trendEl.style.color = '#718096';
    }
}

function updateAnomalyCount(anomalies) {
    let count = 0;
    if (Array.isArray(anomalies)) {
        anomalies.forEach(a => {
            count += parseInt(a.count || 0);
        });
    }
    document.getElementById('anomalyCount').textContent = count;
}

function renderDailyTrendChart(dailyData) {
    const ctx = document.getElementById('dailyTrendChart').getContext('2d');
    
    // Группировка данных по датам
    const grouped = {};
    dailyData.forEach(item => {
        const date = item.date;
        if (!grouped[date]) {
            grouped[date] = { water: 0, heat: 0, electricity: 0 };
        }
        grouped[date][item.resource_type] = parseFloat(item.total || 0);
    });
    
    const labels = Object.keys(grouped).sort();
    const waterData = labels.map(d => grouped[d].water);
    const heatData = labels.map(d => grouped[d].heat);
    const electricityData = labels.map(d => grouped[d].electricity);
    
    if (dailyTrendChartInstance) {
        dailyTrendChartInstance.destroy();
    }
    
    dailyTrendChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Вода',
                    data: waterData,
                    borderColor: '#4299e1',
                    backgroundColor: 'rgba(66, 153, 225, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Тепло',
                    data: heatData,
                    borderColor: '#f56565',
                    backgroundColor: 'rgba(245, 101, 101, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Электричество',
                    data: electricityData,
                    borderColor: '#ecc94b',
                    backgroundColor: 'rgba(236, 201, 75, 0.1)',
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

function renderResourceStructureChart(zoneEfficiency) {
    const ctx = document.getElementById('resourceStructureChart').getContext('2d');
    
    // Агрегация по типам ресурсов
    const byResource = { water: 0, heat: 0, electricity: 0 };
    zoneEfficiency.forEach(item => {
        byResource[item.resource_type] += parseFloat(item.total_consumption || 0);
    });
    
    if (resourceStructureChartInstance) {
        resourceStructureChartInstance.destroy();
    }
    
    resourceStructureChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Вода', 'Тепло', 'Электричество'],
            datasets: [{
                data: [byResource.water, byResource.heat, byResource.electricity],
                backgroundColor: ['#4299e1', '#f56565', '#ecc94b']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

function renderZoneEfficiencyChart(zoneEfficiency) {
    const ctx = document.getElementById('zoneEfficiencyChart').getContext('2d');
    
    // Агрегация по зонам
    const byZone = {};
    zoneEfficiency.forEach(item => {
        if (!byZone[item.zone_name]) {
            byZone[item.zone_name] = 0;
        }
        byZone[item.zone_name] += parseFloat(item.total_consumption || 0);
    });
    
    const labels = Object.keys(byZone);
    const data = labels.map(z => byZone[z]);
    
    if (zoneEfficiencyChartInstance) {
        zoneEfficiencyChartInstance.destroy();
    }
    
    zoneEfficiencyChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Потребление',
                data: data,
                backgroundColor: '#48bb78'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

function renderRecommendations(recommendations) {
    const container = document.getElementById('recommendationsList');
    
    if (!recommendations || recommendations.length === 0) {
        container.innerHTML = '<div class="info-message">Нет рекомендаций на данный период</div>';
        return;
    }
    
    let html = '';
    recommendations.forEach(rec => {
        const icon = rec.type === 'critical' ? '🚨' : rec.type === 'warning' ? '⚠️' : 'ℹ️';
        const colorClass = rec.type === 'critical' ? 'critical' : rec.type === 'warning' ? 'warning' : 'info';
        html += `<div class="recommendation-item ${colorClass}">${icon} ${rec.message}</div>`;
    });
    
    container.innerHTML = html;
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
}

.recommendations-panel {
    margin-top: 30px;
    padding: 20px;
    background: #f7fafc;
    border-radius: 8px;
}

.recommendation-item {
    padding: 12px;
    margin: 10px 0;
    border-radius: 4px;
    border-left: 4px solid;
}

.recommendation-item.critical {
    background: #fff5f5;
    border-color: #e53e3e;
    color: #c53030;
}

.recommendation-item.warning {
    background: #fffff0;
    border-color: #d69e2e;
    color: #975a16;
}

.recommendation-item.info {
    background: #f0fff4;
    border-color: #48bb78;
    color: #276749;
}

.loading {
    text-align: center;
    padding: 20px;
    color: #718096;
}

.error-message {
    text-align: center;
    padding: 20px;
    color: #e53e3e;
    background: #fff5f5;
    border-radius: 4px;
}

.info-message {
    text-align: center;
    padding: 20px;
    color: #276749;
    background: #f0fff4;
    border-radius: 4px;
}
</style>
