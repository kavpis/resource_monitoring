<?php
/**
 * Модель показаний счётчиков
 * Раздел 7.1 — Архив показаний
 */

require_once __DIR__ . '/../config/database.php';

class Reading {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // Добавление нового показания
    public function add($sensorId, $value, $unit, $timestamp = null, $isValid = true, $error = null) {
        $stmt = $this->db->prepare("
            INSERT INTO readings 
            (sensor_id, reading_value, unit_of_measurement, reading_timestamp, is_valid, validation_error)
            VALUES (:sensor_id, :value, :unit, :timestamp, :is_valid, :error)
        ");
        
        return $stmt->execute([
            'sensor_id' => $sensorId,
            'value' => $value,
            'unit' => $unit,
            'timestamp' => $timestamp ?? date('Y-m-d H:i:s'),
            'is_valid' => $isValid ? 1 : 0,
            'error' => $error
        ]);
    }
    
    // Получение показаний за период
    public function getByPeriod($sensorId, $start, $end) {
        $stmt = $this->db->prepare("
            SELECT * FROM readings 
            WHERE sensor_id = :sensor_id 
            AND reading_timestamp BETWEEN :start AND :end
            ORDER BY reading_timestamp ASC
        ");
        $stmt->execute([
            'sensor_id' => $sensorId,
            'start' => $start,
            'end' => $end
        ]);
        return $stmt->fetchAll();
    }
    
    // Получение показаний с агрегацией (усреднение по интервалам)
    public function getAggregated($sensorId, $start, $end, $interval = 'hour') {
        // Определение интервала группировки
        $interval_sql = match($interval) {
            'minute' => '%Y-%m-%d %H:%i:00',
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d %H:00:00'
        };
        
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(reading_timestamp, :interval) as time_group,
                AVG(reading_value) as avg_value,
                MIN(reading_value) as min_value,
                MAX(reading_value) as max_value,
                COUNT(*) as count
            FROM readings 
            WHERE sensor_id = :sensor_id 
            AND reading_timestamp BETWEEN :start AND :end
            AND is_valid = 1
            GROUP BY time_group
            ORDER BY time_group ASC
        ");
        
        $stmt->execute([
            'interval' => $interval_sql,
            'sensor_id' => $sensorId,
            'start' => $start,
            'end' => $end
        ]);
        
        return $stmt->fetchAll();
    }
    
    // Получение статистики по счётчику за период
    public function getStatistics($sensorId, $start, $end) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_readings,
                SUM(CASE WHEN is_valid = 1 THEN 1 ELSE 0 END) as valid_readings,
                SUM(CASE WHEN is_valid = 0 THEN 1 ELSE 0 END) as invalid_readings,
                AVG(reading_value) as avg_value,
                MIN(reading_value) as min_value,
                MAX(reading_value) as max_value,
                STD(reading_value) as std_dev
            FROM readings 
            WHERE sensor_id = :sensor_id 
            AND reading_timestamp BETWEEN :start AND :end
        ");
        
        $stmt->execute([
            'sensor_id' => $sensorId,
            'start' => $start,
            'end' => $end
        ]);
        
        return $stmt->fetch();
    }
    
    // Получение всех показаний по зоне за период
    public function getByZoneAndPeriod($zoneId, $start, $end) {
        $stmt = $this->db->prepare("
            SELECT 
                r.*,
                s.sensor_name,
                s.resource_type,
                s.unit_of_measurement as sensor_unit
            FROM readings r
            INNER JOIN sensors s ON r.sensor_id = s.sensor_id
            WHERE s.zone_id = :zone_id
            AND r.reading_timestamp BETWEEN :start AND :end
            ORDER BY r.reading_timestamp DESC
        ");
        
        $stmt->execute([
            'zone_id' => $zoneId,
            'start' => $start,
            'end' => $end
        ]);
        
        return $stmt->fetchAll();
    }
    
    // Удаление старых данных (для очистки архива)
    public function deleteOlderThan($days) {
        $stmt = $this->db->prepare("
            DELETE FROM readings 
            WHERE reading_timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        return $stmt->execute(['days' => $days]);
    }
    
    // Получение потребления за период (разница между последним и первым показанием)
    public function getConsumption($sensorId, $start, $end) {
        $stmt = $this->db->prepare("
            SELECT 
                MAX(reading_value) - MIN(reading_value) as consumption,
                MIN(reading_timestamp) as first_time,
                MAX(reading_timestamp) as last_time
            FROM readings 
            WHERE sensor_id = :sensor_id 
            AND reading_timestamp BETWEEN :start AND :end
            AND is_valid = 1
        ");
        
        $stmt->execute([
            'sensor_id' => $sensorId,
            'start' => $start,
            'end' => $end
        ]);
        
        return $stmt->fetch();
    }
    
    // Получение данных для графика (последние N записей)
    public function getLatestForChart($sensorId, $limit = 100) {
        $stmt = $this->db->prepare("
            SELECT 
                reading_timestamp as timestamp,
                reading_value as value,
                unit_of_measurement as unit
            FROM readings 
            WHERE sensor_id = :sensor_id 
            AND is_valid = 1
            ORDER BY reading_timestamp DESC
            LIMIT :limit
        ");
        
        $stmt->bindParam(':sensor_id', $sensorId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $data = $stmt->fetchAll();
        return array_reverse($data); // В обратном порядке для правильного отображения на графике
    }
    
    // Валидация показания
    public function validateReading($value, $sensorType) {
        $rules = [
            'water' => ['min' => 0, 'max' => 1000],      // м³/ч
            'heat' => ['min' => 0, 'max' => 200],        // температура °C
            'electricity' => ['min' => 0, 'max' => 1000] // кВт
        ];
        
        $rule = $rules[$sensorType] ?? ['min' => 0, 'max' => 10000];
        
        return $value >= $rule['min'] && $value <= $rule['max'];
    }
}
?>