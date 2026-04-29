// ========================================
// Основные скрипты приложения
// ========================================

// Показать уведомление
function showAlert(message, type = 'info') {
    const types = {
        'success': {bg: '#c6f6d5', border: '#68d391', color: '#276749', icon: '✅'},
        'warning': {bg: '#feebc8', border: '#f6ad55', color: '#92400e', icon: '⚠️'},
        'danger': {bg: '#fed7d7', border: '#feb2b2', color: '#742a2a', icon: '❌'},
        'info': {bg: '#bee3f8', border: '#63b3ed', color: '#2c5282', icon: 'ℹ️'}
    };
    
    const config = types[type] || types.info;
    
    const alert = document.createElement('div');
    alert.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background-color: ${config.bg};
        border: 1px solid ${config.border};
        color: ${config.color};
        padding: 16px 24px;
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        animation: slideIn 0.3s, fadeOut 0.5s 2.5s;
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 500;
    `;
    
    alert.innerHTML = `<span style="font-size: 24px;">${config.icon}</span> ${message}`;
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, 3000);
}

// Анимации
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes fadeOut {
        from {
            opacity: 1;
        }
        to {
            opacity: 0;
            transform: translateX(20px);
        }
    }
`;
document.head.appendChild(style);

// Подтверждение удаления
function confirmDelete(message = 'Вы уверены, что хотите удалить?') {
    return confirm(message);
}

// Форматирование даты
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Форматирование числа
function formatNumber(num, decimals = 2) {
    return num.toLocaleString('ru-RU', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// AJAX запрос
function ajax(url, options = {}) {
    return fetch(url, {
        method: options.method || 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...options.headers
        },
        body: options.body ? JSON.stringify(options.body) : null
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    });
}

// Автообновление данных
function autoRefresh(callback, interval = 30000) {
    callback();
    setInterval(callback, interval);
}

console.log('✅ Система мониторинга ресурсов загружена');