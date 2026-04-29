<?php
/**
 * Сервис экспорта отчётов
 * Раздел 6.4 — Модуль визуализации
 */

require_once __DIR__ . '/ReportGenerator.php';

class ExportService {
    private $reportGenerator;
    private $uploadDir;
    private $maxFileSize = 50 * 1024 * 1024; // 50 MB
    
    public function __construct() {
        $this->reportGenerator = new ReportGenerator();
        $this->uploadDir = ROOT_PATH . '/uploads/reports';
        
        // Создание директории, если не существует
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * Экспорт отчёта в файл
     */
    public function exportToFile($reportType, $params) {
        try {
            // Генерация отчёта
            $result = match($reportType) {
                'consumption' => $this->reportGenerator->generateConsumptionReport($params),
                'alerts' => $this->reportGenerator->generateAlertsReport($params),
                'efficiency' => $this->reportGenerator->generateEfficiencyReport($params),
                'custom' => $this->reportGenerator->generateCustomReport($params),
                default => ['success' => false, 'message' => 'Неизвестный тип отчёта']
            };
            
            if (!$result['success']) {
                return $result;
            }
            
            // Получение данных отчёта
            $reportData = $result['data'];
            $format = $result['format'];
            $fileName = $result['filename'];
            
            // Определение MIME-типа
            $mimeTypes = [
                'json' => 'application/json',
                'csv' => 'text/csv',
                'excel_html' => 'application/vnd.ms-excel',
                'pdf_html' => 'application/pdf',
                'xml' => 'application/xml'
            ];
            
            $mimeType = $mimeTypes[$format] ?? 'application/octet-stream';
            
            // Создание файла
            $filePath = $this->uploadDir . '/' . $fileName;
            
            $writeResult = file_put_contents($filePath, $reportData);
            
            if ($writeResult === false) {
                return [
                    'success' => false,
                    'message' => 'Ошибка при сохранении файла'
                ];
            }
            
            // Проверка размера файла
            $fileSize = filesize($filePath);
            if ($fileSize > $this->maxFileSize) {
                unlink($filePath);
                return [
                    'success' => false,
                    'message' => 'Размер файла превышает допустимый лимит (' . ($this->maxFileSize / 1024 / 1024) . ' MB)'
                ];
            }
            
            // Логирование создания отчёта
            $this->logReportGeneration($reportType, $params, $fileName, $fileSize);
            
            return [
                'success' => true,
                'message' => 'Отчёт успешно экспортирован',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'download_url' => '/resource_monitoring/uploads/reports/' . $fileName,
                'format' => $format
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Ошибка при экспорте: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Экспорт в Excel (реализация через библиотеку PHPExcel/PhpSpreadsheet)
     */
    public function exportToExcel($reportType, $params) {
        $params['format'] = 'excel';
        return $this->exportToFile($reportType, $params);
    }
    
    /**
     * Экспорт в PDF (реализация через библиотеку TCPDF)
     */
    public function exportToPDF($reportType, $params) {
        $params['format'] = 'pdf';
        return $this->exportToFile($reportType, $params);
    }
    
    /**
     * Экспорт в CSV
     */
    public function exportToCSV($reportType, $params) {
        $params['format'] = 'csv';
        return $this->exportToFile($reportType, $params);
    }
    
    /**
     * Экспорт в JSON
     */
    public function exportToJSON($reportType, $params) {
        $params['format'] = 'json';
        return $this->exportToFile($reportType, $params);
    }
    
    /**
     * Экспорт в XML
     */
    public function exportToXML($reportType, $params) {
        $params['format'] = 'xml';
        return $this->exportToFile($reportType, $params);
    }
    
    /**
     * Получение списка сохранённых отчётов
     */
    public function getSavedReports($limit = 50, $offset = 0) {
        $files = glob($this->uploadDir . '/*');
        
        $reports = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $fileName = basename($file);
                $fileSize = filesize($file);
                $modifiedTime = date('Y-m-d H:i:s', filemtime($file));
                
                $reports[] = [
                    'file_name' => $fileName,
                    'size' => $fileSize,
                    'size_formatted' => $this->formatFileSize($fileSize),
                    'modified_at' => $modifiedTime,
                    'download_url' => '/resource_monitoring/uploads/reports/' . $fileName,
                    'type' => pathinfo($fileName, PATHINFO_EXTENSION),
                    'path' => $file
                ];
            }
        }
        
        // Сортировка по дате модификации (новые первыми)
        usort($reports, function($a, $b) {
            return strtotime($b['modified_at']) - strtotime($a['modified_at']);
        });
        
        // Применение лимита и смещения
        $total = count($reports);
        $reports = array_slice($reports, $offset, $limit);
        
        return [
            'success' => true,
            'data' => $reports,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Удаление старых отчётов
     */
    public function cleanupOldReports($days = 30) {
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $deletedCount = 0;
        
        $files = glob($this->uploadDir . '/*');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }
        
        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'message' => "Удалено {$deletedCount} старых отчётов старше {$days} дней"
        ];
    }
    
    /**
     * Получение статистики по отчётам
     */
    public function getStatistics() {
        $reports = $this->getSavedReports();
        
        $stats = [
            'total_reports' => $reports['total'],
            'total_size' => 0,
            'formats_distribution' => [],
            'oldest_report' => null,
            'newest_report' => null
        ];
        
        foreach ($reports['data'] as $report) {
            $stats['total_size'] += $report['size'];
            
            $format = $report['type'];
            $stats['formats_distribution'][$format] = ($stats['formats_distribution'][$format] ?? 0) + 1;
            
            if (!$stats['oldest_report'] || $report['modified_at'] < $stats['oldest_report']) {
                $stats['oldest_report'] = $report['modified_at'];
            }
            
            if (!$stats['newest_report'] || $report['modified_at'] > $stats['newest_report']) {
                $stats['newest_report'] = $report['modified_at'];
            }
        }
        
        $stats['total_size_mb'] = round($stats['total_size'] / (1024 * 1024), 2);
        
        return [
            'success' => true,
            'data' => $stats
        ];
    }
    
    /**
     * Загрузка отчёта для скачивания
     */
    public function downloadReport($fileName) {
        $filePath = $this->uploadDir . '/' . $fileName;
        
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'message' => 'Файл отчёта не найден'
            ];
        }
        
        // Проверка безопасности имени файла
        if (strpos($fileName, '..') !== false || strpos($fileName, '/') !== false) {
            return [
                'success' => false,
                'message' => 'Неверное имя файла'
            ];
        }
        
        // Отправка файла для скачивания
        $fileSize = filesize($filePath);
        $mimeType = mime_content_type($filePath);
        
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        
        readfile($filePath);
        exit();
    }
    
