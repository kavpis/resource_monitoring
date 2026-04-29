/**
 * Модуль визуализации данных для системы мониторинга ресурсов
 * Использует библиотеку Chart.js для построения графиков
 * 
 * @module VisualizationModule
 */

const VisualizationModule = (function() {
    'use strict';
    
    // Конфигурация
    const config = {
        apiEndpoint: '/api.php',
        refreshInterval: 30000, // 30 секунд
        chartColors: {
            water: 'rgba(54, 162, 235, 0.8)',
            heat: 'rgba(255, 99, 132, 0.8)',
            electricity: 'rgba(255, 206, 86, 0.8)'
        },
        chartBorderColors: {
            water: 'rgba(54, 162, 235, 1)',
            heat: 'rgba(255, 99, 132, 1)',
            electricity: 'rgba(255, 206, 86, 1)'
        }
    };
    
    // Хранилище экземпляров графиков
    const charts = {};
    
    /**
     * Инициализация модуля визуализации
     */
    function init() {
        console.log('[Visualization] Модуль инициализирован');
        setupEventListeners();
    }
    
    /**
     * Настройка обработчиков событий
     */
    function setupEventListeners() {
        document.addEventListener('DOMContentLoaded', function() {
            // Автообновление графиков на дашборде
            const dashboardCharts = document.querySelectorAll('[data-auto-refresh]');
            if (dashboardCharts.length > 0) {
                setInterval(refreshAllCharts, config.refreshInterval);
            }
        });
    }
    
    /**
     * Загрузка данных для графика через API
     * @param {string} action - Действие API
     * @param {object} params - Параметры запроса
     * @returns {Promise} Промис с данными
     */
    async function loadChartData(action, params = {}) {
        try {
            const url = new URL(config.apiEndpoint);
            url.searchParams.append('action', 'visualization');
            url.searchParams.append('visualization_action', action);
            
            Object.keys(params).forEach(key => {
                url.searchParams.append(key, params[key]);
            });
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ошибка: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                console.log(`[Visualization] Данные получены: ${action}`, data.data);
                return data.data;
            } else {
                console.error(`[Visualization] Ошибка API: ${data.error}`);
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error(`[Visualization] Ошибка загрузки данных:`, error);
            throw error;
        }
    }
    
    /**
     * Создание графика потребления ресурсов
     * @param {string} canvasId - ID элемента canvas
     * @param {string} resourceType - Тип ресурса (water, heat, electricity)
     * @param {object} options - Опции графика
     */
    async function createConsumptionChart(canvasId, resourceType, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`[Visualization] Canvas элемент не найден: ${canvasId}`);
            return null;
        }
        
        const ctx = canvas.getContext('2d');
        
        try {
            const data = await loadChartData('consumption', {
                resource: resourceType,
                zone_id: options.zoneId || null,
                start_date: options.startDate || getDefaultStartDate(),
                end_date: options.endDate || getDefaultEndDate(),
                grouping: options.grouping || 'day'
            });
            
            // Уничтожить существующий график
            if (charts[canvasId]) {
                charts[canvasId].destroy();
            }
            
            // Создать новый график
            charts[canvasId] = new Chart(ctx, {
                type: options.chartType || 'line',
                data: {
                    labels: data.labels || [],
                    datasets: [{
                        label: get_resourceLabel(resourceType),
                        data: data.values || [],
                        backgroundColor: config.chartColors[resourceType],
                        borderColor: config.chartBorderColors[resourceType],
                        borderWidth: 2,
                        fill: options.fill !== false,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + 
                                           context.parsed.y.toFixed(2) + ' ' + 
                                           (options.unit || '');
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: options.unit || 'Значение'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Период'
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
            
            console.log(`[Visualization] График потребления создан: ${canvasId}`);
            return charts[canvasId];
            
        } catch (error) {
            console.error(`[Visualization] Ошибка создания графика:`, error);
            showErrorMessage(canvasId, 'Не удалось загрузить данные графика');
            return null;
        }
    }
    
    /**
     * Создание графика сравнения зон
     * @param {string} canvasId - ID элемента canvas
     * @param {string} resourceType - Тип ресурса
     * @param {object} options - Опции графика
     */
    async function createZoneComparisonChart(canvasId, resourceType, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`[Visualization] Canvas элемент не найден: ${canvasId}`);
            return null;
        }
        
        const ctx = canvas.getContext('2d');
        
        try {
            const data = await loadChartData('zone_comparison', {
                resource: resourceType,
                start_date: options.startDate || getDefaultStartDate(),
                end_date: options.endDate || getDefaultEndDate()
            });
            
            // Уничтожить существующий график
            if (charts[canvasId]) {
                charts[canvasId].destroy();
            }
            
            // Создать новый график
            charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.zones || [],
                    datasets: [{
                        label: 'Потребление (' + get_resourceLabel(resourceType) + ')',
                        data: data.consumption || [],
                        backgroundColor: config.chartColors[resourceType],
                        borderColor: config.chartBorderColors[resourceType],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + 
                                           context.parsed.y.toFixed(2) + ' ' + 
                                           (options.unit || '');
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: options.unit || 'Значение'
                            }
                        },
                        x: {
                            ticks: {
                                autoSkip: false,
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
            
            console.log(`[Visualization] График сравнения зон создан: ${canvasId}`);
            return charts[canvasId];
            
        } catch (error) {
            console.error(`[Visualization] Ошибка создания графика:`, error);
            showErrorMessage(canvasId, 'Не удалось загрузить данные сравнения');
            return null;
        }
    }
    
    /**
     * Создание графика аварий
     * @param {string} canvasId - ID элемента canvas
     * @param {object} options - Опции графика
     */
    async function createAlertsChart(canvasId, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.error(`[Visualization] Canvas элемент не найден: ${canvasId}`);
            return null;
        }
        
        const ctx = canvas.getContext('2d');
        
        try {
            const data = await loadChartData('alerts', {
                start_date: options.startDate || getDefaultStartDate(),
                end_date: options.endDate || getDefaultEndDate(),
                zone_id: options.zoneId || null
            });
            
            // Уничтожить существующий график
            if (charts[canvasId]) {
                charts[canvasId].destroy();
            }
            
            // Создать новый график
            charts[canvasId] = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: data.types || [],
                    datasets: [{
                        label: 'Количество аварий',
                        data: data.counts || [],
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 206, 86, 0.8)',
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(153, 102, 255, 0.8)',
                            'rgba(255, 159, 64, 0.8)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            console.log(`[Visualization] График аварий создан: ${canvasId}`);
            return charts[canvasId];
            
        } catch (error) {
            console.error(`[Visualization] Ошибка создания графика:`, error);
            showErrorMessage(canvasId, 'Не удалось загрузить данные аварий');
            return null;
        }
    }
    
    /**
     * Обновление всех графиков на странице
     */
    async function refreshAllCharts() {
        console.log('[Visualization] Обновление всех графиков');
        
        for (const canvasId in charts) {
            if (charts.hasOwnProperty(canvasId)) {
                const chart = charts[canvasId];
                const canvas = document.getElementById(canvasId);
                
                if (canvas && canvas.dataset.autoRefresh !== undefined) {
                    const resourceType = canvas.dataset.resource || 'electricity';
                    const chartType = canvas.dataset.chartType || 'consumption';
                    
                    try {
                        if (chartType === 'consumption') {
                            await createConsumptionChart(canvasId, resourceType);
                        } else if (chartType === 'zone_comparison') {
                            await createZoneComparisonChart(canvasId, resourceType);
                        } else if (chartType === 'alerts') {
                            await createAlertsChart(canvasId);
                        }
                    } catch (error) {
                        console.error(`[Visualization] Ошибка обновления графика ${canvasId}:`, error);
                    }
                }
            }
        }
    }
    
    /**
     * Получение даты начала по умолчанию (30 дней назад)
     */
    function getDefaultStartDate() {
        const date = new Date();
        date.setDate(date.getDate() - 30);
        return date.toISOString().split('T')[0];
    }
    
    /**
     * Получение даты окончания по умолчанию (сегодня)
     */
    function getDefaultEndDate() {
        return new Date().toISOString().split('T')[0];
    }
    
    /**
     * Получение метки ресурса на русском языке
     */
    function get_resourceLabel(resourceType) {
        const labels = {
            water: 'Вода',
            heat: 'Тепло',
            electricity: 'Электричество'
        };
        return labels[resourceType] || resourceType;
    }
    
    /**
     * Отображение сообщения об ошибке
     */
    function showErrorMessage(canvasId, message) {
        const canvas = document.getElementById(canvasId);
        if (canvas) {
            const container = canvas.parentElement;
            let errorDiv = container.querySelector('.chart-error-message');
            
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.className = 'chart-error-message';
                errorDiv.style.cssText = 'color: #dc3545; padding: 20px; text-align: center;';
                container.insertBefore(errorDiv, canvas.nextSibling);
            }
            
            errorDiv.textContent = message;
            canvas.style.display = 'none';
        }
    }
    
    /**
     * Получение экземпляра графика
     */
    function getChart(canvasId) {
        return charts[canvasId] || null;
    }
    
    /**
     * Уничтожение графика
     */
    function destroyChart(canvasId) {
        if (charts[canvasId]) {
            charts[canvasId].destroy();
            delete charts[canvasId];
            console.log(`[Visualization] График уничтожен: ${canvasId}`);
        }
    }
    
    /**
     * Экспорт графика в изображение
     */
    function exportChartToImage(canvasId, filename = 'chart.png') {
        const chart = charts[canvasId];
        if (chart) {
            const canvas = document.getElementById(canvasId);
            const image = canvas.toDataURL('image/png');
            
            const link = document.createElement('a');
            link.download = filename;
            link.href = image;
            link.click();
            
            console.log(`[Visualization] График экспортирован: ${filename}`);
            return true;
        }
        return false;
    }
    
    // Публичный API модуля
    return {
        init: init,
        createConsumptionChart: createConsumptionChart,
        createZoneComparisonChart: createZoneComparisonChart,
        createAlertsChart: createAlertsChart,
        refreshAllCharts: refreshAllCharts,
        getChart: getChart,
        destroyChart: destroyChart,
        exportChartToImage: exportChartToImage,
        loadChartData: loadChartData,
        config: config
    };
    
})();

// Автоматическая инициализация при загрузке страницы
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function() {
        VisualizationModule.init();
    });
}
