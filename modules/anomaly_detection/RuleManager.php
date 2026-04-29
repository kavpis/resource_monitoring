<?php
/**
 * Менеджер правил детекции
 * Раздел 5.5 — Интеграция и приоритизация событий
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';

class RuleManager {
    private $db;
    private $auth;
    
    public function __construct() {
        $this->db = getDB();
        $this->auth = new Auth();
    }
    
    /**
     * Создание нового правила
     */
    public function createRule($data) {
        $required = ['rule_name', 'resource_type', 'condition_type', 'alert_level'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "Поле '{$field}' обязательно"];
            }
        }
        
        // Валидация данных
        $validation = $this->validateRuleData($data);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['error']];
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO detection_rules 
            (rule_name, resource_type, zone_id, condition_type, threshold_value, time_window_seconds,
             comparison_operator, alert_level, alert_message_template, is_active, created_by, created_at)
            VALUES (:rule_name, :resource_type, :zone_id, :condition_type, :threshold_value, :time_window,
                    :comparison_operator, :alert_level, :alert_message, :is_active, :created_by, NOW())
        ");
        
        $result = $stmt->execute([
            'rule_name' => $data['rule_name'],
            'resource_type' => $data['resource_type'],
            'zone_id' => $data['zone_id'] ?? null,
            'condition_type' => $data['condition_type'],
            'threshold_value' => $data['threshold_value'] ?? null,
            'time_window' => $data['time_window_seconds'] ?? null,
            'comparison_operator' => $data['comparison_operator'] ?? null,
            'alert_level' => $data['alert_level'],
            'alert_message' => $data['alert_message_template'],
            'is_active' => $data['is_active'] ?? 1,
            'created_by' => $this->auth->getUser()['user_id'] ?? null
        ]);
        
        if ($result) {
            $ruleId = $this->db->lastInsertId();
            
            // Логирование
            $this->auth->logAction(
                $this->auth->getUser()['user_id'] ?? null,
                'create_detection_rule',
                "Создано правило детекции: {$data['rule_name']}",
                'detection_rule',
                $ruleId
            );
            
            return [
                'success' => true,
                'message' => 'Правило создано',
                'rule_id' => $ruleId
            ];
        }
        
        return ['success' => false, 'message' => 'Ошибка при создании правила'];
    }
    
    /**
     * Обновление правила
     */
    public function updateRule($ruleId, $data) {
        $stmt = $this->db->prepare("
            UPDATE detection_rules 
            SET rule_name = :rule_name, resource_type = :resource_type, zone_id = :zone_id,
                condition_type = :condition_type, threshold_value = :threshold_value,
                time_window_seconds = :time_window, comparison_operator = :comparison_operator,
                alert_level = :alert_level, alert_message_template = :alert_message,
                is_active = :is_active, updated_at = NOW()
            WHERE rule_id = :rule_id
        ");
        
        $result = $stmt->execute([
            'rule_id' => $ruleId,
            'rule_name' => $data['rule_name'],
            'resource_type' => $data['resource_type'],
            'zone_id' => $data['zone_id'] ?? null,
            'condition_type' => $data['condition_type'],
            'threshold_value' => $data['threshold_value'] ?? null,
            'time_window' => $data['time_window_seconds'] ?? null,
            'comparison_operator' => $data['comparison_operator'] ?? null,
            'alert_level' => $data['alert_level'],
            'alert_message' => $data['alert_message_template'],
            'is_active' => $data['is_active'] ?? 1
        ]);
        
        if ($result) {
            // Логирование
            $this->auth->logAction(
                $this->auth->getUser()['user_id'] ?? null,
                'update_detection_rule',
                "Обновлено правило детекции ID: {$ruleId}",
                'detection_rule',
                $ruleId
            );
            
            return ['success' => true, 'message' => 'Правило обновлено'];
        }
        
        return ['success' => false, 'message' => 'Ошибка при обновлении правила'];
    }
    
    /**
     * Удаление правила
     */
    public function deleteRule($ruleId) {
        $stmt = $this->db->prepare("DELETE FROM detection_rules WHERE rule_id = :rule_id");
        $result = $stmt->execute(['rule_id' => $ruleId]);
        
        if ($result) {
            // Логирование
            $this->auth->logAction(
                $this->auth->getUser()['user_id'] ?? null,
                'delete_detection_rule',
                "Удалено правило детекции ID: {$ruleId}",
                'detection_rule',
                $ruleId
            );
            
            return ['success' => true, 'message' => 'Правило удалено'];
        }
        
        return ['success' => false, 'message' => 'Ошибка при удалении правила'];
    }
    
    /**
     * Получение всех правил
     */
    public function getAllRules($filters = []) {
        $query = "SELECT dr.*, u.full_name as created_by_name FROM detection_rules dr LEFT JOIN users u ON dr.created_by = u.user_id WHERE 1=1";
        $params = [];
        
        if (!empty($filters['resource_type'])) {
            $query .= " AND dr.resource_type = :resource_type";
            $params['resource_type'] = $filters['resource_type'];
        }
        
        if (!empty($filters['zone_id'])) {
            $query .= " AND dr.zone_id = :zone_id";
            $params['zone_id'] = $filters['zone_id'];
        }
        
        if (isset($filters['is_active'])) {
            $query .= " AND dr.is_active = :is_active";
            $params['is_active'] = $filters['is_active'];
        }
        
        $query .= " ORDER BY dr.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получение активных правил для типа ресурса
     */
    public function getActiveRulesByResource($resourceType) {
        $stmt = $this->db->prepare("
            SELECT * FROM detection_rules 
            WHERE resource_type = :resource_type 
            AND is_active = 1
            ORDER BY alert_level DESC, created_at ASC
        ");
        
        $stmt->execute(['resource_type' => $resourceType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Валидация данных правила
     */
    private function validateRuleData($data) {
        // Проверка типа условия
        $validConditions = ['threshold', 'rate_of_change', 'absence', 'delta', 'custom'];
        if (!in_array($data['condition_type'], $validConditions)) {
            return ['valid' => false, 'error' => 'Неверный тип условия'];
        }
        
        // Проверка уровня тревоги
        $validLevels = ['warning', 'emergency', 'critical'];
        if (!in_array($data['alert_level'], $validLevels)) {
            return ['valid' => false, 'error' => 'Неверный уровень тревоги'];
        }
        
        // Проверка оператора сравнения
        if (in_array($data['condition_type'], ['threshold', 'delta'])) {
            $validOperators = ['>', '<', '>=', '<=', '=', '!='];
            if (empty($data['comparison_operator']) || !in_array($data['comparison_operator'], $validOperators)) {
                return ['valid' => false, 'error' => 'Неверный оператор сравнения'];
            }
        }
        
        // Проверка порогового значения
        if (in_array($data['condition_type'], ['threshold', 'delta']) && !isset($data['threshold_value'])) {
            return ['valid' => false, 'error' => 'Пороговое значение обязательно для этого типа условия'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Тестирование правила
     */
    public function testRule($ruleId, $testValue) {
        $rule = $this->getRuleById($ruleId);
        if (!$rule) {
            return ['success' => false, 'message' => 'Правило не найдено'];
        }
        
        $detector = match($rule['resource_type']) {
            'water' => new WaterLeakDetector(),
            'heat' => new HeatAnomalyDetector(),
            'electricity' => new PowerAnomalyDetector(),
            default => new DetectionEngine()
        };
        
        $result = $detector->evaluateRule($rule, [['reading_value' => $testValue]], []);
        
        return [
            'success' => true,
            'data' => [
                'rule_id' => $ruleId,
                'test_value' => $testValue,
                'triggered' => $result,
                'rule' => $rule
            ]
        ];
    }
    
    /**
     * Получение правила по ID
     */
    public function getRuleById($ruleId) {
        $stmt = $this->db->prepare("SELECT * FROM detection_rules WHERE rule_id = :rule_id");
        $stmt->execute(['rule_id' => $ruleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Активация/деактивация правила
     */
    public function setActive($ruleId, $isActive) {
        $stmt = $this->db->prepare("
            UPDATE detection_rules 
            SET is_active = :is_active, updated_at = NOW()
            WHERE rule_id = :rule_id
        ");
        
        $result = $stmt->execute([
            'rule_id' => $ruleId,
            'is_active' => $isActive ? 1 : 0
        ]);
        
        if ($result) {
            $action = $isActive ? 'activate_rule' : 'deactivate_rule';
            $message = $isActive ? "Активировано правило ID: {$ruleId}" : "Деактивировано правило ID: {$ruleId}";
            
            $this->auth->logAction(
                $this->auth->getUser()['user_id'] ?? null,
                $action,
                $message,
                'detection_rule',
                $ruleId
            );
            
            return ['success' => true, 'message' => $isActive ? 'Правило активировано' : 'Правило деактивировано'];
        }
        
        return ['success' => false, 'message' => 'Ошибка при изменении статуса правила'];
    }
    
    /**
     * Получение статистики по правилам
     */
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_rules,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rules,
                SUM(CASE WHEN resource_type = 'water' THEN 1 ELSE 0 END) as water_rules,
                SUM(CASE WHEN resource_type = 'heat' THEN 1 ELSE 0 END) as heat_rules,
                SUM(CASE WHEN resource_type = 'electricity' THEN 1 ELSE 0 END) as electricity_rules,
                SUM(CASE WHEN alert_level = 'critical' THEN 1 ELSE 0 END) as critical_rules,
                SUM(CASE WHEN alert_level = 'emergency' THEN 1 ELSE 0 END) as emergency_rules,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning_rules
            FROM detection_rules
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получение правил с подсветкой приоритетов
     */
    public function getPrioritizedRules() {
        $stmt = $this->db->query("
            SELECT 
                dr.*,
                COUNT(a.alert_id) as triggered_count
            FROM detection_rules dr
            LEFT JOIN alerts a ON dr.rule_name = a.alert_type AND a.alert_timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE dr.is_active = 1
            GROUP BY dr.rule_id
            ORDER BY dr.alert_level DESC, dr.created_at ASC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>