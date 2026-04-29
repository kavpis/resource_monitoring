<?php
/**
 * Сервис данных дашборда
 * Модуль аналитики
 */

require_once __DIR__ . '/../../config/database.php';

class DashboardService {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Получение данных для дашборда
     */
    public function getDashboardData($params) {
        $userId = $params['user_id'] ?? null;
        $role = $params['role'] ?? 'viewer';
        
        try {
            // Потребление по типам ресурсов за последние 24 часа
            $consumption = $this->getConsumptionByType();
            
            // Активные аварии
            $activeAlerts = $this->getActiveAlerts();
            
            // Данные для графика потребления по часам
            $hourlyData = $this->getHourlyConsumption();
            
            // Статистика по зонам
            $zoneStats = $this->getZoneStatistics();
            
            return [
                'consumption' => $consumption,
                'active_alerts' => $activeAlerts,
                'hourly_data' => $hourlyData,
                'zone_stats' => $zoneStats,
                'last_update' => date('Y-m-d H:i:s')
            ];
            
        } catch (PDOException $e) {
            error_log("DashboardService error: " . $e->getMessage());
            return [
                'consumption' => [],
                'active_alerts' => [],
                'hourly_data' => [],
                'zone_stats' => [],
                'error' => 'Ошибка загрузки данных'
            ];
        }
    }
    
    /**
     * Потребление по типам ресурсов
     */
    private function getConsumptionByType() {
        $stmt = $this->db->query("
            SELECT 
                s.resource_type,
                SUM(r.value) as total_value,
                COUNT(DISTINCT r.sensor_id) as sensor_count
            FROM readings r
            JOIN sensors s ON r.sensor_id = s.sensor_id
            WHERE r.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY s.resource_type
        ");
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = ['water' => 0, 'heat' => 0, 'electricity' => 0];
        foreach ($result as $row) {
            $data[$row['resource_type']] = floatval($row['total_value']);
        }
        
        return $data;
    }
    
    /**
     * Активные аварии
     */
    private function getActiveAlerts() {
        $stmt = $this->db->query("
            SELECT 
                a.alert_id,
                a.description,
                a.alert_level,
                a.created_at,
                s.sensor_name,
                s.resource_type,
                z.zone_name
            FROM alerts a
            JOIN sensors s ON a.sensor_id = s.sensor_id
            LEFT JOIN zones z ON s.zone_id = z.zone_id
            WHERE a.status = 'active'
            ORDER BY a.created_at DESC
            LIMIT 10
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Почасовое потребление
     */
    private function getHourlyConsumption() {
        $stmt = $this->db->query("
            SELECT 
                s.resource_type,
                DATE_FORMAT(r.timestamp, '%Y-%m-%d %H:00') as hour,
                AVG(r.value) as avg_value
            FROM readings r
            JOIN sensors s ON r.sensor_id = s.sensor_id
            WHERE r.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY s.resource_type, DATE_FORMAT(r.timestamp, '%Y-%m-%d %H:00')
            ORDER BY hour ASC
        ");
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [
            'labels' => [],
            'water' => [],
            'heat' => [],
            'electricity' => []
        ];
        
        foreach ($result as $row) {
            if (!in_array($row['hour'], $data['labels'])) {
                $data['labels'][] = $row['hour'];
            }
            $data[$row['resource_type']][] = floatval($row['avg_value']);
        }
        
        return $data;
    }
    
    /**
     * Статистика по зонам
     */
    private function getZoneStatistics() {
        $stmt = $this->db->query("
            SELECT 
                z.zone_name,
                COUNT(DISTINCT s.sensor_id) as sensor_count,
                SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END) as active_alerts
            FROM zones z
            LEFT JOIN sensors s ON z.zone_id = s.zone_id
            LEFT JOIN alerts a ON s.sensor_id = a.sensor_id AND a.status = 'active'
            GROUP BY z.zone_id, z.zone_name
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
