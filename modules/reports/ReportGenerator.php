<?php
/**
 * Модуль генерации отчётов
 * Раздел 6.4 — Модуль визуализации
 * Раздел 7.1 — Структура программного обеспечения
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../models/Sensor.php';
require_once __DIR__ . '/../../models/Reading.php';
require_once __DIR__ . '/../../models/Alert.php';
require_once __DIR__ . '/../../models/Zone.php';

class ReportGenerator {
    private $db;
    private $sensorModel;
    private $readingModel;
    private $alertModel;
    private $zoneModel;
    private $auth;
    
    public function __construct() {
        $this->db = getDB();
        $this->sensorModel = new Sensor();
        $this->readingModel = new Reading();
        $this->alertModel = new Alert();
        $this->zoneModel = new Zone();
        $this->auth = new Auth();
    }
    
    /**
     * Генерация отчёта по потреблению ресурсов
     */
    public function generateConsumptionReport($params) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $zoneId = $params['zone_id'] ?? null;
        $resourceType = $params['resource_type'] ?? 'all';
        $format = $params['format'] ?? 'json';
        $groupBy = $params['group_by'] ?? 'day'; // day, week, month, hour
        $aggregation = $params['aggregation'] ?? 'sum'; // sum, avg, max, min
        
        // Формирование условий запроса
        $whereConditions = ["r.reading_timestamp BETWEEN :start AND :end", "r.is_valid = 1"];
        $paramsForQuery = [
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59'
        ];
        
        if ($zoneId) {
            $whereConditions[] = "s.zone_id = :zone_id";
            $paramsForQuery['zone_id'] = $zoneId;
        }
        
        if ($resourceType !== 'all') {
            $whereConditions[] = "s.resource_type = :resource_type";
            $paramsForQuery['resource_type'] = $resourceType;
        }
        
        $whereSql = implode(' AND ', $whereConditions);
        
        // Определение формата группировки для GROUP BY
        $groupFormat = match($groupBy) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d'
        };
        
        // Определение типа агрегации
        $aggFunction = match($aggregation) {
            'avg' => 'AVG',
            'max' => 'MAX',
            'min' => 'MIN',
            'sum' => 'SUM',
            default => 'SUM'
        };
        
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(r.reading_timestamp, :group_format) as period,
                s.resource_type,
                s.zone_id,
                z.zone_name,
                {$aggFunction}(r.reading_value) as aggregated_value,
                COUNT(*) as reading_count,
                AVG(r.reading_value) as avg_value,
                MAX(r.reading_value) as max_value,
                MIN(r.reading_value) as min_value,
                s.unit_of_measurement
            FROM readings r
            INNER JOIN sensors s ON r.sensor_id = s.sensor_id
            INNER JOIN zones z ON s.zone_id = z.zone_id
            WHERE {$whereSql}
            GROUP BY period, s.resource_type, s.zone_id, s.unit_of_measurement
            ORDER BY period ASC
        ");
        
        $stmt->execute(array_merge($paramsForQuery, ['group_format' => $groupFormat]));
        $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Группировка данных по типам ресурсов
        $groupedData = [];
        foreach ($rawData as $row) {
            $key = $row['resource_type'] . '_' . $row['zone_id'];
            if (!isset($groupedData[$key])) {
                $groupedData[$key] = [
                    'resource_type' => $row['resource_type'],
                    'zone_name' => $row['zone_name'],
                    'unit' => $row['unit_of_measurement'],
                    'data' => []
                ];
            }
            $groupedData[$key]['data'][] = [
                'period' => $row['period'],
                'value' => $row['aggregated_value'],
                'avg' => $row['avg_value'],
                'max' => $row['max_value'],
                'min' => $row['min_value'],
                'count' => $row['reading_count']
            ];
        }
        
        $report = [
            'type' => 'consumption',
            'title' => 'Отчёт по потреблению ресурсов',
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'group_by' => $groupBy,
                'aggregation' => $aggregation
            ],
            'filters' => [
                'zone_id' => $zoneId,
                'resource_type' => $resourceType
            ],
            'data' => $groupedData,
            'summary' => $this->calculateConsumptionSummary($rawData),
            'generated_at' => date('Y-m-d H:i:s'),
            'generator' => 'Resource Monitoring System v' . APP_VERSION
        ];
        
        return $this->formatReport($report, $format);
    }
    
    /**
     * Генерация отчёта по авариям
     */
    public function generateAlertsReport($params) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $zoneId = $params['zone_id'] ?? null;
        $alertLevel = $params['alert_level'] ?? 'all';
        $status = $params['status'] ?? 'all';
        $format = $params['format'] ?? 'json';
        
        $whereConditions = ["a.alert_timestamp BETWEEN :start AND :end"];
        $paramsForQuery = [
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59'
        ];
        
        if ($zoneId) {
            $whereConditions[] = "a.zone_id = :zone_id";
            $paramsForQuery['zone_id'] = $zoneId;
        }
        
        if ($alertLevel !== 'all') {
            $whereConditions[] = "a.alert_level = :alert_level";
            $paramsForQuery['alert_level'] = $alertLevel;
        }
        
        if ($status !== 'all') {
            $whereConditions[] = "a.status = :status";
            $paramsForQuery['status'] = $status;
        }
        
        $whereSql = implode(' AND ', $whereConditions);
        
        $stmt = $this->db->prepare("
            SELECT 
                a.alert_timestamp,
                a.alert_type,
                a.alert_level,
                a.description,
                a.current_value,
                a.threshold_value,
                a.status,
                s.sensor_name,
                z.zone_name,
                u.full_name as resolved_by_name,
                a.resolved_at
            FROM alerts a
            LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
            LEFT JOIN zones z ON a.zone_id = z.zone_id
            LEFT JOIN users u ON a.resolved_by = u.user_id
            WHERE {$whereSql}
            ORDER BY a.alert_timestamp DESC
        ");
        
        $stmt->execute($paramsForQuery);
        $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $report = [
            'type' => 'alerts',
            'title' => 'Отчёт по аварийным событиям',
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'filters' => [
                'zone_id' => $zoneId,
                'alert_level' => $alertLevel,
                'status' => $status
            ],
            'data' => $rawData,
            'statistics' => $this->calculateAlertStatistics($rawData),
            'generated_at' => date('Y-m-d H:i:s'),
            'generator' => 'Resource Monitoring System v' . APP_VERSION
        ];
        
        return $this->formatReport($report, $format);
    }
    
    /**
     * Генерация отчёта по эффективности
     */
    public function generateEfficiencyReport($params) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $zoneId = $params['zone_id'] ?? null;
        $format = $params['format'] ?? 'json';
        
        $stmt = $this->db->prepare("
            SELECT 
                z.zone_name,
                z.zone_type,
                COUNT(DISTINCT s.sensor_id) as total_sensors,
                SUM(CASE WHEN s.status = 'online' THEN 1 ELSE 0 END) as online_sensors,
                SUM(CASE WHEN s.status = 'offline' THEN 1 ELSE 0 END) as offline_sensors,
                SUM(CASE WHEN s.status = 'error' THEN 1 ELSE 0 END) as error_sensors,
                COUNT(DISTINCT a.alert_id) as total_alerts,
                SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END) as active_alerts,
                SUM(CASE WHEN a.alert_level = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
                SUM(CASE WHEN a.alert_level = 'emergency' THEN 1 ELSE 0 END) as emergency_alerts,
                SUM(CASE WHEN a.alert_level = 'warning' THEN 1 ELSE 0 END) as warning_alerts,
                CASE 
                    WHEN COUNT(DISTINCT s.sensor_id) > 0 
                    THEN ROUND((SUM(CASE WHEN s.status = 'online' THEN 1 ELSE 0 END) / COUNT(DISTINCT s.sensor_id)) * 100, 2)
                    ELSE 0 
                END as availability_percent,
                CASE 
                    WHEN COUNT(DISTINCT s.sensor_id) > 0 
                    THEN ROUND((COUNT(DISTINCT a.alert_id) / COUNT(DISTINCT s.sensor_id)) * 100, 2)
                    ELSE 0 
                END as alert_density_per_sensor,
                CASE 
                    WHEN COUNT(DISTINCT a.alert_id) > 0 
                    THEN ROUND((SUM(CASE WHEN a.status = 'resolved' THEN 1 ELSE 0 END) / COUNT(DISTINCT a.alert_id)) * 100, 2)
                    ELSE 0 
                END as resolution_rate_percent
            FROM zones z
            LEFT JOIN sensors s ON z.zone_id = s.zone_id
            LEFT JOIN alerts a ON z.zone_id = a.zone_id 
                AND a.alert_timestamp BETWEEN :start AND :end
            WHERE 1=1
        ");
        
        if ($zoneId) {
            $stmt->where .= " AND z.zone_id = :zone_id";
            $stmt->params['zone_id'] = $zoneId;
        }
        
        $stmt->orderBy('z.zone_name');
        
        $stmt->execute(array_merge($paramsForQuery, ['zone_id' => $zoneId]));
        $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $report = [
            'type' => 'efficiency',
            'title' => 'Отчёт по эффективности работы зон',
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'filters' => [
                'zone_id' => $zoneId
            ],
            'data' => $rawData,
            'summary' => $this->calculateEfficiencySummary($rawData),
            'generated_at' => date('Y-m-d H:i:s'),
            'generator' => 'Resource Monitoring System v' . APP_VERSION
        ];
        
        return $this->formatReport($report, $format);
    }
    
    /**
     * Генерация пользовательского отчёта
     */
    public function generateCustomReport($params) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $sqlQuery = $params['query'] ?? null;
        $format = $params['format'] ?? 'json';
        
        if (!$sqlQuery) {
            return [
                'success' => false,
                'message' => 'SQL-запрос обязателен для пользовательского отчёта'
            ];
        }
        
        // Проверка безопасности запроса (только SELECT)
        $sqlQuery = trim($sqlQuery);
        if (strtolower(substr($sqlQuery, 0, 6)) !== 'select') {
            return [
                'success' => false,
                'message' => 'Разрешены только SELECT запросы'
            ];
        }
        
        // Добавление ограничений безопасности
        $forbiddenKeywords = ['drop', 'delete', 'update', 'insert', 'create', 'alter', 'truncate', 'replace'];
        $lowerQuery = strtolower($sqlQuery);
        
        foreach ($forbiddenKeywords as $keyword) {
            if (strpos($lowerQuery, $keyword) !== false) {
                return [
                    'success' => false,
                    'message' => "Запрос содержит запрещённое слово: {$keyword}"
                ];
            }
        }
        
        try {
            $stmt = $this->db->prepare($sqlQuery);
            $stmt->execute();
            $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $report = [
                'type' => 'custom',
                'title' => 'Пользовательский отчёт',
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ],
                'query' => $sqlQuery,
                'data' => $rawData,
                'row_count' => count($rawData),
                'generated_at' => date('Y-m-d H:i:s'),
                'generator' => 'Resource Monitoring System v' . APP_VERSION
            ];
            
            return $this->formatReport($report, $format);
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Ошибка выполнения запроса: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Форматирование отчёта в нужный формат
     */
    private function formatReport($report, $format) {
        switch ($format) {
            case 'json':
                return [
                    'success' => true,
                    'data' => $report,
                    'format' => 'json',
                    'filename' => $report['type'] . '_' . date('Y-m-d_His') . '.json'
                ];
                
            case 'csv':
                return $this->formatAsCSV($report);
                
            case 'excel':
                return $this->formatAsExcel($report);
                
            case 'pdf':
                return $this->formatAsPDF($report);
                
            case 'xml':
                return $this->formatAsXML($report);
                
            default:
                return [
                    'success' => false,
                    'message' => 'Неподдерживаемый формат: ' . $format
                ];
        }
    }
    
    /**
     * Форматирование в CSV
     */
    private function formatAsCSV($report) {
        $data = $report['data'];
        
        if (empty($data)) {
            return [
                'success' => true,
                'data' => 'title,description,value' . "\n" . 'Нет данных,,',
                'format' => 'csv',
                'filename' => $report['type'] . '_' . date('Y-m-d_His') . '.csv'
            ];
        }
        
        // Определение структуры данных для CSV
        if ($report['type'] === 'consumption') {
            // Для отчёта по потреблению
            $csv = ['Период,Тип ресурса,Зона,Значение,Ед.изм.,Количество показаний,Среднее,Максимум,Минимум'];
            
            foreach ($data as $resourceGroup) {
                foreach ($resourceGroup['data'] as $record) {
                    $csv[] = implode(',', [
                        '"' . $record['period'] . '"',
                        '"' . $resourceGroup['resource_type'] . '"',
                        '"' . $resourceGroup['zone_name'] . '"',
                        $record['value'],
                        '"' . $resourceGroup['unit'] . '"',
                        $record['count'],
                        $record['avg'],
                        $record['max'],
                        $record['min']
                    ]);
                }
            }
            
        } elseif ($report['type'] === 'alerts') {
            // Для отчёта по авариям
            $csv = ['Время,Тип,Уровень,Описание,Значение,Порог,Статус,Сенсор,Зона'];
            
            foreach ($data as $alert) {
                $csv[] = implode(',', [
                    '"' . $alert['alert_timestamp'] . '"',
                    '"' . $alert['alert_type'] . '"',
                    '"' . $alert['alert_level'] . '"',
                    '"' . str_replace('"', '""', $alert['description']) . '"',
                    $alert['current_value'] ?? '',
                    $alert['threshold_value'] ?? '',
                    '"' . $alert['status'] . '"',
                    '"' . ($alert['sensor_name'] ?? '') . '"',
                    '"' . ($alert['zone_name'] ?? '') . '"'
                ]);
            }
            
        } elseif ($report['type'] === 'efficiency') {
            // Для отчёта по эффективности
            $csv = ['Зона,Тип,Всего сенсоров,Онлайн,Оффлайн,Ошибки,Всего аварий,Активных,Критических,Доступность %,Плотность аварий %,Процент устранения %'];
            
            foreach ($data as $zone) {
                $csv[] = implode(',', [
                    '"' . $zone['zone_name'] . '"',
                    '"' . $zone['zone_type'] . '"',
                    $zone['total_sensors'],
                    $zone['online_sensors'],
                    $zone['offline_sensors'],
                    $zone['error_sensors'],
                    $zone['total_alerts'],
                    $zone['active_alerts'],
                    $zone['critical_alerts'],
                    $zone['availability_percent'],
                    $zone['alert_density_per_sensor'],
                    $zone['resolution_rate_percent']
                ]);
            }
            
        } else {
            // Для пользовательского отчёта
            if (is_array($data) && !empty($data)) {
                $headers = array_keys($data[0]);
                $csv = [implode(',', array_map(fn($h) => '"' . $h . '"', $headers))];
                
                foreach ($data as $row) {
                    $csvRow = [];
                    foreach ($headers as $header) {
                        $value = $row[$header] ?? '';
                        $value = str_replace('"', '""', $value);
                        $csvRow[] = '"' . $value . '"';
                    }
                    $csv[] = implode(',', $csvRow);
                }
            } else {
                $csv = ['Нет данных'];
            }
        }
        
        return [
            'success' => true,
            'data' => implode("\n", $csv),
            'format' => 'csv',
            'filename' => $report['type'] . '_' . date('Y-m-d_His') . '.csv'
        ];
    }
    
    /**
     * Форматирование в Excel (HTML-таблица)
     */
    private function formatAsExcel($report) {
        $data = $report['data'];
        $type = $report['type'];
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($report['title']) . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .title { font-size: 24px; font-weight: bold; color: #333; }
                .period { font-size: 14px; color: #666; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="title">' . htmlspecialchars($report['title']) . '</div>
                <div class="period">Период: ' . $report['period']['start'] . ' — ' . $report['period']['end'] . '</div>
                <div class="period">Сгенерировано: ' . $report['generated_at'] . '</div>
            </div>';
        
        if ($type === 'consumption') {
            $html .= '<table border="1">
                <thead>
                    <tr>
                        <th>Период</th>
                        <th>Тип ресурса</th>
                        <th>Зона</th>
                        <th>Значение</th>
                        <th>Ед.изм.</th>
                        <th>Количество</th>
                        <th>Среднее</th>
                        <th>Максимум</th>
                        <th>Минимум</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($data as $resourceGroup) {
                foreach ($resourceGroup['data'] as $record) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($record['period']) . '</td>
                        <td>' . htmlspecialchars($resourceGroup['resource_type']) . '</td>
                        <td>' . htmlspecialchars($resourceGroup['zone_name']) . '</td>
                        <td>' . $record['value'] . '</td>
                        <td>' . htmlspecialchars($resourceGroup['unit']) . '</td>
                        <td>' . $record['count'] . '</td>
                        <td>' . $record['avg'] . '</td>
                        <td>' . $record['max'] . '</td>
                        <td>' . $record['min'] . '</td>
                    </tr>';
                }
            }
            
            $html .= '</tbody></table>';
            
        } elseif ($type === 'alerts') {
            $html .= '<table border="1">
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Тип</th>
                        <th>Уровень</th>
                        <th>Описание</th>
                        <th>Значение</th>
                        <th>Порог</th>
                        <th>Статус</th>
                        <th>Сенсор</th>
                        <th>Зона</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($data as $alert) {
                $html .= '<tr>
                    <td>' . htmlspecialchars($alert['alert_timestamp']) . '</td>
                    <td>' . htmlspecialchars($alert['alert_type']) . '</td>
                    <td>' . htmlspecialchars($alert['alert_level']) . '</td>
                    <td>' . htmlspecialchars($alert['description']) . '</td>
                    <td>' . ($alert['current_value'] ?? '') . '</td>
                    <td>' . ($alert['threshold_value'] ?? '') . '</td>
                    <td>' . htmlspecialchars($alert['status']) . '</td>
                    <td>' . htmlspecialchars($alert['sensor_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($alert['zone_name'] ?? '') . '</td>
                </tr>';
            }
            
            $html .= '</tbody></table>';
            
        } elseif ($type === 'efficiency') {
            $html .= '<table border="1">
                <thead>
                    <tr>
                        <th>Зона</th>
                        <th>Тип</th>
                        <th>Всего сенсоров</th>
                        <th>Онлайн</th>
                        <th>Оффлайн</th>
                        <th>Ошибки</th>
                        <th>Всего аварий</th>
                        <th>Активных</th>
                        <th>Критических</th>
                        <th>Доступность %</th>
                        <th>Плотность %</th>
                        <th>Устранение %</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($data as $zone) {
                $html .= '<tr>
                    <td>' . htmlspecialchars($zone['zone_name']) . '</td>
                    <td>' . htmlspecialchars($zone['zone_type']) . '</td>
                    <td>' . $zone['total_sensors'] . '</td>
                    <td>' . $zone['online_sensors'] . '</td>
                    <td>' . $zone['offline_sensors'] . '</td>
                    <td>' . $zone['error_sensors'] . '</td>
                    <td>' . $zone['total_alerts'] . '</td>
                    <td>' . $zone['active_alerts'] . '</td>
                    <td>' . $zone['critical_alerts'] . '</td>
                    <td>' . $zone['availability_percent'] . '</td>
                    <td>' . $zone['alert_density_per_sensor'] . '</td>
                    <td>' . $zone['resolution_rate_percent'] . '</td>
                </tr>';
            }
            
            $html .= '</tbody></table>';
            
        } else {
            // Для пользовательского отчёта
            if (is_array($data) && !empty($data)) {
                $headers = array_keys($data[0]);
                
                $html .= '<table border="1">
                    <thead>
                        <tr>';
                
                foreach ($headers as $header) {
                    $html .= '<th>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $header))) . '</th>';
                }
                
                $html .= '</tr>
                    </thead>
                    <tbody>';
                
                foreach ($data as $row) {
                    $html .= '<tr>';
                    foreach ($headers as $header) {
                        $value = $row[$header] ?? '';
                        $html .= '<td>' . htmlspecialchars($value) . '</td>';
                    }
                    $html .= '</tr>';
                }
                
                $html .= '</tbody></table>';
            } else {
                $html .= '<p>Нет данных для отображения</p>';
            }
        }
        
        $html .= '<div class="footer">
            <p>© ' . date('Y') . ' Система мониторинга ресурсов предприятия</p>
            <p>Отчёт сгенерирован автоматически системой анализа</p>
        </div>
        </body>
        </html>';
        
        return [
            'success' => true,
            'data' => $html,
            'format' => 'excel_html',
            'filename' => $type . '_' . date('Y-m-d_His') . '.xls'
        ];
    }
    
    /**
     * Форматирование в PDF (HTML-шаблон)
     */
    private function formatAsPDF($report) {
        $data = $report['data'];
        $type = $report['type'];
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($report['title']) . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 30px; line-height: 1.6; }
                .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #333; }
                .title { font-size: 28px; font-weight: bold; color: #333; margin: 0; }
                .subtitle { font-size: 16px; color: #666; margin: 10px 0 0 0; }
                .period { font-size: 14px; color: #888; margin: 5px 0 20px 0; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background-color: #f8f9fa; padding: 12px; border: 1px solid #ddd; font-weight: bold; text-align: left; }
                td { padding: 10px; border: 1px solid #eee; vertical-align: top; }
                tr:nth-child(even) { background-color: #f8f9fa; }
                .section-header { font-size: 18px; font-weight: bold; color: #495057; margin: 25px 0 15px 0; }
                .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #6c757d; padding-top: 20px; border-top: 1px solid #dee2e6; }
                .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
                .stat-card { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; }
                .stat-value { font-size: 24px; font-weight: bold; color: #007bff; }
                .stat-label { font-size: 12px; color: #6c757d; text-transform: uppercase; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="title">' . htmlspecialchars($report['title']) . '</div>
                <div class="subtitle">Система мониторинга ресурсов предприятия</div>
                <div class="period">Период: ' . $report['period']['start'] . ' — ' . $report['period']['end'] . '</div>
                <div class="period">Сгенерировано: ' . $report['generated_at'] . '</div>
            </div>';
        
        // Добавление статистики для некоторых типов отчётов
        if (isset($report['summary'])) {
            $html .= '<div class="section-header">📊 Сводная статистика</div>';
            $html .= '<div class="stats-grid">';
            
            foreach ($report['summary'] as $key => $value) {
                $label = match($key) {
                    'total_consumption' => 'Всего потреблено',
                    'avg_consumption' => 'Среднее потребление',
                    'peak_consumption' => 'Пиковое потребление',
                    'total_alerts' => 'Всего аварий',
                    'critical_alerts' => 'Критических',
                    'emergency_alerts' => 'Аварийных',
                    'warning_alerts' => 'Предупреждений',
                    'resolution_rate' => 'Процент устранения',
                    'availability_rate' => 'Доступность систем',
                    default => ucfirst(str_replace('_', ' ', $key))
                };
                
                $html .= '<div class="stat-card">
                    <div class="stat-value">' . (is_numeric($value) ? number_format($value, 2, ',', ' ') : $value) . '</div>
                    <div class="stat-label">' . htmlspecialchars($label) . '</div>
                </div>';
            }
            
            $html .= '</div>';
        }
        
        if ($type === 'consumption') {
            $html .= '<div class="section-header">📈 Потребление по ресурсам</div>';
            
            foreach ($data as $resourceGroup) {
                $html .= '<h4>' . htmlspecialchars($resourceGroup['zone_name']) . ' — ' . htmlspecialchars($resourceGroup['resource_type']) . '</h4>';
                $html .= '<table>
                    <thead>
                        <tr>
                            <th>Период</th>
                            <th>Значение</th>
                            <th>Среднее</th>
                            <th>Максимум</th>
                            <th>Минимум</th>
                            <th>Количество</th>
                        </tr>
                    </thead>
                    <tbody>';
                
                foreach ($resourceGroup['data'] as $record) {
                    $html .= '<tr>
                        <td>' . htmlspecialchars($record['period']) . '</td>
                        <td>' . number_format($record['value'], 2, ',', ' ') . ' ' . htmlspecialchars($resourceGroup['unit']) . '</td>
                        <td>' . number_format($record['avg'], 2, ',', ' ') . '</td>
                        <td>' . number_format($record['max'], 2, ',', ' ') . '</td>
                        <td>' . number_format($record['min'], 2, ',', ' ') . '</td>
                        <td>' . $record['count'] . '</td>
                    </tr>';
                }
                
                $html .= '</tbody></table>';
            }
            
        } elseif ($type === 'alerts') {
            $html .= '<div class="section-header">🚨 Журнал аварийных событий</div>';
            $html .= '<table>
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Уровень</th>
                        <th>Тип</th>
                        <th>Описание</th>
                        <th>Значение</th>
                        <th>Порог</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($data as $alert) {
                $levelIcon = match($alert['alert_level']) {
                    'critical' => '🔴',
                    'emergency' => '🟠',
                    'warning' => '🟡',
                    default => '🔵'
                };
                
                $html .= '<tr>
                    <td>' . htmlspecialchars($alert['alert_timestamp']) . '</td>
                    <td>' . $levelIcon . ' ' . htmlspecialchars($alert['alert_level']) . '</td>
                    <td>' . htmlspecialchars($alert['alert_type']) . '</td>
                    <td>' . htmlspecialchars($alert['description']) . '</td>
                    <td>' . ($alert['current_value'] ?? '—') . '</td>
                    <td>' . ($alert['threshold_value'] ?? '—') . '</td>
                    <td>' . htmlspecialchars($alert['status']) . '</td>
                </tr>';
            }
            
            $html .= '</tbody></table>';
            
        } elseif ($type === 'efficiency') {
            $html .= '<div class="section-header">🎯 Эффективность работы зон</div>';
            $html .= '<table>
                <thead>
                    <tr>
                        <th>Зона</th>
                        <th>Тип</th>
                        <th>Всего сенсоров</th>
                        <th>Онлайн</th>
                        <th>Доступность %</th>
                        <th>Всего аварий</th>
                        <th>Уровень устранения %</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($data as $zone) {
                $html .= '<tr>
                    <td><strong>' . htmlspecialchars($zone['zone_name']) . '</strong></td>
                    <td>' . htmlspecialchars($zone['zone_type']) . '</td>
                    <td>' . $zone['total_sensors'] . '</td>
                    <td>' . $zone['online_sensors'] . '</td>
                    <td>' . $zone['availability_percent'] . '%</td>
                    <td>' . $zone['total_alerts'] . '</td>
                    <td>' . $zone['resolution_rate_percent'] . '%</td>
                </tr>';
            }
            
            $html .= '</tbody></table>';
            
        } else {
            // Для пользовательского отчёта
            $html .= '<div class="section-header">📋 Пользовательский отчёт</div>';
            
            if (is_array($data) && !empty($data)) {
                $headers = array_keys($data[0]);
                
                $html .= '<table>
                    <thead>
                        <tr>';
                
                foreach ($headers as $header) {
                    $html .= '<th>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $header))) . '</th>';
                }
                
                $html .= '</tr>
                    </thead>
                    <tbody>';
                
                foreach ($data as $row) {
                    $html .= '<tr>';
                    foreach ($headers as $header) {
                        $value = $row[$header] ?? '';
                        $html .= '<td>' . htmlspecialchars($value) . '</td>';
                    }
                    $html .= '</tr>';
                }
                
                $html .= '</tbody></table>';
            } else {
                $html .= '<p style="text-align: center; padding: 40px; color: #6c757d;">Нет данных для отображения</p>';
            }
        }
        
        $html .= '<div class="footer">
            <p>© ' . date('Y') . ' Система мониторинга ресурсов предприятия</p>
            <p>Автоматически сгенерированный отчёт системой анализа</p>
        </div>
        </body>
        </html>';
        
        return [
            'success' => true,
            'data' => $html,
            'format' => 'pdf_html',
            'filename' => $type . '_' . date('Y-m-d_His') . '.pdf'
        ];
    }
    
    /**
     * Форматирование в XML
     */
    private function formatAsXML($report) {
        $xml = new SimpleXMLElement('<report></report>');
        $xml->addChild('type', $report['type']);
        $xml->addChild('title', $report['title']);
        $xml->addChild('generated_at', $report['generated_at']);
        $xml->addChild('generator', $report['generator']);
        
        $period = $xml->addChild('period');
        $period->addChild('start', $report['period']['start']);
        $period->addChild('end', $report['period']['end']);
        $period->addChild('group_by', $report['period']['group_by'] ?? 'day');
        $period->addChild('aggregation', $report['period']['aggregation'] ?? 'sum');
        
        $filters = $xml->addChild('filters');
        foreach ($report['filters'] as $key => $value) {
            $filters->addChild($key, $value);
        }
        
        $data = $xml->addChild('data');
        
        if ($report['type'] === 'consumption') {
            foreach ($report['data'] as $resourceGroup) {
                $resource = $data->addChild('resource');
                $resource->addChild('type', $resourceGroup['resource_type']);
                $resource->addChild('zone', $resourceGroup['zone_name']);
                $resource->addChild('unit', $resourceGroup['unit']);
                
                $values = $resource->addChild('values');
                foreach ($resourceGroup['data'] as $record) {
                    $value = $values->addChild('value');
                    $value->addChild('period', $record['period']);
                    $value->addChild('aggregated', $record['value']);
                    $value->addChild('average', $record['avg']);
                    $value->addChild('maximum', $record['max']);
                    $value->addChild('minimum', $record['min']);
                    $value->addChild('count', $record['count']);
                }
            }
        } elseif ($report['type'] === 'alerts') {
            foreach ($report['data'] as $alert) {
                $item = $data->addChild('alert');
                $item->addChild('timestamp', $alert['alert_timestamp']);
                $item->addChild('type', $alert['alert_type']);
                $item->addChild('level', $alert['alert_level']);
                $item->addChild('description', $alert['description']);
                $item->addChild('current_value', $alert['current_value'] ?? '');
                $item->addChild('threshold_value', $alert['threshold_value'] ?? '');
                $item->addChild('status', $alert['status']);
                $item->addChild('sensor', $alert['sensor_name'] ?? '');
                $item->addChild('zone', $alert['zone_name'] ?? '');
            }
        } elseif ($report['type'] === 'efficiency') {
            foreach ($report['data'] as $zone) {
                $item = $data->addChild('zone');
                $item->addChild('name', $zone['zone_name']);
                $item->addChild('type', $zone['zone_type']);
                $item->addChild('total_sensors', $zone['total_sensors']);
                $item->addChild('online_sensors', $zone['online_sensors']);
                $item->addChild('offline_sensors', $zone['offline_sensors']);
                $item->addChild('error_sensors', $zone['error_sensors']);
                $item->addChild('total_alerts', $zone['total_alerts']);
                $item->addChild('availability_percent', $zone['availability_percent']);
                $item->addChild('resolution_rate_percent', $zone['resolution_rate_percent']);
            }
        } else {
            // Для пользовательского отчёта
            foreach ($report['data'] as $row) {
                $item = $data->addChild('record');
                foreach ($row as $key => $value) {
                    $item->addChild($key, $value);
                }
            }
        }
        
        return [
            'success' => true,
            'data' => $xml->asXML(),
            'format' => 'xml',
            'filename' => $report['type'] . '_' . date('Y-m-d_His') . '.xml'
        ];
    }
    
    /**
     * Расчёт статистики по потреблению
     */
    private function calculateConsumptionSummary($data) {
        $summary = [
            'total_consumption' => 0,
            'avg_consumption' => 0,
            'peak_consumption' => 0,
            'min_consumption' => PHP_FLOAT_MAX,
            'total_readings' => 0,
            'zones_covered' => [],
            'resources_covered' => []
        ];
        
        foreach ($data as $row) {
            $summary['total_consumption'] += $row['aggregated_value'];
            $summary['total_readings'] += $row['reading_count'];
            
            if ($row['aggregated_value'] > $summary['peak_consumption']) {
                $summary['peak_consumption'] = $row['aggregated_value'];
            }
            
            if ($row['aggregated_value'] < $summary['min_consumption']) {
                $summary['min_consumption'] = $row['aggregated_value'];
            }
            
            $summary['zones_covered'][] = $row['zone_name'];
            $summary['resources_covered'][] = $row['resource_type'];
        }
        
        $summary['zones_covered'] = array_unique($summary['zones_covered']);
        $summary['resources_covered'] = array_unique($summary['resources_covered']);
        
        if ($summary['total_readings'] > 0) {
            $summary['avg_consumption'] = $summary['total_consumption'] / count($data);
        }
        
        $summary['min_consumption'] = $summary['min_consumption'] === PHP_FLOAT_MAX ? 0 : $summary['min_consumption'];
        
        return $summary;
    }
    
    /**
     * Расчёт статистики по авариям
     */
    private function calculateAlertStatistics($data) {
        $stats = [
            'total_alerts' => count($data),
            'critical_alerts' => 0,
            'emergency_alerts' => 0,
            'warning_alerts' => 0,
            'active_alerts' => 0,
            'resolved_alerts' => 0,
            'false_positive_alerts' => 0,
            'zones_affected' => [],
            'average_resolution_time' => 0
        ];
        
        foreach ($data as $alert) {
            switch ($alert['alert_level']) {
                case 'critical':
                    $stats['critical_alerts']++;
                    break;
                case 'emergency':
                    $stats['emergency_alerts']++;
                    break;
                case 'warning':
                    $stats['warning_alerts']++;
                    break;
            }
            
            switch ($alert['status']) {
                case 'active':
                    $stats['active_alerts']++;
                    break;
                case 'resolved':
                    $stats['resolved_alerts']++;
                    break;
                case 'false_positive':
                    $stats['false_positive_alerts']++;
                    break;
            }
            
            if ($alert['zone_name']) {
                $stats['zones_affected'][] = $alert['zone_name'];
            }
        }
        
        $stats['zones_affected'] = array_unique($stats['zones_affected']);
        
        return $stats;
    }
    
    /**
     * Расчёт статистики по эффективности
     */
    private function calculateEfficiencySummary($data) {
        $summary = [
            'total_zones' => count($data),
            'total_sensors' => 0,
            'total_online_sensors' => 0,
            'total_alerts' => 0,
            'average_availability' => 0,
            'average_resolution_rate' => 0
        ];
        
        foreach ($data as $zone) {
            $summary['total_sensors'] += $zone['total_sensors'];
            $summary['total_online_sensors'] += $zone['online_sensors'];
            $summary['total_alerts'] += $zone['total_alerts'];
        }
        
        if ($summary['total_sensors'] > 0) {
            $summary['average_availability'] = ($summary['total_online_sensors'] / $summary['total_sensors']) * 100;
        }
        
        if ($summary['total_alerts'] > 0) {
            $summary['average_resolution_rate'] = array_sum(array_column($data, 'resolution_rate_percent')) / count($data);
        }
        
        return $summary;
    }
    
    /**
     * Получение истории сгенерированных отчётов
     */
    public function getGeneratedReports($limit = 50, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT * FROM report_history 
            ORDER BY generated_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return [
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'count' => $this->getReportsCount()
        ];
    }
    
    /**
     * Получение количества отчётов
     */
    private function getReportsCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM report_history");
        return $stmt->fetchColumn();
    }
    
    /**
     * Сохранение информации о сгенерированном отчёте
     */
    private function saveReportHistory($reportType, $params, $fileName, $userId) {
        $stmt = $this->db->prepare("
            INSERT INTO report_history 
            (report_type, parameters, file_name, generated_by, generated_at)
            VALUES (:type, :params, :file, :user, NOW())
        ");
        
        return $stmt->execute([
            'type' => $reportType,
            'params' => json_encode($params),
            'file' => $fileName,
            'user' => $userId
        ]);
    }
    
    /**
     * Получение шаблонов отчётов
     */
    public function getReportTemplates() {
        return [
            'consumption' => [
                'name' => 'Потребление ресурсов',
                'description' => 'Отчёт по потреблению воды, тепла и электричества',
                'parameters' => ['start_date', 'end_date', 'zone_id', 'resource_type', 'group_by', 'aggregation'],
                'formats' => ['json', 'csv', 'excel', 'pdf']
            ],
            'alerts' => [
                'name' => 'Аварийные события',
                'description' => 'Журнал аварийных и предупредительных событий',
                'parameters' => ['start_date', 'end_date', 'zone_id', 'alert_level', 'status'],
                'formats' => ['json', 'csv', 'excel', 'pdf']
            ],
            'efficiency' => [
                'name' => 'Эффективность работы',
                'description' => 'Отчёт по эффективности зон и систем',
                'parameters' => ['start_date', 'end_date', 'zone_id'],
                'formats' => ['json', 'csv', 'excel', 'pdf']
            ],
            'custom' => [
                'name' => 'Пользовательский',
                'description' => 'Произвольный отчёт по SQL-запросу',
                'parameters' => ['query', 'start_date', 'end_date'],
                'formats' => ['json', 'csv', 'excel', 'pdf', 'xml']
            ]
        ];
    }
}
?>