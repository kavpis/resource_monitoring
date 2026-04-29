<?php
/**
 * Скрипт автоматического опроса устройств
 * 
 * Запускается по расписанию через cron для периодического
 * сбора данных со счётчиков ресурсов.
 * 
 * Использование:
 * php /workspace/cron/poll_devices.php
 * 
 * @package Cron
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Modules\Integrations\DevicePoller;
use Modules\Integrations\Logs\IntegrationLogger;
use Modules\Integrations\AdapterFactory;

// Установка ограничения по времени выполнения
set_time_limit(300); // 5 минут

// Обработка ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

IntegrationLogger::info("=== Запуск скрипта опроса устройств ===", 'CronPoller');

$startTime = microtime(true);
$exitCode = 0;

try {
    // Создание менеджера опроса
    $poller = new DevicePoller();
    
    // Опрос всех активных датчиков
    $results = $poller->pollAllSensors();
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    // Логирование результатов
    IntegrationLogger::info(
        "Опрос завершён успешно",
        'CronPoller',
        [
            'длительность_сек' => $duration,
            'всего_датчиков' => $results['statistics']['total_sensors'],
            'успешно' => $results['statistics']['successful_polls'],
            'ошибок' => $results['statistics']['failed_polls']
        ]
    );
    
    // Вывод краткой статистики в консоль
    echo "Опрос устройств завершён\n";
    echo "Длительность: {$duration} сек\n";
    echo "Всего датчиков: {$results['statistics']['total_sensors']}\n";
    echo "Успешно: {$results['statistics']['successful_polls']}\n";
    echo "Ошибок: {$results['statistics']['failed_polls']}\n";
    
    // Проверка на критическое количество ошибок
    $errorRate = $results['statistics']['total_sensors'] > 0
        ? ($results['statistics']['failed_polls'] / $results['statistics']['total_sensors']) * 100
        : 0;
    
    if ($errorRate > 50) {
        IntegrationLogger::critical(
            "Критический уровень ошибок опроса: {$errorRate}%",
            'CronPoller'
        );
        $exitCode = 2;
    } elseif ($errorRate > 20) {
        IntegrationLogger::warning(
            "Высокий уровень ошибок опроса: {$errorRate}%",
            'CronPoller'
        );
    }
    
} catch (\Exception $e) {
    $exitCode = 1;
    
    IntegrationLogger::critical(
        "Критическая ошибка скрипта опроса: {$e->getMessage()}",
        'CronPoller',
        [
            'файл' => $e->getFile(),
            'строка' => $e->getLine(),
            'трассировка' => $e->getTraceAsString()
        ]
    );
    
    echo "ОШИБКА: {$e->getMessage()}\n";
} finally {
    // Закрытие всех подключений
    AdapterFactory::closeAll();
    
    IntegrationLogger::info("=== Скрипт опроса завершён (код: {$exitCode}) ===", 'CronPoller');
}

exit($exitCode);
