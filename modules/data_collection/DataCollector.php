<?php
/**
 * Модуль сбора данных с счётчиков
 * Раздел 6.1 — Модуль сбора данных
 * Раздел 4 — Протокол Modbus TCP
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../models/Sensor.php';
require_once __DIR__ . '/../../models/Reading.php';
require_once __DIR__ . '/../../models/Alert.php';
require_once __DIR__ . '/../../models/DetectionRule.php';
require_once __DIR__ . '/ModbusClient.php';

class DataCollector {
    private $db;
    private $sensorModel;
    private $readingModel;
    private $alertModel;
    private $ruleModel;
    private $auth;
    
    private $pollingInterval = 30; // секунд
    private $connectionTimeout = 5; // секунд
    private $maxRetries = 3;
    private $logErrors = true;
    
    public function __construct() {
        $this->db = getDB();
        $this->sensorModel = new Sensor();
        $this->readingModel = new Reading();
        $this->alertModel = new Alert();
        $this->ruleModel = new DetectionRule();
        $this->auth = new Auth();
        
        // Загрузка настроек из базы
        $this->loadSettings();
    }
    
    /**
     * Загрузка настроек системы
     */
    private function loadSettings() {
        try {
            $stmt = $this->db->prepare("
                SELECT setting_value FROM system_settings 
                WHERE setting_key = :key
            ");
            
            $stmt->execute(['key' => 'polling_interval_seconds']);
            $interval = $stmt->fetchColumn();
            if ($interval !== false) {
                $this->pollingInterval = (int)$interval;
            }
            
            $stmt->execute(['key' => 'modbus_timeout_seconds']);
            $timeout = $stmt->fetchColumn();
            if ($timeout !== false) {
                $this->connectionTimeout = (int)$timeout;
            }
            
            $stmt->execute(['key' => 'modbus_max_retries']);
            $retries = $stmt->fetchColumn();
            if ($retries !== false) {
                $this->maxRetries = (int)$retries;
            }
            
        } catch (Exception $e) {
            error_log("DataCollector settings load error: " . $e->getMessage());
            // Используем значения по умолчанию
        }
    }
    
    /**
     * Сбор данных со всех активных счётчиков
     */
    public function collectAll() {
        $startTime = microtime(true);
        
        // Получение всех активных счётчиков
        $sensors = $this->sensorModel->getAll(['status' => 'online']);
        
        $results = [
            'collected' => 0,
            'errors' => 0,
            'total' => count($sensors),
            'details' => [],
            'duration' => 0
        ];
        
        foreach ($sensors as $sensor) {
            $result = $this->collectFromSensor($sensor);
            
            if ($result['success']) {
                $results['collected']++;
            } else {
                $results['errors']++;
            }
            
            $results['details'][] = $result;
        }
        
        $results['duration'] = round(microtime(true) - $startTime, 2);
        
        // Логирование статистики
        $this->logCollectionStats($results);
        
        return $results;
    }
    
    /**
     * Сбор данных с одного счётчика
     */
    public function collectFromSensor($sensor) {
        $startTime = microtime(true);
        
        try {
            // Создание клиента Modbus
            $modbus = new ModbusClient(
                $sensor['ip_address'],
                $sensor['modbus_port'] ?? 502,
                $this->connectionTimeout
            );
            
            // Подключение к счётчику
            if (!$modbus->connect()) {
                $this->handleConnectionError($sensor);
                return [
                    'success' => false,
                    'sensor_id' => $sensor['sensor_id'],
                    'sensor_name' => $sensor['sensor_name'],
                    'error' => 'Нет подключения к счётчику',
                    'duration' => round(microtime(true) - $startTime, 2)
                ];
            }
            
            // Чтение значения из регистра
            $value = $this->readValueFromModbus($modbus, $sensor);
            
            if ($value === false) {
                $this->handleReadError($sensor);
                $modbus->disconnect();
                return [
                    'success' => false,
                    'sensor_id' => $sensor['sensor_id'],
                    'sensor_name' => $sensor['sensor_name'],
                    'error' => 'Ошибка чтения данных из счётчика',
                    'duration' => round(microtime(true) - $startTime, 2)
                ];
            }
            
            // Валидация значения
            $isValid = $this->readingModel->validateValue($value, $sensor['resource_type']);
            $validationError = null;
            
            if (!$isValid) {
                $validationError = "Значение {$value} вне допустимого диапазона для {$sensor['resource_type']}";
                $this->logValidationError($sensor, $value, $validationError);
            }
            
            // Сохранение показания
            $readingId = $this->readingModel->add(
                $sensor['sensor_id'],
                $value,
                $sensor['unit_of_measurement'],
                null, // Автоматическая временная метка
                $isValid,
                $validationError
            );
            
            if ($readingId) {
                // Обновление времени последней связи
                $this->sensorModel->updateLastCommunication($sensor['sensor_id']);
                
                // Проверка на аномалии
                $this->checkForAnomalies($sensor, $value);
                
                // Успешное обновление статуса
                $this->sensorModel->updateStatus($sensor['sensor_id'], 'online');
                
                $modbus->disconnect();
                
                return [
                    'success' => true,
                    'sensor_id' => $sensor['sensor_id'],
                    'sensor_name' => $sensor['sensor_name'],
                    'value' => $value,
                    'reading_id' => $readingId,
                    'duration' => round(microtime(true) - $startTime, 2)
                ];
            } else {
                $this->handleSaveError($sensor, $value);
                $modbus->disconnect();
                
                return [
                    'success' => false,
                    'sensor_id' => $sensor['sensor_id'],
                    'sensor_name' => $sensor['sensor_name'],
                    'error' => 'Ошибка сохранения показания в базу',
                    'duration' => round(microtime(true) - $startTime, 2)
                ];
            }
            
        } catch (Exception $e) {
            $this->handleException($sensor, $e);
            
            return [
                'success' => false,
                'sensor_id' => $sensor['sensor_id'],
                'sensor_name' => $sensor['sensor_name'],
                'error' => 'Исключение: ' . $e->getMessage(),
                'duration' => round(microtime(true) - $startTime, 2)
            ];
        }
    }
    
    /**
     * Чтение значения из Modbus-регистра
     */
    private function readValueFromModbus($modbus, $sensor) {
        $address = $sensor['register_address'];
        $resourceType = $sensor['resource_type'];
        $registerCount = $sensor['register_count'] ?? 2; // По умолчанию 2 регистра для float
        
        switch ($resourceType) {
            case 'water':
            case 'heat':
                // Для float-значений (температура, расход) читаем 2 регистра
                if ($registerCount === 2) {
                    return $modbus->readFloat($address);
                } else {
                    // Для int-значений (напряжение) читаем 1 регистр
                    return $modbus->readInt($address);
                }
                
            case 'electricity':
                // Для электричества может быть напряжение (int) или мощность (float)
                if ($sensor['unit_of_measurement'] === 'В') {
                    return $modbus->readInt($address);
                } else {
                    if ($registerCount === 2) {
                        return $modbus->readFloat($address);
                    } else {
                        return $modbus->readInt($address);
                    }
                }
                
            default:
                return $modbus->readFloat($address);
        }
    }
    
    /**
     * Обработка ошибки подключения
     */
    private function handleConnectionError($sensor) {
        $this->sensorModel->updateStatus($sensor['sensor_id'], 'offline');
        
        // Создание события о потере связи
        $this->alertModel->create([
            'sensor_id' => $sensor['sensor_id'],
            'zone_id' => $sensor['zone_id'],
            'alert_type' => 'connection_lost',
            'alert_level' => 'warning',
            'description' => "Потеря связи со счётчиком {$sensor['sensor_name']}",
            'status' => 'active'
        ]);
        
        // Логирование ошибки
        if ($this->logErrors) {
            $this->auth->logAction(
                null, // Системное событие
                'connection_error',
                "Нет связи с счётчиком {$sensor['sensor_name']} ({$sensor['ip_address']})",
                'sensor',
                $sensor['sensor_id']
            );
        }
    }
    
    /**
     * Обработка ошибки чтения
     */
    private function handleReadError($sensor) {
        $this->sensorModel->updateStatus($sensor['sensor_id'], 'error');
        
        // Создание события об ошибке чтения
        $this->alertModel->create([
            'sensor_id' => $sensor['sensor_id'],
            'zone_id' => $sensor['zone_id'],
            'alert_type' => 'read_error',
            'alert_level' => 'warning',
            'description' => "Ошибка чтения данных со счётчика {$sensor['sensor_name']}",
            'status' => 'active'
        ]);
        
        if ($this->logErrors) {
            $this->auth->logAction(
                null,
                'read_error',
                "Ошибка чтения данных со счётчика {$sensor['sensor_name']}",
                'sensor',
                $sensor['sensor_id']
            );
        }
    }
    
    /**
     * Обработка ошибки сохранения
     */
    private function handleSaveError($sensor, $value) {
        $this->sensorModel->updateStatus($sensor['sensor_id'], 'error');
        
        if ($this->logErrors) {
            $this->auth->logAction(
                null,
                'save_error',
                "Ошибка сохранения показания {$value} для счётчика {$sensor['sensor_name']}",
                'sensor',
                $sensor['sensor_id']
            );
        }
    }
    
    /**
     * Обработка исключения
     */
    private function handleException($sensor, $exception) {
        $this->sensorModel->updateStatus($sensor['sensor_id'], 'error');
        
        if ($this->logErrors) {
            $this->auth->logAction(
                null,
                'collection_exception',
                "Исключение при сборе данных со счётчика {$sensor['sensor_name']}: " . $exception->getMessage(),
                'sensor',
                $sensor['sensor_id']
            );
        }
    }
    
    /**
     * Проверка на аномалии
     */
    private function checkForAnomalies($sensor, $value) {
        // Получение активных правил для этого типа ресурса
        $rules = $this->ruleModel->getActiveByResource($sensor['resource_type']);
        
        foreach ($rules as $rule) {
            $triggered = $this->ruleModel->evaluate($rule, $value);
            
            if ($triggered) {
                // Проверка, не создана ли уже такая авария
                $existingAlert = $this->alertModel->getByTypeAndSensor($rule['rule_name'], $sensor['sensor_id'], ['status' => 'active']);
                
                if (empty($existingAlert)) {
                    // Создание нового события
                    $alertId = $this->alertModel->create([
                        'sensor_id' => $sensor['sensor_id'],
                        'zone_id' => $sensor['zone_id'],
                        'alert_type' => $rule['rule_name'],
                        'alert_level' => $rule['alert_level'],
                        'description' => $this->formatAlertDescription($rule, $sensor, $value),
                        'current_value' => $value,
                        'threshold_value' => $rule['threshold_value'],
                        'status' => 'active'
                    ]);
                    
                    if ($alertId) {
                        // Логирование события
                        $this->auth->logAction(
                            null,
                            'anomaly_detected',
                            "Обнаружена аномалия: {$rule['rule_name']} на счётчике {$sensor['sensor_name']} (значение: {$value})",
                            'alert',
                            $alertId
                        );
                    }
                }
            }
        }
    }
    
    /**
     * Форматирование описания аварии
     */
    private function formatAlertDescription($rule, $sensor, $value) {
        $template = $rule['alert_message_template'];
        $template = str_replace('[zone]', $sensor['zone_name'], $template);
        $template = str_replace('[sensor]', $sensor['sensor_name'], $template);
        $template = str_replace('[value]', number_format($value, 2, ',', ' '), $template);
        $template = str_replace('[threshold]', number_format($rule['threshold_value'], 2, ',', ' '), $template);
        
        return $template;
    }
    
    /**
     * Логирование статистики сбора
     */
    private function logCollectionStats($results) {
        $logEntry = sprintf(
            "[%s] Сбор данных завершён. Всего: %d, Успешно: %d, Ошибок: %d, Время: %.2fs\n",
            date('Y-m-d H:i:s'),
            $results['total'],
            $results['collected'],
            $results['errors'],
            $results['duration']
        );
        
        $logFile = LOG_PATH . '/data_collection_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Логирование ошибки валидации
     */
    private function logValidationError($sensor, $value, $error) {
        $logEntry = sprintf(
            "[%s] Ошибка валидации для счётчика %s (ID: %d): значение %s, ошибка: %s\n",
            date('Y-m-d H:i:s'),
            $sensor['sensor_name'],
            $sensor['sensor_id'],
            $value,
            $error
        );
        
        $logFile = LOG_PATH . '/validation_errors_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Получение статистики по сбору данных
     */
    public function getStatistics($startDate = null, $endDate = null) {
        $startDate = $startDate ?? date('Y-m-d 00:00:00', strtotime('-24 hours'));
        $endDate = $endDate ?? date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_readings,
                SUM(CASE WHEN r.is_valid = 1 THEN 1 ELSE 0 END) as valid_readings,
                SUM(CASE WHEN r.is_valid = 0 THEN 1 ELSE 0 END) as invalid_readings,
                COUNT(DISTINCT r.sensor_id) as unique_sensors,
                COUNT(DISTINCT a.alert_id) as generated_alerts,
                AVG(r.reading_value) as avg_value,
                MIN(r.reading_value) as min_value,
                MAX(r.reading_value) as max_value,
                SUM(CASE WHEN s.status = 'online' THEN 1 ELSE 0 END) as online_sensors,
                SUM(CASE WHEN s.status = 'offline' THEN 1 ELSE 0 END) as offline_sensors,
                SUM(CASE WHEN s.status = 'error' THEN 1 ELSE 0 END) as error_sensors
            FROM readings r
            LEFT JOIN sensors s ON r.sensor_id = s.sensor_id
            LEFT JOIN alerts a ON a.sensor_id = s.sensor_id AND a.alert_timestamp BETWEEN :start AND :end
            WHERE r.reading_timestamp BETWEEN :start AND :end
        ");
        
        $stmt->execute([
            'start' => $startDate,
            'end' => $endDate
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Тестирование подключения к счётчику
     */
    public function testConnection($sensorId) {
        $sensor = $this->sensorModel->getById($sensorId);
        if (!$sensor) {
            return ['success' => false, 'message' => 'Счётчик не найден'];
        }
        
        try {
            $modbus = new ModbusClient(
                $sensor['ip_address'],
                $sensor['modbus_port'] ?? 502,
                $this->connectionTimeout
            );
            
            $available = $modbus->isAvailable();
            $modbus->disconnect();
            
            // Обновление статуса счётчика
            $newStatus = $available ? 'online' : 'offline';
            $this->sensorModel->updateStatus($sensorId, $newStatus);
            
            return [
                'success' => true,
                'available' => $available,
                'status' => $newStatus,
                'message' => $available ? 'Счётчик доступен' : 'Счётчик недоступен'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Ошибка тестирования: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Ручной запуск сбора данных
     */
    public function manualCollect($sensorId = null) {
        if ($sensorId) {
            // Сбор данных с одного счётчика
            $sensor = $this->sensorModel->getById($sensorId);
            if (!$sensor) {
                return ['success' => false, 'message' => 'Счётчик не найден'];
            }
            
            return $this->collectFromSensor($sensor);
        } else {
            // Сбор данных со всех счётчиков
            return $this->collectAll();
        }
    }
    
    /**
     * Установка интервала опроса
     */
    public function setPollingInterval($seconds) {
        if ($seconds < 5 || $seconds > 3600) {
            return ['success' => false, 'message' => 'Интервал должен быть от 5 до 3600 секунд'];
        }
        
        $this->pollingInterval = $seconds;
        
        // Сохранение в настройки
        $stmt = $this->db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_group, description)
            VALUES ('polling_interval_seconds', :value, 'data_collection', 'Интервал опроса счётчиков в секундах')
            ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = NOW()
        ");
        
        $result = $stmt->execute(['value' => $seconds]);
        
        if ($result) {
            $this->auth->logAction(
                $this->auth->getUser()['user_id'] ?? null,
                'update_polling_interval',
                "Изменён интервал опроса: {$seconds} сек",
                'system',
                null
            );
            
            return [
                'success' => true,
                'message' => "Интервал опроса изменён на {$seconds} секунд"
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Ошибка при сохранении интервала'
        ];
    }
    
    /**
     * Получение текущего интервала опроса
     */
    public function getPollingInterval() {
        return [
            'success' => true,
            'interval' => $this->pollingInterval,
            'timeout' => $this->connectionTimeout,
            'retries' => $this->maxRetries
        ];
    }
    
    /**
     * Проверка доступности всех счётчиков
     */
    public function checkAllConnections() {
        $sensors = $this->sensorModel->getAll();
        $results = [];
        
        foreach ($sensors as $sensor) {
            $result = $this->testConnection($sensor['sensor_id']);
            $results[] = [
                'sensor_id' => $sensor['sensor_id'],
                'sensor_name' => $sensor['sensor_name'],
                'available' => $result['available'] ?? false,
                'status' => $result['status'] ?? 'unknown'
            ];
        }
        
        return [
            'success' => true,
            'data' => $results,
            'total_sensors' => count($sensors),
            'available_count' => count(array_filter($results, fn($r) => $r['available']))
        ];
    }
}
?>