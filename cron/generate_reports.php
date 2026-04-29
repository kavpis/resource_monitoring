<?php
/**
 * Cron-скрипт автоматической генерации отчетов
 * Запускается по расписанию (ежедневно/еженедельно/ежемесячно)
 * 
 * Пример настройки crontab:
 * 0 1 * * * /usr/bin/php /path/to/cron/generate_reports.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/reports/ReportGenerator.php';
require_once __DIR__ . '/../modules/reports/ExportService.php';

// Константы для логирования
define('REPORTS_LOG_FILE', __DIR__ . '/../logs/reports_cron.log');
define('REPORTS_EXPORT_DIR', __DIR__ . '/../uploads/reports/scheduled');

/**
 * Логирование событий
 */
function logMessage($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(REPORTS_LOG_FILE, $logEntry, FILE_APPEND);
}

/**
 * Создание директории для экспорта
 */
if (!is_dir(REPORTS_EXPORT_DIR)) {
    mkdir(REPORTS_EXPORT_DIR, 0755, true);
    logMessage('INFO', 'Создана директория для запланированных отчетов: ' . REPORTS_EXPORT_DIR);
}

logMessage('INFO', '=== ЗАПУСК ГЕНЕРАЦИИ ОТЧЕТОВ ПО РАСПИСАНИЮ ===');

