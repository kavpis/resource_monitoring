<?php
/**
 * Движок детекции аномалий
 * Раздел 5 — Алгоритмы обработки сигналов
 * Раздел 6.2 — Модуль анализа
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../models/Sensor.php';
require_once __DIR__ . '/../../models/Reading.php';
require_once __DIR__ . '/../../models/Alert.php';
require_once __DIR__ . '/../../models/DetectionRule.php';

class DetectionEngine {
    private $db;
    private $sensorModel;
    private $readingModel;
    private $alertModel;
    private $ruleModel;
    private $auth;
    
    public function __construct() {
        $this->db = getDB();
        $this->sensorModel = new Sensor();
        $this->readingModel = new Reading();
        $this->alertModel = new Alert();
        $this->ruleModel = new DetectionRule();
        $this->auth = new Auth();
    }
    
    /**
     * Основной метод анализа данных на аномалии
     */
    public function analyzeData($sensorId = null, $timeWindowHours = 1) {
        $startTime = microtime(true);
        
        // Получение счётчиков для анализа
        $sensors = $sensorId ? 
            [$this->sensorModel->getById($sensorId)] : 
            $this->sensorModel->getAll(['status' => 'online']);
        
        $sensors = array_filter($sensors); // Убираем null значения
        $alertsGenerated = 0;
        $alerts = [];
        
        foreach ($sensors as $sensor) {
            try {
                // Получение последних показаний
                $startDate = date('Y-m-d H:i:s', strtotime("-{$timeWindowHours} hours"));
                $endDate = date('Y-m-d H:i:s');
                
                $readings = $this->readingModel->getByPeriod($sensor['sensor_id'], $startDate, $endDate);
                
                if (empty($readings)) {
                    continue; // Нет данных для анализа
                }
                
                // Получение активных правил для этого типа ресурса
                $rules = $this->ruleModel->getActiveByResourceType($sensor['resource_type']);
                
                foreach ($rules as $rule) {
                    $anomalyDetected = $this->evaluateRule($rule, $readings, $sensor);
                    
                    if ($anomalyDetected) {
                        $existingAlert = $this->checkExistingAlert($sensor['sensor_id'], $rule['rule_name']);
                        
                        if (!$existingAlert) {
                            $alertData = [
                                'sensor_id' => $sensor['sensor_id'],
                                'zone_id' => $sensor['zone_id'],
                                'alert_type' => $rule['rule_name'],
                                'alert_level' => $rule['alert_level'],
                                'description' => $this->formatAlertDescription($rule, $sensor, end($readings)['reading_value']),
                                'current_value' => end($readings)['reading_value'],
                                'threshold_value' => $rule['threshold_value'],
                                'status' => 'active'
                            ];
                            
                            $alertId = $this->alertModel->create($alertData);
                            
                            if ($alertId) {
                                $alertsGenerated++;
                                $alerts[] = [
                                    'alert_id' => $alertId,
                                    'sensor_id' => $sensor['sensor_id'],
                                    'rule_name' => $rule['rule_name'],
                                    'alert_level' => $rule['alert_level'],
                                    'description' => $alertData['description']
                                ];
                                
                                // Логирование события
                                $this->auth->logAction(
                                    null, // Системное событие
                                    'anomaly_detected',
                                    "Обнаружена аномалия: {$rule['rule_name']} на сенсоре {$sensor['sensor_name']}",
                                    'alert',
                                    $alertId
                                );
                            }
                        }
                    }
                }
                
            } catch (Exception $e) {
                error_log("DetectionEngine error for sensor {$sensor['sensor_id']}: " . $e->getMessage());
                continue;
            }
        }
        
        $duration = round(microtime(true) - $startTime, 3);
        
        return [
            'success' => true,
            'data' => [
                'alerts_generated' => $alertsGenerated,
                'alerts' => $alerts,
                'analyzed_sensors' => count($sensors),
                'time_window_hours' => $timeWindowHours,
                'duration_seconds' => $duration
            ]
        ];
    }
    
    /**
     * Оценка одного правила
     */
    private function evaluateRule($rule, $readings, $sensor) {
        $currentValue = end($readings)['reading_value'];
        $previousValues = array_slice($readings, 0, -1);
        
        switch ($rule['condition_type']) {
            case 'threshold':
                return $this->evaluateThresholdRule($rule, $currentValue);
                
            case 'rate_of_change':
                return $this->evaluateRateOfChangeRule($rule, $currentValue, $previousValues);
                
            case 'absence':
                return $this->evaluateAbsenceRule($rule, $readings);
                
            case 'delta':
                return $this->evaluateDeltaRule($rule, $currentValue, $previousValues);
                
            case 'custom':
                return $this->evaluateCustomRule($rule, $currentValue, $previousValues, $sensor);
                
            default:
                return false;
        }
    }
    
    /**
     * Пороговое правило
     */
    private function evaluateThresholdRule($rule, $currentValue) {
        $threshold = $rule['threshold_value'];
        $operator = $rule['comparison_operator'];
        
        return match($operator) {
            '>' => $currentValue > $threshold,
            '<' => $currentValue < $threshold,
            '>=' => $currentValue >= $threshold,
            '<=' => $currentValue <= $threshold,
            '=' => abs($currentValue - $threshold) < 0.001,
            '!=' => abs($currentValue - $threshold) >= 0.001,
            default => false
        };
    }
    
    /**
     * Правило по скорости изменения
     */
    private function evaluateRateOfChangeRule($rule, $currentValue, $previousValues) {
        if (count($previousValues) < 2) {
            return false;
        }
        
        $timeWindow = $rule['time_window_seconds'] ?? 300; // 5 минут по умолчанию
        $threshold = $rule['threshold_value'] ?? 200; // 200% по умолчанию
        
        // Берём значения за последнее время
        $recentValues = array_filter($previousValues, function($reading) use ($timeWindow) {
            return strtotime($reading['reading_timestamp']) >= (time() - $timeWindow);
        });
        
        if (empty($recentValues)) {
            return false;
        }
        
        $baseValue = reset($recentValues)['reading_value'];
        
        if ($baseValue == 0) {
            return $currentValue > $threshold; // Защита от деления на 0
        }
        
        $changePercent = (($currentValue - $baseValue) / abs($baseValue)) * 100;
        
        return abs($changePercent) > $threshold;
    }
    
    /**
     * Правило отсутствия данных
     */
    private function evaluateAbsenceRule($rule, $readings) {
        if (empty($readings)) {
            return true;
        }
        
        $lastReadingTime = strtotime(end($readings)['reading_timestamp']);
        $timeWindow = $rule['time_window_seconds'] ?? 600; // 10 минут по умолчанию
        
        return (time() - $lastReadingTime) > $timeWindow;
    }
    
    /**
     * Правило дельты (разницы между значениями)
     */
    private function evaluateDeltaRule($rule, $currentValue, $previousValues) {
        if (empty($previousValues)) {
            return false;
        }
        
        $previousValue = end($previousValues)['reading_value'];
        $threshold = $rule['threshold_value'];
        $operator = $rule['comparison_operator'] ?? '>';
        
        $delta = abs($currentValue - $previousValue);
        
        return match($operator) {
            '>' => $delta > $threshold,
            '<' => $delta < $threshold,
            '>=' => $delta >= $threshold,
            '<=' => $delta <= $threshold,
            default => false
        };
    }
    
    /**
     * Пользовательское правило
     */
    private function evaluateCustomRule($rule, $currentValue, $previousValues, $sensor) {
        // Пользовательская логика определена в поле rule_logic
        $logic = $rule['rule_logic'] ?? '';
        
        // Пример: проверка сложных условий
        switch ($rule['rule_name']) {
            case 'water_leak_silent':
                // Тихая утечка: ночной расход > 0.1 м³/ч в течение 2 часов
                return $this->detectSilentWaterLeak($currentValue, $previousValues, $sensor);
                
            case 'heat_low_delta':
                // Низкая дельта температур: разница подачи и обратки < 5°C
                return $this->detectLowHeatDelta($currentValue, $previousValues, $sensor);
                
            case 'power_factor_low':
                // Низкий коэффициент мощности: cos φ < 0.85
                return $this->detectLowPowerFactor($currentValue, $sensor);
                
            default:
                return false;
        }
    }
    
    /**
     * Детекция тихой утечки воды
     */
    private function detectSilentWaterLeak($currentValue, $previousValues, $sensor) {
        // Проверка, ночь ли сейчас (между 22:00 и 6:00)
        $currentHour = (int)date('H');
        if ($currentHour >= 6 && $currentHour < 22) {
            return false; // Не ночь - не проверяем
        }
        
        // Проверка постоянного расхода в ночные часы
        $nightReadings = array_filter($previousValues, function($reading) {
            $hour = (int)date('H', strtotime($reading['reading_timestamp']));
            return ($hour >= 22 || $hour < 6); // Ночь
        });
        
        if (count($nightReadings) < 12) { // Минимум 12 показаний за ночь
            return false;
        }
        
        // Если все ночные показания > 0.1 м³/ч
        $nonZeroReadings = array_filter($nightReadings, function($reading) {
            return $reading['reading_value'] > 0.1;
        });
        
        // Если 80% ночных показаний > 0.1 - это тихая утечка
        return (count($nonZeroReadings) / count($nightReadings)) > 0.8;
    }
    
    /**
     * Детекция низкой дельты температур
     */
    private function detectLowHeatDelta($currentValue, $previousValues, $sensor) {
        // Для тепла: нужно сравнивать с другими сенсорами в той же зоне
        // Это сложное правило, требует сравнения с датчиком обратки
        
        // В реальности тут нужно сравнивать подачу и обратку
        // Пока заглушка
        return $currentValue < 40; // Температура слишком низкая
    }
    
    /**
     * Детекция низкого коэффициента мощности
     */
    private function detectLowPowerFactor($currentValue, $sensor) {
        return $currentValue < 0.85; // cos φ < 0.85
    }
    
    /**
     * Проверка существования активной аварии для правила
     */
    private function checkExistingAlert($sensorId, $ruleName) {
        $stmt = $this->alertModel->db->prepare("
            SELECT COUNT(*) FROM alerts 
            WHERE sensor_id = :sensor_id 
            AND alert_type = :rule_name
            AND status IN ('active', 'in_progress')
            AND alert_timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        
        $stmt->execute([
            'sensor_id' => $sensorId,
            'rule_name' => $ruleName
        ]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Форматирование описания аварии
     */
    private function formatAlertDescription($rule, $sensor, $currentValue) {
        $template = $rule['alert_message_template'];
        
        $template = str_replace('[zone]', $sensor['zone_name'] ?? 'неизвестная зона', $template);
        $template = str_replace('[sensor]', $sensor['sensor_name'], $template);
        $template = str_replace('[value]', number_format($currentValue, 2, ',', ' '), $template);
        $template = str_replace('[threshold]', number_format($rule['threshold_value'] ?? 0, 2, ',', ' '), $template);
        $template = str_replace('[resource]', $sensor['resource_type'], $template);
        
        return $template;
    }
    
    /**
     * Запуск анализа по расписанию
     */
    public function scheduleAnalysis($intervalMinutes = 30) {
        $this->analyzeAll();
        
        // Установка таймера для следующего анализа
        sleep($intervalMinutes * 60);
        $this->scheduleAnalysis($intervalMinutes);
    }
    
    /**
     * Получение статистики по детекции
     */
    public function getDetectionStatistics($startDate = null, $endDate = null) {
        $startDate = $startDate ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
        $endDate = $endDate ?? date('Y-m-d H:i:s');
        
        $stmt = $this->alertModel->db->prepare("
            SELECT 
                COUNT(*) as total_alerts,
                SUM(CASE WHEN alert_level = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
                SUM(CASE WHEN alert_level = 'emergency' THEN 1 ELSE 0 END) as emergency_alerts,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning_alerts,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_alerts,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_alerts,
                AVG(TIMESTAMPDIFF(MINUTE, alert_timestamp, resolved_at)) as avg_resolution_time_minutes
            FROM alerts
            WHERE alert_timestamp BETWEEN :start AND :end
        ");
        
        $stmt->execute([
            'start' => $startDate,
            'end' => $endDate
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Тестирование правила на конкретных данных
     */
    public function testRule($ruleId, $testData) {
        $rule = $this->ruleModel->getById($ruleId);
        if (!$rule) {
            return ['success' => false, 'message' => 'Правило не найдено'];
        }
        
        $result = $this->evaluateRule($rule, $testData, []);
        
        return [
            'success' => true,
            'data' => [
                'rule_id' => $ruleId,
                'rule_name' => $rule['rule_name'],
                'triggered' => $result,
                'test_data' => $testData
            ]
        ];
    }
    
    /**
     * Получение активных аномалий
     */
    public function getActiveAnomalies($filters = []) {
        $stmt = $this->alertModel->db->prepare("
            SELECT 
                a.*, 
                s.sensor_name, 
                z.zone_name,
                u.full_name as resolved_by_name
            FROM alerts a
            LEFT JOIN sensors s ON a.sensor_id = s.sensor_id
            LEFT JOIN zones z ON a.zone_id = z.zone_id
            LEFT JOIN users u ON a.resolved_by = u.user_id
            WHERE a.status IN ('active', 'in_progress')
        ");
        
        if (!empty($filters['resource_type'])) {
            $stmt->where .= " AND s.resource_type = :resource_type";
            $stmt->params['resource_type'] = $filters['resource_type'];
        }
        
        if (!empty($filters['zone_id'])) {
            $stmt->where .= " AND a.zone_id = :zone_id";
            $stmt->params['zone_id'] = $filters['zone_id'];
        }
        
        if (!empty($filters['alert_level'])) {
            $stmt->where .= " AND a.alert_level = :alert_level";
            $stmt->params['alert_level'] = $filters['alert_level'];
        }
        
        $stmt->orderBy('a.alert_timestamp DESC');
        $stmt->limit($filters['limit'] ?? 50);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверка подозрительной активности (по истории)
     */
    public function checkSuspiciousActivity($userId, $timeWindowHours = 1) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$timeWindowHours} hours"));
        
        $stmt = $this->auth->db->prepare("
            SELECT 
                action_type,
                COUNT(*) as count,
                GROUP_CONCAT(action_description SEPARATOR '; ') as descriptions
            FROM audit_log
            WHERE user_id = :user_id
            AND action_timestamp > :cutoff_time
            AND action_type IN ('failed_login', 'unauthorized_access', 'suspicious_activity')
            GROUP BY action_type
            HAVING count > 5
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'cutoff_time' => $cutoffTime
        ]);
        
        $suspiciousActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'suspicious' => !empty($suspiciousActions),
            'actions' => $suspiciousActions,
            'count' => count($suspiciousActions)
        ];
    }
    
    /**
     * Интеграция с модулем уведомлений
     */
    public function integrateWithNotifications($alertData) {
        // Здесь будет интеграция с NotificationService
        // Пока заглушка
        return [
            'success' => true,
            'notifications_sent' => 0
        ];
    }
}
?>