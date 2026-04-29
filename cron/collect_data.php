#!/usr/bin/env php
<?php
/**
 * Скрипт автоматического сбора данных
 * Должен запускаться по расписанию: */30 * * * * /usr/bin/php /path/to/collect_data.php
 */

define('CLI_MODE', true);
define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/config/constants.php';
require_once ROOT_PATH . '/modules/data_collection/DataCollector.php';

if (php_sapi_name() !== 'cli') {
    die("Скрипт должен запускаться в CLI режиме\n");
}

$startTime = microtime(true);
$scriptStart = date('Y-m-d H:i:s');

echo "=== Запуск автоматического сбора данных ===\n";
echo "Время запуска: {$scriptStart}\n";

try {
    $collector = new DataCollector();
    
    echo "Начало сбора данных...\n";
    $result = $collector->collectAll();
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    $scriptEnd = date('Y-m-d H:i:s');
    
    echo "\n=== Результаты сбора данных ===\n";
    echo "Время окончания: {$scriptEnd}\n";
    echo "Время выполнения: {$duration} сек\n";
    echo "Всего счётчиков: {$result['total']}\n";
    echo "Успешно собрано: {$result['collected']}\n";
    echo "Ошибок: {$result['errors']}\n";
    
    if ($result['errors'] > 0) {
        echo "\nДетали ошибок:\n";
        foreach ($result['details'] as $detail) {
            if (!$detail['success']) {
                echo "  - {$detail['sensor_name']}: {$detail['error']}\n";
            }
        }
    }
    
    echo "\n=== Сбор данных завершён ===\n";
    
    // Логирование в файл
    $logFile = LOG_PATH . '/collect_data_' . date('Y-m-d') . '.log';
    $logEntry = "[{$scriptStart} - {$scriptEnd}] Сбор данных: {$result['collected']}/{$result['total']}, Ошибок: {$result['errors']}, Время: {$duration}s\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Выход с кодом в зависимости от результата
    exit($result['errors'] > 0 ? 1 : 0);
    
} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMessage = "КРИТИЧЕСКАЯ ОШИБКА в {$errorTime}: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    
    echo "\n{$errorMessage}\n";
    
    $errorLogFile = LOG_PATH . '/collect_error_' . date('Y-m-d') . '.log';
    file_put_contents($errorLogFile, "[{$errorTime}] {$errorMessage}\n", FILE_APPEND | LOCK_EX);
    
    exit(2);
}
?>