try {
    $reportGenerator = new ReportGenerator();
    $exportService = new ExportService();
    
    // Определение периода для отчетов (вчера)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $lastWeekStart = date('Y-m-d', strtotime('monday last week'));
    $lastMonthStart = date('Y-m-d', strtotime('first day of last month'));
    $lastMonthEnd = date('Y-m-d', strtotime('last day of last month'));
    
    $generatedReports = [];
    $errors = [];
    
    // ============================================
    // ЕЖЕДНЕВНЫЕ ОТЧЕТЫ
    // ============================================
    logMessage('INFO', 'Генерация ежедневных отчетов за ' . $yesterday);
    
    // Отчет по потреблению воды за день
    try {
        $result = $reportGenerator->generateConsumptionReport([
            'start_date' => $yesterday,
            'end_date' => $yesterday,
            'resource_type' => 'water',
            'group_by' => 'hour',
            'format' => 'json'
        ]);
        
        if ($result['success']) {
            $generatedReports[] = [
                'type' => 'daily_water_consumption',
                'period' => $yesterday,
                'file' => 'daily_water_' . $yesterday . '.json',
                'status' => 'success'
            ];
            logMessage('INFO', 'Ежедневный отчет по воде сгенерирован успешно');
        } else {
            $errors[] = 'Ошибка генерации ежедневного отчета по воде: ' . ($result['message'] ?? 'Неизвестная ошибка');
            logMessage('ERROR', 'Ошибка генерации ежедневного отчета по воде: ' . ($result['message'] ?? 'Неизвестная ошибка'));
        }
    } catch (Exception $e) {
        $errors[] = 'Исключение при генерации отчета по воде: ' . $e->getMessage();
        logMessage('ERROR', 'Исключение: ' . $e->getMessage());
    }
    
    // Отчет по потреблению тепла за день
    try {
        $result = $reportGenerator->generateConsumptionReport([
            'start_date' => $yesterday,
            'end_date' => $yesterday,
            'resource_type' => 'heat',
            'group_by' => 'hour',
            'format' => 'json'
        ]);
        
        if ($result['success']) {
            $generatedReports[] = [
                'type' => 'daily_heat_consumption',
                'period' => $yesterday,
                'file' => 'daily_heat_' . $yesterday . '.json',
                'status' => 'success'
            ];
            logMessage('INFO', 'Ежедневный отчет по теплу сгенерирован успешно');
        }
    } catch (Exception $e) {
        $errors[] = 'Исключение при генерации отчета по теплу: ' . $e->getMessage();
        logMessage('ERROR', 'Исключение: ' . $e->getMessage());
    }
    
    // Отчет по потреблению электричества за день
    try {
        $result = $reportGenerator->generateConsumptionReport([
            'start_date' => $yesterday,
            'end_date' => $yesterday,
            'resource_type' => 'electricity',
            'group_by' => 'hour',
            'format' => 'json'
        ]);
        
        if ($result['success']) {
            $generatedReports[] = [
                'type' => 'daily_electricity_consumption',
                'period' => $yesterday,
                'file' => 'daily_electricity_' . $yesterday . '.json',
                'status' => 'success'
            ];
            logMessage('INFO', 'Ежедневный отчет по электричеству сгенерирован успешно');
        }
    } catch (Exception $e) {
        $errors[] = 'Исключение при генерации отчета по электричеству: ' . $e->getMessage();
        logMessage('ERROR', 'Исключение: ' . $e->getMessage());
    }
    
    // Отчет по авариям за день
    try {
        $result = $reportGenerator->generateAlertsReport([
            'start_date' => $yesterday,
            'end_date' => $yesterday,
            'format' => 'json'
        ]);
        
        if ($result['success']) {
            $generatedReports[] = [
                'type' => 'daily_alerts',
                'period' => $yesterday,
                'file' => 'daily_alerts_' . $yesterday . '.json',
                'status' => 'success'
            ];
            logMessage('INFO', 'Ежедневный отчет по авариям сгенерирован успешно');
        }
    } catch (Exception $e) {
        $errors[] = 'Исключение при генерации отчета по авариям: ' . $e->getMessage();
        logMessage('ERROR', 'Исключение: ' . $e->getMessage());
    }
    
    // ============================================
    // ЕЖЕНЕДЕЛЬНЫЕ ОТЧЕТЫ (генерируются по понедельникам)
    // ============================================
    if (date('N') == '1') {
        logMessage('INFO', 'Генерация еженедельных отчетов за прошлую неделю');
        
        try {
            $result = $reportGenerator->generateConsumptionReport([
                'start_date' => $lastWeekStart,
                'end_date' => $yesterday,
                'resource_type' => 'all',
                'group_by' => 'day',
                'format' => 'excel'
            ]);
            
            if ($result['success']) {
                $generatedReports[] = [
                    'type' => 'weekly_consumption',
                    'period' => $lastWeekStart . ' - ' . $yesterday,
                    'file' => 'weekly_consumption_' . $lastWeekStart . '.xlsx',
                    'status' => 'success'
                ];
                logMessage('INFO', 'Еженедельный отчет по потреблению сгенерирован успешно');
            }
        } catch (Exception $e) {
            $errors[] = 'Исключение при генерации еженедельного отчета: ' . $e->getMessage();
            logMessage('ERROR', 'Исключение: ' . $e->getMessage());
        }
        
        try {
            $result = $reportGenerator->generateEfficiencyReport([
                'start_date' => $lastWeekStart,
                'end_date' => $yesterday,
                'format' => 'pdf'
            ]);
            
            if ($result['success']) {
                $generatedReports[] = [
                    'type' => 'weekly_efficiency',
                    'period' => $lastWeekStart . ' - ' . $yesterday,
                    'file' => 'weekly_efficiency_' . $lastWeekStart . '.pdf',
                    'status' => 'success'
                ];
                logMessage('INFO', 'Еженедельный отчет по эффективности сгенерирован успешно');
            }
        } catch (Exception $e) {
            $errors[] = 'Исключение при генерации еженедельного отчета по эффективности: ' . $e->getMessage();
            logMessage('ERROR', 'Исключение: ' . $e->getMessage());
        }
    }
    
    // ============================================
    // ЕЖЕМЕСЯЧНЫЕ ОТЧЕТЫ (генерируются 1 числа месяца)
    // ============================================
    if (date('j') == '1') {
        logMessage('INFO', 'Генерация ежемесячных отчетов за прошлый месяц');
        
        try {
            $result = $reportGenerator->generateConsumptionReport([
                'start_date' => $lastMonthStart,
                'end_date' => $lastMonthEnd,
                'resource_type' => 'all',
                'group_by' => 'day',
                'format' => 'excel'
            ]);
            
            if ($result['success']) {
                $generatedReports[] = [
                    'type' => 'monthly_consumption',
                    'period' => $lastMonthStart . ' - ' . $lastMonthEnd,
                    'file' => 'monthly_consumption_' . $lastMonthStart . '.xlsx',
                    'status' => 'success'
                ];
                logMessage('INFO', 'Ежемесячный отчет по потреблению сгенерирован успешно');
            }
        } catch (Exception $e) {
            $errors[] = 'Исключение при генерации ежемесячного отчета: ' . $e->getMessage();
            logMessage('ERROR', 'Исключение: ' . $e->getMessage());
        }
        
        try {
            $result = $reportGenerator->generateAlertsReport([
                'start_date' => $lastMonthStart,
                'end_date' => $lastMonthEnd,
                'format' => 'pdf'
            ]);
            
            if ($result['success']) {
                $generatedReports[] = [
                    'type' => 'monthly_alerts',
                    'period' => $lastMonthStart . ' - ' . $lastMonthEnd,
                    'file' => 'monthly_alerts_' . $lastMonthStart . '.pdf',
                    'status' => 'success'
                ];
                logMessage('INFO', 'Ежемесячный отчет по авариям сгенерирован успешно');
            }
        } catch (Exception $e) {
            $errors[] = 'Исключение при генерации ежемесячного отчета по авариям: ' . $e->getMessage();
            logMessage('ERROR', 'Исключение: ' . $e->getMessage());
        }
        
        try {
            $result = $reportGenerator->generateEnterpriseSummaryReport([
                'start_date' => $lastMonthStart,
                'end_date' => $lastMonthEnd
            ]);
            
            if ($result['success']) {
                $generatedReports[] = [
                    'type' => 'monthly_enterprise_summary',
                    'period' => $lastMonthStart . ' - ' . $lastMonthEnd,
                    'file' => 'monthly_summary_' . $lastMonthStart . '.json',
                    'status' => 'success'
                ];
                logMessage('INFO', 'Ежемесячный сводный отчет по предприятию сгенерирован успешно');
            }
        } catch (Exception $e) {
            $errors[] = 'Исключение при генерации сводного отчета: ' . $e->getMessage();
            logMessage('ERROR', 'Исключение: ' . $e->getMessage());
        }
    }
    
    // ============================================
    // ИТОГИ ВЫПОЛНЕНИЯ
    // ============================================
    $totalGenerated = count($generatedReports);
    $totalErrors = count($errors);
    
    logMessage('INFO', '=== ЗАВЕРШЕНИЕ ГЕНЕРАЦИИ ОТЧЕТОВ ===');
    logMessage('INFO', 'Успешно сгенерировано отчетов: ' . $totalGenerated);
    logMessage('INFO', 'Ошибок: ' . $totalErrors);
    
    if ($totalErrors > 0) {
        logMessage('WARNING', 'Список ошибок:');
        foreach ($errors as $error) {
            logMessage('WARNING', '  - ' . $error);
        }
    }
    
    // Вывод результатов в консоль
    echo "=== Генерация отчетов завершена ===" . PHP_EOL;
    echo "Успешно сгенерировано: $totalGenerated" . PHP_EOL;
    echo "Ошибок: $totalErrors" . PHP_EOL;
    
    if ($totalGenerated > 0) {
        echo PHP_EOL . "Сгенерированные отчеты:" . PHP_EOL;
        foreach ($generatedReports as $report) {
            echo "  ✓ {$report['type']} ({$report['period']})" . PHP_EOL;
        }
    }
    
    if ($totalErrors > 0) {
        echo PHP_EOL . "Ошибки:" . PHP_EOL;
        foreach ($errors as $error) {
            echo "  ✗ $error" . PHP_EOL;
        }
    }
    
    exit($totalErrors > 0 ? 1 : 0);
    
} catch (Exception $e) {
    logMessage('CRITICAL', 'Критическая ошибка выполнения скрипта: ' . $e->getMessage());
    echo "КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
