<?php
/**
 * Константы системы мониторинга ресурсов
 * Раздел 6.3 — Настройка системы
 */

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'resource_monitoring');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Настройки приложения
define('APP_NAME', 'Система мониторинга ресурсов');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/resource_monitoring');

// Пути к директориям
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('REPORTS_PATH', UPLOAD_PATH . '/reports');
define('LOG_PATH', ROOT_PATH . '/logs');

// Настройки безопасности
define('SESSION_LIFETIME', 3600); // 1 час
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 300); // 5 минут блокировки

// Настройки сбора данных
define('DEFAULT_POLLING_INTERVAL', 30); // секунд
define('MODBUS_DEFAULT_PORT', 502);
define('MODBUS_TIMEOUT', 3); // секунд
define('MODBUS_RETRIES', 3);

// Настройки хранения данных
define('DATA_RETENTION_DAYS', 1095); // 3 года

// Уровни критичности аварий
define('ALERT_LEVELS', [
    'warning' => 'Предупреждение',
    'emergency' => 'Авария',
    'critical' => 'Критическая угроза'
]);

// Роли пользователей
define('USER_ROLES', [
    'dispatcher' => 'Диспетчер',
    'engineer' => 'Инженер',
    'admin' => 'Администратор',
    'manager' => 'Руководитель'
]);

// Типы ресурсов
define('RESOURCE_TYPES', [
    'water' => 'Вода',
    'heat' => 'Тепло',
    'electricity' => 'Электричество'
]);

// Типы аномалий
define('ANOMALY_TYPES', [
    'water_leak' => 'Утечка воды',
    'heat_overheat' => 'Перегрев теплоносителя',
    'heat_low_delta' => 'Низкая дельта температур',
    'power_overload' => 'Перегрузка по мощности',
    'voltage_low' => 'Низкое напряжение',
    'voltage_high' => 'Высокое напряжение',
    'power_factor_low' => 'Низкий коэффициент мощности'
]);

// Форматы отчётов
define('REPORT_FORMATS', [
    'pdf' => 'PDF',
    'excel' => 'Excel',
    'csv' => 'CSV'
]);
?>