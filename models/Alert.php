<?php
/**
 * Модель событий и аварий
 * Раздел 5 — Алгоритмы обработки сигналов
 */

require_once __DIR__ . '/../config/database.php';

class Alert {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Создание нового события
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO alerts 
            (sensor_id, zone_id, alert_type, alert_level, description, 
             current_value, threshold_value, status, alert_timestamp)
            VALUES 
            (:sensor_id, :zone_id, :alert_type, :alert_level, :description,
             :current_value, :threshold_value, :status, :timestamp)
        ");
        
        $result = $stmt->execute([
            'sensor_id' => $data['sensor_id'] ?? null,
            'zone_id' => $data['zone_id'] ?? null,
            'alert_type' => $data['alert_type'],
            'alert_level' => $data['alert_level'],
            'description' => $data['description'],
            'current_value' => $data['current_value'] ?? null,
            'threshold_value' => $data['threshold_value'] ?? null,
            'status' => $data['status'] ?? 'active',
            'timestamp' => $data['timestamp'] ?? date('Y-m-d H:i:s')
        ]);
        
        return $this->db->lastInsertId();
    }
    
    // Получение всех активных событий
    public function getActive($filters = []) {
        $sql = "SELECT a.*, s.sensor_name, z.zone_name, u.full_name as resolved_by_name
                FROM alerts a
                LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
                LEFT JOIN zones z ON a.zone_id = z.zone_id
                LEFT JOIN users u ON a.resolved_by = u.user_id
                WHERE a.status IN ('active', 'in_progress')";
        
        $params = [];
        
        // Фильтр по уровню
        if (!empty($filters['alert_level'])) {
            $sql .= " AND a.alert_level = :alert_level";
            $params['alert_level'] = $filters['alert_level'];
        }
        
        // Фильтр по зоне
        if (!empty($filters['zone_id'])) {
            $sql .= " AND a.zone_id = :zone_id";
            $params['zone_id'] = $filters['zone_id'];
        }
        
        // Фильтр по типу
        if (!empty($filters['alert_type'])) {
            $sql .= " AND a.alert_type = :alert_type";
            $params['alert_type'] = $filters['alert_type'];
        }
        
        $sql .= " ORDER BY 
            CASE a.alert_level
                WHEN 'critical' THEN 1
                WHEN 'emergency' THEN 2
                WHEN 'warning' THEN 3
            END,
            a.alert_timestamp DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    // Получение событий за период
    public function getByPeriod($start, $end, $filters = []) {
        $sql = "SELECT a.*, s.sensor_name, z.zone_name, u.full_name as resolved_by_name
                FROM alerts a
                LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
                LEFT JOIN zones z ON a.zone_id = z.zone_id
                LEFT JOIN users u ON a.resolved_by = u.user_id
                WHERE a.alert_timestamp BETWEEN :start AND :end";
        
        $params = [
            'start' => $start,
            'end' => $end
        ];
        
        // Фильтры
        if (!empty($filters['alert_level'])) {
            $sql .= " AND a.alert_level = :alert_level";
            $params['alert_level'] = $filters['alert_level'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND a.status = :status";
            $params['status'] = $filters['status'];
        }
        
        $sql .= " ORDER BY a.alert_timestamp DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    // Получение события по ID
    public function getById($alertId) {
        $stmt = $this->db->prepare("
            SELECT a.*, s.sensor_name, z.zone_name, u.full_name as resolved_by_name
            FROM alerts a
            LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
            LEFT JOIN zones z ON a.zone_id = z.zone_id
            LEFT JOIN users u ON a.resolved_by = u.user_id
            WHERE a.alert_id = :alert_id
        ");
        $stmt->execute(['alert_id' => $alertId]);
        return $stmt->fetch();
    }
    
    // Обновление статуса события
    public function updateStatus($alertId, $status, $userId = null) {
        if ($status === 'resolved') {
            $stmt = $this->db->prepare("
                UPDATE alerts 
                SET status = :status, resolved_by = :user_id, resolved_at = NOW()
                WHERE alert_id = :alert_id
            ");
            return $stmt->execute([
                'alert_id' => $alertId,
                'status' => $status,
                'user_id' => $userId
            ]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE alerts 
                SET status = :status
                WHERE alert_id = :alert_id
            ");
            return $stmt->execute([
                'alert_id' => $alertId,
                'status' => $status
            ]);
        }
    }
    
    // Удаление события
    public function delete($alertId) {
        $stmt = $this->db->prepare("DELETE FROM alerts WHERE alert_id = :alert_id");
        return $stmt->execute(['alert_id' => $alertId]);
    }
    
    // Получение статистики событий
    public function getStatistics($start = null, $end = null) {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warnings,
                    SUM(CASE WHEN alert_level = 'emergency' THEN 1 ELSE 0 END) as emergencies,
                    SUM(CASE WHEN alert_level = 'critical' THEN 1 ELSE 0 END) as criticals,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN status = 'false_positive' THEN 1 ELSE 0 END) as false_positives
                FROM alerts";
        
        $params = [];
        
        if ($start && $end) {
            $sql .= " WHERE alert_timestamp BETWEEN :start AND :end";
            $params['start'] = $start;
            $params['end'] = $end;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    // Получение событий по зоне
    public function getByZone($zoneId) {
        $stmt = $this->db->prepare("
            SELECT a.*, s.sensor_name, z.zone_name
            FROM alerts a
            LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
            LEFT JOIN zones z ON a.zone_id = z.zone_id
            WHERE a.zone_id = :zone_id
            ORDER BY a.alert_timestamp DESC
        ");
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetchAll();
    }
    
    // Создание события о потере связи
    public function createConnectionLost($sensorId, $zoneId) {
        return $this->create([
            'sensor_id' => $sensorId,
            'zone_id' => $zoneId,
            'alert_type' => 'connection_lost',
            'alert_level' => 'warning',
            'description' => 'Нет связи с счётчиком более 10 минут',
            'status' => 'active'
        ]);
    }
    
    // Создание события об утечке воды
    public function createWaterLeak($sensorId, $zoneId, $currentValue, $threshold) {
        return $this->create([
            'sensor_id' => $sensorId,
            'zone_id' => $zoneId,
            'alert_type' => 'water_leak',
            'alert_level' => 'emergency',
            'description' => "Вероятна утечка воды: расход вырос до {$currentValue} м³/ч (порог: {$threshold} м³/ч)",
            'current_value' => $currentValue,
            'threshold_value' => $threshold,
            'status' => 'active'
        ]);
    }
    
    // Создание события о перегреве
    public function createOverheat($sensorId, $zoneId, $currentValue, $threshold) {
        return $this->create([
            'sensor_id' => $sensorId,
            'zone_id' => $zoneId,
            'alert_type' => 'heat_overheat',
            'alert_level' => 'critical',
            'description' => "Перегрев теплоносителя: температура {$currentValue}°C (порог: {$threshold}°C) - риск разрыва трубы",
            'current_value' => $currentValue,
            'threshold_value' => $threshold,
            'status' => 'active'
        ]);
    }
    
    // Создание события о перегрузке
    public function createPowerOverload($sensorId, $zoneId, $currentValue, $threshold) {
        return $this->create([
            'sensor_id' => $sensorId,
            'zone_id' => $zoneId,
            'alert_type' => 'power_overload',
            'alert_level' => 'emergency',
            'description' => "Перегрузка по мощности: {$currentValue} кВт (порог: {$threshold} кВт)",
            'current_value' => $currentValue,
            'threshold_value' => $threshold,
            'status' => 'active'
        ]);
    }
    
    // Получение последних событий (для виджета на дашборде)
    public function getRecent($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT a.*, s.sensor_name, z.zone_name
            FROM alerts a
            LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
            LEFT JOIN zones z ON a.zone_id = z.zone_id
            ORDER BY a.alert_timestamp DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
?>