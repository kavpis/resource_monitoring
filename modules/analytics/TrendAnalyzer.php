<?php
/**
 * Анализ трендов потребления
 * Модуль аналитики
 */

require_once __DIR__ . '/../../config/database.php';

class TrendAnalyzer {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Получение трендов потребления
     */
    public function getTrends($params) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $resourceType = $params['resource_type'] ?? 'all';
        $zoneId = $params['zone_id'] ?? null;
        
        try {
            $trends = [];
            
            // Общий тренд по ресурсам
            $trends['overall'] = $this->getOverallTrend($startDate, $endDate, $resourceType, $zoneId);
            
            // Дневные тренды
            $trends['daily'] = $this->getDailyTrend($startDate, $endDate, $resourceType, $zoneId);
            
            // Прогноз на основе линейной регрессии
            $trends['forecast'] = $this->calculateForecast($trends['daily']);
            
            return $trends;
            
        } catch (PDOException $e) {
            error_log("TrendAnalyzer error: " . $e->getMessage());
            return ['error' => 'Ошибка анализа трендов'];
        }
    }
    
    /**
     * Общий тренд
     */
    private function getOverallTrend($startDate, $endDate, $resourceType, $zoneId) {
        $where = "r.timestamp BETWEEN :start AND :end";
        $params = ['start' => $startDate, 'end' => $endDate];
        
        if ($resourceType !== 'all') {
            $where .= " AND s.resource_type = :resource";
            $params['resource'] = $resourceType;
        }
        
        if ($zoneId) {
            $where .= " AND s.zone_id = :zone";
            $params['zone'] = $zoneId;
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                s.resource_type,
                SUM(r.value) as total,
                AVG(r.value) as average,
                MIN(r.value) as min,
                MAX(r.value) as max,
                COUNT(r.reading_id) as readings_count
            FROM readings r
            JOIN sensors s ON r.sensor_id = s.sensor_id
            WHERE {$where}
            GROUP BY s.resource_type
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Дневной тренд
     */
    private function getDailyTrend($startDate, $endDate, $resourceType, $zoneId) {
        $where = "r.timestamp BETWEEN :start AND :end";
        $params = ['start' => $startDate, 'end' => $endDate];
        
        if ($resourceType !== 'all') {
            $where .= " AND s.resource_type = :resource";
            $params['resource'] = $resourceType;
        }
        
        if ($zoneId) {
            $where .= " AND s.zone_id = :zone";
            $params['zone'] = $zoneId;
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                DATE(r.timestamp) as date,
                s.resource_type,
                SUM(r.value) as total,
                AVG(r.value) as average
            FROM readings r
            JOIN sensors s ON r.sensor_id = s.sensor_id
            WHERE {$where}
            GROUP BY DATE(r.timestamp), s.resource_type
            ORDER BY date ASC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Простой прогноз на основе тренда
     */
    private function calculateForecast($dailyData) {
        if (empty($dailyData)) {
            return [];
        }
        
        // Группировка по датам
        $grouped = [];
        foreach ($dailyData as $row) {
            $date = $row['date'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = 0;
            }
            $grouped[$date] += floatval($row['total']);
        }
        
        $values = array_values($grouped);
        $n = count($values);
        
        if ($n < 2) {
            return ['trend' => 'stable', 'change_percent' => 0];
        }
        
        // Простой расчет изменения
        $firstHalf = array_sum(array_slice($values, 0, floor($n / 2))) / floor($n / 2);
        $secondHalf = array_sum(array_slice($values, floor($n / 2))) / ceil($n / 2);
        
        $changePercent = (($secondHalf - $firstHalf) / $firstHalf) * 100;
        
        $trend = 'stable';
        if ($changePercent > 5) {
            $trend = 'increasing';
        } elseif ($changePercent < -5) {
            $trend = 'decreasing';
        }
        
        return [
            'trend' => $trend,
            'change_percent' => round($changePercent, 2),
            'average_daily' => round(array_sum($values) / $n, 2)
        ];
    }
}
?>
