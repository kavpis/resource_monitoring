<?php
/**
 * Анализ эффективности потребления
 * Модуль аналитики
 */

require_once __DIR__ . '/../../config/database.php';

class EfficiencyAnalyzer {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Получение данных по эффективности
     */
    public function getEfficiencyData($params) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $zoneId = $params['zone_id'] ?? null;
        
        try {
            // Эффективность по зонам
            $zoneEfficiency = $this->getZoneEfficiency($startDate, $endDate, $zoneId);
            
            // Аномалии и простои
            $anomalies = $this->getAnomalyStats($startDate, $endDate, $zoneId);
            
            // Рекомендации
            $recommendations = $this->generateRecommendations($zoneEfficiency, $anomalies);
            
            return [
                'zone_efficiency' => $zoneEfficiency,
                'anomalies' => $anomalies,
                'recommendations' => $recommendations,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ];
            
        } catch (PDOException $e) {
            error_log("EfficiencyAnalyzer error: " . $e->getMessage());
            return ['error' => 'Ошибка анализа эффективности'];
        }
    }
    
    /**
     * Эффективность по зонам
     */
    private function getZoneEfficiency($startDate, $endDate, $zoneId) {
        $where = "r.timestamp BETWEEN :start AND :end";
        $params = ['start' => $startDate, 'end' => $endDate];
        
        if ($zoneId) {
            $where .= " AND s.zone_id = :zone";
            $params['zone'] = $zoneId;
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                z.zone_name,
                z.zone_id,
                s.resource_type,
                SUM(r.value) as total_consumption,
                AVG(r.value) as avg_consumption,
                COUNT(DISTINCT s.sensor_id) as sensors_count
            FROM readings r
            JOIN sensors s ON r.sensor_id = s.sensor_id
            JOIN zones z ON s.zone_id = z.zone_id
            WHERE {$where}
            GROUP BY z.zone_id, z.zone_name, s.resource_type
            ORDER BY total_consumption DESC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Статистика аномалий
     */
    private function getAnomalyStats($startDate, $endDate, $zoneId) {
        $where = "a.created_at BETWEEN :start AND :end";
        $params = ['start' => $startDate, 'end' => $endDate];
        
        if ($zoneId) {
            $where .= " AND s.zone_id = :zone";
            $params['zone'] = $zoneId;
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                a.alert_level,
                COUNT(a.alert_id) as count,
                s.resource_type
            FROM alerts a
            JOIN sensors s ON a.sensor_id = s.sensor_id
            WHERE {$where}
            GROUP BY a.alert_level, s.resource_type
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Генерация рекомендаций
     */
    private function generateRecommendations($zoneEfficiency, $anomalies) {
        $recommendations = [];
        
        // Анализ зон с высоким потреблением
        $highConsumption = array_filter($zoneEfficiency, function($z) {
            return floatval($z['total_consumption']) > 1000;
        });
        
        if (!empty($highConsumption)) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Обнаружены зоны с высоким потреблением ресурсов. Рекомендуется провести аудит оборудования.',
                'zones' => array_column($highConsumption, 'zone_name')
            ];
        }
        
        // Анализ критических аномалий
        $criticalAnomalies = array_filter($anomalies, function($a) {
            return $a['alert_level'] === 'critical';
        });
        
        if (!empty($criticalAnomalies)) {
            $recommendations[] = [
                'type' => 'critical',
                'message' => 'Зафиксированы критические аномалии. Требуется немедленная проверка оборудования.',
                'count' => array_sum(array_column($criticalAnomalies, 'count'))
            ];
        }
        
        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Потребление ресурсов в пределах нормы. Критических отклонений не выявлено.'
            ];
        }
        
        return $recommendations;
    }
}
?>
