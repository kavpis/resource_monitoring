// ========================================
// Графики с использованием Chart.js
// ========================================

// Создание графика потребления
function createConsumptionChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Потребление',
                 data.values,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
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
}

// Создание круговой диаграммы
function createPieChart(canvasId, data) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'doughnut',
         {
            labels: data.labels,
            datasets: [{
                 data.values,
                backgroundColor: [
                    '#667eea',
                    '#764ba2',
                    '#f8b400',
                    '#f093fb',
                    '#4facfe'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right'
                }
            },
            cutout: '60%'
        }
    });
}

// Создание графика по ресурсам
function createResourceChart(canvasId, datasets) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    const colors = {
        water: '#3498db',
        heat: '#e74c3c',
        electricity: '#f39c12'
    };
    
    const chartData = {
        labels: datasets[0].labels,
        datasets: datasets.map(dataset => ({
            label: dataset.label,
            data: dataset.data,
            borderColor: colors[dataset.resource] || '#667eea',
            backgroundColor: (colors[dataset.resource] || '#667eea') + '20',
            borderWidth: 2,
            fill: true,
            tension: 0.3
        }))
    };
    
    return new Chart(ctx, {
        type: 'line',
         chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                }
            }
        }
    });
}

// Обновление данных графика
function updateChart(chart, newData) {
    chart.data.labels = newData.labels;
    chart.data.datasets[0].data = newData.values;
    chart.update();
}