    /**
     * Удаление конкретного отчёта
     */
    public function deleteReport($fileName) {
        $filePath = $this->uploadDir . '/' . $fileName;
        
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'message' => 'Файл отчёта не найден'
            ];
        }
        
        $result = unlink($filePath);
        
        if ($result) {
            // Логирование удаления
            $this->logReportDeletion($fileName);
            
            return [
                'success' => true,
                'message' => 'Отчёт удалён'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при удалении файла'
            ];
        }
    }
    
    /**
     * Логирование генерации отчёта
     */
    private function logReportGeneration($reportType, $params, $fileName, $fileSize) {
        // В реальной системе здесь будет запись в таблицу report_history
        // или в лог-файл
        $logEntry = sprintf(
            "[%s] Report generated: %s, Type: %s, Size: %d bytes, Params: %s\n",
            date('Y-m-d H:i:s'),
            $fileName,
            $reportType,
            $fileSize,
            json_encode($params, JSON_UNESCAPED_UNICODE)
        );
        
        $logFile = LOG_PATH . '/reports_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Логирование удаления отчёта
     */
    private function logReportDeletion($fileName) {
        $logEntry = sprintf(
            "[%s] Report deleted: %s\n",
            date('Y-m-d H:i:s'),
            $fileName
        );
        
        $logFile = LOG_PATH . '/reports_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Форматирование размера файла
     */
    private function formatFileSize($size) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 2) . ' ' . $units[$unit];
    }
    
    /**
     * Валидация параметров отчёта
     */
    public function validateReportParams($reportType, $params) {
        $required = match($reportType) {
            'consumption', 'alerts', 'efficiency' => ['start_date', 'end_date'],
            'custom' => ['query', 'start_date', 'end_date'],
            default => []
        };
        
        foreach ($required as $param) {
            if (empty($params[$param])) {
                return [
                    'success' => false,
                    'message' => "Параметр '{$param}' обязателен для отчёта типа {$reportType}"
                ];
            }
        }
        
        // Проверка формата дат
        if (!empty($params['start_date']) && !strtotime($params['start_date'])) {
            return [
                'success' => false,
                'message' => 'Неверный формат даты начала'
            ];
        }
        
        if (!empty($params['end_date']) && !strtotime($params['end_date'])) {
            return [
                'success' => false,
                'message' => 'Неверный формат даты окончания'
            ];
        }
        
        // Проверка диапазона дат
        if (!empty($params['start_date']) && !empty($params['end_date'])) {
            $start = strtotime($params['start_date']);
            $end = strtotime($params['end_date']);
            
            if ($start > $end) {
                return [
                    'success' => false,
                    'message' => 'Дата начала не может быть позже даты окончания'
                ];
            }
        }
        
        return ['success' => true];
    }
}
?>