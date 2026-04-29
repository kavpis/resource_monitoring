/**
 * Модуль управления уведомлениями и алертами
 */

const AlertsModule = {
    // Показать уведомление
    showNotification: function(title, message, type = 'info') {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, {
                body: message,
                icon: '/resource_monitoring/public/img/logo.png',
                tag: type
            });
        }
        
        // Также показываем toast-уведомление в интерфейсе
        this.showToast(message, type);
    },
    
    // Показать toast-уведомление
    showToast: function(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        // Анимация появления
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Удаление через 5 секунд
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    },
    
    // Запрос разрешения на push-уведомления
    requestPermission: async function() {
        if (!('Notification' in window)) {
            console.warn('Браузер не поддерживает уведомления');
            return false;
        }
        
        if (Notification.permission === 'granted') {
            return true;
        }
        
        if (Notification.permission !== 'denied') {
            const permission = await Notification.requestPermission();
            return permission === 'granted';
        }
        
        return false;
    },
    
    // Обработка новых аварий
    handleNewAlert: function(alertData) {
        const levelMap = {
            'warning': 'Предупреждение',
            'emergency': 'Авария',
            'critical': 'Критическая угроза'
        };
        
        const title = levelMap[alertData.alert_level] || 'Уведомление';
        const message = `${alertData.sensor_name}: ${alertData.description}`;
        
        this.showNotification(title, message, alertData.alert_level);
        
        // Звуковой сигнал для критических аварий
        if (alertData.alert_level === 'critical' || alertData.alert_level === 'emergency') {
            this.playAlertSound();
        }
    },
    
    // Воспроизведение звукового сигнала
    playAlertSound: function() {
        const audio = new Audio('/resource_monitoring/public/audio/alert.mp3');
        audio.play().catch(e => console.log('Звук не воспроизведён:', e));
    }
};

// Автозапуск при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    // Запрашиваем разрешение на уведомления
    AlertsModule.requestPermission();
});
