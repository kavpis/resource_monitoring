<?php
/**
 * Класс для генерации данных графиков и визуализаций
 * 
 * @package Visualizations
 */

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once __DIR__ . '/VisualizationLogger.php';
require_once __DIR__ . '/VisualizationCache.php';

class ChartDataGenerator {
    private $db;
    private $logger;
    private $cache;
    
    /**
     * Конструктор
     */
    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
        $this->logger = VisualizationLogger::getInstance();
        $this->cache = VisualizationCache::getInstance();
        
        $this->logger->info('Генератор данных графиков инициализирован', ['component' => 'ChartDataGenerator']);
    }
    
    /**
     * Получить данные потребления по ресурсам за период
     * 
     * @param string $resourceType Тип ресурса (water, heat, electricity)
     * @param int $zoneId ID зоны (опционально)
     * @param string $startDate Дата начала
     * @param string $endDate Дата окончания
     * @param string $grouping Группировка (hour, day, month)
     * @return array Данные для графика
     */
    public function getConsumptionData($resourceType, $zoneId = null, $startDate, $endDate, $grouping = 'day') {
        $cacheKey = "consumption_{$resourceType}_{$zoneId}_{$startDate}_{$endDate}_{$grouping}";
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            $this->logger->debug('Данные потребления получены из кэша', [
                'component' => 'ChartDataGenerator',
                'resource' => $resourceType
            ]);
            return $cachedData;
        }
        
        try {
            $sql = "SELECT 
                        DATE_FORMAT(r.timestamp, :format) as period,
                        SUM(r.value) as total_value,
                        AVG(r.value) as avg_value,
                        MIN(r.value) as min_value,
                        MAX(r.value) as max_value,
                        COUNT(r.id) as readings_count
                    FROM readings r
                    JOIN sensors s ON r.sensor_id = s.id
                    WHERE s.resource_type = :resource_type
                        AND r.timestamp BETWEEN :start_date AND :end_date";
            
            $params = [
                ':resource_type' => $resourceType,
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ];
            
            switch ($grouping) {
                case 'hour':
                    $format = '%Y-%m-%d %H:00';
                    break;
                case 'month':
                    $format = '%Y-%m-01';
                    break;
                default:
                    $format = '%Y-%m-%d';
            }
            
            $params[':format'] = $format;
            
            if ($zoneId !== null) {
                $sql .= " AND s.zone_id = :zone_id";
                $params[':zone_id'] = $zoneId;
            }
            
            $sql .= " GROUP BY period ORDER BY period ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data = [
                'labels' => [],
                'values' => [],
                'averages' => [],
                'min_values' => [],
                'max_values' => [],
                'counts' => []
            ];
            
            foreach ($results as $row) {
                $data['labels'][] = $row['period'];
                $data['values'][] = (float)$row['total_value'];
                $data['averages'][] = (float)$row['avg_value'];
                $data['min_values'][] = (float)$row['min_value'];
                $data['max_values'][] = (float)$row['max_value'];
                $data['counts'][] = (int)$row['readings_count'];
            }
            
            $this->cache->set($cacheKey, $data, 300);
            
            $this->logger->info('Данные потребления сгенерированы', [
                'component' => 'ChartDataGenerator',
                'resource' => $resourceType,
                'records' => count($data['labels'])
            ]);
            
            return $data;
            
        } catch (PDOException $e) {
            $this->logger->error('Ошибка получения данных потребления: ' . $e->getMessage(), [
                'component' => 'ChartDataGenerator',
                'resource' => $resourceType
            ]);
            return ['error' => 'Не удалось получить данные потребления'];
        }
    }
    
    /**
     * Получить данные для сравнения зон
     * 
     * @param string $resourceType Тип ресурса
     * @param string $startDate Дата начала
     * @param string $endDate Дата окончания
     * @return array Данные для сравнения
     */
    public function getZoneComparisonData($resourceType, $startDate, $endDate) {
        $cacheKey = "zone_comparison_{$resourceType}_{$startDate}_{$endDate}";
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        try {
            $sql = "SELECT 
                        z.name as zone_name,
                        z.id as zone_id,
                        SUM(r.value) as total_consumption,
                        AVG(r.value) as avg_consumption,
                        COUNT(DISTINCT s.id) as sensors_count
                    FROM zones z
                    LEFT JOIN sensors s ON s.zone_id = z.id AND s.resource_type = :resource_type
                    LEFT JOIN readings r ON r.sensor_id = s.id 
                        AND r.timestamp BETWEEN :start_date AND :end_date
                    GROUP BY z.id, z.name
                    ORDER BY total_consumption DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':resource_type' => $resourceType,
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ]);
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data = [
                'zones' => [],
                'consumption' => [],
                'averages' => [],
                'sensor_counts' => []
            ];
            
            foreach ($results as $row) {
                $data['zones'][] = $row['zone_name'];
                $data['consumption'][] = (float)$row['total_consumption'];
                $data['averages'][] = (float)$row['avg_consumption'];
                $data['sensor_counts'][] = (int)$row['sensors_count'];
            }
            
            $this->cache->set($cacheKey, $data, 600);
            
            $this->logger->info('Данные сравнения зон сгенерированы', [
                'component' => 'ChartDataGenerator',
                'resource' => $resourceType,
                'zones_count' => count($data['zones'])
            ]);
            
            return $data;
            
        } catch (PDOException $e) {
            $this->logger->error('Ошибка получения данных сравнения зон: ' . $e->getMessage(), [
                'component' => 'ChartDataGenerator'
            ]);
            return ['error' => 'Не удалось получить данные сравнения'];
        }
    }
    
    /**
     * Получить данные аварий по типам
     * 
     * @param string $startDate Дата начала
     * @param string $endDate Дата окончания
     * @param int|null $zoneId ID зоны (опционально)
     * @return array Данные аварий
     */
    public function getAlertsData($startDate, $endDate, $zoneId = null) {
        $cacheKey = "alerts_{$startDate}_{$endDate}_{$zoneId}";
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        try {
            $sql = "SELECT 
                        a.alert_type,
                        COUNT(a.id) as alerts_count,
                        SUM(CASE WHEN a.acknowledged = 1 THEN 1 ELSE 0 END) as acknowledged_count,
                        AVG(TIMESTAMPDIFF(SECOND, a.created_at, a.acknowledged_at)) as avg_response_time
                    FROM alerts a
                    JOIN sensors s ON a.sensor_id = s.id
                    WHERE a.created_at BETWEEN :start_date AND :end_date";
            
            $params = [
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ];
            
            if ($zoneId !== null) {
                $sql .= " AND s.zone_id = :zone_id";
                $params[':zone_id'] = $zoneId;
            }
            
            $sql .= " GROUP BY a.alert_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data = [
                'types' => [],
                'counts' => [],
                'acknowledged' => [],
                'response_times' => []
            ];
            
            foreach ($results as $row) {
                $data['types'][] = $row['alert_type'];
                $data['counts'][] = (int)$row['alerts_count'];
                $data['acknowledged'][] = (int)$row['acknowledged_count'];
                $data['response_times'][] = (float)$row['avg_response_time'];
            }
            
            $this->cache->set($cacheKey, $data, 300);
            
            $this->logger->info('Данные аварий сгенерированы', [
                'component' => 'ChartDataGenerator',
                'alerts_types' => count($data['types'])
            ]);
            
            return $data;
            
        } catch (PDOException $e) {
            $this->logger->error('Ошибка получения данных аварий: ' . $e->getMessage(), [
                'component' => 'ChartDataGenerator'
            ]);
            return ['error' => 'Не удалось получить данные аварий'];
        }
    }
    
    /**
     * Получить данные для карты объекта
     * 
     * @return array Данные карты
     */
    public function getMapData() {
        $cacheKey = "map_data";
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        try {
            // Получаем иерархию зон
            $sql = "SELECT 
                        z.id,
                        z.name,
                        z.parent_id,
                        z.zone_type,
                        COUNT(DISTINCT s.id) as sensors_count,
                        COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_sensors,
                        COUNT(DISTINCT a.id) as active_alerts
                    FROM zones z
                    LEFT JOIN sensors s ON s.zone_id = z.id
                    LEFT JOIN alerts a ON a.sensor_id = s.id AND a.acknowledged = 0
                    GROUP BY z.id
                    ORDER BY z.parent_id, z.name";
            
            $stmt = $this->db->query($sql);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data = [
                'zones' => $zones,
                'resources' => [
                    'water' => $this->getResourceSummary('water'),
                    'heat' => $this->getResourceSummary('heat'),
                    'electricity' => $this->getResourceSummary('electricity')
                ]
            ];
            
            $this->cache->set($cacheKey, $data, 120);
            
            $this->logger->info('Данные карты объекта сгенерированы', [
                'component' => 'ChartDataGenerator',
                'zones_count' => count($zones)
            ]);
            
            return $data;
            
        } catch (PDOException $e) {
            $this->logger->error('Ошибка получения данных карты: ' . $e->getMessage(), [
                'component' => 'ChartDataGenerator'
            ]);
            return ['error' => 'Не удалось получить данные карты'];
        }
    }
    
    /**
     * Получить сводку по ресурсу
     * 
     * @param string $resourceType Тип ресурса
     * @return array Сводные данные
     */
    private function getResourceSummary($resourceType) {
        try {
            $sql = "SELECT 
                        SUM(r.value) as total_consumption,
                        AVG(r.value) as avg_consumption,
                        COUNT(DISTINCT s.id) as sensors_count,
                        COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_sensors
                    FROM sensors s
                    LEFT JOIN readings r ON r.sensor_id = s.id
                    WHERE s.resource_type = :resource_type
                    GROUP BY s.resource_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':resource_type' => $resourceType]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? [
                'total' => (float)$result['total_consumption'],
                'average' => (float)$result['avg_consumption'],
                'sensors_total' => (int)$result['sensors_count'],
                'sensors_active' => (int)$result['active_sensors']
            ] : [
                'total' => 0,
                'average' => 0,
                'sensors_total' => 0,
                'sensors_active' => 0
            ];
            
        } catch (PDOException $e) {
            $this->logger->error('Ошибка получения сводки по ресурсу: ' . $e->getMessage(), [
                'component' => 'ChartDataGenerator',
                'resource' => $resourceType
            ]);
            return ['total' => 0, 'average' => 0, 'sensors_total' => 0, 'sensors_active' => 0];
        }
    }
    
    /**
     * Получить данные в реальном времени для дашборда
     * 
     * @param int|null $zoneId ID зоны (опционально)
     * @return array Данные реального времени
     */
    public function getRealtimeData($zoneId = null) {
        try {
            $sql = "SELECT 
                        s.id as sensor_id,
                        s.name as sensor_name,
                        s.resource_type,
                        s.unit,
                        r.value as current_value,
                        r.timestamp as last_reading,
                        z.name as zone_name,
                        z.id as zone_id
                    FROM sensors s
                    LEFT JOIN readings r ON r.sensor_id = s.id 
                        AND r.timestamp = (
                            SELECT MAX(timestamp) 
                            FROM readings 
                            WHERE sensor_id = s.id
                        )
                    JOIN zones z ON s.zone_id = z.id
                    WHERE s.status = 'active'";
            
            $params = [];
            
            if ($zoneId !== null) {
                $sql .= " AND s.zone_id = :zone_id";
                $params[':zone_id'] = $zoneId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data = [
                'sensors' => [],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            foreach ($results as $row) {
                $data['sensors'][] = [
                    'id' => (int)$row['sensor_id'],
                    'name' => $row['sensor_name'],
                    'resource_type' => $row['resource_type'],
                    'value' => (float)$row['current_value'],
                    'unit' => $row['unit'],
                    'timestamp' => $row['last_reading'],
                    'zone' => [
                        'id' => (int)$row['zone_id'],
                        'name' => $row['zone_name']
                    ]
                ];
            }
            
            $this->logger->debug('Данные реального времени получены', [
                'component' => 'ChartDataGenerator',
                'sensors_count' => count($data['sensors'])
            ]);
            
            return $data;
            
        } catch (PDOException $e) {
            $this->logger->error('Ошибка получения данных реального времени: ' . $e->getMessage(), [
                'component' => 'ChartDataGenerator'
            ]);
            return ['error' => 'Не удалось получить данные реального времени'];
        }
    }
}
