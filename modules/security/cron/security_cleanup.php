#!/usr/bin/env php
<?php
/**
 * security_cleanup.php
 * Cron-скрипт для плановой очистки данных безопасности
 * Запускать ежедневно: 0 2 * * * /usr/bin/php /workspace/modules/security/cron/security_cleanup.php
 */

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../SecurityLogger.php';
require_once __DIR__ . '/../Firewall.php';
require_once __DIR__ . '/../RateLimiter.php';
require_once __DIR__ . '/../SecurityMiddleware.php';

use Modules\Security\SecurityLogger;
use Modules\Security\SecurityMiddleware;

// Устанавливаем часовой пояс
date_default_timezone_set('Europe/Moscow');

SecurityLogger::log('Cron', '=== Запуск скрипта очистки безопасности ===', 'info');

try {
    // Запускаем очистку через SecurityMiddleware
    $result = SecurityMiddleware::runCleanup();
    
    echo "Очистка завершена успешно:\n";
    echo "- Очищено правил фаервола: {$result['firewall_cleaned']}\n";
    echo "- Очищено записей rate limiting: {$result['rate_limit_cleaned']}\n";
    echo "- Ротация логов (удалено записей): {$result['logs_rotated']}\n";
    
    SecurityLogger::log('Cron', 'Скрипт очистки завершен успешно', 'info', $result);
    
} catch (Exception $e) {
    $errorMsg = "Ошибка выполнения скрипта очистки: " . $e->getMessage();
    echo "ERROR: $errorMsg\n";
    SecurityLogger::log('Cron', $errorMsg, 'critical', ['exception' => $e->getTraceAsString()]);
    exit(1);
}

echo "=== Скрипт завершен ===\n";
exit(0);
