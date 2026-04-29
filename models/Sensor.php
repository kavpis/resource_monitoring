<?php
/**
 * Модель счётчиков ресурсов
 * Раздел 7.1 — Структура базы данных
 */

require_once __DIR__ . '/../config/database.php';

class Sensor {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Получение всех счётчиков
    public function getAll($filters = []) {
        $sql = "SELECT s.*, z.zone_name 
                FROM sensors s
                LEFT JOIN zones z ON s.zone_id = z.zone_id
                WHERE 1=1";
        
        $params = [];
        
        // Фильтр по типу ресурса
        if (!empty($filters['resource_type'])) {
            $sql .= " AND s.resource_type = :resource_type";
            $params['resource_type'] = $filters['resource_type'];
        }
        
        // Фильтр по зоне
        if (!empty($filters['zone_id'])) {
            $sql .= " AND s.zone_id = :zone_id";
            $params['zone_id'] = $filters['zone_id'];
        }
        
        // Фильтр по статусу
        if (isset($filters['status'])) {
            $sql .= " AND s.status = :status";
            $params['status'] = $filters['status'];
        }
        
        $sql .= " ORDER BY s.sensor_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    // Получение счётчика по ID
    public function getById($sensorId) {
        $stmt = $this->db->prepare("
            SELECT s.*, z.zone_name, z.zone_type
            FROM sensors s
            LEFT JOIN zones z ON s.zone_id = z.zone_id
            WHERE s.sensor_id = :sensor_id
        ");
        $stmt->execute(['sensor_id' => $sensorId]);
        return $stmt->fetch();
    }
    
    // Получение счётчиков по зоне
    public function getByZone($zoneId) {
        $stmt = $this->db->prepare("
            SELECT * FROM sensors 
            WHERE zone_id = :zone_id 
            ORDER BY resource_type, sensor_name
        ");
        $stmt->execute(['zone_id' => $zoneId]);
        return $stmt->fetchAll();
    }
    
    // Получение статистики по всем счётчикам
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                COUNT(DISTINCT resource_type) as resource_types
            FROM sensors
        ");
        return $stmt->fetch();
    }
    
    // Добавление нового счётчика
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO sensors 
            (sensor_name, resource_type, zone_id, ip_address, modbus_port, 
             modbus_address, register_address, register_count, unit_of_measurement,
             status, installation_date, description)
            VALUES 
            (:sensor_name, :resource_type, :zone_id, :ip_address, :modbus_port,
             :modbus_address, :register_address, :register_count, :unit_of_measurement,
             :status, :installation_date, :description)
        ");
        
        return $stmt->execute([
            'sensor_name' => $data['sensor_name'],
            'resource_type' => $data['resource_type'],
            'zone_id' => $data['zone_id'],
            'ip_address' => $data['ip_address'],
            'modbus_port' => $data['modbus_port'] ?? 502,
            'modbus_address' => $data['modbus_address'],
            'register_address' => $data['register_address'],
            'register_count' => $data['register_count'] ?? 2,
            'unit_of_measurement' => $data['unit_of_measurement'],
            'status' => $data['status'] ?? 'offline',
            'installation_date' => $data['installation_date'] ?? date('Y-m-d'),
            'description' => $data['description'] ?? null
        ]);
    }
    
    // Обновление счётчика
    public function update($sensorId, $data) {
        $fields = [];
        $params = ['sensor_id' => $sensorId];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[$key] = $value;
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE sensors SET " . implode(', ', $fields) . " WHERE sensor_id = :sensor_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    // Удаление счётчика
    public function delete($sensorId) {
        $stmt = $this->db->prepare("DELETE FROM sensors WHERE sensor_id = :sensor_id");
        return $stmt->execute(['sensor_id' => $sensorId]);
    }
    
    // Обновление статуса счётчика
    public function updateStatus($sensorId, $status) {
        $stmt = $this->db->prepare("
            UPDATE sensors 
            SET status = :status, last_communication = NOW() 
            WHERE sensor_id = :sensor_id
        ");
        return $stmt->execute([
            'sensor_id' => $sensorId,
            'status' => $status
        ]);
    }
    
    // Проверка доступности счётчика (Modbus)
    public function checkAvailability($sensorId) {
        $sensor = $this->getById($sensorId);
        if (!$sensor) return false;
        
        require_once __DIR__ . '/../core/ModbusClient.php';
        
        $modbus = new ModbusClient($sensor['ip_address'], $sensor['modbus_port']);
        
        if ($modbus->isAvailable()) {
            $this->updateStatus($sensorId, 'online');
            return true;
        } else {
            $this->updateStatus($sensorId, 'offline');
            return false;
        }
    }
    
    // Получение текущих показаний счётчика
    public function getCurrentReading($sensorId) {
        $stmt = $this->db->prepare("
            SELECT * FROM readings 
            WHERE sensor_id = :sensor_id 
            AND is_valid = 1
            ORDER BY reading_timestamp DESC 
            LIMIT 1
        ");
        $stmt->execute(['sensor_id' => $sensorId]);
        return $stmt->fetch();
    }
    
    // Получение показаний за период
    public function getReadingsByPeriod($sensorId, $start, $end) {
        $stmt = $this->db->prepare("
            SELECT * FROM readings 
            WHERE sensor_id = :sensor_id 
            AND reading_timestamp BETWEEN :start AND :end
            AND is_valid = 1
            ORDER BY reading_timestamp ASC
        ");
        $stmt->execute([
            'sensor_id' => $sensorId,
            'start' => $start,
            'end' => $end
        ]);
        return $stmt->fetchAll();
    }
    
    // Получение всех счётчиков с последними показаниями
    public function getAllWithLastReadings() {
        $stmt = $this->db->query("
            SELECT 
                s.*,
                z.zone_name,
                r.reading_value,
                r.reading_timestamp,
                r.unit_of_measurement as reading_unit
            FROM sensors s
            LEFT JOIN zones z ON s.zone_id = z.zone_id
            LEFT JOIN readings r ON s.sensor_id = r.sensor_id 
                AND r.reading_id = (
                    SELECT MAX(reading_id) 
                    FROM readings 
                    WHERE sensor_id = s.sensor_id AND is_valid = 1
                )
            ORDER BY s.zone_id, s.resource_type, s.sensor_name
        ");
        return $stmt->fetchAll();
    }
}
?>