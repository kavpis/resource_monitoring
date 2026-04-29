<?php
/**
 * Модель зон объекта
 * Раздел 7.1 — Структура объекта: иерархия цехов, участков, линий
 */

require_once __DIR__ . '/../config/database.php';

class Zone {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Получение всех зон
    public function getAll() {
        $stmt = $this->db->query("
            SELECT z1.*, z2.zone_name as parent_name
            FROM zones z1
            LEFT JOIN zones z2 ON z1.parent_zone_id = z2.zone_id
            ORDER BY z1.zone_type, z1.zone_name
        ");
        return $stmt->fetchAll();
    }
    
    // Получение зоны по ID
    public function getById($zoneId) {
        $stmt = $this->db->prepare("
            SELECT z1.*, z2.zone_name as parent_name
            FROM zones z1
            LEFT JOIN zones z2 ON z1.parent_zone_id = z2.zone_id
            WHERE z1.zone_id = :zone_id
        ");
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetch();
    }
    
    // Получение дочерних зон
    public function getChildren($parentId) {
        $stmt = $this->db->prepare("
            SELECT * FROM zones 
            WHERE parent_zone_id = :parent_id
            ORDER BY zone_name
        ");
        $stmt->execute(['parent_id' => $parentId]);
        return $stmt->fetchAll();
    }
    
    // Получение всех зон с иерархией
    public function getWithHierarchy() {
        $stmt = $this->db->query("
            SELECT 
                z1.zone_id,
                z1.zone_name,
                z1.zone_type,
                z1.parent_zone_id,
                z1.description,
                z2.zone_name as parent_name,
                COUNT(DISTINCT s.sensor_id) as sensor_count,
                COUNT(DISTINCT a.alert_id) as active_alerts
            FROM zones z1
            LEFT JOIN zones z2 ON z1.parent_zone_id = z2.zone_id
            LEFT JOIN sensors s ON z1.zone_id = s.zone_id
            LEFT JOIN alerts a ON z1.zone_id = a.zone_id AND a.status IN ('active', 'in_progress')
            GROUP BY z1.zone_id
            ORDER BY z1.zone_type, z1.zone_name
        ");
        return $stmt->fetchAll();
    }
    
    // Получение зон по типу
    public function getByType($zoneType) {
        $stmt = $this->db->prepare("
            SELECT * FROM zones 
            WHERE zone_type = :zone_type
            ORDER BY zone_name
        ");
        $stmt->execute(['zone_type' => $zoneType]);
        return $stmt->fetchAll();
    }
    
    // Добавление новой зоны
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO zones 
            (zone_name, zone_type, parent_zone_id, description)
            VALUES 
            (:zone_name, :zone_type, :parent_zone_id, :description)
        ");
        
        $result = $stmt->execute([
            'zone_name' => $data['zone_name'],
            'zone_type' => $data['zone_type'],
            'parent_zone_id' => $data['parent_zone_id'] ?? null,
            'description' => $data['description'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    // Обновление зоны
    public function update($zoneId, $data) {
        $fields = [];
        $params = ['zone_id' => $zoneId];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[$key] = $value;
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE zones SET " . implode(', ', $fields) . " WHERE zone_id = :zone_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    // Удаление зоны
    public function delete($zoneId) {
        $stmt = $this->db->prepare("DELETE FROM zones WHERE zone_id = :zone_id");
        return $stmt->execute(['zone_id' => $zoneId]);
    }
    
    // Получение статистики по зоне
    public function getStatistics($zoneId) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT s.sensor_id) as total_sensors,
                SUM(CASE WHEN s.status = 'online' THEN 1 ELSE 0 END) as online_sensors,
                SUM(CASE WHEN s.status = 'offline' THEN 1 ELSE 0 END) as offline_sensors,
                SUM(CASE WHEN s.status = 'error' THEN 1 ELSE 0 END) as error_sensors,
                COUNT(DISTINCT a.alert_id) as total_alerts,
                SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END) as active_alerts,
                SUM(CASE WHEN a.alert_level = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
                SUM(CASE WHEN a.alert_level = 'emergency' THEN 1 ELSE 0 END) as emergency_alerts,
                SUM(CASE WHEN a.alert_level = 'warning' THEN 1 ELSE 0 END) as warning_alerts
            FROM zones z
            LEFT JOIN sensors s ON z.zone_id = s.zone_id
            LEFT JOIN alerts a ON z.zone_id = a.zone_id AND a.status IN ('active', 'in_progress')
            WHERE z.zone_id = :zone_id
        ");
        
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetch();
    }
    
    // Получение потребления ресурсов по зоне за период
    public function getResourceConsumption($zoneId, $start, $end) {
        $stmt = $this->db->prepare("
            SELECT 
                s.resource_type,
                SUM(r.reading_value) as total_consumption,
                AVG(r.reading_value) as avg_consumption,
                MAX(r.reading_value) as max_consumption,
                MIN(r.reading_value) as min_consumption,
                s.unit_of_measurement
            FROM readings r
            INNER JOIN sensors s ON r.sensor_id = s.sensor_id
            WHERE s.zone_id = :zone_id
            AND r.reading_timestamp BETWEEN :start AND :end
            AND r.is_valid = 1
            GROUP BY s.resource_type, s.unit_of_measurement
        ");
        
        $stmt->execute([
            'zone_id' => $zoneId,
            'start' => $start,
            'end' => $end
        ]);
        
        return $stmt->fetchAll();
    }
    
    // Получение дерева зон (рекурсивно)
    public function getTree($parentId = null) {
        $zones = $parentId === null 
            ? $this->getByType('factory')
            : $this->getChildren($parentId);
        
        $result = [];
        
        foreach ($zones as $zone) {
            $children = $this->getChildren($zone['zone_id']);
            
            $result[] = [
                'zone_id' => $zone['zone_id'],
                'zone_name' => $zone['zone_name'],
                'zone_type' => $zone['zone_type'],
                'parent_zone_id' => $zone['parent_zone_id'],
                'description' => $zone['description'],
                'children' => !empty($children) ? $children : null
            ];
        }
        
        return $result;
    }
    
    // Проверка существования зоны
    public function exists($zoneId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM zones WHERE zone_id = :zone_id");
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetchColumn() > 0;
    }
}
?>