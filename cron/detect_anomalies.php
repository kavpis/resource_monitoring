#!/usr/bin/env php
<?php
/**
 * Скрипт автоматического анализа аномалий
 * Размещается в планировщике задач (cron)
 * 
 * Пример настройки в crontab (запуск каждые 5 минут):
 * 0/5 * * * * /usr/bin/php /path/to/resource_monitoring/cron/detect_anomalies.php >> /var/log/anomaly_detection.log 2>&1
 */

define('CLI_MODE', true);
define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/config/constants.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/modules/anomaly_detection/DetectionEngine.php';
require_once ROOT_PATH . '/modules/anomaly_detection/WaterLeakDetector.php';
require_once ROOT_PATH . '/modules/anomaly_detection/HeatAnomalyDetector.php';
require_once ROOT_PATH . '/modules/anomaly_detection/PowerAnomalyDetector.php';

if (php_sapi_name() !== 'cli') {
    die("Скрипт должен запускаться в CLI режиме\n");
}

$startTime = microtime(true);
$scriptStart = date('Y-m-d H:i:s');

echo "=== Запуск автоматического анализа аномалий ===\n";
echo "Время запуска: {$scriptStart}\n";

try {
    // Инициализация детекторов
    $detectionEngine = new DetectionEngine();
    $waterDetector = new WaterLeakDetector();
    $heatDetector = new HeatAnomalyDetector();
    $powerDetector = new PowerAnomalyDetector();
    
    echo "Инициализация детекторов... ✅\n";
    
    // Анализ всех счётчиков
    echo "Начало анализа данных...\n";
    
    $result = $detectionEngine->analyzeData(null, 1); // За последний час
    
    if ($result['success']) {
        $anomaliesFound = $result['data']['alerts_generated'];
        $duration = $result['data']['duration_seconds'];
        
        echo "\n📊 Результаты анализа:\n";
        echo "  - Проанализировано счётчиков: {$result['data']['analyzed_sensors']}\n";
        echo "  - Обнаружено аномалий: {$anomaliesFound}\n";
        echo "  - Время выполнения: {$duration} сек\n";
        
        if ($anomaliesFound > 0) {
            echo "\n🚨 Обнаруженные аномалии:\n";
            foreach ($result['data']['alerts'] as $alert) {
                echo "  - [{$alert['alert_level']}] {$alert['description']}\n";
            }
        } else {
            echo "  - Аномалий не обнаружено ✅\n";
        }
        
        $scriptEnd = date('Y-m-d H:i:s');
        $durationTotal = round(microtime(true) - $startTime, 2);
        
        echo "\nВремя окончания: {$scriptEnd}\n";
        echo "Общее время выполнения: {$durationTotal} сек\n";
        echo "\n=== Анализ аномалий завершён ===\n";
        
        // Логирование в файл
        $logFile = LOG_PATH . '/anomaly_detection_' . date('Y-m-d') . '.log';
        $logEntry = "[{$scriptStart} - {$scriptEnd}] Анализ завершён. Аномалий: {$anomaliesFound}, Время: {$durationTotal}s\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        exit($anomaliesFound > 0 ? 1 : 0); // 1 - есть аномалии, 0 - всё нормально
        
    } else {
        echo "❌ Ошибка анализа: {$result['message']}\n";
        exit(2);
    }
    
} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMessage = "КРИТИЧЕСКАЯ ОШИБКА в {$errorTime}: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    
    echo "\n{$errorMessage}\n";
    
    // Логирование ошибки
    $errorLogFile = LOG_PATH . '/anomaly_detection_error_' . date('Y-m-d') . '.log';
    file_put_contents($errorLogFile, "[{$errorTime}] {$errorMessage}\n", FILE_APPEND | LOCK_EX);
    
    exit(3); // Код ошибки
}
?>